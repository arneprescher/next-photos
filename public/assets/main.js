document.addEventListener('DOMContentLoaded', function () {
	const refreshBtn = document.getElementById('refresh-cache-btn')
	const cancelBtn = document.getElementById('cancel-cache-btn')
	const progressContainer = document.getElementById('progress-container')
	const progressBar = document.getElementById('progress-bar')
	const progressText = document.getElementById('progress-text')

	let isCaching = false
	let cancelRequested = false

	refreshBtn.addEventListener('click', () => {
		if (isCaching) return
		isCaching = true
		cancelRequested = false
		refreshBtn.disabled = true
		cancelBtn.style.display = 'inline-block'
		progressContainer.style.display = 'block'
		progressText.textContent = 'Initializing cache...'

		// Step 1: Initialize the cache on the server. This creates the list of photos to process.
		fetch('cache_manager.php?action=init')
			.then(response => response.json())
			.then(data => {
				if (data.status === 'error') throw new Error(data.message)
				progressText.textContent = `Found ${data.total} photos. Starting cache...`
				// Step 2: Start processing the first batch.
				processNextBatch(0)
			})
			.catch(handleError)
	})

	cancelBtn.addEventListener('click', () => {
		if (!isCaching) return
		cancelRequested = true
		cancelBtn.disabled = true
		progressText.textContent = 'Cancelling...'

		fetch('cache_manager.php?action=cancel')
			.then(response => response.json())
			.then(data => {
				if (data.status === 'error') throw new Error(data.message)
				isCaching = false
				progressText.textContent = 'Caching cancelled. Reloading page.'
				setTimeout(() => window.location.reload(), 1500)
			})
			.catch(handleError)
	})

	/**
	 * Fetches and processes the next batch of photos.
	 * It passes the number of already processed photos as an offset.
	 * The function calls itself recursively until the server reports 'complete'.
	 * @param {number} processedCount - The number of items already processed.
	 */
	function processNextBatch(processedCount) {
		if (cancelRequested) {
			isCaching = false
			return
		}

		fetch('cache_manager.php?action=process&offset=' + processedCount)
			.then(response => response.json())
			.then(data => {
				if (data.status === 'error') throw new Error(data.message)

				updateProgress(data.processed, data.total)

				if (data.status === 'caching') {
					setTimeout(() => processNextBatch(data.processed), 200) // Continue with next batch
				} else if (data.status === 'complete') {
					progressText.textContent = 'Cache complete! Reloading...'
					setTimeout(() => window.location.reload(), 1000)
				} else {
					// Any other status (e.g., idle after cancellation) stops the loop
					isCaching = false
					resetUI()
				}
			})
			.catch(handleError)
	}

	function updateProgress(processed, total) {
		if (total > 0) {
			const percentage = Math.round((processed / total) * 100)
			progressBar.value = percentage
			progressText.textContent = `Processing... ${processed} / ${total} photos (${percentage}%)`
		}
	}

	function handleError(error) {
		progressText.textContent = 'Error: ' + error.message
		progressText.style.color = 'red'
		isCaching = false
		refreshBtn.disabled = false
		cancelBtn.style.display = 'none'
		cancelBtn.disabled = false
	}

	function resetUI() {
		refreshBtn.disabled = false
		cancelBtn.style.display = 'none'
		cancelBtn.disabled = false
		progressContainer.style.display = 'none'
		progressBar.value = 0
	}

	// On page load, check if a caching process is already running.
	// If so, automatically resume displaying the progress.
	// The initialStatus is passed from PHP to a global variable in the HTML.
	if (typeof initialCacheStatus !== 'undefined' && (initialCacheStatus.status === 'caching' || initialCacheStatus.status === 'finishing')) {
		isCaching = true
		refreshBtn.disabled = true
		cancelBtn.style.display = 'inline-block'
		progressContainer.style.display = 'block'
		updateProgress(initialCacheStatus.processed, initialCacheStatus.total)
		processNextBatch(initialCacheStatus.processed)
	}

	// --- Image Lazy Loading with Concurrency Limit ---
	// This system prevents the browser from trying to download all images at once.
	// It uses an IntersectionObserver to detect when an image is about to enter the viewport.
	const imageQueue = []
	let activeDownloads = 0
	const MAX_CONCURRENT_DOWNLOADS = 10 // Limits simultaneous image requests.

	const mediaElements = document.querySelectorAll('[data-src]')

	/**
	 * Processes the image queue, loading the next image if the concurrency limit is not reached.
	 */
	function processImageQueue() {
		if (activeDownloads >= MAX_CONCURRENT_DOWNLOADS || imageQueue.length === 0) {
			return
		}

		activeDownloads++
		const mediaElement = imageQueue.shift()
		const src = mediaElement.dataset.src

		if (mediaElement.tagName === 'IMG') {
			const tempImg = new Image()
			tempImg.src = src

			tempImg.onload = () => {
				mediaElement.src = tempImg.src
				mediaElement.removeAttribute('data-src')
				activeDownloads--
				processImageQueue()
			}

			tempImg.onerror = () => {
				console.error('Could not load image: ' + src)
				activeDownloads--
				processImageQueue()
			}
		} else if (mediaElement.tagName === 'VIDEO') {
			mediaElement.src = src
			mediaElement.addEventListener('loadeddata', () => {
				mediaElement.removeAttribute('data-src')
				activeDownloads--
				processImageQueue()
			}, {
				once: true
			})
			mediaElement.addEventListener('error', () => {
				console.error('Could not load video: ' + src)
				activeDownloads--
				processImageQueue()
			}, {
				once: true
			})
			mediaElement.play().catch(() => {
				// Autoplay might be blocked, which is fine. The user can play it manually.
			})
		}
	}

	// Use IntersectionObserver to efficiently detect when images scroll into view.
	if ('IntersectionObserver' in window) {
		const observer = new IntersectionObserver((entries, observer) => {
			entries.forEach(entry => {
				if (entry.isIntersecting) {
					imageQueue.push(entry.target)
					processImageQueue()
					observer.unobserve(entry.target) // Stop observing once it's been queued.
				}
			})
		}, {
			rootMargin: "0px 0px 250px 0px"
		}) // Pre-load media that are 250px below the viewport.

		mediaElements.forEach(img => observer.observe(img))
	} else {
		// Fallback for browsers without IntersectionObserver
		mediaElements.forEach(media => {
			media.src = media.dataset.src
		})
	}
}) 