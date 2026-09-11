<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
if (!isset($settings, $cdavAdminTab, $moduleDescriptor)) {
	http_response_code(404);
	exit;
}
$formSetup = new FormSetup($db);
foreach ($settings as $key => $definition) {
	$item = $formSetup->newItem($key);
	$item->fieldValue = $submitted[$key] ?? getDolGlobalString($key);
	$item->cssClass = 'minwidth100';
	if (isset($definition['module']) && !isModEnabled($definition['module'])) {
		$item->fieldInputOverride = '<span class="opacitymedium">'.$langs->trans('CDavModuleRequired', $definition['module']).'</span>';
		continue;
	}
	if ($definition['type'] === 'bool') {
		// Native Ajax switches persist only their own constant in the current entity.
		// v16 prints the non-Ajax link instead of returning it. Keep it in the row.
		ob_start();
		$switch = ajax_constantonoff($key, array(), (int) $conf->entity, 0, 0, 0, 2, 0, 1);
		$item->fieldInputOverride = ob_get_clean().$switch;
	} elseif ($definition['type'] === 'select') {
		$options = array();
		foreach ($definition['choices'] as $choice) {
			$options[$choice] = $langs->trans($key.'_'.$choice);
		}
		$item->setAsSelect($options);
	} elseif ($definition['type'] === 'role') {
		$options = array('' => $langs->trans('None'));
		$resql = $db->query("SELECT rowid, code, libelle FROM ".MAIN_DB_PREFIX."c_type_contact WHERE active = 1 AND source = 'internal' AND element = '".$db->escape($definition['element'])."' ORDER BY libelle");
		if ($resql) {
			while (is_object($role = $db->fetch_object($resql))) {
				$translation = 'TypeContact_'.$definition['element'].'_internal_'.$role->code;
				$options[(int) $role->rowid] = $langs->trans($translation) !== $translation ? $langs->trans($translation) : $role->libelle;
			}
		}
		$item->setAsSelect($options);
	} elseif ($definition['type'] === 'service') {
		// The native product selector's type parameter restricts this to services on v16–v24.
		$item->fieldInputOverride = $formSetup->form->select_produits($item->fieldValue, $key, 1, 0, 0, 1, 2, '', 0, array(), 0, '1', 0, 'minwidth200', 0, '', null, 1);
	} elseif ($definition['type'] === 'contactcategory' || $definition['type'] === 'productcategory') {
		$item->setAsCategory($definition['type'] === 'contactcategory' ? Categorie::TYPE_CONTACT : Categorie::TYPE_PRODUCT);
	} else {
		$item->setAsString();
		$item->fieldAttr = array('class' => 'flat minwidth100', 'maxlength' => $definition['type'] === 'key' ? 8 : 9);
	}
}
print '<div class="div-table-responsive-no-min">'.$formSetup->generateOutput(true).'</div>';
if ($cdavAdminTab === 'setup') {
	$clientSetupMessage = $langs->trans('CDavClientSetupHelp');
	$clientSetupMessage .= '<br><br><a href="'.dol_buildpath('/cdav/cdavurls.php?type=CardDAV', 1).'">'.$langs->trans('CardDAVurl').'</a> · <a href="'.dol_buildpath('/cdav/cdavurls.php?type=CalDAV', 1).'">'.$langs->trans('CalDAVurl').'</a>';
	print get_htmloutput_mesg($clientSetupMessage, array(), 'info', 1);
}
print dol_get_fiche_end();
llxFooter();
$db->close();
