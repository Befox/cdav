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
function llxHeader() { }
function llxFooter() { }

function base64url_decode($data) {
  return base64_decode(str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=', STR_PAD_RIGHT));
} 

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME']; $tmp2 = realpath(__FILE__); $i = strlen($tmp) - 1; $j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) { $i--; $j--; }
if (!$res && $i > 0 && @file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) $res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
if (!$res && $i > 0 && @file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) $res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
// Try main.inc.php using relative path
if (!$res && @file_exists("../main.inc.php")) $res = @include "../main.inc.php";
if (!$res && @file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res && @file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (!$res) die("Include of main fails");

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

// Load traductions files requiredby by page
$langs->load("cdav");

//parse Token
$arrTmp = explode('+ø+', openssl_decrypt(base64url_decode(GETPOST('token')), 'bf-ecb', CDAV_URI_KEY, true));

if (! isset($arrTmp[1]) || ! in_array(trim($arrTmp[1]), array('nolabel', 'full')))
{
	echo 'Unauthorized Access !';
	exit;
}

$id 	= trim($arrTmp[0]);
$type 	= trim($arrTmp[1]);

header('Content-type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename=Calendar-'.$id.'-'.$type.'.ics');

//fake user having right on this calendar
$user = new stdClass();

$user->rights = new stdClass();
$user->rights->agenda = new stdClass();
$user->rights->agenda->myactions = new stdClass();
$user->rights->agenda->allactions = new stdClass();
$user->rights->societe = new stdClass();
$user->rights->societe->client = new stdClass();

$user->id = $id;
$user->rights->agenda->myactions->read = true;
$user->rights->agenda->allactions->read = true;
$user->rights->societe->client->voir = false;

//Get all event
require_once './lib/cdav.lib.php';
$cdavLib = new CdavLib($user, $db, $langs);

//Format them
$arrEvents = $cdavLib->getFullCalendarObjects($id, true);

foreach($arrEvents as $event)
{
	if ($type == 'nolabel')
	{
		//Remove SUMMARY / DESCRIPTION / LOCATION
		$event['calendardata'] = preg_replace('#SUMMARY:.*[^\n]#', 'SUMMARY:'.$langs->trans('Busy'), $event['calendardata']);//FIXME translate busy !!
		$event['calendardata'] = preg_replace('#DESCRIPTION:.*[^\n]#', 'DESCRIPTION:.', $event['calendardata']);
		$event['calendardata'] = preg_replace('#LOCATION:.*[^\n]#', 'LOCATION:', $event['calendardata']);
		echo $event['calendardata'];
	}
	else
		echo $event['calendardata'];
}
