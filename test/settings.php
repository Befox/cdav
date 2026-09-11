<?php
// Isolated validation tests: these do not certify a running Dolibarr instance.
define('MAIN_DB_PREFIX', 'test_');
class Categorie { const TYPE_CONTACT = 4; const TYPE_PRODUCT = 0; }
class TestLang { function trans($key, ...$args) { return $key.implode(' ', $args); } }
class TestDb {
	public $exists = true;
	public $queries = array();
	function sanitize($v) { return $v; }
	function escape($v) { return addslashes($v); }
	function query($v) { $this->queries[] = $v; return true; }
	function fetch_object($res) { return $this->exists ? (object) array('rowid' => 1) : false; }
}
$disabled = array();
function isModEnabled($name) { global $disabled; return !in_array($name, $disabled, true); }
function getDolGlobalString($name, $default = '') { return $default; }
function getEntity($name) { return '2,3'; }
require __DIR__.'/../lib/cdav_admin.lib.php';
$langs = new TestLang(); $db = new TestDb(); $checks = 0;
function check($condition, $label) { global $checks; $checks++; if (!$condition) throw new RuntimeException($label); }
check(!cdavValidateSettings($db, 'setup', array('CDAV_URI_KEY' => 'abcd_123')), 'valid key');
check(count(cdavValidateSettings($db, 'setup', array('CDAV_URI_KEY' => '<script>'))) === 1, 'invalid key');
check(!cdavValidateSettings($db, 'caldav', array('CDAV_SYNC_PAST' => '0', 'CDAV_SYNC_FUTURE' => '0')), 'zero days');
check(!cdavValidateSettings($db, 'caldav', array('CDAV_TASK_USER_ROLE' => '', 'CDAV_GENTASK_INI1' => '0')), 'empty and zero selectors');
check(count(cdavValidateSettings($db, 'caldav', array('CDAV_SYNC_PAST' => '-1'))) === 1, 'negative days');
check(count(cdavValidateSettings($db, 'caldav', array('CDAV_TASK_HOUR_INI' => '19', 'CDAV_TASK_HOUR_END' => '7'))) === 1, 'reversed hours');
check(count(cdavValidateSettings($db, 'carddav', array('CDAV_THIRD_SYNC' => '3'))) === 1, 'enum validation');
check(!cdavValidateSettings($db, 'caldav', array('CDAV_TASK_USER_ROLE' => '10')), 'existing role');
check(strpos(end($db->queries), "element = 'project_task'") !== false, 'role element');
check(!cdavValidateSettings($db, 'caldav', array('CDAV_GENTASK_INI1' => '5')), 'existing service');
check(strpos(end($db->queries), 'entity IN (2,3)') !== false && strpos(end($db->queries), 'fk_product_type = 1') !== false, 'service scope/type');
$db->exists = false;
check(count(cdavValidateSettings($db, 'carddav', array('CDAV_CONTACT_TAG' => '999'))) === 1, 'forged category');
$disabled = array('project');
check(count(cdavValidateSettings($db, 'caldav', array('CDAV_TASK_SYNC' => '1'))) === 1, 'disabled dependency');
$all = array_merge(array_keys(cdavSettingsDefinition('setup')), array_keys(cdavSettingsDefinition('carddav')), array_keys(cdavSettingsDefinition('caldav')));
check(count($all) === count(array_unique($all)) && count($all) === 24, 'independent tabs retain all 24 constants');
check(!cdavValidateSettings($db, 'carddav', array('CDAV_CONTACT_SYNC_CIVILITY' => '0')), 'civility sync disabled');
check(!cdavValidateSettings($db, 'carddav', array('CDAV_CONTACT_SYNC_CIVILITY' => '1')), 'civility sync enabled');
check(count(cdavValidateSettings($db, 'carddav', array('CDAV_CONTACT_SYNC_CIVILITY' => '2'))) === 1, 'invalid civility switch');
echo "$checks settings checks passed (simulated).\n";
