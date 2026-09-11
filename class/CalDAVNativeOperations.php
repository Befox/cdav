<?php
namespace Sabre\CalDAV\Backend;

use Sabre\DAV;

/** Transactions across native calendar objects, assignments and protocol metadata. */
trait CalDAVNativeOperations
{
	/** @return array{0: string, 1: \ActionComm|\Task|\Fichinter, 2: \FichinterLigne|null} */
	private function loadCalendarTarget(array $existing)
	{
		require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
		require_once DOL_DOCUMENT_ROOT.'/projet/class/task.class.php';
		require_once DOL_DOCUMENT_ROOT.'/fichinter/class/fichinter.class.php';
		$source = $existing['source'];
		$line = null;
		if ($source === 'fi') {
			$line = new \FichinterLigne($this->db);
			if ($line->fetch((int) $existing['id']) <= 0) throw new DAV\Exception\NotFound();
			$object = new \Fichinter($this->db);
			$id = (int) $line->fk_fichinter;
			$scope = 'intervention';
		} elseif ($source === 'pe' || $source === 'pt') {
			$object = new \Task($this->db); $id = (int) $existing['id']; $scope = 'project';
		} else {
			$object = new \ActionComm($this->db); $id = (int) $existing['id']; $scope = 'agenda';
		}
		if ($object->fetch($id) <= 0) throw new DAV\Exception\NotFound();
		if ($source === 'pe' || $source === 'pt') {
			// Task::fetch() in v16 does not load entity; the parent is authoritative.
			require_once DOL_DOCUMENT_ROOT.'/projet/class/project.class.php';
			$project = new \Project($this->db);
			if ($project->fetch((int) $object->fk_project) <= 0) throw new DAV\Exception\NotFound();
			$object->entity = (int) $project->entity;
		}
		if (!in_array((int) $object->entity, array_map('intval', explode(',', getEntity($scope, 1))), true)) throw new DAV\Exception\Forbidden();
		return array($source, $object, $line);
	}

