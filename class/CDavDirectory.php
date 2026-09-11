<?php
use Sabre\DAV;

require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
require_once __DIR__.'/cdavcompatibility.class.php';

/** Stateful filesystem context, including the owning entity and native document module. */
trait CDavFileContext
{
	/** @var User */ protected $davUser;
	/** @var string */ protected $root;
	/** @var string */ protected $modulepart;
	/** @var int */ protected $entity;
	/** @var list<string> */ protected $readRight;
	/** @var list<string> */ protected $writeRight;
	/** @var list<string> */ protected $deleteRight;

	public function __construct($path, $user, $root = '', $modulepart = '', $entity = 1, $readRight = array('ecm', 'read'), $writeRight = array('ecm', 'upload'), $deleteRight = array('ecm', 'setup'))
	{
		$path = str_replace(chr(92), '/', $path);
		$root = str_replace(chr(92), '/', $root);
		parent::__construct($path);
		$this->davUser = $user;
		$this->root = $root !== '' ? $root : $path;
		$this->modulepart = $modulepart;
		$this->entity = (int) $entity;
		$this->readRight = $readRight; $this->writeRight = $writeRight; $this->deleteRight = $deleteRight;
		$this->checkPath($path);
	}

	/** Validate confinement, symlinks and the native document access contract. */
	protected function checkPath($path, $mode = 'read')
	{
		if (strpos($path, $this->root.'/') !== 0 && $path !== $this->root) throw new DAV\Exception\Forbidden();
		$cursor = $path;
		while ($cursor !== dirname($cursor)) {
			if (is_link($cursor)) throw new DAV\Exception\Forbidden();
			$cursor = dirname($cursor);
		}
		if ($this->modulepart !== '' && $path !== $this->root) {
			$relative = substr($path, strlen($this->root) + 1);
			$ref = explode('/', $relative, 2)[0];
			$access = dol_check_secure_access_document($this->modulepart, $relative, $this->entity, $this->davUser, $ref, $mode);
			if (!is_array($access) || empty($access['accessallowed'])) throw new DAV\Exception\Forbidden();
		}
	}
	protected function childPath($name)
	{
		if ($name === '' || $name[0] === '.' || $name !== dol_sanitizeFileName($name) || strpbrk($name, '/'.chr(92)) !== false || preg_match('/[\x00-\x1f]/', $name)) throw new DAV\Exception\Forbidden();
		return $this->path.'/'.$name;
	}
	public function setName($name)
	{
		if (!$this->davUser->hasRight(...$this->writeRight) || !$this->davUser->hasRight(...$this->deleteRight) || $this->path === $this->root) throw new DAV\Exception\Forbidden();
		if (is_file($this->path) && strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== strtolower(pathinfo($this->path, PATHINFO_EXTENSION))) throw new DAV\Exception\Forbidden();
		$target = dirname($this->path).'/'.$name;
		$this->childPath($name); $this->checkPath($this->path, 'write'); $this->checkPath($target, 'write');
		if (is_dir($this->path)) $this->checkDescendants();
		if (is_dir($this->path) ? dol_move_dir($this->path, $target, 0) < 0 : !dol_move($this->path, $target, 0, 0, 1)) throw new DAV\Exception('File operation failed');
		$this->path = $target;
	}
	/** Preflight the entire subtree before a recursive deletion or rename. */
	public function checkDescendants()
	{
		foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $entry) {
			if ($entry->isLink() || $entry->getFilename()[0] === '.') throw new DAV\Exception\Forbidden();
			$this->checkPath(str_replace(DIRECTORY_SEPARATOR, '/', $entry->getPathname()), 'write');
		}
	}

}

class CDavDirectory extends DAV\FS\Directory
{
	use CDavFileContext;
	public function getChild($name)
	{
		if (!$this->davUser->hasRight(...$this->readRight)) throw new DAV\Exception\Forbidden();
		$path = $this->childPath($name); $this->checkPath($path);
		if (!file_exists($path)) throw new DAV\Exception\NotFound();
		$class = is_dir($path) ? self::class : CDavFile::class;
		return new $class($path, $this->davUser, $this->root, $this->modulepart, $this->entity, $this->readRight, $this->writeRight, $this->deleteRight);
	}
	public function getChildren()
	{
		if (!$this->davUser->hasRight(...$this->readRight)) throw new DAV\Exception\Forbidden();
		$this->checkPath($this->path);
		$nodes = array();
		foreach (new DirectoryIterator($this->path) as $entry) {
			if ($entry->isDot() || $entry->isLink() || $entry->getFilename()[0] === '.') continue;
			try { $nodes[] = $this->getChild($entry->getFilename()); } catch (DAV\Exception\Forbidden $e) { continue; }
		}
		return $nodes;
	}
	public function childExists($name)
	{
		try { $this->getChild($name); return true; } catch (DAV\Exception\NotFound $e) { return false; }
	}
	public function createFile($name, $data = null)
	{
		if (!$this->davUser->hasRight(...$this->writeRight)) throw new DAV\Exception\Forbidden();
		$path = $this->childPath($name); $this->checkPath($path, 'write');
		$file = new CDavFile($path, $this->davUser, $this->root, $this->modulepart, $this->entity, $this->readRight, $this->writeRight, $this->deleteRight);
		return $file->put($data);
	}
	public function createDirectory($name)
	{
		if (!$this->davUser->hasRight(...$this->writeRight)) throw new DAV\Exception\Forbidden();
		$path = $this->childPath($name); $this->checkPath($path, 'write');
		if (dol_mkdir($path) < 0) throw new DAV\Exception('File operation failed');
	}
	public function delete()
	{
		if (!$this->davUser->hasRight(...$this->deleteRight) || $this->path === $this->root) throw new DAV\Exception\Forbidden();
		$this->checkPath($this->path, 'write');
		// Refuse before deleting anything if an inaccessible descendant was hidden.
		$this->checkDescendants();
		foreach (new DirectoryIterator($this->path) as $entry) {
			if (!$entry->isDot()) $this->getChild($entry->getFilename())->delete();
		}
		if (!dol_delete_dir($this->path)) throw new DAV\Exception('File operation failed');
	}
}

