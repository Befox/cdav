<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
require_once __DIR__.'/cdavcompatibility.class.php';
require_once __DIR__.'/../lib/cdav.lib.php';

/** Generate the initial service tasks inside the native project validation transaction. */
class CDavTaskGeneration
{
	/** @var DoliDB */ private $db;
	/** @var string */ public $error = '';
	/** @var list<string> */ public $errors = array();
	public function __construct($db) { $this->db = $db; }

	/** @param Project $project @param User $user @return int Number created, 0 skipped, -1 failure. */
	public function generate($project, $user)
	{
		global $conf, $langs;
		if (!CDavCompatibility::isFeatureAvailable('gentask')) return 0;
		$langs->loadLangs(array('cdav@cdav', 'projects', 'products', 'orders', 'propal', 'errors'));
		if (!$user->hasRight('projet', 'lire') || !$user->hasRight('projet', 'creer') || !$user->hasRight('service', 'lire') || !empty($user->socid)) {
			$this->error = $langs->trans('NotEnoughPermissions'); return -1;
		}
		// v16 Task::create() writes conf->entity; never silently mix parent and task owners.
		if ((int) $project->id <= 0 || (int) $project->entity !== (int) $conf->entity) {
			$this->error = $langs->trans('CDavGenerationOwner'); return -1;
		}
		require_once DOL_DOCUMENT_ROOT.'/projet/class/task.class.php';
		require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
		require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
		$authorized = $project->getProjectsAuthorizedForUser($user, $user->hasRight('projet', 'all', 'lire') ? 2 : 0, 1);
		if (!in_array((int) $project->id, array_map('intval', explode(',', (string) $authorized)), true)) {
			$this->error = $langs->trans('NotEnoughPermissions'); return -1;
		}
		$this->db->begin();
		try {
			// Held until the enclosing native validation commits, including other triggers.
			$res = $this->db->query('SELECT rowid FROM '.MAIN_DB_PREFIX.'projet WHERE rowid='.(int) $project->id.' AND entity='.(int) $conf->entity.' FOR UPDATE');
			if (!$res || !is_object($this->db->fetch_object($res))) throw new RuntimeException('CDavGenerationFailed');
			$res = $this->db->query('SELECT rowid FROM '.MAIN_DB_PREFIX.'projet_task WHERE fk_projet='.(int) $project->id.' AND entity='.(int) $project->entity);
			if (!$res) throw new RuntimeException('CDavGenerationFailed');
			if ($this->db->num_rows($res) > 0) { $this->db->commit(); return 0; }
			$tasks = array(); $hasDocuments = false; $orderedProposals = array();
			$tag = getDolGlobalInt('CDAV_GENTASK_SERVICE_TAG');
			$customDuration = getDolGlobalInt('CDAV_EXTRAFIELD_DURATION') > 0;
			foreach (array('commande', 'propal') as $type) {
				if (!isModEnabled($type)) continue;
				if (!$user->hasRight($type, 'lire')) throw new RuntimeException('NotEnoughPermissions');
				$res = $this->db->query('SELECT rowid FROM '.MAIN_DB_PREFIX.$type.' WHERE fk_projet='.(int) $project->id.' AND entity IN ('.$this->db->sanitize(getEntity($type)).') ORDER BY rowid');
				if (!$res) throw new RuntimeException('CDavGenerationFailed');
				while (is_object($document = $this->db->fetch_object($res))) {
					$hasDocuments = true;
					if ($type === 'propal' && isset($orderedProposals[(int) $document->rowid])) continue;
					if ($type === 'commande') {
						$links = $this->db->query("SELECT e.fk_source FROM ".MAIN_DB_PREFIX."element_element e INNER JOIN ".MAIN_DB_PREFIX."propal p ON p.rowid=e.fk_source AND p.entity IN (".$this->db->sanitize(getEntity('propal')).") WHERE e.sourcetype='propal' AND e.targettype='commande' AND e.fk_target=".(int) $document->rowid);
						if (!$links) throw new RuntimeException('CDavGenerationFailed');
						while (is_object($link = $this->db->fetch_object($links))) $orderedProposals[(int) $link->fk_source] = true;
					}
					$lines = $type === 'commande' ? 'commandedet' : 'propaldet';
					$sql = 'SELECT d.label, d.description, p.label AS service_label, p.description AS service_description, p.duration, ef.cdav_duration, d.fk_product FROM '.MAIN_DB_PREFIX.$lines.' d';
					$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'product p ON p.rowid=d.fk_product AND p.entity IN ('.$this->db->sanitize(getEntity('product')).')';
					$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.$lines.'_extrafields ef ON ef.fk_object=d.rowid';
					$sql .= ' WHERE d.fk_'.$type.'='.(int) $document->rowid.' AND d.product_type=1 AND d.qty>0 AND (d.fk_product IS NULL OR p.rowid IS NOT NULL)';
					if ($tag > 0) {
						$sql .= ' AND (EXISTS (SELECT 1 FROM '.MAIN_DB_PREFIX.'categorie_product cp INNER JOIN '.MAIN_DB_PREFIX.'categorie c ON c.rowid=cp.fk_categorie AND c.entity IN ('.$this->db->sanitize(getEntity('category')).') WHERE cp.fk_product=d.fk_product AND cp.fk_categorie='.$tag.')';
						if ($customDuration) $sql .= " OR COALESCE(ef.cdav_duration,'')<>''";
						$sql .= ')';
					}
					$sql .= ' ORDER BY d.rang, d.rowid';
					$lineResult = $this->db->query($sql);
					if (!$lineResult) throw new RuntimeException('CDavGenerationFailed');
					while (is_object($line = $this->db->fetch_object($lineResult))) {
						if (getDolGlobalInt('WEEE_PRODUCT_ID') > 0 && (int) $line->fk_product === getDolGlobalInt('WEEE_PRODUCT_ID')) continue;
						$description = (string) ($line->description ?: $line->service_description);
						$label = (string) ($line->label ?: ($line->service_label ?: strtok($description, "\n")));
						$tasks[] = array('label' => $label ?: $langs->transnoentities('Task'), 'description' => $description, 'duration' => (string) ($customDuration && $line->cdav_duration !== null && $line->cdav_duration !== '' ? $line->cdav_duration : $line->duration));
					}
				}
			}
			if (!$hasDocuments) { $this->db->commit(); return 0; }
			$before = array(); $after = array();
			foreach (array('INI', 'END') as $stage) {
				for ($i = 1; $i <= 3; $i++) {
					$id = getDolGlobalInt('CDAV_GENTASK_'.$stage.$i);
					if ($id <= 0) continue;
					$service = new Product($this->db);
					if ($service->fetch($id) <= 0 || (int) $service->type !== 1 || !in_array((int) $service->entity, array_map('intval', explode(',', getEntity('product'))), true)) throw new RuntimeException('CDavGenerationService');
					$item = array('label' => $service->label, 'description' => $service->description, 'duration' => (string) $service->duration);
					if ($stage === 'INI') $before[] = $item; else $after[] = $item;
				}
			}
			$tasks = array_merge($before, $tasks, $after);
			if (!$tasks) { $this->db->commit(); return 0; }
			$role = getDolGlobalInt('CDAV_TASK_USER_ROLE');
			$res = $this->db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."c_type_contact WHERE rowid=".$role." AND element='project_task' AND source='internal' AND active=1");
			if (!$res || !is_object($this->db->fetch_object($res))) throw new RuntimeException('CDavGenerationRole');
			$assigned = (int) $user->id;
			$contacts = $project->liste_contact(-1, 'internal');
			if (!is_array($contacts)) throw new RuntimeException('CDavGenerationFailed');
			foreach ($contacts as $contact) {
				if ((int) $contact['fk_c_type_contact'] === getDolGlobalInt('CDAV_PROJ_USER_ROLE') && (int) $contact['status'] === 4) { $assigned = (int) $contact['id']; break; }
			}
			$res = $this->db->query('SELECT u.rowid FROM '.MAIN_DB_PREFIX.'user u WHERE '.cdavCalendarUserScope().' AND u.rowid='.$assigned);
			if (!$res || !is_object($this->db->fetch_object($res))) throw new RuntimeException('CDavGenerationRole');
			$startHour = getDolGlobalString('CDAV_TASK_HOUR_INI') === '' ? 7 : getDolGlobalInt('CDAV_TASK_HOUR_INI');
			$endHour = getDolGlobalString('CDAV_TASK_HOUR_END') === '' ? 19 : getDolGlobalInt('CDAV_TASK_HOUR_END');
			if (empty($project->date_start) || $startHour < 0 || $endHour > 23 || $endHour <= $startHour) throw new RuntimeException('CDavGenerationDate');
			$date = dol_getdate($project->date_start);
			$start = dol_mktime($startHour, 0, 0, $date['mon'], $date['mday'], $date['year']);
			$addon = getDolGlobalString('PROJECT_TASK_ADDON', 'mod_task_simple');
			if (!preg_match('/^[a-zA-Z0-9_]+$/D', $addon) || !is_readable(DOL_DOCUMENT_ROOT.'/core/modules/project/task/'.$addon.'.php')) throw new RuntimeException('CDavGenerationNumbering');
			require_once DOL_DOCUMENT_ROOT.'/core/modules/project/task/'.$addon.'.php';
			$numbering = new $addon();
			foreach ($tasks as $row) {
				$task = new Task($this->db);
				$task->entity = (int) $project->entity; $task->fk_project = (int) $project->id;
				$task->fk_task_parent = 0; $task->fk_statut = 0; $task->date_c = dol_now();
				$task->label = trim(strip_tags($row['label'])); $task->description = trim(strip_tags($row['description']));
				$task->date_start = $start; $task->date_end = self::endDate($start, $row['duration'], $startHour, $endHour);
				$task->ref = $numbering->getNextValue(null, $task);
				if (!is_string($task->ref) || $task->ref === '') throw new RuntimeException('CDavGenerationNumbering');
				if ($task->create($user) <= 0 || $task->add_contact($assigned, $role, 'internal') <= 0) throw new RuntimeException('CDavGenerationFailed');
			}
			$this->db->commit();
			return count($tasks);
		} catch (Throwable $error) {
			$this->db->rollback();
			$this->error = $langs->trans($error instanceof RuntimeException ? $error->getMessage() : 'CDavGenerationFailed');
			$this->errors[] = $this->error;
			dol_syslog(__METHOD__.' failed', LOG_ERR);
			return -1;
		}
	}

	/** Working-day spans retain the historical CDav rule; date arithmetic uses the core.
	 * @param int $start @param string $duration @param int $startHour @param int $endHour @return int
	 */
	public static function endDate($start, $duration, $startHour, $endHour)
	{
		if (trim($duration) === '') return (int) dol_time_plus_duree($start, 1, 'h');
		if (!preg_match('/^\s*([0-9]{1,5})\s*(min|mn|i|h|j|d|t|s|w)\s*$/iD', $duration, $parts)) throw new RuntimeException('CDavGenerationDuration');
		$value = (int) $parts[1]; $unit = strtolower($parts[2]);
		if ($value === 0) return $start;
		if (in_array($unit, array('j', 'd', 't', 's', 'w'), true)) {
			$days = in_array($unit, array('s', 'w'), true) ? $value * 7 : $value;
			$lastDay = dol_time_plus_duree($start, $days - 1, 'd');
			return (int) dol_time_plus_duree($lastDay, $endHour - $startHour, 'h');
		}
		return (int) dol_time_plus_duree($start, $value, $unit);
	}
}
