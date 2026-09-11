<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/** Runtime prerequisites. Permissions are deliberately checked by callers. */
class CDavCompatibility
{
	/** @return array<string, array{label: string, available: bool, reason: string, min_dolibarr: string, min_php: string, core_versions: string, detail: string}> */
	public static function getFeatures()
	{
		global $conf;
		$runtime = version_compare(PHP_VERSION, '8.0.0', '>=') && version_compare(DOL_VERSION, '16.0.0', '>=');
		$sabre = is_readable(DOL_DOCUMENT_ROOT.'/includes/sabre/autoload.php');
		$missingExtensions = array();
		foreach (array('dom', 'simplexml', 'mbstring', 'ctype', 'date', 'iconv', 'json', 'xml', 'xmlwriter') as $extension) {
			if (!extension_loaded($extension)) $missingExtensions[] = $extension;
		}
		$extensions = !$missingExtensions;
		$base = $runtime && $sabre && $extensions && isModEnabled('cdav');
		$directories = isset($conf->cdav->multidir_output) && is_array($conf->cdav->multidir_output)
			&& isset($conf->cdav->multidir_output[(int) $conf->entity])
			&& is_string($conf->cdav->multidir_output[(int) $conf->entity])
			&& trim($conf->cdav->multidir_output[(int) $conf->entity]) !== ''
			&& strpos($conf->cdav->multidir_output[(int) $conf->entity], 'error-') !== 0;
		$features = array(
			'runtime' => array('label' => 'CDavRuntime', 'available' => $runtime, 'reason' => 'CDavRequiresRuntime'),
			'sabre' => array('label' => 'CDavSabre', 'available' => $sabre, 'reason' => 'CDavRequiresSabre'),
			'extensions' => array('label' => 'CDavExtensions', 'available' => $extensions, 'reason' => 'CDavRequiresExtensions'),
			'dav' => array('label' => 'CDavDAV', 'available' => $base, 'reason' => 'CDavRequiresDAV'),
			'carddav' => array('label' => 'CardDAV', 'available' => $base && isModEnabled('societe'), 'reason' => 'CDavRequiresThirdparties'),
			'members' => array('label' => 'CDavMembers', 'available' => $base && isModEnabled('adherent') && getDolGlobalInt('CDAV_MEMBER_SYNC') > 0, 'reason' => 'CDavRequiresMembers'),
			'caldav' => array('label' => 'CalDAV', 'available' => $base && isModEnabled('agenda'), 'reason' => 'CDavRequiresAgenda'),
			'tasks' => array('label' => 'CDavTasks', 'available' => $base && isModEnabled('agenda') && isModEnabled('project') && !getDolGlobalInt('PROJECT_HIDE_TASKS') && getDolGlobalInt('CDAV_TASK_SYNC') > 0, 'reason' => 'CDavRequiresTasks'),
			'interventions' => array('label' => 'CDavInterventions', 'available' => $base && isModEnabled('agenda') && isModEnabled('ficheinter') && getDolGlobalInt('CDAV_INTERV_SYNC') > 0, 'reason' => 'CDavRequiresInterventions'),
			'gentask' => array('label' => 'CDavGenerateTasks', 'available' => $runtime && isModEnabled('cdav') && isModEnabled('project') && isModEnabled('service') && !getDolGlobalInt('PROJECT_HIDE_TASKS') && getDolGlobalInt('CDAV_GENTASK') > 0, 'reason' => 'CDavRequiresGeneration'),
			'photos' => array('label' => 'CDavPhotos', 'available' => $base && extension_loaded('gd') && extension_loaded('exif'), 'reason' => 'CDavRequiresGD'),
			'ics' => array('label' => 'ICS', 'available' => $base && isModEnabled('agenda') && extension_loaded('openssl') && getDolGlobalString('CDAV_URI_KEY') !== '', 'reason' => 'CDavRequiresICS'),
			'webdav_write' => array('label' => 'CDavWebDAVWrite', 'available' => $base && extension_loaded('fileinfo'), 'reason' => 'CDavRequiresFileinfo'),
			'document_helper' => array('label' => 'CDavDocumentHelper', 'available' => version_compare(DOL_VERSION, '18.0.0', '>=') && function_exists('getMultidirOutput'), 'reason' => 'CDavDocumentHelperVersions', 'min_dolibarr' => '18.0', 'core_versions' => '18–24'),
			'directories' => array('label' => 'CDavDirectories', 'available' => $directories, 'reason' => 'CDavRequiresDirectories'),
			'qrcode' => array('label' => 'CDavQRCode', 'available' => $base && extension_loaded('gd') && is_readable(DOL_DOCUMENT_ROOT.'/core/modules/barcode/doc/tcpdfbarcode.modules.php'), 'reason' => 'CDavRequiresQRCode'),
		);
		foreach ($features as &$feature) {
			$feature += array('min_dolibarr' => '16.0', 'min_php' => '8.0', 'core_versions' => '16–24', 'detail' => '');
		}
		unset($feature);
		$features['extensions']['detail'] = implode(', ', $missingExtensions);
		return $features;
	}

	/** @param string $feature Capability code. @return bool */
	public static function isFeatureAvailable($feature)
	{
		$features = self::getFeatures();
		return isset($features[$feature]) && $features[$feature]['available'];
	}
}
