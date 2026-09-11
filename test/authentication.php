<?php
// Run the server admission block with simulated native authentication and Multicompany.
define('DOL_VERSION', '24.0.0');
define('MAIN_DB_PREFIX', 'test_');
$scenario = $argv[1];
$conf = (object) array('entity' => 2);
$_SERVER['PHP_AUTH_USER'] = 'central-user'; $_SERVER['PHP_AUTH_PW'] = 'secret:with:colons';
$dolibarr_main_authentication = ' dolibarr,googlerecaptcha ';
$rightsLoaded = false; $authTarget = 0;
function isModEnabled($module) { return true; }
function getDolGlobalInt($key) { return 1; }
function checkLoginPassEntity($login, $password, $entity, $modes, $context) {
	global $authTarget, $scenario;
	if ($password !== 'secret:with:colons' || $modes !== array('dolibarr') || $context !== 'dav') throw new RuntimeException('Native authentication arguments');
	$authTarget = $entity;
	return $scenario === 'bad-password' ? '' : $login;
}
class User {
	public $id = 7; public $socid = 0; public $societe_id = 0;
	public function __construct($db) {}
	public function fetch($id) { return 1; }
	public function loadRights($module, $force) { global $rightsLoaded, $conf; $rightsLoaded = $force === 1 && $conf->entity === 2; }
}
class AdmissionDb {
	public function escape($value) { return $value; }
	public function query($sql) { if (strpos($sql, 'entity IN (0,1)') === false) throw new RuntimeException('Central account lookup'); return true; }
	public function num_rows($result) { global $scenario; return $scenario === 'ambiguous' ? 2 : 1; }
	public function fetch_object($result) { return (object) array('rowid' => 7); }
}
class AdmissionMc {
	public function checkRight($id, $entity) {
		global $scenario;
		if ($id !== 7 || $entity !== 2) throw new RuntimeException('Wrong entity admission');
		return array('allowed' => 0, 'denied' => -1, 'false' => false, 'null' => null)[$scenario] ?? -1;
	}
}
$db = new AdmissionDb(); $mc = new AdmissionMc();
$source = file_get_contents(__DIR__.'/../server.php');
$start = strpos($source, '$username = isset');
$end = strpos($source, '$cdavLib = new CdavLib', $start);
if ($start === false || $end === false) throw new RuntimeException('Admission block not found');
register_shutdown_function(static function () use ($scenario) {
	global $rightsLoaded, $authTarget;
	$accepted = http_response_code() !== 401 && $rightsLoaded;
	if ($accepted !== ($scenario === 'allowed') || $authTarget !== 2) { fwrite(STDERR, 'Entity admission outcome mismatch'); exit(1); }
	echo "ADMISSION_CHECK_PASSED\n";
});
eval(substr($source, $start, $end - $start));
