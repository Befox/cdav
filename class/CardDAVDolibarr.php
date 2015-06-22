<?php

namespace Sabre\CardDAV\Backend;

use Sabre\CardDAV;
use Sabre\DAV;

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

		$sql = 'SELECT MAX(GREATEST(p.tms, s.tms)) lastupd FROM '.MAIN_DB_PREFIX.'socpeople as p
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
			'{DAV:}displayname'											  => 'Dolibarr',
			'{' . CardDAV\Plugin::NS_CARDDAV . '}addressbook-description' => 'Contacts Dolibarr '.$this->user->login,
			'{http://calendarserver.org/ns/}getctag'					  => $lastupd,
			'{http://sabredav.org/ns}sync-token'						  => $lastupd,
		];

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
	function updateAddressBook($addressBookId, \Sabre\DAV\PropPatch $propPatch) {
		
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
	function deleteAddressBook($addressBookId) {

		// not supported
		return;

	}
	
	/**
	 * Base sql request for contacts
	 * 
	 * @return string
	 */
	protected function _getSqlContacts()
	{
		$sql = 'SELECT p.*, co.label country_label, GREATEST(s.tms, p.tms) lastupd,
					s.nom soc_nom, s.address soc_address, s.zip soc_zip, s.town soc_town, cos.label soc_country_label, s.phone soc_phone, s.email soc_email,
					s.client soc_client, s.fournisseur soc_fournisseur, s.note_private soc_note_private, s.note_public soc_note_public, cl.label category_label
				FROM '.MAIN_DB_PREFIX.'socpeople as p
				LEFT JOIN '.MAIN_DB_PREFIX.'c_country as co ON co.rowid = p.fk_pays
				LEFT JOIN '.MAIN_DB_PREFIX.'societe as s ON s.rowid = p.fk_soc
				LEFT JOIN '.MAIN_DB_PREFIX.'c_country as cos ON cos.rowid = s.fk_pays
				LEFT JOIN '.MAIN_DB_PREFIX.'categorie_contact as cc ON cc.fk_socpeople = p.rowid 
				LEFT JOIN '.MAIN_DB_PREFIX.'categorie_lang as cl ON (cl.fk_category = cc.fk_categorie AND cl.lang=\''.$this->db->escape($this->langs->defaultlang).'\')
				WHERE p.entity IN ('.getEntity('societe', 1).')
				AND (p.priv=0 OR (p.priv=1 AND p.fk_user_creat='.$this->user->id.'))';
				
		return $sql;
	}

	/**
	 * Convert contact row to VCard string
	 * 
     * @param row object
	 * @return string
	 */
	protected function _toVCard($obj)
	{
        $categ = [];
        if($obj->soc_client)
            $categ[] = $this->langs->transnoentitiesnoconv('Customer');
        if($obj->soc_fournisseur)
            $categ[] = $this->langs->transnoentitiesnoconv('Supplier');
        if(trim($obj->category_label)!='')
            $categ[] = trim($obj->category_label);
        if($obj->priv)
            $categ[] = $this->langs->transnoentitiesnoconv('ContactPrivate');
        else
            $categ[] = $this->langs->transnoentitiesnoconv('ContactPublic');

        $soc_address=explode("\n",$obj->soc_address,2);
        $soc_address[]='';
        $soc_address[]='';
        
        $address=explode("\n",$obj->address,2);
        $address[]='';
        $address[]='';
        
		$carddata ="BEGIN:VCARD\n";
		$carddata.="VERSION:3.0\n";
		$carddata.="PRODID:-//Dolibarr CDav//FR\n";
        
		$carddata.="UID:".$obj->rowid.'-ct-'.CDAV_URI_KEY."\n";
		$carddata.="N;CHARSET=UTF-8:".$obj->lastname.";".$obj->firstname.";;".$obj->civility."\n";
		$carddata.="FN;CHARSET=UTF-8:".$obj->lastname." ".$obj->firstname."\n";
		$carddata.="ORG;CHARSET=UTF-8:".$obj->soc_nom.";\n";
		$carddata.="TITLE;CHARSET=UTF-8:".$obj->poste."\n";
		$carddata.="CATEGORIES;CHARSET=UTF-8:".implode(',',$categ)."\n";
		$carddata.="CLASS:".($obj->priv?'PRIVATE':'PUBLIC')."\n";
		$carddata.="ADR;TYPE=HOME;CHARSET=UTF-8:;".$address[0].";".$address[1].";".$obj->town.";;".$obj->zip.";".$obj->country_label."\n";
		$carddata.="ADR;TYPE=WORK;CHARSET=UTF-8:;".$soc_address[0].";".$soc_address[1].";".$obj->soc_town.";;".$obj->soc_zip.";".$obj->soc_country_label."\n";
		$carddata.="TEL;WORK;VOICE:".$obj->phone."\n";
		$carddata.="TEL;HOME;VOICE:".$obj->phone_perso."\n";
		$carddata.="TEL;CELL;VOICE:".$obj->phone_mobile."\n";
		$carddata.="TEL;FAX:".$obj->fax."\n";
		$carddata.="EMAIL;PREF;INTERNET:".$obj->email."\n";
		$carddata.="EMAIL;INTERNET:".$obj->soc_email."\n";
		$carddata.="X-JABBER:".$obj->jabberid."\n";
		$carddata.="X-SKYPE:".$obj->skype."\n";
        $carddata.="NOTE;CHARSET=UTF-8:".strtr(trim($obj->note_public),array("\n"=>"\\n* ", "\r"=>""))."\n";

   		$carddata.="REV:".strtr($obj->lastupd,array(" "=>"T", ":"=>"", "-"=>""))."Z\n";
		$carddata.="END:VCARD\n";

        return $carddata;
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

		$cards = [] ;
        
		$sql = $this->_getSqlContacts();
		$result = $this->db->query($sql);
		if ($result)
		{
			while ($obj = $this->db->fetch_object($result))
			{
				$carddata = $this->_toVCard($obj);
				
				$cards[] = [
					// 'carddata' => $carddata,  not necessary because etag+size are present
					'uri' => $obj->rowid.'-ct-'.CDAV_URI_KEY,
					'lastmodified' => strtotime($obj->lastupd),
					'etag' => '"'.md5($carddata).'"',
					'size' => strlen($carddata)
				];
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
	function getCard($addressBookId, $cardUri) {

		$sql = $this->_getSqlContacts();
		$sql.= ' AND p.rowid='.($cardUri*1);   // cardUri starts with contact id
		
		$result = $this->db->query($sql);
		if ($result && $obj = $this->db->fetch_object($result))
		{
			$carddata = $this->_toVCard($obj);
			
			$card = [
				'carddata' => $carddata,
				'uri' => $obj->rowid.'-ct-'.CDAV_URI_KEY,
				'lastmodified' => strtotime($obj->lastupd),
				'etag' => '"'.md5($carddata).'"',
				'size' => strlen($carddata)
			];
			
			return $card;
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
	function getMultipleCards($addressBookId, array $uris) {

		$cards = [] ;

        $ids = [];
        foreach($uris as $cardUri)
            $ids[] = ($cardUri*1);   // cardUri starts with contact id
		
		$sql = $this->_getSqlContacts();
        $sql.= ' AND p.rowid IN ('.implode(',', $ids).')';
		
		$result = $this->db->query($sql);
		if ($result)
		{
			while ($obj = $this->db->fetch_object($result))
			{
				$carddata = $this->_toVCard($obj);
				
				$cards[] = [
					'carddata' => $carddata,
					'uri' => $obj->rowid.'-ct-'.CDAV_URI_KEY,
					'lastmodified' => strtotime($obj->lastupd),
					'etag' => '"'.md5($carddata).'"',
					'size' => strlen($carddata)
				];
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
	function createCard($addressBookId, $cardUri, $cardData) {
        
        // TODO
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
	function updateCard($addressBookId, $cardUri, $cardData) {

        // TODO
        return null;
	}

	/**
	 * Deletes a card
	 *
	 * @param mixed $addressBookId
	 * @param string $cardUri
	 * @return bool
	 */
	function deleteCard($addressBookId, $cardUri) {
    
        // TODO disable
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
	function getChangesForAddressBook($addressBookId, $syncToken, $syncLevel, $limit = null) {

        // TODO
        return array();
	}

}
