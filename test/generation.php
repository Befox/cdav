<?php
// Reproducible generation tests with simulated native objects and database.
define('DOL_VERSION', '16.0.0');
define('MAIN_DB_PREFIX', 'test_');
$root = dirname(__DIR__).'/.test-cache/generation';
foreach (array('projet/class/task.class.php', 'product/class/product.class.php', 'core/lib/date.lib.php', 'core/modules/project/task/mod_task_simple.php') as $relative) {
	$file = $root.'/'.$relative;
	if (!is_dir(dirname($file))) mkdir(dirname($file), 0700, true);
	file_put_contents($file, '<?php // Fixture only');
}
define('DOL_DOCUMENT_ROOT', $root);
$values = array('CDAV_GENTASK' => 1, 'CDAV_GENTASK_INI1' => 3, 'CDAV_GENTASK_END1' => 4, 'CDAV_TASK_USER_ROLE' => 10, 'CDAV_TASK_HOUR_INI' => 0, 'CDAV_TASK_HOUR_END' => 19);
function getDolGlobalInt($name, $default = 0) { global $values; return (int) ($values[$name] ?? $default); }
function getDolGlobalString($name, $default = '') { global $values; return (string) ($values[$name] ?? $default); }
function isModEnabled($name) { return $name !== 'multicompany' && $name !== 'propal'; }
function getEntity($name, $sharing = 1) { return '2,3'; }
function dol_now() { return 100000; }
function dol_getdate($value) { return getdate($value); }
function dol_mktime($h, $i, $s, $m, $d, $y) { return mktime($h, $i, $s, $m, $d, $y); }
function dol_time_plus_duree($start, $value, $unit) { return $start + $value * (array('min' => 60, 'mn' => 60, 'i' => 60, 'h' => 3600, 'd' => 86400)[$unit]); }
function dol_syslog($message, $level) {}
class GenerationLang { public function loadLangs($values) {} public function trans($key) { return $key; } public function transnoentities($key) { return $key; } }
class GenerationUser { public $id = 7; public $socid = 0; public $allow = true; public function hasRight(...$right) { return $this->allow; } }
class Project {
	public $id = 50; public $entity = 2; public $date_start = 100000;
	public function getProjectsAuthorizedForUser(...$args) { return '50'; }
	public function liste_contact(...$args) { return array(); }
}
class Product {
	public $type = 1; public $entity = 3; public $label = 'Configured service'; public $description = ''; public $duration = '1h';
	public function __construct($db) {} public function fetch($id) { return 1; }
}
class Task {
	public static $saved = array(); public static $failAssignment = false;
	public $entity; public $fk_project; public $fk_task_parent; public $fk_statut; public $date_c; public $label; public $description; public $date_start; public $date_end; public $ref;
	public function __construct($db) {}
	public function create($user) { self::$saved[] = clone $this; return count(self::$saved); }
	public function add_contact(...$args) { return self::$failAssignment ? -1 : 1; }
}
class mod_task_simple { public function getNextValue($thirdparty, $task) { return 'TASK-'.count(Task::$saved); } }
class GenerationDb {
	public $queries = array(); public $snapshot = array(); public $rollbackCount = 0;
	public function begin() { $this->snapshot = Task::$saved; }
	public function commit() {}
	public function rollback() { Task::$saved = $this->snapshot; $this->rollbackCount++; }
	public function sanitize($value) { return $value; }
	public function query($sql) {
		$this->queries[] = $sql; $rows = array();
		if (strpos($sql, 'FROM test_projet WHERE') !== false || strpos($sql, 'FROM test_commande WHERE') !== false || strpos($sql, 'FROM test_c_type_contact') !== false || strpos($sql, 'FROM test_user u') !== false) $rows[] = (object) array('rowid' => 1);
		if (strpos($sql, 'FROM test_projet_task WHERE') !== false && Task::$saved) $rows[] = (object) array('rowid' => 1);
		if (strpos($sql, 'FROM test_commandedet d') !== false) $rows[] = (object) array('label' => 'Source service', 'description' => '', 'service_label' => '', 'service_description' => '', 'duration' => '2h', 'cdav_duration' => null, 'fk_product' => 10);
		return (object) array('rows' => $rows);
	}
	public function fetch_object($result) { return array_shift($result->rows) ?? false; }
	public function num_rows($result) { return count($result->rows); }
}
require __DIR__.'/../class/cdavtaskgeneration.class.php';
$conf = (object) array('entity' => 2); $langs = new GenerationLang(); $db = new GenerationDb(); $user = new GenerationUser(); $project = new Project();
$generator = new CDavTaskGeneration($db); $checks = 0;
function checkGeneration($condition, $label) { global $checks; $checks++; if (!$condition) throw new RuntimeException($label); }
checkGeneration($generator->generate($project, $user) === 3, $generator->error);
checkGeneration(Task::$saved[0]->label === 'Configured service' && Task::$saved[1]->label === 'Source service', 'Initial/source/final order');
checkGeneration(Task::$saved[0]->entity === 2 && (int) date('H', Task::$saved[0]->date_start) === 0, 'Owner and zero starting hour');
checkGeneration($generator->generate($project, $user) === 0 && count(Task::$saved) === 3, 'No duplicate generation');
Task::$saved = array(); Task::$failAssignment = true;
checkGeneration($generator->generate($project, $user) === -1 && !Task::$saved && $db->rollbackCount === 1, 'Assignment failure rolls back all tasks');
Task::$failAssignment = false; $project->entity = 3;
checkGeneration($generator->generate($project, $user) === -1 && !Task::$saved, 'Shared project must use its owning entity');
$project->entity = 2; $user->allow = false;
checkGeneration($generator->generate($project, $user) === -1 && !Task::$saved, 'Functional rights required');
checkGeneration(CDavTaskGeneration::endDate(100, '0h', 0, 19) === 100, 'Zero duration');
checkGeneration(CDavTaskGeneration::endDate(100, '30min', 7, 19) === 1900, 'Minutes');
checkGeneration(CDavTaskGeneration::endDate(100, '2d', 7, 19) === 100 + 86400 + 12 * 3600, 'Working day span');
try { CDavTaskGeneration::endDate(100, 'invalid', 7, 19); throw new LogicException('Invalid duration accepted'); } catch (RuntimeException $error) { $checks++; }
$user->allow = true; $values['CDAV_TASK_HOUR_INI'] = ''; $values['CDAV_TASK_HOUR_END'] = '';
checkGeneration($generator->generate($project, $user) === 3 && (int) date('H', Task::$saved[0]->date_start) === 7, 'Empty hours retain historical working-day defaults');
echo "$checks generation checks passed (simulated).\n";
