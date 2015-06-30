<?php

namespace Sabre\CalDAV\Backend;

use Sabre\VObject;
use Sabre\CalDAV;
use Sabre\DAV;
use Sabre\DAV\Exception\Forbidden;

/**
 * Dolibarr CalDAV backend
 *
 * This CardDAV backend uses Dolibarr to store calendars events
 *
 * @copyright Copyright (C) 2007-2015 fruux GmbH (https://fruux.com/).
 * @author Evert Pot (http://evertpot.com/)
 * @license http://sabre.io/license/ Modified BSD License
 */
class Dolibarr extends AbstractBackend implements SyncSupport, SubscriptionSupport, SchedulingSupport {

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
    function __construct($user,$db,$langs) {

		$this->user = $user;
		$this->db = $db;
		$this->langs = $langs;
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

        debug_log("getCalendarsForUser( $principalUri )");
        
        $calendars = [];
        
        if(! $this->user->rights->agenda->myactions->read)
            return $calendars;
        
        if(!isset($this->user->rights->agenda->allactions->read) || !$this->user->rights->agenda->allactions->read)
            $onlyme = true;
        else
            $onlyme = false;
                    
        $components = [ 'VTODO', 'VEVENT' ];


		$sql = 'SELECT u.rowid, u.login, u.firstname, u.lastname, u.color, MAX(a.tms) lastupd
                FROM '.MAIN_DB_PREFIX.'actioncomm as a, '.MAIN_DB_PREFIX.'actioncomm_resources as ar
                LEFT OUTER JOIN '.MAIN_DB_PREFIX.'user u ON (u.rowid = ar.fk_element)
				WHERE ar.fk_actioncomm = a.id AND ar.element_type=\'user\'
                AND a.entity IN ('.getEntity('societe', 1).')
                AND a.code IN (SELECT cac.code FROM '.MAIN_DB_PREFIX.'c_actioncomm cac WHERE cac.type<>\'systemauto\')';
        if($onlyme)
            $sql .= ' AND u.rowid='.$this->user->id;
        $sql.= ' GROUP BY u.rowid';
        
		$result = $this->db->query($sql);
		while($row = $this->db->fetch_array($result))
        {
            $lastupd = strtotime($row['lastupd']);

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
                '{http://apple.com/ns/ical/}calendar-color'                          => $row['color'],

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

        debug_log("createCalendar( $principalUri )");

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
        
        debug_log("updateCalendar( $calendarId )");

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
        
        debug_log("deleteCalendar( $calendarId )");

		// not supported
		return;
    }
    
    
    /**
	 * Base sql request for calendar events
	 * 
     * @param int calendar user id
     * @param int actioncomm object id
	 * @return string
	 */
	protected function _getSqlCalEvents($calid, $oid=false)
	{
        // TODO : replace GROUP_CONCAT by 
        $sql = 'SELECT a.tms lastupd, a.*, s.nom soc_nom, sp.firstname, sp.lastname,
                    (SELECT GROUP_CONCAT(u.login) FROM '.MAIN_DB_PREFIX.'actioncomm_resources ar
                    LEFT OUTER JOIN '.MAIN_DB_PREFIX.'user u ON (u.rowid=fk_element) 
                    WHERE ar.element_type=\'user\' AND fk_actioncomm=a.id) other_users
                FROM '.MAIN_DB_PREFIX.'actioncomm as a';
        if (! $this->user->rights->societe->client->voir )
        {
            $sql.=' LEFT OUTER JOIN '.MAIN_DB_PREFIX.'societe_commerciaux as sc ON (a.fk_soc = sc.fk_soc AND sc.fk_user='.$this->user->id.')";
                    LEFT JOIN '.MAIN_DB_PREFIX.'societe as s ON (s.rowid = sc.fk_soc)
                    LEFT JOIN '.MAIN_DB_PREFIX.'socpeople as sp ON (sp.fk_soc = sc.fk_soc AND sp.rowid = a.fk_contact)';
        }
        else
        {
            $sql.=' LEFT JOIN '.MAIN_DB_PREFIX.'societe as s ON (s.rowid = a.fk_soc)
                    LEFT JOIN '.MAIN_DB_PREFIX.'socpeople as sp ON (sp.rowid = a.fk_contact)';
        }
        $sql.=' WHERE a.id IN (SELECT ar.fk_actioncomm FROM '.MAIN_DB_PREFIX.'actioncomm_resources ar WHERE ar.element_type=\'user\' AND ar.fk_element='.intval($calid).')
                AND a.code IN (SELECT cac.code FROM '.MAIN_DB_PREFIX.'c_actioncomm cac WHERE cac.type<>\'systemauto\')
                AND a.entity IN ('.getEntity('societe', 1).')';
        if($oid!==false)
            $sql.=' AND a.id = '.intval($oid);
        
        debug_log("_getSqlCalEvents : $sql ");

		return $sql;
        
	}


	/**
	 * Convert calendar row to VCalendar string
	 * 
     * @param row object
	 * @return string
	 */
	protected function _toVCalendar($calid, $obj)
	{
        debug_log("_toVCalendar $calid , ".$obj->id);
        
        $categ = [];
        /*if($obj->soc_client)
        {
            $nick[] = $obj->soc_code_client;
            $categ[] = $this->langs->transnoentitiesnoconv('Customer');
        }*/

        if($obj->percent==-1 && !empty(trim($obj->datep)))
            $type='VEVENT';
        else
            $type='VTODO';
            
        $timezone = date_default_timezone_get();

		$caldata ="BEGIN:VCALENDAR\n";
		$caldata.="VERSION:2.0\n";
		$caldata.="METHOD:PUBLISH\n";
		$caldata.="PRODID:-//Dolibarr CDav//FR\n";
        $caldata.="BEGIN:".$type."\n";
        $caldata.="CREATED:".gmdate('Ymd\THis', strtotime($obj->datec))."Z\n";
        $caldata.="LAST-MODIFIED:".gmdate('Ymd\THis', strtotime($obj->lastupd))."Z\n";
        $caldata.="DTSTAMP:".gmdate('Ymd\THis', strtotime($obj->lastupd))."Z\n";
        $caldata.="UID:".$obj->id.'-ev-'.$calid.'-cal-'.CDAV_URI_KEY."\n";
        $caldata.="SUMMARY:".$obj->label."\n";
        $caldata.="LOCATION:".$obj->location."\n";
        $caldata.="PRIORITY:".$obj->priority."\n";
        if($obj->fulldayevent)
        {
            $caldata.="DTSTART;VALUE=DATE:".substr(strtr($obj->datep2,array(" "=>"T", ":"=>"", "-"=>"")),0,8)."\n";
            if($type=='VEVENT')
                $caldata.="DTEND;VALUE=DATE:".substr(strtr($obj->datep2,array(" "=>"T", ":"=>"", "-"=>"")),0,8)."\n";
            elseif(!empty(trim($obj->datep2)))
                $caldata.="DUE;VALUE=DATE:".substr(strtr($obj->datep2,array(" "=>"T", ":"=>"", "-"=>"")),0,8)."\n";
        }
        else
        {
            $caldata.="DTSTART;TZID=".$timezone.":".strtr($obj->datep,array(" "=>"T", ":"=>"", "-"=>""))."\n";
            if($type=='VEVENT')
                $caldata.="DTEND;TZID=".$timezone.":".strtr($obj->datep2,array(" "=>"T", ":"=>"", "-"=>""))."\n";
            elseif(!empty(trim($obj->datep2)))
                $caldata.="DUE;TZID=".$timezone.":".strtr($obj->datep2,array(" "=>"T", ":"=>"", "-"=>""))."\n";
        }
        $caldata.="CLASS:PUBLIC\n";
        if($obj->transparency==0)
            $caldata.="TRANSP:TRANSPARENT\n";
        else
            $caldata.="TRANSP:OPAQUE\n";
        
        if($type=='VEVENT')
            $caldata.="STATUS:CONFIRMED\n";
        elseif($obj->percent==0)
            $caldata.="STATUS:NEEDS-ACTION\n";
        elseif($obj->percent==100)
            $caldata.="STATUS:COMPLETED\n";
        else
        {
            $caldata.="STATUS:IN-PROCESS\n";
            $caldata.="PERCENT-COMPLETE:".$obj->percent."\n";
        }
	
    	$caldata.="DESCRIPTION:";
		$caldata.=strtr($obj->note,array("\n"=>"\\n", "\r"=>""));
        if(!empty($obj->soc_nom))
            $caldata.="\n \\n*DOLIBARR-SOC: ".$obj->soc_nom;
        if(!empty($obj->firstname) || !empty($obj->lastname))
            $caldata.="\n \\n*DOLIBARR-CTC: ".trim($obj->firstname.' '.$obj->lastname);
        if(strpos($obj->other_users,',')) // several
            $caldata.="\n \\n*DOLIBARR-USR: ".$obj->other_users;
        $caldata.="\n";
         
        $caldata.="END:".$type."\n";
		$caldata.="END:VCALENDAR\n";

        return $caldata;
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

        debug_log("getCalendarObjects( $calendarId )");
        
		return $this->_getFullCalendarObjects($calendarId, false);
    }
    
    
    protected function _getFullCalendarObjects($calendarId, $bCalendarData) {

        debug_log("_getFullCalendarObjects $calendarId , $bCalendarData ");
        
        $calid = ($calendarId*1);
		$calevents = [] ;

        if(! $this->user->rights->agenda->myactions->read)
            return $calevents;
        
        if($calid!=$this->user->id && (!isset($this->user->rights->agenda->allactions->read) || !$this->user->rights->agenda->allactions->read))
            return $calevents;

		$sql = $this->_getSqlCalEvents($calid);
      
		$result = $this->db->query($sql);
        
		if ($result)
		{
			while ($obj = $this->db->fetch_object($result))
			{
				$calendardata = $this->_toVCalendar($calid, $obj);

				if($bCalendarData)
                {
                    $calevents[] = [
                        'calendardata' => $calendardata,
                        'uri' => $obj->id.'-ev-'.CDAV_URI_KEY,
                        'lastmodified' => strtotime($obj->lastupd),
                        'etag' => '"'.md5($calendardata).'"',
                        'calendarid'   => $calendarId,
                        'size' => strlen($calendardata),
                        'component' => strpos($calendardata, 'BEGIN:VEVENT')>0 ? 'vevent' : 'vtodo',
                    ];
                }
                else
                {
                    $calevents[] = [
                        // 'calendardata' => $calendardata,  not necessary because etag+size are present
                        'uri' => $obj->id.'-ev-'.CDAV_URI_KEY,
                        'lastmodified' => strtotime($obj->lastupd),
                        'etag' => '"'.md5($calendardata).'"',
                        'calendarid'   => $calendarId,
                        'size' => strlen($calendardata),
                        'component' => strpos($calendardata, 'BEGIN:VEVENT')>0 ? 'vevent' : 'vtodo',
                    ];
                }
			}
		}
		return $calevents;
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

        debug_log("getCalendarObject( $calendarId , $objectUri )");

        $calid = ($calendarId*1);
        $oid = ($objectUri*1);
		$calevent = null ;

        if(! $this->user->rights->agenda->myactions->read)
            return $calevent;
        
        if($calid!=$this->user->id && (!isset($this->user->rights->agenda->allactions->read) || !$this->user->rights->agenda->allactions->read))
            return $calevent;

		$sql = $this->_getSqlCalEvents($calid, $oid);
        
		$result = $this->db->query($sql);
        
		if ($result)
		{
			if ($obj = $this->db->fetch_object($result))
			{
				$calendardata = $this->_toVCalendar($calid, $obj);
				
				$calevent = [
					'id' => $oid,
					'uri' => $obj->id.'-ev-'.CDAV_URI_KEY,
					'lastmodified' => strtotime($obj->lastupd),
					'etag' => '"'.md5($calendardata).'"',
                    'calendarid'   => $calendarId,
					'size' => strlen($calendardata),
					'calendardata' => $calendardata,
                    'component' => strpos($calendardata, 'BEGIN:VEVENT')>0 ? 'vevent' : 'vtodo',
				];
			}
		}
        
        debug_log("getCalendarObject return: \n".print_r($calevent,true));
        
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

        debug_log("getMultipleCalendarObjects( $calendarId , ".count($uris)." uris )");
    
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

        debug_log("createCalendarObject( $calendarId , $objectUri )");

		// not supported yet
		return;
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

        debug_log("updateCalendarObject( $calendarId , $objectUri )");

        // not supported
		return;
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

        debug_log("getDenormalizedData( ... )");

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
     * Deletes an existing calendar object.
     *
     * The object uri is only the basename, or filename and not a full path.
     *
     * @param string $calendarId
     * @param string $objectUri
     * @return void
     */
    function deleteCalendarObject($calendarId, $objectUri) {

        debug_log("deleteCalendarObject( $calendarId , $objectUri) ");

        // not supported
		return;
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

        debug_log("calendarQuery($calendarId, ".print_r($filters, true)." ) ");

        $result = [];
        $objects = $this->getCalendarObjects($calendarId);

        foreach ($objects as $object) {

            if ($this->validateFilterForObject($object, $filters)) {
                $result[] = $object['uri'];
            }

        }

        debug_log("calendarQuery return x ".count($result));

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
    
        debug_log("getCalendarObjectByUID( $principalUri , $uid)");

        // "UID:".$obj->id.'-ev-'.$calid.'-cal-'.CDAV_URI_KEY
    
        $oid =  $uid*1;
        $calid = $this->user->id;
        
        $calpos = strpos($uid, '-ev-');
        if($calpos>0)
            $calid = substr($uid,$calpos+1)*1;
            
        return $calid.'-cal-'.$this->user->login . '/' . $oid.'-ev-'.CDAV_URI_KEY;
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

        debug_log("getChangesForCalendar( $calendarId , $syncToken , $syncLevel , $limit )");

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

        debug_log("getSubscriptionsForUser( $principalUri )");

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

debug_log("createSubscription( $$principalUri , $uri )");

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

        debug_log("updateSubscription( $subscriptionId ... )");

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

        debug_log("deleteSubscription( $subscriptionId ... )");

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

        debug_log("getSchedulingObject( $principalUri , $objectUri )");

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

        debug_log("getSchedulingObjects( $principalUri )");
        
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

        debug_log("deleteSchedulingObject( $principalUri , $objectUri )");
        
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

        debug_log("createSchedulingObject( $principalUri , $objectUri)");

        // not supported
        return;
    }

}
