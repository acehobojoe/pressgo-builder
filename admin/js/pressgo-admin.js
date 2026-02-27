/**
 * PressGo Admin — SSE stream consumer + live preview with iframe.
 */
(function () {
	'use strict';

	const $ = document.querySelector.bind(document);
	const $$ = document.querySelectorAll.bind(document);

	// Elements.
	let promptInput, generateBtn, workspace, activityLog, sectionPreview;
	let resultActions, editLink, viewLink, newBtn;
	let imageInput, imageName, imageClear, pageTitleInput;
	let emptyState;
	let importUrlInput, importConsentInput, importBtn, importPageTitleInput;
	let generateFields, importFields;
	let editorFields, editorSelect, editorLoadBtn;
	let previewTitle, liveIndicator, iframeWrapper, previewIframe;
	let imageData = null;
	let imageType = null;
	let isGenerating = false;
	let currentMode = 'generate';
	let pollingInterval = null;
	let currentPostId = null;
	let currentVersion = null;

	// Section display names.
	const SECTION_NAMES = {
		hero: 'Hero',
		stats: 'Stats',
		social_proof: 'Social Proof',
		features: 'Features',
		steps: 'How It Works',
		results: 'Results',
		competitive_edge: 'Competitive Edge',
		testimonials: 'Testimonials',
		faq: 'FAQ',
		blog: 'Blog',
		pricing: 'Pricing',
		logo_bar: 'Logo Bar',
		team: 'Team',
		gallery: 'Gallery',
		newsletter: 'Newsletter',
		map: 'Map',
		footer: 'Footer',
		cta_final: 'Final CTA',
		disclaimer: 'Disclaimer',
		colors: 'Color Palette',
		generating: 'Building Layout',
		creating_page: 'Creating Page',
	};

	// Section colors for preview blocks.
	const SECTION_COLORS = {
		hero: '#1a1a2e',
		stats: '#e8f0fe',
		social_proof: '#f7f8fc',
		features: '#f0f4ff',
		steps: '#ffffff',
		results: '#0a0f1e',
		competitive_edge: '#f7f8fc',
		testimonials: '#ffffff',
		pricing: '#1E293B',
		faq: '#f7f8fc',
		blog: '#ffffff',
		logo_bar: '#f7f8fc',
		team: '#ffffff',
		gallery: '#f7f8fc',
		newsletter: '#0043B3',
		map: '#ffffff',
		footer: '#1a1a2e',
		cta_final: '#0043B3',
		disclaimer: '#ffffff',
	};

	function init() {
		promptInput = $('#pressgo-prompt');
		generateBtn = $('#pressgo-generate-btn');
		workspace = $('#pressgo-workspace');
		activityLog = $('#pressgo-activity-log');
		sectionPreview = $('#pressgo-section-preview');
		resultActions = $('#pressgo-result-actions');
		editLink = $('#pressgo-edit-link');
		viewLink = $('#pressgo-view-link');
		newBtn = $('#pressgo-new-btn');
		imageInput = $('#pressgo-image');
		imageName = $('#pressgo-image-name');
		imageClear = $('#pressgo-image-clear');
		pageTitleInput = $('#pressgo-page-title');
		emptyState = $('#pressgo-empty-state');

		// Import mode elements.
		importUrlInput = $('#pressgo-import-url');
		importConsentInput = $('#pressgo-import-consent');
		importBtn = $('#pressgo-import-btn');
		importPageTitleInput = $('#pressgo-import-page-title');
		generateFields = $('#pressgo-generate-fields');
		importFields = $('#pressgo-import-fields');

		// Editor mode elements.
		editorFields = $('#pressgo-editor-fields');
		editorSelect = $('#pressgo-editor-select');
		editorLoadBtn = $('#pressgo-editor-load');

		// Iframe / live preview elements.
		previewTitle = $('#pressgo-preview-title');
		liveIndicator = $('#pressgo-live-indicator');
		iframeWrapper = $('#pressgo-iframe-wrapper');
		previewIframe = $('#pressgo-preview-iframe');

		if (!generateBtn) return;

		generateBtn.addEventListener('click', startGeneration);
		newBtn.addEventListener('click', resetUI);
		imageInput.addEventListener('change', handleImageSelect);
		imageClear.addEventListener('click', clearImage);

		// Allow Ctrl+Enter to submit.
		promptInput.addEventListener('keydown', function (e) {
			if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
				startGeneration();
			}
		});

		// Import mode events.
		if (importBtn) {
			importBtn.addEventListener('click', startImport);
		}
		if (importUrlInput) {
			importUrlInput.addEventListener('keydown', function (e) {
				if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
					startImport();
				}
			});
		}

		// Editor mode events.
		if (editorLoadBtn) {
			editorLoadBtn.addEventListener('click', loadEditorPreview);
		}

		// Mode toggle tabs.
		var tabs = $$('.pressgo-mode-tab');
		tabs.forEach(function (tab) {
			tab.addEventListener('click', function () {
				if (isGenerating) return;
				var mode = tab.getAttribute('data-mode');
				switchMode(mode);
			});
		});

		// Example prompt chips.
		var chips = $$('.pressgo-example-chip');
		chips.forEach(function (chip) {
			chip.addEventListener('click', function () {
				// Switch to generate mode if in import mode.
				if (currentMode !== 'generate') {
					switchMode('generate');
				}
				promptInput.value = chip.getAttribute('data-prompt');
				promptInput.focus();
				promptInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
			});
		});
	}

	function switchMode(mode) {
		currentMode = mode;

		// Update tab styles.
		var tabs = $$('.pressgo-mode-tab');
		tabs.forEach(function (tab) {
			if (tab.getAttribute('data-mode') === mode) {
				tab.classList.add('active');
			} else {
				tab.classList.remove('active');
			}
		});

		// Toggle field visibility.
		generateFields.style.display = 'none';
		importFields.style.display = 'none';
		if (editorFields) editorFields.style.display = 'none';

		if (mode === 'generate') {
			generateFields.style.display = '';
		} else if (mode === 'import') {
			importFields.style.display = '';
		} else if (mode === 'editor') {
			editorFields.style.display = '';
			fetchEditorPages();
		}
	}

	function fetchEditorPages() {
		if (!pressgoData.restUrl || !pressgoData.restNonce || !editorSelect) return;

		fetch(pressgoData.restUrl + '/pages', {
			headers: { 'X-WP-Nonce': pressgoData.restNonce },
			credentials: 'same-origin',
		})
			.then(function (r) { return r.ok ? r.json() : []; })
			.then(function (pages) {
				// Keep the placeholder option, clear the rest.
				editorSelect.innerHTML = '<option value="">Select a page...</option>';
				pages.forEach(function (page) {
					var opt = document.createElement('option');
					opt.value = page.id;
					opt.textContent = page.title + ' (ID ' + page.id + ')';
					opt.setAttribute('data-url', page.url);
					editorSelect.appendChild(opt);
				});
			})
			.catch(function () {
				// Silently fail — dropdown stays with placeholder.
			});
	}

	function loadEditorPreview() {
		if (!editorSelect) return;

		var postId = editorSelect.value;
		if (!postId) {
			editorSelect.classList.add('pressgo-shake');
			setTimeout(function () { editorSelect.classList.remove('pressgo-shake'); }, 500);
			return;
		}

		var selectedOption = editorSelect.options[editorSelect.selectedIndex];
		var viewUrl = selectedOption.getAttribute('data-url');
		if (!viewUrl) return;

		// Stop any existing polling.
		stopVersionPolling();

		// Show workspace, hide empty state + section blocks + result actions.
		if (emptyState) emptyState.style.display = 'none';
		workspace.style.display = 'block';
		activityLog.innerHTML = '';
		sectionPreview.innerHTML = '';
		sectionPreview.style.display = 'none';
		resultActions.style.display = 'none';

		// Show iframe preview with live polling.
		currentPostId = parseInt(postId, 10);
		showIframePreview(viewUrl, currentPostId);

		workspace.scrollIntoView({ behavior: 'smooth', block: 'start' });
	}

	function handleImageSelect(e) {
		const file = e.target.files[0];
		if (!file) return;

		// Validate size (10MB max).
		if (file.size > 10 * 1024 * 1024) {
			alert('Image must be under 10MB.');
			imageInput.value = '';
			return;
		}

		imageName.textContent = file.name;
		imageClear.style.display = 'inline';

		const reader = new FileReader();
		reader.onload = function (ev) {
			const result = ev.target.result;
			// Extract base64 data after the comma.
			imageData = result.split(',')[1];
			imageType = file.type;
		};
		reader.readAsDataURL(file);
	}

	function clearImage() {
		imageInput.value = '';
		imageName.textContent = '';
		imageClear.style.display = 'none';
		imageData = null;
		imageType = null;
	}

	function startGeneration() {
		const prompt = promptInput.value.trim();
		if (!prompt) {
			promptInput.focus();
			promptInput.classList.add('pressgo-shake');
			setTimeout(() => promptInput.classList.remove('pressgo-shake'), 500);
			return;
		}

		if (isGenerating) return;
		isGenerating = true;

		// Show workspace, hide empty state.
		if (emptyState) emptyState.style.display = 'none';
		workspace.style.display = 'block';
		activityLog.innerHTML = '';
		sectionPreview.innerHTML = '';
		sectionPreview.style.display = '';
		resultActions.style.display = 'none';
		generateBtn.disabled = true;
		generateBtn.innerHTML = '<span class="pressgo-spinner"></span> Generating...';

		// Reset iframe state.
		if (iframeWrapper) iframeWrapper.style.display = 'none';
		if (previewIframe) previewIframe.src = 'about:blank';
		if (previewTitle) previewTitle.textContent = 'Page Layout';
		if (liveIndicator) liveIndicator.style.display = 'none';
		stopVersionPolling();

		// Scroll to workspace.
		workspace.scrollIntoView({ behavior: 'smooth', block: 'start' });

		// Build form data.
		const formData = new FormData();
		formData.append('action', 'pressgo_generate_stream');
		formData.append('nonce', pressgoData.nonce);
		formData.append('prompt', prompt);
		formData.append('page_title', pageTitleInput.value.trim() || 'Generated Landing Page');

		if (imageData) {
			formData.append('image', imageData);
			formData.append('image_type', imageType);
		}

		// Use fetch with ReadableStream for SSE via POST.
		fetch(pressgoData.ajaxUrl, {
			method: 'POST',
			body: formData,
			credentials: 'same-origin',
		})
			.then((response) => {
				if (!response.ok) {
					throw new Error('HTTP ' + response.status);
				}
				return readSSEStream(response.body);
			})
			.catch((err) => {
				addActivity('error', 'Connection failed: ' + err.message);
				finishGeneration();
			});
	}

	function startImport() {
		const url = importUrlInput.value.trim();
		if (!url) {
			importUrlInput.focus();
			importUrlInput.classList.add('pressgo-shake');
			setTimeout(() => importUrlInput.classList.remove('pressgo-shake'), 500);
			return;
		}

		if (!importConsentInput.checked) {
			importConsentInput.parentElement.classList.add('pressgo-shake');
			setTimeout(() => importConsentInput.parentElement.classList.remove('pressgo-shake'), 500);
			return;
		}

		if (isGenerating) return;
		isGenerating = true;

		// Show workspace, hide empty state.
		if (emptyState) emptyState.style.display = 'none';
		workspace.style.display = 'block';
		activityLog.innerHTML = '';
		sectionPreview.innerHTML = '';
		sectionPreview.style.display = '';
		resultActions.style.display = 'none';
		importBtn.disabled = true;
		importBtn.innerHTML = '<span class="pressgo-spinner"></span> Importing...';

		// Reset iframe state.
		if (iframeWrapper) iframeWrapper.style.display = 'none';
		if (previewIframe) previewIframe.src = 'about:blank';
		if (previewTitle) previewTitle.textContent = 'Page Layout';
		if (liveIndicator) liveIndicator.style.display = 'none';
		stopVersionPolling();

		// Scroll to workspace.
		workspace.scrollIntoView({ behavior: 'smooth', block: 'start' });

		// Build form data.
		const formData = new FormData();
		formData.append('action', 'pressgo_import_stream');
		formData.append('nonce', pressgoData.nonce);
		formData.append('url', url);
		formData.append('consent', 'yes');
		formData.append('page_title', importPageTitleInput.value.trim() || 'Imported Page');

		// Use fetch with ReadableStream for SSE via POST (same as generate).
		fetch(pressgoData.ajaxUrl, {
			method: 'POST',
			body: formData,
			credentials: 'same-origin',
		})
			.then((response) => {
				if (!response.ok) {
					throw new Error('HTTP ' + response.status);
				}
				return readSSEStream(response.body);
			})
			.catch((err) => {
				addActivity('error', 'Connection failed: ' + err.message);
				finishGeneration();
			});
	}

	function readSSEStream(body) {
		const reader = body.getReader();
		const decoder = new TextDecoder();
		let buffer = '';

		function processChunk({ done, value }) {
			if (done) {
				// Process any remaining buffer.
				if (buffer.trim()) {
					processSSEBuffer(buffer);
				}
				finishGeneration();
				return;
			}

			buffer += decoder.decode(value, { stream: true });

			// Process complete SSE events (separated by double newline).
			const events = buffer.split('\n\n');
			buffer = events.pop(); // Keep the incomplete last chunk.

			for (const eventBlock of events) {
				processSSEEvent(eventBlock);
			}

			return reader.read().then(processChunk);
		}

		return reader.read().then(processChunk);
	}

	function processSSEBuffer(buf) {
		const events = buf.split('\n\n');
		for (const eventBlock of events) {
			if (eventBlock.trim()) {
				processSSEEvent(eventBlock);
			}
		}
	}

	function processSSEEvent(eventBlock) {
		let eventType = 'message';
		let eventData = '';

		const lines = eventBlock.split('\n');
		for (const line of lines) {
			if (line.startsWith('event: ')) {
				eventType = line.substring(7).trim();
			} else if (line.startsWith('data: ')) {
				eventData = line.substring(6);
			}
		}

		if (!eventData) return;

		let data;
		try {
			data = JSON.parse(eventData);
		} catch (e) {
			return;
		}

		switch (eventType) {
			case 'thinking':
				addActivity('thinking', data.text);
				break;

			case 'progress':
				handleProgress(data);
				break;

			case 'section':
				handleSectionPreview(data);
				break;

			case 'config':
				addActivity('success', 'Configuration generated successfully');
				break;

			case 'page_created':
				handlePageCreated(data);
				break;

			case 'error':
				addActivity('error', data.message);
				finishGeneration();
				break;
		}
	}

	function handleProgress(data) {
		const name = SECTION_NAMES[data.phase] || data.phase;
		addActivity('progress', data.detail || 'Building ' + name + '...');

		// Add section block to preview.
		if (SECTION_COLORS[data.phase]) {
			addSectionBlock(data.phase, 'building');
		}
	}

	function handleSectionPreview(data) {
		const block = document.getElementById('pressgo-section-' + data.key);
		if (block) {
			const preview = block.querySelector('.pressgo-section-detail');
			if (preview && data.preview) {
				preview.textContent = data.preview;
			}
			block.classList.remove('building');
			block.classList.add('complete');
		}
	}

	function handlePageCreated(data) {
		addActivity('done', 'Page created! (Post ID: ' + data.post_id + ')');

		// Mark all sections as complete.
		const blocks = sectionPreview.querySelectorAll('.pressgo-section-block');
		blocks.forEach((b) => {
			b.classList.remove('building');
			b.classList.add('complete');
		});

		// Show action buttons.
		editLink.href = data.edit_url;
		viewLink.href = data.view_url;
		resultActions.style.display = 'flex';

		// Switch to iframe preview after a short delay.
		currentPostId = data.post_id;
		setTimeout(function () {
			showIframePreview(data.view_url, data.post_id);
		}, 1000);

		finishGeneration();
	}

	/**
	 * Switch from section blocks to live iframe preview.
	 */
	function showIframePreview(viewUrl, postId) {
		if (!iframeWrapper || !previewIframe) return;

		// Hide section blocks, show iframe.
		sectionPreview.style.display = 'none';
		iframeWrapper.style.display = 'flex';

		// Load page in iframe (add preview=true for draft pages).
		var iframeSrc = viewUrl;
		if (iframeSrc.indexOf('?') !== -1) {
			iframeSrc += '&t=' + Date.now();
		} else {
			iframeSrc += '?t=' + Date.now();
		}
		previewIframe.src = iframeSrc;

		// Update header.
		if (previewTitle) previewTitle.textContent = 'Live Preview';
		if (liveIndicator) liveIndicator.style.display = 'flex';

		// Start polling for version changes.
		startVersionPolling(postId);
	}

	/**
	 * Poll version endpoint every 2 seconds. Reload iframe on change.
	 */
	function startVersionPolling(postId) {
		if (!pressgoData.restUrl || !pressgoData.restNonce) return;

		// Get initial version.
		fetchVersion(postId).then(function (v) {
			currentVersion = v;
		});

		pollingInterval = setInterval(function () {
			fetchVersion(postId).then(function (v) {
				if (v && currentVersion && v !== currentVersion) {
					currentVersion = v;
					reloadIframe();
					flashLiveIndicator();
					addActivity('success', 'Page updated externally — preview reloaded');
				}
			});
		}, 2000);
	}

	function stopVersionPolling() {
		if (pollingInterval) {
			clearInterval(pollingInterval);
			pollingInterval = null;
		}
		currentPostId = null;
		currentVersion = null;
	}

	function fetchVersion(postId) {
		return fetch(pressgoData.restUrl + '/pages/' + postId + '/version', {
			headers: { 'X-WP-Nonce': pressgoData.restNonce },
			credentials: 'same-origin',
		})
			.then(function (r) {
				return r.ok ? r.json() : null;
			})
			.then(function (data) {
				return data ? data.version : null;
			})
			.catch(function () {
				return null;
			});
	}

	function reloadIframe() {
		if (!previewIframe || previewIframe.src === 'about:blank') return;

		// Reload with cache-buster.
		var src = previewIframe.src.replace(/[?&]t=\d+/, '');
		var sep = src.indexOf('?') !== -1 ? '&' : '?';
		previewIframe.src = src + sep + 't=' + Date.now();
	}

	function flashLiveIndicator() {
		if (!liveIndicator) return;
		liveIndicator.classList.add('pressgo-live-flash');
		setTimeout(function () {
			liveIndicator.classList.remove('pressgo-live-flash');
		}, 1000);
	}

	function addSectionBlock(key, status) {
		// Don't add duplicate blocks.
		if (document.getElementById('pressgo-section-' + key)) return;

		const name = SECTION_NAMES[key] || key;
		const color = SECTION_COLORS[key] || '#f0f0f0';
		const isDark = isColorDark(color);

		const block = document.createElement('div');
		block.id = 'pressgo-section-' + key;
		block.className = 'pressgo-section-block ' + status;
		block.style.backgroundColor = color;
		block.innerHTML =
			'<div class="pressgo-section-label" style="color: ' +
			(isDark ? 'rgba(255,255,255,0.8)' : 'rgba(0,0,0,0.6)') +
			'">' +
			name +
			'</div>' +
			'<div class="pressgo-section-detail" style="color: ' +
			(isDark ? 'rgba(255,255,255,0.5)' : 'rgba(0,0,0,0.35)') +
			'"></div>';

		sectionPreview.appendChild(block);
	}

	function addActivity(type, text) {
		const entry = document.createElement('div');
		entry.className = 'pressgo-activity-entry pressgo-activity-' + type;

		const icons = {
			thinking: '&#9679;',
			progress: '&#9679;',
			success: '&#10003;',
			done: '&#10003;',
			error: '&#10007;',
		};

		entry.innerHTML =
			'<span class="pressgo-activity-icon">' +
			(icons[type] || '&#9679;') +
			'</span>' +
			'<span class="pressgo-activity-text">' +
			escapeHtml(text) +
			'</span>';

		activityLog.appendChild(entry);
		activityLog.scrollTop = activityLog.scrollHeight;
	}

	function finishGeneration() {
		isGenerating = false;
		generateBtn.disabled = false;
		generateBtn.innerHTML =
			'<span class="dashicons dashicons-superhero-alt"></span> Generate Page';
		if (importBtn) {
			importBtn.disabled = false;
			importBtn.innerHTML =
				'<span class="dashicons dashicons-download"></span> Import Page';
		}
	}

	function resetUI() {
		workspace.style.display = 'none';
		if (emptyState) emptyState.style.display = '';
		activityLog.innerHTML = '';
		sectionPreview.innerHTML = '';
		sectionPreview.style.display = '';
		resultActions.style.display = 'none';
		promptInput.value = '';
		pageTitleInput.value = '';
		clearImage();
		if (importUrlInput) importUrlInput.value = '';
		if (importConsentInput) importConsentInput.checked = false;
		if (importPageTitleInput) importPageTitleInput.value = '';

		// Reset iframe state.
		if (iframeWrapper) iframeWrapper.style.display = 'none';
		if (previewIframe) previewIframe.src = 'about:blank';
		if (previewTitle) previewTitle.textContent = 'Page Layout';
		if (liveIndicator) liveIndicator.style.display = 'none';
		stopVersionPolling();

		if (currentMode === 'generate') {
			promptInput.focus();
		} else if (importUrlInput) {
			importUrlInput.focus();
		}
	}

	function isColorDark(hex) {
		if (hex.startsWith('rgba')) return false;
		hex = hex.replace('#', '');
		const r = parseInt(hex.substr(0, 2), 16);
		const g = parseInt(hex.substr(2, 2), 16);
		const b = parseInt(hex.substr(4, 2), 16);
		return r * 0.299 + g * 0.587 + b * 0.114 < 128;
	}

	function escapeHtml(text) {
		const div = document.createElement('div');
		div.textContent = text;
		return div.innerHTML;
	}

	// Init on DOM ready.
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