	/** Persist a parsed resource without ever selecting a business row from its body UID.
	 * @param int $calendarId Authorized calendar owner.
	 * @param string $uri Resource path segment.
	 * @param array $data Parsed iCalendar fields.
	 * @param array|null $existing Resource already found through the scoped read query.
	 * @param bool $attach Add calendar assignment on COPY/MOVE/create.
	 * @return null
	 */
	private function persistCalendarObject($calendarId, $uri, array $data, $existing, $attach)
	{
		global $conf;
		if ($uri === '' || strlen($uri) > 255 || strpbrk($uri, '/'.chr(92)) !== false || preg_match('/[\x00-\x20]/', $uri)
			|| empty($data['uid']) || strlen($data['uid']) > 255 || !in_array($data['componentType'], array('VEVENT', 'VTODO'), true)
			|| !preg_match('/^[0-9]$/D', (string) $data['priority']) || !is_numeric($data['percent']) || $data['percent'] < -1 || $data['percent'] > 100
			|| !is_numeric($data['start']) || !is_numeric($data['end']) || $data['end'] < $data['start']) throw new DAV\Exception\BadRequest('Invalid calendar data');
		$source = $existing ? $existing['source'] : 'ev';
		if ($existing) {
			list($source, $object, $line) = $this->loadCalendarTarget($existing);
		} else {
			require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
			$object = new \ActionComm($this->db); $object->entity = (int) $conf->entity; $object->userownerid = $calendarId; $line = null;
		}
		if ($source === 'ev' && !$this->user->hasRight('agenda', (int) $object->userownerid === (int) $this->user->id && $calendarId === (int) $this->user->id ? 'myactions' : 'allactions', 'create')) throw new DAV\Exception\Forbidden();
		if (($source === 'pe' || $source === 'pt') && (!$this->user->hasRight('projet', 'creer') || !\CDavCompatibility::isFeatureAvailable('tasks'))) throw new DAV\Exception\Forbidden();
		if ($source === 'fi' && (!$this->user->hasRight('ficheinter', 'creer') || !\CDavCompatibility::isFeatureAvailable('interventions'))) throw new DAV\Exception\Forbidden();
		// MySQL/MariaDB lock makes repeated/concurrent PUTs of the same URI idempotent
		// without changing the historical actioncomm_cdav primary key or old UIDs.
		$lock = 'cdav-'.substr(hash('sha256', (int) $conf->entity.':'.$uri), 0, 50);
		$res = $this->db->query("SELECT GET_LOCK('".$lock."', 5) AS locked");
		if (!$res || !is_object($locked = $this->db->fetch_object($res)) || (int) $locked->locked !== 1) throw new DAV\Exception\Conflict('Calendar resource is busy');
		$this->db->begin();
		try {
			if ($source === 'ev') {
				$occurrences = $data['occurences'];
				if (!$occurrences) $occurrences = array((object) array('start' => $data['start'], 'end' => $data['end']));
				foreach ($occurrences as $index => $occurrence) {
					$occurrenceUri = $index === 0 ? $uri : 'cdav-occ-'.hash('sha256', $uri.':'.$occurrence->start).'.ics';
					$event = $index === 0 && $existing ? $object : null;
					if ($event === null) {
						// External UIDs are local to the target entity. Native shared URIs were
						// resolved separately through the read path before entering this method.
						$sql = "SELECT a.id FROM ".MAIN_DB_PREFIX."actioncomm a INNER JOIN ".MAIN_DB_PREFIX."actioncomm_cdav c ON c.fk_object=a.id WHERE a.entity=".(int) $conf->entity." AND c.uuidext='".$this->db->escape($occurrenceUri)."'";
						$res = $this->db->query($sql);
						if (!$res) throw new DAV\Exception('Calendar lookup failed');
						if ($this->db->num_rows($res) > 1) throw new DAV\Exception\Conflict('Ambiguous legacy recurring URI');
						$event = new \ActionComm($this->db);
						if (is_object($row = $this->db->fetch_object($res))) {
							if ($event->fetch((int) $row->id) <= 0) throw new DAV\Exception\NotFound();
							if (!$this->user->hasRight('agenda', (int) $event->userownerid === (int) $this->user->id && $calendarId === (int) $this->user->id ? 'myactions' : 'allactions', 'create')) throw new DAV\Exception\Forbidden();
						} else {
							$event->entity = (int) $conf->entity; $event->userownerid = $calendarId;
							$typeResult = $this->db->query("SELECT id FROM ".MAIN_DB_PREFIX."c_actioncomm WHERE code='AC_RDV' AND active=1");
							if (!$typeResult || !is_object($type = $this->db->fetch_object($typeResult))) throw new DAV\Exception\Conflict('Agenda type unavailable');
							$event->type_id = (int) $type->id; $event->type_code = 'AC_RDV';
						}
					}
					$event->oldcopy = clone $event;
					$event->label = $data['label']; $event->datep = (int) $occurrence->start;
					$event->datef = (int) $occurrence->end - ($data['fullday'] ? 1 : 0);
					$event->fulldayevent = (int) $data['fullday']; $event->location = $data['location'];
					$event->priority = (int) $data['priority']; $event->transparency = (int) $data['transparency'];
					$event->note_private = $data['note']; $event->percentage = (int) $data['percent'];
					$event->userassigned[$calendarId] = array_replace($event->userassigned[$calendarId] ?? array(), array('id' => $calendarId, 'transparency' => (int) $data['transparency']));
					$isNew = empty($event->id);
					if (($isNew ? $event->create($this->user) : $event->update($this->user)) <= 0) throw new DAV\Exception\Conflict($event->error);
					if ($isNew) {
						$uid = $index === 0 ? $data['uid'] : 'cdav-occ-'.hash('sha256', $data['uid'].':'.$occurrence->start);
						$sql = "INSERT INTO ".MAIN_DB_PREFIX."actioncomm_cdav(fk_object,uuidext,sourceuid) VALUES (".(int) $event->id.",'".$this->db->escape($occurrenceUri)."','".$this->db->escape($uid)."')";
						if (!$this->db->query($sql)) throw new DAV\Exception('Calendar metadata update failed');
					}
				}
			} else {
				$object->oldcopy = clone $object;
				if ($source === 'fi') {
					$line->datei = (int) $data['start'];
					$line->duration = (int) $data['end'] - (int) $data['start']; $line->desc = $data['note'];
					if ($line->update($this->user) <= 0) throw new DAV\Exception\Conflict($line->error);
					if ($object->fetch($object->id) <= 0) throw new DAV\Exception\NotFound();
				} else {
					$object->label = $data['label']; $object->date_start = (int) $data['start'];
					$object->date_end = (int) $data['end'] - ($data['fullday'] ? 1 : 0);
					$object->description = $data['note']; $object->priority = (int) $data['priority'];
					if ($source === 'pt') $object->progress = (int) $data['percent'];
				}
				if ($attach) {
					$element = $source === 'fi' ? 'fichinter' : 'project_task';
					$role = $source === 'fi' ? (int) CDAV_INTERV_USER_ROLE : (int) CDAV_TASK_USER_ROLE;
					$res = $this->db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."c_type_contact WHERE rowid=".$role." AND element='".$element."' AND source='internal' AND active=1");
					if (!$res || !is_object($this->db->fetch_object($res))) throw new DAV\Exception\Conflict('Contact role unavailable');
					$found = false;
					$contacts = $object->liste_contact(-1, 'internal');
					if (!is_array($contacts)) throw new DAV\Exception('Contact lookup failed');
					foreach ($contacts as $contact) { if ((int) $contact['id'] === $calendarId) $found = true; }
					if (!$found && $object->add_contact($calendarId, $role, 'internal') < 0) throw new DAV\Exception\Conflict($object->error);
				}
				if ($object->update($this->user) < 0) throw new DAV\Exception\Conflict($object->error);
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollback(); throw $e;
		} finally {
			$this->db->query("SELECT RELEASE_LOCK('".$lock."')");
		}
		return null;
	}
}
