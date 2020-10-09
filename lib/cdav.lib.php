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
					"ev" elem_source,
					a.tms AS lastupd, 
					a.*, 
					sp.firstname, 
					sp.lastname,
					sp.address,
					sp.zip,
					sp.town,
					co.label country_label,
					sp.phone,
					sp.phone_perso,
					sp.phone_mobile,
					s.nom AS soc_nom,
					s.address soc_address,
					s.zip soc_zip,
					s.town soc_town,
					cos.label soc_country_label,
					s.phone soc_phone,
					p.ref proj_ref,
					p.title proj_title,
					p.description proj_desc,
					ac.sourceuid,
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

		$sql.=' LEFT JOIN '.MAIN_DB_PREFIX.'projet AS p ON (p.rowid = a.fk_project)
				LEFT JOIN '.MAIN_DB_PREFIX.'c_country as co ON co.rowid = sp.fk_pays
				LEFT JOIN '.MAIN_DB_PREFIX.'c_country as cos ON cos.rowid = s.fk_pays
				WHERE 	a.id IN (SELECT ar.fk_actioncomm FROM '.MAIN_DB_PREFIX.'actioncomm_resources ar WHERE ar.element_type=\'user\' AND ar.fk_element='.intval($calid).')
						AND a.code IN (SELECT cac.code FROM '.MAIN_DB_PREFIX.'c_actioncomm cac WHERE cac.type<>\'systemauto\')
						AND a.entity IN ('.getEntity('societe', 1).')';
		if($oid!==false) {
			if($ouri===false) 
			{
				$sql.=' AND a.id = '.intval($oid);
			}
			else
			{
				$sql.=' AND (a.id = '.intval($oid).' OR ac.uuidext = \''.$this->db->escape($ouri).'\' OR ac.sourceuid = \''.$this->db->escape($ouri).'\')';
			}
		}
		else
		{
			$sql.='	AND COALESCE(a.datep2,a.datep)>="'.date('Y-m-d 00:00:00',time()-86400*CDAV_SYNC_PAST).'" 
					AND a.datep<="'.date('Y-m-d 23:59:59',time()+86400*CDAV_SYNC_FUTURE).'"';
		}
		
		return $sql;
		
	}
	/**
	 * Base sql request for project tasks
	 * 
	 * @param int calendar user id
	 * @param int task object id
	 * @param string elem_source 'pt'=Project TODO  'pe'=Project EVENT
	 * @return string
	 */
	public function getSqlProjectTasks($calid, $oid=false, $elem_source)
	{
		global $conf;
		
		if(!$conf->projet->enabled || (isset($conf->global->PROJECT_HIDE_TASKS) && $conf->global->PROJECT_HIDE_TASKS))
			return false;
		
		if(intval(CDAV_TASK_SYNC)==0 || (intval(CDAV_TASK_SYNC)==1 && $elem_source=='pt'))
			return false;
		
		if(intval(CDAV_TASK_SYNC)==0 || (intval(CDAV_TASK_SYNC)==2 && $elem_source=='pe'))
			return false;
		
		// TODO : replace GROUP_CONCAT by 
		$sql = 'SELECT 
					"'.$elem_source.'" elem_source,
					pt.rowid AS id, 
					pt.tms AS lastupd, 
					pt.*, 
					p.ref proj_ref,
					p.title proj_title,
					p.description proj_desc,
					s.nom AS soc_nom,
					s.address soc_address,
					s.zip soc_zip,
					s.town soc_town,
					cos.label soc_country_label,
					s.phone soc_phone,
					(SELECT GROUP_CONCAT(u.login) FROM '.MAIN_DB_PREFIX.'element_contact gec
						LEFT JOIN '.MAIN_DB_PREFIX.'c_type_contact as gtc ON (gtc.rowid=gec.fk_c_type_contact AND gtc.element="project_task" AND gtc.source="internal")
						LEFT OUTER JOIN '.MAIN_DB_PREFIX.'user AS u ON (u.rowid=gec.fk_socpeople) 
						WHERE gec.element_id=pt.rowid AND gtc.element="project_task" AND u.login IS NOT NULL) AS other_users,
					(SELECT GROUP_CONCAT(sp.firstname, " ", sp.lastname) FROM '.MAIN_DB_PREFIX.'element_contact gec
						LEFT JOIN '.MAIN_DB_PREFIX.'c_type_contact as gtc ON (gtc.rowid=gec.fk_c_type_contact AND gtc.element="project_task" AND gtc.source="external")
						LEFT JOIN '.MAIN_DB_PREFIX.'socpeople AS sp ON (sp.rowid=gec.fk_socpeople) 
						WHERE gec.element_id=pt.rowid AND gtc.element="project_task" AND sp.lastname IS NOT NULL) AS other_contacts
				FROM '.MAIN_DB_PREFIX.'projet_task AS pt
				LEFT JOIN '.MAIN_DB_PREFIX.'projet AS p ON (p.rowid = pt.fk_projet)
				LEFT JOIN '.MAIN_DB_PREFIX.'societe AS s ON (s.rowid = p.fk_soc)
				LEFT JOIN '.MAIN_DB_PREFIX.'c_country as cos ON cos.rowid = s.fk_pays
				LEFT JOIN '.MAIN_DB_PREFIX.'element_contact as ec ON (ec.element_id=pt.rowid)
				LEFT JOIN '.MAIN_DB_PREFIX.'c_type_contact as tc ON (tc.rowid=ec.fk_c_type_contact AND tc.element="project_task" AND tc.source="internal")
				WHERE tc.element="project_task" AND tc.source="internal" AND ec.fk_socpeople='.intval($calid).'
				AND pt.entity IN ('.getEntity('societe', 1).')';
		if($oid!==false)
		{
			$sql.=' AND pt.rowid = '.intval($oid);
		}
		else
		{
			$sql.='	AND COALESCE(pt.datee,pt.dateo)>="'.date('Y-m-d 00:00:00',time()-86400*CDAV_SYNC_PAST).'" 
					AND pt.dateo<="'.date('Y-m-d 23:59:59',time()+86400*CDAV_SYNC_FUTURE).'"';
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
	   if($obj->elem_source=='ev')		// Calendar Event
	   {
			$categ = [];
			/*if($obj->soc_client)
			{
				$nick[] = $obj->soc_code_client;
				$categ[] = $this->langs->transnoentitiesnoconv('Customer');
			}*/

			$location=trim(str_replace(array("\r","\t","\n"),' ',$obj->location));
			
			// contact address
			if(empty($location) && !empty($obj->address))
			{
				$location = trim(str_replace(array("\r","\t","\n"),' ', $obj->address));
				$location = trim($location.', '.$obj->zip);
				$location = trim($location.' '.$obj->town);
				$location = trim($location.', '.$obj->country_label);
			}
			
			// contact address
			if(empty($location) && !empty($obj->soc_address))
			{
				$location = trim(str_replace(array("\r","\t","\n"),' ', $obj->soc_address));
				$location = trim($location.', '.$obj->soc_zip);
				$location = trim($location.' '.$obj->soc_town);
				$location = trim($location.', '.$obj->soc_country_label);
			}
			
			$address=explode("\n",$obj->address,2);
			foreach($address as $kAddr => $vAddr)
			{
				$address[$kAddr] = trim(str_replace(array("\r","\t"),' ', str_replace("\n",' | ', trim($vAddr))));
			}
			$address[]='';
			$address[]='';

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
			if($obj->sourceuid=='')
				$caldata.="UID:".$obj->id.'-ev-'./*$calid.'-cal-'.*/ CDAV_URI_KEY."\n";
			else
				$caldata.="UID:".$obj->sourceuid."\n";
			$caldata.="SUMMARY:".$obj->label."\n";
			$caldata.="URL:".dol_buildpath("/comm/action/card.php?id=".$obj->id, 2)."\n";
			$caldata.="LOCATION:".$location."\n";
			$caldata.="PRIORITY:".$obj->priority."\n";
			if($obj->fulldayevent)
			{
				$caldata.="DTSTART;VALUE=DATE:".date('Ymd', strtotime($obj->datep))."\n";
				if($type=='VEVENT')
				{
					if(trim($obj->datep2)>trim($obj->datep))
						$caldata.="DTEND;VALUE=DATE:".date('Ymd', strtotime($obj->datep2)+1)."\n";
					else
						$caldata.="DTEND;VALUE=DATE:".date('Ymd', strtotime($obj->datep)+(25*3600))."\n";
				}
				elseif(trim($obj->datep2)!='')
					$caldata.="DUE;VALUE=DATE:".date('Ymd', strtotime($obj->datep2)+1)."\n";
			}
			else
			{
				$caldata.="DTSTART;TZID=".$timezone.":".strtr($obj->datep,array(" "=>"T", ":"=>"", "-"=>""))."\n";
				if($type=='VEVENT')
				{
					if(trim($obj->datep2)>trim($obj->datep))
						$caldata.="DTEND;TZID=".$timezone.":".strtr($obj->datep2,array(" "=>"T", ":"=>"", "-"=>""))."\n";
					else
						$caldata.="DTEND;TZID=".$timezone.":".strtr($obj->datep,array(" "=>"T", ":"=>"", "-"=>""))."\n";
				}
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
			if(!empty($obj->proj_ref))
				$caldata.="💼📋 [".$obj->proj_ref."] ".$obj->proj_title."\\n";
			if(!empty($obj->proj_desc))
				$caldata.="💼⚠️ ".strtr(trim(strip_tags($obj->proj_desc)), array("\n"=>"\\n💼⚠️ ", "\r"=>""))."\\n";
			if(!empty($obj->soc_town))
				$caldata.="💼🏁 ".$obj->soc_town."\\n";
			if(!empty($obj->soc_nom))
				$caldata.="💼🏢 ".$obj->soc_nom."\\n";
			if(!empty($obj->soc_phone))
				$caldata.="💼☎️ ".$obj->soc_phone."\\n";
			if(!empty($obj->firstname) || !empty($obj->lastname))
				$caldata.="💼👨 ".trim($obj->firstname.' '.$obj->lastname)."\\n";
			if(!empty($obj->phone) || !empty($obj->phone_perso) || !empty($obj->phone_mobile))
				$caldata.="💼📞 ".trim($obj->phone.' '.$obj->phone_perso.' '.$obj->phone_mobile)."\\n";
	// removed because unable to swap from one calendar to an other with extrenal client
	//		if(strpos($obj->other_users,',')) // several
	//			$caldata.="💼USR: ".$obj->other_users."\\n";
			if($type=='VEVENT')
				$caldata.=strtr(trim($obj->note), array("\n"=>"\\n", "\r"=>""));
			else
				$caldata.=strtr("\n".trim($obj->note), array("\n- "=>"\\n[ ] ", "\n"=>"\\n", "\r"=>""));
			$caldata.="\n";
			 
			$caldata.="END:".$type."\n";
			$caldata.="END:VCALENDAR\n";
		}
	   elseif(substr($obj->elem_source,0,1)=='p')		// Project Task  pe/pt
	   {
			if($obj->elem_source=='pe')
				$type='VEVENT';
			else 	// 'pt'
				$type='VTODO';
			
			$location='';
			
			// soc address
			if(!empty($obj->soc_address))
			{
				$location = trim(str_replace(array("\r","\t","\n"),' ', $obj->soc_address));
				$location = trim($location.', '.$obj->soc_zip);
				$location = trim($location.' '.$obj->soc_town);
				$location = trim($location.', '.$obj->soc_country_label);
			}
			
			$timezone = date_default_timezone_get();

			$caldata ="BEGIN:VCALENDAR\n";
			$caldata.="VERSION:2.0\n";
			$caldata.="METHOD:PUBLISH\n";
			$caldata.="PRODID:-//Dolibarr CDav//FR\n";
			$caldata.="BEGIN:".$type."\n";
			$caldata.="CREATED:".gmdate('Ymd\THis', strtotime($obj->datec))."Z\n";
			$caldata.="LAST-MODIFIED:".gmdate('Ymd\THis', strtotime($obj->lastupd))."Z\n";
			$caldata.="DTSTAMP:".gmdate('Ymd\THis', strtotime($obj->lastupd))."Z\n";
			$caldata.="UID:".$obj->id.'-'.$obj->elem_source.'-'./*$calid.'-cal-'.*/ CDAV_URI_KEY."\n";
			$caldata.="SUMMARY:[".$obj->proj_title."]".$obj->label."\n";
			$caldata.="URL:".dol_buildpath("/projet/tasks/task.php?id=".$obj->id."&withproject=".$obj->fk_projet,2)."\n";
			$caldata.="LOCATION:".$location."\n";
			$caldata.="PRIORITY:".$obj->priority."\n";

			$caldata.="DTSTART;TZID=".$timezone.":".strtr($obj->dateo,array(" "=>"T", ":"=>"", "-"=>""))."\n";
			if($type=='VEVENT')
			{
				if(trim($obj->datee)>trim($obj->dateo))
					$caldata.="DTEND;TZID=".$timezone.":".strtr($obj->datee,array(" "=>"T", ":"=>"", "-"=>""))."\n";
				else
					$caldata.="DTEND;TZID=".$timezone.":".strtr($obj->dateo,array(" "=>"T", ":"=>"", "-"=>""))."\n";
			}
			elseif(trim($obj->datee)!='')
				$caldata.="DUE;TZID=".$timezone.":".strtr($obj->datee,array(" "=>"T", ":"=>"", "-"=>""))."\n";
				
			$caldata.="CLASS:PUBLIC\n";
			$caldata.="TRANSP:OPAQUE\n";
			
			if($type=='VEVENT')
				$caldata.="STATUS:CONFIRMED\n";
			elseif($obj->progress==0)
				$caldata.="STATUS:NEEDS-ACTION\n";
			elseif($obj->progress==100)
				$caldata.="STATUS:COMPLETED\n";
			else
			{
				$caldata.="STATUS:IN-PROCESS\n";
				$caldata.="PERCENT-COMPLETE:".$obj->progress."\n";
			}
		
			$caldata.="DESCRIPTION:";
			if(!empty($obj->proj_desc))
				$caldata.="💼⚠️ ".strtr(trim(strip_tags($obj->proj_desc)), array("\n"=>"\\n💼⚠️ ", "\r"=>""))."\\n";
			if(!empty($obj->soc_town))
				$caldata.="💼🏁 ".$obj->soc_town."\\n";
			if(!empty($obj->soc_nom))
				$caldata.="💼🏢 ".$obj->soc_nom."\\n";
			if(!empty($obj->soc_phone))
				$caldata.="💼☎️ ".$obj->soc_phone."\\n";
			if(!empty($obj->other_contacts))
				$caldata.="💼👨 ".$obj->other_contacts."\\n";
			if(!empty($obj->proj_ref))
				$caldata.="💼📋 [".$obj->proj_ref."/".$obj->ref."] ".$obj->proj_title."\\n";
			if(!empty($obj->note_public))
				$caldata.="💼📝 ".strtr(trim(strip_tags($obj->note_public)), array("\n"=>"\\n💼📝 ", "\r"=>""))."\\n";
	//removed because unable to swap from one calendar to an other with external client
	//		if(!empty($obj->note_private))
	//			$caldata.="💼🔒 ".strtr(trim(strip_tags($obj->note_private)), array("\n"=>"\\n💼🔒 ", "\r"=>""))."\\n";
			$caldata.=strtr("\n".trim($obj->description), array("\n- "=>"\\n[ ] ", "\n"=>"\\n", "\r"=>""));
	// removed because unable to swap from one calendar to an other with external client
	//		if(strpos($obj->other_users,',')) // several
	//			$caldata.="💼USR: ".$obj->other_users."\\n";
			$caldata.="\n";
			 
			$caldata.="END:".$type."\n";
			$caldata.="END:VCALENDAR\n";
		}

		return $caldata;
	}
	
	public function getFullCalendarObjects($calendarId, $bCalendarData) 
	{
		debug_log("getCalendarObjects( $calendarId , $bCalendarData )");
		
		$calid = ($calendarId*1);
		$calevents = [] ;
		$rSql = [] ;

		if(! $this->user->rights->agenda->myactions->read)
			return $calevents;
		
		if($calid!=$this->user->id && (!isset($this->user->rights->agenda->allactions->read) || !$this->user->rights->agenda->allactions->read))
			return $calevents;

		$rSql['ev'] = $this->getSqlCalEvents($calid);
		$rSql['pe'] = $this->getSqlProjectTasks($calid, false, 'pe');
		$rSql['pt'] = $this->getSqlProjectTasks($calid, false, 'pt');
	  
		foreach($rSql as $elem_source => $sql)
		{
			if($sql=='')
				continue;
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
							'uri' => $obj->id.'-'.$elem_source.'-'.CDAV_URI_KEY,
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
							'uri' => $obj->id.'-'.$elem_source.'-'.CDAV_URI_KEY,
							'lastmodified' => strtotime($obj->lastupd),
							'etag' => '"'.md5($calendardata).'"',
							'calendarid'   => $calendarId,
							'size' => strlen($calendardata),
							'component' => strpos($calendardata, 'BEGIN:VEVENT')>0 ? 'vevent' : 'vtodo',
						];
					}
				}
			}
		}
		return $calevents;
	}
	
}
