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


require DOL_DOCUMENT_ROOT.'main.inc.php';	// Load $user and permissions
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

$encoding = '';
/*
$action=GETPOST('action','alpha');
$original_file=GETPOST('file','alpha');	// Do not use urldecode here ($_GET are already decoded by PHP).
$modulepart=GETPOST('modulepart','alpha');
$urlsource=GETPOST('urlsource','alpha');
$entity=GETPOST('entity')?GETPOST('entity','int'):$conf->entity;
*/
// Security check
//if (empty($modulepart)) accessforbidden('Bad value for parameter modulepart');

$socid=0;
if ($user->societe_id > 0) $socid = $user->societe_id;


/*
 * Action
 */

// None


/*
 * View
 */

print_r($user);

exit;

// Define mime type
$type = 'application/octet-stream';
if (GETPOST('type','alpha')) $type=GETPOST('type','alpha');
else $type=dol_mimetype($original_file);

// Define attachment (attachment=true to force choice popup 'open'/'save as')
$attachment = true;
if (preg_match('/\.(html|htm)$/i',$original_file)) $attachment = false;
if (isset($_GET["attachment"])) $attachment = GETPOST("attachment")?true:false;
if (! empty($conf->global->MAIN_DISABLE_FORCE_SAVEAS)) $attachment=false;

// Suppression de la chaine de caractere ../ dans $original_file
$original_file = str_replace("../","/", $original_file);

// Find the subdirectory name as the reference
$refname=basename(dirname($original_file)."/");

// Security check
if (empty($modulepart)) accessforbidden('Bad value for parameter modulepart');
$check_access = dol_check_secure_access_document($modulepart,$original_file,$entity,$refname);
$accessallowed              = $check_access['accessallowed'];
$sqlprotectagainstexternals = $check_access['sqlprotectagainstexternals'];
$original_file              = $check_access['original_file'];

// Basic protection (against external users only)
if ($user->societe_id > 0)
{
	if ($sqlprotectagainstexternals)
	{
		$resql = $db->query($sqlprotectagainstexternals);
		if ($resql)
		{
			$num=$db->num_rows($resql);
			$i=0;
			while ($i < $num)
			{
				$obj = $db->fetch_object($resql);
				if ($user->societe_id != $obj->fk_soc)
				{
					$accessallowed=0;
					break;
				}
				$i++;
			}
		}
	}
}

// Security:
// Limite acces si droits non corrects
if (! $accessallowed)
{
	accessforbidden();
}

// Security:
// On interdit les remontees de repertoire ainsi que les pipe dans
// les noms de fichiers.
if (preg_match('/\.\./',$original_file) || preg_match('/[<>|]/',$original_file))
{
	dol_syslog("Refused to deliver file ".$original_file);
	$file=basename($original_file);		// Do no show plain path of original_file in shown error message
	dol_print_error(0,$langs->trans("ErrorFileNameInvalid",$file));
	exit;
}


clearstatcache();

$filename = basename($original_file);

// Output file on browser
dol_syslog("document.php download $original_file $filename content-type=$type");
$original_file_osencoded=dol_osencode($original_file);	// New file name encoded in OS encoding charset

// This test if file exists should be useless. We keep it to find bug more easily
if (! file_exists($original_file_osencoded))
{
	dol_print_error(0,$langs->trans("ErrorFileDoesNotExists",$original_file));
	exit;
}

// Permissions are ok and file found, so we return it

header('Content-Description: File Transfer');
if ($encoding)   header('Content-Encoding: '.$encoding);
if ($type)       header('Content-Type: '.$type.(preg_match('/text/',$type)?'; charset="'.$conf->file->character_set_client:''));
// Add MIME Content-Disposition from RFC 2183 (inline=automatically displayed, atachment=need user action to open)
if ($attachment) header('Content-Disposition: attachment; filename="'.$filename.'"');
else header('Content-Disposition: inline; filename="'.$filename.'"');
header('Content-Length: ' . dol_filesize($original_file));
// Ajout directives pour resoudre bug IE
header('Cache-Control: Public, must-revalidate');
header('Pragma: public');

//ob_clean();
//flush();

readfile($original_file_osencoded);

if (is_object($db)) $db->close();
