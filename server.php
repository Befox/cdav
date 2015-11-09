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
ini_set("display_errors", 1);
ini_set("log_errors", 1);

function exception_error_handler($errno, $errstr, $errfile, $errline) {
	if(function_exists("debug_log"))
		debug_log("Error $errno : $errstr - $errfile @ $errline");
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
}

// debug
//$debug_file = fopen('/tmp/cdav_'.date('Ymd_'),'a');
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

// HTTP auth workaround for php in fastcgi mode HTTP_AUTHORIZATION set by rewrite engine in .htaccess
if( isset($_SERVER['HTTP_AUTHORIZATION']) && 
	(!isset($_SERVER['PHP_AUTH_USER']) || !isset($_SERVER['PHP_AUTH_PW'])) )
{
    $rAuth = explode(':', base64_decode(substr($_SERVER['HTTP_AUTHORIZATION'], 6)));

    if(count($rAuth)>1)
    {
		$_SERVER['PHP_AUTH_USER'] = $rAuth[0];
		$_SERVER['PHP_AUTH_PW'] = $rAuth[1];
	}
}

define('NOTOKENRENEWAL',1); 								// Disables token renewal
if (! defined('NOLOGIN')) define('NOLOGIN','1');
if (! defined('NOCSRFCHECK')) define('NOCSRFCHECK','1');	// We accept to go on this page from external web site.
if (! defined('NOREQUIREMENU')) define('NOREQUIREMENU','1');
if (! defined('NOREQUIREHTML')) define('NOREQUIREHTML','1');
if (! defined('NOREQUIREAJAX')) define('NOREQUIREAJAX','1');
function llxHeader() { }
function llxFooter() { }


if(is_file('../main.inc.php'))
	$dir = '../';
elseif(is_file('../../../main.inc.php'))
	$dir = '../../../';
else 
	$dir = '../../';

require $dir.'main.inc.php';	// Load $user and permissions

if(!$conf->cdav->enabled)
	die('module CDav not enabled !'); 

set_error_handler("exception_error_handler");

require_once './lib/cdav.lib.php';

// Sabre/dav configuration

use Sabre\DAV;
use Sabre\DAVACL;

// The autoloader
require './lib/SabreDAV/vendor/autoload.php';
require './class/PrincipalsDolibarr.php';
require './class/CardDAVDolibarr.php';
require './class/CalDAVDolibarr.php';

$user = new User($db);

if(isset($_SERVER['PHP_AUTH_USER']) && $_SERVER['PHP_AUTH_USER']!='')
{
	$user->fetch('',$_SERVER['PHP_AUTH_USER']);
	$user->getrights();
}

$cdavLib = new CdavLib($user, $db, $langs);

// Authentication
$authBackend = new DAV\Auth\Backend\BasicCallBack(function ($username, $password)
{
	global $user;
	if ( ! isset($user->login) || $user->login=='')
		return false;
	if ($user->societe_id!=0)
		return false;
	if ($user->login!=$username)
		return false;
	if ($user->pass_indatabase_crypted == '' || dol_hash($password) != $user->pass_indatabase_crypted)
		return false;

	return true;
});

$authBackend->setRealm('Dolibarr');


// The lock manager is reponsible for making sure users don't overwrite
// each others changes.
$lockBackend = new DAV\Locks\Backend\File($dolibarr_main_data_root.'/cdav/.locks');

// Principals Backend
$principalBackend = new DAVACL\PrincipalBackend\Dolibarr($user,$db);

// CardDav & CalDav Backend
$carddavBackend   = new Sabre\CardDAV\Backend\Dolibarr($user,$db,$langs);
$caldavBackend    = new Sabre\CalDAV\Backend\Dolibarr($user,$db,$langs, $cdavLib);

// Setting up the directory tree //
$nodes = array(
    // /principals
	new DAVACL\PrincipalCollection($principalBackend),
    // /addressbook
    new \Sabre\CardDAV\AddressBookRoot($principalBackend, $carddavBackend),
    // /calendars
    new \Sabre\CalDAV\CalendarRoot($principalBackend, $caldavBackend),
    // / Public docs
	new DAV\FS\Directory($dolibarr_main_data_root. '/cdav/public')
);
// admin can access all dolibarr documents
if($user->admin)
	$nodes[] = new DAV\FS\Directory($dolibarr_main_data_root);


// The server object is responsible for making sense out of the WebDAV protocol
$server = new DAV\Server($nodes);

// If your server is not on your webroot, make sure the following line has the
// correct information
$server->setBaseUri(dol_buildpath('cdav/server.php', 1).'/');


$server->addPlugin(new \Sabre\DAV\Auth\Plugin($authBackend));
$server->addPlugin(new \Sabre\DAV\Locks\Plugin($lockBackend));
$server->addPlugin(new \Sabre\DAV\Browser\Plugin());
$server->addPlugin(new \Sabre\CardDAV\Plugin());
$server->addPlugin(new \Sabre\CalDAV\Plugin());
$server->addPlugin(new \Sabre\DAVACL\Plugin());
// $server->addPlugin(new \Sabre\DAV\Sync\Plugin());

// All we need to do now, is to fire up the server
$server->exec();

if (is_object($db)) $db->close();
