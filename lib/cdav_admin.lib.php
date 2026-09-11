<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/** @return array<int, array{0: string, 1: string, 2: string}> */
function cdavAdminPrepareHead()
{
	global $langs;
	$head = array();
	foreach (array('setup' => 'Settings', 'carddav' => 'CardDAV', 'caldav' => 'CalDAV', 'compatibility' => 'Compatibility', 'about' => 'About') as $tab => $label) {
		$head[] = array(dol_buildpath('/cdav/admin/'.$tab.'.php', 1), $langs->trans($label), $tab);
	}
	return $head;
}

/**
 * One schema for the settings displayed and accepted by each tab.
 * @param string $tab Tab identifier.
 * @return array<string, array{type: string, module?: string, choices?: list<int>, element?: string}>
 */
function cdavSettingsDefinition($tab)
{
	if ($tab === 'setup') {
		return array('CDAV_URI_KEY' => array('type' => 'key'), 'CDAV_QRCODE_DAVX5_ENABLED' => array('type' => 'bool'));
	}
	if ($tab === 'carddav') {
		return array(
			'CDAV_CONTACT_TAG' => array('type' => 'contactcategory', 'module' => 'categorie'),
			'CDAV_CONTACT_SYNC_CIVILITY' => array('type' => 'bool', 'module' => 'societe'),
			'CDAV_THIRD_SYNC' => array('type' => 'select', 'choices' => array(0, 1, 2), 'module' => 'societe'),
			'CDAV_MEMBER_SYNC' => array('type' => 'bool', 'module' => 'adherent'),
		);
	}
	if ($tab !== 'caldav') {
		return array();
	}
	$settings = array(
		'CDAV_SYNC_PAST' => array('type' => 'days'),
		'CDAV_SYNC_FUTURE' => array('type' => 'days'),
		'CDAV_TASK_SYNC' => array('type' => 'select', 'choices' => array(0, 1, 2, 3), 'module' => 'project'),
		'CDAV_TASK_USER_ROLE' => array('type' => 'role', 'element' => 'project_task', 'module' => 'project'),
		'CDAV_INTERV_SYNC' => array('type' => 'select', 'choices' => array(0, 1), 'module' => 'ficheinter'),
		'CDAV_INTERV_USER_ROLE' => array('type' => 'role', 'element' => 'fichinter', 'module' => 'ficheinter'),
		'CDAV_GENTASK' => array('type' => 'bool', 'module' => 'project'),
		'CDAV_PROJ_USER_ROLE' => array('type' => 'role', 'element' => 'project', 'module' => 'project'),
	);
	foreach (array('INI', 'END') as $stage) {
		for ($i = 1; $i <= 3; $i++) {
			$settings['CDAV_GENTASK_'.$stage.$i] = array('type' => 'service', 'module' => 'service');
		}
	}
	return $settings + array(
		'CDAV_GENTASK_SERVICE_TAG' => array('type' => 'productcategory', 'module' => 'categorie'),
		'CDAV_EXTRAFIELD_DURATION' => array('type' => 'bool', 'module' => 'project'),
		'CDAV_TASK_HOUR_INI' => array('type' => 'hour', 'module' => 'project'),
		'CDAV_TASK_HOUR_END' => array('type' => 'hour', 'module' => 'project'),
	);
}

/**
 * Validate a complete tab before any write, including foreign references.
 * @param DoliDB $db Database.
 * @param string $tab Tab identifier.
 * @param array<string, string> $values Submitted values only.
 * @return list<string> Translation keys, with offending field names already translated.
 */
function cdavValidateSettings($db, $tab, array $values)
{
	global $langs;
	$errors = array();
	foreach (cdavSettingsDefinition($tab) as $key => $definition) {
		if (!array_key_exists($key, $values)) {
			continue;
		}
		$value = $values[$key];
		$type = $definition['type'];
		$valid = !isset($definition['module']) || isModEnabled($definition['module']);
		if ($type === 'key') {
			$valid = $valid && (bool) preg_match('/^[a-zA-Z0-9_-]{1,8}$/D', $value);
		} elseif ($type === 'bool') {
			$valid = $valid && in_array($value, array('0', '1'), true);
		} elseif ($type === 'select') {
			$valid = $valid && ctype_digit($value) && in_array((int) $value, $definition['choices'], true);
		} else {
			$valid = $valid && ($value === '' || (ctype_digit($value) && strlen($value) <= 9));
			if ($type === 'days') {
				$valid = $valid && $value !== '' && (int) $value <= 36500;
			} elseif ($type === 'hour') {
				$valid = $valid && ($value === '' || (int) $value <= 23);
			} elseif ($valid && (int) $value > 0) {
				$sql = '';
				if ($type === 'role') {
					$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."c_type_contact WHERE active = 1 AND source = 'internal' AND element = '".$db->escape($definition['element'])."'";
				} elseif ($type === 'service') {
					$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'product WHERE fk_product_type = 1 AND entity IN ('.$db->sanitize(getEntity('product')).')';
				} elseif ($type === 'contactcategory' || $type === 'productcategory') {
					$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'categorie WHERE type = '.($type === 'contactcategory' ? Categorie::TYPE_CONTACT : Categorie::TYPE_PRODUCT).' AND entity IN ('.$db->sanitize(getEntity('category')).')';
				}
				if ($sql !== '') {
					$resql = $db->query($sql.' AND rowid = '.((int) $value));
					$valid = $resql && is_object($db->fetch_object($resql));
				}
			}
		}
		if (!$valid) {
			$errors[] = $langs->trans('CDavInvalidSetting', $langs->trans($key));
		}
	}
	if ($tab === 'caldav') {
		$start = $values['CDAV_TASK_HOUR_INI'] ?? getDolGlobalString('CDAV_TASK_HOUR_INI');
		$end = $values['CDAV_TASK_HOUR_END'] ?? getDolGlobalString('CDAV_TASK_HOUR_END');
		if (($start !== '' || $end !== '') && (int) ($end === '' ? 19 : $end) <= (int) ($start === '' ? 7 : $start)) {
			$errors[] = $langs->trans('CDavInvalidHours');
		}
	}
	return $errors;
}
