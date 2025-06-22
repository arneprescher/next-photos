<?php

// Show all errors for debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';

use App\NextcloudClient;
use App\Photo;

// --- Cache Pruning Logic ---
// Periodically cleans out very old files from the cache directories.
// This is triggered randomly to avoid performance impact on every page load.
function pruneCache($directory, $maxAgeInSeconds) {
	$files = glob($directory . '/*');
	$now = time();
	foreach ($files as $file) {
		if (is_file($file)) {
			if ($now - filemtime($file) > $maxAgeInSeconds) {
				unlink($file);
			}
		}
	}
}

// Trigger pruning occasionally (e.g., 1% chance on page load)
if (rand(1, 100) === 1) {
	$cacheDir = __DIR__ . '/../cache';
	$cacheMaxAge = 7 * 24 * 60 * 60; // 7 days
	pruneCache($cacheDir, $cacheMaxAge);
}
// --- End Cache Pruning ---

$client = new NextcloudClient(NEXTCLOUD_URL, NEXTCLOUD_USERNAME, NEXTCLOUD_PASSWORD);
$photos = $client->getPhotosFromCache();
$cacheStatus = $client->getCacheStatus();

?>
<!DOCTYPE html>
<html lang="en">

<head>
	<meta charset="UTF-8">
	<title>Nextcloud Photos</title>
	<link rel="stylesheet" href="assets/styles.css">
</head>

<body>
	<h1>Nextcloud Photos</h1>

	<div class="controls">
		<button id="refresh-cache-btn">Refresh Gallery Cache</button>
		<button id="cancel-cache-btn" style="display: none;">Cancel Caching</button>
		<div class="progress-container" id="progress-container">
			<p id="progress-text">Caching photos...</p>
			<progress id="progress-bar" value="0" max="100"></progress>
		</div>
	</div>

	<div class="gallery">
		<?php if (empty($photos)): ?>
			<p>No photos found. Try refreshing the cache.</p>
		<?php else: ?>
			<?php foreach ($photos as $photo): ?>
				<div class="gallery-item">
					<a href="edit.php?path=<?= urlencode($photo->getPath()) ?>">
						<?php if ($photo->mediaType === 'video'): ?>
							<video muted loop autoplay preload="metadata" data-src="media.php?path=<?= urlencode($photo->getPath()) ?>#t=0.1" title="<?= htmlspecialchars($photo->getFilename()) ?>"></video>
						<?php else: ?>
							<img data-src="media.php?path=<?= urlencode($photo->getPath()) ?>" src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" alt="<?= htmlspecialchars($photo->getFilename()) ?>">
						<?php endif; ?>
						<div class="gallery-item-caption"><?= htmlspecialchars($photo->getFilename()) ?></div>
					</a>
				</div>
			<?php endforeach; ?>
		<?php endif; ?>
	</div>

	<script>
		// Pass PHP status to a global JS variable
		const initialCacheStatus = <?= json_encode($cacheStatus) ?>;
	</script>
	<script src="assets/main.js"></script>
</body>

</html>