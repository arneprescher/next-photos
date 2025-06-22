<?php

/**
 * This script acts as the backend endpoint for all AJAX requests related to the caching process.
 * It follows a simple controller pattern, executing actions based on the 'action' GET parameter.
 */

// --- Custom Error Handling ---
// This is critical for an AJAX endpoint. It ensures that any PHP warnings, notices,
// or fatal errors are caught and returned as a structured JSON error response,
// rather than outputting broken HTML that would cause a JSON parsing error on the client-side.
set_error_handler(function ($severity, $message, $file, $line) {
	if (!(error_reporting() & $severity)) {
		// This error code is not included in error_reporting
		return;
	}
	// Do not throw exceptions for deprecation notices, which Sabre/DAV generates on PHP 8+
	if ($severity === E_DEPRECATED || $severity === E_USER_DEPRECATED) {
		return;
	}
	throw new ErrorException($message, 0, $severity, $file, $line);
});

// This will catch Fatal Errors.
register_shutdown_function(function () {
	$error = error_get_last();
	if ($error !== null && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
		// Clean the output buffer to prevent mixing HTML with JSON
		if (ob_get_length()) {
			ob_end_clean();
		}

		http_response_code(500);
		header('Content-Type: application/json');
		echo json_encode([
			'status' => 'error',
			'message' => 'Fatal Error: ' . $error['message'],
			'file' => $error['file'],
			'line' => $error['line'],
		]);
	}
});
// --- End Custom Error Handling ---

// Show all errors for debugging (will be caught by the handlers above)
ini_set('display_errors', 1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config.php';

use App\NextcloudClient;

header('Content-Type: application/json');

$action = $_GET['action'] ?? null;

if (!$action) {
	http_response_code(400);
	echo json_encode(['status' => 'error', 'message' => 'Action parameter is missing.']);
	exit;
}

try {
	$client = new NextcloudClient(NEXTCLOUD_URL, NEXTCLOUD_USERNAME, NEXTCLOUD_PASSWORD);
	$response = [];

	// A simple router to handle different caching actions.
	switch ($action) {
		case 'init':
			// Starts the caching process by creating a list of all photos.
			$response = $client->initPhotoCache(NEXTCLOUD_PHOTO_DIR);
			break;

		case 'process':
			// Processes the next batch of photos.
			$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
			$response = $client->processPhotoCacheBatch($offset);
			break;

		case 'status':
			$response = $client->getCacheStatus();
			break;

		default:
			http_response_code(400);
			$response = ['status' => 'error', 'message' => 'Invalid action.'];
			break;
	}

	echo json_encode($response);
} catch (Exception $e) {
	http_response_code(500);
	echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
