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
 * Author : Evert Pot (http://evertpot.com/)
 * copyright Copyright (C) 2007-2015 fruux GmbH (https://fruux.com/)
 * copyright Copyright (C) 2015 Befox SARL http://www.befox.fr/
 *
 ******************************************************************/

namespace Sabre\CalDAV\Backend;

use Sabre\VObject;
use Sabre\CalDAV;
use Sabre\DAV;
use Sabre\DAV\Exception\Forbidden;

require_once __DIR__.'/CalDAVNativeOperations.php';

class Dolibarr extends AbstractBackend implements SyncSupport, SubscriptionSupport, SchedulingSupport {
	use CalDAVNativeOperations;


	/**
	 * We need to specify a max date, because we need to stop *somewhere*
	 *
	 * On 32 bit system the maximum for a signed integer is 2147483647, so
	 * MAX_DATE cannot be higher than date('Y-m-d', 2147483647) which results
	 * in 2038-01-19 to avoid problems when the date is converted
	 * to a unix timestamp.
	 */
	const MAX_DATE = '2038-01-01';

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
	 * Library Class for reading Dolibarr events
	 *
	 * @var cdavLib
	 * */
	private $cdavLib;

	/**
	 * List of CalDAV properties, and how they map to database fieldnames
	 * Add your own properties by simply adding on to this array.
	 *
	 * Note that only string-based properties are supported here.
	 *
	 * @var array
	 */
	public $propertyMap = [
		'{DAV:}displayname'                                   => 'displayname',
		'{urn:ietf:params:xml:ns:caldav}calendar-description' => 'description',
		'{urn:ietf:params:xml:ns:caldav}calendar-timezone'    => 'timezone',
		'{http://apple.com/ns/ical/}calendar-order'           => 'calendarorder',
		'{http://apple.com/ns/ical/}calendar-color'           => 'calendarcolor',
	];

	/**
	 * List of subscription properties, and how they map to database fieldnames.
	 *
	 * @var array
	 */
	public $subscriptionPropertyMap = [
		'{DAV:}displayname'                                           => 'displayname',
		'{http://apple.com/ns/ical/}refreshrate'                      => 'refreshrate',
		'{http://apple.com/ns/ical/}calendar-order'                   => 'calendarorder',
		'{http://apple.com/ns/ical/}calendar-color'                   => 'calendarcolor',
		'{http://calendarserver.org/ns/}subscribed-strip-todos'       => 'striptodos',
		'{http://calendarserver.org/ns/}subscribed-strip-alarms'      => 'stripalarms',
		'{http://calendarserver.org/ns/}subscribed-strip-attachments' => 'stripattachments',
	];

	/**
	 * Creates the backend
	 *
	 * @param user
	 * @param db
	 * @param langs
	 */
	function __construct($user,$db,$langs, $cdavLib) {

		$this->user = $user;
		$this->db = $db;
		$this->langs = $langs;
		$this->cdavLib = $cdavLib;
		$this->langs->load("users");
		$this->langs->load("companies");
		$this->langs->load("agenda");
		$this->langs->load("commercial");

	}

	/**
	 * Returns a list of calendars for a principal.
	 *
	 * Every project is an array with the following keys:
	 *  * id, a unique id that will be used by other functions to modify the
	 *    calendar. This can be the same as the uri or a database key.
	 *  * uri. This is just the 'base uri' or 'filename' of the calendar.
	 *  * principaluri. The owner of the calendar. Almost always the same as
	 *    principalUri passed to this method.
	 *
	 * Furthermore it can contain webdav properties in clark notation. A very
	 * common one is '{DAV:}displayname'.
	 *
	 * Many clients also require:
	 * {urn:ietf:params:xml:ns:caldav}supported-calendar-component-set
	 * For this property, you can just return an instance of
	 * Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet.
	 *
	 * If you return {http://sabredav.org/ns}read-only and set the value to 1,
	 * ACL will automatically be put in read-only mode.
	 *
	 * @param string $principalUri
	 * @return array
	 */
	function getCalendarsForUser($principalUri) {
		if ($principalUri !== 'principals/'.$this->user->login) return array();


		global $conf;


		$calendars = [];

		if(!\CDavCompatibility::isFeatureAvailable('caldav') || !$this->user->hasRight('agenda', 'myactions', 'read'))
			return $calendars;

		if(!$this->user->hasRight('agenda', 'allactions', 'read'))
			$onlyme = true;
		else
			$onlyme = false;

		$components = [ 'VTODO', 'VEVENT' ];

		$sql = 'SELECT
					u.rowid, u.login, u.firstname, u.lastname, u.color,
					(SELECT MAX(a.tms)
						FROM '.MAIN_DB_PREFIX.'actioncomm as a, '.MAIN_DB_PREFIX.'actioncomm_resources as ar
						WHERE ar.fk_actioncomm = a.id AND ar.element_type="user"
						AND a.entity IN ('.getEntity('agenda', 1).')
						AND a.code IN (SELECT cac.code FROM '.MAIN_DB_PREFIX.'c_actioncomm cac WHERE cac.type<>"systemauto")
						AND ar.fk_element = u.rowid) as lastupd_ev, ';
		if(isModEnabled('project') && (!isset($conf->global->PROJECT_HIDE_TASKS) || !$conf->global->PROJECT_HIDE_TASKS) && intval(CDAV_TASK_SYNC)>0)
		{
			$sql.='(SELECT MAX(pt.tms)
						FROM '.MAIN_DB_PREFIX.'projet_task AS pt
						LEFT JOIN '.MAIN_DB_PREFIX.'element_contact as ec ON (ec.element_id=pt.rowid)
						LEFT JOIN '.MAIN_DB_PREFIX.'c_type_contact as tc ON (tc.rowid=ec.fk_c_type_contact AND tc.element="project_task" AND tc.source="internal")
						WHERE tc.element="project_task" AND tc.source="internal" AND ec.fk_socpeople=u.rowid
						AND pt.entity IN ('.getEntity('project', 1).') ) as lastupd_p, ';
		}
		else
		{
			$sql.='"1970-01-01 00:00:00" as lastupd_p, ';
		}
		if(isModEnabled('ficheinter') && intval(CDAV_INTERV_SYNC)>0)
		{
			$sql.='(SELECT MAX(fi.tms)
						FROM '.MAIN_DB_PREFIX.'fichinter AS fi
						LEFT JOIN '.MAIN_DB_PREFIX.'element_contact as ec ON (ec.element_id=fi.rowid)
						LEFT JOIN '.MAIN_DB_PREFIX.'c_type_contact as tc ON (tc.rowid=ec.fk_c_type_contact AND tc.element="fichinter" AND tc.source="internal")
						WHERE tc.element="fichinter" AND tc.source="internal" AND ec.fk_socpeople=u.rowid
						AND fi.entity IN ('.getEntity('intervention', 1).') ) as lastupd_fi';
		}
		else
		{
			$sql.='"1970-01-01 00:00:00" as lastupd_fi';
		}
		$sql.= ' FROM '.MAIN_DB_PREFIX.'user u WHERE '.\cdavCalendarUserScope();
		if($onlyme)
			$sql .= ' AND u.rowid='.$this->user->id;

		$result = $this->db->query($sql);
		while($result && ($row = $this->db->fetch_array($result)))
		{
			$lastupd = strtotime(max($row['lastupd_ev'],$row['lastupd_p'],$row['lastupd_fi']));

			$calendars[] = [
				'id'                                                                 => $row['rowid'],
				'uri'                                                                => $row['rowid'].'-cal-'.$row['login'],
				'principaluri'                                                       => $principalUri,
				'{' . CalDAV\Plugin::NS_CALENDARSERVER . '}getctag'                  => $lastupd,
				'{http://sabredav.org/ns}sync-token'                                 => $lastupd,
				'{' . CalDAV\Plugin::NS_CALDAV . '}supported-calendar-component-set' => new CalDAV\Xml\Property\SupportedCalendarComponentSet($components),
				'{' . CalDAV\Plugin::NS_CALDAV . '}schedule-calendar-transp'         => new CalDAV\Xml\Property\ScheduleCalendarTransp('opaque'),
				'{DAV:}displayname'                                                  => $row['login'],
				'{urn:ietf:params:xml:ns:caldav}calendar-description'                => trim($row['firstname'].' '.$row['lastname']),
				'{urn:ietf:params:xml:ns:caldav}calendar-timezone'                   => date_default_timezone_get(),
				'{http://apple.com/ns/ical/}calendar-order'                          => $row['rowid']==$this->user->id?0:$row['rowid'],
				'{http://apple.com/ns/ical/}calendar-color'                          => ($row['color']=='')?'':('#'.$row['color']),

				// try unorthodox method :
				'{http://calendarserver.org/ns/}subscribed-strip-todos'              => false,
				'{http://calendarserver.org/ns/}subscribed-strip-alarms'             => $row['rowid']!=$this->user->id,
				'{http://calendarserver.org/ns/}subscribed-strip-attachments'        => $row['rowid']!=$this->user->id,
			];
		}

		return $calendars;
	}

	/**
	 * Creates a new calendar for a principal.
	 *
	 * If the creation was a success, an id must be returned that can be used
	 * to reference this calendar in other methods, such as updateCalendar.
	 *
	 * @param string $principalUri
	 * @param string $calendarUri
	 * @param array $properties
	 * @return string
	 */
	function createCalendar($principalUri, $calendarUri, array $properties) {


		// not supported
		return false;
	}

	/**
	 * Updates properties for a calendar.
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
	 * @param string $calendarId
	 * @param \Sabre\DAV\PropPatch $propPatch
	 * @return void
	 */
	function updateCalendar($calendarId, \Sabre\DAV\PropPatch $propPatch) {




		// not supported
		return;
	}

	/**
	 * Delete a calendar and all it's objects
	 *
	 * @param string $calendarId
	 * @return void
	 */
	function deleteCalendar($calendarId) {


		// not supported
		return;
	}

	/**
	 * Returns all calendar objects within a calendar.
	 *
	 * Every item contains an array with the following keys:
	 *   * calendardata - The iCalendar-compatible calendar data
	 *   * uri - a unique key which will be used to construct the uri. This can
	 *     be any arbitrary string, but making sure it ends with '.ics' is a
	 *     good idea. This is only the basename, or filename, not the full
	 *     path.
	 *   * lastmodified - a timestamp of the last modification time
	 *   * etag - An arbitrary string, surrounded by double-quotes. (e.g.:
	 *   '  "abcdef"')
	 *   * size - The size of the calendar objects, in bytes.
	 *   * component - optional, a string containing the type of object, such
	 *     as 'vevent' or 'vtodo'. If specified, this will be used to populate
	 *     the Content-Type header.
	 *
	 * Note that the etag is optional, but it's highly encouraged to return for
	 * speed reasons.
	 *
	 * The calendardata is also optional. If it's not returned
	 * 'getCalendarObject' will be called later, which *is* expected to return
	 * calendardata.
	 *
	 * If neither etag or size are specified, the calendardata will be
	 * used/fetched to determine these numbers. If both are specified the
	 * amount of times this is needed is reduced by a great degree.
	 *
	 * @param string $calendarId
	 * @return array
	 */
	function getCalendarObjects($calendarId) {


		return $this->cdavLib->getFullCalendarObjects($calendarId, false);
	}

	/**
	 * Returns information from a single calendar object, based on it's object
	 * uri.
	 *
	 * The object uri is only the basename, or filename and not a full path.
	 *
	 * The returned array must have the same keys as getCalendarObjects. The
	 * 'calendardata' object is required here though, while it's not required
	 * for getCalendarObjects.
	 *
	 * This method must return null if the object did not exist.
	 *
	 * @param string $calendarId
	 * @param string $objectUri
	 * @return array|null
	 */
	function getCalendarObject($calendarId, $objectUri) {
		if (!in_array((int) $calendarId, $this->_getCalendarsIdForUser(), true)) return null;



		$calid = intval($calendarId);
		$elem_source = 'ev';
		//objectUri Dolibarr sinon utilisation de $objectUri en tant que ref externe
		if (preg_match('/^[1-9][0-9]*-ev-'.preg_quote(CDAV_URI_KEY, '/').'$/D', $objectUri))
			$oid = intval($objectUri);
		else if (preg_match('/^[1-9][0-9]*-pt-'.preg_quote(CDAV_URI_KEY, '/').'$/D', $objectUri))
		{
			$elem_source = 'pt';
			$oid = intval($objectUri);
		}
		else if (preg_match('/^[1-9][0-9]*-pe-'.preg_quote(CDAV_URI_KEY, '/').'$/D', $objectUri))
		{
			$elem_source = 'pe';
			$oid = intval($objectUri);
		}
		else if (preg_match('/^[1-9][0-9]*-fi-'.preg_quote(CDAV_URI_KEY, '/').'$/D', $objectUri))
		{
			$elem_source = 'fi';
			$oid = intval($objectUri);
		}
		else
			$oid = 0;

		$calevent = null ;

		if(!\CDavCompatibility::isFeatureAvailable('caldav') || !$this->user->hasRight('agenda', 'myactions', 'read'))
			return $calevent;

		if($calid!=$this->user->id && (!$this->user->hasRight('agenda', 'allactions', 'read')))
			return $calevent;

		if($elem_source=='ev') // Calendar Events
			$sql = $this->cdavLib->getSqlCalEvents($calid, $oid, $oid > 0 ? false : $objectUri);
		elseif($elem_source=='fi') // Intervention card
			$sql = $this->cdavLib->getSqlIntervEvents($calid, $oid);
		else // Project Tasks
			$sql = $this->cdavLib->getSqlProjectTasks($calid, $oid, $elem_source);

		if($sql===false || $sql=='')
			return $calevent;

		$result = $this->db->query($sql);

		if ($result)
		{
			if ($this->db->num_rows($result) > 1) throw new \Sabre\DAV\Exception\Conflict('Ambiguous DAV URI');
			if (is_object($obj = $this->db->fetch_object($result)))
			{
				$calendardata = $this->cdavLib->toVCalendar($calid, $obj, true);

				$calevent = [
					'id' => $obj->id,
					'source' => $elem_source,
					'uri' => $objectUri,
					'lastmodified' => strtotime($obj->lastupd),
					'etag' => '"'.md5($calendardata).'"',
					'calendarid'   => $calendarId,
					'size' => strlen($calendardata),
					'calendardata' => $calendardata,
					'component' => strpos($calendardata, 'BEGIN:VEVENT')>0 ? 'vevent' : 'vtodo',
				];
			}
		}


		return $calevent;
	}

	/**
	 * Returns a list of calendar objects.
	 *
	 * This method should work identical to getCalendarObject, but instead
	 * return all the calendar objects in the list as an array.
	 *
	 * If the backend supports this, it may allow for some speed-ups.
	 *
	 * @param mixed $calendarId
	 * @param array $uris
	 * @return array
	 */
	function getMultipleCalendarObjects($calendarId, array $uris) {


		$calevents = [];

		foreach($uris as $uri)
		{
			$calevent = $this->getCalendarObject($calendarId, $uri);
			if($calevent != null)
				$calevents[] = $calevent;
		}

		return $calevents;
	}


	/**
	 * Creates a new calendar object.
	 *
	 * The object uri is only the basename, or filename and not a full path.
	 *
	 * It is possible return an etag from this function, which will be used in
	 * the response to this PUT request. Note that the ETag must be surrounded
	 * by double-quotes.
	 *
	 * However, you should only really return this ETag if you don't mangle the
	 * calendar-data. If the result of a subsequent GET to this object is not
	 * the exact same as this request body, you should omit the ETag.
	 *
	 * @param mixed $calendarId
	 * @param string $objectUri
	 * @param string $calendarData
	 * @return string|null
	 */
	function createCalendarObject($calendarId, $objectUri, $calendarData) {
		if (!in_array((int) $calendarId, $this->_getCalendarsIdForUser(), true)) throw new \Sabre\DAV\Exception\Forbidden();
		$data = $this->_parseData($calendarData);
		$existing = $this->getCalendarObject($calendarId, $objectUri);
		if (!$existing) {
			// A MOVE/COPY can attach an accessible native object to another calendar.
			foreach ($this->_getCalendarsIdForUser() as $sourceCalendar) {
				$existing = $this->getCalendarObject($sourceCalendar, $objectUri);
				if ($existing) break;
			}
		}
		if (!$existing && preg_match('/^[1-9][0-9]*-(ev|pe|pt|fi)-/', $objectUri)) throw new \Sabre\DAV\Exception\NotFound();
		if ($existing) {
			$stored = VObject\Reader::read($existing['calendardata']);
			foreach ($stored->getComponents() as $component) {
				if (isset($component->UID) && (string) $component->UID !== $data['uid']) throw new \Sabre\DAV\Exception\Conflict('UID cannot be changed');
			}
		}
		return $this->persistCalendarObject((int) $calendarId, $objectUri, $data, $existing, true);
	}

	/**
	 * Updates an existing calendarobject, based on it's uri.
	 *
	 * The object uri is only the basename, or filename and not a full path.
	 *
	 * It is possible return an etag from this function, which will be used in
	 * the response to this PUT request. Note that the ETag must be surrounded
	 * by double-quotes.
	 *
	 * However, you should only really return this ETag if you don't mangle the
	 * calendar-data. If the result of a subsequent GET to this object is not
	 * the exact same as this request body, you should omit the ETag.
	 *
	 * @param mixed $calendarId
	 * @param string $objectUri
	 * @param string $calendarData
	 * @return string|null
	 */
	function updateCalendarObject($calendarId, $objectUri, $calendarData) {
		$existing = $this->getCalendarObject($calendarId, $objectUri);
		if (!$existing) throw new \Sabre\DAV\Exception\NotFound();
		$data = $this->_parseData($calendarData);
		// The request URI selects the object. A UID in an untrusted body never selects a row.
		$stored = VObject\Reader::read($existing['calendardata']);
		foreach ($stored->getComponents() as $component) {
			if (isset($component->UID) && (string) $component->UID !== $data['uid']) throw new \Sabre\DAV\Exception\Conflict('UID cannot be changed');
		}
		return $this->persistCalendarObject((int) $calendarId, $objectUri, $data, $existing, false);
	}

	/**
	 * Parses some information from calendar objects, used for optimized
	 * calendar-queries.
	 *
	 * Returns an array with the following keys:
	 *   * etag - An md5 checksum of the object without the quotes.
	 *   * size - Size of the object in bytes
	 *   * componentType - VEVENT, VTODO or VJOURNAL
	 *   * firstOccurence
	 *   * lastOccurence
	 *   * uid - value of the UID property
	 *
	 * @param string $calendarData
	 * @return array
	 */
	protected function getDenormalizedData($calendarData) {


		$vObject = VObject\Reader::read($calendarData);
		$componentType = null;
		$component = null;
		$firstOccurence = null;
		$lastOccurence = null;
		$uid = null;
		foreach ($vObject->getComponents() as $component) {
			if ($component->name !== 'VTIMEZONE') {
				$componentType = $component->name;
				$uid = (string)$component->UID;
				break;
			}
		}
		if (!$componentType) {
			throw new \Sabre\DAV\Exception\BadRequest('Calendar objects must have a VJOURNAL, VEVENT or VTODO component');
		}
		if ($componentType === 'VEVENT') {
			if (!isset($component->DTSTART)) throw new \Sabre\DAV\Exception\BadRequest('DTSTART is required');
			$firstOccurence = $component->DTSTART->getDateTime()->getTimeStamp();
			// Finding the last occurence is a bit harder
			if (!isset($component->RRULE)) {
				if (isset($component->DTEND)) {
					$lastOccurence = $component->DTEND->getDateTime()->getTimeStamp();
				} elseif (isset($component->DURATION)) {
					$endDate = clone $component->DTSTART->getDateTime();
					$endDate->add(VObject\DateTimeParser::parse($component->DURATION->getValue()));
					$lastOccurence = $endDate->getTimeStamp();
				} elseif (!$component->DTSTART->hasTime()) {
					$endDate = clone $component->DTSTART->getDateTime();
					$endDate->modify('+1 day');
					$lastOccurence = $endDate->getTimeStamp();
				} else {
					$lastOccurence = $firstOccurence;
				}
			} else {
				$it = new VObject\Recur\EventIterator($vObject, (string)$component->UID);
				$maxDate = new \DateTime(self::MAX_DATE);
				if ($it->isInfinite()) {
					$lastOccurence = $maxDate->getTimeStamp();
				} else {
					$end = $it->getDtEnd();
					while ($it->valid() && $end < $maxDate) {
						$end = $it->getDtEnd();
						$it->next();

					}
					$lastOccurence = $end->getTimeStamp();
				}

			}
		}

		return [
			'etag'           => md5($calendarData),
			'size'           => strlen($calendarData),
			'componentType'  => $componentType,
			'firstOccurence' => $firstOccurence,
			'lastOccurence'  => $lastOccurence,
			'uid'            => $uid,
		];
	}

	/**
	 * Returns a list of calendars ID for a principal.
	 *
	 * @return array
	 */
	function _getCalendarsIdForUser() {


		$calendars = [];

		if(!\CDavCompatibility::isFeatureAvailable('caldav') || !$this->user->hasRight('agenda', 'myactions', 'read'))
			return $calendars;

		if(!$this->user->hasRight('agenda', 'allactions', 'read'))
			$onlyme = true;
		else
			$onlyme = false;

		$sql = 'SELECT
					u.rowid
				FROM '.MAIN_DB_PREFIX.'user u WHERE '.\cdavCalendarUserScope();
		if($onlyme)
			$sql .= ' AND u.rowid='.$this->user->id;

		$result = $this->db->query($sql);
		while($result && ($row = $this->db->fetch_array($result)))
		{
			$calendars[] = (int) $row['rowid'];
		}

		return $calendars;
	}

	/**
	 * Parses all information from calendar object
	 *
	 * Returns an array with the following keys:
	 *   * etag - An md5 checksum of the object without the quotes.
	 *   * size - Size of the object in bytes
	 *   * componentType - VEVENT, VTODO or VJOURNAL
	 *   * firstOccurence
	 *   * lastOccurence
	 *   * uid - value of the UID property
	 *   * id
	 *   * label
	 *   * start
	 *   * end
	 *   * fullday
	 *   * location
	 *   * priority
	 *   * transparency
	 *   * note
	 *   * percent
	 *   * status
	 * @param string $calendarData
	 * @return array
	 */
	protected function _parseData($calendarData) {


		$vObject = VObject\Reader::read($calendarData);
		$vObject->validate(VObject\Node::REPAIR | VObject\Node::PROFILE_CALDAV);
		$componentType = null;
		$component = null;
		$firstOccurence = null;
		$lastOccurence = null;
		$uid = null;
		$id = null;
		$label = null;
		$start = null;
		$end = null;
		$fullday = null;
		$location = null;
		$priority = null;
		$transparency = null;
		$note = null;
		$percent = null;
		$status = null;
		$elem_source='ev';
		foreach ($vObject->getComponents() as $component) {
			if ($component->name !== 'VTIMEZONE')
			{
				$componentType = $component->name;
				$uid = (string)$component->UID;
				if(strpos($uid, '-ev-')>0)
				{
					$id = intval($uid);
				}
				elseif(strpos($uid, '-pe-')>0)
				{
					$elem_source='pe';
					$id = intval($uid);
				}
				elseif(strpos($uid, '-pt-')>0)
				{
					$elem_source='pt';
					$id = intval($uid);
				}
				elseif(strpos($uid, '-fi-')>0)
				{
					$elem_source='fi';
					$id = intval($uid);
				}
				if (in_array($componentType, array('VEVENT', 'VTODO')))
				{
					$label 			= isset($component->SUMMARY) ? (string)$component->SUMMARY : '';
					if(substr($label,0,1)=='[' && strpos($label, ']')!==false)
						$label = trim(substr($label,strpos($label, ']')+1));
					$fullday		= ! (  isset($component->DTSTART) && $component->DTSTART->hasTime()
										|| isset($component->DTEND) && $component->DTEND->hasTime()
										|| isset($component->DUE) && $component->DUE->hasTime());
					if ($fullday == 1)
					{
						if(isset($component->DTSTART))
							$tmp 	= $component->DTSTART->__toString();
						elseif(isset($component->DTEND))
							$tmp 	= $component->DTEND->__toString();
						elseif(isset($component->DUE))
							$tmp 	= $component->DUE->__toString();
						else
							$tmp	= date('Ymd');
						$start	= mktime(0, 0, 0, substr($tmp, 4, 2), substr($tmp, 6, 2), substr($tmp, 0, 4));

						if(isset($component->DTEND))
							$tmp 	= $component->DTEND->__toString();
						elseif(isset($component->DUE))
							$tmp 	= $component->DUE->__toString();
						$end	= mktime(0, 0, 0, substr($tmp, 4, 2), substr($tmp, 6, 2), substr($tmp, 0, 4));
					}
					else
					{
						if(isset($component->DTSTART))
							$start 	= $component->DTSTART->getDateTime()->getTimeStamp();
						elseif(isset($component->DTEND))
							$start 	= $component->DTEND->getDateTime()->getTimeStamp();
						elseif(isset($component->DUE))
							$start 	= $component->DUE->getDateTime()->getTimeStamp();
						else
							$start  = time();

						if(isset($component->DTEND))
							$end 	= $component->DTEND->getDateTime()->getTimeStamp();
						elseif(isset($component->DUE))
							$end 	= $component->DUE->getDateTime()->getTimeStamp();
						else
							$end 	= $start+60*60;//date de fin = date dÃ©but +1H par dÃ©faut
					}
					$location 		= isset($component->LOCATION) ? trim(str_replace(array("\r","\t","\n"),' ',(string)$component->LOCATION)) : '';
					$priority 		= isset($component->PRIORITY) ? (string)$component->PRIORITY : '5';
					$transparency 	= isset($component->TRANSP) ? (string)$component->TRANSP : 'OPAQUE';
					if ($transparency == 'OPAQUE')
						$transparency = 0;
					else
						$transparency = 1;
					//TODO clear note special comment ðŸ’¼
					$tmp 			= isset($component->DESCRIPTION) ? (string)$component->DESCRIPTION : '';
					$arrNote = array();
					$arrTmp = explode("\n", $tmp);
					foreach($arrTmp as $line)
					{
						if (mb_strpos($line, 'ðŸ’¼', 0, 'UTF-8')!==0
							&& mb_strpos($line, '??', 0, 'UTF-8')!==0				// ðŸ’¼ could be converted in ?? if utf8 char is truncated on 2 VCal lines
							&& mb_strpos($line, '*DOLIBARR-', 0, 'UTF-8')===false)
						{
							$noteline = preg_replace('/[\x{10000}-\x{10FFFF}]/u', "\xEF\xBF\xBD",$line); // remove utf8mb4 chars
							$noteline = strtr($noteline, array("- ["=>"[", "[x] [ ]"=>"[x]", "[ ] [x]"=>"[x]", "[x] [x]"=>"[x]", "[ ] [ ]"=>"[ ]",));
							$arrNote[] = $noteline;
						}
					}
					$note = trim(implode("\n", $arrNote));
					$percent = -1;
					if ($componentType == 'VTODO')
					{
						$status = isset($component->STATUS) ? (string)$component->STATUS : '';
						if ($status == 'COMPLETED')
							$percent = 100;
						else
							$percent = isset($component->{'PERCENT-COMPLETE'}) ? $component->{'PERCENT-COMPLETE'}->getValue() : 0;
					}
				}
				break;
			}
		}
		if (!$componentType) {
			throw new \Sabre\DAV\Exception\BadRequest('Calendar objects must have a VJOURNAL, VEVENT or VTODO component');
		}
		/* Gestion de la rÃ©currence */
		$occurences=array();
		if ($componentType === 'VEVENT') {
			if (!isset($component->DTSTART)) throw new \Sabre\DAV\Exception\BadRequest('DTSTART is required');
			$firstOccurence = $component->DTSTART->getDateTime()->getTimeStamp();
			// Finding the last occurence is a bit harder
			if (!isset($component->RRULE)) {
				if (isset($component->DTEND)) {
					$lastOccurence = $component->DTEND->getDateTime()->getTimeStamp();
				} elseif (isset($component->DURATION)) {
					$endDate = clone $component->DTSTART->getDateTime();
					$endDate->add(VObject\DateTimeParser::parse($component->DURATION->getValue()));
					$lastOccurence = $endDate->getTimeStamp();
				} elseif (!$component->DTSTART->hasTime()) {
					$endDate = clone $component->DTSTART->getDateTime();
					$endDate->modify('+1 day');
					$lastOccurence = $endDate->getTimeStamp();
				} else {
					$lastOccurence = $firstOccurence;
				}
				$occur = new \stdClass();
				$occur->start = $firstOccurence;
				$occur->end = $lastOccurence;
				$occurences[] = $occur;
			} else {
				$it = new VObject\Recur\EventIterator($vObject, (string)$component->UID);
				$maxts = time()+86400*CDAV_SYNC_FUTURE;
				$lastOccurence = $firstOccurence;
				while ($it->valid() && $lastOccurence < $maxts) {
					$itDtStart = $it->getDtStart();
					$itDtEnd = $it->getDtEnd();
					$occur = new \stdClass();
					$occur->start = $itDtStart->getTimeStamp();
					$occur->end = $itDtEnd->getTimeStamp();
					$occurences[] = $occur;
					if (count($occurences) > 1000) throw new \Sabre\DAV\Exception\BadRequest('Too many occurrences');
					$lastOccurence = $itDtEnd->getTimeStamp();
					$it->next();
				}
			}
		}



		$ret = array(
			'etag'           	=> md5($calendarData),
			'size'           	=> strlen($calendarData),
			'componentType'  	=> $componentType,
			'firstOccurence' 	=> $firstOccurence,
			'lastOccurence'  	=> $lastOccurence,
			'occurences'		=> $occurences,
			'uid'            	=> $uid,
			'id'            	=> $id,
			'label' 			=> $label,
			'start' 			=> $start,
			'end' 				=> $end,
			'fullday' 			=> $fullday,
			'location' 			=> $location,
			'priority' 			=> $priority,
			'transparency' 		=> $transparency,
			'note' 				=> $note,
			'percent' 			=> $percent,
			'status'			=> $status,
			'elem_source'		=> $elem_source,
		);
		return $ret;
	}

	/**
	 * Deletes an existing calendar object.
	 *
	 * The object uri is only the basename, or filename and not a full path.
	 *
	 * @param string $calendarId
	 * @param string $objectUri
	 * @return void
	 */
	function deleteCalendarObject($calendarId, $objectUri) {
		$existing = $this->getCalendarObject($calendarId, $objectUri);
		if (!$existing) throw new \Sabre\DAV\Exception\NotFound();
		list($source, $object, $line) = $this->loadCalendarTarget($existing);
		if ($source === 'ev' && !$this->user->hasRight('agenda', (int) $object->userownerid === (int) $this->user->id ? 'myactions' : 'allactions', 'delete')) throw new \Sabre\DAV\Exception\Forbidden();
		if (($source === 'pe' || $source === 'pt') && !$this->user->hasRight('projet', 'creer')) throw new \Sabre\DAV\Exception\Forbidden();
		if ($source === 'fi' && !$this->user->hasRight('ficheinter', 'creer')) throw new \Sabre\DAV\Exception\Forbidden();
		$this->db->begin();
		try {
			$object->oldcopy = clone $object;
			if ($source === 'ev') {
				unset($object->userassigned[(int) $calendarId]);
				if ((int) $object->userownerid === (int) $calendarId) $object->userownerid = $object->userassigned ? (int) array_key_first($object->userassigned) : 0;
			} else {
				$contacts = $object->liste_contact(-1, 'internal');
				if (!is_array($contacts)) throw new \Sabre\DAV\Exception('Contact lookup failed');
				foreach ($contacts as $contact) {
					if ((int) $contact['id'] === (int) $calendarId && $object->delete_contact((int) $contact['rowid']) < 0) throw new \Sabre\DAV\Exception('Contact removal failed');
				}
			}
			// CDav DELETE removes the calendar assignment and retains the business object.
			if ($object->update($this->user) < 0) throw new \Sabre\DAV\Exception('Calendar update failed');
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollback(); throw $e;
		}
	}

	/**
	 * Performs a calendar-query on the contents of this calendar.
	 *
	 * The calendar-query is defined in RFC4791 : CalDAV. Using the
	 * calendar-query it is possible for a client to request a specific set of
	 * object, based on contents of iCalendar properties, date-ranges and
	 * iCalendar component types (VTODO, VEVENT).
	 *
	 * This method should just return a list of (relative) urls that match this
	 * query.
	 *
	 * The list of filters are specified as an array. The exact array is
	 * documented by \Sabre\CalDAV\CalendarQueryParser.
	 *
	 * Note that it is extremely likely that getCalendarObject for every path
	 * returned from this method will be called almost immediately after. You
	 * may want to anticipate this to speed up these requests.
	 *
	 * This method provides a default implementation, which parses *all* the
	 * iCalendar objects in the specified calendar.
	 *
	 * This default may well be good enough for personal use, and calendars
	 * that aren't very large. But if you anticipate high usage, big calendars
	 * or high loads, you are strongly adviced to optimize certain paths.
	 *
	 * The best way to do so is override this method and to optimize
	 * specifically for 'common filters'.
	 *
	 * Requests that are extremely common are:
	 *   * requests for just VEVENTS
	 *   * requests for just VTODO
	 *   * requests with a time-range-filter on a VEVENT.
	 *
	 * ..and combinations of these requests. It may not be worth it to try to
	 * handle every possible situation and just rely on the (relatively
	 * easy to use) CalendarQueryValidator to handle the rest.
	 *
	 * Note that especially time-range-filters may be difficult to parse. A
	 * time-range filter specified on a VEVENT must for instance also handle
	 * recurrence rules correctly.
	 * A good example of how to interprete all these filters can also simply
	 * be found in \Sabre\CalDAV\CalendarQueryFilter. This class is as correct
	 * as possible, so it gives you a good idea on what type of stuff you need
	 * to think of.
	 *
	 * This specific implementation (for the PDO) backend optimizes filters on
	 * specific components, and VEVENT time-ranges.
	 *
	 * @param string $calendarId
	 * @param array $filters
	 * @return array
	 */
	function calendarQuery($calendarId, array $filters) {


		$result = [];
		$objects = $this->getCalendarObjects($calendarId);

		foreach ($objects as $object) {

			if ($this->validateFilterForObject($object, $filters)) {
				$result[] = $object['uri'];
			}

		}


		return $result;
	}

	/**
	 * Searches through all of a users calendars and calendar objects to find
	 * an object with a specific UID.
	 *
	 * This method should return the path to this object, relative to the
	 * calendar home, so this path usually only contains two parts:
	 *
	 * calendarpath/objectpath.ics
	 *
	 * If the uid is not found, return null.
	 *
	 * This method should only consider * objects that the principal owns, so
	 * any calendars owned by other principals that also appear in this
	 * collection should be ignored.
	 *
	 * @param string $principalUri
	 * @param string $uid
	 * @return string|null
	 */
	function getCalendarObjectByUID($principalUri, $uid) {
		foreach ($this->getCalendarsForUser($principalUri) as $calendar) {
			$object = $this->getCalendarObject($calendar['id'], $uid);
			if ($object) return $calendar['uri'].'/'.$object['uri'];
		}
		return null;
	}

	/**
	 * The getChanges method returns all the changes that have happened, since
	 * the specified syncToken in the specified calendar.
	 *
	 * This function should return an array, such as the following:
	 *
	 * [
	 *   'syncToken' => 'The current synctoken',
	 *   'added'   => [
	 *      'new.txt',
	 *   ],
	 *   'modified'   => [
	 *      'modified.txt',
	 *   ],
	 *   'deleted' => [
	 *      'foo.php.bak',
	 *      'old.txt'
	 *   ]
	 * ];
	 *
	 * The returned syncToken property should reflect the *current* syncToken
	 * of the calendar, as reported in the {http://sabredav.org/ns}sync-token
	 * property this is needed here too, to ensure the operation is atomic.
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
	 * @param string $calendarId
	 * @param string $syncToken
	 * @param int $syncLevel
	 * @param int $limit
	 * @return array
	 */
	function getChangesForCalendar($calendarId, $syncToken, $syncLevel, $limit = null) {


		// not supported
		return null;
	}


	/**
	 * Returns a list of subscriptions for a principal.
	 *
	 * Every subscription is an array with the following keys:
	 *  * id, a unique id that will be used by other functions to modify the
	 *    subscription. This can be the same as the uri or a database key.
	 *  * uri. This is just the 'base uri' or 'filename' of the subscription.
	 *  * principaluri. The owner of the subscription. Almost always the same as
	 *    principalUri passed to this method.
	 *  * source. Url to the actual feed
	 *
	 * Furthermore, all the subscription info must be returned too:
	 *
	 * 1. {DAV:}displayname
	 * 2. {http://apple.com/ns/ical/}refreshrate
	 * 3. {http://calendarserver.org/ns/}subscribed-strip-todos (omit if todos
	 *    should not be stripped).
	 * 4. {http://calendarserver.org/ns/}subscribed-strip-alarms (omit if alarms
	 *    should not be stripped).
	 * 5. {http://calendarserver.org/ns/}subscribed-strip-attachments (omit if
	 *    attachments should not be stripped).
	 * 7. {http://apple.com/ns/ical/}calendar-color
	 * 8. {http://apple.com/ns/ical/}calendar-order
	 * 9. {urn:ietf:params:xml:ns:caldav}supported-calendar-component-set
	 *    (should just be an instance of
	 *    Sabre\CalDAV\Property\SupportedCalendarComponentSet, with a bunch of
	 *    default components).
	 *
	 * @param string $principalUri
	 * @return array
	 */
	function getSubscriptionsForUser($principalUri) {


		// Not supported
		return [];
	}

	/**
	 * Creates a new subscription for a principal.
	 *
	 * If the creation was a success, an id must be returned that can be used to reference
	 * this subscription in other methods, such as updateSubscription.
	 *
	 * @param string $principalUri
	 * @param string $uri
	 * @param array $properties
	 * @return mixed
	 */
	function createSubscription($principalUri, $uri, array $properties) {


		// Not supported
		return null;

		/*
		$fieldNames = [
			'principaluri',
			'uri',
			'source',
			'lastmodified',
		];

		if (!isset($properties['{http://calendarserver.org/ns/}source'])) {
			throw new Forbidden('The {http://calendarserver.org/ns/}source property is required when creating subscriptions');
		}

		$values = [
			':principaluri' => $principalUri,
			':uri'          => $uri,
			':source'       => $properties['{http://calendarserver.org/ns/}source']->getHref(),
			':lastmodified' => time(),
		];

		foreach ($this->subscriptionPropertyMap as $xmlName => $dbName) {
			if (isset($properties[$xmlName])) {

				$values[':' . $dbName] = $properties[$xmlName];
				$fieldNames[] = $dbName;
			}
		}

		$stmt = $this->pdo->prepare("INSERT INTO " . $this->calendarSubscriptionsTableName . " (" . implode(', ', $fieldNames) . ") VALUES (" . implode(', ', array_keys($values)) . ")");
		$stmt->execute($values);

		return $this->pdo->lastInsertId();
		*/
	}

	/**
	 * Updates a subscription
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
	 * @param mixed $subscriptionId
	 * @param \Sabre\DAV\PropPatch $propPatch
	 * @return void
	 */
	function updateSubscription($subscriptionId, DAV\PropPatch $propPatch) {


		// not supported
		return;
	}

	/**
	 * Deletes a subscription
	 *
	 * @param mixed $subscriptionId
	 * @return void
	 */
	function deleteSubscription($subscriptionId) {


		// not supported
		return;
	}

	/**
	 * Returns a single scheduling object.
	 *
	 * The returned array should contain the following elements:
	 *   * uri - A unique basename for the object. This will be used to
	 *           construct a full uri.
	 *   * calendardata - The iCalendar object
	 *   * lastmodified - The last modification date. Can be an int for a unix
	 *                    timestamp, or a PHP DateTime object.
	 *   * etag - A unique token that must change if the object changed.
	 *   * size - The size of the object, in bytes.
	 *
	 * @param string $principalUri
	 * @param string $objectUri
	 * @return array
	 */
	function getSchedulingObject($principalUri, $objectUri) {


		// not supported
		return null;
	}

	/**
	 * Returns all scheduling objects for the inbox collection.
	 *
	 * These objects should be returned as an array. Every item in the array
	 * should follow the same structure as returned from getSchedulingObject.
	 *
	 * The main difference is that 'calendardata' is optional.
	 *
	 * @param string $principalUri
	 * @return array
	 */
	function getSchedulingObjects($principalUri) {


		// not supported
		return [];
	}

	/**
	 * Deletes a scheduling object
	 *
	 * @param string $principalUri
	 * @param string $objectUri
	 * @return void
	 */
	function deleteSchedulingObject($principalUri, $objectUri) {


		// not supported
		return;
	}

	/**
	 * Creates a new scheduling object. This should land in a users' inbox.
	 *
	 * @param string $principalUri
	 * @param string $objectUri
	 * @param string $objectData
	 * @return void
	 */
	function createSchedulingObject($principalUri, $objectUri, $objectData) {


		// not supported
		return;
	}

}
