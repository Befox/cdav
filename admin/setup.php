<?php
/******************************************************************
 * cdav is a Dolibarr module
 * It allows caldav and carddav clients to sync with Dolibarr
 * calendars and contacts.
 *
 * cdav is distributed under GNU/GPLv3 license
 * (see COPYING file)
 *
 * cdav uses Sabre/dav library http://sabre.io/dav/
 * Sabre/dav is distributed under use the three-clause BSD-license
 *
 * Author : Befox SARL http://www.befox.fr/
 *
 ******************************************************************/

/**
 *  \file          htdocs/cdav/admin/setup.php
 *  \ingroup   	cdav
 *  \brief        Setup page  of module cdav
 */

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME']; $tmp2 = realpath(__FILE__); $i = strlen($tmp) - 1; $j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
// Try main.inc.php using relative path
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

dol_include_once("/cdav/lib/cdav.lib.php");
require_once(DOL_DOCUMENT_ROOT."/core/lib/admin.lib.php");
require_once(DOL_DOCUMENT_ROOT."/core/class/html.formadmin.class.php");
require_once(DOL_DOCUMENT_ROOT."/core/class/html.form.class.php");
require_once(DOL_DOCUMENT_ROOT."/core/class/html.formother.class.php");

$langs->load("admin");
$langs->load("other");
$langs->load("cdav@cdav");

// Security check
if (! $user->admin || !empty($user->design)) accessforbidden();

$action = GETPOST('action', 'alpha');

$taskcontact_types=array();
$sql = 'SELECT rowid, libelle FROM '.MAIN_DB_PREFIX.'c_type_contact WHERE element="project_task" AND source="internal" AND active=1';
$result = $db->query($sql);
if ($result!==false)
{
	while(($row=$db->fetch_object($result))!==null)
		$taskcontact_types[$row->rowid] = $row->libelle;
}
$projcontact_types=array();
$sql = 'SELECT rowid, libelle FROM '.MAIN_DB_PREFIX.'c_type_contact WHERE element="project" AND source="internal" AND active=1';
$result = $db->query($sql);
if ($result!==false)
{
	while(($row=$db->fetch_object($result))!==null)
		$projcontact_types[$row->rowid] = $row->libelle;
}

$tasksync_method=array(
	'0' => $langs->trans("Not synchonized"),
	'1' => $langs->trans("Sync as calendar events only"),
	'2' => $langs->trans("Sync as todo tasks only"),
	'3' => $langs->trans("Sync as calendar events and todo tasks"),
);

$thirdsync_method=array(
	'0' => $langs->trans("Not synchonized"),
	'1' => $langs->trans("Only thirdparties without contact"),
	'2' => $langs->trans("All thirdparties"),
);

$form = new Form($db);
/*
 * Actions
 */

