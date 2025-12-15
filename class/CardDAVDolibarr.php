<?php

namespace Sabre\CardDAV\Backend;

use Sabre\VObject;
use Sabre\CardDAV;
use Sabre\DAV;
use Sabre\DAV\Exception\Forbidden;

/**
 * Dolibarr CardDAV backend
 *
 * This CardDAV backend uses Dolibarr to store addressbooks
 *
 * @copyright Copyright (C) 2007-2015 fruux GmbH (https://fruux.com/).
 * @author Evert Pot (http://evertpot.com/)
 * @license http://sabre.io/license/ Modified BSD License
 */
class Dolibarr extends AbstractBackend implements SyncSupport {

	/**
	 * Dolibarr user object
	 *
	 * @var string
	 */
	public $user;

	/**
	 * DB connection
	 *
	 * @var db
	 */
	protected $db;

	/**
	 * Lang translation
	 *
	 * @var langs
	 */
	protected $langs;

	/**
	 * Sets up the object
	 *
	 * @param user
	 * @param db
	 * @param langs
	 */
	function __construct($user, $db, $langs) {

		$this->user = $user;
		$this->db = $db;
		$this->langs = $langs;
		$this->langs->load("companies");
		$this->langs->load("suppliers");
	}

	/**
	 * Returns the list of addressbooks for a specific user.
	 *
	 * @param string $principalUri
	 * @return array
	 */
	function getAddressBooksForUser($principalUri) {
		global $conf;

		debug_log("getAddressBooksForUser( $principalUri )");

		$sql = 'SELECT MAX(GREATEST(COALESCE(s.tms, p.tms), p.tms)) lastupd FROM '.MAIN_DB_PREFIX.'socpeople as p
				LEFT JOIN '.MAIN_DB_PREFIX.'societe as s ON s.rowid = p.fk_soc
				WHERE p.entity IN ('.getEntity('societe', 1).')
				AND (p.priv=0 OR (p.priv=1 AND p.fk_user_creat='.$this->user->id.'))';
		$result = $this->db->query($sql);
		$row = $this->db->fetch_array($result);
		$lastupd = strtotime($row['lastupd']);

		$addressBooks = [];

		$addressBooks[] = [
			'id'														  => $this->user->id,
			'uri'														  => 'default',
			'principaluri'												  => $principalUri,
			'{DAV:}displayname'											  => $conf->global->MAIN_INFO_SOCIETE_NOM.' - contacts',
			'{' . CardDAV\Plugin::NS_CARDDAV . '}addressbook-description' => 'Contacts '.$conf->global->MAIN_INFO_SOCIETE_NOM.' '.$this->user->login,
			'{http://calendarserver.org/ns/}getctag'					  => $lastupd,
			'{http://sabredav.org/ns}sync-token'						  => $lastupd,
		];

		if(CDAV_THIRD_SYNC>0)
		{
			$sql = 'SELECT MAX(s.tms) lastupd FROM '.MAIN_DB_PREFIX.'societe as s
					LEFT JOIN '.MAIN_DB_PREFIX.'societe_commerciaux as sc ON sc.fk_soc = s.rowid
					WHERE s.entity IN ('.getEntity('societe', 1).')
					AND s.status=1';
			if(empty($this->user->rights->societe->client->voir))
				$sql.= ' AND s.rowid = sc.fk_soc AND sc.fk_user = '.((int) $this->user->id);
			if (!isset($this->user->rights->fournisseur->lire))
				$sql .= ' AND (s.fournisseur <> 1 OR s.client <> 0)'; // client=0, fournisseur=0 must be visible
			if (CDAV_THIRD_SYNC==1) // without contact
				$sql .= ' AND (SELECT count(sp.rowid) FROM llx_socpeople sp WHERE sp.fk_soc=s.rowid)=0';
			$result = $this->db->query($sql);
			$row = $this->db->fetch_array($result);
			$lastupd = strtotime($row['lastupd']);

			$addressBooks[] = [
				'id'														  => $this->user->id + CDAV_ADDRESSBOOK_ID_SHIFT,
				'uri'														  => 'thirdparties',
				'principaluri'												  => $principalUri,
				'{DAV:}displayname'											  => $conf->global->MAIN_INFO_SOCIETE_NOM.' - thirdparties',
				'{' . CardDAV\Plugin::NS_CARDDAV . '}addressbook-description' => 'Thirdparties '.$conf->global->MAIN_INFO_SOCIETE_NOM.' '.$this->user->login,
				'{http://calendarserver.org/ns/}getctag'					  => $lastupd,
				'{http://sabredav.org/ns}sync-token'						  => $lastupd,
			];
		}

		if(CDAV_MEMBER_SYNC>0 && $this->user->hasRight('adherent', 'lire'))
		{
			$sql = 'SELECT MAX(GREATEST(COALESCE(s.tms, p.tms), p.tms)) lastupd FROM '.MAIN_DB_PREFIX.'adherent as p
					LEFT JOIN '.MAIN_DB_PREFIX.'societe as s ON s.rowid = p.fk_soc
					WHERE p.entity IN ('.getEntity('societe', 1).')';
			$result = $this->db->query($sql);
			$row = $this->db->fetch_array($result);
			$lastupd = strtotime($row['lastupd']);

			$addressBooks[] = [
				'id'														  => $this->user->id + 2*CDAV_ADDRESSBOOK_ID_SHIFT,
				'uri'														  => 'members',
				'principaluri'												  => $principalUri,
				'{DAV:}displayname'											  => $conf->global->MAIN_INFO_SOCIETE_NOM.' - members',
				'{' . CardDAV\Plugin::NS_CARDDAV . '}addressbook-description' => 'Members '.$conf->global->MAIN_INFO_SOCIETE_NOM.' '.$this->user->login,
				'{http://calendarserver.org/ns/}getctag'					  => $lastupd,
				'{http://sabredav.org/ns}sync-token'						  => $lastupd,
			];
		}

		return $addressBooks;

	}


	/**
	 * Updates properties for an address book.
	 *
	 * The list of mutations is stored in a Sabre\DAV\PropPatch object.
	 * To do the actual updates, you must tell this object which properties
	 * you're going to process with the handle() method.
	 *
	 * Calling the handle method is like telling the PropPatch object "I
	 * promise I can handle updating this property".
	 *
	 * Read the PropPatch documenation for more info and examples.
	 *
	 * @param string $addressBookId
	 * @param \Sabre\DAV\PropPatch $propPatch
	 * @return void
	 */
	function updateAddressBook($addressbookId, \Sabre\DAV\PropPatch $propPatch) {

		// not supported
		return;

	}

	/**
	 * Creates a new address book
	 *
	 * @param string $principalUri
	 * @param string $url Just the 'basename' of the url.
	 * @param array $properties
	 * @return void
	 */
	function createAddressBook($principalUri, $url, array $properties) {

		// not supported
		return;

	}

	/**
	 * Deletes an entire addressbook and all its contents
	 *
	 * @param int $addressBookId
	 * @return void
	 */
	function deleteAddressBook($addressbookId) {

		// not supported
		return;

	}

