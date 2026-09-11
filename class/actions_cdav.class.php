<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
require_once __DIR__.'/../lib/cdav.lib.php';

/** Display assignments on the native task table. Mutations use native triggers. */
class ActionsCDav
{
	/** @var string */ public $error = '';
	/** @var list<string> */ public $errors = array();
	/** @var array<string, string> */ public $results = array();
	/** @var string */ public $resprints = '';

	/** @param array $parameters @param CommonObject $object @param string $action @return int */
	public function formObjectOptions($parameters, &$object, &$action)
	{
		global $user, $db;
		if (($parameters['currentcontext'] ?? '') !== 'projecttaskscard' || empty($parameters['id']) || !isModEnabled('cdav') || !isModEnabled('project')) return 0;
		if (!$user->hasRight('projet', 'lire') || !empty($user->socid)) return 0;
		require_once DOL_DOCUMENT_ROOT.'/projet/class/project.class.php';
		$project = new Project($db);
		$ids = $project->getProjectsAuthorizedForUser($user, $user->hasRight('projet', 'all', 'lire') ? 2 : 0, 1);
		if (!in_array((int) $parameters['id'], array_map('intval', explode(',', (string) $ids)), true)) return 0;
		$sql = 'SELECT DISTINCT pt.rowid, u.login FROM '.MAIN_DB_PREFIX.'projet_task pt';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'projet p ON p.rowid=pt.fk_projet AND p.entity IN ('.$db->sanitize(getEntity('project')).')';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'element_contact ec ON ec.element_id=pt.rowid AND ec.statut=4';
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."c_type_contact tc ON tc.rowid=ec.fk_c_type_contact AND tc.element='project_task' AND tc.source='internal' AND tc.active=1";
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'user u ON u.rowid=ec.fk_socpeople';
		$sql .= ' WHERE '.cdavCalendarUserScope().' AND pt.fk_projet='.(int) $parameters['id'].' AND pt.entity IN ('.$db->sanitize(getEntity('project')).') ORDER BY pt.rowid, u.login';
		$result = $db->query($sql);
		if (!$result) { $this->error = $db->lasterror(); return -1; }
		$icons = array();
		while (is_object($row = $db->fetch_object($result))) {
			$icons[(int) $row->rowid] = ($icons[(int) $row->rowid] ?? '').' '.img_picto($row->login, 'user');
		}
		// Existing task rows have no dedicated cell hook on v16. Encode complete native HTML.
		print '<script>jQuery(function($) { const icons = '.json_encode($icons, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT).'; Object.keys(icons).forEach(function(id) { $("tr#row-"+id+" td:first-child").append(icons[id]); }); });</script>';
		return 0;
	}
}
