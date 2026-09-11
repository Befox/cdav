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
 *   	\file       cdav/cdavurls.php
 *		\ingroup    cdav
 *		\brief      This page displays urls for carddav and caldav sync
 *
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

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/barcode.lib.php'; // This is to include def like $genbarcode_loc and $font_loc
require_once dol_buildpath('/cdav/lib/cdav.lib.php');

function base64url_encode($data) {
  return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

// Load traductions files requiredby by page
$langs->loadLangs(array('cdav@cdav', 'agenda', 'companies'));
require_once __DIR__.'/class/cdavcompatibility.class.php';


require __DIR__.'/lib/cdav_constants.php';

// Get parameters
$id			= GETPOST('id','int');
$action		= GETPOST('action','alpha');
$backtopage = GETPOST('backtopage');
$type		= GETPOST('type','alpha');

// Protection if external user
if (!empty($user->societe_id) || !empty($user->socid)) // external user
{
	accessforbidden();
}

if (!isModEnabled('cdav') || !in_array($type, array('CardDAV', 'CalDAV', 'ICS'), true)) accessforbidden();
if (($type === 'CardDAV' && (!CDavCompatibility::isFeatureAvailable('carddav') || !$user->hasRight('societe', 'contact', 'lire')))
	|| ($type !== 'CardDAV' && (!CDavCompatibility::isFeatureAvailable(strtolower($type)) || !$user->hasRight('agenda', 'myactions', 'read')))) accessforbidden();
header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');


$cdaventity = cdavEntityUriSegment();
$cdavserverurl = dol_buildpath('/cdav/server.php', 2).$cdaventity;
$cdavicsurl = dol_buildpath('/cdav/ics.php', 2).'?entity='.(int) $conf->entity.'&token=';
llxHeader('', $langs->trans($type.'url'));
print load_fiche_titre($langs->trans($type.'url'), '', 'technic');
print '<p>'.$langs->trans('CDavEntitySettings', (int) $conf->entity).'</p>';
$urls = array();
if ($type !== 'ICS') {
	$urls[] = array($langs->trans('URLGeneric'), $cdavserverurl.'/');
	$urls[] = array($langs->trans('URLUserAccount'), $cdavserverurl.'/principals/'.rawurlencode($user->login).'/');
}
if ($type === 'CardDAV') {
	$urls[] = array($langs->trans('URLforCardDAV'), $cdavserverurl.'/addressbooks/'.rawurlencode($user->login).'/default/');
} else {
	$sql = 'SELECT u.rowid, u.login, u.firstname, u.lastname FROM '.MAIN_DB_PREFIX.'user u WHERE '.cdavCalendarUserScope();
	if (!$user->hasRight('agenda', 'allactions', 'read')) $sql .= ' AND u.rowid='.(int) $user->id;
	$resql = $db->query($sql.' ORDER BY u.login');
	if ($resql) {
		while (is_object($calendarUser = $db->fetch_object($resql))) {
			$label = trim($calendarUser->firstname.' '.$calendarUser->lastname).' ('.$calendarUser->login.')';
			if ($type === 'CalDAV') {
				$urls[] = array($label, $cdavserverurl.'/calendars/'.rawurlencode($user->login).'/'.(int) $calendarUser->rowid.'-cal-'.rawurlencode($calendarUser->login).'/');
			} else {
				foreach (array('full' => 'Full', 'nolabel' => 'NoLabel') as $mode => $translation) {
					$encrypted = openssl_encrypt($calendarUser->rowid.'+ø+'.$mode, 'aes-256-cbc', CDAV_URI_KEY, OPENSSL_RAW_DATA, str_repeat(chr(0), 16));
					if ($encrypted !== false) $urls[] = array($label.' — '.$langs->trans($translation), $cdavicsurl.base64url_encode($encrypted));
				}
			}
		}
	}
}
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><th>'.$langs->trans('Description').'</th><th class="soixantepercent">URL</th></tr>';
foreach ($urls as $entry) {
	print '<tr class="oddeven"><td>'.dol_escape_htmltag($entry[0]).'</td><td class="wordbreak">'.showValueWithClipboardCPButton($entry[1], 0).'</td></tr>';
}
if (!$urls) print '<tr class="oddeven"><td colspan="2"><span class="opacitymedium">'.$langs->trans('NoRecordFound').'</span></td></tr>';
print '</table></div>';
if ($type !== 'ICS' && getDolGlobalInt('CDAV_QRCODE_DAVX5_ENABLED') && CDavCompatibility::isFeatureAvailable('qrcode')) {
	print '<h3>'.$langs->trans('URLForDavX5').'</h3><p>'.$langs->trans('URLForDavX5Tooltip').'</p>';
	require_once DOL_DOCUMENT_ROOT.'/core/modules/barcode/doc/tcpdfbarcode.modules.php';
	$qrmodule = new modTcpdfbarcode();
	require_once TCPDF_PATH.'tcpdf_barcodes_2d.php';
	$davx = preg_replace('#^https?://#', 'davx5://'.rawurlencode($user->login).':@', $cdavserverurl.'/');
	$barcode = new TCPDF2DBarcode($davx, $qrmodule->getTcpdfEncodingType('QRCODE'));
	print '<img alt="'.dol_escape_htmltag($langs->trans('CDavQRCode')).'" src="data:image/png;base64,'.base64_encode($barcode->getBarcodePngData()).'">';
}
llxFooter();
$db->close();
