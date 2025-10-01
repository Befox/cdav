<?php

/**
 *  ActionsCDav
 * Hook class for Dolibarr action
 */
class ActionsCDav
{
	/**
	 * Overloading the doActions function : replacing the parent's function with the one below
	 *
	 * @param   array           $parameters     Hook metadatas (context, etc...)
	 * @param   CommonObject    $object         The object to process (an invoice if you are in invoice module, a propale in propale's module, etc...)
	 * @param   string          $action         Current action (if set). Generally create or edit or null
	 * @param   HookManager     $hookmanager    Hook manager propagated to allow calling another hook
	 * @return  int                             < 0 on error, 0 on success, 1 to replace standard code
	 */
	public function doActions($parameters, &$object, &$action, $hookmanager)
	{
		global $user, $conf, $db;

		// echo "action: " . $action;
		// echo "parameters: ";
		// print_r($parameters);

		if (!isset($conf->global->CDAV_GENTASK) || intval($conf->global->CDAV_GENTASK) == 0 || $parameters['currentcontext'] != 'projectcard')
			return 0;

		if ($action == "confirm_validate" && isset($object->id) && $object->id > 0) {
			//ok go ahead
		} elseif (GETPOST('status') != 1 && $action != "confirm_validateProject" || !isset($object->id) || $object->id <= 0)
			return 0;

		$CDAV_PROJ_USER_ROLE = $conf->global->CDAV_PROJ_USER_ROLE;        // to pick good user in project
		$CDAV_TASK_USER_ROLE = $conf->global->CDAV_TASK_USER_ROLE;        // role to put on task
		$CDAV_GENTASK_INI1 = $conf->global->CDAV_GENTASK_INI1;        // initial service
		$CDAV_GENTASK_INI2 = $conf->global->CDAV_GENTASK_INI2;        // initial service
		$CDAV_GENTASK_INI3 = $conf->global->CDAV_GENTASK_INI3;        // initial service
		$CDAV_GENTASK_END1 = $conf->global->CDAV_GENTASK_END1;        // final service
		$CDAV_GENTASK_END2 = $conf->global->CDAV_GENTASK_END2;        // final service
		$CDAV_GENTASK_END3 = $conf->global->CDAV_GENTASK_END3;        // final service
		$CDAV_GENTASK_SERVICE_TAG = $conf->global->CDAV_GENTASK_SERVICE_TAG;        // restrict services
		$CDAV_EXTRAFIELD_DURATION = $conf->global->CDAV_EXTRAFIELD_DURATION;        // duration in propaldet & commandedet extrafields
		$CDAV_TASK_HOUR_INI = $conf->global->CDAV_TASK_HOUR_INI;        // begining of a working day
		$CDAV_TASK_HOUR_END = $conf->global->CDAV_TASK_HOUR_END;        // ending of a working day

		if (isset($conf->global->WEEE_PRODUCT_ID) && intval($conf->global->WEEE_PRODUCT_ID) != 0)
			$WEEE_PRODUCT_ID = intval($conf->global->WEEE_PRODUCT_ID);        // DEEE ?

		if ($action == "confirm_validate") {  // button Validate
			$date_start = $object->date_start;
			$date_end = $object->date_end;
		} else // POST form
		{
			$date_start = dol_mktime(0, 0, 0, GETPOST('projectstartmonth', 'int'), GETPOST('projectstartday', 'int'), GETPOST('projectstartyear', 'int'));
			$date_end = dol_mktime(0, 0, 0, GETPOST('projectendmonth', 'int'), GETPOST('projectendday', 'int'), GETPOST('projectendyear', 'int'));
		}

		$error = 0; // Error counter
		//$myvalue = 'test'; // A result value

		// echo "action: " . $action;
		// echo "User ";
		// print_r($user);
		//echo "Object ";
		//print_r($object);

		// do something only for the context 'somecontext'

		$sql = "SELECT * FROM " . MAIN_DB_PREFIX . "projet_task WHERE fk_projet = " . intval($object->id);
		$result = $db->query($sql);
		// echo "Result ";
		// print_r($result);
		if ($db->num_rows($result) == 0 && isset($user->rights->agenda->allactions->read)) {
			$db->free($result);
			//echo "NOTASK";

			$rPreTasksLib = array();
			$rPreTasksDesc = array();
			$rPreTasksDuree = array();
			$rPostTasksLib = array();
			$rPostTasksDesc = array();
			$rPostTasksDuree = array();
			$rTasksLib = array();
			$rTasksDesc = array();
			$rTasksDuree = array();

			// retrieve n pre-tasks [0-3]
			$i = 0;
			while (++$i <= 3) {
				if (isset(${'CDAV_GENTASK_INI' . $i}) && ${'CDAV_GENTASK_INI' . $i} > 0) {
					// Found CDAV_GENTASK_INI$i ==> ${'CDAV_GENTASK_INI' . $i}
					$sqldet = "SELECT label, description, duration FROM " . MAIN_DB_PREFIX . "product WHERE rowid = " . intval(${'CDAV_GENTASK_INI' . $i});
					$querydet = $db->query($sqldet);
					while ($querydet && ($det = $db->fetch_object($querydet)) !== null) {
						$rPreTasksLib[] = $det->label;
						$rPreTasksDesc[] = $det->description;
						$rPreTasksDuree[] = $det->duration;
					}
					$db->free($querydet);
					unset($det);
				}
			}

			$bDocs = false;
			$rElmts = array(); // orders source's : $rElmts[propal_ID]=commande_ID

			$sql = " SELECT * FROM " . MAIN_DB_PREFIX . "commande WHERE fk_projet = " . intval($object->id) . " ORDER BY rowid";
			$result = $db->query($sql);
			while ($result && ($res = $db->fetch_object($result)) !== null) {
				$bDocs = true;
				$sqldet = " SELECT det.*, pro.label as prod_label, pro.description as prod_description, pro.duration as prod_duration, elt.fk_source AS prop_source, ef.cdav_duration as cdav_duration
							FROM " . MAIN_DB_PREFIX . "commandedet AS det
								LEFT OUTER JOIN " . MAIN_DB_PREFIX . "product AS pro ON (pro.rowid=det.fk_product)";
				if (isset($CDAV_GENTASK_SERVICE_TAG) && $CDAV_GENTASK_SERVICE_TAG > 0)
					$sqldet .= " LEFT OUTER JOIN " . MAIN_DB_PREFIX . "categorie_product AS cat ON (cat.fk_product=det.fk_product AND cat.fk_categorie = " . intval($CDAV_GENTASK_SERVICE_TAG) . ")";

				$sqldet .= " LEFT OUTER JOIN " . MAIN_DB_PREFIX . "commandedet_extrafields AS ef ON (ef.fk_object=det.rowid)";
				$sqldet .= " LEFT OUTER JOIN " . MAIN_DB_PREFIX . "element_element elt ON ( elt.sourcetype='propal' AND elt.targettype='commande' AND elt.fk_target = " . intval($res->rowid) . ")
							WHERE fk_commande = " . intval($res->rowid) . " AND qty > 0 AND product_type=1 ";
				if ($CDAV_GENTASK_SERVICE_TAG > 0 && $CDAV_EXTRAFIELD_DURATION !== false)
					$sqldet .= " AND (cat.fk_categorie IS NOT NULL OR COALESCE(ef.cdav_duration,'') <> '' )";
				elseif ($CDAV_EXTRAFIELD_DURATION !== false)
					$sqldet .= " AND COALESCE(duration,'') <> '' ";
				elseif ($CDAV_GENTASK_SERVICE_TAG > 0)
					$sqldet .= " AND cat.fk_categorie IS NOT NULL";
				$sqldet .= " ORDER BY rowid";
				//echo "\n\n $sqldet \n\n";

				$querydet = $db->query($sqldet);
				while ($querydet && ($det = $db->fetch_object($querydet)) !== null) {
					if (!is_null($det->prop_source) && $det->prop_source !== false)
						$rElmts[$det->prop_source] = $det->rowid;
					//else echo "\n\n NOSOURCE";

					if (isset($WEEE_PRODUCT_ID) && $WEEE_PRODUCT_ID == $det->fk_product)
						continue;

					if (!empty($det->label))
						$rTasksLib[] = $det->label;
					elseif (!empty($det->prod_label))
						$rTasksLib[] = $det->prod_label;
					elseif (!empty($det->description))
						$rTasksLib[] = strtok($det->description, "\n");
					else $rTasksLib[] = 'Task';

					if (!empty($det->description))
						$rTasksDesc[] = $det->description;
					elseif (!empty($det->prod_description))
						$rTasksDesc[] = $det->prod_description;
					else $rTasksDesc[] = '';

					if (!empty($det->cdav_duration))
						$rTasksDuree[] = $det->cdav_duration;
					elseif (!empty($det->prod_duration))
						$rTasksDuree[] = $det->prod_duration;
					else $rTasksDuree[] = '';
				}
				unset($det);
				$db->free($querydet);
			}
			$db->free($result);
			//var_dump($rElmts);
			$sql = " SELECT * FROM " . MAIN_DB_PREFIX . "propal WHERE fk_projet = " . intval($object->id);
			$query = $db->query($sql);
			while ($query && ($res = $db->fetch_object($query)) !== null) {
				$bDocs = true;
				if (isset($rElmts[$res->rowid]))
					continue;

				$sqldet = " SELECT det.*, pro.label as prod_label, pro.description as prod_description, pro.duration as prod_duration, ef.cdav_duration as cdav_duration
							FROM " . MAIN_DB_PREFIX . "propaldet AS det
								LEFT OUTER JOIN " . MAIN_DB_PREFIX . "product AS pro ON (pro.rowid=det.fk_product)";
				if (isset($CDAV_GENTASK_SERVICE_TAG) && $CDAV_GENTASK_SERVICE_TAG > 0)
					$sqldet .= " LEFT OUTER JOIN " . MAIN_DB_PREFIX . "categorie_product AS cat ON (cat.fk_product=det.fk_product AND cat.fk_categorie = " . intval($CDAV_GENTASK_SERVICE_TAG) . ")";

				$sqldet .= " LEFT OUTER JOIN " . MAIN_DB_PREFIX . "propaldet_extrafields AS ef ON (ef.fk_object=det.rowid)";
				$sqldet .= " WHERE fk_propal = " . intval($res->rowid) . " AND qty > 0 AND product_type=1";
				if ($CDAV_GENTASK_SERVICE_TAG > 0 && $CDAV_EXTRAFIELD_DURATION !== false)
					$sqldet .= " AND (cat.fk_categorie IS NOT NULL OR COALESCE(ef.cdav_duration,'') <> '' )";
				elseif ($CDAV_EXTRAFIELD_DURATION !== false)
					$sqldet .= " AND COALESCE(ef.cdav_duration,'') <> '' ";
				elseif ($CDAV_GENTASK_SERVICE_TAG > 0)
					$sqldet .= " AND cat.fk_categorie IS NOT NULL";
				$sqldet .= " ORDER BY rowid";

				//echo "\n\n $sqldet \n\n";
				$querydet = $db->query($sqldet);
				while ($querydet && ($det = $db->fetch_object($querydet)) !== null) {
					if (isset($WEEE_PRODUCT_ID) && $WEEE_PRODUCT_ID == $det->fk_product)
						continue;

					//if( !isset($CDAV_EXTRAFIELD_DURATION) || $CDAV_EXTRAFIELD_DURATION != true || empty($det->duration) )
					//	continue;

					if (!empty($det->label))
						$rTasksLib[] = $det->label;
					elseif (!empty($det->prod_label))
						$rTasksLib[] = $det->prod_label;
					elseif (!empty($det->description))
						$rTasksLib[] = strtok($det->description, "\n");
					else $rTasksLib[] = 'Task';

					if (!empty($det->description))
						$rTasksDesc[] = $det->description;
					elseif (!empty($det->prod_description))
						$rTasksDesc[] = $det->prod_description;
					else $rTasksDesc[] = '';

					if (!empty($det->cdav_duration))
						$rTasksDuree[] = $det->cdav_duration;
					elseif (!empty($det->prod_duration))
						$rTasksDuree[] = $det->prod_duration;
					else $rTasksDuree[] = '';
				}
				unset($det);
				$db->free($querydet);
			}
			$db->free($query);
			$sql = "SELECT fk_socpeople FROM llx_element_contact WHERE fk_c_type_contact = " . intval($CDAV_PROJ_USER_ROLE) . " AND element_id = " . intval($object->id);
			$querydet = $db->query($sql);
			if ($querydet && ($row = $db->fetch_object($querydet)) !== null)
				$task_user = $row->fk_socpeople;
			else $task_user = $user->id;
			$db->free($querydet);
			// echo "\nrTasksLib ";
			// print_r($rTasksLib);


			if (count($rTasksLib) > 0 || $bDocs) { // create pre & post tasks even if no service in docs
				// retrieve n post-tasks [0-3]
				$i = 0;
				while (++$i <= 3) {
					if (isset(${'CDAV_GENTASK_END' . $i}) && ${'CDAV_GENTASK_END' . $i} > 0) {
						// Found CDAV_GENTASK_END$i ==> ${'CDAV_GENTASK_END' . $i}
						$sqldet = "SELECT label, description, duration FROM " . MAIN_DB_PREFIX . "product WHERE rowid = " . intval(${'CDAV_GENTASK_END' . $i});
						$querydet = $db->query($sqldet);
						while ($querydet && ($det = $db->fetch_object($querydet)) !== null) {
							$rPostTasksLib[] = $det->label;
							$rPostTasksDesc[] = $det->description;
							$rPostTasksDuree[] = $det->duration;
						}
						$db->free($querydet);
						unset($det);
					}
				}

				// merging pre & post tasks
				$rTasksLib = array_merge_recursive($rPreTasksLib, $rTasksLib, $rPostTasksLib);
				$rTasksDesc = array_merge_recursive($rPreTasksDesc, $rTasksDesc, $rPostTasksDesc);
				$rTasksDuree = array_merge_recursive($rPreTasksDuree, $rTasksDuree, $rPostTasksDuree);

				//echo "\nrTasksDuree ";
				//print_r($rTasksDuree);
				//exit;

				// creating tasks

				$rechI = '/[ ]*([0123456789]*)[ ]*[i|min]/i';
				$rechH = '/[ ]*([0123456789]*)[ ]*h/i';
				$rechJ = '/[ ]*([0123456789]*)[ ]*[j|d|t]/i';
				$rechS = '/[ ]*([0123456789]*)[ ]*[s|w]/i';
				$hIni = 7;
				$hEnd = 19;
				if (!empty($CDAV_TASK_HOUR_INI))
					$hIni = $CDAV_TASK_HOUR_INI;
				if (!empty($CDAV_TASK_HOUR_END))
					$hEnd = $CDAV_TASK_HOUR_END;

				if (intval($hEnd) < intval($hIni)) {
					$hTmp = $hIni;
					$hIni = $hEnd;
					$hEnd = $hTmp;
				}

				foreach ($rTasksLib as $taskid => $label) {
					$defaultref = '';
					$obj = empty($conf->global->PROJECT_TASK_ADDON) ? 'mod_task_simple' : $conf->global->PROJECT_TASK_ADDON;
					if (!empty($conf->global->PROJECT_TASK_ADDON) && is_readable(DOL_DOCUMENT_ROOT . "/core/modules/project/task/" . $conf->global->PROJECT_TASK_ADDON . ".php")) {
						require_once DOL_DOCUMENT_ROOT . "/core/modules/project/task/" . $conf->global->PROJECT_TASK_ADDON . '.php';
						$modTask = new $obj;
						$defaultref = $modTask->getNextValue($object->thirdparty, null);
					}
					if (is_numeric($defaultref) && $defaultref <= 0) $defaultref = '';

					$label = trim($label);
					$desc = trim(strip_tags($rTasksDesc[$taskid]));

					if (empty($label)) {
						$descLines = explode("\n", $desc);
						$label = trim($descLines[0]);
					}

					if (preg_match_all($rechI, $rTasksDuree[$taskid], $out0))
						$task_duration = intval($out0[1][0]) * 60;
					elseif (preg_match_all($rechH, $rTasksDuree[$taskid], $out1)) {
						//if( $hIni + intval($out1[1][0]) <= 24 )
						$task_duration = intval($out1[1][0]) * 3600;
						//else // too many hour for a single day, convert hours in working days after rounding
						//	$task_duration = (intval($hEnd)-intval($hIni))*3600 + ( (round(intval($out[1][0])/($hEnd-$hIni))-1)*3600*24 );
					} elseif (preg_match_all($rechJ, $rTasksDuree[$taskid], $out2))
						$task_duration = (intval($hEnd) - intval($hIni)) * 3600 + ((intval($out2[1][0]) - 1) * 3600 * 24);
					elseif (preg_match_all($rechS, $rTasksDuree[$taskid], $out3))
						$task_duration = (intval($hEnd) - intval($hIni)) * 3600 + (((intval($out3[1][0])) * 3600 * 24 * 7) - 24 * 3600);
					else $task_duration = 3600;

					$task = new Task($db);
					$task->ref = $defaultref;
					$task->fk_task_parent = 0;
					$task->fk_project = intval($object->id);
					$task->label = $label;
					$task->description = $desc;
					$task->fk_statut = 0;
					$task->date_c = time();
					$task->date_start = $date_start + intval($hIni) * 3600;
					$task->date_end = $date_start + intval($hIni) * 3600 + $task_duration;
					$task->fk_user_creat = $user->id;
					$task->fk_user_valid = $user->id;

					$task_id = $task->create($user);
					// echo "Task ";
					// print_r($task);

					if ($task_id > 0) {
						$sql = "INSERT INTO " . MAIN_DB_PREFIX . "element_contact (`datecreate`, `statut`, `element_id`, `fk_c_type_contact`, `fk_socpeople` )
							VALUES (
								NOW(),
								4,
								" . (int) $task_id . ",
								" . (int) $CDAV_TASK_USER_ROLE . ",
								" . (int) $task_user . "
							)";
					}
					$db->query($sql);

					/*$ref = "TK".date("ym")."-".$tasknum;
					$sql = "INSERT INTO ".MAIN_DB_PREFIX."projet_task (`ref`, `entity`, `fk_projet`, `datec`, `label`, `description`, ``, ``, ``, ``, ``)
							VALUES (
								,
								,
							)";*/

					//$db->query($sql);
				}
			}
		}


		/*
		if (! $error)
		{
			$this->results = array('myreturn' => $myvalue);
			$this->resprints = 'A text to show';
			return 0; // or return 1 to replace standard code
		}
		else
		{
			$this->errors[] = 'Error message';
			return -1;
		}
		*/
		return 0;
	}


