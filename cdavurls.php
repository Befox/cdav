<?php
/* Copyright (C) 2007-2010 Laurent Destailleur  <eldy@users.sourceforge.net>
 * Copyright (C) ---Put here your own copyright and developer email---
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

/**
 *   	\file       cdav/cdavurls.php
 *		\ingroup    cdav
 *		\brief      This page displays urls for carddav and caldav sync
 *	
 */

//if (! defined('NOREQUIREUSER'))  define('NOREQUIREUSER','1');
//if (! defined('NOREQUIREDB'))    define('NOREQUIREDB','1');
//if (! defined('NOREQUIRESOC'))   define('NOREQUIRESOC','1');
//if (! defined('NOREQUIRETRAN'))  define('NOREQUIRETRAN','1');
//if (! defined('NOCSRFCHECK'))    define('NOCSRFCHECK','1');			// Do not check anti CSRF attack test
//if (! defined('NOSTYLECHECK'))   define('NOSTYLECHECK','1');			// Do not check style html tag into posted data
//if (! defined('NOTOKENRENEWAL')) define('NOTOKENRENEWAL','1');		// Do not check anti POST attack test
//if (! defined('NOREQUIREMENU'))  define('NOREQUIREMENU','1');			// If there is no need to load and show top and left menu
//if (! defined('NOREQUIREHTML'))  define('NOREQUIREHTML','1');			// If we don't need to load the html.form.class.php
//if (! defined('NOREQUIREAJAX'))  define('NOREQUIREAJAX','1');
//if (! defined("NOLOGIN"))        define("NOLOGIN",'1');				// If this page is public (can be called outside logged session)

// Change this following line to use the correct relative path (../, ../../, etc)
$res=0;
if (! $res && file_exists("../main.inc.php")) $res=@include '../main.inc.php';					// to work if your module directory is into dolibarr root htdocs directory
if (! $res) die("Include of main fails");

// Load traductions files requiredby by page
$langs->load("cdav");

// Get parameters
$id			= GETPOST('id','int');
$action		= GETPOST('action','alpha');
$backtopage = GETPOST('backtopage');
$type	= GETPOST('type','CalDAV');

// Protection if external user
if ($user->societe_id > 0)
{
	accessforbidden();
}

/***************************************************
* VIEW
*
* Put here all code to build page
****************************************************/

llxHeader('',$langs->trans($type.'url'),'');

echo '<H2>'.$langs->trans($type.'url').'</H2>';

if($type=='CardDAV')
{
	echo '<h3>'.$langs->trans('URLGeneric').'</h3>';
	echo '<PRE>'.DOL_MAIN_URL_ROOT.'/cdav</PRE>';
	
	echo '<h3>'.$langs->trans('URLforCardDAV').'</h3>';
	echo '<PRE>'.DOL_MAIN_URL_ROOT.'/cdav/server.php/addressbooks/'.$user->login.'/default/</PRE>';
}
elseif($type=='CalDAV')
{
	echo '<h3>'.$langs->trans('URLGeneric').'</h3>';
	echo '<PRE>'.DOL_MAIN_URL_ROOT.'/cdav</PRE>';
	
	echo '<h3>'.$langs->trans('URLforCalDAV').'</h3>';
	
	if(isset($user->rights->agenda->allactions->read) && $user->rights->agenda->allactions->read)
	{
		if (versioncompare(versiondolibarrarray(), array(3,8,0))<0)
			$fk_soc_fieldname = 'fk_societe';
		else
			$fk_soc_fieldname = 'fk_soc';

		$sql = 'SELECT u.rowid, u.login, u.firstname, u.lastname
			FROM '.MAIN_DB_PREFIX.'user u WHERE '.$fk_soc_fieldname.' IS NULL
			ORDER BY login';
		echo $sql;
		$result = $db->query($sql);
		while($row = $db->fetch_array($result))
		{
			if($row['rowid'] == $user->id)
				echo '<strong>';
			echo $row['firstname'].' '.$row['lastname'].' :';
			echo '<PRE>'.DOL_MAIN_URL_ROOT.'/cdav/server.php/calendars/'.$user->login.'/'.$row['rowid'].'-cal-'.$row['login'].'</PRE><br/>';
			if($row['rowid'] == $user->id)
				echo '</strong>';
		}
	}
	else
	{
		echo '<PRE>'.DOL_MAIN_URL_ROOT.'/cdav/server.php/calendars/'.$user->login.'/'.$user->id.'-cal-'.$user->login.'</PRE>';
	}

}
else
{
	echo '<h3>'.$langs->trans('URLGeneric').'</h3>';
	echo '<PRE>'.DOL_MAIN_URL_ROOT.'/cdav</PRE>';
}

// End of page
llxFooter();
$db->close();
