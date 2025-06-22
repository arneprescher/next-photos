<?php

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config.php';

use App\NextcloudClient;
use lsolesen\pel\PelJpeg;
use lsolesen\pel\PelExif;
use lsolesen\pel\PelTiff;
use lsolesen\pel\PelIfd;
use lsolesen\pel\PelEntryAscii;
use lsolesen\pel\PelTag;

if (!isset($_GET['path'])) {
	http_response_code(400);
	echo 'Missing path parameter';
	exit;
}

$path = $_GET['path'];
$client = new NextcloudClient(NEXTCLOUD_URL, NEXTCLOUD_USERNAME, NEXTCLOUD_PASSWORD);
$photo = null;
$exifData = [];
$message = '';
$albums = [];

try {
	// Load metadata from cache
	$metadataCacheFile = __DIR__ . '/../cache/metadata.json';
	if (!file_exists($metadataCacheFile)) {
		throw new Exception('Metadata cache not found. Please refresh the gallery cache.');
	}

	$allPhotosData = json_decode(file_get_contents($metadataCacheFile), true);
	$photoData = null;
	foreach ($allPhotosData as $p) {
		if ($p['path'] === $path) {
			$photoData = $p;
			break;
		}
	}

	if (!$photoData) {
		throw new Exception('Photo not found in metadata cache.');
	}

	$exifData = $photoData['exifData'];

	// Get albums for dropdown
	$albums = $client->getAlbums();

	// Handle form submission for description update
	if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['description'])) {
		$newDescription = $_POST['description'];
		if ($client->updatePhotoDescription($path, $newDescription)) {
			$message = 'Description updated successfully! The cache will be updated on the next full refresh.';
			// Update the local variable to show the change immediately
			$exifData['ImageDescription'] = $newDescription;
		} else {
			$message = 'Error saving description.';
		}
	}

	// Handle form submission for copy to album
	if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['album_path'])) {
		try {
			$client->copyToAlbum($path, $_POST['album_path']);
			$message = 'Photo copied to album successfully!';
		} catch (Exception $e) {
			$message = 'Error copying photo: ' . $e->getMessage();
		}
	}
} catch (Exception $e) {
	$message = 'Error: ' . $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
	<meta charset="UTF-8">
	<title>Edit Photo</title>
	<style>
		body {
			font-family: sans-serif;
			margin: 2em;
		}

		.container {
			display: flex;
			gap: 20px;
			align-items: flex-start;
		}

		.photo-view img {
			max-width: 60vw;
			max-height: 80vh;
			border: 1px solid #ccc;
		}

		.form-group {
			margin-bottom: 15px;
		}

		label {
			display: block;
			font-weight: bold;
			margin-bottom: 5px;
		}

		textarea,
		select,
		button {
			width: 100%;
			padding: 8px;
			box-sizing: border-box;
		}

		.message {
			padding: 10px;
			background-color: #eef;
			border: 1px solid #aac;
			margin-bottom: 20px;
		}

		.exif-table {
			border-collapse: collapse;
			width: 100%;
		}

		.exif-table th,
		.exif-table td {
			border: 1px solid #ddd;
			padding: 8px;
			text-align: left;
		}

		.exif-table th {
			background-color: #f2f2f2;
			width: 150px;
		}
	</style>
</head>

<body>
	<a href="index.php">Back to Gallery</a>
	<h1>Edit Photo: <?= htmlspecialchars($path) ?></h1>

	<?php if ($message): ?>
		<p class="message"><?= htmlspecialchars($message) ?></p>
	<?php endif; ?>

	<div class="container">
		<div class="photo-view">
			<img src="image.php?path=<?= urlencode($path) ?>" alt="<?= htmlspecialchars($path) ?>">
		</div>
		<div class="exif-form">
			<form action="edit.php?path=<?= urlencode($path) ?>" method="post">
				<div class="form-group">
					<label for="description">Description</label>
					<textarea name="description" id="description"><?= htmlspecialchars($exifData['ImageDescription'] ?? '') ?></textarea>
				</div>
				<button type="submit" name="update_description">Save Description</button>
			</form>

			<hr style="margin: 20px 0;">

			<form action="edit.php?path=<?= urlencode($path) ?>" method="post">
				<div class="form-group">
					<label for="album_path">Copy to Album</label>
					<select name="album_path" id="album_path">
						<option value="">-- Select an Album --</option>
						<?php if (!empty($albums)): ?>
							<?php foreach ($albums as $name => $albumPath): ?>
								<option value="<?= htmlspecialchars($albumPath) ?>"><?= htmlspecialchars($name) ?></option>
							<?php endforeach; ?>
						<?php endif; ?>
					</select>
				</div>
				<button type="submit" name="copy_to_album">Copy</button>
			</form>

			<hr style="margin: 20px 0;">

			<h3>EXIF Data</h3>
			<?php if (!empty($exifData)): ?>
				<table class="exif-table">
					<?php foreach ($exifData as $key => $value): ?>
						<?php if ($value !== null && $value !== ''): ?>
							<tr>
								<th><?= htmlspecialchars($key) ?></th>
								<td>
									<?php
									if (is_array($value)) {
										echo htmlspecialchars(print_r($value, true));
									} else {
										echo htmlspecialchars($value);
									}
									?>
								</td>
							</tr>
						<?php endif; ?>
					<?php endforeach; ?>
				</table>
			<?php else: ?>
				<p>No EXIF data available for this photo.</p>
			<?php endif; ?>
		</div>
	</div>
</body>

</html>