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

error_reporting(E_ALL & ~E_NOTICE);
ini_set("display_errors", 0);
ini_set("log_errors", 1);

function exception_error_handler($errno, $errstr, $errfile, $errline) {
	if(function_exists("debug_log"))
	{
		debug_log("Error $errno : $errstr - $errfile @ $errline");
		foreach(debug_backtrace(false) as $trace)
			debug_log(" - ".$trace['file'].'@'.$trace['line'].' '.$trace['function'].'(...)');
	}
	throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
}

// debug
//$debug_file = fopen( sys_get_temp_dir() . '/cdav_'.date('Ymd').'.log','a');
$debug_file = false;

function debug_log($txt)
{
	global $debug_file;
	if ($debug_file)
	{
		fputs($debug_file, '========' . date('H:i:s').': '.$txt."\n");
		fflush($debug_file);
	}
}

// CGI may forward Basic credentials through either header. Preserve colons in passwords.
foreach (array('HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION') as $header) {
	if (!isset($_SERVER['PHP_AUTH_USER']) && isset($_SERVER[$header]) && preg_match('/^Basic\s+(.+)$/i', $_SERVER[$header], $match)) {
		$decoded = base64_decode($match[1], true);
		if (is_string($decoded) && strpos($decoded, ':') !== false) {
			list($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']) = explode(':', $decoded, 2);
		}
	}
}

define('NOTOKENRENEWAL',1); 								// Disables token renewal
if (! defined('NOLOGIN')) define('NOLOGIN','1');
if (! defined('NOCSRFCHECK')) define('NOCSRFCHECK','1');	// We accept to go on this page from external web site.
if (! defined('NOREQUIREMENU')) define('NOREQUIREMENU','1');
if (! defined('NOREQUIREHTML')) define('NOREQUIREHTML','1');
if (! defined('NOREQUIREAJAX')) define('NOREQUIREAJAX','1');
// This server is stateless (HTTP Basic auth on each request) : without a dolibarr session,
// $_SESSION['dol_entity'] can not override the entity read from the url (see DOLENTITY below)
if (! defined('NOSESSION')) define('NOSESSION','1');
function llxHeader() { }
function llxFooter() { }

require_once __DIR__.'/lib/cdav_request.lib.php';
try {
	$cdavRoute = cdavParseDavRoute($_SERVER);
} catch (InvalidArgumentException $e) {
	http_response_code(400);
	exit;
}
define('DOLENTITY', $cdavRoute['entity']);
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

if(!defined('DOL_DOCUMENT_ROOT'))
	define('DOL_DOCUMENT_ROOT', $dolibarr_main_document_root);

require DOL_DOCUMENT_ROOT.'/core/lib/security2.lib.php';	// auth method
require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';

require_once __DIR__.'/class/cdavcompatibility.class.php';
require_once __DIR__.'/lib/cdav.lib.php';
$langs->loadLangs(array('cdav@cdav', 'agenda', 'companies', 'projects', 'members', 'interventions'));
if ((int) $conf->entity !== $cdavRoute['entity'] || !CDavCompatibility::isFeatureAvailable('dav')) {
	http_response_code(503);
	exit($langs->transnoentities('CDavRequiresDAV'));
}
require __DIR__.'/lib/cdav_constants.php';

// 0 < CDAV_ADDRESSBOOK_ID_SHIFT = Contacts
// CDAV_ADDRESSBOOK_ID_SHIFT   < 2*CDAV_ADDRESSBOOK_ID_SHIFT = Thirdparties
// 2*CDAV_ADDRESSBOOK_ID_SHIFT < 3*CDAV_ADDRESSBOOK_ID_SHIFT = Members
define('CDAV_ADDRESSBOOK_ID_SHIFT', 100000); 

// Sabre/dav configuration

use Sabre\DAV;
use Sabre\DAVACL;

// The autoloader
require DOL_DOCUMENT_ROOT.'/includes/sabre/autoload.php';
require __DIR__.'/class/PrincipalsDolibarr.php';
require __DIR__.'/class/CardDAVDolibarr.php';
require __DIR__.'/class/CalDAVDolibarr.php';

// Authenticate before constructing any principal or opening any document directory.
$username = isset($_SERVER['PHP_AUTH_USER']) ? (string) $_SERVER['PHP_AUTH_USER'] : '';
$password = isset($_SERVER['PHP_AUTH_PW']) ? (string) $_SERVER['PHP_AUTH_PW'] : '';
$authmodes = array_values(array_diff(array_map('trim', explode(',', $dolibarr_main_authentication ?: 'dolibarr')), array('googlerecaptcha')));
$multicompanyActive = isModEnabled('multicompany');
$transverseMode = $multicompanyActive && getDolGlobalInt('MULTICOMPANY_TRANSVERSE_MODE');
$login = $username !== '' ? checkLoginPassEntity($username, $password, (int) $conf->entity, $authmodes, 'dav') : '';
$user = new User($db);
$authenticated = false;
if (is_string($login) && $login !== '') {
	$accountEntity = $transverseMode ? 1 : (int) $conf->entity;
	$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."user WHERE login='".$db->escape($login)."' AND entity IN (0,".$accountEntity.") AND statut=1";
	$resql = $db->query($sql);
	if ($resql && $db->num_rows($resql) === 1 && is_object($row = $db->fetch_object($resql)) && $user->fetch((int) $row->rowid) > 0) {
		$authenticated = empty($user->socid) && empty($user->societe_id);
		if ($multicompanyActive) {
			// Authentication and entity admission are distinct, including centralized users.
			$admission = isset($mc) && is_object($mc) && method_exists($mc, 'checkRight') ? $mc->checkRight((int) $user->id, (int) $conf->entity) : null;
			$authenticated = $authenticated && is_int($admission) && $admission >= 0;
		}
	}
}
if (!$authenticated) {
	header('WWW-Authenticate: Basic realm="Dolibarr"');
	http_response_code(401);
	exit;
}
if (version_compare(DOL_VERSION, '20.0.0', '>=')) $user->loadRights('', 1);
else $user->getrights('', 1);
$cdavLib = new CdavLib($user, $db, $langs);
$authBackend = new DAV\Auth\Backend\BasicCallBack(function ($name, $pass) use ($username, $password) {
	return hash_equals($username, $name) && hash_equals($password, $pass);
});
$authBackend->setRealm('Dolibarr');

// Principals Backend
$principalBackend = new DAVACL\PrincipalBackend\Dolibarr($user,$db);

// CardDav & CalDav Backend
$carddavBackend   = new Sabre\CardDAV\Backend\Dolibarr($user,$db,$langs);
$caldavBackend	= new Sabre\CalDAV\Backend\Dolibarr($user,$db,$langs, $cdavLib);

// Setting up the directory tree //
$nodes = array(
	// /principals
	new DAVACL\PrincipalCollection($principalBackend),
	// /addressbook
	new \Sabre\CardDAV\AddressBookRoot($principalBackend, $carddavBackend),
	// /calendars
	new \Sabre\CalDAV\CalendarRoot($principalBackend, $caldavBackend)
);
// Expose only the entity's configured CDav public directory. Native ECM rights
// are also enforced for every filesystem operation by the DAV node.
$lockBackend = null;
if (CDavCompatibility::isFeatureAvailable('directories')) {
	require_once __DIR__.'/lib/cdav_documents.lib.php';
	$directoryObject = new stdClass();
	$directoryObject->entity = (int) $conf->entity;
	try { $cdavDirectory = cdavDocumentRoot($directoryObject, 'cdav'); }
	catch (RuntimeException $e) { http_response_code(503); exit($langs->transnoentities('CDavRequiresDirectories')); }
	if (dol_mkdir($cdavDirectory) < 0) {
		http_response_code(503);
		exit($langs->transnoentities('CDavRequiresDirectories'));
	}
	if (is_link($cdavDirectory.'/.locks')) { http_response_code(503); exit; }
	$lockBackend = new DAV\Locks\Backend\File($cdavDirectory.'/.locks');
	require_once __DIR__.'/class/CDavDirectory.php';
	if ($user->hasRight('ecm', 'read') && is_dir($cdavDirectory.'/public')) {
		$nodes[] = new CDavDirectory($cdavDirectory.'/public', $user, '', '', (int) $conf->entity);
	}
}

// Keep the legacy administrative documents collection, but mount only configured
// native modules. In entity 1 this never exposes the other entities' directories.
if ($user->admin) {
	require_once __DIR__.'/class/CDavDirectory.php';
	require_once __DIR__.'/lib/cdav_documents.lib.php';
	$documentNodes = array();
	foreach (array(
		'societe' => array('societe', 'societe', 'lire', 'creer', 'supprimer'),
		'facture' => array('facture', 'facture', 'lire', 'creer', 'supprimer'),
		'commande' => array('commande', 'commande', 'lire', 'creer', 'supprimer'),
		'propal' => array('propal', 'propal', 'lire', 'creer', 'supprimer'),
		'projet' => array('project', 'projet', 'lire', 'creer', 'supprimer'),
		'ficheinter' => array('ficheinter', 'ficheinter', 'lire', 'creer', 'supprimer'),
		'ecm' => array('ecm', 'ecm', 'read', 'upload', 'setup'),
	) as $modulepart => $definition) {
		list($configModule, $rightModule, $read, $write, $delete) = $definition;
		if (!isModEnabled($configModule) || !$user->hasRight($rightModule, $read)) continue;
		$owner = new stdClass(); $owner->entity = (int) $conf->entity;
		try { $directory = cdavDocumentRoot($owner, $configModule); }
		catch (RuntimeException $e) { continue; }
		if (is_dir($directory)) $documentNodes[] = new CDavDirectory($directory, $user, $directory, $modulepart, (int) $conf->entity,
			array($rightModule, $read), array($rightModule, $write), array($rightModule, $delete));
	}
	$documentsName = (int) $conf->entity > 1 ? (string) $conf->entity : basename(DOL_DATA_ROOT);
	$nodes[] = new DAV\SimpleCollection($documentsName, $documentNodes);
}

// The server object is responsible for making sense out of the WebDAV protocol
$server = new DAV\Server($nodes);

// If your server is not on your webroot, make sure the following line has the
// correct information
$server->setBaseUri(dol_buildpath('cdav/server.php', 1).$cdavRoute['segment'].'/');


$server->addPlugin(new \Sabre\DAV\Auth\Plugin($authBackend));
if ($lockBackend !== null) $server->addPlugin(new \Sabre\DAV\Locks\Plugin($lockBackend));
$server->addPlugin(new \Sabre\DAV\Browser\Plugin());
$server->addPlugin(new \Sabre\CardDAV\Plugin());
$server->addPlugin(new \Sabre\CalDAV\Plugin());
$DAVACL_plugin = new \Sabre\DAVACL\Plugin();
$DAVACL_plugin->allowUnauthenticatedAccess = false;
$server->addPlugin($DAVACL_plugin);

debug_log("Ready : ".$user->login);

// All we need to do now, is to fire up the server
$server->exec();

if (is_object($db)) $db->close();
