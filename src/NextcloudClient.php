<?php

namespace App;

use lsolesen\pel\PelJpeg;
use lsolesen\pel\PelIfd;
use lsolesen\pel\PelTag;
use lsolesen\pel\PelEntryAscii;
use lsolesen\pel\PelExif;
use lsolesen\pel\PelTiff;

/**
 * Encapsulates all communication with the Nextcloud server via WebDAV.
 * This class handles file listing, file fetching, and the entire photo caching mechanism,
 * including EXIF data extraction.
 */
class NextcloudClient {
	private $baseUri;
	private $userName;
	private $password;
	private $cacheDir;

	/**
	 * @param string $baseUri The base URL of the Nextcloud instance.
	 * @param string $userName The username for authentication.
	 * @param string $password The app-specific password for authentication.
	 */
	public function __construct($baseUri, $userName, $password) {
		// The base URI is constructed to point directly to the user's file root in WebDAV.
		$this->baseUri = rtrim((string)$baseUri, '/') . '/remote.php/dav/files/' . $userName . '/';
		$this->userName = $userName;
		$this->password = $password;
		$this->cacheDir = __DIR__ . '/../cache';

		if (!is_dir($this->cacheDir)) {
			mkdir($this->cacheDir, 0755, true);
		}
	}

	/**
	 * Converts GPS coordinates from the EXIF rational format (degrees, minutes, seconds)
	 * into a single decimal value.
	 *
	 * @param \lsolesen\pel\PelEntryRational|null $coordEntry The EXIF entry for Latitude or Longitude.
	 * @param string|null $ref The reference ('N', 'S', 'E', 'W').
	 * @return float|null The coordinate as a decimal, or null if conversion fails.
	 */
	private function convertGpsToDecimal($coordEntry, $ref) {
		if (!$coordEntry || !$ref) {
			return null;
		}

		$values = $coordEntry->getValue();

		if (!is_array($values) || count($values) !== 3) {
			return null;
		}

		$deg_rational = $values[0];
		$min_rational = $values[1];
		$sec_rational = $values[2];

		if (
			!is_array($deg_rational) || count($deg_rational) !== 2 ||
			!is_array($min_rational) || count($min_rational) !== 2 ||
			!is_array($sec_rational) || count($sec_rational) !== 2 ||
			$deg_rational[1] == 0 || $min_rational[1] == 0 || $sec_rational[1] == 0
		) {
			return null;
		}

		$degrees = $deg_rational[0] / $deg_rational[1];
		$minutes = $min_rational[0] / $min_rational[1];
		$seconds = $sec_rational[0] / $sec_rational[1];

		$decimal = $degrees + ($minutes / 60) + ($seconds / 3600);

		if ($ref === 'S' || $ref === 'W') {
			$decimal = -$decimal;
		}

		return $decimal;
	}

	/**
	 * Updates the 'ImageDescription' EXIF tag of a given photo.
	 *
	 * @param string $path The path to the photo on the Nextcloud server.
	 * @param string $newDescription The new description text.
	 * @return bool True on success, false on failure.
	 */
	public function updatePhotoDescription($path, $newDescription) {
		try {
			$fileContent = $this->getFile($path);
			if (!$fileContent) {
				return false;
			}

			$tmpFile = tempnam(sys_get_temp_dir(), 'pel_');
			file_put_contents($tmpFile, $fileContent);

			$jpeg = new PelJpeg($tmpFile);
			$exif = $jpeg->getExif();
			if (!$exif) {
				$exif = new PelExif();
				$jpeg->setExif($exif);
				$tiff = new PelTiff();
				$exif->setTiff($tiff);
			}

			$ifd0 = $exif->getTiff()->getIfd();
			if (!$ifd0) {
				$ifd0 = new PelIfd(PelIfd::IFD0);
				$exif->getTiff()->addIfd($ifd0);
			}

			$descEntry = $ifd0->getEntry(PelTag::IMAGE_DESCRIPTION);
			if ($descEntry) {
				$descEntry->setValue($newDescription);
			} else {
				$ifd0->addEntry(new PelEntryAscii(PelTag::IMAGE_DESCRIPTION, $newDescription));
			}

			$jpeg->saveFile($tmpFile);
			$updatedContent = file_get_contents($tmpFile);
			unlink($tmpFile);

			return $this->putFile($path, $updatedContent);
		} catch (\Exception $e) {
			error_log("Error updating description for $path: " . $e->getMessage());
			return false;
		}
	}

