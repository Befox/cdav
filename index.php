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

$user = new User($db);
$user->fetch('','jpmorfin');

$socid=0;
if ($user->societe_id > 0) $socid = $user->societe_id;
print_r($user);

if (is_object($db)) $db->close();


/*
 * Action
 */

// None


/*
 * View
 */


exit;
