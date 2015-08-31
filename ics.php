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

if(is_file('../main.inc.php'))
	$dir = '../';
elseif(is_file('../../../main.inc.php'))
	$dir = '../../../';
else 
	$dir = '../../';
	
require $dir.'main.inc.php';	// Load $user and permissions

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

// Load traductions files requiredby by page
$langs->load("cdav");

//parse Token
$arrTmp = explode('+ø+', mcrypt_ecb(MCRYPT_BLOWFISH, CDAV_URI_KEY, base64url_decode(GETPOST('token')),MCRYPT_DECRYPT));

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