	/**
	 * Base sql request for contacts
	 *
	 * @return string
	 */
	protected function _getSqlContacts($sqlWhere='')
	{
		$sql = 'SELECT p.*, co.label country_label, GREATEST(COALESCE(s.tms, p.tms), p.tms) lastupd, s.code_client soc_code_client, s.code_fournisseur soc_code_fournisseur,
					s.nom soc_nom, s.name_alias soc_name_alias, s.address soc_address, s.zip soc_zip, s.town soc_town, cos.label soc_country_label, s.phone soc_phone, s.fax soc_fax,
					s.email soc_email, s.url soc_url, s.client soc_client, s.fournisseur soc_fournisseur, s.note_private soc_note_private, s.note_public soc_note_public,
					GROUP_CONCAT(DISTINCT cat.label ORDER BY cat.label ASC SEPARATOR \',\') category_label,
					GROUP_CONCAT(DISTINCT cc.fk_categorie ORDER BY cc.fk_categorie ASC SEPARATOR \',\') category_ids,
					s.logo
				FROM '.MAIN_DB_PREFIX.'socpeople as p
				LEFT JOIN '.MAIN_DB_PREFIX.'c_country as co ON co.rowid = p.fk_pays
				LEFT JOIN '.MAIN_DB_PREFIX.'societe as s ON s.rowid = p.fk_soc
				LEFT JOIN '.MAIN_DB_PREFIX.'c_country as cos ON cos.rowid = s.fk_pays
				LEFT JOIN '.MAIN_DB_PREFIX.'categorie_contact as cc ON cc.fk_socpeople = p.rowid
				LEFT JOIN '.MAIN_DB_PREFIX.'categorie as cat ON cat.rowid = cc.fk_categorie
				WHERE p.entity IN ('.getEntity('societe', 1).')
				AND p.statut=1
				AND (p.priv=0 OR (p.priv=1 AND p.fk_user_creat='.$this->user->id.'))
				'.$sqlWhere.'
				GROUP BY p.rowid';

		if(intval(CDAV_CONTACT_TAG)>0)
			$sql.= " HAVING CONCAT(',',category_ids,',') LIKE '%,".$this->db->escape(CDAV_CONTACT_TAG).",%'";

		return $sql;
	}

	/**
	 * Base sql request for adherents
	 *
	 * @return string
	 */
	protected function _getSqlMembers($sqlWhere='')
	{
		$sql = 'SELECT p.*, co.label country_label, GREATEST(COALESCE(s.tms, p.tms), p.tms) lastupd, s.code_client soc_code_client, s.code_fournisseur soc_code_fournisseur,
					COALESCE(s.nom, p.societe) soc_nom, s.name_alias soc_name_alias, s.address soc_address, s.zip soc_zip, s.town soc_town, cos.label soc_country_label, s.phone soc_phone, s.fax soc_fax,
					s.email soc_email, s.url soc_url, s.client soc_client, s.fournisseur soc_fournisseur, s.note_private soc_note_private, s.note_public soc_note_public,
					GROUP_CONCAT(DISTINCT cat.label ORDER BY cat.label ASC SEPARATOR \',\') category_label,
					GROUP_CONCAT(DISTINCT cc.fk_categorie ORDER BY cc.fk_categorie ASC SEPARATOR \',\') category_ids
				FROM '.MAIN_DB_PREFIX.'adherent as p
				LEFT JOIN '.MAIN_DB_PREFIX.'c_country as co ON co.rowid = p.country
				LEFT JOIN '.MAIN_DB_PREFIX.'societe as s ON s.rowid = p.fk_soc
				LEFT JOIN '.MAIN_DB_PREFIX.'c_country as cos ON cos.rowid = s.fk_pays
				LEFT JOIN '.MAIN_DB_PREFIX.'categorie_contact as cc ON cc.fk_socpeople = p.rowid
				LEFT JOIN '.MAIN_DB_PREFIX.'categorie as cat ON cat.rowid = cc.fk_categorie
				WHERE p.entity IN ('.getEntity('societe', 1).')
				AND p.statut=1
				'.$sqlWhere.'
				GROUP BY p.rowid';
		return $sql;
	}

	/**
	 * Base sql request for thirdparties
	 *
	 * @return string
	 */
	protected function _getSqlThirdparties($sqlWhere='')
	{
		$sql = 'SELECT s.*, co.label country_label, s.tms lastupd, cfj.libelle as forme_juridique,
					GROUP_CONCAT(DISTINCT cat.label ORDER BY cat.label ASC SEPARATOR \',\') category_label,
					GROUP_CONCAT(DISTINCT cs.fk_categorie ORDER BY cs.fk_categorie ASC SEPARATOR \',\') category_ids
				FROM '.MAIN_DB_PREFIX.'societe as s
				LEFT JOIN '.MAIN_DB_PREFIX.'societe_commerciaux as sc ON sc.fk_soc = s.rowid
				LEFT JOIN '.MAIN_DB_PREFIX.'c_country as co ON co.rowid = s.fk_pays
				LEFT JOIN '.MAIN_DB_PREFIX.'c_country as cos ON cos.rowid = s.fk_pays
				LEFT JOIN '.MAIN_DB_PREFIX.'c_forme_juridique as cfj ON cfj.rowid = s.fk_forme_juridique
				LEFT JOIN '.MAIN_DB_PREFIX.'categorie_societe as cs ON cs.fk_soc = s.rowid
				LEFT JOIN '.MAIN_DB_PREFIX.'categorie as cat ON cat.rowid = cs.fk_categorie
				WHERE s.entity IN ('.getEntity('societe', 1).')
				AND s.status=1';
		if(empty($this->user->rights->societe->client->voir))
			$sql.= ' AND s.rowid = sc.fk_soc AND sc.fk_user = '.((int) $this->user->id);
		if (!isset($this->user->rights->fournisseur->lire))
			$sql .= ' AND (s.fournisseur <> 1 OR s.client <> 0)'; // client=0, fournisseur=0 must be visible
		if (CDAV_THIRD_SYNC==1) // without contact
			$sql .= ' AND (SELECT count(sp.rowid) FROM llx_socpeople sp WHERE sp.fk_soc=s.rowid)=0';
		$sql .= $sqlWhere.' GROUP BY s.rowid';

		return $sql;
	}

	/**
	 * Convert contact row to VCard string
	 *
	 * @param row object
	 * @return string
	 */
	protected function _contactToVCard($obj)
	{
		global $conf;
		$nick = [];
		$categ = [];
		if($obj->soc_client)
		{
			$nick[] = $obj->soc_code_client;
			$categ[] = $this->langs->transnoentitiesnoconv('Customer');
		}
		if($obj->soc_fournisseur)
		{
			$nick[] = $obj->soc_code_fournisseur;
			$categ[] = $this->langs->transnoentitiesnoconv('Supplier');
		}
		if($obj->priv)
			$categ[] = $this->langs->transnoentitiesnoconv('ContactPrivate');
		else
			$categ[] = $this->langs->transnoentitiesnoconv('ContactPublic');
		if (! empty($conf->categorie->enabled)  && ! empty($this->user->rights->categorie->lire))
			if(trim($obj->category_label)!='')
				$categ[] = trim($obj->category_label);

		$soc_address=explode("\n",$obj->soc_address,2);
		foreach($soc_address as $kAddr => $vAddr)
			$soc_address[$kAddr] = trim(str_replace(array("\r","\t"),' ', str_replace("\n",' | ', trim($vAddr))));
		$soc_address[]='';
		$soc_address[]='';

		$address=explode("\n",$obj->address,2);
		foreach($address as $kAddr => $vAddr)
		{
			$address[$kAddr] = trim(str_replace(array("\r","\t"),' ', str_replace("\n",' | ', trim($vAddr))));
		}
		$address[]='';
		$address[]='';

		// remove carriage return in data
		$objvars = get_object_vars($obj);
		foreach ($objvars as $key => $value)
		{
			if(is_string($value))
				$obj->$key = strtr(trim($value), array("\n"=>"\\n", "\r"=>""));
		}

		$carddata ="BEGIN:VCARD\n";
		$carddata.="VERSION:3.0\n";
		$carddata.="PRODID:-//Dolibarr CDav//FR\n";
		$carddata.="UID:".$obj->rowid.'-ct-'.CDAV_URI_KEY."\n";
		if(!empty($obj->soc_nom) && getDolGlobalInt('CDAV_CONCAT_SOCNAME_FOR_PHONE'))
		{
			$carddata.="N;CHARSET=UTF-8:".str_replace(';','\;',$obj->lastname).";".str_replace(';','\;',$obj->firstname).";;".str_replace(';','\;',"(".$obj->soc_nom.")")."\n";
			$carddata.="FN;CHARSET=UTF-8:".str_replace(';','\;',"(".$obj->soc_nom.") ".$obj->lastname." ".$obj->firstname)."\n";
		}
		else
		{
			$carddata.="N;CHARSET=UTF-8:".str_replace(';','\;',$obj->lastname).";".str_replace(';','\;',$obj->firstname).";;".str_replace(';','\;',$obj->civility)."\n";
			$carddata.="FN;CHARSET=UTF-8:".str_replace(';','\;',$obj->lastname." ".$obj->firstname)."\n";
		}

		if(!empty($obj->soc_nom) && !empty($obj->soc_name_alias))
			$carddata.="ORG;CHARSET=UTF-8:".str_replace(';','\;',$obj->soc_nom." (".$obj->soc_name_alias.")").";\n";
		elseif(!empty($obj->soc_nom))
			$carddata.="ORG;CHARSET=UTF-8:".str_replace(';','\;',$obj->soc_nom).";\n";
		if(!empty($obj->poste))
			$carddata.="TITLE;CHARSET=UTF-8:".str_replace(';','\;',$obj->poste)."\n";
		if(count($categ)>0)
			$carddata.="CATEGORIES;CHARSET=UTF-8:".str_replace(';','\;',implode(',',$categ))."\n";
		$carddata.="CLASS:".($obj->priv?'PRIVATE':'PUBLIC')."\n";
		$carddata.="ADR;TYPE=HOME;CHARSET=UTF-8:;".str_replace(';','\;',$address[1]).";".str_replace(';','\;',$address[0]).";";
		$carddata.=	 str_replace(';','\;',$obj->town).";;".str_replace(';','\;',$obj->zip).";".str_replace(';','\;',$obj->country_label)."\n";
		$carddata.="ADR;TYPE=WORK;CHARSET=UTF-8:;".str_replace(';','\;',$soc_address[1]).";".str_replace(';','\;',$soc_address[0]).";";
		$carddata.=	 str_replace(';','\;',$obj->soc_town).";;".str_replace(';','\;',$obj->soc_zip).";".str_replace(';','\;',$obj->soc_country_label)."\n";
		$carddata.="TEL;TYPE=WORK,VOICE:".str_replace(';','\;',(trim($obj->phone)==''?$obj->soc_phone:$obj->phone))."\n";
		if(!empty($obj->phone_perso))
			$carddata.="TEL;TYPE=HOME,VOICE:".str_replace(';','\;',$obj->phone_perso)."\n";
		if(!empty($obj->phone_mobile))
			$carddata.="TEL;TYPE=CELL,VOICE:".str_replace(';','\;',$obj->phone_mobile)."\n";
		if(!empty($obj->soc_fax))
			$carddata.="TEL;TYPE=WORK,FAX:".str_replace(';','\;',$obj->soc_fax)."\n";
		if(!empty($obj->fax))
			$carddata.="TEL;TYPE=HOME,FAX:".str_replace(';','\;',$obj->fax)."\n";
		if(!empty($obj->email))
			$carddata.="EMAIL;PREF=1:".str_replace(';','\;',$obj->email)."\n";
		if(!empty($obj->soc_email) && $obj->soc_email!=$obj->email)
			$carddata.="EMAIL:".str_replace(';','\;',$obj->soc_email)."\n";
		if(!empty($obj->soc_url))
		{
			if(strpos($obj->soc_url,'://')===false)
				$carddata.="URL:http://".trim($obj->soc_url)."\n";
			else
				$carddata.="URL:".trim($obj->soc_url)."\n";
		}
		if(!empty($obj->jabberid))
			$carddata.="X-JABBER:".str_replace(';','\;',$obj->jabberid)."\n";
		if(!empty($obj->skype))
			$carddata.="X-SKYPE:".str_replace(';','\;',$obj->skype)."\n";
		if(!empty($obj->birthday))
			$carddata.="BDAY:".str_replace(';','\;',$obj->birthday)."\n";
		if(!empty($obj->note_public))
			$carddata.="NOTE;CHARSET=UTF-8:".str_replace(';','\;',strtr(trim($obj->note_public),array("\n"=>"\\n", "\r"=>"")))."\n";
		if(!empty($obj->photo))
		{
			$photofile = $conf->societe->dir_output."/contact/".$obj->rowid."/photos/".$obj->photo;
			if(!file_exists($photofile) && !empty($object->logo))
			{
				// fallback image search thirdparty if possible
				$photofile = $conf->societe->dir_output . '/' . $obj->soc_id . '/logos/' . getImageFileNameForSize($obj->logo,''); //, '_mini' getImageFileNameForSize include the thumbs
			}

			if(file_exists($photofile))
			{
				if(function_exists('exif_imagetype'))
				{
					$image_type = image_type_to_mime_type(exif_imagetype($photofile));
					$image_type = strtoupper(substr($image_type, strpos($image_type, '/')+1));
				}
				else
				{
					$image_type='';
					switch(strtolower(substr($obj->photo,-4)))
					{
						case '.jpg':
						case 'jpeg':
							$image_type='JPEG';
							break;
						case '.gif':
							$image_type='GIF';
							break;
						case '.png':
							$image_type='PNG';
							break;
						case '.bmp':
							$image_type='BMP';
							break;
						case '.tif':
						case 'tiff':
							$image_type='TIFF';
							break;
					}
				}
				if(!empty($image_type))
				{
					$photodata = wordwrap("PHOTO;ENCODING=b;TYPE=JPEG:".base64_encode(file_get_contents($photofile)),72,"\n",true);
					$photodata = trim(str_replace("\n", "\n ", $photodata));
					$carddata .= $photodata."\n";
				}
			}
		}
   		$carddata.="REV;TZID=".date_default_timezone_get().":".strtr($obj->lastupd,array(" "=>"T", ":"=>"", "-"=>""))."\n";
		$carddata.="END:VCARD\n";
		return $carddata;
	}

	/**
	 * Convert member row to VCard string
	 *
	 * @param row object
	 * @return string
	 */
	protected function _memberToVCard($obj)
	{
		global $conf;
		$nick = [];
		$categ = [];
		if($obj->soc_client)
		{
			$nick[] = $obj->soc_code_client;
			$categ[] = $this->langs->transnoentitiesnoconv('Customer');
		}
		if($obj->soc_fournisseur)
		{
			$nick[] = $obj->soc_code_fournisseur;
			$categ[] = $this->langs->transnoentitiesnoconv('Supplier');
		}
		if (! empty($conf->categorie->enabled)  && ! empty($this->user->rights->categorie->lire))
			if(trim($obj->category_label)!='')
				$categ[] = trim($obj->category_label);

		$soc_address=explode("\n",$obj->soc_address,2);
		foreach($soc_address as $kAddr => $vAddr)
			$soc_address[$kAddr] = trim(str_replace(array("\r","\t"),' ', str_replace("\n",' | ', trim($vAddr))));
		$soc_address[]='';
		$soc_address[]='';

		$address=explode("\n",$obj->address,2);
		foreach($address as $kAddr => $vAddr)
		{
			$address[$kAddr] = trim(str_replace(array("\r","\t"),' ', str_replace("\n",' | ', trim($vAddr))));
		}
		$address[]='';
		$address[]='';

		// remove carriage return in data
		$objvars = get_object_vars($obj);
		foreach ($objvars as $key => $value)
		{
			if(is_string($value))
				$obj->$key = strtr(trim($value), array("\n"=>"\\n", "\r"=>""));
		}

		$carddata ="BEGIN:VCARD\n";
		$carddata.="VERSION:3.0\n";
		$carddata.="PRODID:-//Dolibarr CDav//FR\n";
		$carddata.="UID:".$obj->rowid.'-mb-'.CDAV_URI_KEY."\n";
		$carddata.="N;CHARSET=UTF-8:".str_replace(';','\;',$obj->lastname).";".str_replace(';','\;',$obj->firstname).";;".str_replace(';','\;',$obj->civility)."\n";
		$carddata.="FN;CHARSET=UTF-8:".str_replace(';','\;',$obj->lastname." ".$obj->firstname)."\n";
		if(!empty($obj->soc_nom) && !empty($obj->soc_name_alias))
			$carddata.="ORG;CHARSET=UTF-8:".str_replace(';','\;',$obj->soc_nom." (".$obj->soc_name_alias.")").";\n";
		elseif(!empty($obj->soc_nom))
			$carddata.="ORG;CHARSET=UTF-8:".str_replace(';','\;',$obj->soc_nom).";\n";
		/*if(!empty($obj->poste))
			$carddata.="TITLE;CHARSET=UTF-8:".str_replace(';','\;',$obj->poste)."\n";*/
		if(count($categ)>0)
			$carddata.="CATEGORIES;CHARSET=UTF-8:".str_replace(';','\;',implode(',',$categ))."\n";
		$carddata.="CLASS:PUBLIC\n";
		$carddata.="ADR;TYPE=HOME;CHARSET=UTF-8:;".str_replace(';','\;',$address[1]).";".str_replace(';','\;',$address[0]).";";
		$carddata.=	 str_replace(';','\;',$obj->town).";;".str_replace(';','\;',$obj->zip).";".str_replace(';','\;',$obj->country_label)."\n";
		$carddata.="ADR;TYPE=WORK;CHARSET=UTF-8:;".str_replace(';','\;',$soc_address[1]).";".str_replace(';','\;',$soc_address[0]).";";
		$carddata.=	 str_replace(';','\;',$obj->soc_town).";;".str_replace(';','\;',$obj->soc_zip).";".str_replace(';','\;',$obj->soc_country_label)."\n";
		$carddata.="TEL;TYPE=WORK,VOICE:".str_replace(';','\;',(trim($obj->phone)==''?$obj->soc_phone:$obj->phone))."\n";
		if(!empty($obj->phone_perso))
			$carddata.="TEL;TYPE=HOME,VOICE:".str_replace(';','\;',$obj->phone_perso)."\n";
		if(!empty($obj->phone_mobile))
			$carddata.="TEL;TYPE=CELL,VOICE:".str_replace(';','\;',$obj->phone_mobile)."\n";
		if(!empty($obj->soc_fax))
			$carddata.="TEL;TYPE=WORK,FAX:".str_replace(';','\;',$obj->soc_fax)."\n";
		if(!empty($obj->email))
			$carddata.="EMAIL;PREF=1:".str_replace(';','\;',$obj->email)."\n";
		if(!empty($obj->soc_email) && $obj->soc_email!=$obj->email)
			$carddata.="EMAIL:".str_replace(';','\;',$obj->soc_email)."\n";
		if(!empty($obj->soc_url))
		{
			if(strpos($obj->soc_url,'://')===false)
				$carddata.="URL:https://".trim($obj->soc_url)."\n";
			else
				$carddata.="URL:".trim($obj->soc_url)."\n";
		}
		if(!empty($obj->birth))
			$carddata.="BDAY;VALUE=DATE:".str_replace(';','\;',date('Ymd',strtotime($obj->birth)))."\n";
		if(!empty($obj->note_public))
			$carddata.="NOTE;CHARSET=UTF-8:".str_replace(';','\;',strtr(trim($obj->note_public),array("\n"=>"\\n", "\r"=>"")))."\n";
		if(!empty($obj->photo))
		{
			$photofile = $conf->adherent->dir_output."/member/".$obj->rowid."/photos/".$obj->photo;
			if(file_exists($photofile))
			{
				if(function_exists('exif_imagetype'))
				{
					$image_type = image_type_to_mime_type(exif_imagetype($photofile));
					$image_type = strtoupper(substr($image_type, strpos($image_type, '/')+1));
				}
				else
				{
					$image_type='';
					switch(strtolower(substr($obj->photo,-4)))
					{
						case '.jpg':
						case 'jpeg':
							$image_type='JPEG';
							break;
						case '.gif':
							$image_type='GIF';
							break;
						case '.png':
							$image_type='PNG';
							break;
						case '.bmp':
							$image_type='BMP';
							break;
						case '.tif':
						case 'tiff':
							$image_type='TIFF';
							break;
					}
				}
				if(!empty($image_type))
				{
					$photodata = wordwrap("PHOTO;ENCODING=b;TYPE=JPEG:".base64_encode(file_get_contents($photofile)),72,"\n",true);
					$photodata = trim(str_replace("\n", "\n ", $photodata));
					$carddata .= $photodata."\n";
				}
			}
		}
		$carddata.="REV;TZID=".date_default_timezone_get().":".strtr($obj->lastupd,array(" "=>"T", ":"=>"", "-"=>""))."\n";
		$carddata.="END:VCARD\n";
		return $carddata;
	}


	/**
	 * Convert thirdparty row to VCard string
	 *
	 * @param row object
	 * @return string
	 */
	protected function _thirdpartyToVCard($obj)
	{
		global $conf;
		$doliinfo = [];
		$categ = [];
		if($obj->client)
		{
			$doliinfo[] = "💼👑".$obj->code_client;
			$categ[] = $this->langs->transnoentitiesnoconv('Customer');
		}
		if($obj->fournisseur)
		{
			$doliinfo[] = "💼🏭".$obj->code_fournisseur;
			$categ[] = $this->langs->transnoentitiesnoconv('Supplier');
		}
		if (! empty($conf->categorie->enabled)  && ! empty($this->user->rights->categorie->lire))
			if(trim($obj->category_label)!='')
				$categ[] = trim($obj->category_label);

		$address=explode("\n",$obj->address,2);
		foreach($address as $kAddr => $vAddr)
		{
			$address[$kAddr] = trim(str_replace(array("\r","\t"),' ', str_replace("\n",' | ', trim($vAddr))));
		}
		$address[]='';
		$address[]='';

		// remove carriage return in data
		$objvars = get_object_vars($obj);
		foreach ($objvars as $key => $value)
		{
			if(is_string($value))
				$obj->$key = strtr(trim($value), array("\n"=>"\\n", "\r"=>""));
		}

		$carddata ="BEGIN:VCARD\n";
		$carddata.="VERSION:3.0\n";
		$carddata.="PRODID:-//Dolibarr CDav//FR\n";
		$carddata.="UID:".$obj->rowid.'-th-'.CDAV_URI_KEY."\n";
		$carddata.="N;CHARSET=UTF-8:".str_replace(';','\;',$obj->nom).";;;\n";
		$carddata.="FN;CHARSET=UTF-8:".str_replace(';','\;',$obj->nom)."\n";
		if(!empty($obj->nom))
			$carddata.="ORG;CHARSET=UTF-8:".str_replace(';','\;',$obj->nom).";\n";
		if(!empty($obj->name_alias))
			$carddata.="NICKNAME;CHARSET=UTF-8:".str_replace(';','\;',$obj->name_alias).";\n";
		if(!empty($obj->forme_juridique))
			$carddata.="TITLE;CHARSET=UTF-8:".str_replace(';','\;',$obj->forme_juridique)."\n";
		if(count($categ)>0)
			$carddata.="CATEGORIES;CHARSET=UTF-8:".str_replace(';','\;',implode(',',$categ))."\n";
		// $carddata.="CLASS:".($obj->priv?'PRIVATE':'PUBLIC')."\n";
		$carddata.="CLASS:PRIVATE\n";
		$carddata.="ADR;TYPE=WORK;CHARSET=UTF-8:;".str_replace(';','\;',$address[1]).";".str_replace(';','\;',$address[0]).";";
		$carddata.=	 str_replace(';','\;',$obj->town).";;".str_replace(';','\;',$obj->zip).";".str_replace(';','\;',$obj->country_label)."\n";
		$carddata.="TEL;TYPE=WORK,VOICE:".str_replace(';','\;',$obj->phone)."\n";
		if(!empty($obj->phone_mobile))
			$carddata.="TEL;TYPE=CELL,VOICE:".str_replace(';','\;',$obj->phone_mobile)."\n";
		if(!empty($obj->fax))
			$carddata.="TEL;TYPE=WORK,FAX:".str_replace(';','\;',$obj->fax)."\n";
		if(!empty($obj->email))
			$carddata.="EMAIL;PREF=1:".str_replace(';','\;',$obj->email)."\n";
		if(!empty($obj->url))
		{
			if(strpos($obj->url,'://')===false)
				$carddata.="URL:https://".trim($obj->url)."\n";
		}
		if(!empty($obj->whatsapp))
			$carddata.="X-WHATSAPP:".str_replace(';','\;',$obj->whatsapp)."\n";
		if(!empty($obj->snapchat))
			$carddata.="X-SNAPCHAT:".str_replace(';','\;',$obj->snapchat)."\n";
		if(!empty($obj->linkedin))
			$carddata.="X-LINKEDIN:".str_replace(';','\;',$obj->linkedin)."\n";
		if(!empty($obj->instagram))
			$carddata.="X-INSTAGRAM:".str_replace(';','\;',$obj->instagram)."\n";
		if(!empty($obj->skype))
			$carddata.="X-SKYPE:".str_replace(';','\;',$obj->skype)."\n";
		$carddata.="NOTE;CHARSET=UTF-8:";
		foreach($doliinfo as $info)
			$carddata.=strtr(trim($info),array("\n"=>"\\n", "\r"=>""))."\\n";
		if(!empty($obj->note_public))
			$carddata.=strtr(trim($obj->note_public),array("\n"=>"\\n", "\r"=>""))."\\n";
		if(!empty($obj->note_public))
			$carddata.=strtr(trim($obj->note_public),array("\n"=>"\\n", "\r"=>""))."\\n";
		$carddata.="\n";
		$carddata.="REV;TZID=".date_default_timezone_get().":".strtr($obj->lastupd,array(" "=>"T", ":"=>"", "-"=>""))."\n";
		$carddata.="END:VCARD\n";

		return $carddata;
	}

	/*
	 * parse vcard data to dolibarr table fields
	 * @param cardData : string vcard
	 * @param mode : C=create / U=update
	 */
	protected function _parseDataContact($cardData, $mode) {

		debug_log("_parseDataContact( $cardData )");

		$rdata = [] ;

		$vCard = VObject\Reader::read($cardData);
		$vCard->validate(VObject\Node::REPAIR | VObject\Node::PROFILE_CARDDAV);
		$vCard->convert(VObject\Document::VCARD30);

		// debug_log("_parseData__converted( ".$vCard->PHOTO." )");

		$rdata['_uid'] = (string)$vCard->UID;
		if(isset($vCard->PHOTO) && strpos(substr($vCard->PHOTO,0,10),'://')===false) // exist and not uri
		{
			$rdata['_photo_bin'] = (string)$vCard->PHOTO;
		}
		else
			$rdata['_photo_bin'] = false;

		$names = $vCard->N->getParts();
		if(isset($names[0]) && trim((string)$names[0])!='')
			$rdata['lastname'] = (string)$names[0];
		if($rdata['lastname']=='' && isset($vCard->FN) && trim((string)$vCard->FN)!='')
			$rdata['lastname'] = (string)$vCard->FN;
		if($rdata['lastname']=='' && isset($names[1]) && trim((string)$names[1])!='')
			$rdata['lastname'] = (string)$names[1];
		if($rdata['lastname']=='')
			$rdata['lastname'] = "Contact ".date('Y-m-d H:i:s');

		if(isset($names[1]))
			$rdata['firstname'] = (string)$names[1];

		if(isset($names[3]))
			$rdata['civility'] = (string)$names[3];

		if(isset($vCard->TITLE))
			$rdata['poste'] = (string)$vCard->TITLE;

		if(isset($vCard->CLASS) && ((string)strtoupper($vCard->CLASS))=='PRIVATE')
			$rdata['priv'] = 1;
		else
			$rdata['priv'] = 0;

		if(isset($vCard->TEL))
		{
			foreach($vCard->TEL as $tel)
			{
				$teltype = [];
				$types = $tel['TYPE'];
				foreach($types as $type)
				{
					$teltype[strtoupper($type)]=true;
				}

				if(isset($teltype['WORK']) && (isset($teltype['VOICE']) || count($teltype)==1))
					$rdata['phone'] = (string)$tel;

				if(isset($teltype['HOME']) && (isset($teltype['VOICE']) || count($teltype)==1))
					$rdata['phone_perso'] = (string)$tel;

				if(isset($teltype['CELL']))
					$rdata['phone_mobile'] = (string)$tel;

				if(isset($teltype['HOME']) && isset($teltype['FAX']))
					$rdata['fax'] = (string)$tel;
				elseif(isset($teltype['FAX']) && !isset($rdata['fax']))
					$rdata['fax'] = (string)$tel;
			}
		}

		if(isset($vCard->EMAIL))
		{
			foreach($vCard->EMAIL as $email)
			{
				if(!isset($rdata['email']))
					$rdata['email'] = (string)$email;
				if(isset($email->PREF))
					$rdata['email'] = (string)$email;
			}
		}

		if(isset($vCard->ADR))
		{
			foreach($vCard->ADR as $adr)
			{
				$types = $adr['TYPE'];
				$adrtype = [];
				foreach($types as $type)
				{
					$adrtype[strtoupper($type)]=true;
				}
				$adrparts = $adr->getParts();
				// debug_log("adrparts:\n".print_r($adrtype, true).print_r($adrparts, true));
				if(isset($adrtype['HOME']) || !isset($rdata['address']))
				{
					$rdata['address'] = '';
					$rdata['town'] = '';
					$rdata['zip'] = '';
					$rdata['_country_label'] = '';
					if(isset($adrparts[2]) && !empty($adrparts[2]))
						$rdata['address'].= str_replace(' | ',"\n",trim($adrparts[2]))."\n";
					if(isset($adrparts[0]) && !empty($adrparts[0]))
						$rdata['address'].= $adrparts[0]."\n";
					if(isset($adrparts[1]) && !empty($adrparts[1]))
						$rdata['address'].= str_replace(' | ',"\n",trim($adrparts[1]))."\n";
					$rdata['address'] = trim($rdata['address']);
					if(isset($adrparts[3]))
						$rdata['town'] = $adrparts[3];
					if(isset($adrparts[5]))
						$rdata['zip'] = $adrparts[5];
					if(isset($adrparts[6]))
						$rdata['_country_label'] = $adrparts[6];
					if($mode=='C' && isset($vCard->ORG))	// keep ORG info in address
						$rdata['address'] = trim((string)$vCard->ORG," ;\n\r\t") . "\n" . $rdata['address'];
				}
			}
		}

		if(isset($vCard->{'X-JABBER'}))
			$rdata['jabberid'] = (string)$vCard->{'X-JABBER'};

		if(isset($vCard->{'X-SKYPE'}))
			$rdata['skype'] = (string)$vCard->{'X-SKYPE'};
		elseif(isset($vCard->{'X-SKYPE-USERNAME'}))
			$rdata['skype'] = (string)$vCard->{'X-SKYPE-USERNAME'};

		$bday = '';
		if( isset($vCard->BDAY))
			$bday = trim((string)$vCard->BDAY);
		if( isset($vCard->BDAY) &&
			!empty($bday) &&
			date("Y-m-d", strtotime(trim($bday))) == trim($bday) )
			$rdata['birthday'] = trim($bday);

		if(isset($vCard->NOTE))
			$rdata['note_public'] = strtr(trim((string)$vCard->NOTE),"\\n", "\n");

		if(isset($rdata['_country_label']) && $rdata['_country_label']!='')
		{
			$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'c_country
					WHERE label LIKE "'.$this->db->escape($rdata['_country_label']).'"
					AND active = 1';
			$result = $this->db->query($sql);
			if($result!==false && ($row = $this->db->fetch_array($result))!==false)
				$rdata['fk_pays'] = $row['rowid'];
		}

		debug_log("parsed:\n".print_r($rdata, true));

		return $rdata;
	}

	/*
	 * parse vcard data to dolibarr table fields
	 * @param cardData : string vcard
	 * @param mode : C=create / U=update
	 */
	protected function _parseDataMember($cardData, $mode) {

		debug_log("_parseDataMember( $cardData )");

		$rdata = [] ;

		$vCard = VObject\Reader::read($cardData);
		$vCard->validate(VObject\Node::REPAIR | VObject\Node::PROFILE_CARDDAV);
		$vCard->convert(VObject\Document::VCARD30);

		// debug_log("_parseData__converted( ".$vCard->PHOTO." )");

		$rdata['_uid'] = (string)$vCard->UID;
		if(isset($vCard->PHOTO) && strpos(substr($vCard->PHOTO,0,10),'://')===false) // exist and not uri
		{
			$rdata['_photo_bin'] = (string)$vCard->PHOTO;
		}
		else
			$rdata['_photo_bin'] = false;

		$names = $vCard->N->getParts();
		if(isset($names[0]) && trim((string)$names[0])!='')
			$rdata['lastname'] = (string)$names[0];
		if($rdata['lastname']=='' && isset($vCard->FN) && trim((string)$vCard->FN)!='')
			$rdata['lastname'] = (string)$vCard->FN;
		if($rdata['lastname']=='' && isset($names[1]) && trim((string)$names[1])!='')
			$rdata['lastname'] = (string)$names[1];
		if($rdata['lastname']=='')
			$rdata['lastname'] = "Member ".date('Y-m-d H:i:s');

		if(isset($names[1]))
			$rdata['firstname'] = (string)$names[1];

		if(isset($names[3]))
			$rdata['civility'] = (string)$names[3];

		/*if(isset($vCard->TITLE))
			$rdata['poste'] = (string)$vCard->TITLE;*/

		if(isset($vCard->TEL))
		{
			foreach($vCard->TEL as $tel)
			{
				$teltype = [];
				$types = $tel['TYPE'];
				foreach($types as $type)
				{
					$teltype[strtoupper($type)]=true;
				}

				if(isset($teltype['WORK']) && (isset($teltype['VOICE']) || count($teltype)==1))
					$rdata['phone'] = (string)$tel;

				if(isset($teltype['HOME']) && (isset($teltype['VOICE']) || count($teltype)==1))
					$rdata['phone_perso'] = (string)$tel;

				if(isset($teltype['CELL']))
					$rdata['phone_mobile'] = (string)$tel;
			}
		}

		if(isset($vCard->EMAIL))
		{
			foreach($vCard->EMAIL as $email)
			{
				if(!isset($rdata['email']))
					$rdata['email'] = (string)$email;
				if(isset($email->PREF))
					$rdata['email'] = (string)$email;
			}
		}

		if(isset($vCard->ADR))
		{
			foreach($vCard->ADR as $adr)
			{
				$types = $adr['TYPE'];
				$adrtype = [];
				foreach($types as $type)
				{
					$adrtype[strtoupper($type)]=true;
				}
				$adrparts = $adr->getParts();
				// debug_log("adrparts:\n".print_r($adrtype, true).print_r($adrparts, true));
				if(isset($adrtype['HOME']) || !isset($rdata['address']))
				{
					$rdata['address'] = '';
					$rdata['town'] = '';
					$rdata['zip'] = '';
					$rdata['_country_label'] = '';
					if(isset($adrparts[2]) && !empty($adrparts[2]))
						$rdata['address'].= str_replace(' | ',"\n",trim($adrparts[2]))."\n";
					if(isset($adrparts[0]) && !empty($adrparts[0]))
						$rdata['address'].= $adrparts[0]."\n";
					if(isset($adrparts[1]) && !empty($adrparts[1]))
						$rdata['address'].= str_replace(' | ',"\n",trim($adrparts[1]))."\n";
					$rdata['address'] = trim($rdata['address']);
					if(isset($adrparts[3]))
						$rdata['town'] = $adrparts[3];
					if(isset($adrparts[5]))
						$rdata['zip'] = $adrparts[5];
					if(isset($adrparts[6]))
						$rdata['_country_label'] = $adrparts[6];
					if($mode=='C' && isset($vCard->ORG))	// keep ORG info in address
						$rdata['address'] = trim((string)$vCard->ORG," ;\n\r\t") . "\n" . $rdata['address'];
				}
			}
		}

		$bday = '';
		if( isset($vCard->BDAY))
			$bday = trim((string)$vCard->BDAY);
		if( isset($vCard->BDAY) &&
			!empty($bday) &&
			date("Y-m-d", strtotime(trim($bday))) == trim($bday) )
			$rdata['birth'] = trim($bday);

		if(isset($vCard->NOTE))
			$rdata['note_public'] = strtr(trim((string)$vCard->NOTE),"\\n", "\n");

		if(isset($rdata['_country_label']) && $rdata['_country_label']!='')
		{
			$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'c_country
					WHERE label LIKE "'.$this->db->escape($rdata['_country_label']).'"
					AND active = 1';
			$result = $this->db->query($sql);
			if($result!==false && ($row = $this->db->fetch_array($result))!==false)
				$rdata['country'] = $row['rowid'];
		}

		debug_log("parsed:\n".print_r($rdata, true));

		return $rdata;
	}


	/*
	 * parse vcard data to dolibarr table fields
	 * @param cardData : string vcard
	 * @param mode : C=create / U=update
	 */
	protected function _parseDataThirdparty($cardData, $mode) {

		debug_log("_parseDataThirdparty( $cardData )");

		$rdata = [] ;

		$vCard = VObject\Reader::read($cardData);
		$vCard->validate(VObject\Node::REPAIR | VObject\Node::PROFILE_CARDDAV);
		$vCard->convert(VObject\Document::VCARD30);

		// debug_log("_parseData__converted( ".$vCard->PHOTO." )");

		$rdata['_uid'] = (string)$vCard->UID;

		if($mode=='C')
			$rdata['status']=1;

		if(!empty((string)$vCard->FN))
			$rdata['nom'] = (string)$vCard->FN;
		else
		{
			$rdata['nom']='';
			$names = $vCard->N->getParts();
			if(!empty((string)$names[0]))
				$rdata['nom'].= (string)$names[0];
			if(!empty((string)$names[1]))
				$rdata['nom'] = trim($rdata['nom']." ".(string)$names[1]);
			if(!empty((string)$names[2]))
				$rdata['nom'] = trim($rdata['nom']." ".(string)$names[2]);
			if(!empty((string)$names[3]))
				$rdata['nom'] = trim((string)$names[3]." ".$rdata['nom']);
			if(empty($rdata['nom']))
				$rdata['nom'] = "New ".date('Y-m-d H:i:s');
		}

		if(isset($vCard->{'NICKNAME'}))
			$rdata['name_alias'] = (string)$vCard->{'NICKNAME'};

		if(isset($vCard->TEL))
		{
			foreach($vCard->TEL as $tel)
			{
				$teltype = [];
				$types = $tel['TYPE'];
				foreach($types as $type)
				{
					$teltype[strtoupper($type)]=true;
				}

				if((float) DOL_VERSION >= 20.0) // field societe.phone_mobile exists
				{
					if(isset($teltype['WORK']) && (isset($teltype['VOICE']) || count($teltype)==1))
						$rdata['phone'] = (string)$tel;
					if(isset($teltype['CELL']))
						$rdata['phone_mobile'] = (string)$tel;
				}
				else // < v 20
				{
					if(isset($teltype['VOICE']) && empty($rdata['phone']))
						$rdata['phone'] = (string)$tel;
					if(isset($teltype['WORK']) && (isset($teltype['VOICE']) || count($teltype)==1))
						$rdata['phone'] = (string)$tel;
				}

				if(isset($teltype['FAX']))
					$rdata['fax'] = (string)$tel;
			}
		}

		if(isset($vCard->EMAIL))
		{
			foreach($vCard->EMAIL as $email)
			{
				if(!isset($rdata['email']))
					$rdata['email'] = (string)$email;
				if(isset($email->PREF))
					$rdata['email'] = (string)$email;
			}
		}

		if(isset($vCard->URL))
			$rdata['url'] = (string)$vCard->URL;

		if(isset($vCard->ADR))
		{
			foreach($vCard->ADR as $adr)
			{
				$types = $adr['TYPE'];
				$adrtype = [];
				foreach($types as $type)
				{
					$adrtype[strtoupper($type)]=true;
				}
				$adrparts = $adr->getParts();
				// debug_log("adrparts:\n".print_r($adrtype, true).print_r($adrparts, true));
				if(isset($adrtype['WORK']) || !isset($rdata['address']))
				{
					$rdata['address'] = '';
					$rdata['town'] = '';
					$rdata['zip'] = '';
					$rdata['_country_label'] = '';
					if(isset($adrparts[2]) && !empty($adrparts[2]))
						$rdata['address'].= str_replace(' | ',"\n",trim($adrparts[2]))."\n";
					if(isset($adrparts[0]) && !empty($adrparts[0]))
						$rdata['address'].= $adrparts[0]."\n";
					if(isset($adrparts[1]) && !empty($adrparts[1]))
						$rdata['address'].= str_replace(' | ',"\n",trim($adrparts[1]))."\n";
					$rdata['address'] = trim($rdata['address']);
					if(isset($adrparts[3]))
						$rdata['town'] = $adrparts[3];
					if(isset($adrparts[5]))
						$rdata['zip'] = $adrparts[5];
					if(isset($adrparts[6]))
						$rdata['_country_label'] = $adrparts[6];
				}
			}
		}

		if(isset($vCard->{'X-WHATSAPP'}))
			$rdata['whatsapp'] = (string)$vCard->{'X-WHATSAPP'};
		if(isset($vCard->{'X-SNAPCHAT'}))
			$rdata['snapchat'] = (string)$vCard->{'X-SNAPCHAT'};
		if(isset($vCard->{'X-LINKEDIN'}))
			$rdata['linkedin'] = (string)$vCard->{'X-LINKEDIN'};
		if(isset($vCard->{'X-INSTAGRAM'}))
			$rdata['instagram'] = (string)$vCard->{'X-INSTAGRAM'};
		if(isset($vCard->{'X-SKYPE'}))
			$rdata['skype'] = (string)$vCard->{'X-SKYPE'};
		elseif(isset($vCard->{'X-SKYPE-USERNAME'}))
			$rdata['skype'] = (string)$vCard->{'X-SKYPE-USERNAME'};

		/**
			IMPP;X-SERVICE-TYPE=GOOGLETALK:xmpp:goog
			IMPP;X-SERVICE-TYPE=JABBER:xmpp:jabjab
			IMPP;X-SERVICE-TYPE=YAHOO:ymsgr:yahoo
			IMPP;X-SERVICE-TYPE=QQ:x-apple:qq
			IMPP;X-SERVICE-TYPE=AIM:aim:aim
			IMPP;X-SERVICE-TYPE=MSN:msnim:msn
			IMPP;X-SERVICE-TYPE=SKYPE:skype:skyp
			IMPP;X-SERVICE-TYPE=ICQ:aim:icqq
			IMPP;X-SERVICE-TYPE=IRC:irc:irc
		**/
		if(isset($vCard->IMPP))
		{
			foreach($vCard->IMPP as $impp)
			{
				$type = strtoupper((string)$impp['X-SERVICE-TYPE']);
				$pseudo = (string)$impp;
				if(mb_strpos($pseudo,':',0,'UTF-8')!==false)
					$pseudo = mb_substr($pseudo, mb_strpos($pseudo,':',0,'UTF-8')+1, null, 'UTF-8');

				switch($type)
				{
					case "WHATSAPP":
						$rdata['whatsapp'] = $pseudo;
						break;
					case "SNAPCHAT":
						$rdata['snapchat'] = $pseudo;
						break;
					case "LINKEDIN":
						$rdata['linkedin'] = $pseudo;
						break;
					case "INSTAGRAM":
						$rdata['instagram'] = $pseudo;
						break;
					case "SKYPE":
						$rdata['skype'] = $pseudo;
						break;
				}
			}
		}


		if(isset($vCard->NOTE))
		{
			$tmp 	= (string)$vCard->NOTE;
			$arrNote = array();
			$arrTmp = explode("\n", $tmp);
			foreach($arrTmp as $line)
			{
				if (mb_strpos($line, '💼', 0, 'UTF-8')!==0
					&& mb_strpos($line, '??', 0, 'UTF-8')!==0				// 💼 could be converted in ?? if utf8 char is truncated on 2 VCal lines
					&& mb_strpos($line, '*DOLIBARR-', 0, 'UTF-8')===false)
				{
					$noteline = preg_replace('/[\x{10000}-\x{10FFFF}]/u', "\xEF\xBF\xBD",$line); // remove utf8mb4 chars
					$arrNote[] = $noteline;
				}
			}
			$rdata['note_public'] = trim(implode("\n", $arrNote));
		}

		if(isset($rdata['_country_label']) && $rdata['_country_label']!='')
		{
			$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'c_country
					WHERE label LIKE "'.$this->db->escape($rdata['_country_label']).'"
					AND active = 1';
			$result = $this->db->query($sql);
			if($result!==false && ($row = $this->db->fetch_array($result))!==false)
				$rdata['fk_pays'] = $row['rowid'];
		}

		debug_log("parsed:\n".print_r($rdata, true));

		return $rdata;
	}

	/**
	 * Returns all cards for a specific addressbook id.
	 *
	 * This method should return the following properties for each card:
	 *   * carddata - raw vcard data
	 *   * uri - Some unique url
	 *   * lastmodified - A unix timestamp
	 *
	 * It's recommended to also return the following properties:
	 *   * etag - A unique etag. This must change every time the card changes.
	 *   * size - The size of the card in bytes.
	 *
	 * If these last two properties are provided, less time will be spent
	 * calculating them. If they are specified, you can also ommit carddata.
	 * This may speed up certain requests, especially with large cards.
	 *
	 * @param mixed $addressbookId
	 * @return array
	 */
	function getCards($addressbookId) {

		debug_log("getCards( $addressbookId )");

		$cards = [] ;

		if(intval($addressbookId)<CDAV_ADDRESSBOOK_ID_SHIFT && $this->user->rights->societe->contact->lire)
		{
			$sql = $this->_getSqlContacts();
			$result = $this->db->query($sql);
			if ($result)
			{
				while ($obj = $this->db->fetch_object($result))
				{
					$carddata = $this->_contactToVCard($obj);

					$cards[] = [
						// 'carddata' => $carddata,  not necessary because etag+size are present
						'uri' => $obj->rowid.'-ct-'.CDAV_URI_KEY,
						'lastmodified' => strtotime($obj->lastupd),
						'etag' => '"'.md5($carddata).'"',
						'size' => strlen($carddata)
					];
				}
			}
		}

		if(CDAV_THIRD_SYNC>0 && intval($addressbookId)>=CDAV_ADDRESSBOOK_ID_SHIFT && intval($addressbookId)<(2*CDAV_ADDRESSBOOK_ID_SHIFT) && $this->user->rights->societe->lire)
		{
			$sql = $this->_getSqlThirdparties();
			$result = $this->db->query($sql);
			if ($result)
			{
				while ($obj = $this->db->fetch_object($result))
				{
					$carddata = $this->_thirdpartyToVCard($obj);

					$cards[] = [
						// 'carddata' => $carddata,  not necessary because etag+size are present
						'uri' => $obj->rowid.'-th-'.CDAV_URI_KEY,
						'lastmodified' => strtotime($obj->lastupd),
						'etag' => '"'.md5($carddata).'"',
						'size' => strlen($carddata)
					];
				}
			}
		}

		if(CDAV_MEMBER_SYNC>0 && intval($addressbookId)>=(2*CDAV_ADDRESSBOOK_ID_SHIFT) && intval($addressbookId)<(3*CDAV_ADDRESSBOOK_ID_SHIFT) && $this->user->hasRight('adherent', 'lire'))
		{
			$sql = $this->_getSqlMembers();
			$result = $this->db->query($sql);
			if ($result)
			{
				while ($obj = $this->db->fetch_object($result))
				{
					$carddata = $this->_memberToVCard($obj);

					$cards[] = [
						// 'carddata' => $carddata,  not necessary because etag+size are present
						'uri' => $obj->rowid.'-mb-'.CDAV_URI_KEY,
						'lastmodified' => strtotime($obj->lastupd),
						'etag' => '"'.md5($carddata).'"',
						'size' => strlen($carddata)
					];
				}
			}
		}
		return $cards;
	}

	/**
	 * Returns a specfic card.
	 *
	 * The same set of properties must be returned as with getCards. The only
	 * exception is that 'carddata' is absolutely required.
	 *
	 * If the card does not exist, you must return false.
	 *
	 * @param mixed $addressBookId
	 * @param string $cardUri
	 * @return array
	 */
	function getCard($addressbookId, $cardUri) {

		debug_log("getCard( $addressbookId , $cardUri )");

		if(intval($addressbookId)<CDAV_ADDRESSBOOK_ID_SHIFT && $this->user->rights->societe->contact->lire)
		{
			if(strpos($cardUri, '-ct-')>0)
				$sqlWhere = ' AND p.rowid='.intval($cardUri);							// cardUri starts with contact id
			else
				return false;

			$sql = $this->_getSqlContacts($sqlWhere);

			$result = $this->db->query($sql);
			if ($result && $obj = $this->db->fetch_object($result))
			{
				$carddata = $this->_contactToVCard($obj);

				$card = [
					'carddata' => $carddata,
					'uri' => $obj->rowid.'-ct-'.CDAV_URI_KEY,
					'lastmodified' => strtotime($obj->lastupd),
					'etag' => '"'.md5($carddata).'"',
					'size' => strlen($carddata)
				];

				return $card;
			}
		}

		if(CDAV_THIRD_SYNC>0 && intval($addressbookId)>=CDAV_ADDRESSBOOK_ID_SHIFT && intval($addressbookId)<(2*CDAV_ADDRESSBOOK_ID_SHIFT) && $this->user->rights->societe->lire)
		{
			if(strpos($cardUri, '-th-')>0)
				$sqlWhere = ' AND s.rowid='.intval($cardUri);							// cardUri starts with contact id
			else
				return false;

			$sql = $this->_getSqlThirdparties($sqlWhere);
			debug_log($sql);
			$result = $this->db->query($sql);
			if ($result && $obj = $this->db->fetch_object($result))
			{
				$carddata = $this->_thirdpartyToVCard($obj);

				$card = [
					'carddata' => $carddata,
					'uri' => $obj->rowid.'-th-'.CDAV_URI_KEY,
					'lastmodified' => strtotime($obj->lastupd),
					'etag' => '"'.md5($carddata).'"',
					'size' => strlen($carddata)
				];

				return $card;
			}
		}

		if(CDAV_MEMBER_SYNC>0 && intval($addressbookId)>=(2*CDAV_ADDRESSBOOK_ID_SHIFT) && intval($addressbookId)<(3*CDAV_ADDRESSBOOK_ID_SHIFT) && $this->user->hasRight('adherent', 'lire'))
		{
			if(strpos($cardUri, '-mb-')>0)
				$sqlWhere = ' AND p.rowid='.intval($cardUri);							// cardUri starts with member id
			else
				return false;

			$sql = $this->_getSqlMembers($sqlWhere);
			debug_log($sql);
			$result = $this->db->query($sql);
			if ($result && $obj = $this->db->fetch_object($result))
			{
				$carddata = $this->_memberToVCard($obj);

				$card = [
					'carddata' => $carddata,
					'uri' => $obj->rowid.'-mb-'.CDAV_URI_KEY,
					'lastmodified' => strtotime($obj->lastupd),
					'etag' => '"'.md5($carddata).'"',
					'size' => strlen($carddata)
				];

				return $card;
			}
		}
		return false;
	}

	/**
	 * Returns a list of cards.
	 *
	 * This method should work identical to getCard, but instead return all the
	 * cards in the list as an array.
	 *
	 * If the backend supports this, it may allow for some speed-ups.
	 *
	 * @param mixed $addressBookId
	 * @param array $uris
	 * @return array
	 */
	function getMultipleCards($addressbookId, array $uris) {

		debug_log("getMultipleCards( $addressbookId , ".implode('; ',$uris)." )");

		$cards = [] ;

		$typecth = '';
		$ids = [];
		$extids = [];

		if(intval($addressbookId)<CDAV_ADDRESSBOOK_ID_SHIFT && $this->user->rights->societe->contact->lire)
		{
			$typecth = '-ct-';
			foreach($uris as $cardUri)
			{
				if(strpos($cardUri, $typecth)>0)
					$ids[] = intval($cardUri);   // cardUri starts with contact id
			}

			$sqlWhere = ' AND p.rowid IN ('.implode(',', $ids).')';

			$sql = $this->_getSqlContacts($sqlWhere);
		}

		if(CDAV_THIRD_SYNC>0 && intval($addressbookId)>=CDAV_ADDRESSBOOK_ID_SHIFT && intval($addressbookId)<(2*CDAV_ADDRESSBOOK_ID_SHIFT) && $this->user->rights->societe->lire)
		{
			$typecth = '-th-';
			foreach($uris as $cardUri)
			{
				if(strpos($cardUri, $typecth)>0)
					$ids[] = intval($cardUri);   // cardUri starts with contact id
			}

			$sqlWhere = ' AND s.rowid IN ('.implode(',', $ids).')';

			$sql = $this->_getSqlThirdparties($sqlWhere);
		}

		if(CDAV_MEMBER_SYNC>0 && intval($addressbookId)>=(2*CDAV_ADDRESSBOOK_ID_SHIFT) && intval($addressbookId)<(3*CDAV_ADDRESSBOOK_ID_SHIFT) && $this->user->hasRight('adherent', 'lire'))
		{
			$typecth = '-mb-';
			foreach($uris as $cardUri)
			{
				if(strpos($cardUri, $typecth)>0)
					$ids[] = intval($cardUri);   // cardUri starts with member id
			}

			$sqlWhere = ' AND p.rowid IN ('.implode(',', $ids).')';

			$sql = $this->_getSqlMembers($sqlWhere);
		}

		if($typecth!='')
		{

			$result = $this->db->query($sql);
			if ($result)
			{
				while ($obj = $this->db->fetch_object($result))
				{
					if($typecth=='-th-')
						$carddata = $this->_thirdpartyToVCard($obj);
					elseif($typecth=='-mb-')
						$carddata = $this->_memberToVCard($obj);
					else
						$carddata = $this->_contactToVCard($obj);

					$cards[] = [
						'carddata' => $carddata,
						'uri' => $obj->rowid.$typecth.CDAV_URI_KEY,
						'lastmodified' => strtotime($obj->lastupd),
						'etag' => '"'.md5($carddata).'"',
						'size' => strlen($carddata)
					];
				}
			}
		}
		return $cards;
	}

	/**
	 * Creates a new card.
	 *
	 * The addressbook id will be passed as the first argument. This is the
	 * same id as it is returned from the getAddressBooksForUser method.
	 *
	 * The cardUri is a base uri, and doesn't include the full path. The
	 * cardData argument is the vcard body, and is passed as a string.
	 *
	 * It is possible to return an ETag from this method. This ETag is for the
	 * newly created resource, and must be enclosed with double quotes (that
	 * is, the string itself must contain the double quotes).
	 *
	 * You should only return the ETag if you store the carddata as-is. If a
	 * subsequent GET request on the same card does not have the same body,
	 * byte-by-byte and you did return an ETag here, clients tend to get
	 * confused.
	 *
	 * If you don't return an ETag, you can just return null.
	 *
	 * @param mixed $addressBookId
	 * @param string $cardUri
	 * @param string $cardData
	 * @return string|null
	 */
	function createCard($addressbookId, $cardUri, $cardData) {

		global $conf;

		debug_log("createContactObject( $addressbookId , $cardUri )");

		if(intval($addressbookId)<CDAV_ADDRESSBOOK_ID_SHIFT && $this->user->rights->societe->contact->creer)
		{
			$rdata = $this->_parseDataContact($cardData, 'C');

			if($rdata['_photo_bin']!==false)
			{
				$gdim = @imagecreatefromstring($rdata['_photo_bin']);
				if($gdim!==false)
					$rdata['photo'] = 'cdavimage.jpg';
			}

			$sql = "INSERT INTO ".MAIN_DB_PREFIX."socpeople (";
			foreach($rdata as $fld => $val)
			{
				if(substr($fld,0,1)!='_')
					$sql.="`".$fld."`,";
			}
			$sql.= "entity,datec,tms,fk_user_creat,fk_user_modif) VALUES(";
			foreach($rdata as $fld => $val)
			{
				if(substr($fld,0,1)!='_')
					$sql.="'".$this->db->escape($val)."',";
			}
			$sql.= "1,NOW(),NOW(),".$this->user->id.",".$this->user->id.")";

			$res = $this->db->query($sql);
			if ( ! $res)
			{
				return null;
			}

			//Récupérer l'ID de l'event créer et faire une insertion dans actioncomm_resources
			$id = $this->db->last_insert_id(MAIN_DB_PREFIX.'socpeople');
			if ( ! $id)
			{
				return null;
			}

			if (! empty($conf->categorie->enabled) && intval(CDAV_CONTACT_TAG)>0)
			{
				$tagid = intval(CDAV_CONTACT_TAG);
				$sql = "INSERT INTO ".MAIN_DB_PREFIX."categorie_contact (`fk_categorie`, `fk_socpeople`)
						VALUES ( ".$tagid.", ".$id.")";
				$this->db->query($sql);
			}

			// save photo with jpeg format
			if(isset($rdata['photo']))
			{
				$dir = $conf->societe->dir_output."/contact/".$id."/photos";
				@mkdir($dir, 0777, true);
				if(@imagejpeg($gdim, $dir.'/'.$rdata['photo']))
				{
					$object = new \Contact($this->db);
					if($object->fetch($id)>0)
						$object->addThumbs($dir.'/'.$rdata['photo']);
				}
			}
			return null;
		}

		if(CDAV_THIRD_SYNC>0 && intval($addressbookId)>=CDAV_ADDRESSBOOK_ID_SHIFT && intval($addressbookId)<(2*CDAV_ADDRESSBOOK_ID_SHIFT) && $this->user->rights->societe->creer)
		{
			$rdata = $this->_parseDataThirdparty($cardData, 'C');


			$sql = "INSERT INTO ".MAIN_DB_PREFIX."societe (";
			foreach($rdata as $fld => $val)
			{
				if(substr($fld,0,1)!='_')
					$sql.="`".$fld."`,";
			}
			$sql.= "entity,datec,tms,fk_user_creat,fk_user_modif) VALUES(";
			foreach($rdata as $fld => $val)
			{
				if(substr($fld,0,1)!='_')
					$sql.="'".$this->db->escape($val)."',";
			}
			$sql.= "1,NOW(),NOW(),".$this->user->id.",".$this->user->id.")";

			$res = $this->db->query($sql);
			if ( ! $res)
			{
				return null;
			}

			//Récupérer l'ID de l'event créer et faire une insertion dans actioncomm_resources
			$id = $this->db->last_insert_id(MAIN_DB_PREFIX.'societe');
			if ( ! $id)
			{
				return null;
			}

			//Insérer association user/thirdpartie
			$sql = "INSERT INTO ".MAIN_DB_PREFIX."societe_commerciaux (`fk_soc`, `fk_user`)
					VALUES (".$id.",".$this->user->id.")";
			$this->db->query($sql);

			return null;
		}

		if(CDAV_MEMBER_SYNC>0 && intval($addressbookId)>=(2*CDAV_ADDRESSBOOK_ID_SHIFT) && intval($addressbookId)<(3*CDAV_ADDRESSBOOK_ID_SHIFT) && $this->user->hasRight('adherent','creer'))
		{
			$rdata = $this->_parseDataMember($cardData, 'C');


			$sql = "INSERT INTO ".MAIN_DB_PREFIX."adherent (";
			foreach($rdata as $fld => $val)
			{
				if(substr($fld,0,1)!='_')
					$sql.="`".$fld."`,";
			}
			$sql.= "entity,datec,tms,fk_user_author,fk_user_mod) VALUES(";
			foreach($rdata as $fld => $val)
			{
				if(substr($fld,0,1)!='_')
					$sql.="'".$this->db->escape($val)."',";
			}
			$sql.= "1,NOW(),NOW(),".$this->user->id.",".$this->user->id.")";

			$res = $this->db->query($sql);
			if ( ! $res)
			{
				return null;
			}

			return null;
		}

		return null;
	}

	/**
	 * Updates a card.
	 *
	 * The addressbook id will be passed as the first argument. This is the
	 * same id as it is returned from the getAddressBooksForUser method.
	 *
	 * The cardUri is a base uri, and doesn't include the full path. The
	 * cardData argument is the vcard body, and is passed as a string.
	 *
	 * It is possible to return an ETag from this method. This ETag should
	 * match that of the updated resource, and must be enclosed with double
	 * quotes (that is: the string itself must contain the actual quotes).
	 *
	 * You should only return the ETag if you store the carddata as-is. If a
	 * subsequent GET request on the same card does not have the same body,
	 * byte-by-byte and you did return an ETag here, clients tend to get
	 * confused.
	 *
	 * If you don't return an ETag, you can just return null.
	 *
	 * @param mixed $addressBookId
	 * @param string $cardUri
	 * @param string $cardData
	 * @return string|null
	 */
	function updateCard($addressbookId, $cardUri, $cardData) {

		global $conf;

		debug_log("updateContactObject( $addressbookId , $cardUri )");

		if(intval($addressbookId)<CDAV_ADDRESSBOOK_ID_SHIFT && $this->user->rights->societe->contact->creer)
		{
			$rdata = $this->_parseDataContact($cardData, 'U');

			if(strpos($cardUri, '-ct-')>0)
				$contactid = intval($cardUri); // cardUri starts with contact id
			else
				return false;

			if($rdata['_photo_bin']!==false)
			{
				$gdim = @imagecreatefromstring($rdata['_photo_bin']);
				if($gdim!==false)
					$rdata['photo'] = 'cdavimage.jpg';
			}

			$sql = "UPDATE ".MAIN_DB_PREFIX."socpeople SET ";
			foreach($rdata as $fld => $val)
			{
				if(substr($fld,0,1)!='_')
					$sql.="`".$fld."` = '".$this->db->escape($val)."', ";
			}
			$sql.= " tms = NOW(), fk_user_modif = ".$this->user->id;
			$sql.= " WHERE rowid = ".$contactid;
			$res = $this->db->query($sql);

			$this->db->query($sql);

			// save photo with jpeg format
			if(isset($rdata['photo']))
			{
				$dir = $conf->societe->dir_output."/contact/".$contactid."/photos";
				@mkdir($dir, 0777, true);
				if(@imagejpeg($gdim, $dir.'/'.$rdata['photo']))
				{
					$object = new \Contact($this->db);
					if($object->fetch($contactid)>0)
						$object->addThumbs($dir.'/'.$rdata['photo']);
				}
			}
		}

		if(CDAV_THIRD_SYNC>0 && intval($addressbookId)>=CDAV_ADDRESSBOOK_ID_SHIFT && intval($addressbookId)<(2*CDAV_ADDRESSBOOK_ID_SHIFT) && $this->user->rights->societe->creer)
		{
			$rdata = $this->_parseDataThirdparty($cardData, 'U');

			if(strpos($cardUri, '-th-')>0)
				$socid = intval($cardUri); // cardUri starts with contact id
			else
				return false;

			$sql = "UPDATE ".MAIN_DB_PREFIX."societe SET ";
			foreach($rdata as $fld => $val)
			{
				if(substr($fld,0,1)!='_')
					$sql.="`".$fld."` = '".$this->db->escape($val)."', ";
			}
			$sql.= " tms = NOW(), fk_user_modif = ".$this->user->id;
			$sql.= " WHERE rowid = ".$socid;
			$res = $this->db->query($sql);
			$this->db->query($sql);
		}

		if(CDAV_MEMBER_SYNC>0 && intval($addressbookId)>=(2*CDAV_ADDRESSBOOK_ID_SHIFT) && intval($addressbookId)<(3*CDAV_ADDRESSBOOK_ID_SHIFT) && $this->user->hasRigh('adherent','creer'))
		{
			$rdata = $this->_parseDataMember($cardData, 'U');

			if(strpos($cardUri, '-mb-')>0)
				$adhid = intval($cardUri); // cardUri starts with member id
			else
				return false;

			$sql = "UPDATE ".MAIN_DB_PREFIX."adherent SET ";
			foreach($rdata as $fld => $val)
			{
				if(substr($fld,0,1)!='_')
					$sql.="`".$fld."` = '".$this->db->escape($val)."', ";
			}
			$sql.= " tms = NOW(), fk_user_mod = ".$this->user->id;
			$sql.= " WHERE rowid = ".$adhid;
			$res = $this->db->query($sql);
			$this->db->query($sql);
		}

		return null;
	}

	/**
	 * Deletes a card
	 *
	 * @param mixed $addressBookId
	 * @param string $cardUri
	 * @return bool
	 */
	function deleteCard($addressbookId, $cardUri) {

		debug_log("deleteContactObject( $addressbookId , $cardUri )");

		if(intval($addressbookId)<CDAV_ADDRESSBOOK_ID_SHIFT && $this->user->rights->societe->contact->supprimer)
		{

			if(strpos($cardUri, '-ct-')>0)
				$contactid = intval($cardUri); // cardUri starts with contact id
			else
				return false;

			$sql = "UPDATE ".MAIN_DB_PREFIX."socpeople SET ";
			$sql.= " statut = 0, tms = NOW(), fk_user_modif = ".$this->user->id;
			$sql.= " WHERE rowid = ".$contactid;
			$res = $this->db->query($sql);

			return true;
		}

		if(CDAV_THIRD_SYNC>0 && intval($addressbookId)>=CDAV_ADDRESSBOOK_ID_SHIFT && intval($addressbookId)<(2*CDAV_ADDRESSBOOK_ID_SHIFT) && $this->user->rights->societe->supprimer)
		{

			if(strpos($cardUri, '-th-')>0)
				$socid = intval($cardUri); // cardUri starts with contact id
			else
				return false;

			$sql = "UPDATE ".MAIN_DB_PREFIX."societe SET ";
			$sql.= " status = 0, tms = NOW(), fk_user_modif = ".$this->user->id;
			$sql.= " WHERE rowid = ".$socid;
			$res = $this->db->query($sql);

			return true;
		}

		if(CDAV_MEMBER_SYNC>0 && intval($addressbookId)>=(2*CDAV_ADDRESSBOOK_ID_SHIFT) && intval($addressbookId)<(3*CDAV_ADDRESSBOOK_ID_SHIFT) && $this->user->hasRight('adherent','supprimer'))
		{

			if(strpos($cardUri, '-mb-')>0)
				$adhid = intval($cardUri); // cardUri starts with member id
			else
				return false;

			$sql = "UPDATE ".MAIN_DB_PREFIX."adherent SET ";
			$sql.= " statut = 0, tms = NOW(), fk_user_mod = ".$this->user->id;
			$sql.= " WHERE rowid = ".$adhid;
			$res = $this->db->query($sql);

			return true;
		}

		return false;
	}

	/**
	 * The getChanges method returns all the changes that have happened, since
	 * the specified syncToken in the specified address book.
	 *
	 * This function should return an array, such as the following:
	 *
	 * [
	 *   'syncToken' => 'The current synctoken',
	 *   'added'   => [
	 *	  'new.txt',
	 *   ],
	 *   'modified'   => [
	 *	  'updated.txt',
	 *   ],
	 *   'deleted' => [
	 *	  'foo.php.bak',
	 *	  'old.txt'
	 *   ]
	 * ];
	 *
	 * The returned syncToken property should reflect the *current* syncToken
	 * of the addressbook, as reported in the {http://sabredav.org/ns}sync-token
	 * property. This is needed here too, to ensure the operation is atomic.
	 *
	 * If the $syncToken argument is specified as null, this is an initial
	 * sync, and all members should be reported.
	 *
	 * The modified property is an array of nodenames that have changed since
	 * the last token.
	 *
	 * The deleted property is an array with nodenames, that have been deleted
	 * from collection.
	 *
	 * The $syncLevel argument is basically the 'depth' of the report. If it's
	 * 1, you only have to report changes that happened only directly in
	 * immediate descendants. If it's 2, it should also include changes from
	 * the nodes below the child collections. (grandchildren)
	 *
	 * The $limit argument allows a client to specify how many results should
	 * be returned at most. If the limit is not specified, it should be treated
	 * as infinite.
	 *
	 * If the limit (infinite or not) is higher than you're willing to return,
	 * you should throw a Sabre\DAV\Exception\TooMuchMatches() exception.
	 *
	 * If the syncToken is expired (due to data cleanup) or unknown, you must
	 * return null.
	 *
	 * The limit is 'suggestive'. You are free to ignore it.
	 *
	 * @param string $addressBookId
	 * @param string $syncToken
	 * @param int $syncLevel
	 * @param int $limit
	 * @return array
	 */
	function getChangesForAddressBook($addressbookId, $syncToken, $syncLevel, $limit = null) {

		// TODO
		return null;
	}

}