if ($action == 'setvalue') {
	// save the setting

	$valCDAV_URI_KEY = substr(GETPOST('CDAV_URI_KEY', 'alphanohtml'),0,8);
	if($valCDAV_URI_KEY=='')
		$valCDAV_URI_KEY = substr(md5(time()),0,8);

	dolibarr_set_const(
									$db, "CDAV_URI_KEY",
									$valCDAV_URI_KEY, 'chaine', 0, '', $conf->entity
	);
	dolibarr_set_const(
									$db, "CDAV_CONTACT_TAG",
									GETPOST('CDAV_CONTACT_TAG', 'alphanohtml'), 'chaine', 0, '', $conf->entity
	);
	dolibarr_set_const(
									$db, "CDAV_THIRD_SYNC",
									GETPOST('CDAV_THIRD_SYNC', 'alphanohtml'), 'chaine', 0, '', $conf->entity
	);
	dolibarr_set_const(
									$db, "CDAV_MEMBER_SYNC",
									GETPOST('CDAV_MEMBER_SYNC', 'alphanohtml'), 'chaine', 0, '', $conf->entity
	);
	dolibarr_set_const(
									$db, "CDAV_SYNC_PAST",
									GETPOST('CDAV_SYNC_PAST', 'alphanohtml'), 'chaine', 0, '', $conf->entity
	);
	dolibarr_set_const(
									$db, "CDAV_SYNC_FUTURE",
									GETPOST('CDAV_SYNC_FUTURE', 'alphanohtml'), 'chaine', 0, '', $conf->entity
	);
	dolibarr_set_const(
									$db, "CDAV_TASK_SYNC",
									GETPOST('CDAV_TASK_SYNC', 'alphanohtml'), 'chaine', 0, '', $conf->entity
	);
	dolibarr_set_const(
									$db, "CDAV_TASK_USER_ROLE",
									GETPOST('CDAV_TASK_USER_ROLE', 'alphanohtml'), 'chaine', 0, '', $conf->entity
	);
	dolibarr_set_const(
									$db, "CDAV_GENTASK",
									GETPOST('CDAV_GENTASK', 'alphanohtml'), 'chaine', 0, '', $conf->entity
	);
	dolibarr_set_const(
									$db, "CDAV_GENTASK_INI1",
									GETPOST('CDAV_GENTASK_INI1', 'alphanohtml'), 'chaine', 0, '', $conf->entity
	);
	dolibarr_set_const(
									$db, "CDAV_GENTASK_INI2",
									GETPOST('CDAV_GENTASK_INI2', 'alphanohtml'), 'chaine', 0, '', $conf->entity
	);
	dolibarr_set_const(
									$db, "CDAV_GENTASK_INI3",
									GETPOST('CDAV_GENTASK_INI3', 'alphanohtml'), 'chaine', 0, '', $conf->entity
	);
	dolibarr_set_const(
									$db, "CDAV_GENTASK_END1",
									GETPOST('CDAV_GENTASK_END1', 'alphanohtml'), 'chaine', 0, '', $conf->entity
	);
	dolibarr_set_const(
									$db, "CDAV_GENTASK_END2",
									GETPOST('CDAV_GENTASK_END2', 'alphanohtml'), 'chaine', 0, '', $conf->entity
	);
	dolibarr_set_const(
									$db, "CDAV_GENTASK_END3",
									GETPOST('CDAV_GENTASK_END3', 'alphanohtml'), 'chaine', 0, '', $conf->entity
	);
	dolibarr_set_const(
									$db, "CDAV_PROJ_USER_ROLE",
									GETPOST('CDAV_PROJ_USER_ROLE', 'alphanohtml'), 'chaine', 0, '', $conf->entity
	);
	dolibarr_set_const(
									$db, "CDAV_GENTASK_SERVICE_TAG",
									GETPOST('CDAV_GENTASK_SERVICE_TAG', 'alphanohtml'), 'chaine', 0, '', $conf->entity
	);
	dolibarr_set_const(
									$db, "CDAV_EXTRAFIELD_DURATION",
									GETPOST('CDAV_EXTRAFIELD_DURATION', 'alphanohtml'), 'chaine', 0, '', $conf->entity
	);
	dolibarr_set_const(
									$db, "CDAV_TASK_HOUR_INI",
									GETPOST('CDAV_TASK_HOUR_INI', 'alphanohtml'), 'chaine', 0, '', $conf->entity
	);
	dolibarr_set_const(
									$db, "CDAV_TASK_HOUR_END",
									GETPOST('CDAV_TASK_HOUR_END', 'alphanohtml'), 'chaine', 0, '', $conf->entity
	);
	dolibarr_set_const(
									$db, "CDAV_QRCODE_DAVX5_ENABLED",
									GETPOST('CDAV_QRCODE_DAVX5_ENABLED', 'alphanohtml'), 'chaine', 0, '', $conf->entity
);


	$mesg = "<font class='ok'>".$langs->trans("SetupSaved")."</font>";
}

/*
 * View
 */

$page_name = $langs->trans("CDav Setup") . " - " . $langs->trans("CDav General Setting");
llxHeader('', $page_name);

$linkback='<a href="'.DOL_URL_ROOT.'/admin/modules.php">'.$langs->trans("BackToModuleList").'</a>';
print_fiche_titre($page_name, $linkback, 'title_setup');

$CDAV_URI_KEY=substr($conf->global->CDAV_URI_KEY,0,8);
$CDAV_CONTACT_TAG=$conf->global->CDAV_CONTACT_TAG;
$CDAV_THIRD_SYNC=$conf->global->CDAV_THIRD_SYNC;
$CDAV_MEMBER_SYNC=$conf->global->CDAV_MEMBER_SYNC;
$CDAV_SYNC_PAST=$conf->global->CDAV_SYNC_PAST;
$CDAV_SYNC_FUTURE=$conf->global->CDAV_SYNC_FUTURE;
$CDAV_TASK_SYNC=$conf->global->CDAV_TASK_SYNC;
$CDAV_TASK_USER_ROLE=$conf->global->CDAV_TASK_USER_ROLE;
$CDAV_GENTASK=$conf->global->CDAV_GENTASK;
$CDAV_GENTASK_INI1=$conf->global->CDAV_GENTASK_INI1;
$CDAV_GENTASK_INI2=$conf->global->CDAV_GENTASK_INI2;
$CDAV_GENTASK_INI3=$conf->global->CDAV_GENTASK_INI3;
$CDAV_GENTASK_END1=$conf->global->CDAV_GENTASK_END1;
$CDAV_GENTASK_END2=$conf->global->CDAV_GENTASK_END2;
$CDAV_GENTASK_END3=$conf->global->CDAV_GENTASK_END3;
$CDAV_PROJ_USER_ROLE=$conf->global->CDAV_PROJ_USER_ROLE;
$CDAV_GENTASK_SERVICE_TAG=$conf->global->CDAV_GENTASK_SERVICE_TAG;
$CDAV_EXTRAFIELD_DURATION=$conf->global->CDAV_EXTRAFIELD_DURATION;
$CDAV_TASK_HOUR_INI=$conf->global->CDAV_TASK_HOUR_INI;
$CDAV_TASK_HOUR_END=$conf->global->CDAV_TASK_HOUR_END;
$CDAV_QRCODE_DAVX5_ENABLED=$conf->global->CDAV_QRCODE_DAVX5_ENABLED;


