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


define('NOTOKENRENEWAL',1); // Disables token renewal
// Pour autre que bittorrent, on charge environnement + info issus de logon (comme le user)
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
//require_once DOL_DOCUMENT_ROOT.'/cdav/core/modules/modCDav.class.php';

//$module_cdav = new modCDav($db);

if(!$conf->cdav->enabled)
	die('module CDav not enabled !'); 


use Sabre\DAV;

// The autoloader
require 'SabreDAV/vendor/autoload.php';

// Now we're creating a whole bunch of objects
$rootDirectory = new DAV\FS\Directory('SabreDAV/public');

// The server object is responsible for making sense out of the WebDAV protocol
$server = new DAV\Server($rootDirectory);

// If your server is not on your webroot, make sure the following line has the
// correct information
$server->setBaseUri('/dolibarr/htdocs/cdav/index.php');



// Authentication
use Sabre\DAV\Auth;
$authBackend = new Auth\Backend\BasicCallBack(function ($username, $password)
{
	global $db;
	global $user;
	$user = new User($db);
	$user->fetch('',$username);
	print_r($user);
	exit;
	return ($user->login==$username && $user->pass==$password);
});

// Creating the plugin. We're assuming that the realm
// name is called 'Dolibarr'.
$authPlugin = new Auth\Plugin($authBackend,'Dolibarr');

// Adding the plugin to the server
$server->addPlugin($authPlugin);



// The lock manager is reponsible for making sure users don't overwrite
// each others changes.
$lockBackend = new DAV\Locks\Backend\File('SabreDAV/data/locks');
$lockPlugin = new DAV\Locks\Plugin($lockBackend);
$server->addPlugin($lockPlugin);

// This ensures that we get a pretty index in the browser, but it is
// optional. 
$server->addPlugin(new DAV\Browser\Plugin());

// All we need to do now, is to fire up the server
$server->exec();






if (is_object($db)) $db->close();


/*
 * Action
 */

// None


/*
 * View
 */


exit;
