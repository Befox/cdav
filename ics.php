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

define('NOTOKENRENEWAL',1); 								// Disables token renewal
if (! defined('NOLOGIN')) define('NOLOGIN','1');
if (! defined('NOCSRFCHECK')) define('NOCSRFCHECK','1');	// We accept to go on this page from external web site.
if (! defined('NOREQUIREMENU')) define('NOREQUIREMENU','1');
if (! defined('NOREQUIREHTML')) define('NOREQUIREHTML','1');
if (! defined('NOREQUIREAJAX')) define('NOREQUIREAJAX','1');
// Stateless page : without a dolibarr session, $_SESSION['dol_entity'] can not
// override the entity read from the url (see DOLENTITY below)
if (! defined('NOSESSION')) define('NOSESSION','1');
function llxHeader() { }
function llxFooter() { }



// Multicompany : the entity is given as a plain url parameter (the token can not carry it,
// it is only decipherable once CDAV_URI_KEY is known, ie after Dolibarr is loaded).
// It has to be known before loading Dolibarr environment because master.inc.php
// reads the DOLENTITY constant to set $conf->entity.
$icsEntity = $_GET['entity'] ?? '1';
if (!is_string($icsEntity) || !preg_match('/^[1-9][0-9]{0,8}$/D', $icsEntity)) {
	http_response_code(400);
	exit;
}
define('DOLENTITY', (int) $icsEntity);
// This endpoint is not a login page; prevent native bootstrap entity overrides.
if (isset($_GET['loginfunction']) || isset($_POST['loginfunction'])
	|| (session_id() !== '' && !empty($_SESSION['dol_entity'])) || !empty($_ENV['dol_entity'])) {
	http_response_code(400);
	exit;
}

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

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

// Load traductions files requiredby by page



//Get all event
require_once __DIR__.'/lib/cdav.lib.php';


require_once __DIR__.'/class/cdavcompatibility.class.php';
$langs->loadLangs(array('cdav@cdav', 'agenda', 'companies', 'projects', 'members', 'interventions'));
if ((int) $conf->entity !== DOLENTITY || !CDavCompatibility::isFeatureAvailable('ics')) {
	http_response_code(503);
	exit;
}
require __DIR__.'/lib/cdav_constants.php';

// 0 < CDAV_ADDRESSBOOK_ID_SHIFT = Contacts
// CDAV_ADDRESSBOOK_ID_SHIFT   < 2*CDAV_ADDRESSBOOK_ID_SHIFT = Thirdparties
// 2*CDAV_ADDRESSBOOK_ID_SHIFT < 3*CDAV_ADDRESSBOOK_ID_SHIFT = Members
define('CDAV_ADDRESSBOOK_ID_SHIFT', 100000);

// Existing shared links retain their historical encryption and payload format.
$token = GETPOST('token', 'alphanohtml');
if (!is_string($token) || strlen($token) > 2048 || !preg_match('/^[A-Za-z0-9_-]+$/D', $token)) {
	http_response_code(403);
	exit;
}
$encrypted = base64_decode(strtr($token, '-_', '+/'), true);
$payload = $encrypted === false ? false : openssl_decrypt($encrypted, 'aes-256-cbc', CDAV_URI_KEY, OPENSSL_RAW_DATA, str_repeat(chr(0), 16));
if ($payload === false && in_array('bf-ecb', openssl_get_cipher_methods(), true)) {
	$payload = openssl_decrypt($encrypted, 'bf-ecb', CDAV_URI_KEY, OPENSSL_RAW_DATA, '');
}
$arrTmp = is_string($payload) ? explode('+ø+', $payload) : array();
// Also accept the historical double-encoded separator from older installations.
if (count($arrTmp) !== 2 && is_string($payload)) $arrTmp = explode('+Ã¸+', $payload);

if (count($arrTmp) !== 2 || !in_array(trim($arrTmp[1]), array('nolabel', 'full'), true))
{
	http_response_code(403);
	exit;
}

$id = ctype_digit(trim($arrTmp[0])) ? (int) trim($arrTmp[0]) : 0;
$type 	= trim($arrTmp[1]);

// The token is the authorization, but $user must stay a real User object : dolibarr core
// code calls User methods on the global $user (dol_syslog() does $user->hasRight('debugbar','read')
// on every query), and a stdClass makes it fatal.
$user = new User($db);
$resql = $db->query('SELECT u.rowid FROM '.MAIN_DB_PREFIX.'user u WHERE '.cdavCalendarUserScope().' AND u.rowid='.(int) $id);
if (!$resql || !is_object($db->fetch_object($resql)) || $user->fetch($id) <= 0) {
	http_response_code(403);
	exit;
}
if (isModEnabled('multicompany')) {
	$admission = isset($mc) && is_object($mc) && method_exists($mc, 'checkRight') ? $mc->checkRight((int) $user->id, (int) $conf->entity) : null;
	if (!is_int($admission) || $admission < 0) { http_response_code(403); exit; }
}
if (version_compare(DOL_VERSION, '20.0.0', '>=')) $user->loadRights('', 1);
else $user->getrights('', 1);
if (!$user->hasRight('agenda', 'myactions', 'read')) {
	http_response_code(403);
	exit;
}
header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');

header('Content-type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename=Calendar-'.$id.'-'.$type.'.ics');



$cdavLib = new CdavLib($user, $db, $langs);

require_once DOL_DOCUMENT_ROOT.'/includes/sabre/autoload.php';
$calendar = new \Sabre\VObject\Component\VCalendar();
$calendar->PRODID = '-//Dolibarr CDav//FR';
foreach ($cdavLib->getFullCalendarObjects($id, true) as $event) {
	$source = \Sabre\VObject\Reader::read($event['calendardata']);
	foreach ($source->getComponents() as $component) {
		if ($type === 'nolabel') {
			$component->SUMMARY = $langs->transnoentities('Busy');
			unset($component->DESCRIPTION, $component->LOCATION, $component->CONTACT, $component->ATTENDEE, $component->ORGANIZER, $component->URL, $component->CATEGORIES);
		}
		$calendar->add(clone $component);
	}
}
echo $calendar->serialize();
$db->close();