class CDavFile extends DAV\FS\File
{
	use CDavFileContext;
	public function get()
	{
		if (!$this->davUser->hasRight(...$this->readRight)) throw new DAV\Exception\Forbidden();
		$this->checkPath($this->path);
		$result = fopen($this->path, 'rb');
		if ($result === false) throw new DAV\Exception\NotFound();
		return $result;
	}
	public function put($data)
	{
		if (!$this->davUser->hasRight(...$this->writeRight)) throw new DAV\Exception\Forbidden();
		if (!CDavCompatibility::isFeatureAvailable('webdav_write')) throw new DAV\Exception\ServiceUnavailable('WebDAV upload prerequisites unavailable');
		$this->checkPath($this->path, 'write');
		// DAV is not an HTTP multipart upload. Stage in the owner's directory, then
		// apply the native virus scan/move and index, with a restrictive extension policy.
		if (!preg_match('/\.(pdf|txt|csv|ics|vcf|odt|ods|odp|docx|xlsx|pptx|jpg|jpeg|png|gif|zip)$/iD', $this->path)) throw new DAV\Exception\UnsupportedMediaType();
		$temp = tempnam(dirname($this->path), '.cdav-');
		if ($temp === false) throw new DAV\Exception('File operation failed');
		try {
			$output = fopen($temp, 'wb');
			$limit = min(64 * 1024 * 1024, max(1, getDolGlobalInt('MAIN_UPLOAD_DOC', 65536)) * 1024);
			if ($output === false) throw new DAV\Exception('File operation failed');
			$size = is_resource($data) ? stream_copy_to_stream($data, $output, $limit + 1) : fwrite($output, (string) $data);
			fclose($output);
			if ($size === false || $size > $limit) throw new DAV\Exception\BadRequest('File too large');
			$mime = (new finfo(FILEINFO_MIME_TYPE))->file($temp);
			if (!is_string($mime) || in_array($mime, array('text/x-php', 'application/x-httpd-php', 'text/html', 'application/x-executable'), true)) throw new DAV\Exception\UnsupportedMediaType();
			$extension = strtolower(pathinfo($this->path, PATHINFO_EXTENSION));
			$expected = array('pdf' => array('application/pdf'), 'png' => array('image/png'), 'gif' => array('image/gif'), 'jpg' => array('image/jpeg'), 'jpeg' => array('image/jpeg'));
			if (isset($expected[$extension]) && !in_array($mime, $expected[$extension], true)) throw new DAV\Exception\UnsupportedMediaType();
			if (in_array($extension, array('jpg', 'jpeg', 'png', 'gif'), true)) {
				require_once __DIR__.'/cdavcompatibility.class.php';
				require_once __DIR__.'/../lib/cdav_documents.lib.php';
				if (!CDavCompatibility::isFeatureAvailable('photos')) throw new DAV\Exception\UnsupportedMediaType();
				$image = cdavDecodePhoto(file_get_contents($temp));
				$written = $extension === 'png' ? imagepng($image, $temp) : ($extension === 'gif' ? imagegif($image, $temp) : imagejpeg($image, $temp));
				imagedestroy($image);
				if (!$written) throw new DAV\Exception('File operation failed');
			}
			if (!dol_move($temp, $this->path, 0, 1, 1)) throw new DAV\Exception('File operation failed');
			if (addFileIntoDatabaseIndex(dirname($this->path), basename($this->path)) < 0) throw new DAV\Exception('File index update failed');
		} finally {
			if (is_file($temp)) dol_delete_file($temp);
		}
		return null;
	}
	public function delete()
	{
		if (!$this->davUser->hasRight(...$this->deleteRight)) throw new DAV\Exception\Forbidden();
		$this->checkPath($this->path, 'write');
		if (dol_delete_file($this->path) <= 0) throw new DAV\Exception('File operation failed');
	}
}
