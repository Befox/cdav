<?php
namespace Sabre\CardDAV\Backend;

use Sabre\DAV;
use Sabre\DAV\Exception\Forbidden;

/** Native object persistence and protocol metadata; permissions remain at each entry point. */
trait CardDAVNativeOperations
{
	/** @var array<string, array<int, array{uri: string, uid: string}>> */
	private $cardMappingCache = array();

	/** @return string Closed collection type belonging to this principal. */
	private function bookKind($bookId)
	{
		$offset = (int) $bookId - (int) $this->user->id;
		if ($offset === 0 && \CDavCompatibility::isFeatureAvailable('carddav')) return 'ct';
		if ($offset === CDAV_ADDRESSBOOK_ID_SHIFT && \CDavCompatibility::isFeatureAvailable('carddav') && CDAV_THIRD_SYNC > 0) return 'th';
		if ($offset === 2 * CDAV_ADDRESSBOOK_ID_SHIFT && \CDavCompatibility::isFeatureAvailable('members')) return 'mb';
		throw new Forbidden();
	}

	/** @return array<int, array{uri: string, uid: string}> */
	private function cardMappings($kind)
	{
		global $conf;
		if (!isset($this->cardMappingCache[$kind])) {
			$this->cardMappingCache[$kind] = array();
			$res = $this->db->query("SELECT fk_object, uri, uid FROM ".MAIN_DB_PREFIX."cdav_card WHERE entity=".(int) $conf->entity." AND kind='".$this->db->escape($kind)."'");
			if (!$res) throw new DAV\Exception('CDav metadata unavailable');
			while (is_object($row = $this->db->fetch_object($res))) {
				$this->cardMappingCache[$kind][(int) $row->fk_object] = array('uri' => $row->uri, 'uid' => $row->uid);
			}
		}
		return $this->cardMappingCache[$kind];
	}

	/** @return \Contact|\Societe|\Adherent Loaded native object or a new object in the target entity. */
	private function loadNativeCard($kind, $id)
	{
		global $conf;
		require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
		require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
		require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent.class.php';
		$object = $kind === 'ct' ? new \Contact($this->db) : ($kind === 'th' ? new \Societe($this->db) : new \Adherent($this->db));
		if ($id !== null) {
			if ($object->fetch($id) <= 0) throw new DAV\Exception\NotFound();
			$scope = $kind === 'ct' ? 'socpeople' : ($kind === 'th' ? 'societe' : 'adherent');
			if (!in_array((int) $object->entity, array_map('intval', explode(',', getEntity($scope, 1))), true)) throw new Forbidden();
		} else {
			$object->entity = (int) $conf->entity;
		}
		return $object;
	}

