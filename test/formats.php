<?php
// Real bundled Sabre parsers/serializers, simulated Dolibarr data and translations.
define('DOL_DOCUMENT_ROOT', $argv[1]);
define('CDAV_URI_KEY', 'stable');
define('MAIN_DB_PREFIX', 'test_');
require DOL_DOCUMENT_ROOT.'/includes/sabre/autoload.php';
require __DIR__.'/../lib/cdav.lib.php';
require __DIR__.'/../class/CardDAVDolibarr.php';
function dol_buildpath($url, $mode) { return 'https://erp.example.test'.$url; }
function isModEnabled($name) { return false; }
function getDolGlobalInt($name, $default = 0) { return $default; }
class FormatLang { public function load($file) {} public function transnoentities($key) { return 'Contact'; } }
class FormatDb { public function query($sql) { return false; } }
class FormatCards extends \Sabre\CardDAV\Backend\Dolibarr {
	public function parse($body) { return $this->_parseDataContact($body, 'C'); }
	public function parseThirdparty($body) { return $this->_parseDataThirdparty($body, 'C'); }
}
$cards = new FormatCards((object) array('id' => 1), new FormatDb(), new FormatLang());
// Ignore deprecations inside historical Sabre itself, never those in CDav.
set_error_handler(static function ($code, $message, $file, $line) {
	if (strpos(str_replace('\\', '/', $file), '/includes/sabre/') !== false && ($code === E_DEPRECATED || ($code === E_WARNING && strpos($message, '"continue" targeting switch') !== false))) return false;
	throw new ErrorException($message, 0, $code, $file, $line);
});
$checks = 0;
function checkFormat($value, $label) { global $checks; $checks++; if (!$value) throw new RuntimeException($label); }
$card = "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:stable-card\r\nFN:Élodie Müller\r\nTEL:+33123456789\r\nNOTE:une ligne\\ndeux lignes\r\nEND:VCARD\r\n";
$parsed = $cards->parse($card);
checkFormat($parsed['lastname'] === 'Élodie Müller', 'FN without N');
checkFormat($parsed['_uid'] === 'stable-card', 'Technical UID');
checkFormat($parsed['note_public'] === "une ligne\ndeux lignes", 'Native note decoding must not replace every letter n');
$card4 = str_replace('VERSION:3.0', 'VERSION:4.0', $card);
checkFormat($cards->parse($card4)['_uid'] === 'stable-card', 'vCard 4 conversion');
$unnamed = $cards->parseThirdparty("BEGIN:VCARD\r\nVERSION:3.0\r\nUID:unnamed\r\nFN:\r\nEND:VCARD\r\n");
checkFormat($unnamed['nom'] !== '' && !isset($unnamed['lastname']), 'Unnamed third party has a label without contact fields');
$lib = new CdavLib(null, null, new FormatLang());
$row = (object) array('elem_source' => 'ev', 'id' => 4, 'percent' => -1, 'label' => "Événement, test; \\ chemin\nEND:VEVENT\nBEGIN:VEVENT", 'sourceuid' => 'stable-event', 'datep' => '2026-03-29 00:00:00', 'datep2' => '2026-03-29 23:59:59', 'datec' => '2026-03-01 10:00:00', 'lastupd' => '2026-03-02 10:00:00', 'fulldayevent' => 1, 'location' => null, 'address' => null, 'note' => null);
date_default_timezone_set('Europe/Paris');
$body = $lib->toVCalendar(1, $row, true);
$calendar = \Sabre\VObject\Reader::read($body);
checkFormat(count($calendar->select('VEVENT')) === 1, 'Text cannot inject a calendar component');
checkFormat((string) $calendar->VEVENT->SUMMARY === $row->label, 'Accents, separators, backslash and newlines round trip');
checkFormat((string) $calendar->VEVENT->DTEND === '20260330', 'All-day exclusive end across DST');
checkFormat($body === $lib->toVCalendar(1, $row, true), 'Stable bytes and ETag');
checkFormat(strpos($body, "\r\n") !== false, 'RFC line endings');
$row->fulldayevent = 0;
$calendar = \Sabre\VObject\Reader::read($lib->toVCalendar(1, $row, true));
checkFormat($calendar->VEVENT->DTSTART->getDateTime()->getTimestamp() === strtotime($row->datep), 'UTC conversion preserves instant');
$row->elem_source = 'pt'; $row->dateo = null; $row->datee = null; $row->progress = 0;
$row->proj_title = 'Projet'; $row->description = null;
$calendar = \Sabre\VObject\Reader::read($lib->toVCalendar(1, $row, true));
checkFormat(isset($calendar->VTODO) && !isset($calendar->VTODO->DTSTART), 'Undated task');
checkFormat((string) $calendar->VTODO->UID === '4-pt-stable', 'Task UID unchanged');
$row->elem_source = 'fi'; $row->det_date = '2026-03-29 10:00:00'; $row->det_duree = 0;
$row->det_description = ''; $row->fi_ref = 'FI-test'; $row->fi_description = ''; $row->soc_nom = '';
$calendar = \Sabre\VObject\Reader::read($lib->toVCalendar(1, $row, true));
checkFormat($calendar->VEVENT->DTEND->getDateTime()->getTimestamp() - $calendar->VEVENT->DTSTART->getDateTime()->getTimestamp() === 3600, 'Unspecified intervention duration retains one-hour default');
echo "$checks format checks passed (real Sabre, simulated rows).\n";
