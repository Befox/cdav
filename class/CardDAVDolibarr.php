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
require_once __DIR__.'/CardDAVNativeOperations.php';
require_once __DIR__.'/../lib/cdav_documents.lib.php';

class Dolibarr extends AbstractBackend implements SyncSupport {
	use CardDAVNativeOperations;

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
		if ($principalUri !== 'principals/'.$this->user->login || !\CDavCompatibility::isFeatureAvailable('dav')) return array();

		global $conf;


		$addressBooks = [];

		if (\CDavCompatibility::isFeatureAvailable('carddav') && $this->user->hasRight('societe', 'contact', 'lire')) {
			// Hash the visible set: MAX(tms) alone misses removals and permission changes.
			$result = $this->db->query($this->_getSqlContacts('', true).' ORDER BY p.rowid');
			if (!$result) throw new DAV\Exception\ServiceUnavailable($this->langs->transnoentities('CDavContactsUnavailable'));
			$state = hash_init('sha256');
			hash_update($state, getDolGlobalInt('CDAV_CONTACT_SYNC_CIVILITY').':'.getDolGlobalInt('CDAV_CONCAT_SOCNAME_FOR_PHONE').':'.(int) $this->user->hasRight('categorie', 'lire').':'.(int) isModEnabled('categorie'));
			while (is_object($row = $this->db->fetch_object($result))) {
				hash_update($state, serialize($row));
			}
			$lastupd = 'contacts-v2-'.hash_final($state);

			$addressBooks[] = [
				'id'														  => $this->user->id,
				'uri'														  => 'default',
				'principaluri'												  => $principalUri,
				'{DAV:}displayname'											  => getDolGlobalString('MAIN_INFO_SOCIETE_NOM').' - '.$this->langs->transnoentities('Contacts'),
				'{' . CardDAV\Plugin::NS_CARDDAV . '}addressbook-description' => $this->langs->transnoentities('Contacts').' '.getDolGlobalString('MAIN_INFO_SOCIETE_NOM').' '.$this->user->login,
				'{http://calendarserver.org/ns/}getctag'					  => $lastupd,
				'{http://sabredav.org/ns}sync-token'						  => $lastupd,
			];
		}

		if(\CDavCompatibility::isFeatureAvailable('carddav') && CDAV_THIRD_SYNC>0 && $this->user->hasRight('societe', 'lire'))
		{
			$sql = 'SELECT MAX(s.tms) lastupd FROM '.MAIN_DB_PREFIX.'societe as s
					LEFT JOIN '.MAIN_DB_PREFIX.'societe_commerciaux as sc ON sc.fk_soc = s.rowid
					WHERE s.entity IN ('.getEntity('societe', 1).')
					AND s.status=1';
			if(!$this->user->hasRight('societe', 'client', 'voir'))
				$sql.= ' AND s.rowid = sc.fk_soc AND sc.fk_user = '.((int) $this->user->id);
			if (!$this->user->hasRight('fournisseur', 'lire'))
				$sql .= ' AND (s.fournisseur <> 1 OR s.client <> 0)'; // client=0, fournisseur=0 must be visible
			if (CDAV_THIRD_SYNC==1) // without contact
				$sql .= ' AND (SELECT count(sp.rowid) FROM '.MAIN_DB_PREFIX.'socpeople sp WHERE sp.fk_soc=s.rowid)=0';
			$result = $this->db->query($sql);
			$row = $result ? $this->db->fetch_array($result) : false;
			$lastupd = $row && $row['lastupd'] ? strtotime($row['lastupd']) : 0;

			$addressBooks[] = [
				'id'														  => $this->user->id + CDAV_ADDRESSBOOK_ID_SHIFT,
				'uri'														  => 'thirdparties',
				'principaluri'												  => $principalUri,
				'{DAV:}displayname'											  => getDolGlobalString('MAIN_INFO_SOCIETE_NOM').' - '.$this->langs->transnoentities('ThirdParties'),
				'{' . CardDAV\Plugin::NS_CARDDAV . '}addressbook-description' => $this->langs->transnoentities('ThirdParties').' '.getDolGlobalString('MAIN_INFO_SOCIETE_NOM').' '.$this->user->login,
				'{http://calendarserver.org/ns/}getctag'					  => $lastupd,
				'{http://sabredav.org/ns}sync-token'						  => $lastupd,
			];
		}