dol_fiche_head('', 'setup', $langs->trans("CDav"), 0, "cdav@cdav");

print_titre($langs->trans("CDav Setting Value"));
print '<br>';
print '<form method="post" action="setup.php">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="setvalue">';
print '<table class="noborder" >';
print '<tr class="liste_titre">';
print '<td width="50%" align=left>'.$langs->trans("desc").'</td>';
print '<td align=left>'.$langs->trans("value").'</td>';
print '</tr>'."\n";

print '<tr >';
print '<td  align=left><strong>'.$langs->trans("Sync token").'</strong><br/>'.$langs->trans("Change it to force client to resync").'</td>';
print '<td  align=left>';
print '<input size="8" type="text" class="flat" name="CDAV_URI_KEY" value="'.htmlentities($CDAV_URI_KEY).'">';
print '</td></tr>'."\n";

print '<tr >';
print '<td  align=left><strong>'.$langs->trans("Contacts filter").'</strong><br/>'.$langs->trans("Contact tag to restrict contacts to sync, leave blank to sync all").'</td>';
print '<td  align=left>';
print $form->select_all_categories("contact", $CDAV_CONTACT_TAG, 'CDAV_CONTACT_TAG', 0);
print '</td></tr>'."\n";

print '<tr >';
print '<td  align=left><strong>'.$langs->trans("Enable thirdparties sync").'</strong><br/>'.$langs->trans("How to synchronize thirparties").'</td>';
print '<td  align=left>';
print $form->selectarray('CDAV_THIRD_SYNC', $thirdsync_method, $CDAV_THIRD_SYNC);
print '</td></tr>'."\n";

print '<tr >';
print '<td  align=left><strong>'.$langs->trans("EnableMembersSync").'</strong><br/>'.$langs->trans("GenerateAddressbookForMembership").'</td>';
print '<td  align=left>';
print $form->selectyesno('CDAV_MEMBER_SYNC', $CDAV_MEMBER_SYNC, 1);
print '</td></tr>'."\n";

print '<tr >';
print '<td  align=left><strong>'.$langs->trans("Period to sync").'</strong><br/>'.$langs->trans("Number of days to sync before and after today").'</td>';
print '<td  align=left>';
print $langs->trans("In past:").' <input size="4" type="text" class="flat" name="CDAV_SYNC_PAST" value="'.htmlentities($CDAV_SYNC_PAST).'"> '.$langs->trans("days");
print '<br />';
print $langs->trans("In future:").' <input size="4" type="text" class="flat" name="CDAV_SYNC_FUTURE" value="'.htmlentities($CDAV_SYNC_FUTURE).'"> '.$langs->trans("days");
print '</td></tr>'."\n";

print '<tr class="liste_titre">';
print '<td align="center" colspan="2">'.$langs->trans("Project tasks synchronization").'</td>';
print '</tr>'."\n";

print '<tr >';
print '<td  align=left><strong>'.$langs->trans("Enable project tasks sync").'</strong><br/>'.$langs->trans("How to synchronize project tasks").'</td>';
print '<td  align=left>';
print $form->selectarray('CDAV_TASK_SYNC', $tasksync_method, $CDAV_TASK_SYNC);
print '</td></tr>'."\n";

print '<tr >';
print '<td  align=left><strong>'.$langs->trans("Generate tasks from documents").'</strong><br/>'.$langs->trans("Generate project tasks for each service lines from attached documents (proposals or orders) on project validation. Only the lastest documents are used in case of inheritance").'</td>';
print '<td  align=left>';
print $form->selectyesno('CDAV_GENTASK', $CDAV_GENTASK, 1);
print '</td></tr>'."\n";

