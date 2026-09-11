<?php
// Execute the native main.inc.php CSRF block with a simulated session, without a database.
$source = file_get_contents($argv[1].'/main.inc.php');
$start = strpos($source, "if ((!defined('NOCSRFCHECK')");
$end = strpos($source, "\n}", $start) + 2;
if ($start === false || $end <= $start) throw new RuntimeException('Native CSRF block not found');
$block = substr($source, $start, $end - $start);
if (strpos($block, '$sessiontokenforthisurl') === false) throw new RuntimeException('Incomplete native CSRF block');
$scenario = $argv[2];
define('CSRFCHECK_WITH_TOKEN', 1);
$_SESSION = array('token' => 'fixture-token');
$_SERVER['REQUEST_METHOD'] = strpos($scenario, 'get-') === 0 ? 'GET' : 'POST';
$_SERVER['PHP_SELF'] = '/cdav/admin/setup.php';
$values = array('action' => $_SERVER['REQUEST_METHOD'] === 'GET' ? 'set_CDAV_QRCODE_DAVX5_ENABLED' : 'update');
if (strpos($scenario, 'valid') !== false) $values['token'] = 'fixture-token';
if (strpos($scenario, 'expired') !== false) $values['token'] = 'expired';
$_POST = $_SERVER['REQUEST_METHOD'] === 'POST' ? $values : array();
$_GET = $_SERVER['REQUEST_METHOD'] === 'GET' ? $values : array();
function GETPOST($key, $type = '') { return $_POST[$key] ?? $_GET[$key] ?? ''; }
function GETPOSTINT($key) { return (int) GETPOST($key); }
function GETPOSTISSET($key) { return isset($_POST[$key]) || isset($_GET[$key]); }
function getDolGlobalInt($key) { return 0; } // Even when the global setting is off.
function dol_syslog($message, $level = 0) {}
function top_httphead() {}
function setEventMessages(...$args) {}
register_shutdown_function(static function () use ($scenario) {
	$accepted = GETPOST('action') !== '' && http_response_code() !== 403;
	$expected = strpos($scenario, 'valid') !== false;
	if ($accepted !== $expected) { fwrite(STDERR, 'CSRF outcome mismatch'); exit(1); }
	echo "CSRF_CHECK_PASSED\n";
});
eval($block); // Only the selected, unmodified native block, never request data.