	public function putFile($path, $content) {
		$fullUrl = $this->baseUri . implode('/', array_map('rawurlencode', explode('/', $path)));
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $fullUrl);
		curl_setopt($ch, CURLOPT_USERPWD, $this->userName . ':' . $this->password);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
		curl_setopt($ch, CURLOPT_POSTFIELDS, $content);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/octet-stream']);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

		$response = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		return $httpCode >= 200 && $httpCode < 300;
	}

	/**
	 * Reads all photo metadata from the cache file (`metadata.json`).
	 * The cache is a JSONL file (one JSON object per line), which is memory-efficient to read.
	 *
	 * @return Photo[] An array of Photo objects.
	 */
	public function getPhotosFromCache() {
		$metadataCacheFile = $this->cacheDir . '/metadata.json';
		if (!file_exists($metadataCacheFile)) {
			return [];
		}

		$photos = [];
		$fp = fopen($metadataCacheFile, 'r');
		if ($fp) {
			while (($line = fgets($fp)) !== false) {
				$data = json_decode($line, true);

				// Skip malformed lines
				if (!is_array($data)) {
					continue;
				}

				$mediaType = $data['mediaType'] ?? 'image';

				if (empty($data['path']) || (empty($data['exifData']) && $mediaType !== 'video') || isset($data['exifData']['error'])) {
					continue;
				}
				$photo = new Photo($data['path']);
				$photo->setExifData($data['exifData']);
				$photo->mediaType = $mediaType;
				$photos[] = $photo;
			}
			fclose($fp);
		}
		return $photos;
	}

	/**
	 * Determines the current status of the metadata cache.
	 * This is used by the frontend to display the progress of the caching process.
	 * The logic distinguishes between several states:
	 * - 'idle': No caching process is active.
	 * - 'caching': The cache is currently being built.
	 * - 'finishing': All photos have been processed, and the cache is about to be finalized.
	 *
	 * @return array The current cache status.
	 */
	public function getCacheStatus() {
		$metadataCacheFile = $this->cacheDir . '/metadata.json';
		$photoListFile = $this->cacheDir . '/photolist.json';

		$status = 'idle';
		$processed = 0;
		$total = 0;

		// Helper for fast line counting
		$countLines = function ($file) {
			$count = 0;
			$handle = fopen($file, "r");
			if (!$handle) return 0;
			while (!feof($handle)) {
				// Read in chunks and count newlines for performance
				$chunk = fread($handle, 8192);
				$count += substr_count($chunk, PHP_EOL);
			}
			fclose($handle);
			return $count;
		};

		if (file_exists($photoListFile)) {
			// Caching is in progress.
			$status = 'caching';
			$allPaths = json_decode(file_get_contents($photoListFile), true);
			$total = is_array($allPaths) ? count($allPaths) : 0;

			if (file_exists($metadataCacheFile)) {
				$processed = $countLines($metadataCacheFile);
			}

			if ($processed >= $total && $total > 0) {
				$status = 'finishing'; // Almost done, about to delete photolist.json
			}
		} elseif (file_exists($metadataCacheFile)) {
			// Caching is complete, photolist is gone. We are idle with a full cache.
			$status = 'idle';
			$processed = $countLines($metadataCacheFile);
			$total = $processed;
		}
		// If neither file exists, defaults are correct (idle, 0, 0).

		return [
			'status' => $status,
			'processed' => $processed,
			'total' => $total,
		];
	}

	/**
	 * Initializes the caching process.
	 * It deletes any old cache files and creates a new `photolist.json` containing
	 * all image paths to be processed.
	 *
	 * @param string $folderPath The root folder on Nextcloud to scan for photos.
	 * @return array The result of the initialization.
	 */
	public function initPhotoCache($folderPath) {
		$metadataCacheFile = $this->cacheDir . '/metadata.json';
		$photoListFile = $this->cacheDir . '/photolist.json';

		if (file_exists($metadataCacheFile)) unlink($metadataCacheFile);
		if (file_exists($photoListFile)) unlink($photoListFile);

		error_log("Photo list cache initialized.");
		$allFilePaths = $this->getAllFilePaths($folderPath);
		file_put_contents($photoListFile, json_encode(array_reverse($allFilePaths)));
		// Create empty metadata file, using JSONL format (one JSON object per line)
		file_put_contents($metadataCacheFile, '');

		return ['status' => 'initialized', 'total' => count($allFilePaths)];
	}

	/**
	 * Processes a small batch of photos from the `photolist.json`.
	 * It downloads each photo, extracts metadata, and appends it to `metadata.json`.
	 *
	 * @param int $offset The number of photos already processed, used to determine the next batch.
	 * @return array The status after processing the batch.
	 */
	public function processPhotoCacheBatch($offset = 0) {
		$metadataCacheFile = $this->cacheDir . '/metadata.json';
		$photoListFile = $this->cacheDir . '/photolist.json';
		$batchSize = 5;

		if (!file_exists($photoListFile)) {
			return ['status' => 'idle', 'processed' => 0, 'total' => 0, 'message' => 'Cache process not started.'];
		}

		$allFilePaths = json_decode(file_get_contents($photoListFile), true);
		if (!is_array($allFilePaths)) $allFilePaths = [];

		// Check for and handle outdated cache format
		if (!empty($allFilePaths) && isset($allFilePaths[0]) && is_string($allFilePaths[0])) {
			error_log('Old cache format detected. Deleting cache files.');
			unlink($photoListFile);
			if (file_exists($metadataCacheFile)) {
				unlink($metadataCacheFile);
			}
			return ['status' => 'idle', 'processed' => 0, 'total' => 0, 'message' => 'Cache format was outdated. Please re-initialize.'];
		}

		$totalCount = count($allFilePaths);

		// This check is now based on the offset provided by the client
		if ($offset >= $totalCount && $totalCount > 0) {
			unlink($photoListFile);
			error_log("Cache processing complete.");
			return ['status' => 'complete', 'processed' => $totalCount, 'total' => $totalCount];
		}

		$pathsToProcess = array_slice($allFilePaths, $offset, $batchSize);

		$fp = fopen($metadataCacheFile, 'a');
		if (!$fp) {
			throw new \Exception("Could not open metadata cache file for writing.");
		}

		foreach ($pathsToProcess as $photoInfo) {
			$relativePath = $photoInfo['path'];
			$fileSize = $photoInfo['size'];
			$extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
			$isPotentialVideo = in_array($extension, ['mp4', 'mov', 'avi', 'webm']) || (isset($photoInfo['contentType']) && strpos($photoInfo['contentType'], 'video/') === 0);

			try {
				// Check file size BEFORE downloading. A larger limit for videos.
				$limit = $isPotentialVideo ? 500 * 1024 * 1024 : 50 * 1024 * 1024; // 500MB for videos, 20MB for images
				if ($fileSize > $limit) {
					$mediaType = $isPotentialVideo ? 'video' : 'image';
					$exifData = ['error' => 'File skipped, too large.'];
					$dataToWrite = ['path' => $relativePath, 'mediaType' => $mediaType, 'exifData' => $exifData];
					fwrite($fp, json_encode($dataToWrite) . PHP_EOL);
					continue; // Skip to next file
				}

				$fileContent = $this->getFile($relativePath);
				$exifData = [];
				$mediaType = 'image'; // Default to image

				if ($fileContent) {
					// Check for video types first by extension, then by content type
					if ($isPotentialVideo) {
						$mediaType = 'video';
						// No metadata extraction for videos for now.
					} elseif ($extension === 'png') {
						// For PNGs, we can't get EXIF, but we can get dimensions.
						$imageInfo = getimagesizefromstring($fileContent);
						if ($imageInfo) {
							$exifData['Width'] = $imageInfo[0];
							$exifData['Height'] = $imageInfo[1];
						}
					} elseif ($extension === 'jpg' || $extension === 'jpeg') {
						// For JPEGs, use the PEL library to extract detailed EXIF data.
						$tmpFile = tempnam(sys_get_temp_dir(), 'pel_');
						file_put_contents($tmpFile, $fileContent);
						$jpeg = new PelJpeg($tmpFile);
						$exif = $jpeg->getExif();
						if ($exif) {
							$tiff = $exif->getTiff();
							if ($tiff) {
								$ifd0 = $tiff->getIfd();
								if ($ifd0) {
									// Basic info from IFD0
									$exifData = [
										'ImageDescription' => $ifd0->getEntry(PelTag::IMAGE_DESCRIPTION) ? $ifd0->getEntry(PelTag::IMAGE_DESCRIPTION)->getValue() : null,
										'Make'             => $ifd0->getEntry(PelTag::MAKE) ? $ifd0->getEntry(PelTag::MAKE)->getValue() : null,
										'Model'            => $ifd0->getEntry(PelTag::MODEL) ? $ifd0->getEntry(PelTag::MODEL)->getValue() : null,
										'Software'         => $ifd0->getEntry(PelTag::SOFTWARE) ? $ifd0->getEntry(PelTag::SOFTWARE)->getValue() : null,
										'Orientation'      => $ifd0->getEntry(PelTag::ORIENTATION) ? $ifd0->getEntry(PelTag::ORIENTATION)->getValue() : null,
										'DateTime'         => $ifd0->getEntry(PelTag::DATE_TIME) ? $ifd0->getEntry(PelTag::DATE_TIME)->getValue() : null,
										'Width'            => $ifd0->getEntry(PelTag::IMAGE_WIDTH) ? $ifd0->getEntry(PelTag::IMAGE_WIDTH)->getValue() : null,
										'Height'           => $ifd0->getEntry(PelTag::IMAGE_LENGTH) ? $ifd0->getEntry(PelTag::IMAGE_LENGTH)->getValue() : null,
									];

									// Detailed EXIF data from the SubIFD
									$exifIfd = $ifd0->getSubIfd(PelIfd::EXIF);
									if ($exifIfd) {
										$exifData['UserComment'] = $exifIfd->getEntry(PelTag::USER_COMMENT) ? $exifIfd->getEntry(PelTag::USER_COMMENT)->getValue() : null;
										$exifData['DateTimeOriginal'] = $exifIfd->getEntry(PelTag::DATE_TIME_ORIGINAL) ? $exifIfd->getEntry(PelTag::DATE_TIME_ORIGINAL)->getValue() : null;

										// --- ExposureTime ---
										$entry = $exifIfd->getEntry(PelTag::EXPOSURE_TIME);
										if ($entry) {
											$v = $entry->getValue();
											if (is_array($v) && count($v) === 2 && $v[1] != 0) {
												$dec = $v[0] / $v[1];
												if ($dec < 1) {
													$exifData['ExposureTime'] = "1/" . round(1 / $dec) . " s";
												} else {
													$exifData['ExposureTime'] = round($dec, 1) . " s";
												}
											} else {
												$exifData['ExposureTime'] = $v;
											}
										} else {
											$exifData['ExposureTime'] = null;
										}

										// --- Aperture ---
										$entry = $exifIfd->getEntry(PelTag::FNUMBER);
										if ($entry) {
											$v = $entry->getValue();
											if (is_array($v) && count($v) === 2 && $v[1] != 0) {
												$exifData['Aperture'] = "f/" . round($v[0] / $v[1], 1);
											} else {
												$exifData['Aperture'] = $v;
											}
										} else {
											$exifData['Aperture'] = null;
										}

										// --- FocalLength ---
										$entry = $exifIfd->getEntry(PelTag::FOCAL_LENGTH);
										if ($entry) {
											$v = $entry->getValue();
											if (is_array($v) && count($v) === 2 && $v[1] != 0) {
												$exifData['FocalLength'] = round($v[0] / $v[1], 1) . " mm";
											} else {
												$exifData['FocalLength'] = $v;
											}
										} else {
											$exifData['FocalLength'] = null;
										}

										// --- ISO ---
										$entry = $exifIfd->getEntry(PelTag::ISO_SPEED_RATINGS);
										if ($entry) {
											$v = $entry->getValue();
											$exifData['ISO'] = is_array($v) ? $v[0] : $v;
										} else {
											$exifData['ISO'] = null;
										}

										$exifData['Flash']            = $exifIfd->getEntry(PelTag::FLASH) ? $exifIfd->getEntry(PelTag::FLASH)->getValue() : null;

										// Prefer more specific width/height from ExifIFD if available
										$width = $exifIfd->getEntry(PelTag::PIXEL_X_DIMENSION) ? $exifIfd->getEntry(PelTag::PIXEL_X_DIMENSION)->getValue() : null;
										$height = $exifIfd->getEntry(PelTag::PIXEL_Y_DIMENSION) ? $exifIfd->getEntry(PelTag::PIXEL_Y_DIMENSION)->getValue() : null;
										if ($width) $exifData['Width'] = $width;
										if ($height) $exifData['Height'] = $height;
									}

									// GPS data from its own SubIFD
									$gpsIfd = $ifd0->getSubIfd(PelIfd::GPS);
									if ($gpsIfd) {
										$latRef = $gpsIfd->getEntry(PelTag::GPS_LATITUDE_REF);
										$lonRef = $gpsIfd->getEntry(PelTag::GPS_LONGITUDE_REF);
										$lat = $this->convertGpsToDecimal($gpsIfd->getEntry(PelTag::GPS_LATITUDE), $latRef ? $latRef->getValue() : null);
										$lon = $this->convertGpsToDecimal($gpsIfd->getEntry(PelTag::GPS_LONGITUDE), $lonRef ? $lonRef->getValue() : null);

										if ($lat !== null && $lon !== null) {
											$exifData['GPS'] = [
												'Latitude'  => $lat,
												'Longitude' => $lon
											];
										}
									}
								}
							}
						}
						unlink($tmpFile);
					}
				} else {
					$exifData = ['error' => 'File is not a supported image or could not be downloaded.'];
				}

				$dataToWrite = ['path' => $relativePath, 'mediaType' => $mediaType, 'exifData' => $exifData];
				fwrite($fp, json_encode($dataToWrite) . PHP_EOL);

				// Aggressively free memory after each photo. This is a safeguard against
				// potential memory leaks in the EXIF processing library, ensuring the
				// long-running cache process remains stable.
				unset($fileContent, $jpeg, $exif, $tiff, $ifd0, $exifIfd, $gpsIfd, $dataToWrite, $photoInfo);
				gc_collect_cycles(); // Force garbage collection
			} catch (\Exception $e) {
				error_log("Error processing $relativePath: " . $e->getMessage());
				$dataToWrite = ['path' => $relativePath, 'mediaType' => 'image', 'exifData' => ['error' => $e->getMessage()]];
				fwrite($fp, json_encode($dataToWrite) . PHP_EOL);
			}
		}
		fclose($fp);

		$newProcessedCount = $offset + count($pathsToProcess);

		if ($newProcessedCount >= $totalCount && $totalCount > 0) {
			unlink($photoListFile);
			error_log("Cache processing complete.");
			return ['status' => 'complete', 'processed' => $newProcessedCount, 'total' => $totalCount];
		}

		return ['status' => 'caching', 'processed' => $newProcessedCount, 'total' => $totalCount];
	}

	/**
	 * Cancels an ongoing caching process by deleting the photo list file.
	 *
	 * @return array The status after attempting to cancel.
	 */
	public function cancelPhotoCache() {
		$photoListFile = $this->cacheDir . '/photolist.json';
		if (file_exists($photoListFile)) {
			unlink($photoListFile);
			return ['status' => 'cancelled', 'message' => 'Cache process cancelled.'];
		}
		return ['status' => 'idle', 'message' => 'No active cache process to cancel.'];
	}

	/**
	 * Fetches a single file from Nextcloud.
	 *
	 * @param string $path The path of the file to fetch.
	 * @return string|null The file content, or null on failure.
	 */
	public function getFile($path) {
		$fullUrl = $this->baseUri . implode('/', array_map('rawurlencode', explode('/', $path)));

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $fullUrl);
		curl_setopt($ch, CURLOPT_USERPWD, $this->userName . ':' . $this->password);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
		$response = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($httpCode !== 200) {
			return null;
		}

		return $response;
	}

	public function updateFile($path, $data) {
		// Implementation of updateFile method
	}

	public function createAlbum($albumName) {
		// Implementation of createAlbum method
	}

	public function getAlbums() {
		// Implementation of getAlbums method
	}

	public function copyToAlbum($photoPath, $albumPath) {
		// Implementation of copyToAlbum method
	}

	/**
	 * Recursively fetches all file paths from a given folder on Nextcloud.
	 * This method uses a `curl` command via `shell_exec` because the `sabre/dav`
	 * library proved unreliable for recursive "Depth: infinity" searches on this server,
	 * while a direct curl command was stable. This is a pragmatic workaround.
	 *
	 * @param string $folderPath The folder to scan.
	 * @return array A list of file paths and their sizes.
	 */
	private function getAllFilePaths($folderPath) {
		$fullUrl = $this->baseUri . implode('/', array_map('rawurlencode', explode('/', $folderPath)));
		$username = escapeshellarg($this->userName);
		$password = escapeshellarg($this->password);
		$url = escapeshellarg($fullUrl);
		$xmlRequest = '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:getcontenttype/><d:getcontentlength/></d:prop></d:propfind>';
		$xmlRequest = escapeshellarg($xmlRequest);
		$command = "curl -u {$username}:{$password} -X PROPFIND {$url} -H \"Depth: infinity\" --data-binary {$xmlRequest}";
		$xmlString = shell_exec($command);
		if (empty($xmlString)) {
			return [];
		}
		$xmlStart = strpos($xmlString, '<?xml');
		if ($xmlStart !== false) {
			$xmlString = substr($xmlString, $xmlStart);
		}
		$xml = @simplexml_load_string($xmlString, "SimpleXMLElement", LIBXML_NOCDATA);
		if ($xml === false) {
			return [];
		}

		$xml->registerXPathNamespace('d', 'DAV:');
		$responses = $xml->xpath('//d:response');
		$paths = [];
		$basePath = '/remote.php/dav/files/' . $this->userName . '/';
		foreach ($responses as $response) {
			$href = (string)$response->xpath('d:href')[0];
			$contentTypeNodes = $response->xpath('.//d:getcontenttype');
			$contentLengthNodes = $response->xpath('.//d:getcontentlength');

			if (count($contentTypeNodes) === 0) {
				continue;
			}
			$contentType = (string)$contentTypeNodes[0];

			$decodedHref = urldecode($href);
			$relativePath = $decodedHref;
			if (strpos($decodedHref, $basePath) === 0) {
				$relativePath = substr($decodedHref, strlen($basePath));
			}
			if (rtrim($relativePath, '/') === rtrim($folderPath, '/')) {
				continue;
			}
			if (stripos($contentType, 'image/') === 0 || stripos($contentType, 'video/') === 0) {
				$contentLength = count($contentLengthNodes) > 0 ? (int)$contentLengthNodes[0] : 0;
				$paths[] = ['path' => $relativePath, 'size' => $contentLength, 'contentType' => $contentType];
			}
		}
		return $paths;
	}
}
