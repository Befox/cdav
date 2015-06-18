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

// HTTP auth workaround for php in fastcgi mode HTTP_AUTHORIZATION set by rewrite engine in .htaccess
if( isset($_SERVER['HTTP_AUTHORIZATION']) && 
	(!isset($_SERVER['PHP_AUTH_USER']) || !isset($_SERVER['PHP_AUTH_PW'])) )
{
    list($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']) = 
		explode(':', base64_decode(substr($_SERVER['HTTP_AUTHORIZATION'], 6)));
}


define('NOTOKENRENEWAL',1); 								// Disables token renewal
if (! defined('NOLOGIN')) define('NOLOGIN','1');
if (! defined('NOCSRFCHECK')) define('NOCSRFCHECK','1');	// We accept to go on this page from external web site.
if (! defined('NOREQUIREMENU')) define('NOREQUIREMENU','1');
if (! defined('NOREQUIREHTML')) define('NOREQUIREHTML','1');
if (! defined('NOREQUIREAJAX')) define('NOREQUIREAJAX','1');

/**
 * Header empty
 *
 * @return	void
 */
function llxHeader() { }
/**
 * Footer empty
 *
 * @return	void
 */
function llxFooter() { }

require '../main.inc.php';	// Load $user and permissions

if(!$conf->cdav->enabled)
	die('module CDav not enabled !'); 

function exception_error_handler($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
}
set_error_handler("exception_error_handler");

use Sabre\DAV;

// The autoloader
require 'SabreDAV/vendor/autoload.php';

// Now we're creating a whole bunch of objects
$rootDirectory = new DAV\FS\Directory('SabreDAV/public');

// The server object is responsible for making sense out of the WebDAV protocol
$server = new DAV\Server($rootDirectory);

// If your server is not on your webroot, make sure the following line has the
// correct information
$server->setBaseUri('/dolibarr/htdocs/cdav/server.php');

$user=false;


// Authentication
$authBackend = new DAV\Auth\Backend\BasicCallBack(function ($username, $password)
{
	/*
	global $db;
	global $user;
	$user = new User($db);
	$user->fetch('',$username);
	return ($user->login==$username && $user->pass==$password);*/
	return true;
});
$authBackend->setRealm('Dolibarr');


// The lock manager is reponsible for making sure users don't overwrite
// each others changes.
$lockBackend = new DAV\Locks\Backend\File('SabreDAV/data/locks');


$server->addPlugin(new DAV\Auth\Plugin($authBackend));
$server->addPlugin(new DAV\Locks\Plugin($lockBackend));
$server->addPlugin(new DAV\Browser\Plugin());

// All we need to do now, is to fire up the server
$server->exec();






if (is_object($db)) $db->close();

