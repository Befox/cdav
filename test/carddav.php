<?php
// Real backend/Sabre and SQL evaluation; SQLite adapts only MySQL GROUP_CONCAT syntax.
// Dolibarr permissions, entity sharing and native Contact persistence are simulated.
require $argv[1].'/includes/sabre/autoload.php';
$fixtureRoot = dirname(__DIR__).'/.test-cache/carddav';
foreach (array('contact/class/contact.class.php', 'societe/class/societe.class.php', 'adherents/class/adherent.class.php') as $relative) {
	$file = $fixtureRoot.'/'.$relative;
	if (!is_dir(dirname($file))) mkdir(dirname($file), 0700, true);
	file_put_contents($file, "<?php // Classes simulated by test/carddav.php.\n");
}
define('DOL_DOCUMENT_ROOT', $fixtureRoot);
define('MAIN_DB_PREFIX', 'test_');
define('CDAV_URI_KEY', 'stable');
define('CDAV_ADDRESSBOOK_ID_SHIFT', 100000);
define('CDAV_THIRD_SYNC', 0);
define('CDAV_MEMBER_SYNC', 0);
define('CDAV_CONTACT_TAG', (int) ($argv[2] ?? 0));
require __DIR__.'/../class/CardDAVDolibarr.php';
set_error_handler(static function ($code, $message, $file, $line) {
	if (strpos(str_replace('\\', '/', $file), '/includes/sabre/') !== false && ($code === E_DEPRECATED || ($code === E_WARNING && strpos($message, '"continue" targeting switch') !== false))) return false;
	throw new ErrorException($message, 0, $code, $file, $line);
});
$conf = (object) array('entity' => 1);
$settings = array();
$entities = array('societe' => '1', 'socpeople' => '1', 'category' => '1');
function getDolGlobalInt($key, $default = 0) { global $conf, $settings; return (int) ($settings[$conf->entity][$key] ?? $default); }
function getDolGlobalString($key, $default = '') { return $default; }
function getEntity($key, $sharing = 0) { global $entities; return $entities[$key] ?? '1'; }
function isModEnabled($key) { return $key === 'categorie'; }
class CDavCompatibility { public static function isFeatureAvailable($feature) { return in_array($feature, array('dav', 'carddav'), true); } }
class CardUser {
	public $id = 7;
	public $login = 'sales';
	public $admin = 0;
	public $permissions = array('societe.lire' => true, 'societe.contact.lire' => true, 'societe.contact.creer' => true, 'societe.contact.supprimer' => true, 'categorie.lire' => true);
	public function hasRight(...$parts) { return $this->permissions[implode('.', $parts)] ?? false; }
}
class CardLang {
	public function load($file) {}
	public function transnoentities($key) { return $key; }
	public function transnoentitiesnoconv($key) { return $key; }
}
class CardDb {
	public $pdo;
	public $failContacts = false;
	public $writes = 0;
	public function __construct() {
		$this->pdo = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
		$this->pdo->sqliteCreateFunction('GREATEST', static function ($a, $b) { return max($a, $b); }, 2);
		$this->pdo->sqliteCreateFunction('CONCAT', static function (...$parts) { return implode('', $parts); });
	}
	public function query($sql) {
		if ($this->failContacts && strpos($sql, 'socpeople') !== false) return false;
		$sql = preg_replace("/GROUP_CONCAT\(DISTINCT ([a-z_.]+) ORDER BY [a-z_.]+ ASC SEPARATOR ','\)/i", 'GROUP_CONCAT(DISTINCT $1)', $sql);
		return $this->pdo->query($sql);
	}
	public function fetch_object($result) { return $result->fetchObject(); }
	public function escape($value) { return str_replace("'", "''", (string) $value); }
	public function begin() { $this->pdo->beginTransaction(); }
	public function commit() { $this->pdo->commit(); }
	public function rollback() { $this->pdo->rollBack(); }
	public function table($name, $fields) { $this->pdo->exec('CREATE TABLE test_'.$name.' (rowid INTEGER PRIMARY KEY, '.implode(', ', array_map(static function ($field) { return $field.' TEXT'; }, $fields)).')'); }
	public function insert($table, array $values) {
		$stmt = $this->pdo->prepare('INSERT INTO test_'.$table.' ('.implode(',', array_keys($values)).') VALUES ('.implode(',', array_fill(0, count($values), '?')).')');
		$stmt->execute(array_values($values));
	}
}
class Contact {
	private $db;
	private $fields = array();
	public $id;
	public $entity = 1;
	public $socialnetworks = array();
	public $oldcopy;
	public $error = '';
	public function __construct($db) { $this->db = $db; }
	public function __set($key, $value) { $this->fields[$key] = $value; }
	public function __get($key) { return $this->fields[$key] ?? null; }
	public function fetch($id) {
		$row = $this->db->query('SELECT * FROM test_socpeople WHERE rowid='.(int) $id)->fetchObject();
		if (!$row) return 0;
		$this->id = (int) $row->rowid; $this->entity = (int) $row->entity;
		$this->fields = (array) $row;
		$this->fields['civility_code'] = $row->civility;
		return 1;
	}
	public function create($user) {
		$this->db->writes++;
		$this->db->insert('socpeople', array('entity' => $this->entity, 'lastname' => $this->lastname, 'civility' => $this->civility_code, 'priv' => $this->priv, 'fk_user_creat' => $user->id, 'statut' => 1));
		$this->id = (int) $this->db->pdo->lastInsertId();
		return $this->id;
	}
	public function update(...$args) {
		$this->db->writes++;
		$stmt = $this->db->pdo->prepare('UPDATE test_socpeople SET lastname=?, civility=?, priv=?, statut=? WHERE rowid=?');
		$stmt->execute(array($this->lastname, $this->civility_code, $this->priv, $this->statut, $this->id));
		return 1;
	}
}
class TestCards extends \Sabre\CardDAV\Backend\Dolibarr {
	public function parse($body) { return $this->_parseDataContact($body, 'U'); }
}
$db = new CardDb(); $user = new CardUser(); $cards = new TestCards($user, $db, new CardLang());
$db->table('socpeople', array('entity', 'fk_soc', 'statut', 'priv', 'fk_user_creat', 'tms', 'fk_pays', 'lastname', 'firstname', 'civility', 'poste', 'address', 'town', 'zip', 'phone', 'phone_perso', 'phone_mobile', 'fax', 'email', 'birthday', 'note_public', 'photo', 'socialnetworks'));
$db->table('societe', array('entity', 'tms', 'code_client', 'code_fournisseur', 'nom', 'name_alias', 'address', 'zip', 'town', 'phone', 'fax', 'email', 'url', 'client', 'fournisseur', 'note_private', 'note_public', 'logo', 'fk_pays'));
$db->table('societe_commerciaux', array('fk_soc', 'fk_user'));
$db->table('c_country', array('label'));
$db->table('c_socialnetworks', array('entity', 'code', 'url'));
$db->table('categorie', array('entity', 'label'));
$db->table('categorie_contact', array('fk_socpeople', 'fk_categorie'));
$db->table('cdav_card', array('entity', 'kind', 'fk_object', 'uri', 'uid'));
foreach (array(1 => array(1, 1, 0), 2 => array(1, 1, 0), 3 => array(2, 1, 0), 4 => array(1, 0, 1), 5 => array(1, 1, 1)) as $id => $values) {
	$db->insert('societe', array('rowid' => $id, 'entity' => $values[0], 'client' => $values[1], 'fournisseur' => $values[2], 'nom' => 'Company '.$id, 'tms' => '2026-09-10 10:00:00'));
	if ($id !== 2) $db->insert('societe_commerciaux', array('fk_soc' => $id, 'fk_user' => 7));
}
$db->insert('societe_commerciaux', array('fk_soc' => 1, 'fk_user' => 8));
$db->insert('categorie', array('rowid' => 5, 'entity' => 1, 'label' => 'Selected'));
foreach (array(1 => 1, 2 => 2, 3 => null, 4 => 0, 5 => 1, 6 => 1, 7 => 3, 8 => 999, 9 => 1, 10 => 1, 11 => 4, 12 => 5) as $id => $parent) {
	$db->insert('socpeople', array('rowid' => $id, 'entity' => $id === 9 ? 2 : 1, 'fk_soc' => $parent, 'statut' => $id === 10 ? 0 : 1, 'priv' => in_array($id, array(5, 6)) ? 1 : 0, 'fk_user_creat' => $id === 5 ? 8 : 7, 'tms' => $id === 3 ? '2026-09-11 10:00:00' : '2026-09-01 10:00:00', 'lastname' => 'Müller '.$id, 'firstname' => 'Élodie', 'civility' => 'MME', 'poste' => 'Architecte'));
	if ($id !== 4) $db->insert('categorie_contact', array('fk_socpeople' => $id, 'fk_categorie' => 5));
}
$db->insert('cdav_card', array('entity' => 1, 'kind' => 'ct', 'fk_object' => 2, 'uri' => 'custom.vcf', 'uid' => 'external-uid'));
$checks = 0;
function verifyCard($ok, $label) { global $checks; $checks++; if (!$ok) throw new RuntimeException($label); }
function refusedCard($fn, $type) { try { $fn(); } catch (Throwable $error) { verifyCard($error instanceof $type, get_class($error).': '.$error->getMessage()); return; } throw new RuntimeException('Expected '.$type); }
function uris($ids) { if (CDAV_CONTACT_TAG) $ids = array_diff($ids, array(4)); return array_values(array_map(static function ($id) { return $id === 2 ? 'custom.vcf' : $id.'-ct-stable'; }, $ids)); }
function listed($cards) { $uris = array_column($cards->getCards(7), 'uri'); sort($uris); return $uris; }
function expectCards($cards, $ids, $label) { $expected = uris($ids); sort($expected); verifyCard(listed($cards) === $expected, $label); }
function token($cards) { return $cards->getAddressBooksForUser('principals/sales')[0]['{http://calendarserver.org/ns/}getctag']; }
expectCards($cards, array(1, 3, 4, 6, 12), 'Commercial, privacy, entity, parent and supplier restrictions');
verifyCard($cards->getCard(7, '1-ct-stable')['id'] === 1, 'Direct allowed contact');
foreach (array('2-ct-stable', 'custom.vcf', '5-ct-stable', '7-ct-stable', '8-ct-stable', '9-ct-stable', '10-ct-stable', '11-ct-stable') as $uri) {
	verifyCard($cards->getCard(7, $uri) === false, 'Forbidden contact '.$uri);
	refusedCard(static function () use ($cards, $uri) { $cards->updateCard(7, $uri, 'invalid body'); }, \Sabre\DAV\Exception\NotFound::class);
	refusedCard(static function () use ($cards, $uri) { $cards->deleteCard(7, $uri); }, \Sabre\DAV\Exception\NotFound::class);
}
verifyCard($db->writes === 0, 'Refused operations never reach native persistence');
verifyCard(count($cards->getMultipleCards(7, array('1-ct-stable', 'custom.vcf', '8-ct-stable'))) === 1, 'Multiget filters inaccessible rows');
$before = token($cards);
$db->pdo->exec("UPDATE test_socpeople SET tms='2027-01-01' WHERE rowid=2");
verifyCard(token($cards) === $before, 'Hidden contact does not affect discovery metadata');
$db->pdo->exec('DELETE FROM test_societe_commerciaux WHERE fk_soc=1 AND fk_user=7');
verifyCard(token($cards) !== $before, 'Revocation changes ctag even with unchanged maximum timestamp');
verifyCard($cards->getCard(7, '1-ct-stable') === false, 'Revoked contact immediately inaccessible');
refusedCard(static function () use ($cards) { $cards->updateCard(7, '1-ct-stable', 'invalid body'); }, \Sabre\DAV\Exception\NotFound::class);
refusedCard(static function () use ($cards) { $cards->deleteCard(7, '1-ct-stable'); }, \Sabre\DAV\Exception\NotFound::class);
$db->insert('societe_commerciaux', array('fk_soc' => 1, 'fk_user' => 7));
$before = token($cards);
$db->pdo->exec("UPDATE test_socpeople SET tms='2026-09-02 10:00:00' WHERE rowid=1");
verifyCard(token($cards) !== $before, 'Contact update detected even below parent timestamp');
$user->permissions['societe.client.voir'] = true;
expectCards($cards, array(1, 2, 3, 4, 6, 12), 'Global extension lifts only commercial restriction');
$user->permissions['fournisseur.lire'] = true;
expectCards($cards, array(1, 2, 3, 4, 6, 11, 12), 'Supplier parent permission');
$entities['societe'] = '1,2'; $entities['socpeople'] = '1,2';
expectCards($cards, array(1, 2, 3, 4, 6, 7, 9, 11, 12), 'Independent parent and contact shares');
$user->permissions['societe.client.voir'] = false;
expectCards($cards, array(1, 3, 4, 6, 7, 9, 11, 12), 'Sharing never grants commercial access');
$user->permissions['societe.lire'] = false;
expectCards($cards, array(3, 4), 'No parent read still permits independent contacts');
$user->admin = 1; $user->permissions['societe.contact.lire'] = false;
expectCards($cards, array(), 'Administrator without functional read');
verifyCard($cards->getAddressBooksForUser('principals/sales') === array(), 'No contact collection without read');
verifyCard($cards->getCard(7, '3-ct-stable') === false, 'No direct read without functional permission');
$user->permissions['societe.contact.lire'] = true; $user->permissions['societe.lire'] = true;
verifyCard($cards->getAddressBooksForUser('principals/other') === array(), 'No foreign principal discovery');
refusedCard(static function () use ($cards) { $cards->getCards(8); }, \Sabre\DAV\Exception\Forbidden::class);
$db->failContacts = true;
refusedCard(static function () use ($cards) { token($cards); }, \Sabre\DAV\Exception\ServiceUnavailable::class);
refusedCard(static function () use ($cards) { $cards->getCards(7); }, \Sabre\DAV\Exception\ServiceUnavailable::class);
refusedCard(static function () use ($cards) { $cards->getCard(7, '1-ct-stable'); }, \Sabre\DAV\Exception\ServiceUnavailable::class);
$db->failContacts = false;
$before = token($cards);
$original = $cards->getCard(7, '1-ct-stable');
$vcard = \Sabre\VObject\Reader::read($original['carddata']);
verifyCard($vcard->N->getParts()[3] === '' && (string) $vcard->TITLE === 'Architecte', 'Default omits civility, preserves job title');
foreach (array('3.0', '4.0') as $version) {
	$body = "BEGIN:VCARD\r\nVERSION:$version\r\nUID:1-ct-stable\r\nN:Müller;Élodie;;DR;\r\nFN:Élodie Müller\r\nTITLE:Architecte\r\nEND:VCARD\r\n";
	verifyCard(!array_key_exists('civility', $cards->parse($body)), 'Disabled incoming civility '.$version);
	$cards->updateCard(7, '1-ct-stable', $body);
	verifyCard($db->query('SELECT civility FROM test_socpeople WHERE rowid=1')->fetchColumn() === 'MME', 'Native update preserves existing civility '.$version);
}
$before = token($cards);
$original = $cards->getCard(7, '1-ct-stable');
$settings[1]['CDAV_CONTACT_SYNC_CIVILITY'] = 1;
verifyCard(token($cards) !== $before, 'Setting change invalidates collection ctag');
$enabled = $cards->getCard(7, '1-ct-stable');
verifyCard($enabled['etag'] !== $original['etag'], 'Setting changes card ETag');
verifyCard(\Sabre\VObject\Reader::read($enabled['carddata'])->N->getParts()[3] === 'MME', 'Enabled exports civility');
verifyCard($cards->parse($body)['civility'] === 'DR', 'Enabled accepts incoming civility');
verifyCard($cards->parse(str_replace(';;DR;', ';;;', $body))['civility'] === '', 'Enabled accepts deliberate civility clearing');
$cards->updateCard(7, '1-ct-stable', $body);
verifyCard($db->query('SELECT civility FROM test_socpeople WHERE rowid=1')->fetchColumn() === 'DR', 'Enabled updates native civility');
$settings[1]['CDAV_CONCAT_SOCNAME_FOR_PHONE'] = 1;
$vcard = \Sabre\VObject\Reader::read($cards->getCard(7, '1-ct-stable')['carddata']);
verifyCard(strpos((string) $vcard->FN, '(Company 1)') === 0 && $vcard->N->getParts()[3] === 'DR', 'Company display does not overwrite civility prefix');
$settings[1]['CDAV_CONTACT_SYNC_CIVILITY'] = 0;
verifyCard(\Sabre\VObject\Reader::read($cards->getCard(7, '1-ct-stable')['carddata'])->N->getParts()[3] === '', 'Disabled prefix stays empty with company display');
$settings[2]['CDAV_CONTACT_SYNC_CIVILITY'] = 1; $conf->entity = 2;
verifyCard($cards->parse($body)['civility'] === 'DR', 'Other entity has independent option');
$conf->entity = 1;
verifyCard(!array_key_exists('civility', $cards->parse($body)), 'Original entity stays disabled');
if (!CDAV_CONTACT_TAG) {
	$cards->createCard(7, 'new-off.vcf', str_replace('1-ct-stable', 'new-off', $body));
	verifyCard($db->query('SELECT civility FROM test_socpeople ORDER BY rowid DESC LIMIT 1')->fetchColumn() === null, 'Creation ignores incoming civility by default');
	$settings[1]['CDAV_CONTACT_SYNC_CIVILITY'] = 1;
	$cards->createCard(7, 'new-on.vcf', str_replace('1-ct-stable', 'new-on', $body));
	verifyCard($db->query('SELECT civility FROM test_socpeople ORDER BY rowid DESC LIMIT 1')->fetchColumn() === 'DR', 'Creation imports civility when enabled');
} else {
	$before = token($cards);
	$db->pdo->exec('DELETE FROM test_categorie_contact WHERE fk_socpeople=3');
	verifyCard($cards->getCard(7, '3-ct-stable') === false && token($cards) !== $before, 'Category removal changes visibility and ctag');
}
verifyCard($cards->getChangesForAddressBook(7, 'obsolete-token', 1) === null, 'Unknown sync token requires a full rescan');
$user->permissions['societe.contact.creer'] = false;
refusedCard(static function () use ($cards, $body) { $cards->updateCard(7, '1-ct-stable', $body); }, \Sabre\DAV\Exception\Forbidden::class);
$user->permissions['societe.contact.supprimer'] = false;
refusedCard(static function () use ($cards) { $cards->deleteCard(7, '1-ct-stable'); }, \Sabre\DAV\Exception\Forbidden::class);
$user->permissions['societe.contact.supprimer'] = true;
$cards->deleteCard(7, '1-ct-stable');
verifyCard($db->query('SELECT statut FROM test_socpeople WHERE rowid=1')->fetchColumn() === '0', 'Allowed deletion uses native archival');
echo "$checks CardDAV checks passed (SQLite, real Sabre, simulated ERP; category filter ".CDAV_CONTACT_TAG.").\n";
