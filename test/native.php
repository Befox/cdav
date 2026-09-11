<?php
// Transaction and ownership simulations. No database or Multicompany instance is used.
$sabreRoot = $argv[1] ?? dirname(__DIR__, 2).'/dolibarr/htdocs';
require $sabreRoot.'/includes/sabre/autoload.php';
$fixtureRoot = dirname(__DIR__).'/.test-cache/native';
foreach (array('comm/action/class/actioncomm.class.php', 'projet/class/task.class.php', 'projet/class/project.class.php', 'fichinter/class/fichinter.class.php', 'contact/class/contact.class.php', 'societe/class/societe.class.php', 'adherents/class/adherent.class.php') as $relative) {
	$file = $fixtureRoot.'/'.$relative;
	if (!is_dir(dirname($file))) mkdir(dirname($file), 0700, true);
	file_put_contents($file, "<?php // Isolated fixture; classes are declared by test/native.php.\n");
}
define('DOL_DOCUMENT_ROOT', $fixtureRoot);
define('MAIN_DB_PREFIX', 'test_');
define('CDAV_URI_KEY', 'testkey');
define('CDAV_CONTACT_TAG', 0);
define('CDAV_TASK_USER_ROLE', 10);
define('CDAV_INTERV_USER_ROLE', 20);
function getEntity($element, $sharing = 1) { return '2,3'; }
function isModEnabled($module) { return true; }
class CDavCompatibility { public static function isFeatureAvailable($feature) { return true; } }
class NativeTestUser {
	public $id = 7;
	public $admin = 1;
	public $allow = true;
	public function hasRight(...$args) { return $this->allow; }
}
class NativeTestLang { public function transnoentities($key) { return $key; } }
class NativeTestDb {
	public $failMetadata = false;
	public $queries = array();
	public $commits = 0;
	public $rollbacks = 0;
	public $snapshot = array();
	public function begin() { $this->snapshot = NativeTestObject::$stored; }
	public function commit() { $this->commits++; }
	public function rollback() { $this->rollbacks++; NativeTestObject::$stored = $this->snapshot; }
	public function escape($value) { return addslashes($value); }
	public function sanitize($value) { return $value; }
	public function query($sql) {
		$this->queries[] = $sql;
		if ($this->failMetadata && strpos($sql, 'INSERT INTO test_') === 0) return false;
		$rows = array();
		if (strpos($sql, 'GET_LOCK') !== false) $rows[] = (object) array('locked' => 1);
		if (strpos($sql, 'c_actioncomm') !== false || strpos($sql, 'adherent_type') !== false || strpos($sql, 'c_type_contact') !== false) $rows[] = (object) array('id' => 1, 'rowid' => 1);
		return (object) array('rows' => $rows);
	}
	public function fetch_object($result) { return array_shift($result->rows) ?? false; }
	public function num_rows($result) { return count($result->rows); }
}
/** Native objects are simulated; their methods record the contract exercised by CDav. */
class NativeTestObject {
	public static $stored = array();
	public static $nextId = 100;
	public $id = 0;
	public $entity = 2;
	public $error = '';
	public $socialnetworks = array();
	public $oldcopy;
	public $userownerid = 7;
	public $userassigned = array(7 => array('id' => 7, 'answer_status' => 2));
	public $fk_project = 50;
	public $fk_fichinter = 50;
	public $contacts = array();
	private $data = array();
	public function __construct($db) {}
	public function __set($key, $value) { $this->data[$key] = $value; }
	public function __get($key) { return $this->data[$key] ?? null; }
	public function fetch($id) {
		if (!isset(self::$stored[$id])) return 0;
		foreach (get_object_vars(self::$stored[$id]) as $key => $value) $this->{$key} = $value;
		return 1;
	}
	public function create($user) { $this->id = self::$nextId++; self::$stored[$this->id] = clone $this; return $this->id; }
	public function update(...$args) { self::$stored[$this->id] = clone $this; return 1; }
	public function add_commercial($user, $id) { return 1; }
	public function liste_contact(...$args) { return $this->contacts; }
	public function add_contact($id, $role, $source) { $this->contacts[] = array('id' => $id, 'fk_c_type_contact' => $role); return 1; }
}
class ActionComm extends NativeTestObject {}
class Task extends NativeTestObject {}
class Project extends NativeTestObject {}
class Fichinter extends NativeTestObject {}
class FichinterLigne extends NativeTestObject {}
class Contact extends NativeTestObject {}
class Societe extends NativeTestObject {}
class Adherent extends NativeTestObject {}
require __DIR__.'/../class/CardDAVNativeOperations.php';
require __DIR__.'/../class/CalDAVNativeOperations.php';
class NativeTestBackend {
	use \Sabre\CardDAV\Backend\CardDAVNativeOperations;
	use \Sabre\CalDAV\Backend\CalDAVNativeOperations;
	public $db;
	public $user;
	public $langs;
	public function __construct($db, $user) { $this->db = $db; $this->user = $user; $this->langs = new NativeTestLang(); }
	public function card($uri, $values, $id = null) { return $this->saveCard('ct', $uri, $values, $id); }
	public function calendar($uri, $values, $existing = null) { return $this->persistCalendarObject(7, $uri, $values, $existing, false); }
	protected function _parseDataContact($values, $mode) { return $values; }
}
$conf = (object) array('entity' => 2);
$db = new NativeTestDb(); $user = new NativeTestUser(); $backend = new NativeTestBackend($db, $user);
$checks = 0;
function verify($condition, $label) { global $checks; $checks++; if (!$condition) throw new RuntimeException($label); }
function refused($operation, $exception) {
	try { $operation(); } catch (Throwable $error) { verify($error instanceof $exception, get_class($error).': '.$error->getMessage()); return; }
	throw new RuntimeException('Operation should have been refused');
}
$card = array('_uid' => 'external-contact', 'lastname' => 'Müller', 'priv' => 1);
$backend->card('contact.vcf', $card);
$id = NativeTestObject::$nextId - 1;
verify(NativeTestObject::$stored[$id]->entity === 2 && NativeTestObject::$stored[$id]->lastname === 'Müller', 'Contact creation belongs to target');
verify(NativeTestObject::$stored[$id]->priv === 1, 'Private contact remains private');
$db->failMetadata = true; $count = count(NativeTestObject::$stored);
refused(static function () use ($backend, $card) { $backend->card('failure.vcf', $card); }, \Sabre\DAV\Exception\Conflict::class);
verify(count(NativeTestObject::$stored) === $count && $db->rollbacks === 1, 'Metadata failure rolls back native creation');
$db->failMetadata = false;
$shared = new Contact($db); $shared->id = 60; $shared->entity = 3; NativeTestObject::$stored[60] = $shared;
$backend->card('60-ct-testkey', array('_uid' => '60-ct-testkey', 'lastname' => 'Updated'), 60);
verify(NativeTestObject::$stored[60]->entity === 3, 'Shared contact keeps owner');
refused(static function () use ($backend) { $backend->card('60-ct-testkey', array('_uid' => 'other'), 60); }, \Sabre\DAV\Exception\Conflict::class);
$outside = new Contact($db); $outside->id = 61; $outside->entity = 4; NativeTestObject::$stored[61] = $outside;
refused(static function () use ($backend) { $backend->card('61-ct-testkey', array('_uid' => '61-ct-testkey'), 61); }, \Sabre\DAV\Exception\Forbidden::class);
$event = array('uid' => 'same-uid', 'componentType' => 'VEVENT', 'start' => 1000, 'end' => 2000, 'priority' => 5, 'percent' => -1, 'occurences' => array(), 'label' => 'Meeting', 'fullday' => false, 'location' => '', 'transparency' => 0, 'note' => '');
$backend->calendar('external.ics', $event);
$id = NativeTestObject::$nextId - 1;
verify(NativeTestObject::$stored[$id]->entity === 2, 'Event target entity');
verify(strpos(implode('\n', $db->queries), "a.entity=2 AND c.uuidext='external.ics'") !== false, 'External URI lookup is entity-local');
$eventObject = NativeTestObject::$stored[$id]; $eventObject->entity = 3; NativeTestObject::$stored[$id] = $eventObject;
$backend->calendar($id.'-ev-testkey', $event, array('id' => $id, 'source' => 'ev'));
verify(NativeTestObject::$stored[$id]->entity === 3, 'Shared event retains owner');
verify(NativeTestObject::$stored[$id]->userassigned[7]['answer_status'] === 2, 'Native invitation answer retained');
$user->allow = false;
refused(static function () use ($backend, $event) { $backend->calendar('denied.ics', $event); }, \Sabre\DAV\Exception\Forbidden::class);
$user->allow = true; $db->failMetadata = true; $count = count(NativeTestObject::$stored);
refused(static function () use ($backend, $event) { $backend->calendar('rollback.ics', $event); }, \Sabre\DAV\Exception::class);
verify(count(NativeTestObject::$stored) === $count, 'Event rollback restores native writes');
verify(strpos(end($db->queries), 'RELEASE_LOCK') !== false, 'Lock released after rollback');
echo "$checks native persistence checks passed (simulated).\n";
