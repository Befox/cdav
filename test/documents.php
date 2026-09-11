<?php
// Owner-directory failure cases. The native getMultidirOutput return is simulated.
define('DOL_VERSION', '24.0.0');
require __DIR__.'/../lib/cdav_documents.lib.php';
class DocumentLang { public function transnoentities($key) { return $key; } }
$langs = new DocumentLang();
$conf = (object) array('entity' => 2, 'societe' => (object) array('multidir_output' => array(2 => '/entity-b', 3 => '/entity-a')));
$nativeResult = '/entity-a'; $nativeCalls = 0;
function getMultidirOutput($object, $module, $force) { global $nativeResult, $nativeCalls; $nativeCalls++; return $nativeResult; }
$owner = (object) array('entity' => 3);
if (cdavDocumentRoot($owner, 'societe') !== '/entity-a') throw new RuntimeException('Owner root');
$checks = 1;
foreach (array(null, '', 'error-no-output') as $result) {
	$nativeResult = $result;
	try { cdavDocumentRoot($owner, 'societe'); throw new LogicException('Invalid native output accepted'); }
	catch (RuntimeException $error) { $checks++; }
}
$nativeResult = '/entity-b';
foreach (array(null, '', 'error-missing') as $configured) {
	$conf->societe->multidir_output[3] = $configured; $before = $nativeCalls;
	try { cdavDocumentRoot($owner, 'societe'); throw new LogicException('Fallback to consulting entity'); }
	catch (RuntimeException $error) { if ($nativeCalls !== $before) throw new LogicException('Invalid configuration reached native fallback'); $checks++; }
}
$owner->entity = 0;
try { cdavDocumentRoot($owner, 'societe'); throw new LogicException('Missing owner'); }
catch (RuntimeException $error) { $checks++; }
echo "$checks document ownership checks passed (simulated configuration/helper).\n";
