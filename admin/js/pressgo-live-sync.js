/**
 * PressGo Live Sync — toggle pill + polling import.
 *
 * Drops a small "Live" pill into the bottom-right of the Elementor editor.
 * When green, polls /pressgo/v1/page-rev/{id} every 2s. On revision change
 * (i.e. an external write to _elementor_data via the MCP server / chat),
 * fetches /pressgo/v1/page-data/{id} and re-imports the document so the
 * editor reflects the change without a page reload.
 *
 * Local drag-and-drop edits are untouched — the user updates the JS doc
 * normally; we only react when the *server* hash differs from what we
 * last fetched.
 */
(function () {
	'use strict';

	if (typeof window.pressgoLiveSync === 'undefined') return;
	var cfg = window.pressgoLiveSync;
	if (!cfg.postId) return;

	var STATE_KEY = 'pressgoLiveSync.enabled';
	var enabled = localStorage.getItem(STATE_KEY) !== '0'; // default ON
	var lastRev = null;
	var pollTimer = null;
	var inFlight = false;
	var pillEl = null;

	function makePill() {
		var pill = document.createElement('button');
		pill.type = 'button';
		pill.className = 'pressgo-live-pill';
		pill.setAttribute('aria-pressed', enabled ? 'true' : 'false');
		pill.title = 'PressGo Live — chat edits sync in real time';
		pill.innerHTML =
			'<span class="pressgo-live-dot" aria-hidden="true"></span>' +
			'<span class="pressgo-live-label">Live</span>';
		pill.addEventListener('click', function () { setEnabled(!enabled); });
		document.body.appendChild(pill);
		render();
		return pill;
	}

	function render() {
		if (!pillEl) return;
		pillEl.classList.toggle('pressgo-live-on', enabled);
		pillEl.classList.toggle('pressgo-live-off', !enabled);
		pillEl.setAttribute('aria-pressed', enabled ? 'true' : 'false');
	}

	function flash() {
		if (!pillEl) return;
		pillEl.classList.add('pressgo-live-flash');
		setTimeout(function () { pillEl && pillEl.classList.remove('pressgo-live-flash'); }, 600);
	}

	function setEnabled(next) {
		enabled = !!next;
		localStorage.setItem(STATE_KEY, enabled ? '1' : '0');
		render();
		if (enabled) startPolling();
		else stopPolling();
	}

	function startPolling() {
		stopPolling();
		// Fire once immediately to seed lastRev, then on interval.
		pollOnce().then(function () {
			pollTimer = setInterval(pollOnce, cfg.pollMs || 2000);
		});
	}

	function stopPolling() {
		if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
	}

	function pollOnce() {
		if (inFlight) return Promise.resolve();
		inFlight = true;
		return fetch(cfg.restRoot + 'page-rev/' + cfg.postId, {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': cfg.nonce },
		})
			.then(function (r) { return r.ok ? r.json() : null; })
			.then(function (json) {
				if (!json || !json.rev) return;
				if (lastRev === null) { lastRev = json.rev; return; }
				if (json.rev !== lastRev) {
					lastRev = json.rev;
					return fetchAndApply();
				}
			})
			.catch(function () { /* network blip — try again next tick */ })
			.then(function () { inFlight = false; });
	}

	function fetchAndApply() {
		return fetch(cfg.restRoot + 'page-data/' + cfg.postId, {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': cfg.nonce },
		})
			.then(function (r) { return r.ok ? r.json() : null; })
			.then(function (json) {
				if (!json || !Array.isArray(json.elements)) return;
				applyElements(json.elements, json.page_settings || {});
				flash();
			});
	}

	/**
	 * Replace the live document's elements with the freshly-fetched array.
	 * Uses the lower-level Backbone reset so Elementor re-renders without
	 * marking the document dirty (we just pulled the canonical state from
	 * the server, so it IS the saved state).
	 */
	function applyElements(elements, pageSettings) {
		try {
			if (typeof window.elementor === 'undefined' || !elementor.documents) return;
			var doc = elementor.documents.getCurrent();
			if (!doc) return;

			// Reset preview's element collection — triggers full re-render.
			var preview = elementor.getPreviewView && elementor.getPreviewView();
			if (preview && preview.collection) {
				preview.collection.reset(elements);
			} else if (doc.elements && doc.elements.reset) {
				doc.elements.reset(elements);
			}

			// Sync page settings model if present.
			if (pageSettings && doc.config && doc.config.settings) {
				try {
					var settingsModel = doc.config.settings.settings || {};
					Object.keys(pageSettings).forEach(function (k) {
						settingsModel[k] = pageSettings[k];
					});
				} catch (e) { /* non-fatal */ }
			}

			// Don't leave the doc marked dirty — we just pulled saved state.
			if (typeof window.$e !== 'undefined' && $e.internal) {
				try { $e.internal('document/save/set-is-modified', { status: false }); }
				catch (e) { /* older elementor */ }
			}
		} catch (err) {
			// Soft-fail — user can always reload manually.
			if (window.console && console.warn) console.warn('[PressGo Live] apply failed:', err);
		}
	}

	function boot() {
		pillEl = makePill();
		if (enabled) startPolling();
	}

	// Wait until Elementor's editor has finished booting so the preview
	// iframe + document model are available.
	if (typeof window.elementor !== 'undefined' && elementor.on) {
		elementor.on('preview:loaded', boot);
	} else {
		// Fallback — element editor JS not yet defined; poll.
		var tries = 0;
		var iv = setInterval(function () {
			tries++;
			if (typeof window.elementor !== 'undefined' && elementor.on) {
				clearInterval(iv);
				elementor.on('preview:loaded', boot);
			} else if (tries > 80) { // ~20s
				clearInterval(iv);
			}
		}, 250);
	}
})();
