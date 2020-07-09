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

function base64url_encode($data) {
  return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

// Load traductions files requiredby by page
$langs->load("cdav");

// Get parameters
$id			= GETPOST('id','int');
$action		= GETPOST('action','alpha');
$backtopage = GETPOST('backtopage');
$type		= GETPOST('type','CalDAV');

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
	echo '<PRE>';
	echo dol_buildpath('cdav', 2)."\n";
	echo dol_buildpath('cdav/server.php', 2)."\n";
	echo dol_buildpath('cdav', 2)."/server.php/principals/".$user->login."/";
	echo '</PRE>';
	
	echo '<h3>'.$langs->trans('URLforCardDAV', 2).'</h3>';
	echo '<PRE>'.dol_buildpath('cdav/server.php', 2).'/addressbooks/'.$user->login.'/default/</PRE>';
}
elseif($type=='CalDAV')
{
	echo '<h3>'.$langs->trans('URLGeneric').'</h3>';
	echo '<PRE>';
	echo dol_buildpath('cdav', 2)."\n";
	echo dol_buildpath('cdav/server.php', 2)."\n";
	echo dol_buildpath('cdav', 2)."/server.php/principals/".$user->login."/";
	echo '</PRE>';
	
	echo '<h3>'.$langs->trans('URLforCalDAV').'</h3>';
	
	if(isset($user->rights->agenda->allactions->read) && $user->rights->agenda->allactions->read)
	{
		if (versioncompare(versiondolibarrarray(), array(3,7,9))>0)
			$fk_soc_fieldname = 'fk_soc';
		else
			$fk_soc_fieldname = 'fk_societe';

		$sql = 'SELECT u.rowid, u.login, u.firstname, u.lastname
			FROM '.MAIN_DB_PREFIX.'user u WHERE '.$fk_soc_fieldname.' IS NULL
			ORDER BY login';
		$result = $db->query($sql);
		while($row = $db->fetch_array($result))
		{
			if($row['rowid'] == $user->id)
				echo '<strong>';
			echo $row['firstname'].' '.$row['lastname'].' :';
			echo '<PRE>'.dol_buildpath('cdav/server.php', 2).'/calendars/'.$user->login.'/'.$row['rowid'].'-cal-'.$row['login'].'</PRE><br/>';
			if($row['rowid'] == $user->id)
				echo '</strong>';
		}
	}
	else
	{
		echo '<PRE>'.dol_buildpath('cdav/server.php', 2).'/calendars/'.$user->login.'/'.$user->id.'-cal-'.$user->login.'</PRE>';
	}

}
elseif($type=='ICS')
{

	echo '<h3>'.$langs->trans('URLforICS').'</h3>';
	
	if(isset($user->rights->agenda->allactions->read) && $user->rights->agenda->allactions->read)
	{
		if (versioncompare(versiondolibarrarray(), array(3,7,9))>0)
			$fk_soc_fieldname = 'fk_soc';
		else
			$fk_soc_fieldname = 'fk_societe';

		$sql = 'SELECT u.rowid, u.login, u.firstname, u.lastname
			FROM '.MAIN_DB_PREFIX.'user u WHERE '.$fk_soc_fieldname.' IS NULL
			ORDER BY login';
		$result = $db->query($sql);
		while($row = $db->fetch_array($result))
		{
			echo '<h4>'.$row['firstname'].' '.$row['lastname'].' :</h4>';
			
			echo "<PRE>".$langs->trans('Full')." :\n".dol_buildpath('cdav/ics.php', 2).'?token='.base64url_encode(mcrypt_encrypt (MCRYPT_BLOWFISH, CDAV_URI_KEY, $row['rowid'].'+ø+full', ecb))."\n\n";
			echo $langs->trans('NoLabel')." :\n".dol_buildpath('cdav/ics.php', 2).'?token='.base64url_encode(mcrypt_encrypt (MCRYPT_BLOWFISH, CDAV_URI_KEY, $row['rowid'].'+ø+nolabel', ecb)).'</PRE><br/>';
			
		}
	}
	else
	{
		echo "<PRE>".$langs->trans('Full')." :\n".dol_buildpath('cdav/ics.php', 2).'?token='.base64url_encode(mcrypt_encrypt (MCRYPT_BLOWFISH, CDAV_URI_KEY, $user->id.'+ø+full', ecb))."\n\n";
		echo $langs->trans('NoLabel')." :\n".dol_buildpath('cdav/ics.php', 2).'?token='.base64url_encode(mcrypt_encrypt (MCRYPT_BLOWFISH, CDAV_URI_KEY, $user->id.'+ø+nolabel', ecb)).'</PRE><br/>';
	}

}
else
{
	echo '<h3>'.$langs->trans('URLGeneric').'</h3>';
	echo '<PRE>'.dol_buildpath('cdav', 2).'</PRE>';
}

// End of page
llxFooter();
$db->close();
