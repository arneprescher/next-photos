<?php

namespace App;

use DateTime;

class Photo {
	private $path;
	private $exifData = [];
	public $mediaType = 'image';

	public function __construct($path, $exifData = null) {
		$this->path = $path;
		$this->exifData = $exifData;
	}

	public function getPath() {
		return $this->path;
	}

	public function getFilename() {
		return basename($this->path);
	}

	public function getExifData() {
		return $this->exifData;
	}

	public function setExifData($exifData) {
		$this->exifData = $exifData;
	}

	public function getCreationDate() {
		if (empty($this->exifData['DateTimeOriginal'])) {
			return null;
		}

		try {
			// EXIF format is 'YYYY:MM:DD HH:MM:SS', needs to be converted for DateTime constructor
			$dateStr = str_replace(':', '-', substr($this->exifData['DateTimeOriginal'], 0, 10)) . substr($this->exifData['DateTimeOriginal'], 10);
			return new \DateTime($dateStr);
		} catch (\Exception $e) {
			return null;
		}
	}

	public function getDescription() {
		return $this->exifData['ImageDescription'] ?? '';
	}
}
