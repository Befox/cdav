<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/** Validate the owner configuration and every existing path component before file access.
 * @param object $object Native object with its actual entity.
 * @param string $module Native document module.
 * @return string Native directory, never a fallback to another entity.
 */
function cdavDocumentRoot($object, $module)
{
	global $conf, $langs;
	$entity = isset($object->entity) ? (int) $object->entity : 0;
	$directory = $conf->{$module}->multidir_output[$entity] ?? null;
	if ($entity <= 0 || !is_string($directory) || trim($directory) === '' || strpos($directory, 'error-') === 0) {
		throw new RuntimeException($langs->transnoentities('CDavRequiresDirectories'));
	}
	if (version_compare(DOL_VERSION, '18.0.0', '>=')) $directory = getMultidirOutput($object, $module, 1);
	if (!is_string($directory) || trim($directory) === '' || strpos($directory, 'error-') === 0) throw new RuntimeException($langs->transnoentities('CDavRequiresDirectories'));
	$cursor = $directory;
	while ($cursor !== dirname($cursor)) {
		if (is_link($cursor)) throw new RuntimeException($langs->transnoentities('CDavRequiresDirectories'));
		$cursor = dirname($cursor);
	}
	return rtrim($directory, '/\\');
}

/** Write a re-encoded contact photo, preserving orientation and discarding EXIF/GPS.
 * @param Contact $contact Loaded/created contact in its owner entity.
 * @param string $data Inline image data.
 * @return string New unique filename; caller removes it if the database operation fails.
 */
function cdavSaveContactPhoto($contact, $data)
{
	global $langs;
	require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
	if ((int) $contact->id <= 0) throw new RuntimeException($langs->transnoentities('CDavInvalidPhoto'));
	$image = cdavDecodePhoto($data);
	$directory = cdavDocumentRoot($contact, 'societe').'/contact/'.(int) $contact->id.'/photos';
	$cursor = $directory;
	while ($cursor !== dirname($cursor)) {
		if (is_link($cursor)) throw new RuntimeException($langs->transnoentities('CDavRequiresDirectories'));
		$cursor = dirname($cursor);
	}
	if (dol_mkdir($directory) < 0) throw new RuntimeException($langs->transnoentities('CDavRequiresDirectories'));
	$path = $directory.'/cdav-'.bin2hex(random_bytes(12)).'.jpg';
	$success = $image && imagejpeg($image, $path);
	if ($image) imagedestroy($image);
	if (!$success) { if (is_file($path)) dol_delete_file($path); throw new RuntimeException($langs->transnoentities('CDavInvalidPhoto')); }
	return $path;
}

/** Decode an inline DAV image with size/MIME limits and normalized EXIF orientation.
 * @param string $data Image bytes.
 * @return GdImage
 */
function cdavDecodePhoto($data)
{
	global $langs;
	if (strlen($data) > 10 * 1024 * 1024) throw new RuntimeException($langs->transnoentities('CDavInvalidPhoto'));
	$info = @getimagesizefromstring($data);
	if (!$info || $info[0] * $info[1] > 25000000 || !in_array($info['mime'], array('image/jpeg', 'image/png', 'image/gif'), true)) throw new RuntimeException($langs->transnoentities('CDavInvalidPhoto'));
	$image = @imagecreatefromstring($data);
	if (!$image) throw new RuntimeException($langs->transnoentities('CDavInvalidPhoto'));
	if ($info['mime'] === 'image/jpeg' && function_exists('exif_read_data')) {
		$stream = fopen('php://temp', 'w+b');
		if ($stream === false) throw new RuntimeException($langs->transnoentities('CDavInvalidPhoto'));
		fwrite($stream, $data); rewind($stream);
		$exif = @exif_read_data($stream); fclose($stream);
		$orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
		if (in_array($orientation, array(2, 4, 5, 7), true)) imageflip($image, IMG_FLIP_HORIZONTAL);
		$angle = array(3 => 180, 4 => 180, 5 => 90, 6 => -90, 7 => -90, 8 => 90)[$orientation] ?? 0;
		if ($angle) { $rotated = imagerotate($image, $angle, 0); imagedestroy($image); $image = $rotated; }
	}
	if (!$image) throw new RuntimeException($langs->transnoentities('CDavInvalidPhoto'));
	return $image;
}
