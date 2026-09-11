<?php
$root = $argv[1] ?? '';
if (!is_dir($root)) throw new RuntimeException('Pass a Dolibarr htdocs directory');
define('DOL_DOCUMENT_ROOT', $root);
require $root.'/includes/sabre/autoload.php';
require __DIR__.'/../class/PrincipalsDolibarr.php';
require __DIR__.'/../class/CardDAVDolibarr.php';
require __DIR__.'/../class/CalDAVDolibarr.php';
require __DIR__.'/../class/CDavDirectory.php';
echo "Sabre backend and filesystem signatures load successfully.\n";
