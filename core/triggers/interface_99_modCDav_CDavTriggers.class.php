<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';
require_once __DIR__.'/../../class/cdavtaskgeneration.class.php';

/** Listen to an existing native trigger; no custom transition code is introduced. */
class InterfaceCDavTriggers extends DolibarrTriggers
{
	public function __construct($db)
	{
		$this->db = $db;
		$this->name = preg_replace('/^Interface/i', '', get_class($this));
		$this->family = 'cdav'; $this->description = 'CDav project task generation'; $this->version = 'development'; $this->picto = 'technic';
	}
	/** @param string $action @param CommonObject $object @param User $user @param Translate $langs @param Conf $conf @return int */
	public function runTrigger($action, $object, $user, $langs, $conf)
	{
		if ($action !== 'PROJECT_VALIDATE' || !isModEnabled('cdav') || !($object instanceof Project)) return 0;
		$generation = new CDavTaskGeneration($this->db);
		$result = $generation->generate($object, $user);
		if ($result < 0) { $this->error = $generation->error; $this->errors = $generation->errors; }
		return $result < 0 ? -1 : 0;
	}
}
