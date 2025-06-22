# Next Photos

A simple, performant, and self-hosted PHP-based web photo gallery for albums stored in Nextcloud.

This project was developed to provide a fast and responsive interface for large photo collections without overloading the Nextcloud instance on every visit. It uses a robust server-side caching mechanism to manage metadata and thumbnails efficiently.

*(A screenshot of the gallery could be placed here)*

## Features

*   **Performant Gallery View:** Displays all images from a specified Nextcloud folder in a grid view.
*   **Efficient Caching:** Metadata (EXIF information, image dimensions) is cached in a server-side file (`metadata.json`). Image thumbnails are also cached to dramatically reduce loading times.
*   **Asynchronous Loading:** The cache is built in the background via an AJAX interface, without blocking the website. A progress bar informs the user of the status.
*   **Lazy Loading:** Images are only loaded when they scroll into the user's viewport, minimizing the initial page load time.
*   **EXIF Data:** Reads and displays important EXIF data from JPEG images (camera model, exposure time, aperture, GPS coordinates, etc.).
*   **PNG Support:** Also displays PNG images in the gallery (without EXIF data).
*   **Secure Proxy:** Nextcloud credentials are never exposed to the frontend. A PHP backend acts as a secure proxy to fetch the images from the server.

## Technical Architecture

The core of the application is a two-stage caching process designed for stability and performance with large numbers of images:

1.  **Initialization (`init`):** First, a list of all image paths is fetched from the Nextcloud directory. This step uses a `curl` command via `shell_exec`, as this proved to be the most stable method for recursive queries. The list is saved in `photolist.json`.
2.  **Processing (`process`):** A JavaScript client repeatedly calls the `process` action. Each call processes a small batch of photos (e.g., 5):
    *   The image is downloaded from Nextcloud.
    *   Metadata is extracted (EXIF for JPEGs, dimensions for PNGs).
    *   The extracted data is appended as a single line to the `metadata.json` file (JSONL format).

This approach prevents PHP timeouts and "Memory Limit" errors, as only a small amount of data is held in memory at any time.

## Installation

1.  **Clone the repository:**
    ```bash
    git clone https://github.com/YOUR_USERNAME/YOUR_REPO.git
    cd YOUR_REPO
    ```

2.  **Install dependencies:**
    ```bash
    composer install
    ```

3.  **Create configuration:**
    Create a `config.php` file in the project's root directory with the following content and adjust the values:
    ```php
    <?php

    // URL to your Nextcloud instance
    define('NEXTCLOUD_URL', 'https://your.nextcloud.url');

    // Your Nextcloud username
    define('NEXTCLOUD_USERNAME', 'your_user');

    // An app-specific password created for this application
    // IMPORTANT: Do not use your regular password here!
    define('NEXTCLOUD_PASSWORD', 'your-app-password');

    // The path to the photo folder in Nextcloud (relative to the user's file root)
    define('NEXTCLOUD_PHOTO_DIR', 'Photos');

    ```

4.  **Configure web server:**
    Configure your web server (e.g., Apache or Nginx) to use the `public` directory as the document root.

5.  **Set permissions:**
    Ensure that the web server user (e.g., `www-data`) has write permissions for the `cache` directory.
    ```bash
    # Execute from the project's root directory
    sudo chown -R www-data:www-data cache
    sudo chmod -R 775 cache
    ```

## Usage

1.  Open the configured URL in your browser.
2.  On the first visit, the gallery will be empty. Click the **"Refresh Gallery Cache"** button.
3.  The progress bar shows the status of the caching process. This may take a while for very large collections.
4.  Once the process is complete, the page will reload, and the gallery will be displayed with all the images.

## Key Dependencies

*   [lsolesen/pel](https://github.com/lsolesen/pel) for reading and writing EXIF data.
*   [sabre/dav](https://sabre.io/dav/) for WebDAV communication. 