	/**
	 * Overloading the formObjectOptions function : replacing the parent's function with the one below
	 *
	 * @param   array           $parameters     Hook metadatas (context, etc...)
	 * @param   CommonObject    $object         The object to process (an invoice if you are in invoice module, a propale in propale's module, etc...)
	 * @param   string          $action         Current action (if set). Generally create or edit or null
	 * @return  int                             < 0 on error, 0 on success, 1 to replace standard code
	 */
	public function formObjectOptions($parameters, &$object, &$action)
	{
		global $user, $conf, $db;

		// echo "formObjectOptions parameters: ";
		// print_r($parameters);
		// echo "formObjectOptions object: ";
		// print_r($object);
		// echo "formObjectOptions action: ";
		// print_r($action);

		if ($parameters['currentcontext'] == 'projecttaskscard' && ! empty($parameters['id'])) {
			$sql = 'SELECT pt.rowid, us.color, us.login, us.firstname, us.lastname
				FROM ' . MAIN_DB_PREFIX . 'projet_task AS pt
				LEFT JOIN ' . MAIN_DB_PREFIX . 'element_contact as ec ON (ec.element_id=pt.rowid)
				LEFT JOIN ' . MAIN_DB_PREFIX . 'user as us ON (us.rowid=ec.fk_socpeople)
				LEFT JOIN ' . MAIN_DB_PREFIX . 'c_type_contact as tc ON (tc.rowid=ec.fk_c_type_contact AND tc.element="project_task" AND tc.source="internal")
				WHERE tc.element="project_task" AND tc.source="internal" AND us.login IS NOT NULL
				AND pt.fk_projet=' . intval($parameters['id']) . ' AND pt.entity IN (' . getEntity('societe', 1) . ')
				ORDER BY pt.rowid, us.login';
			$result = $db->query($sql);
			echo "\n<script>\n$(function() {\n";
			while ($result && ($res = $db->fetch_object($result)) !== null) {
				echo "$('tr#row-" . $res->rowid . " td:first-child').append('&nbsp<span class=\"fa fa-user\" style=\"padding:1px 3px; border:#000 solid 1px;color:" . ($res->color != '' ? '#' . $res->color : 'inherit') . ";\" alt=\"" . dol_htmlentities($res->login) . "\" title=\"" . dol_htmlentities($res->login) . "\"></span>');\n";
			}
			echo "});\n</script>\n";
		}
	}
}
