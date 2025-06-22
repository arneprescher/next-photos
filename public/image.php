<?php

/**
 * Acts as a secure image proxy and cache.
 * It receives a path to an image on Nextcloud, fetches it using server-side credentials,
 * and serves it to the client. This prevents exposing Nextcloud credentials to the browser.
 * It also maintains a server-side cache of the image files to reduce load on Nextcloud.
 */

// --- Robust Error Handling ---
// This prevents PHP errors (e.g., from a failed file operation) from being
// injected into the image data, which would corrupt the image. Errors are logged instead.
ini_set('display_errors', 0);
ini_set('log_errors', 1);
// Note: error_log path might need to be configured in php.ini for production.
// For the built-in server, errors will go to the console where the server was started.

require_once '../vendor/autoload.php';

$config = require_once '../config.php';

if (!isset($_GET['path'])) {
	http_response_code(400);
	echo "Missing path parameter.";
	exit;
}

$path = $_GET['path'];
$extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

$contentType = null;
if ($extension === 'jpg' || $extension === 'jpeg') {
	$contentType = 'image/jpeg';
} elseif ($extension === 'png') {
	$contentType = 'image/png';
} else {
	http_response_code(415);
	exit;
}

// --- Server-side Image Caching ---
// This script maintains its own cache of image files on the server's local disk.
// This is a second layer of caching, separate from the metadata cache. It reduces
// the need to repeatedly download the same image file from Nextcloud.
$cacheDir = __DIR__ . '/../cache/images';
if (!is_dir($cacheDir)) {
	mkdir($cacheDir, 0755, true);
}
$cacheKey = md5($path);
$cacheFile = $cacheDir . '/' . $cacheKey;
$cacheDuration = 24 * 3600; // 24 hours

// Serve from cache if valid
if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheDuration) {
	header('Content-Type: ' . $contentType);
	header('Content-Length: ' . filesize($cacheFile));
	readfile($cacheFile);
	exit;
}
// --- End Caching ---

$client = new App\NextcloudClient(NEXTCLOUD_URL, NEXTCLOUD_USERNAME, NEXTCLOUD_PASSWORD);

$fileContent = $client->getFile($path);

if ($fileContent) {
	file_put_contents($cacheFile, $fileContent);

	header('Content-Type: ' . $contentType);
	header('Content-Length: ' . strlen($fileContent));
	echo $fileContent;
} else {
	http_response_code(404);
	echo "File not found.";
}
