<?php
require __DIR__.'/../lib/cdav_request.lib.php';
$valid = array('/erp-documents/a.pdf' => array(1, ''), '' => array(1, ''), '/calendars/a' => array(1, ''), '/2/calendars/a' => array(2, '/2'), '/1/addressbooks' => array(1, '/1'));
foreach ($valid as $path => $expected) {
	$result = cdavParseDavRoute(array('PATH_INFO' => $path));
	if (array_values($result) !== $expected) throw new RuntimeException($path);
}
foreach (array('/0/calendars', '/-1', '/2x', '/02', '/999999999999', '/%32/calendars') as $path) {
	try { cdavParseDavRoute(array('PATH_INFO' => $path)); } catch (InvalidArgumentException $e) { continue; }
	throw new RuntimeException('Accepted malformed entity '.$path);
}
$result = cdavParseDavRoute(array('SCRIPT_NAME' => '/custom/cdav/server.php', 'REQUEST_URI' => '/custom/cdav/server.php/2/calendars?entity=7'));
if ($result['entity'] !== 2) throw new RuntimeException('Query override');
echo "12 routing checks passed.\n";
