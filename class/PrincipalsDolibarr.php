<?php

namespace Sabre\DAVACL\PrincipalBackend;

use Sabre\DAV;
use Sabre\DAV\MkCol;
use Sabre\HTTP\URLUtil;

/**
 * PDO principal backend
 *
 *
 * This backend assumes all principals are in a single collection. The default collection
 * is 'principals/', but this can be overriden.
 *
 * @copyright Copyright (C) 2007-2015 fruux GmbH (https://fruux.com/).
 * @author Evert Pot (http://evertpot.com/)
 * @license http://sabre.io/license/ Modified BSD License
 */
class Dolibarr extends AbstractBackend implements CreatePrincipalSupport {

	/**
	 * Dolibarr user object
	 *
	 * @var string
	 */
	public $user;

	/**
	 * Database handle
	 *
	 * @var db
	 */
	protected $db;

	/**
	 * All principals
	 * 
	 * @var allprincipals 
	 */
	protected  $allprincipals;


	/**
	 * A list of additional fields to support
	 *
	 * @var array
	 */
	protected $fieldMap = [

		/**
		 * This property can be used to display the users' real name.
		 */
		'{DAV:}displayname' => [
			'dbField' => 'displayname',
		],

		/**
		 * This is the users' primary email-address.
		 */
		'{http://sabredav.org/ns}email-address' => [
			'dbField' => 'email',
		],
	];

	/**
	 * Sets up the backend.
	 *
	 * @param db $db
	 */
	function __construct($user,$db) {

		$this->user = $user;
		$this->db = $db;
		
		$this->allprincipals = array(
			array('id'=>$user->id*10,  'uri'=>'principals/'.$user->login,'email'=>$user->email,'displayname'=>trim($user->firstname.' '.$user->lastname)),
			array('id'=>$user->id*10+1,'uri'=>'principals/'.$user->login.'/calendar-proxy-read','email'=>null,'displayname'=>null),
			array('id'=>$user->id*10+2,'uri'=>'principals/'.$user->login.'/calendar-proxy-write','email'=>null,'displayname'=>null),
		);


	}

	/**
	 * Returns a list of principals based on a prefix.
	 *
	 * This prefix will often contain something like 'principals'. You are only
	 * expected to return principals that are in this base path.
	 *
	 * You are expected to return at least a 'uri' for every user, you can
	 * return any additional properties if you wish so. Common properties are:
	 *   {DAV:}displayname
	 *   {http://sabredav.org/ns}email-address - This is a custom SabreDAV
	 *	 field that's actualy injected in a number of other properties. If
	 *	 you have an email address, use this property.
	 *
	 * @param string $prefixPath
	 * @return array
	 */
	function getPrincipalsByPrefix($prefixPath) {

		$principals = [];

		foreach ($this->allprincipals as $row) {

			// Checking if the principal is in the prefix
			list($rowPrefix) = URLUtil::splitPath($row['uri']);
			if ($rowPrefix !== $prefixPath) continue;

			$principal = [
				'uri' => $row['uri'],
			];
			foreach ($this->fieldMap as $key => $value) {
				if ($row[$value['dbField']]) {
					$principal[$key] = $row[$value['dbField']];
				}
			}
			$principals[] = $principal;
		}

		return $principals;

	}

	/**
	 * Returns a specific principal, specified by it's path.
	 * The returned structure should be the exact same as from
	 * getPrincipalsByPrefix.
	 *
	 * @param string $path
	 * @return array
	 */
	function getPrincipalByPath($path) {

		foreach ($this->allprincipals as $row) {
			if($row['uri']==$path) {

				$principal = [
					'id'  => $row['id'],
					'uri' => $row['uri'],
				];
				foreach ($this->fieldMap as $key => $value) {
					if ($row[$value['dbField']]) {
						$principal[$key] = $row[$value['dbField']];
					}
				}
				return $principal;
			}
		}
	}

	/**
	 * Updates one ore more webdav properties on a principal.
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
	 * @param string $path
	 * @param DAV\PropPatch $propPatch
	 */
	function updatePrincipal($path, DAV\PropPatch $propPatch) {

		// not supported
		return false;

	}

	/**
	 * This method is used to search for principals matching a set of
	 * properties.
	 *
	 * This search is specifically used by RFC3744's principal-property-search
	 * REPORT.
	 *
	 * The actual search should be a unicode-non-case-sensitive search. The
	 * keys in searchProperties are the WebDAV property names, while the values
	 * are the property values to search on.
	 *
	 * By default, if multiple properties are submitted to this method, the
	 * various properties should be combined with 'AND'. If $test is set to
	 * 'anyof', it should be combined using 'OR'.
	 *
	 * This method should simply return an array with full principal uri's.
	 *
	 * If somebody attempted to search on a property the backend does not
	 * support, you should simply return 0 results.
	 *
	 * You can also just return 0 results if you choose to not support
	 * searching at all, but keep in mind that this may stop certain features
	 * from working.
	 *
	 * @param string $prefixPath
	 * @param array $searchProperties
	 * @param string $test
	 * @return array
	 */
	function searchPrincipals($prefixPath, array $searchProperties, $test = 'allof') {
		// not supported
		return array();
	}

	/**
	 * Returns the list of members for a group-principal
	 *
	 * @param string $principal
	 * @return array
	 */
	function getGroupMemberSet($principal) {
		// not supported
		return array();
	}

	/**
	 * Returns the list of groups a principal is a member of
	 *
	 * @param string $principal
	 * @return array
	 */
	function getGroupMembership($principal) {
		// not supported
		return array();
	}

	/**
	 * Updates the list of group members for a group principal.
	 *
	 * The principals should be passed as a list of uri's.
	 *
	 * @param string $principal
	 * @param array $members
	 * @return void
	 */
	function setGroupMemberSet($principal, array $members) {
		// not supported
		return;
	}

	/**
	 * Creates a new principal.
	 *
	 * This method receives a full path for the new principal. The mkCol object
	 * contains any additional webdav properties specified during the creation
	 * of the principal.
	 *
	 * @param string $path
	 * @param MkCol $mkCol
	 * @return void
	 */
	function createPrincipal($path, MkCol $mkCol) {
		// not supported
		return;
	}

}