	/** @return null The native representation may differ from the submitted vCard, so no ETag. */
	private function saveCard($kind, $uri, $data, $id)
	{
		global $conf;
		if (!is_string($uri) || $uri === '' || strlen($uri) > 255 || strpbrk($uri, '/'.chr(92)) !== false || preg_match('/[\x00-\x20]/', $uri)) throw new DAV\Exception\BadRequest();
		$mode = $id === null ? 'C' : 'U';
		$values = $kind === 'ct' ? $this->_parseDataContact($data, $mode) : ($kind === 'th' ? $this->_parseDataThirdparty($data, $mode) : $this->_parseDataMember($data, $mode));
		if (empty($values['_uid']) || strlen($values['_uid']) > 255) throw new DAV\Exception\BadRequest('Invalid UID');
		if ($id !== null) {
			$mapping = $this->cardMappings($kind);
			$uid = isset($mapping[$id]) ? $mapping[$id]['uid'] : $id.'-'.$kind.'-'.CDAV_URI_KEY;
			if ($values['_uid'] !== $uid) throw new DAV\Exception\Conflict('UID cannot be changed');
		}
		$object = $this->loadNativeCard($kind, $id);
		$object->oldcopy = clone $object;
		$aliases = array('nom' => 'name', 'fk_pays' => 'country_id', 'country' => 'country_id', 'civility' => 'civility_code');
		$allowed = array('lastname', 'firstname', 'nom', 'name_alias', 'civility', 'poste', 'phone', 'phone_perso', 'phone_mobile', 'fax', 'email', 'url', 'address', 'town', 'zip', 'fk_pays', 'country', 'birthday', 'birth', 'note_public', 'statut', 'status', 'priv');
		foreach ($allowed as $field) {
			if (!array_key_exists($field, $values)) continue;
			$property = $aliases[$field] ?? $field;
			$object->{$property} = in_array($field, array('birthday', 'birth'), true) ? ($values[$field] === '' ? null : strtotime($values[$field])) : $values[$field];
		}
		$networks = is_array($object->socialnetworks) ? $object->socialnetworks : array();
		foreach ($values['_socialnetworks'] ?? array() as $code => $value) {
			if ($value === '') unset($networks[$code]);
			else $networks[$code] = $value;
		}
		$object->socialnetworks = $networks;
		if ($id === null && $kind === 'mb') {
			// A vCard does not supply a membership type; accept only an unambiguous native default.
			$res = $this->db->query('SELECT rowid FROM '.MAIN_DB_PREFIX.'adherent_type WHERE entity IN ('.$this->db->sanitize(getEntity('adherent_type')).')');
			if (!$res || $this->db->num_rows($res) !== 1 || !is_object($type = $this->db->fetch_object($res))) throw new DAV\Exception\Conflict($this->langs->transnoentities('CDavMemberTypeRequired'));
			$object->typeid = (int) $type->rowid;
			$object->morphy = 'phy';
			$object->statut = 1;
			$object->login = 'cdav-'.bin2hex(random_bytes(12));
		}
		$photo = $kind === 'ct' ? ($values['_photo_bin'] ?? false) : false;
		if ($photo !== false && !\CDavCompatibility::isFeatureAvailable('photos')) throw new DAV\Exception\Conflict($this->langs->transnoentities('CDavRequiresGD'));
		$this->db->begin();
		$photoPath = null;
		try {
			if ($id === null) {
				if ($object->create($this->user) <= 0) throw new DAV\Exception\Conflict($object->error);
				if ($kind === 'th' && $object->add_commercial($this->user, (int) $this->user->id) < 0) throw new DAV\Exception\Conflict($object->error);
				if ($kind === 'ct' && isModEnabled('categorie') && (int) CDAV_CONTACT_TAG > 0) {
					require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
					$category = new \Categorie($this->db);
					if ($category->fetch((int) CDAV_CONTACT_TAG) <= 0 || (int) $category->type !== \Categorie::TYPE_CONTACT
						|| !in_array((int) $category->entity, array_map('intval', explode(',', getEntity('category'))), true)
						|| $category->add_type($object, \Categorie::TYPE_CONTACT) < 0) throw new DAV\Exception\Conflict('Invalid contact category');
				}
				$sql = "INSERT INTO ".MAIN_DB_PREFIX."cdav_card(entity,kind,fk_object,uri,uid) VALUES (".(int) $conf->entity.",'".$kind."',".(int) $object->id.",'".$this->db->escape($uri)."','".$this->db->escape($values['_uid'])."')";
				if (!$this->db->query($sql)) throw new DAV\Exception\Conflict('Duplicate DAV URI');
			}
			if ($photo !== false) {
				require_once __DIR__.'/../lib/cdav_documents.lib.php';
				$photoPath = cdavSaveContactPhoto($object, $photo);
				$object->photo = basename($photoPath);
			}
			if ($id !== null || $photo !== false) {
				// create() already triggered creation; an additional photo write is part of that operation.
				$result = $kind === 'mb' ? $object->update($this->user, 0, 1, 1, 1) : ($kind === 'th' ? $object->update($object->id, $this->user) : $object->update($object->id, $this->user, $id === null ? 1 : 0, 'update', 1));
				if ($result < 0) throw new DAV\Exception\Conflict($object->error);
			}
			$this->db->commit();
			unset($this->cardMappingCache[$kind]);
		} catch (\Throwable $e) {
			$this->db->rollback();
			if ($photoPath !== null) dol_delete_file($photoPath);
			throw $e;
		}
		return null;
	}
}
