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
		global $conf;
		$projectIds = '0';
		if (isModEnabled('project') && $this->user->hasRight('projet', 'lire')) {
			require_once DOL_DOCUMENT_ROOT.'/projet/class/project.class.php';
			$project = new Project($this->db);
			$authorized = $project->getProjectsAuthorizedForUser($this->user, $this->user->hasRight('projet', 'all', 'lire') ? 2 : 0, 1);
			$projectIds = $authorized ? $this->db->sanitize($authorized) : '0';
		}

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
		if (! $this->user->hasRight('societe', 'client', 'voir') )//FIXME si 'voir' on voit plus de chose ?
		{
			$sql.=' LEFT OUTER JOIN '.MAIN_DB_PREFIX.'societe_commerciaux AS sc ON (a.fk_soc = sc.fk_soc AND sc.fk_user='.$this->user->id.')
					LEFT JOIN '.MAIN_DB_PREFIX.'societe AS s ON (s.rowid = sc.fk_soc AND s.entity IN ('.getEntity('societe').') AND '.($this->user->hasRight('societe', 'lire') ? '1' : '0').')
					LEFT JOIN '.MAIN_DB_PREFIX.'socpeople AS sp ON (sp.fk_soc = sc.fk_soc AND sp.rowid = a.fk_contact AND sp.entity IN ('.getEntity('socpeople').') AND (sp.priv=0 OR sp.fk_user_creat='.(int) $this->user->id.') AND '.($this->user->hasRight('societe', 'contact', 'lire') ? '1' : '0').')
					LEFT JOIN '.MAIN_DB_PREFIX.'actioncomm_cdav AS ac ON (a.id = ac.fk_object)';
		}
		else
		{
			$sql.=' LEFT JOIN '.MAIN_DB_PREFIX.'societe AS s ON (s.rowid = a.fk_soc AND s.entity IN ('.getEntity('societe').') AND '.($this->user->hasRight('societe', 'lire') ? '1' : '0').')
					LEFT JOIN '.MAIN_DB_PREFIX.'socpeople AS sp ON (sp.rowid = a.fk_contact AND sp.entity IN ('.getEntity('socpeople').') AND (sp.priv=0 OR sp.fk_user_creat='.(int) $this->user->id.') AND '.($this->user->hasRight('societe', 'contact', 'lire') ? '1' : '0').')
					LEFT JOIN '.MAIN_DB_PREFIX.'actioncomm_cdav AS ac ON (a.id = ac.fk_object)';
		}

		$sql.=' LEFT JOIN '.MAIN_DB_PREFIX.'projet AS p ON (p.rowid = a.fk_project AND p.entity IN ('.getEntity('project').') AND p.rowid IN ('.$projectIds.'))
				LEFT JOIN '.MAIN_DB_PREFIX.'c_country as co ON co.rowid = sp.fk_pays
				LEFT JOIN '.MAIN_DB_PREFIX.'c_country as cos ON cos.rowid = s.fk_pays
				WHERE 	a.id IN (SELECT ar.fk_actioncomm FROM '.MAIN_DB_PREFIX.'actioncomm_resources ar WHERE ar.element_type=\'user\' AND ar.fk_element='.intval($calid).')
						AND a.code IN (SELECT cac.code FROM '.MAIN_DB_PREFIX.'c_actioncomm cac WHERE cac.type<>\'systemauto\')
						AND a.entity IN ('.getEntity('agenda', 1).')';
		if($oid!==false) {
			if($ouri===false)
			{
				$sql.=' AND a.id = '.intval($oid);
			}
			else
			{
				$sql.=' AND a.entity = '.((int) $conf->entity).' AND (ac.uuidext = \''.$this->db->escape($ouri).'\' OR ac.sourceuid = \''.$this->db->escape($ouri).'\')';
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
	public function getSqlProjectTasks($calid, $oid=false, $elem_source='pe')
	{
		global $conf;

		if(!CDavCompatibility::isFeatureAvailable('tasks') || !$this->user->hasRight('projet', 'lire'))
			return false;

		if(intval(CDAV_TASK_SYNC)==0 || (intval(CDAV_TASK_SYNC)==1 && $elem_source=='pt'))
			return false;

		if(intval(CDAV_TASK_SYNC)==0 || (intval(CDAV_TASK_SYNC)==2 && $elem_source=='pe'))
			return false;

		// TODO : replace GROUP_CONCAT by
		$sql = 'SELECT DISTINCT
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
						LEFT JOIN '.MAIN_DB_PREFIX.'socpeople AS sp ON (sp.rowid=gec.fk_socpeople AND sp.entity IN ('.getEntity('socpeople').') AND (sp.priv=0 OR sp.fk_user_creat='.(int) $this->user->id.') AND '.($this->user->hasRight('societe', 'contact', 'lire') ? '1' : '0').')
						WHERE gec.element_id=pt.rowid AND gtc.element="project_task" AND sp.lastname IS NOT NULL) AS other_contacts
				FROM '.MAIN_DB_PREFIX.'projet_task AS pt
				LEFT JOIN '.MAIN_DB_PREFIX.'projet AS p ON (p.rowid = pt.fk_projet)
				LEFT JOIN '.MAIN_DB_PREFIX.'societe AS s ON (s.rowid = p.fk_soc AND s.entity IN ('.getEntity('societe').') AND '.($this->user->hasRight('societe', 'lire') ? '1' : '0').')
				LEFT JOIN '.MAIN_DB_PREFIX.'c_country as cos ON cos.rowid = s.fk_pays
				LEFT JOIN '.MAIN_DB_PREFIX.'element_contact as ec ON (ec.element_id=pt.rowid)
				LEFT JOIN '.MAIN_DB_PREFIX.'c_type_contact as tc ON (tc.rowid=ec.fk_c_type_contact AND tc.element="project_task" AND tc.source="internal")
				WHERE tc.element="project_task" AND tc.source="internal" AND ec.fk_socpeople='.intval($calid).'
				AND pt.entity IN ('.getEntity('project', 1).') AND p.entity IN ('.getEntity('project', 1).')';
		require_once DOL_DOCUMENT_ROOT.'/projet/class/project.class.php';
		$project = new Project($this->db);
		$authorized = $project->getProjectsAuthorizedForUser($this->user, $this->user->hasRight('projet', 'all', 'lire') ? 2 : 0, 1);
		$sql .= ' AND p.rowid IN ('.($authorized ? $this->db->sanitize($authorized) : '0').')';
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
	 * Base sql request for intervention cards (fichinter)
	 *
	 * @param int calendar user id
	 * @param int fichinter object id
	 * @return string
	 */
	public function getSqlIntervEvents($calid, $oid=false)
	{
		global $conf;
		$projectIds = '0';
		if (isModEnabled('project') && $this->user->hasRight('projet', 'lire')) {
			require_once DOL_DOCUMENT_ROOT.'/projet/class/project.class.php';
			$project = new Project($this->db);
			$authorized = $project->getProjectsAuthorizedForUser($this->user, $this->user->hasRight('projet', 'all', 'lire') ? 2 : 0, 1);
			$projectIds = $authorized ? $this->db->sanitize($authorized) : '0';
		}


		if(!CDavCompatibility::isFeatureAvailable('interventions') || !$this->user->hasRight('ficheinter', 'lire'))
			return false;

		if(intval(CDAV_INTERV_SYNC)==0)
			return false;

		$sql = 'SELECT DISTINCT
					"fi" elem_source,
					fid.rowid AS id,
					fi.rowid AS fi_id,
					fi.ref AS fi_ref,
					fi.description AS fi_description,
					fi.note_public AS fi_note_public,
					fi.datec AS datec,
					fi.tms AS lastupd,
					fid.date AS det_date,
					fid.duree AS det_duree,
					fid.description AS det_description,
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
						LEFT JOIN '.MAIN_DB_PREFIX.'c_type_contact as gtc ON (gtc.rowid=gec.fk_c_type_contact AND gtc.element="fichinter" AND gtc.source="internal")
						LEFT OUTER JOIN '.MAIN_DB_PREFIX.'user AS u ON (u.rowid=gec.fk_socpeople)
						WHERE gec.element_id=fi.rowid AND gtc.element="fichinter" AND u.login IS NOT NULL) AS other_users,
					(SELECT GROUP_CONCAT(sp.firstname, " ", sp.lastname) FROM '.MAIN_DB_PREFIX.'element_contact gec
						LEFT JOIN '.MAIN_DB_PREFIX.'c_type_contact as gtc ON (gtc.rowid=gec.fk_c_type_contact AND gtc.element="fichinter" AND gtc.source="external")
						LEFT JOIN '.MAIN_DB_PREFIX.'socpeople AS sp ON (sp.rowid=gec.fk_socpeople AND sp.entity IN ('.getEntity('socpeople').') AND (sp.priv=0 OR sp.fk_user_creat='.(int) $this->user->id.') AND '.($this->user->hasRight('societe', 'contact', 'lire') ? '1' : '0').')
						WHERE gec.element_id=fi.rowid AND gtc.element="fichinter" AND sp.lastname IS NOT NULL) AS other_contacts
				FROM '.MAIN_DB_PREFIX.'fichinter AS fi
				INNER JOIN '.MAIN_DB_PREFIX.'fichinterdet AS fid ON fid.fk_fichinter = fi.rowid
				LEFT JOIN '.MAIN_DB_PREFIX.'projet AS p ON (p.rowid = fi.fk_projet AND p.entity IN ('.getEntity('project').') AND p.rowid IN ('.$projectIds.'))
				LEFT JOIN '.MAIN_DB_PREFIX.'societe AS s ON (s.rowid = fi.fk_soc AND s.entity IN ('.getEntity('societe').') AND '.($this->user->hasRight('societe', 'lire') ? '1' : '0').')
				LEFT JOIN '.MAIN_DB_PREFIX.'c_country as cos ON cos.rowid = s.fk_pays
				LEFT JOIN '.MAIN_DB_PREFIX.'element_contact as ec ON (ec.element_id=fi.rowid)
				LEFT JOIN '.MAIN_DB_PREFIX.'c_type_contact as gtc ON (gtc.rowid=ec.fk_c_type_contact AND gtc.element="fichinter" AND gtc.source="internal")
				WHERE gtc.element="fichinter" AND gtc.source="internal" AND ec.fk_socpeople='.intval($calid).'
				AND fid.date IS NOT NULL
				AND fi.entity IN ('.getEntity('intervention', 1).')';
		if($oid!==false)
		{
			$sql.=' AND fid.rowid = '.intval($oid);
		}
		else
		{
			$sql.='	AND fid.date>="'.date('Y-m-d 00:00:00',time()-86400*CDAV_SYNC_PAST).'"
					AND fid.date<="'.date('Y-m-d 23:59:59',time()+86400*CDAV_SYNC_FUTURE).'"';
		}
		return $sql;

	}

	/**
	 * Convert calendar row to VCalendar string
	 *
	 * @param row object
	 * @return string
	 */
	public function toVCalendar($calid, $obj, $bHeader)
	{
		require_once DOL_DOCUMENT_ROOT.'/includes/sabre/autoload.php';
		$source = $obj->elem_source;
		if (!in_array($source, array('ev', 'pe', 'pt', 'fi'), true)) throw new InvalidArgumentException('Unknown calendar source');
		$type = $source === 'pt' || ($source === 'ev' && (int) $obj->percent !== -1) ? 'VTODO' : 'VEVENT';
		$calendar = new \Sabre\VObject\Component\VCalendar();
		$calendar->PRODID = '-//Dolibarr CDav//FR';
		$component = $calendar->add($type, array());
		$component->UID = $source === 'ev' && !empty($obj->sourceuid) ? (string) $obj->sourceuid : $obj->id.'-'.$source.'-'.CDAV_URI_KEY;
		$component->SUMMARY = trim((string) ($obj->label ?? ''));
		if ($source === 'pe' || $source === 'pt') $component->SUMMARY = '['.trim((string) $obj->proj_title).']'.trim((string) $obj->label);
		if ($source === 'fi') $component->SUMMARY = '['.trim((string) $obj->fi_ref).'] '.trim((string) ($obj->fi_description ?: $obj->soc_nom));
		$paths = array('ev' => '/comm/action/card.php?id='.$obj->id, 'pe' => '/projet/tasks/task.php?id='.$obj->id.'&withproject='.($obj->fk_projet ?? 0), 'pt' => '/projet/tasks/task.php?id='.$obj->id.'&withproject='.($obj->fk_projet ?? 0), 'fi' => '/fichinter/card.php?id='.($obj->fi_id ?? 0));
		$component->URL = dol_buildpath($paths[$source], 2);
		$component->CLASS = 'PUBLIC';
		$component->TRANSP = $source === 'ev' && !empty($obj->transparency) ? 'TRANSPARENT' : 'OPAQUE';
		$component->PRIORITY = max(0, min(9, (int) ($obj->priority ?? 0)));
		$percent = (int) ($source === 'ev' ? $obj->percent : ($obj->progress ?? 0));
		$component->STATUS = $type === 'VEVENT' ? 'CONFIRMED' : ($percent >= 100 ? 'COMPLETED' : ($percent <= 0 ? 'NEEDS-ACTION' : 'IN-PROCESS'));
		if ($type === 'VTODO') $component->{'PERCENT-COMPLETE'} = max(0, min(100, $percent));
		$startValue = $source === 'ev' ? $obj->datep : ($source === 'fi' ? $obj->det_date : $obj->dateo);
		$endValue = $source === 'ev' ? $obj->datep2 : ($source === 'fi' ? '' : $obj->datee);
		$timezone = new DateTimeZone(date_default_timezone_get());
		$utc = new DateTimeZone('UTC');
		$start = $startValue ? new DateTimeImmutable($startValue, $timezone) : null;
		$end = $endValue ? new DateTimeImmutable($endValue, $timezone) : null;
		if ($source === 'fi' && $start) $end = $start->modify('+'.((int) $obj->det_duree > 0 ? (int) $obj->det_duree : 3600).' seconds');
		$fullday = $source === 'ev' && !empty($obj->fulldayevent);
		if ($start) {
			if ($fullday) $component->add('DTSTART', $start->format('Ymd'), array('VALUE' => 'DATE'));
			else $component->DTSTART = $start->setTimezone($utc)->format('Ymd\THis\Z');
		}
		$endProperty = $type === 'VEVENT' ? 'DTEND' : 'DUE';
		if ($type === 'VEVENT' && $start && (!$end || $end < $start)) $end = $start;
		if ($end) {
			if ($fullday) {
				// Native all-day events end inclusively; RFC 5545 uses an exclusive date.
				$exclusive = $end->modify('+1 second');
				if ($start && $exclusive->format('Ymd') <= $start->format('Ymd')) $exclusive = $start->modify('+1 day');
				$component->add($endProperty, $exclusive->format('Ymd'), array('VALUE' => 'DATE'));
			} else $component->{$endProperty} = $end->setTimezone($utc)->format('Ymd\THis\Z');
		}
		foreach (array('CREATED' => $obj->datec ?? '', 'LAST-MODIFIED' => $obj->lastupd ?? '', 'DTSTAMP' => $obj->lastupd ?? ($obj->datec ?? '')) as $name => $value) {
			if ($value) $component->{$name} = (new DateTimeImmutable($value, $timezone))->setTimezone($utc)->format('Ymd\THis\Z');
		}
		if (!isset($component->DTSTAMP)) $component->DTSTAMP = '19700101T000000Z';
		$location = trim((string) ($obj->location ?? ''));
		if ($location === '') {
			$prefix = $source === 'ev' && !empty($obj->address) ? '' : 'soc_';
			$location = trim((string) ($obj->{$prefix.'address'} ?? ''));
			if ($location !== '') $location .= ', '.trim(($obj->{$prefix.'zip'} ?? '').' '.($obj->{$prefix.'town'} ?? '')).', '.($obj->{$prefix.'country_label'} ?? '');
		}
		$component->LOCATION = trim(str_replace(array("\r", "\n", "\t"), ' ', $location));
		// Keep the existing contextual information; Sabre escapes and folds all text.
		$description = array();
		if (!empty($obj->proj_ref)) $description[] = '💼📋 ['.$obj->proj_ref.(in_array($source, array('pe', 'pt'), true) ? '/'.$obj->ref : '').'] '.$obj->proj_title;
		foreach (array('proj_desc' => '💼⚠️ ', 'soc_town' => '💼🏁 ', 'soc_nom' => '💼🏢 ', 'soc_phone' => '💼☎️ ') as $field => $prefix) {
			if (!empty($obj->{$field})) $description[] = $prefix.trim(strip_tags((string) $obj->{$field}));
		}
		if ($source === 'ev') {
			$name = trim(($obj->firstname ?? '').' '.($obj->lastname ?? ''));
			$phones = trim(($obj->phone ?? '').' '.($obj->phone_perso ?? '').' '.($obj->phone_mobile ?? ''));
			if ($name !== '') $description[] = '💼👨 '.$name;
			if ($phones !== '') $description[] = '💼📞 '.$phones;
			$note = trim((string) ($obj->note ?? ''));
		} else {
			if (!empty($obj->other_contacts)) $description[] = '💼👨 '.$obj->other_contacts;
			$publicNote = $source === 'fi' ? ($obj->fi_note_public ?? '') : ($obj->note_public ?? '');
			if ($publicNote !== '') $description[] = '💼📝 '.trim(strip_tags((string) $publicNote));
			$note = trim((string) ($source === 'fi' ? strip_tags((string) $obj->det_description) : $obj->description));
		}
		if ($type === 'VTODO') $note = strtr("\n".$note, array("\n- [" => "\n[", "\n- " => "\n[ ] ", '[x] [ ]' => '[x]', '[ ] [x]' => '[x]', '[x] [x]' => '[x]', '[ ] [ ]' => '[ ]'));
		$description[] = $note;
		$component->DESCRIPTION = implode("\n", $description);
		return $bHeader ? $calendar->serialize() : $component->serialize();
	}

	public function getFullCalendarObjects($calendarId, $bCalendarData)
	{
		if(function_exists("debug_log"))
			debug_log("getCalendarObjects( $calendarId , $bCalendarData )");

		$calid = intval($calendarId);
		$calevents = [] ;
		$rSql = [] ;

		if(!CDavCompatibility::isFeatureAvailable('caldav') || !$this->user->hasRight('agenda', 'myactions', 'read'))
			return $calevents;

		if($calid!=$this->user->id && (!$this->user->hasRight('agenda', 'allactions', 'read')))
			return $calevents;

		$users = $this->db->query('SELECT u.rowid FROM '.MAIN_DB_PREFIX.'user u WHERE '.cdavCalendarUserScope().' AND u.rowid='.(int) $calid);
		if (!$users || !is_object($this->db->fetch_object($users))) return $calevents;

		$rSql['ev'] = $this->getSqlCalEvents($calid);
		$rSql['pe'] = $this->getSqlProjectTasks($calid, false, 'pe');
		$rSql['pt'] = $this->getSqlProjectTasks($calid, false, 'pt');
		$rSql['fi'] = $this->getSqlIntervEvents($calid);

		foreach($rSql as $elem_source => $sql)
		{
			if($sql=='')
				continue;
			$result = $this->db->query($sql);

			if ($result)
			{
				while ($obj = $this->db->fetch_object($result))
				{
					$calendardata = $this->toVCalendar($calid, $obj, true);

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

/**
 * Return the entity segment to insert into cdav urls (see server.php)
 *
 * The segment is only added when multicompany is enabled, so that mono entity
 * installations keep the exact same urls as before.
 *
 * @param	int		$entity		Entity to use, current one if empty
 * @return	string				'' or '/<entity>'
 */
function cdavEntityUriSegment($entity = 0)
{
	global $conf;

	$multicompany = isModEnabled('multicompany');

	if(!$multicompany)
		return '';

	if(empty($entity))
		$entity = (empty($conf->entity) ? 1 : $conf->entity);

	return '/'.((int) $entity);
}

/**
 * SQL scope for the active internal users discoverable in the target entity.
 * Transverse assignments live in usergroup_user, not in user.entity.
 * The caller must independently enforce its functional permission.
 * @return string SQL predicate, using the fixed alias u
 */
function cdavCalendarUserScope()
{
	global $db;
	$sql = 'u.statut=1 AND u.fk_soc IS NULL';
	if (isModEnabled('multicompany') && getDolGlobalInt('MULTICOMPANY_TRANSVERSE_MODE')) {
		$sql .= ' AND (u.entity=0 OR EXISTS (SELECT 1 FROM '.MAIN_DB_PREFIX.'usergroup_user cug'
			.' WHERE cug.fk_user=u.rowid AND cug.entity IN ('.$db->sanitize(getEntity('usergroup')).')))';
	} else {
		$sql .= ' AND u.entity IN ('.$db->sanitize(getEntity('user', 1)).')';
	}
	return $sql;
}