		if(\CDavCompatibility::isFeatureAvailable('members') && $this->user->hasRight('adherent', 'lire'))
		{
			$sql = 'SELECT MAX(GREATEST(COALESCE(s.tms, p.tms), p.tms)) lastupd FROM '.MAIN_DB_PREFIX.'adherent as p
					LEFT JOIN '.MAIN_DB_PREFIX.'societe as s ON s.rowid = p.fk_soc AND s.entity IN ('.getEntity('societe').') AND '.($this->user->hasRight('societe', 'lire') ? '1' : '0').'
					WHERE p.entity IN ('.getEntity('adherent', 1).')';
			$result = $this->db->query($sql);
			$row = $result ? $this->db->fetch_array($result) : false;
			$lastupd = $row && $row['lastupd'] ? strtotime($row['lastupd']) : 0;

			$addressBooks[] = [
				'id'														  => $this->user->id + 2*CDAV_ADDRESSBOOK_ID_SHIFT,
				'uri'														  => 'members',
				'principaluri'												  => $principalUri,
				'{DAV:}displayname'											  => getDolGlobalString('MAIN_INFO_SOCIETE_NOM').' - '.$this->langs->transnoentities('Members'),
				'{' . CardDAV\Plugin::NS_CARDDAV . '}addressbook-description' => $this->langs->transnoentities('Members').' '.getDolGlobalString('MAIN_INFO_SOCIETE_NOM').' '.$this->user->login,
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
	 * @param string $sqlWhere Internal SQL restriction, never raw client input.
	 * @param bool $metadataOnly Select lightweight collection state without loading photos.
	 * @return string
	 */
	protected function _getSqlContacts($sqlWhere='', $metadataOnly=false)
	{
		$fields = $metadataOnly ? 'p.rowid, p.tms, s.tms parent_tms' : 'p.*, co.label country_label, GREATEST(COALESCE(s.tms, p.tms), p.tms) lastupd, s.code_client soc_code_client, s.code_fournisseur soc_code_fournisseur,
					s.nom soc_nom, s.name_alias soc_name_alias, s.address soc_address, s.zip soc_zip, s.town soc_town, cos.label soc_country_label, s.phone soc_phone, s.fax soc_fax,
					s.email soc_email, s.url soc_url, s.client soc_client, s.fournisseur soc_fournisseur, s.note_private soc_note_private, s.note_public soc_note_public, s.logo';
		$sql = 'SELECT '.$fields.',
					GROUP_CONCAT(DISTINCT cat.label ORDER BY cat.label ASC SEPARATOR \',\') category_label,
					GROUP_CONCAT(DISTINCT cc.fk_categorie ORDER BY cc.fk_categorie ASC SEPARATOR \',\') category_ids
				FROM '.MAIN_DB_PREFIX.'socpeople as p
				LEFT JOIN '.MAIN_DB_PREFIX.'c_country as co ON co.rowid = p.fk_pays
				LEFT JOIN '.MAIN_DB_PREFIX.'societe as s ON s.rowid = p.fk_soc AND s.entity IN ('.getEntity('societe').') AND '.($this->user->hasRight('societe', 'lire') ? '1' : '0').'
				LEFT JOIN '.MAIN_DB_PREFIX.'c_country as cos ON cos.rowid = s.fk_pays
				LEFT JOIN '.MAIN_DB_PREFIX.'categorie_contact as cc ON cc.fk_socpeople = p.rowid
				LEFT JOIN '.MAIN_DB_PREFIX.'categorie as cat ON cat.rowid = cc.fk_categorie AND cat.entity IN ('.getEntity('category').')
				WHERE p.entity IN ('.getEntity('socpeople', 1).')
				AND p.statut=1
				AND (p.priv=0 OR (p.priv=1 AND p.fk_user_creat='.((int) $this->user->id).'))';
		// An unlinked contact is independent; a set but missing/inaccessible parent is not.
		$sql .= ' AND (p.fk_soc IS NULL OR p.fk_soc = 0 OR (s.rowid IS NOT NULL';
		if (!$this->user->hasRight('societe', 'client', 'voir')) {
			$sql .= ' AND EXISTS (SELECT 1 FROM '.MAIN_DB_PREFIX.'societe_commerciaux sc WHERE sc.fk_soc = s.rowid AND sc.fk_user = '.((int) $this->user->id).')';
		}
		if (!$this->user->hasRight('fournisseur', 'lire')) {
			$sql .= ' AND (s.fournisseur <> 1 OR s.client <> 0)';
		}
		$sql .= '))'.$sqlWhere.' GROUP BY p.rowid';

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
				LEFT JOIN '.MAIN_DB_PREFIX.'societe as s ON s.rowid = p.fk_soc AND s.entity IN ('.getEntity('societe').') AND '.($this->user->hasRight('societe', 'lire') ? '1' : '0').'
				LEFT JOIN '.MAIN_DB_PREFIX.'c_country as cos ON cos.rowid = s.fk_pays
				LEFT JOIN '.MAIN_DB_PREFIX.'categorie_member as cc ON cc.fk_member = p.rowid
				LEFT JOIN '.MAIN_DB_PREFIX.'categorie as cat ON cat.rowid = cc.fk_categorie AND cat.entity IN ('.getEntity('category').')
				WHERE p.entity IN ('.getEntity('adherent', 1).')
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
				LEFT JOIN '.MAIN_DB_PREFIX.'categorie as cat ON cat.rowid = cs.fk_categorie AND cat.entity IN ('.getEntity('category').')
				WHERE s.entity IN ('.getEntity('societe', 1).')
				AND s.status=1';
		if(!$this->user->hasRight('societe', 'client', 'voir'))
			$sql.= ' AND s.rowid = sc.fk_soc AND sc.fk_user = '.((int) $this->user->id);
		if (!$this->user->hasRight('fournisseur', 'lire'))
			$sql .= ' AND (s.fournisseur <> 1 OR s.client <> 0)'; // client=0, fournisseur=0 must be visible
		if (CDAV_THIRD_SYNC==1) // without contact
			$sql .= ' AND (SELECT count(sp.rowid) FROM '.MAIN_DB_PREFIX.'socpeople sp WHERE sp.fk_soc=s.rowid)=0';
		$sql .= $sqlWhere.' GROUP BY s.rowid';

		return $sql;
	}

	/**
	 * Social networks handled by cdav, indexed by Dolibarr dictionary code
	 * (llx_c_socialnetworks.code). Single source of truth shared by
	 * _socialNetworksToVCard() and _socialNetworksFromVCard(), so that both
	 * directions can not diverge : adding a network is one line here.
	 *
	 * 'kind' says how the network travels in a vCard, because no single property
	 * is understood by every platform :
	 *   'im'     => IMPP;X-SERVICE-TYPE=<label>:<scheme>:<value>
	 *               read by iOS (Instant Message) and by DAVx5/Android (IM row)
	 *   'social' => X-SOCIALPROFILE;TYPE=<code>;X-USER=<value>:<url>
	 *               read by iOS (Social Profile). Android has no social profile
	 *               row and Thunderbird has no social/chat field at all, so both
	 *               simply ignore it (they never send it back either, and
	 *               _mergeSocialNetworks() therefore keeps the stored value).
	 *
	 * Dolibarr stores the values since 11.0 in the socialnetworks json column of
	 * llx_socpeople / llx_societe / llx_adherent ; the individual columns
	 * (skype, linkedin, ...) were dropped in 15.0.
	 */
	protected static $socialnetworks_map = array(
						'skype'		=> array('kind'=>'im',		'scheme'=>'skype',		'label'=>'Skype'),
						'whatsapp'	=> array('kind'=>'im',		'scheme'=>'whatsapp',	'label'=>'WhatsApp'),
						'linkedin'	=> array('kind'=>'social',	'scheme'=>null,			'label'=>null),
						'twitter'	=> array('kind'=>'social',	'scheme'=>null,			'label'=>null),
						'facebook'	=> array('kind'=>'social',	'scheme'=>null,			'label'=>null),
						'instagram'	=> array('kind'=>'social',	'scheme'=>null,			'label'=>null),
						'snapchat'	=> array('kind'=>'social',	'scheme'=>null,			'label'=>null),
						'mastodon'	=> array('kind'=>'social',	'scheme'=>null,			'label'=>null),
						'github'	=> array('kind'=>'social',	'scheme'=>null,			'label'=>null),
						'youtube'	=> array('kind'=>'social',	'scheme'=>null,			'label'=>null),
						);

	/**
	 * Spellings accepted on input for X-SOCIALPROFILE TYPE and IMPP X-SERVICE-TYPE
	 * => Dolibarr dictionary code
	 */
	protected static $socialnetworks_alias = array(
						'x'					=> 'twitter',	// twitter was renamed
						'twitter.com'		=> 'twitter',
						'x.com'				=> 'twitter',
						'linked-in'			=> 'linkedin',
						'skype-username'	=> 'skype',
						);

	/**
	 * Url templates of the Dolibarr social networks dictionary
	 * @var array|null code => url containing {socialid}
	 */
	protected $socialnetworks_urls = null;

	/**
	 * Read the url templates of the Dolibarr social networks dictionary.
	 * Rows are not filtered on active : an inactive network is not offered in the
	 * Dolibarr edit form but an already stored value is still displayed, so its
	 * url template stays useful.
	 *
	 * @return array code => url containing {socialid}
	 */
	protected function _socialNetworkUrls()
	{
		if(!is_array($this->socialnetworks_urls))
		{
			$this->socialnetworks_urls = array();
			$sql = "SELECT code, url FROM ".MAIN_DB_PREFIX."c_socialnetworks
					WHERE entity IN (".getEntity('c_socialnetworks').")";
			$result = $this->db->query($sql);
			if($result!==false)
			{
				while(is_object($row = $this->db->fetch_object($result)))
					$this->socialnetworks_urls[$row->code] = $row->url;
			}
		}
		return $this->socialnetworks_urls;
	}

	/**
	 * Resolve a service name (X-SOCIALPROFILE TYPE, IMPP X-SERVICE-TYPE or IMPP uri
	 * scheme) to a Dolibarr dictionary code
	 *
	 * @param string|null $name service name as written by the client
	 * @return string|false Dolibarr code, false if unknown to cdav
	 */
	protected function _socialNetworkCode($name)
	{
		$name = strtolower(trim((string) $name));
		if($name==='')
			return false;
		if(isset(self::$socialnetworks_alias[$name]))
			$name = self::$socialnetworks_alias[$name];
		if(isset(self::$socialnetworks_map[$name]))
			return $name;
		foreach(self::$socialnetworks_map as $code => $def)
		{
			if(!empty($def['scheme']) && $def['scheme']===$name)
				return $code;
		}
		return false;
	}

	/**
	 * Quote a vCard parameter value when it holds a character that would end it
	 * (RFC 2426 4.) : ':' ';' ','
	 *
	 * @param string $value parameter value
	 * @return string
	 */
	protected function _quoteVCardParam($value)
	{
		$value = str_replace('"', '', $value);	// a quoted param value can not hold a double quote
		if(strpbrk($value, ":;,")!==false)
			return '"'.$value.'"';
		return $value;
	}

	/**
	 * Decode the socialnetworks json column of a Dolibarr row
	 *
	 * @param object $obj row holding a socialnetworks property
	 * @return array indexed by Dolibarr socialnetworks codes
	 */
	protected function _decodeSocialNetworks($obj)
	{
		if(empty($obj->socialnetworks))
			return array();
		$networks = json_decode($obj->socialnetworks, true);
		return is_array($networks) ? $networks : array();
	}

	/**
	 * Build the vCard lines of the socialnetworks json column : IMPP for the chat
	 * services, X-SOCIALPROFILE for the social profiles (see $socialnetworks_map)
	 *
	 * @param object $obj row holding a socialnetworks property
	 * @return string
	 */
	protected function _socialNetworksToVCard($obj)
	{
		$carddata = '';
		$networks = $this->_decodeSocialNetworks($obj);
		$urls = $this->_socialNetworkUrls();
		foreach($networks as $code => $val)
		{
			$code = strtolower($code);
			if(empty($val) || !isset(self::$socialnetworks_map[$code]))
				continue;
			$def = self::$socialnetworks_map[$code];
			if($def['kind']=='im')
			{
				$carddata.="IMPP;X-SERVICE-TYPE=".$def['label'].":".$def['scheme'].":".str_replace(';','\;',$val)."\n";
				continue;
			}
			// the stored value may already be a full url, Dolibarr accepts both
			if(preg_match('/^https?:\/\//i', $val))
				$url = $val;
			elseif(!empty($urls[$code]))
				$url = str_replace('{socialid}', $this->_urlEncodeSocialId($val), $urls[$code]);
			else
				$url = 'x-apple:'.$this->_urlEncodeSocialId($val);
			$carddata.="X-SOCIALPROFILE;TYPE=".$code.";X-USER=".$this->_quoteVCardParam($val).":".$url."\n";
		}
		return $carddata;
	}

	/**
	 * Percent encode the characters that would make an url invalid, leaving '/' and
	 * '@' alone so that a value like 'c/mychannel' still builds a working url.
	 * Same substitution as dol_print_socialnetworks(), which does not encode either.
	 *
	 * @param string $value social network id as stored by Dolibarr
	 * @return string
	 */
	protected function _urlEncodeSocialId($value)
	{
		return strtr($value, array(' '=>'%20', '"'=>'%22', '<'=>'%3C', '>'=>'%3E', '\\'=>'%5C', '^'=>'%5E', '`'=>'%60', '{'=>'%7B', '|'=>'%7C', '}'=>'%7D'));
	}

	/**
	 * Read the X-USER parameter and the value of an X-SOCIALPROFILE property.
	 *
	 * iOS writes the parameter unquoted, so when the user pasted a full url in the
	 * social profile field the ':' of 'https://' ends the parameter (RFC 2426 4.)
	 * and any conforming parser truncates it :
	 *   X-SOCIALPROFILE;type=linkedin;x-user=https://www.linkedin.com/in/jd:http://...
	 *   => x-user='https'  value='//www.linkedin.com/in/jd:http://...'
	 * Detected by a bare uri scheme in X-USER followed by a value starting with
	 * '//', and put back together here.
	 *
	 * Nothing is percent decoded here : Apple writes the literal user input in
	 * X-USER and only encodes the value, and a network id may legitimately hold
	 * percent sequences (a LinkedIn slug is 's%C3%A9bastien-montusclat-6b97327b').
	 * _normalizeSocialId() does the cleaning.
	 *
	 * @param object $prop X-SOCIALPROFILE property
	 * @return array array(x-user, value)
	 */
	protected function _repairSocialProfile($prop)
	{
		$xuser = ($prop['X-USER']===null ? '' : (string) $prop['X-USER']);
		$value = (string) $prop;
		if($xuser!=='' && preg_match('/^[a-z][a-z0-9+.-]*$/i', $xuser) && substr($value,0,2)=='//')
		{
			$pos = strpos($value, ':');
			if($pos===false)
			{
				$xuser .= ':'.$value;
				$value = '';
			}
			else
			{
				$xuser .= ':'.substr($value, 0, $pos);
				$value = substr($value, $pos+1);
			}
		}
		return array(trim($xuser), trim($value));
	}

	/**
	 * Reduce whatever a client sent to the bare network id Dolibarr expects.
	 *
	 * This is what keeps cdav and iOS from feeding each other : iOS rebuilds its own
	 * url by prepending its template to the value it received, so if cdav stored that
	 * url and sent it back, every edit added one more prefix :
	 *   http://www.linkedin.com/in///www.linkedin.com/in///www.linkedin.com/<id>:https://...
	 * The base url of the network (taken from the llx_c_socialnetworks template) is
	 * therefore stripped as many times as it was prepended, then the url iOS appended
	 * after a ':' is dropped, which makes the function idempotent and the exchange
	 * stable however many round trips happen.
	 *
	 * Networks whose template is only {socialid} (mastodon, snapchat) have no fixed
	 * base url : their value is a full url by design and is left untouched.
	 *
	 * @param string $code Dolibarr dictionary code
	 * @param string $value value as sent by the client
	 * @return string bare network id
	 */
	protected function _normalizeSocialId($code, $value)
	{
		// a double quote can only come from a badly parsed X-USER parameter
		$value = trim(str_replace(array('%22', '"'), '', (string) $value));
		$value = trim(str_replace('%20', ' ', $value));
		if($value==='')
			return '';

		$urls = $this->_socialNetworkUrls();
		if(empty($urls[$code]))
			return $value;

		// base url of the network, without the {socialid} placeholder
		$base = preg_replace('/\/?\{socialid\}.*$/', '', $urls[$code]);
		$base = preg_replace('#^https?://#i', '', $base);
		$base = preg_replace('#^www\.#i', '', $base);
		if($base==='' || strpos($base, '{')!==false)
			return $value;	// template without a fixed base : value is a full url by design

		$host = preg_replace('#/.*$#', '', $base);					// linkedin.com
		$path = substr($base, strlen($host));						// /in
		$pattern = '#^((https?:)?//)?(www\.)?'.preg_quote($host, '#')
					.($path!=='' ? '('.preg_quote($path, '#').')?' : '').'/*#i';
		do {
			$before = $value;
			$value = preg_replace($pattern, '', $value);
		} while($value!==$before);

		// a network id never holds a ':' : drop the url iOS appended after it, but keep
		// a foreign url the user deliberately stored
		if(!preg_match('#^[a-z][a-z0-9+.-]*://#i', $value) && ($pos = strpos($value, ':'))!==false)
			$value = substr($value, 0, $pos);

		return trim($value, " \t/");
	}

	/**
	 * Read every shape a client may use to carry a social network, weakest first so
	 * that the most explicit one wins :
	 *   1. X-<NETWORK>          emitted by cdav before 3.3, kept for compatibility
	 *   2. IMPP                 DAVx5/Android, iOS (Instant Message), CardBook
	 *   3. X-SOCIALPROFILE      iOS (Social Profile)
	 *
	 * @param object $vCard parsed vCard
	 * @return array indexed by Dolibarr socialnetworks codes
	 */
	protected function _socialNetworksFromVCard($vCard)
	{
		$networks = array();

		// 1. legacy X-<NETWORK>
		foreach(self::$socialnetworks_map as $code => $def)
		{
			$xname = 'X-'.strtoupper($code);
			if(isset($vCard->{$xname}))
				$networks[$code] = $this->_normalizeSocialId($code, (string) $vCard->{$xname});
		}
		if(!isset($networks['skype']) && isset($vCard->{'X-SKYPE-USERNAME'}))
			$networks['skype'] = $this->_normalizeSocialId('skype', (string) $vCard->{'X-SKYPE-USERNAME'});

		// 2. IMPP : the value is <scheme>:<handle>, the service may also be named
		//    in X-SERVICE-TYPE (iOS)
		if(isset($vCard->IMPP))
		{
			foreach($vCard->IMPP as $impp)
			{
				$value = trim((string) $impp);
				$scheme = '';
				if(($pos = strpos($value, ':'))!==false)
				{
					$scheme = substr($value, 0, $pos);
					$value = substr($value, $pos+1);
				}
				$code = $this->_socialNetworkCode($impp['X-SERVICE-TYPE']);
				if($code===false)
					$code = $this->_socialNetworkCode($scheme);
				$value = $this->_normalizeSocialId($code, $value);
				if($code!==false && $value!=='')
					$networks[$code] = $value;
			}
		}

		// 3. X-SOCIALPROFILE
		if(isset($vCard->{'X-SOCIALPROFILE'}))
		{
			foreach($vCard->{'X-SOCIALPROFILE'} as $prop)
			{
				$code = $this->_socialNetworkCode($prop['TYPE']);
				if($code===false)
					continue;	// unknown service, or iOS 'Customsocial'
				list($xuser, $value) = $this->_repairSocialProfile($prop);
				// x-user holds what the user typed, the value holds the url iOS built
				$val = ($xuser!=='' ? $xuser : $value);
				if(preg_match('/^x-apple:/i', $val))
					continue;	// iOS placeholder for a service it does not know
				$val = $this->_normalizeSocialId($code, $val);
				if($val!=='')
					$networks[$code] = $val;
			}
		}

		return $networks;
	}

	/**
	 * Merge the networks read from a vCard into those already stored and return the
	 * json to write in the socialnetworks column. Networks unknown to cdav (set from
	 * the Dolibarr UI) are preserved, since the whole set lives in a single column.
	 * A network is only added or updated ; it is removed only when the client sends
	 * the property with an empty value.
	 *
	 * @param array			$networks	networks read from the vCard
	 * @param string|false	$table		table to read the current value from (false on create)
	 * @param int			$rowid		record id
	 * @return string
	 */

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
		if (isModEnabled('categorie')  && $this->user->hasRight('categorie', 'lire'))
			if(trim((string) $obj->category_label)!='')
				$categ[] = trim((string) $obj->category_label);

		$soc_address=explode("\n", (string) $obj->soc_address, 2);
		foreach($soc_address as $kAddr => $vAddr)
			$soc_address[$kAddr] = trim(str_replace(array("\r","\t"),' ', str_replace("\n",' | ', trim($vAddr))));
		$soc_address[]='';
		$soc_address[]='';

		$address=explode("\n", (string) $obj->address, 2);
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
		$civility = getDolGlobalInt('CDAV_CONTACT_SYNC_CIVILITY') ? (string) $obj->civility : '';
		$carddata.="N;CHARSET=UTF-8:".str_replace(';','\;',(string) $obj->lastname).";".str_replace(';','\;',(string) $obj->firstname).";;".str_replace(';','\;',$civility).";\n";
		if(!empty($obj->soc_nom) && getDolGlobalInt('CDAV_CONCAT_SOCNAME_FOR_PHONE'))
		{
			$carddata.="FN;CHARSET=UTF-8:".str_replace(';','\;',"(".$obj->soc_nom.") ".$obj->lastname." ".$obj->firstname)."\n";
		}
		else
		{
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
		$carddata.=	 str_replace(';','\;',(string) $obj->town).";;".str_replace(';','\;',(string) $obj->zip).";".str_replace(';','\;',(string) $obj->country_label)."\n";
		$carddata.="ADR;TYPE=WORK;CHARSET=UTF-8:;".str_replace(';','\;',$soc_address[1]).";".str_replace(';','\;',$soc_address[0]).";";
		$carddata.=	 str_replace(';','\;',(string) $obj->soc_town).";;".str_replace(';','\;',(string) $obj->soc_zip).";".str_replace(';','\;',(string) $obj->soc_country_label)."\n";
		$carddata.="TEL;TYPE=WORK,VOICE:".str_replace(';','\;',(string) (trim((string) $obj->phone)==''?$obj->soc_phone:$obj->phone))."\n";
		if(!empty($obj->phone_perso))
			$carddata.="TEL;TYPE=HOME,VOICE:".str_replace(';','\;',$obj->phone_perso)."\n";
		if(!empty($obj->phone_mobile))
			$carddata.="TEL;TYPE=CELL:".str_replace(';','\;',$obj->phone_mobile)."\n";
		if(!empty($obj->soc_fax))
			$carddata.="TEL;TYPE=WORK,FAX:".str_replace(';','\;',$obj->soc_fax)."\n";
		if(!empty($obj->fax))
			$carddata.="TEL;TYPE=HOME,FAX:".str_replace(';','\;',$obj->fax)."\n";
		if(!empty($obj->email))
			$carddata.="EMAIL;PREF=1:".str_replace(';','\;',$obj->email)."\n";
		if(!empty($obj->soc_email) && $obj->soc_email!=$obj->email)
			$carddata.="EMAIL".(empty($obj->email)?";PREF=1":"").":".str_replace(';','\;',$obj->soc_email)."\n";
		if(!empty($obj->soc_url))
		{
			if(strpos($obj->soc_url,'://')===false)
				$carddata.="URL:http://".trim((string) $obj->soc_url)."\n";
			else
				$carddata.="URL:".trim((string) $obj->soc_url)."\n";
		}
		$carddata.=$this->_socialNetworksToVCard($obj);
		if(!empty($obj->birthday))
			$carddata.="BDAY:".str_replace(';','\;',$obj->birthday)."\n";
		if(!empty($obj->note_public))
			$carddata.="NOTE;CHARSET=UTF-8:".str_replace(';','\;',strtr(trim((string) $obj->note_public),array("\n"=>"\\n", "\r"=>"")))."\n";
		if(!empty($obj->photo))
		{
			$photofile = \cdavDocumentRoot($obj, 'societe')."/contact/".(int) $obj->rowid."/photos/".dol_sanitizeFileName($obj->photo);


			for ($cursor = $photofile; $cursor !== dirname($cursor); $cursor = dirname($cursor)) {
				if (is_link($cursor)) throw new Forbidden();
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
		$mapping = $this->cardMappings('ct');
		if (isset($mapping[(int) $obj->rowid])) {
			$uid = str_replace(array("\r", "\n"), '', $mapping[(int) $obj->rowid]['uid']);
			$carddata = preg_replace_callback('/^UID:.*$/m', static function () use ($uid) { return 'UID:'.$uid; }, $carddata);
		}
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
		if (isModEnabled('categorie')  && $this->user->hasRight('categorie', 'lire'))
			if(trim((string) $obj->category_label)!='')
				$categ[] = trim((string) $obj->category_label);

		$soc_address=explode("\n", (string) $obj->soc_address, 2);
		foreach($soc_address as $kAddr => $vAddr)
			$soc_address[$kAddr] = trim(str_replace(array("\r","\t"),' ', str_replace("\n",' | ', trim($vAddr))));
		$soc_address[]='';
		$soc_address[]='';

		$address=explode("\n", (string) $obj->address, 2);
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
		$carddata.="TEL;TYPE=WORK,VOICE:".str_replace(';','\;',(trim((string) $obj->phone)==''?$obj->soc_phone:$obj->phone))."\n";
		if(!empty($obj->phone_perso))
			$carddata.="TEL;TYPE=HOME,VOICE:".str_replace(';','\;',$obj->phone_perso)."\n";
		if(!empty($obj->phone_mobile))
			$carddata.="TEL;TYPE=CELL:".str_replace(';','\;',$obj->phone_mobile)."\n";
		if(!empty($obj->soc_fax))
			$carddata.="TEL;TYPE=WORK,FAX:".str_replace(';','\;',$obj->soc_fax)."\n";
		if(!empty($obj->email))
			$carddata.="EMAIL;PREF=1:".str_replace(';','\;',$obj->email)."\n";
		if(!empty($obj->soc_email) && $obj->soc_email!=$obj->email)
			$carddata.="EMAIL".(empty($obj->email)?";PREF=1":"").":".str_replace(';','\;',$obj->soc_email)."\n";
		if(!empty($obj->soc_url))
		{
			if(strpos($obj->soc_url,'://')===false)
				$carddata.="URL:https://".trim((string) $obj->soc_url)."\n";
			else
				$carddata.="URL:".trim((string) $obj->soc_url)."\n";
		}
		$carddata.=$this->_socialNetworksToVCard($obj);
		if(!empty($obj->birth))
			$carddata.="BDAY;VALUE=DATE:".str_replace(';','\;',date('Ymd',strtotime((string) $obj->birth)))."\n";
		if(!empty($obj->note_public))
			$carddata.="NOTE;CHARSET=UTF-8:".str_replace(';','\;',strtr(trim((string) $obj->note_public),array("\n"=>"\\n", "\r"=>"")))."\n";
		if(!empty($obj->photo))
		{
			$photofile = \cdavDocumentRoot($obj, 'adherent')."/member/".(int) $obj->rowid."/photos/".dol_sanitizeFileName($obj->photo);
			for ($cursor = $photofile; $cursor !== dirname($cursor); $cursor = dirname($cursor)) {
				if (is_link($cursor)) throw new Forbidden();
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
		$mapping = $this->cardMappings('mb');
		if (isset($mapping[(int) $obj->rowid])) {
			$uid = str_replace(array("\r", "\n"), '', $mapping[(int) $obj->rowid]['uid']);
			$carddata = preg_replace_callback('/^UID:.*$/m', static function () use ($uid) { return 'UID:'.$uid; }, $carddata);
		}
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
		if (isModEnabled('categorie')  && $this->user->hasRight('categorie', 'lire'))
			if(trim((string) $obj->category_label)!='')
				$categ[] = trim((string) $obj->category_label);

		$address=explode("\n", (string) $obj->address, 2);
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
			$carddata.="TEL;TYPE=CELL:".str_replace(';','\;',$obj->phone_mobile)."\n";
		if(!empty($obj->fax))
			$carddata.="TEL;TYPE=WORK,FAX:".str_replace(';','\;',$obj->fax)."\n";
		if(!empty($obj->email))
			$carddata.="EMAIL;PREF=1:".str_replace(';','\;',$obj->email)."\n";
		if(!empty($obj->url))
		{
			if(strpos($obj->url,'://')===false)
				$carddata.="URL:https://".trim((string) $obj->url)."\n";
		}
		$carddata.=$this->_socialNetworksToVCard($obj);
		$carddata.="NOTE;CHARSET=UTF-8:";
		foreach($doliinfo as $info)
			$carddata.=strtr(trim($info),array("\n"=>"\\n", "\r"=>""))."\\n";
		if(!empty($obj->note_public))
			$carddata.=strtr(trim((string) $obj->note_public),array("\n"=>"\\n", "\r"=>""))."\\n";
		if(!empty($obj->note_public))
			$carddata.=strtr(trim((string) $obj->note_public),array("\n"=>"\\n", "\r"=>""))."\\n";
		$carddata.="\n";
		$carddata.="REV;TZID=".date_default_timezone_get().":".strtr($obj->lastupd,array(" "=>"T", ":"=>"", "-"=>""))."\n";
		$carddata.="END:VCARD\n";

		$mapping = $this->cardMappings('th');
		if (isset($mapping[(int) $obj->rowid])) {
			$uid = str_replace(array("\r", "\n"), '', $mapping[(int) $obj->rowid]['uid']);
			$carddata = preg_replace_callback('/^UID:.*$/m', static function () use ($uid) { return 'UID:'.$uid; }, $carddata);
		}
		return $carddata;
	}

	/*
	 * parse vcard data to dolibarr table fields
	 * @param cardData : string vcard
	 * @param mode : C=create / U=update
	 */
	protected function _parseDataContact($cardData, $mode) {


		$rdata = [] ;

		$vCard = VObject\Reader::read($cardData);
		$vCard->validate(VObject\Node::REPAIR | VObject\Node::PROFILE_CARDDAV);
		$vCard = $vCard->convert(VObject\Document::VCARD30);


		$rdata['_uid'] = (string)$vCard->UID;
		if(isset($vCard->PHOTO) && strpos(substr($vCard->PHOTO,0,10),'://')===false) // exist and not uri
		{
			$rdata['_photo_bin'] = (string)$vCard->PHOTO;
		}
		else
			$rdata['_photo_bin'] = false;

		$names = isset($vCard->N) ? $vCard->N->getParts() : array();
		$rdata['lastname'] = '';
		if(isset($names[0]) && trim((string)$names[0])!='')
			$rdata['lastname'] = (string)$names[0];
		if($rdata['lastname']=='' && isset($vCard->FN) && trim((string)$vCard->FN)!='')
			$rdata['lastname'] = (string)$vCard->FN;
		if($rdata['lastname']=='' && isset($names[1]) && trim((string)$names[1])!='')
			$rdata['lastname'] = (string)$names[1];
		if($rdata['lastname']=='')
			$rdata['lastname'] = $this->langs->transnoentities('CDavNewContact');

		if(isset($names[1]))
			$rdata['firstname'] = (string)$names[1];

		if(getDolGlobalInt('CDAV_CONTACT_SYNC_CIVILITY') && isset($names[3]))
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
				$types = isset($tel['TYPE']) ? $tel['TYPE'] : array();
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
				$types = isset($adr['TYPE']) ? $adr['TYPE'] : array();
				$adrtype = [];
				foreach($types as $type)
				{
					$adrtype[strtoupper($type)]=true;
				}
				$adrparts = $adr->getParts();
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

		$rdata['_socialnetworks'] = $this->_socialNetworksFromVCard($vCard);

		$bday = '';
		if( isset($vCard->BDAY))
			$bday = trim((string)$vCard->BDAY);
		if( isset($vCard->BDAY) &&
			!empty($bday) &&
			date("Y-m-d", strtotime(trim($bday))) == trim($bday) )
			$rdata['birthday'] = trim($bday);

		if(isset($vCard->NOTE))
			$rdata['note_public'] = trim((string)$vCard->NOTE);

		if(isset($rdata['_country_label']) && $rdata['_country_label']!='')
		{
			$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'c_country
					WHERE label LIKE "'.$this->db->escape($rdata['_country_label']).'"
					AND active = 1';
			$result = $this->db->query($sql);
			if($result!==false && ($row = $this->db->fetch_array($result))!==false)
				$rdata['fk_pays'] = $row['rowid'];
		}


		return $rdata;
	}

	/*
	 * parse vcard data to dolibarr table fields
	 * @param cardData : string vcard
	 * @param mode : C=create / U=update
	 */
	protected function _parseDataMember($cardData, $mode) {


		$rdata = [] ;

		$vCard = VObject\Reader::read($cardData);
		$vCard->validate(VObject\Node::REPAIR | VObject\Node::PROFILE_CARDDAV);
		$vCard = $vCard->convert(VObject\Document::VCARD30);


		$rdata['_uid'] = (string)$vCard->UID;
		if(isset($vCard->PHOTO) && strpos(substr($vCard->PHOTO,0,10),'://')===false) // exist and not uri
		{
			$rdata['_photo_bin'] = (string)$vCard->PHOTO;
		}
		else
			$rdata['_photo_bin'] = false;

		$names = isset($vCard->N) ? $vCard->N->getParts() : array();
		$rdata['lastname'] = '';
		if(isset($names[0]) && trim((string)$names[0])!='')
			$rdata['lastname'] = (string)$names[0];
		if($rdata['lastname']=='' && isset($vCard->FN) && trim((string)$vCard->FN)!='')
			$rdata['lastname'] = (string)$vCard->FN;
		if($rdata['lastname']=='' && isset($names[1]) && trim((string)$names[1])!='')
			$rdata['lastname'] = (string)$names[1];
		if($rdata['lastname']=='')
			$rdata['lastname'] = $this->langs->transnoentities('CDavNewMember');

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
				$types = isset($tel['TYPE']) ? $tel['TYPE'] : array();
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
				$types = isset($adr['TYPE']) ? $adr['TYPE'] : array();
				$adrtype = [];
				foreach($types as $type)
				{
					$adrtype[strtoupper($type)]=true;
				}
				$adrparts = $adr->getParts();
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

		$rdata['_socialnetworks'] = $this->_socialNetworksFromVCard($vCard);

		if(isset($vCard->NOTE))
			$rdata['note_public'] = trim((string)$vCard->NOTE);

		if(isset($rdata['_country_label']) && $rdata['_country_label']!='')
		{
			$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'c_country
					WHERE label LIKE "'.$this->db->escape($rdata['_country_label']).'"
					AND active = 1';
			$result = $this->db->query($sql);
			if($result!==false && ($row = $this->db->fetch_array($result))!==false)
				$rdata['country'] = $row['rowid'];
		}


		return $rdata;
	}


	/*
	 * parse vcard data to dolibarr table fields
	 * @param cardData : string vcard
	 * @param mode : C=create / U=update
	 */
	protected function _parseDataThirdparty($cardData, $mode) {


		$rdata = [] ;

		$vCard = VObject\Reader::read($cardData);
		$vCard->validate(VObject\Node::REPAIR | VObject\Node::PROFILE_CARDDAV);
		$vCard = $vCard->convert(VObject\Document::VCARD30);


		$rdata['_uid'] = (string)$vCard->UID;

		if($mode=='C')
			$rdata['status']=1;

		if(!empty((string)$vCard->FN))
			$rdata['nom'] = (string)$vCard->FN;
		else
		{
			$rdata['nom']='';
			$names = isset($vCard->N) ? $vCard->N->getParts() : array();
			if(!empty($names[0]))
				$rdata['nom'].= (string)$names[0];
			if(!empty($names[1]))
				$rdata['nom'] = trim($rdata['nom']." ".(string)$names[1]);
			if(!empty($names[2]))
				$rdata['nom'] = trim($rdata['nom']." ".(string)$names[2]);
			if(!empty($names[3]))
				$rdata['nom'] = trim((string)$names[3]." ".$rdata['nom']);
			if(empty($rdata['nom']))
				$rdata['nom'] = $this->langs->transnoentities('CDavNewThirdparty');
		}

		if(isset($vCard->{'NICKNAME'}))
			$rdata['name_alias'] = (string)$vCard->{'NICKNAME'};

		if(isset($vCard->TEL))
		{
			foreach($vCard->TEL as $tel)
			{
				$teltype = [];
				$types = isset($tel['TYPE']) ? $tel['TYPE'] : array();
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
				$types = isset($adr['TYPE']) ? $adr['TYPE'] : array();
				$adrtype = [];
				foreach($types as $type)
				{
					$adrtype[strtoupper($type)]=true;
				}
				$adrparts = $adr->getParts();
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

		$rdata['_socialnetworks'] = $this->_socialNetworksFromVCard($vCard);


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
		$this->bookKind($addressbookId);



		$cards = [] ;

		if(intval($addressbookId)<CDAV_ADDRESSBOOK_ID_SHIFT && $this->user->hasRight('societe', 'contact', 'lire'))
		{
			$sql = $this->_getSqlContacts();
			$result = $this->db->query($sql);
			if (!$result) throw new DAV\Exception\ServiceUnavailable($this->langs->transnoentities('CDavContactsUnavailable'));
			if ($result)
			{
				while ($obj = $this->db->fetch_object($result))
				{
					$carddata = $this->_contactToVCard($obj);

					$cards[] = [
						// 'carddata' => $carddata,  not necessary because etag+size are present
						'uri' => $this->cardMappings('ct')[(int) $obj->rowid]['uri'] ?? $obj->rowid.'-ct-'.CDAV_URI_KEY,
						'lastmodified' => strtotime((string) $obj->lastupd),
						'etag' => '"'.md5($carddata).'"',
						'size' => strlen($carddata)
					];
				}
			}
		}

		if(CDAV_THIRD_SYNC>0 && intval($addressbookId)>=CDAV_ADDRESSBOOK_ID_SHIFT && intval($addressbookId)<(2*CDAV_ADDRESSBOOK_ID_SHIFT) && $this->user->hasRight('societe', 'lire'))
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
						'uri' => $this->cardMappings('th')[(int) $obj->rowid]['uri'] ?? $obj->rowid.'-th-'.CDAV_URI_KEY,
						'lastmodified' => strtotime((string) $obj->lastupd),
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
						'uri' => $this->cardMappings('mb')[(int) $obj->rowid]['uri'] ?? $obj->rowid.'-mb-'.CDAV_URI_KEY,
						'lastmodified' => strtotime((string) $obj->lastupd),
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
		$this->bookKind($addressbookId);


		$requestedUri = $cardUri;
		$kind = $this->bookKind($addressbookId);
		$mapping = $this->cardMappings($kind);
		foreach ($mapping as $objectId => $entry) {
			if ($entry['uri'] === $cardUri) $cardUri = $objectId.'-'.$kind.'-'.CDAV_URI_KEY;
		}

		if(intval($addressbookId)<CDAV_ADDRESSBOOK_ID_SHIFT && $this->user->hasRight('societe', 'contact', 'lire'))
		{
			if(preg_match('/^[1-9][0-9]*-ct-'.preg_quote(CDAV_URI_KEY, '/').'$/D', $cardUri))
				$sqlWhere = ' AND p.rowid='.intval($cardUri);							// cardUri starts with contact id
			else
				return false;

			$sql = $this->_getSqlContacts($sqlWhere);

			$result = $this->db->query($sql);
			if (!$result) throw new DAV\Exception\ServiceUnavailable($this->langs->transnoentities('CDavContactsUnavailable'));
			if ($result && $obj = $this->db->fetch_object($result))
			{
				$carddata = $this->_contactToVCard($obj);

				$card = [
					'carddata' => $carddata,
					'uri' => $requestedUri,
					'id' => (int) $obj->rowid,
					'lastmodified' => strtotime((string) $obj->lastupd),
					'etag' => '"'.md5($carddata).'"',
					'size' => strlen($carddata)
				];

				return $card;
			}
		}

		if(CDAV_THIRD_SYNC>0 && intval($addressbookId)>=CDAV_ADDRESSBOOK_ID_SHIFT && intval($addressbookId)<(2*CDAV_ADDRESSBOOK_ID_SHIFT) && $this->user->hasRight('societe', 'lire'))
		{
			if(preg_match('/^[1-9][0-9]*-th-'.preg_quote(CDAV_URI_KEY, '/').'$/D', $cardUri))
				$sqlWhere = ' AND s.rowid='.intval($cardUri);							// cardUri starts with contact id
			else
				return false;

			$sql = $this->_getSqlThirdparties($sqlWhere);
			$result = $this->db->query($sql);
			if ($result && $obj = $this->db->fetch_object($result))
			{
				$carddata = $this->_thirdpartyToVCard($obj);

				$card = [
					'carddata' => $carddata,
					'uri' => $requestedUri,
					'id' => (int) $obj->rowid,
					'lastmodified' => strtotime((string) $obj->lastupd),
					'etag' => '"'.md5($carddata).'"',
					'size' => strlen($carddata)
				];

				return $card;
			}
		}

		if(CDAV_MEMBER_SYNC>0 && intval($addressbookId)>=(2*CDAV_ADDRESSBOOK_ID_SHIFT) && intval($addressbookId)<(3*CDAV_ADDRESSBOOK_ID_SHIFT) && $this->user->hasRight('adherent', 'lire'))
		{
			if(preg_match('/^[1-9][0-9]*-mb-'.preg_quote(CDAV_URI_KEY, '/').'$/D', $cardUri))
				$sqlWhere = ' AND p.rowid='.intval($cardUri);							// cardUri starts with member id
			else
				return false;

			$sql = $this->_getSqlMembers($sqlWhere);
			$result = $this->db->query($sql);
			if ($result && $obj = $this->db->fetch_object($result))
			{
				$carddata = $this->_memberToVCard($obj);

				$card = [
					'carddata' => $carddata,
					'uri' => $requestedUri,
					'id' => (int) $obj->rowid,
					'lastmodified' => strtotime((string) $obj->lastupd),
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
		$cards = array();
		foreach ($uris as $uri) {
			$card = $this->getCard($addressbookId, $uri);
			if ($card) $cards[] = $card;
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
		$kind = $this->bookKind($addressbookId);
		if (($kind === 'ct' && !$this->user->hasRight('societe', 'contact', 'creer'))
			|| ($kind === 'th' && !$this->user->hasRight('societe', 'creer'))
			|| ($kind === 'mb' && !$this->user->hasRight('adherent', 'creer'))) throw new Forbidden();
		return $this->saveCard($kind, $cardUri, $cardData, null);
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
		$kind = $this->bookKind($addressbookId);
		if (($kind === 'ct' && !$this->user->hasRight('societe', 'contact', 'creer'))
			|| ($kind === 'th' && !$this->user->hasRight('societe', 'creer'))
			|| ($kind === 'mb' && !$this->user->hasRight('adherent', 'creer'))) throw new Forbidden();
		$card = $this->getCard($addressbookId, $cardUri);
		if (!$card) throw new DAV\Exception\NotFound();
		return $this->saveCard($kind, $cardUri, $cardData, (int) $card['id']);
	}

	/**
	 * Deletes a card
	 *
	 * @param mixed $addressBookId
	 * @param string $cardUri
	 * @return bool
	 */
	function deleteCard($addressbookId, $cardUri) {
		$kind = $this->bookKind($addressbookId);
		if (($kind === 'ct' && !$this->user->hasRight('societe', 'contact', 'supprimer'))
			|| ($kind === 'th' && !$this->user->hasRight('societe', 'supprimer'))
			|| ($kind === 'mb' && !$this->user->hasRight('adherent', 'supprimer'))) throw new Forbidden();
		$card = $this->getCard($addressbookId, $cardUri);
		if (!$card) throw new DAV\Exception\NotFound();
		// Preserve CDav's soft deletion: archive the native object, never destroy it.
		$object = $this->loadNativeCard($kind, (int) $card['id']);
		$object->oldcopy = clone $object;
		if ($kind === 'th') $object->status = 0;
		else $object->statut = 0;
		$this->db->begin();
		$result = $kind === 'mb' ? $object->update($this->user, 0, 1, 1, 1) : ($kind === 'ct' ? $object->update($object->id, $this->user, 0, 'update', 1) : $object->update($object->id, $this->user));
		if ($result < 0) {
			$this->db->rollback();
			throw new DAV\Exception($this->langs->transnoentities('ErrorRecordNotSaved'));
		}
		$this->db->commit();
		return true;
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
		$this->bookKind($addressbookId);


		// TODO
		return null;
	}

}
