<?php

/**
 * Define Common function to access calendar items
 * And format it in vCalendar
 * */


class CdavLib
{
	
	private $db;
	
	private $user;
	
	private $langs;
	
	function __construct($user, $db, $langs)
	{
		$this->user 	= $user;
		$this->db 		= $db;
		$this->langs 	= $langs;
	}
	
	/**
	 * Base sql request for calendar events
	 * 
     * @param int calendar user id
     * @param int actioncomm object id
	 * @return string
	 */
	public function getSqlCalEvents($calid, $oid=false, $ouri=false)
	{
        // TODO : replace GROUP_CONCAT by 
        $sql = 'SELECT 
					a.tms AS lastupd, 
					a.*, 
					s.nom AS soc_nom, 
					sp.firstname, 
					sp.lastname,
                    (SELECT GROUP_CONCAT(u.login) FROM '.MAIN_DB_PREFIX.'actioncomm_resources ar
						LEFT OUTER JOIN '.MAIN_DB_PREFIX.'user AS u ON (u.rowid=fk_element) 
						WHERE ar.element_type=\'user\' AND fk_actioncomm=a.id) AS other_users
                FROM '.MAIN_DB_PREFIX.'actioncomm AS a';
        if (! $this->user->rights->societe->client->voir )//FIXME si 'voir' on voit plus de chose ?
        {
            $sql.=' LEFT OUTER JOIN '.MAIN_DB_PREFIX.'societe_commerciaux AS sc ON (a.fk_soc = sc.fk_soc AND sc.fk_user='.$this->user->id.')
                    LEFT JOIN '.MAIN_DB_PREFIX.'societe AS s ON (s.rowid = sc.fk_soc)
                    LEFT JOIN '.MAIN_DB_PREFIX.'socpeople AS sp ON (sp.fk_soc = sc.fk_soc AND sp.rowid = a.fk_contact)
                    LEFT JOIN '.MAIN_DB_PREFIX.'actioncomm_cdav AS ac ON (a.id = ac.fk_object)';
        }
        else
        {
            $sql.=' LEFT JOIN '.MAIN_DB_PREFIX.'societe AS s ON (s.rowid = a.fk_soc)
                    LEFT JOIN '.MAIN_DB_PREFIX.'socpeople AS sp ON (sp.rowid = a.fk_contact)
                    LEFT JOIN '.MAIN_DB_PREFIX.'actioncomm_cdav AS ac ON (a.id = ac.fk_object)';
        }
        $sql.=' WHERE 	a.id IN (SELECT ar.fk_actioncomm FROM '.MAIN_DB_PREFIX.'actioncomm_resources ar WHERE ar.element_type=\'user\' AND ar.fk_element='.intval($calid).')
						AND a.code IN (SELECT cac.code FROM '.MAIN_DB_PREFIX.'c_actioncomm cac WHERE cac.type<>\'systemauto\')
						AND a.entity IN ('.getEntity('societe', 1).')';
        if($oid!==false) {
			if($ouri===false) 
			{
				$sql.=' AND a.id = '.intval($oid);
			}
			else
			{
				$sql.=' AND (a.id = '.intval($oid).' OR ac.uuidext = \''.$this->db->escape($ouri).'\')';
			}
		}
        
		return $sql;
        
	}
	
	/**
	 * Convert calendar row to VCalendar string
	 * 
     * @param row object
	 * @return string
	 */
	public function toVCalendar($calid, $obj)
	{
       
        $categ = [];
        /*if($obj->soc_client)
        {
            $nick[] = $obj->soc_code_client;
            $categ[] = $this->langs->transnoentitiesnoconv('Customer');
        }*/

        if($obj->percent==-1 && trim($obj->datep)!='')
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
            $caldata.="DTSTART;VALUE=DATE:".date('Ymd', strtotime($obj->datep))."\n";
            if($type=='VEVENT')
                $caldata.="DTEND;VALUE=DATE:".date('Ymd', strtotime($obj->datep2)+1)."\n";
            elseif(trim($obj->datep2)!='')
                $caldata.="DUE;VALUE=DATE:".date('Ymd', strtotime($obj->datep2)+1)."\n";
        }
        else
        {
            $caldata.="DTSTART;TZID=".$timezone.":".strtr($obj->datep,array(" "=>"T", ":"=>"", "-"=>""))."\n";
            if($type=='VEVENT')
                $caldata.="DTEND;TZID=".$timezone.":".strtr($obj->datep2,array(" "=>"T", ":"=>"", "-"=>""))."\n";
            elseif(trim($obj->datep2)!='')
                $caldata.="DUE;TZID=".$timezone.":".strtr($obj->datep2,array(" "=>"T", ":"=>"", "-"=>""))."\n";
        }
        $caldata.="CLASS:PUBLIC\n";
        if($obj->transparency==1)
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
		$caldata.=strtr($obj->note, array("\n"=>"\\n", "\r"=>""));
        if(!empty($obj->soc_nom))
            $caldata.="\\n*DOLIBARR-SOC: ".$obj->soc_nom;
        if(!empty($obj->firstname) || !empty($obj->lastname))
            $caldata.="\\n*DOLIBARR-CTC: ".trim($obj->firstname.' '.$obj->lastname);
        if(strpos($obj->other_users,',')) // several
            $caldata.="\\n*DOLIBARR-USR: ".$obj->other_users;
        $caldata.="\n";
         
        $caldata.="END:".$type."\n";
		$caldata.="END:VCALENDAR\n";

        return $caldata;
	}
	
	public function getFullCalendarObjects($calendarId, $bCalendarData) 
	{
 
        $calid = ($calendarId*1);
		$calevents = [] ;

        if(! $this->user->rights->agenda->myactions->read)
            return $calevents;
        
        if($calid!=$this->user->id && (!isset($this->user->rights->agenda->allactions->read) || !$this->user->rights->agenda->allactions->read))
            return $calevents;

		$sql = $this->getSqlCalEvents($calid);
      
		$result = $this->db->query($sql);
        
		if ($result)
		{
			while ($obj = $this->db->fetch_object($result))
			{
				$calendardata = $this->toVCalendar($calid, $obj);

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
    
}
