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
	<style>
		body {
			font-family: sans-serif;
			margin: 2em;
		}

		.gallery {
			display: grid;
			grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
			gap: 10px;
		}

		.gallery-item {
			border: 1px solid #ccc;
		}

		.gallery-item img {
			width: 100%;
			height: auto;
			display: block;
		}

		.gallery-item a {
			text-decoration: none;
			color: inherit;
		}

		.gallery-item-caption {
			padding: 5px;
			font-size: 0.8em;
			background-color: #f9f9f9;
			white-space: nowrap;
			overflow: hidden;
			text-overflow: ellipsis;
		}

		.controls {
			margin-bottom: 20px;
			padding: 15px;
			background-color: #f0f0f0;
			border: 1px solid #ddd;
		}

		.progress-container {
			margin-top: 15px;
			display: none;
		}

		#progress-bar {
			width: 100%;
			-webkit-appearance: none;
			appearance: none;
			height: 25px;
		}

		#progress-bar::-webkit-progress-bar {
			background-color: #eee;
			border-radius: 2px;
			box-shadow: 0 2px 5px rgba(0, 0, 0, 0.25) inset;
		}

		#progress-bar::-webkit-progress-value {
			background-color: #4caf50;
			border-radius: 2px;
		}
	</style>
</head>

<body>
	<h1>Nextcloud Photos</h1>

	<div class="controls">
		<button id="refresh-cache-btn">Refresh Gallery Cache</button>
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
						<img data-src="image.php?path=<?= urlencode($photo->getPath()) ?>" src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" alt="<?= htmlspecialchars($photo->getFilename()) ?>">
						<div class="gallery-item-caption"><?= htmlspecialchars($photo->getFilename()) ?></div>
					</a>
				</div>
			<?php endforeach; ?>
		<?php endif; ?>
	</div>

	<script>
		document.addEventListener('DOMContentLoaded', function() {
			const refreshBtn = document.getElementById('refresh-cache-btn');
			const progressContainer = document.getElementById('progress-container');
			const progressBar = document.getElementById('progress-bar');
			const progressText = document.getElementById('progress-text');

			let isCaching = false;

			refreshBtn.addEventListener('click', () => {
				if (isCaching) return;
				isCaching = true;
				refreshBtn.disabled = true;
				progressContainer.style.display = 'block';
				progressText.textContent = 'Initializing cache...';

				// Step 1: Initialize the cache on the server. This creates the list of photos to process.
				fetch('cache_manager.php?action=init')
					.then(response => response.json())
					.then(data => {
						if (data.status === 'error') throw new Error(data.message);
						progressText.textContent = `Found ${data.total} photos. Starting cache...`;
						// Step 2: Start processing the first batch.
						processNextBatch(0);
					})
					.catch(handleError);
			});

			/**
			 * Fetches and processes the next batch of photos.
			 * It passes the number of already processed photos as an offset.
			 * The function calls itself recursively until the server reports 'complete'.
			 * @param {number} processedCount - The number of items already processed.
			 */
			function processNextBatch(processedCount) {
				fetch('cache_manager.php?action=process&offset=' + processedCount)
					.then(response => response.json())
					.then(data => {
						if (data.status === 'error') throw new Error(data.message);

						updateProgress(data.processed, data.total);

						if (data.status === 'caching') {
							setTimeout(() => processNextBatch(data.processed), 200); // Continue with next batch
						} else if (data.status === 'complete') {
							progressText.textContent = 'Cache complete! Reloading...';
							setTimeout(() => window.location.reload(), 1000);
						}
					})
					.catch(handleError);
			}

			function updateProgress(processed, total) {
				if (total > 0) {
					const percentage = Math.round((processed / total) * 100);
					progressBar.value = percentage;
					progressText.textContent = `Processing... ${processed} / ${total} photos (${percentage}%)`;
				}
			}

			function handleError(error) {
				progressText.textContent = 'Error: ' + error.message;
				progressText.style.color = 'red';
				isCaching = false;
				refreshBtn.disabled = false;
			}

			// On page load, check if a caching process is already running.
			// If so, automatically resume displaying the progress.
			const initialStatus = <?= json_encode($cacheStatus) ?>;
			if (initialStatus.status === 'caching' || initialStatus.status === 'finishing') {
				isCaching = true;
				refreshBtn.disabled = true;
				progressContainer.style.display = 'block';
				updateProgress(initialStatus.processed, initialStatus.total);
				processNextBatch(initialStatus.processed);
			}

			// --- Image Lazy Loading with Concurrency Limit ---
			// This system prevents the browser from trying to download all images at once.
			// It uses an IntersectionObserver to detect when an image is about to enter the viewport.
			const imageQueue = [];
			let activeDownloads = 0;
			const MAX_CONCURRENT_DOWNLOADS = 10; // Limits simultaneous image requests.

			const imageElements = document.querySelectorAll('img[data-src]');

			/**
			 * Processes the image queue, loading the next image if the concurrency limit is not reached.
			 */
			function processImageQueue() {
				if (activeDownloads >= MAX_CONCURRENT_DOWNLOADS || imageQueue.length === 0) {
					return;
				}

				activeDownloads++;
				const imageElement = imageQueue.shift();

				const tempImg = new Image();
				tempImg.src = imageElement.dataset.src;

				tempImg.onload = () => {
					imageElement.src = tempImg.src;
					imageElement.removeAttribute('data-src');
					activeDownloads--;
					processImageQueue();
				};

				tempImg.onerror = () => {
					console.error('Could not load image: ' + imageElement.dataset.src);
					activeDownloads--;
					processImageQueue();
				};
			}

			// Use IntersectionObserver to efficiently detect when images scroll into view.
			if ('IntersectionObserver' in window) {
				const observer = new IntersectionObserver((entries, observer) => {
					entries.forEach(entry => {
						if (entry.isIntersecting) {
							imageQueue.push(entry.target);
							processImageQueue();
							observer.unobserve(entry.target); // Stop observing once it's been queued.
						}
					});
				}, {
					rootMargin: "0px 0px 250px 0px"
				}); // Pre-load images that are 250px below the viewport.

				imageElements.forEach(img => observer.observe(img));
			} else {
				// Fallback for browsers without IntersectionObserver
				imageElements.forEach(img => {
					img.src = img.dataset.src;
				});
			}
		});
	</script>
</body>

</html>