print '<tr >';
print '<td  align=left><strong>'.$langs->trans("Generate initial tasks from services").'</strong><br/>'.$langs->trans("Generate project initial tasks for each seleted services on project validation.").'</td>';
print '<td  align=left>';
print $form->select_produits($CDAV_GENTASK_INI1, 'CDAV_GENTASK_INI1', 1);
print '<br />';
print $form->select_produits($CDAV_GENTASK_INI2, 'CDAV_GENTASK_INI2', 1);
print '<br />';
print $form->select_produits($CDAV_GENTASK_INI3, 'CDAV_GENTASK_INI3', 1);
print '</td></tr>'."\n";

print '<tr >';
print '<td  align=left><strong>'.$langs->trans("Generate final tasks from services").'</strong><br/>'.$langs->trans("Generate project final tasks for each seleted services on project validation.").'</td>';
print '<td  align=left>';
print $form->select_produits($CDAV_GENTASK_END1, 'CDAV_GENTASK_END1', 1);
print '<br />';
print $form->select_produits($CDAV_GENTASK_END2, 'CDAV_GENTASK_END2', 1);
print '<br />';
print $form->select_produits($CDAV_GENTASK_END3, 'CDAV_GENTASK_END3', 1);
print '</td></tr>'."\n";

print '<tr >';
print '<td  align=left><strong>'.$langs->trans("Project user role").'</strong><br/>'.$langs->trans("User role in project to select user to attribute on generated tasks from documents").'</td>';
print '<td  align=left>';
print $form->selectarray('CDAV_PROJ_USER_ROLE', $projcontact_types, $CDAV_PROJ_USER_ROLE);
print '</td></tr>'."\n";

print '<tr >';
print '<td  align=left><strong>'.$langs->trans("Project task user role").'</strong><br/>'.$langs->trans("User role on new project task creation").'</td>';
print '<td  align=left>';
print $form->selectarray('CDAV_TASK_USER_ROLE', $taskcontact_types, $CDAV_TASK_USER_ROLE);
print '</td></tr>'."\n";

print '<tr >';
print '<td  align=left><strong>'.$langs->trans("Project task working hours").'</strong><br/>'.$langs->trans("Start and end time of a working day").'</td>';
print '<td  align=left>';
print $langs->trans("Begining at:").' <input size="4" type="text" class="flat" name="CDAV_TASK_HOUR_INI" value="'.htmlentities($CDAV_TASK_HOUR_INI).'"> '.$langs->trans("hour");
print '<br />';
print $langs->trans("Ending at:").' <input size="4" type="text" class="flat" name="CDAV_TASK_HOUR_END" value="'.htmlentities($CDAV_TASK_HOUR_END).'"> '.$langs->trans("hour");
print '</td></tr>'."\n";

print '<tr >';
print '<td  align=left><strong>'.$langs->trans("Services filter TAG").'</strong><br/>'.$langs->trans("Service tag to restrict services to be converted as task, leave blank to sync all").'</td>';
print '<td  align=left>';
print $form->select_all_categories("product", $CDAV_GENTASK_SERVICE_TAG, 'CDAV_GENTASK_SERVICE_TAG', 0);
print '</td></tr>'."\n";

print '<tr >';
print '<td  align=left><strong>'.$langs->trans("Service duration from documents").'</strong><br/>'.$langs->trans("Generate project tasks for each service lines from attached documents with cdav duration, even if TAG is missing").'</td>';
print '<td  align=left>';
print $form->selectyesno('CDAV_EXTRAFIELD_DURATION', $CDAV_EXTRAFIELD_DURATION, 1);
print '</td></tr>'."\n";

print '<tr >';
print '<td  align=left><strong>'.$langs->trans("ActivateDavX5autoURL").'</strong><br/>'.$langs->trans("ActivateDavX5autoURLTooltip").'</td>';
print '<td  align=left>';
print $form->selectyesno('CDAV_QRCODE_DAVX5_ENABLED', $CDAV_QRCODE_DAVX5_ENABLED, 1);
print '</td></tr>'."\n";

// Boutons d'action
print '<tr ><td>';
print '<div class="tabsAction">';
print '<input type="submit" class="button" value="'.$langs->trans("Modify").'">';
print '</div>';
print '</td></tr>'."\n";
print '</table>';
print '</form>';
// Show errors
print "<br>";

if(!empty($object)) { // Fix Warning: Attempt to read property "error" on null : Is $object really used on this page?
	dol_htmloutput_errors($object->error, $object->errors);
	// Show messages
	dol_htmloutput_mesg($object->mesg, '', 'ok');
}

// Footer
llxFooter();
$db->close();
