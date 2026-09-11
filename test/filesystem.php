<?php
// Read-only profiles, direct file accesses and traversal checks using the real Sabre node API.
define('DOL_DOCUMENT_ROOT', $argv[1] ?? dirname(__DIR__, 2).'/dolibarr/htdocs');
require DOL_DOCUMENT_ROOT.'/includes/sabre/autoload.php';
function dol_sanitizeFileName($name) { return str_replace(array('/', '\\', '..'), '_', $name); }
function dol_osencode($path) { return $path; }
require __DIR__.'/../class/CDavDirectory.php';
set_error_handler(static function ($code, $message, $file, $line) { throw new ErrorException($message, 0, $code, $file, $line); });
class FileTestUser {
	public $admin = 1;
	public $allowRead = true;
	public $allowWrite = false;
	public function hasRight(...$right) { return $right === array('ecm', 'read') ? $this->allowRead : $this->allowWrite; }
}
$directory = dirname(__DIR__).'/.test-cache/fs';
if (!is_dir($directory)) mkdir($directory, 0700, true);
file_put_contents($directory.'/test.txt', 'test');
$user = new FileTestUser();
$node = new CDavDirectory($directory, $user);
$file = $node->getChild('test.txt');
$handle = $file->get();
if (stream_get_contents($handle) !== 'test') throw new RuntimeException('Read');
fclose($handle);
foreach (array(static function () use ($file) { $file->put('changed'); }, static function () use ($file) { $file->delete(); }, static function () use ($node) { $node->createDirectory('new'); }, static function () use ($node) { $node->getChild('../secret'); }, static function () use ($node) { $node->getChild('..\\secret'); }) as $operation) {
	try { $operation(); } catch (\Sabre\DAV\Exception\Forbidden $e) { continue; }
	throw new RuntimeException('Forbidden operation accepted');
}
$user->allowRead = false;
try { $file->get(); throw new RuntimeException('Admin bypass'); } catch (\Sabre\DAV\Exception\Forbidden $e) {}
unlink($directory.'/test.txt');
$user->allowRead = true; $user->allowWrite = true;
$subdirectory = $directory.'/guarded';
if (!is_dir($subdirectory)) mkdir($subdirectory);
file_put_contents($subdirectory.'/visible.txt', 'keep');
file_put_contents($subdirectory.'/.hidden', 'keep');
$guarded = $node->getChild('guarded');
try { $guarded->delete(); throw new RuntimeException('Hidden descendant accepted'); } catch (\Sabre\DAV\Exception\Forbidden $e) {}
if (!is_file($subdirectory.'/visible.txt') || !is_file($subdirectory.'/.hidden')) throw new RuntimeException('Partial deletion before denial');
unlink($subdirectory.'/visible.txt'); unlink($subdirectory.'/.hidden');
$guarded->delete();
if (is_dir($subdirectory)) throw new RuntimeException('Native empty-directory deletion failed');

echo "9 filesystem access checks passed (simulated user, real Sabre/native directory deletion).\n";
