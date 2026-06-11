/* PressGo AI Builder — fullscreen UI logic */
(function () {
	if (!window.PressGoAI) return;
	var cfg = window.PressGoAI;

	var log    = document.getElementById('pg-chat-log');
	var form   = document.getElementById('pg-chat-form');
	var input  = document.getElementById('pg-chat-text');
	var sendBtn= document.getElementById('pg-chat-send');
	var frame  = document.getElementById('pg-preview-frame');
	// The reload-state class lives on .pg-preview (outer wrapper), not the
	// immediate parent .pg-preview-frame-wrap, so the sweep overlay can paint.
	var previewWrap = document.querySelector('.pg-preview');
	var credPill = document.getElementById('pg-credits');
	var visionInput = document.getElementById('pg-vision');
	var lastCreditValue = null;

	// ─── Viewport switcher ─────────────────────────────────────────────
	var stageInner = document.getElementById('pg-preview-stage-inner');
	document.querySelectorAll('.pg-vp-btn').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var vp = btn.getAttribute('data-viewport');
			document.querySelectorAll('.pg-vp-btn').forEach(function (b) { b.classList.toggle('is-active', b === btn); });
			if (stageInner) stageInner.setAttribute('data-viewport', vp);
		});
	});

	// ─── Image attach: drop, paste, file picker ────────────────────────
	var attachBtn    = document.getElementById('pg-attach-btn');
	var attachInput  = document.getElementById('pg-attach-input');
	var attachStrip  = document.getElementById('pg-attach-strip');
	var attachCount  = document.getElementById('pg-attach-count');
	var chatPanel    = document.getElementById('pg-chat');
	var pendingImages = []; // [{ dataUrl, mediaType, base64, name, id }]
	var MAX_IMAGES = 8;
	var imgSeq = 0;

	function addPendingImage(file) {
		if (!file || !/^image\//.test(file.type)) return;
		if (pendingImages.length >= MAX_IMAGES) {
			append(el('pg-msg-error', 'You can attach up to ' + MAX_IMAGES + ' images at once.'));
			return;
		}
		// Generous raw cap just to avoid browser-memory blowups — anything under
		// this gets DOWNSCALED below, so the upload payload stays tiny.
		if (file.size > 30 * 1024 * 1024) {
			append(el('pg-msg-error', 'That image is huge (30MB max). Try a smaller photo or a screenshot.'));
			return;
		}
		// Downscale before upload. A full-res photo (often 5-15MB) would blow past
		// a typical host's post_max_size (8M) and 413 on the way up; the AI only
		// needs to see layout/style, so a ~1600px JPEG (~200-500KB) is plenty and
		// works on every host. Falls back to the raw file if canvas resize fails.
		resizeImage(file, 1600, 0.85, function (resized) {
			if (!resized) { readRaw(file); return; }
			resized.id = 'img' + (++imgSeq);
			pendingImages.push(resized);
			renderStrip();
		});
	}

	// Draw the image onto a canvas scaled so its longest side <= maxDim, then
	// export as JPEG. Returns { dataUrl, base64, mediaType, name } via cb, or
	// null on failure (caller falls back to the raw file).
	function resizeImage(file, maxDim, quality, cb) {
		var name = (file.name || 'image').replace(/\.[^.]+$/, '') + '.jpg';
		function drawAndExport(source, w, h) {
			try {
				if (!w || !h) { cb(null); return; }
				var scale = Math.min(1, maxDim / Math.max(w, h));
				var cw = Math.max(1, Math.round(w * scale));
				var ch = Math.max(1, Math.round(h * scale));
				var canvas = document.createElement('canvas');
				canvas.width = cw; canvas.height = ch;
				var ctx = canvas.getContext('2d');
				if (!ctx) { cb(null); return; }
				ctx.drawImage(source, 0, 0, cw, ch);
				var dataUrl = canvas.toDataURL('image/jpeg', quality);
				var commaIdx = dataUrl.indexOf(',');
				// Guard against a blank decode: a real photo exports far more than
				// a few hundred bytes of base64. If it's tiny, treat as failure so
				// the caller falls back to the raw file.
				if (commaIdx < 0 || (dataUrl.length - commaIdx) < 800) { cb(null); return; }
				cb({ dataUrl: dataUrl, mediaType: 'image/jpeg', base64: dataUrl.slice(commaIdx + 1), name: name });
			} catch (e) { cb(null); }
		}
		// Prefer createImageBitmap: it decodes the file's bytes on call, so it is
		// immune to the file-input being cleared mid-decode (which on WebKit
		// invalidated the blob URL and produced blank images on multi-select).
		if (typeof createImageBitmap === 'function') {
			createImageBitmap(file).then(function (bmp) {
				var w = bmp.width, h = bmp.height;
				drawAndExport(bmp, w, h);
				if (bmp.close) { try { bmp.close(); } catch (e) {} }
			}).catch(function () { resizeViaImage(file, maxDim, quality, name, drawAndExport, cb); });
			return;
		}
		resizeViaImage(file, maxDim, quality, name, drawAndExport, cb);
	}

	// Fallback decode via <img> + object URL (older browsers without createImageBitmap).
	function resizeViaImage(file, maxDim, quality, name, drawAndExport, cb) {
		try {
			var url = URL.createObjectURL(file);
			var img = new Image();
			img.onload = function () {
				URL.revokeObjectURL(url);
				drawAndExport(img, img.naturalWidth || img.width, img.naturalHeight || img.height);
			};
			img.onerror = function () { try { URL.revokeObjectURL(url); } catch (e) {} cb(null); };
			img.src = url;
		} catch (e) { cb(null); }
	}

	// Raw fallback: keep the original bytes (used only when canvas resize fails,
	// e.g. an exotic format).
	function readRaw(file) {
		if (pendingImages.length >= MAX_IMAGES) return;
		var reader = new FileReader();
		reader.onload = function (e) {
			var dataUrl = e.target.result;
			var commaIdx = dataUrl.indexOf(',');
			pendingImages.push({
				dataUrl:   dataUrl,
				mediaType: file.type,
				base64:    dataUrl.slice(commaIdx + 1),
				name:      file.name || 'screenshot.png',
				id:        'img' + (++imgSeq),
			});
			renderStrip();
		};
		reader.readAsDataURL(file);
	}
	function removePendingImage(id) {
		pendingImages = pendingImages.filter(function (im) { return im.id !== id; });
		renderStrip();
	}
	function clearPendingImages() {
		pendingImages = [];
		if (attachInput) attachInput.value = '';
		renderStrip();
	}
	// Render the thumbnail strip (each thumb has a remove ×) + the count badge.
	function renderStrip() {
		if (!attachStrip) return;
		attachStrip.innerHTML = '';
		if (!pendingImages.length) {
			attachStrip.hidden = true;
			if (attachCount) attachCount.hidden = true;
			attachBtn && attachBtn.classList.remove('has-image');
			return;
		}
		attachStrip.hidden = false;
		pendingImages.forEach(function (im) {
			var cell = document.createElement('div');
			cell.className = 'pg-attach-thumb-cell';
			var thumb = document.createElement('img');
			thumb.src = im.dataUrl;
			thumb.alt = im.name || '';
			cell.appendChild(thumb);
			var x = document.createElement('button');
			x.type = 'button';
			x.className = 'pg-attach-thumb-x';
			x.setAttribute('aria-label', 'Remove image');
			x.innerHTML = '&times;';
			x.addEventListener('click', function () { removePendingImage(im.id); });
			cell.appendChild(x);
			attachStrip.appendChild(cell);
		});
		if (attachCount) { attachCount.textContent = String(pendingImages.length); attachCount.hidden = false; }
		attachBtn && attachBtn.classList.add('has-image');
	}

	// Click attach button: open the picker (add more — remove via the strip ×).
	attachBtn && attachBtn.addEventListener('click', function () {
		attachInput.click();
	});
	attachInput && attachInput.addEventListener('change', function (e) {
		var files = e.target.files || [];
		for (var i = 0; i < files.length; i++) addPendingImage(files[i]);
		attachInput.value = ''; // allow re-picking the same file
	});

	// Paste from clipboard inside the textarea (adds each pasted image).
	input.addEventListener('paste', function (e) {
		var items = (e.clipboardData && e.clipboardData.items) || [];
		var added = false;
		for (var i = 0; i < items.length; i++) {
			if (items[i].kind === 'file' && /^image\//.test(items[i].type)) {
				var file = items[i].getAsFile();
				if (file) { addPendingImage(file); added = true; }
			}
		}
		if (added) e.preventDefault();
	});

	// Drag-and-drop over the chat panel.
	var dragDepth = 0;
	function preventDefault(e) { e.preventDefault(); e.stopPropagation(); }
	['dragenter', 'dragover'].forEach(function (ev) {
		chatPanel.addEventListener(ev, function (e) {
			preventDefault(e);
			if (e.dataTransfer && Array.prototype.includes.call(e.dataTransfer.types || [], 'Files')) {
				dragDepth++;
				chatPanel.classList.add('is-dragover');
			}
		});
	});
	chatPanel.addEventListener('dragleave', function (e) {
		preventDefault(e);
		dragDepth = Math.max(0, dragDepth - 1);
		if (dragDepth === 0) chatPanel.classList.remove('is-dragover');
	});
	chatPanel.addEventListener('drop', function (e) {
		preventDefault(e);
		dragDepth = 0;
		chatPanel.classList.remove('is-dragover');
		var files = (e.dataTransfer && e.dataTransfer.files) || [];
		for (var i = 0; i < files.length; i++) addPendingImage(files[i]);
	});

	// Restore vision toggle from localStorage so the user's preference sticks.
	// On the very first build we force it ON regardless of any stored value, so
	// new users get the self-review QA pass out of the box (they can still turn
	// it off — the change handler below persists that choice for next time).
	try {
		if (cfg.firstRun) {
			if (visionInput) visionInput.checked = true;
		} else if (localStorage.getItem('pgVision') === '1') {
			visionInput.checked = true;
		}
	} catch (e) {}
	visionInput && visionInput.addEventListener('change', function () {
		try { localStorage.setItem('pgVision', visionInput.checked ? '1' : '0'); } catch (e) {}
	});

	function typingNode() {
		var d = document.createElement('div');
		d.className = 'pg-typing';
		d.setAttribute('aria-label', 'AI is thinking');
		d.innerHTML = '<span></span><span></span><span></span>';
		return d;
	}

	function flashCredits(newTotal) {
		if (typeof newTotal !== 'number') return;
		credPill.textContent = newTotal + ' credits';
		if (lastCreditValue !== null && newTotal !== lastCreditValue) {
			credPill.classList.remove('is-flash');
			// Force reflow so re-adding the class restarts the animation.
			void credPill.offsetWidth;
			credPill.classList.add('is-flash');
			setTimeout(function () { credPill.classList.remove('is-flash'); }, 700);
		}
		lastCreditValue = newTotal;
	}

	function el(cls, text) {
		var d = document.createElement('div');
		d.className = cls;
		if (text != null) d.textContent = text;
		return d;
	}

	function append(node) {
		log.appendChild(node);
		log.scrollTop = log.scrollHeight;
	}

	// Friendly labels for the progressive build checklist.
	var SECTION_LABELS = {
		hero: 'Hero', stats: 'Stats', social_proof: 'Social proof', features: 'Features',
		steps: 'How it works', results: 'Results', competitive_edge: 'Why us',
		testimonials: 'Testimonials', faq: 'FAQ', blog: 'Blog', pricing: 'Pricing',
		logo_bar: 'Logos', team: 'Team', gallery: 'Gallery', newsletter: 'Newsletter',
		cta_final: 'Call to action', map: 'Map', footer: 'Footer', disclaimer: 'Disclaimer'
	};
	function sectionLabel(name) {
		// Repeated-section instance keys: "steps#2" -> "Steps 2".
		var m = String(name || '').match(/^(.*)#([0-9]+)$/);
		if (m) return sectionLabel(m[1]) + ' ' + m[2];
		if (SECTION_LABELS[name]) return SECTION_LABELS[name];
		return String(name || '').replace(/_/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); });
	}

	// First-run starter prompts. Editable example pre-filled in the box +
	// one-tap chips to swap the whole idea. Goal: never face a blank box.
	var STARTERS = [
		{ chip: 'Roofing company', text: 'A landing page for my roofing company that gets homeowners to book a free roof inspection. Highlight that we\'re local, licensed, and fast. Include reviews and a quote form.' },
		{ chip: 'Yoga studio', text: 'A landing page for my yoga studio that gets people to sign up for a free intro class. Calm, welcoming vibe with class types, a schedule, and a signup form.' },
		{ chip: 'SaaS app', text: 'A landing page for my SaaS app that gets visitors to start a free trial. Clear value prop, three key features, social proof, and a strong call to action.' },
		{ chip: 'Dentist', text: 'A landing page for my dental practice that gets new patients to request an appointment. Friendly and trustworthy, with services, insurance info, and a booking form.' },
		{ chip: 'Restaurant', text: 'A landing page for my restaurant that drives reservations and online orders. Showcase the menu highlights, atmosphere photos, hours, and location.' },
	];

	function renderFirstRun() {
		append(el('pg-msg-system', 'You\'re in — let\'s build your first page. I dropped an example below; tweak it to match your business (or tap a different starter), then hit Send.'));
		// Starter chips row.
		var chips = document.createElement('div');
		chips.className = 'pg-starter-chips';
		chips.style.cssText = 'display:flex;flex-wrap:wrap;gap:8px;margin:6px 0 2px;';
		STARTERS.forEach(function (s) {
			var b = document.createElement('button');
			b.type = 'button';
			b.textContent = s.chip;
			b.className = 'pg-starter-chip';
			b.style.cssText = 'border:1px solid #d9d6ff;background:#f3f1ff;color:#5b4fff;border-radius:999px;padding:5px 12px;font-size:12px;cursor:pointer;line-height:1.2;';
			b.addEventListener('click', function () {
				input.value = s.text;
				input.focus();
			});
			chips.appendChild(b);
		});
		append(chips);
		// Pre-fill the box with the first example, ready to edit + Send — but
		// never overwrite something the user already started typing (a late
		// history retry can land here seconds after page load).
		if (!input.value) input.value = STARTERS[0].text;
		setTimeout(function () { input.focus(); }, 50);
	}

	function renderHistory(messages) {
		// Never clobber a live conversation (belt to chatStarted's suspenders).
		if (log.querySelector('.pg-msg-user')) return;
		log.innerHTML = '';
		if (!messages || !messages.length) {
			if (cfg.firstRun) { renderFirstRun(); return; }
			append(el('pg-msg-system', 'Tell me what kind of page you want — business, vibe, the goal. I\'ll ask 1-2 follow-ups then build a first draft.'));
			return;
		}
		messages.forEach(function (m) {
			if (m.role === 'user') append(el('pg-msg pg-msg-user', m.content));
			else if (m.role === 'assistant') {
				if (m.content) append(el('pg-msg pg-msg-assistant', m.content));
				if (m.built) {
					var b = el('pg-msg pg-msg-built');
					b.innerHTML = '<strong>Built:</strong> ' + escapeHtml(m.summary || '(page updated)');
					append(b);
				}
			}
		});
	}

	function escapeHtml(s) {
		return String(s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function setBusy(busy) {
		sendBtn.disabled = busy;
		input.disabled = busy;
		if (busy) startElapsed();
		else { stopElapsed(); sendBtn.textContent = 'Send'; }
	}

	// Lightweight elapsed-time readout on the Send button so a 20–40s build
	// doesn't feel hung. "~30s typical" sets the expectation.
	var elapsedTimer = null;
	function startElapsed() {
		stopElapsed();
		var t0 = Date.now();
		function tick() {
			var s = Math.round((Date.now() - t0) / 1000);
			sendBtn.textContent = s < 1 ? 'Thinking…'
				: 'Thinking… ' + s + 's' + (s < 30 ? '' : ' · almost there');
		}
		tick();
		elapsedTimer = setInterval(tick, 1000);
		sendBtn.title = 'A full page build usually takes ~30 seconds';
	}
	function stopElapsed() {
		if (elapsedTimer) { clearInterval(elapsedTimer); elapsedTimer = null; }
	}

	// Saved across reloads so the iframe stays scrolled to whatever section
	// the user was looking at when they asked for an edit. Without this,
	// every build snaps them back to the hero and they lose context.
	var savedScrollY = 0;
	function reloadPreview(bust) {
		try {
			// Capture current scroll position before tearing down the doc.
			try {
				if (frame.contentWindow && frame.contentWindow.scrollY !== undefined) {
					savedScrollY = frame.contentWindow.scrollY;
				}
			} catch (e) { /* cross-origin would block; we're same-origin so OK */ }
			previewWrap.classList.add('is-reloading');
			var url = cfg.previewBase;
			var sep = url.indexOf('?') === -1 ? '?' : '&';
			var fresh = url + sep + 'pg_clean=1&_t=' + (bust || Date.now()) + '&_r=' + Math.random().toString(36).slice(2, 8);
			try {
				if (frame.contentWindow && frame.contentWindow.location) {
					frame.contentWindow.location.replace(fresh);
				} else {
					frame.src = fresh;
				}
			} catch (e) {
				frame.src = fresh;
			}
		} catch (e) { /* noop */ }
	}

	// Belt-and-suspenders: even with show_admin_bar(false), some
	// plugins (Elementor Pro Notes, Elementor Debugger, etc.) inject their
	// own toolbars. Same-origin iframe means we can reach into its document
	// and strip them on every load. Also drop the reload overlay here so
	// the fade-in syncs to "actually rendered" not just "src changed".
	function onIframeLoad() {
		try {
			var doc = frame.contentDocument || (frame.contentWindow && frame.contentWindow.document);
			if (doc) {
				var css = doc.createElement('style');
				css.id = 'pg-iframe-scrub';
				css.textContent = [
					'#wpadminbar { display: none !important; }',
					'html.wp-toolbar { padding-top: 0 !important; }',
					'html { margin-top: 0 !important; }',
					'#elementor-editor-wrapper-bar, .e-pro-notes, .e-pro-notes-trigger { display: none !important; }'
				].join('\n');
				doc.head && doc.head.appendChild(css);
				var bar = doc.getElementById('wpadminbar');
				if (bar && bar.parentNode) bar.parentNode.removeChild(bar);
			}
		} catch (e) { /* cross-origin — give up */ }
		// Restore the scroll position the user was at before the rebuild
		// so they stay anchored on whatever section they were editing.
		try {
			if (savedScrollY > 0 && frame.contentWindow) {
				frame.contentWindow.scrollTo(0, savedScrollY);
			}
		} catch (e) { /* cross-origin or detached */ }
		// Slight delay so the eye sees the sweep + blur before clearing.
		setTimeout(function () { previewWrap.classList.remove('is-reloading'); }, 180);
	}
	frame.addEventListener('load', onIframeLoad);

	function refreshCredits() {
		var fd = new FormData();
		fd.append('action', 'pressgo_ai_credits');
		fd.append('nonce', cfg.nonce);
		fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
			.then(function (r) { return r.ok ? r.json() : null; })
			.then(function (j) {
				if (j && j.success && j.data && typeof j.data.total === 'number') {
					// Set initial value without flashing (no previous value to diff).
					credPill.textContent = j.data.total + ' credits';
					lastCreditValue = j.data.total;
				}
			})
			.catch(function () { /* leave default pill */ });
	}

	// Set the moment the user sends their first message this session. A pending
	// loadHistory retry that resolves AFTER that must become a no-op — its
	// renderHistory() would wipe the live optimistic bubble + streaming nodes
	// and the build would stream into detached DOM.
	var chatStarted = false;

	function loadHistory(attempt) {
		attempt = attempt || 0;
		var fd = new FormData();
		fd.append('action', 'pressgo_ai_get_chat');
		fd.append('nonce', cfg.nonce);
		fd.append('post_id', cfg.postId);
		fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
			.then(function (r) { if (!r.ok) throw new Error('http ' + r.status); return r.json(); })
			.then(function (j) {
				if (chatStarted) return; // user already talking — don't clobber the live log
				if (j && j.success) renderHistory(j.data.messages || []);
				else throw new Error('bad payload');
			})
			.catch(function () {
				if (chatStarted) return;
				// A busy server (saturated PHP pool) used to land here and we
				// rendered the EMPTY intro — which reads as "my conversation got
				// deleted" even though the history is safe in the database.
				// Retry with backoff; only after that, say so instead of lying.
				if (attempt < 3) {
					setTimeout(function () { loadHistory(attempt + 1); }, 1200 * (attempt + 1));
					return;
				}
				renderHistory([]);
				var err = el('pg-msg pg-msg-assistant');
				err.textContent = "I couldn't load this page's chat history (the server is busy) — it isn't lost. ";
				var retry = document.createElement('a');
				retry.href = '#';
				retry.textContent = 'Tap to retry';
				retry.addEventListener('click', function (e) {
					e.preventDefault();
					if (chatStarted) { err.remove(); return; } // mid-conversation: just dismiss
					log.innerHTML = '';
					loadHistory(0);
				});
				err.appendChild(retry);
				append(err);
			});
	}

	function sendMessage(text) {
		chatStarted = true; // any still-pending history retries become no-ops
		// Optimistic user bubble — shows any attached images inline.
		var userBubble = el('pg-msg pg-msg-user');
		if (pendingImages.length) {
			pendingImages.forEach(function (im) {
				var thumb = document.createElement('img');
				thumb.src = im.dataUrl;
				thumb.className = 'pg-msg-user-image';
				userBubble.appendChild(thumb);
			});
		}
		if (text) {
			var txt = document.createElement('div');
			txt.textContent = text;
			userBubble.appendChild(txt);
		}
		append(userBubble);
		input.value = '';
		var typing = typingNode();
		append(typing);
		setBusy(true);

		var fd = new FormData();
		fd.append('action', 'pressgo_ai_chat');
		fd.append('nonce', cfg.nonce);
		fd.append('post_id', cfg.postId);
		fd.append('message', text);
		if (visionInput && visionInput.checked) fd.append('vision', '1');
		if (pendingImages.length) {
			fd.append('images', JSON.stringify(pendingImages.map(function (im) {
				return { base64: im.base64, mediaType: im.mediaType };
			})));
			// Clear immediately so a second send doesn't double-attach.
			clearPendingImages();
		}

		// Streaming consumer state.
		var assistantBubble = null;     // current text bubble being filled
		var visionNotice    = null;     // "A(eyes) reviewing…" pill, if any
		var buildList       = null;     // progressive "building…" checklist element
		var buildRows       = {};       // section name -> row element
		var streamFailed    = false;
		var typingDismissed = false;
		var abortController = (typeof AbortController !== 'undefined') ? new AbortController() : null;
		// Expose so the Clear-chat handler can abort in-flight streams.
		window.__pgActiveStream = abortController;

		// Stream watchdog. The server holds the connection open through slow
		// phases (a full build streams the config silently; the A(eyes) pass
		// fetches screenshots then reviews). Both now send heartbeats ('ping' /
		// 'vision_progress') at least every few seconds. If we go fully silent
		// past STALL_MS the stream has died (server hang, dropped socket) — so we
		// abort and clear the UI instead of spinning forever. This is the fix for
		// "A(eyes) just kept checking til I refreshed."
		var STALL_MS = 40000;
		var lastEventAt = Date.now();
		var builtOnce = false;
		var watchdogAborted = false;
		var watchdog = null;
		function clearWatchdog() { if (watchdog) { clearInterval(watchdog); watchdog = null; } }
		function checkStall() {
			if (Date.now() - lastEventAt < STALL_MS) return;
			clearWatchdog();
			watchdogAborted = true;
			try { if (abortController) abortController.abort(); } catch (e) {}
			dismissTypingOnce();
			if (visionNotice) { visionNotice.remove(); visionNotice = null; }
			clearBuildList();
			// If the page already built, a stalled review is harmless — finish
			// quietly. Otherwise the build itself stalled; tell the user how to
			// recover.
			if (!streamFailed && !builtOnce) {
				append(el('pg-msg-warn', 'That stalled before finishing. Your page may have saved anyway — refresh the preview, or send your request again.'));
			}
			setBusy(false);
		}

		function ensureAssistantBubble() {
			if (!assistantBubble) {
				assistantBubble = el('pg-msg pg-msg-assistant', '');
				append(assistantBubble);
			}
			return assistantBubble;
		}
		function dismissTypingOnce() {
			if (!typingDismissed) { typing.remove(); typingDismissed = true; }
		}
		function resetAssistantBubble() { assistantBubble = null; }

		// Progressive build checklist — shows sections appearing as the page builds.
		function renderPlan(sections) {
			if (buildList || !sections || !sections.length) return;
			dismissTypingOnce();
			buildList = el('pg-build-list');
			var head = el('pg-build-head', 'Building your page…');
			buildList.appendChild(head);
			sections.forEach(function (name) {
				var row = el('pg-build-row is-pending');
				row.innerHTML = '<span class="pg-build-dot"></span><span class="pg-build-label">' + escapeHtml(sectionLabel(name)) + '</span>';
				buildRows[name] = row;
				buildList.appendChild(row);
			});
			append(buildList);
		}
		function tickSection(name) {
			// If a section arrives we didn't plan for, add a row on the fly.
			if (!buildList) renderPlan([name]);
			var row = buildRows[name];
			if (!row && buildList) {
				row = el('pg-build-row is-pending');
				row.innerHTML = '<span class="pg-build-dot"></span><span class="pg-build-label">' + escapeHtml(sectionLabel(name)) + '</span>';
				buildRows[name] = row;
				buildList.appendChild(row);
			}
			if (row) { row.className = 'pg-build-row is-done'; }
			if (log) log.scrollTop = log.scrollHeight;
		}
		function clearBuildList() {
			if (buildList) { buildList.remove(); buildList = null; }
			buildRows = {};
		}

		function handleEvent(evt) {
			if (!evt || !evt.type) return;
			lastEventAt = Date.now();   // any event (incl. heartbeats) keeps the watchdog armed
			switch (evt.type) {
				case 'ping':
				case 'vision_progress':
					// Heartbeat only — server is alive mid-build/review. No UI.
					return;
				case 'plan':
					// Ordered section list known — lay out the live checklist.
					renderPlan(evt.sections || []);
					return;
				case 'section':
					// A section finished generating — tick it.
					tickSection(evt.name);
					return;
				case 'section_preview':
					// The plugin rendered the page so far — refresh the preview so
					// the user watches it grow section by section.
					reloadPreview(evt.preview_bust || Date.now());
					return;
				case 'text':
					dismissTypingOnce();
					var b = ensureAssistantBubble();
					b.textContent = (b.textContent || '') + (evt.text || '');
					log.scrollTop = log.scrollHeight;
					break;
				case 'built':
					dismissTypingOnce();
					resetAssistantBubble();
					clearBuildList();
					builtOnce = true;
					var built = el('pg-msg pg-msg-built');
					built.innerHTML = '<strong>Built:</strong> ' + escapeHtml(evt.summary || '(page updated)');
					append(built);
					reloadPreview(evt.preview_bust);
					if (typeof evt.credits_remaining === 'number') flashCredits(evt.credits_remaining);
					maybeAskReview();
					break;
				case 'apply_error':
					dismissTypingOnce();
					resetAssistantBubble();
					append(el('pg-msg-error', 'Build failed: ' + (evt.message || 'unknown')));
					break;
				case 'vision_start':
					dismissTypingOnce();
					resetAssistantBubble();
					visionNotice = el('pg-msg-reviewing', 'A(eyes) reviewing…');
					append(visionNotice);
					break;
				case 'vision_built':
					if (visionNotice) { visionNotice.remove(); visionNotice = null; }
					resetAssistantBubble();
					builtOnce = true;
					var fix = el('pg-msg pg-msg-built');
					fix.innerHTML = '<strong>Vision fix:</strong> ' + escapeHtml(evt.summary || '(corrected after self-review)');
					append(fix);
					reloadPreview(evt.preview_bust || Date.now());
					if (typeof evt.credits_remaining === 'number') flashCredits(evt.credits_remaining);
					break;
				case 'vision_ok':
					if (visionNotice) { visionNotice.remove(); visionNotice = null; }
					// During the vision pass, 'text' deltas already streamed
					// into the current assistantBubble. Only append a fresh
					// bubble as a fallback if NO text was streamed (e.g. AI
					// emitted vision_ok via the tool-less branch with text only
					// in the final event). Otherwise this would duplicate the
					// reply.
					if (evt.text && !assistantBubble) {
						append(el('pg-msg pg-msg-assistant', evt.text));
					}
					resetAssistantBubble();
					break;
				case 'error':
					dismissTypingOnce();
					resetAssistantBubble();
					streamFailed = true;
					var errBubble = el('pg-msg-error');
					var msgText = evt.message || evt.error || 'Chat error';
					var msgDiv = document.createElement('div');
					msgDiv.textContent = msgText;
					errBubble.appendChild(msgDiv);
					// Render action buttons if backend supplied them (e.g.
					// INSUFFICIENT_CREDITS → Buy credits / Pay as you go).
					if (Array.isArray(evt.actions) && evt.actions.length) {
						var row = document.createElement('div');
						row.className = 'pg-error-actions';
						evt.actions.forEach(function (a) {
							var btn = document.createElement('a');
							btn.href = a.url;
							btn.target = '_blank';
							btn.rel = 'noopener';
							btn.textContent = a.label;
							btn.className = 'pg-error-btn' + (a.primary ? ' is-primary' : '');
							row.appendChild(btn);
						});
						errBubble.appendChild(row);
					}
					append(errBubble);
					break;
				case 'done':
					// Stream finished. Guarantee the "A(eyes) reviewing…" pill is
					// gone even if the vision pass returned without a terminal
					// vision_ok/vision_built (e.g. its screenshot timed out). The
					// watchdog only catches a SILENT stall, not a clean finish
					// with an orphaned pill — this is that backstop.
					if (visionNotice) { visionNotice.remove(); visionNotice = null; }
					clearBuildList();
					if (typeof evt.credits_remaining === 'number') flashCredits(evt.credits_remaining);
					// The model hit the length limit mid-build — the page may be
					// cut off. Tell the user plainly how to recover.
					if (evt.truncated) {
						append(el('pg-msg-warn', 'Heads up — this page may be incomplete (it hit the length limit). Try asking me to "finish the page" or split it into a couple of smaller requests.'));
					}
					break;
			}
		}

		// Arm the watchdog now — covers the whole request, including first-token
		// latency before any event arrives.
		lastEventAt = Date.now();
		watchdog = setInterval(checkStall, 5000);

		// SSE consumer over fetch(). Streams as bytes arrive; parses
		// "data: {...}\n\n" events on the fly. No EventSource because we
		// need to POST with cookies + form fields.
		fetch(cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: fd,
			headers: { 'Accept': 'text/event-stream' },
			signal: abortController ? abortController.signal : undefined,
		}).then(function (r) {
			if (!r.ok) {
				return r.text().then(function (body) {
					var snippet = (body || '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 140);
					throw new Error('Upstream error ' + r.status + (snippet ? ' — ' + snippet : '') + ' — try again');
				});
			}
			if (!r.body || !r.body.getReader) {
				throw new Error('Streaming not supported in this browser.');
			}
			var reader  = r.body.getReader();
			var decoder = new TextDecoder('utf-8');
			var buf = '';
			function pump() {
				return reader.read().then(function (out) {
					if (out.done) return;
					buf += decoder.decode(out.value, { stream: true });
					// Split out complete events terminated by blank line.
					var idx;
					while ((idx = buf.indexOf('\n\n')) !== -1) {
						var raw = buf.slice(0, idx);
						buf = buf.slice(idx + 2);
						var lines = raw.split(/\r?\n/);
						for (var i = 0; i < lines.length; i++) {
							var line = lines[i];
							if (line.indexOf('data:') !== 0) continue;
							var json = line.slice(5).trim();
							if (!json) continue;
							try { handleEvent(JSON.parse(json)); } catch (e) { /* skip malformed */ }
						}
					}
					return pump();
				});
			}
			return pump();
		}).then(function () {
			clearWatchdog();
			dismissTypingOnce();
			// Final safety net: never leave the review pill or build checklist
			// hanging after the stream resolves, whatever events arrived.
			if (visionNotice) { visionNotice.remove(); visionNotice = null; }
			clearBuildList();
			setBusy(false);
			input.focus();
		}).catch(function (e) {
			clearWatchdog();
			// The watchdog already aborted and reported — don't double-message.
			if (watchdogAborted) { setBusy(false); return; }
			dismissTypingOnce();
			if (visionNotice) { visionNotice.remove(); visionNotice = null; }
			clearBuildList();
			if (!streamFailed) {
				var raw = (e && e.message) ? e.message : '';
				var friendly;
				if (/Failed to fetch|NetworkError|Load failed/i.test(raw)) {
					friendly = 'Network error — check your connection and try again.';
				} else if (/^Upstream error/i.test(raw)) {
					friendly = raw;
				} else {
					friendly = raw || 'Something went wrong — try again.';
				}
				append(el('pg-msg-error', friendly));
			}
			setBusy(false);
		});
	}

	form.addEventListener('submit', function (e) {
		e.preventDefault();
		var text = (input.value || '').trim();
		if (!text) return;
		sendMessage(text);
	});

	// Clear-chat button — drops the stored conversation for this page and
	// wipes the visible log. Does NOT touch the rendered page itself.
	// Replaced native confirm() with an in-DOM modal so headless test
	// browsers (which sometimes block native confirm) can interact with
	// it, AND so we can abort any in-flight chat stream before clearing
	// (otherwise the stream keeps pumping into a no-longer-existing DOM
	// for the full upstream window — that was the 45s lock).
	var clearBtn = document.getElementById('pg-clear-chat');
	if (clearBtn) {
		clearBtn.addEventListener('click', function () { openClearConfirm(); });
	}

	function openClearConfirm() {
		// Don't double-open.
		if (document.getElementById('pg-clear-modal')) return;
		var modal = document.createElement('div');
		modal.id = 'pg-clear-modal';
		modal.className = 'pg-modal-backdrop';
		modal.innerHTML =
			'<div class="pg-modal" role="dialog" aria-labelledby="pg-clear-modal-title" aria-modal="true">' +
				'<h3 id="pg-clear-modal-title" class="pg-modal-title">Clear chat history?</h3>' +
				'<p class="pg-modal-body">Your built page stays exactly as it is — only the conversation gets reset.</p>' +
				'<div class="pg-modal-actions">' +
					'<button type="button" class="pg-modal-btn" id="pg-clear-cancel">Cancel</button>' +
					'<button type="button" class="pg-modal-btn is-danger" id="pg-clear-confirm">Clear chat</button>' +
				'</div>' +
			'</div>';
		document.body.appendChild(modal);
		var cancel = modal.querySelector('#pg-clear-cancel');
		var confirmBtn = modal.querySelector('#pg-clear-confirm');
		function close() { modal.remove(); document.removeEventListener('keydown', onKey); }
		function onKey(e) {
			if (e.key === 'Escape') close();
			if (e.key === 'Enter' && document.activeElement !== cancel) doClear();
		}
		document.addEventListener('keydown', onKey);
		cancel.addEventListener('click', close);
		modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
		function doClear() {
			confirmBtn.disabled = true; confirmBtn.textContent = 'Clearing…';
			// Abort any active chat stream so it doesn't keep pumping into
			// the cleared DOM and hold the page hostage.
			try { if (window.__pgActiveStream) window.__pgActiveStream.abort(); } catch (e) {}
			var fd = new FormData();
			fd.append('action', 'pressgo_ai_clear_chat');
			fd.append('nonce', cfg.nonce);
			fd.append('post_id', cfg.postId);
			fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
				.then(function (r) { return r.ok ? r.json() : null; })
				.then(function (j) {
					close();
					if (j && j.success) {
						log.innerHTML = '';
						append(el('pg-msg-system', 'Chat cleared. The page itself is unchanged — start fresh whenever you want.'));
					} else {
						append(el('pg-msg-error', 'Could not clear chat — try again.'));
					}
				})
				.catch(function () {
					close();
					append(el('pg-msg-error', 'Could not clear chat — try again.'));
				});
		}
		confirmBtn.addEventListener('click', doClear);
		setTimeout(function () { confirmBtn.focus(); }, 10);
	}

	// ===== Review ask (after 5 successful builds, once, dismissible) =====
	// Only happy users ever see this: 5+ builds, shown right after a SUCCESSFUL
	// build (peak satisfaction), max 3 appearances ever, any click ends it.
	var reviewAskRendered = false;
	function maybeAskReview() {
		var r = cfg.review;
		if (!r || !r.ask || reviewAskRendered) return;
		reviewAskRendered = true;
		var card = el('pg-msg pg-msg-built');
		card.style.borderColor = '#F59E0B';
		var txt = document.createElement('div');
		txt.innerHTML = '<strong>That’s ' + (r.builds || 5) + ' pages built with PressGo.</strong> If it’s been useful, a quick review genuinely keeps this thing going.';
		card.appendChild(txt);
		var rowEl = document.createElement('div');
		rowEl.style.cssText = 'margin-top:10px;display:flex;gap:8px;';
		function done(choice) {
			var fd = new FormData();
			fd.append('action', 'pressgo_ai_review_done');
			fd.append('nonce', cfg.nonce);
			fd.append('choice', choice);
			fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd });
			card.remove();
		}
		var yes = document.createElement('a');
		yes.href = r.url;
		yes.target = '_blank';
		yes.rel = 'noopener';
		yes.textContent = '⭐ Leave a review';
		yes.style.cssText = 'background:#F59E0B;color:#fff;border-radius:8px;padding:8px 14px;font-size:13px;font-weight:600;text-decoration:none;';
		yes.addEventListener('click', function () { done('reviewed'); });
		var no = document.createElement('button');
		no.type = 'button';
		no.textContent = 'No thanks';
		no.style.cssText = 'background:transparent;border:1px solid #e2e0f4;color:#6b7280;border-radius:8px;padding:8px 14px;font-size:13px;cursor:pointer;';
		no.addEventListener('click', function () { done('dismissed'); });
		rowEl.appendChild(yes);
		rowEl.appendChild(no);
		card.appendChild(rowEl);
		append(card);
	}

	// ===== Continuous branding toggle =====
	// Renders only once a brand foundation exists (auto-learned from the first
	// build, or set via MCP). ON = new pages reuse the site's palette/fonts/
	// identity; OFF = each page gets a fresh brand.
	(function () {
		var b = cfg.brand;
		if (!b || !b.exists) return;
		// Compact topbar chip (next to History/Clear chat) instead of a second
		// footer toggle — the footer stays A(eyes)-only and uncluttered.
		var actions = document.querySelector('.pg-builder-actions');
		if (!actions) return;
		var chip = document.createElement('button');
		chip.type = 'button';
		chip.className = 'pg-builder-ghost';
		chip.title = 'Site brand: new pages reuse this site\'s saved colors, fonts, and identity' + (b.name ? ' (' + b.name + ')' : '') + '. Click to toggle; off gives a page its own fresh look.';
		var on = !!b.enabled;
		function paint() {
			chip.innerHTML = '<span style="display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:6px;vertical-align:1px;background:' + (on ? '#22C55E' : '#9CA3AF') + ';"></span>Brand';
			chip.style.opacity = on ? '1' : '0.65';
		}
		paint();
		actions.insertBefore(chip, actions.firstChild);
		chip.addEventListener('click', function () {
			on = !on;
			paint();
			var fd = new FormData();
			fd.append('action', 'pressgo_ai_brand_toggle');
			fd.append('nonce', cfg.nonce);
			fd.append('enabled', on ? '1' : '');
			fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd });
		});
	})();

	// ===== Version history (design snapshots) =====
	// Every AI change snapshots the previous design as a WP revision (visible in
	// Elementor's History too). This panel surfaces those snapshots right in the
	// builder with one-click restore; a restore saves the current design first,
	// so no state is ever lost by clicking around.
	(function () {
		var btn = document.getElementById('pg-history');
		if (!btn) return;
		var panel = null;

		function close() {
			if (panel) { panel.remove(); panel = null; document.removeEventListener('click', onDocClick, true); }
		}
		function onDocClick(e) {
			if (panel && !panel.contains(e.target) && e.target !== btn) close();
		}

		function open() {
			if (panel) { close(); return; }
			panel = document.createElement('div');
			panel.id = 'pg-history-panel';
			panel.style.cssText = 'position:fixed;top:52px;right:16px;width:340px;max-height:70vh;overflow:auto;background:#fff;border:1px solid #e2e0f4;border-radius:10px;box-shadow:0 12px 32px rgba(20,16,60,0.18);z-index:99999;padding:10px 12px;font-size:13px;color:#1f2937;';
			var head = document.createElement('div');
			head.style.cssText = 'margin-bottom:8px;';
			head.innerHTML = '<strong>Page history</strong><div style="color:#6b7280;font-size:12px;margin-top:2px;">A version is saved before every AI change. Restoring saves the current design too, so nothing is ever lost.</div>';
			panel.appendChild(head);
			var list = document.createElement('div');
			list.style.cssText = 'color:#6b7280;';
			list.textContent = 'Loading…';
			panel.appendChild(list);
			document.body.appendChild(panel);
			setTimeout(function () { document.addEventListener('click', onDocClick, true); }, 0);

			var fd = new FormData();
			fd.append('action', 'pressgo_ai_versions');
			fd.append('nonce', cfg.nonce);
			fd.append('post_id', cfg.postId);
			fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
				.then(function (r) { return r.json(); })
				.then(function (j) {
					if (!j || !j.success) throw new Error('bad payload');
					var versions = j.data.versions || [];
					if (!versions.length) {
						list.textContent = j.data.revisions_enabled
							? 'No saved versions yet. One is saved automatically before every AI change.'
							: 'Revisions are disabled on this site (WP_POST_REVISIONS), so versions can\'t be saved.';
						return;
					}
					list.innerHTML = '';
					list.style.color = '';
					versions.forEach(function (ver) {
						var item = document.createElement('div');
						item.style.cssText = 'display:flex;align-items:center;gap:8px;padding:8px 2px;border-top:1px solid #f0effa;';
						var info = document.createElement('div');
						info.style.cssText = 'flex:1;min-width:0;';
						var line1 = document.createElement('div');
						line1.textContent = ver.date + ' · ' + ver.ago + (ver.sections ? ' · ' + ver.sections + ' sections' : '');
						info.appendChild(line1);
						if (ver.label) {
							var line2 = document.createElement('div');
							line2.style.cssText = 'color:#6b7280;font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;';
							line2.textContent = ver.label;
							info.appendChild(line2);
						}
						var rbtn = document.createElement('button');
						rbtn.type = 'button';
						rbtn.textContent = 'Restore';
						rbtn.style.cssText = 'border:1px solid #d9d6ff;background:#f3f1ff;color:#5b4fff;border-radius:6px;padding:4px 10px;font-size:12px;cursor:pointer;flex-shrink:0;';
						rbtn.addEventListener('click', function () { restore(ver, rbtn); });
						item.appendChild(info);
						item.appendChild(rbtn);
						list.appendChild(item);
					});
				})
				.catch(function () { list.textContent = 'Could not load versions — try again.'; });
		}

		function restore(ver, rbtn) {
			if (!window.confirm('Restore the version from ' + ver.date + '? Your current design is saved first, so you can switch back.')) return;
			rbtn.disabled = true;
			rbtn.textContent = 'Restoring…';
			var fd = new FormData();
			fd.append('action', 'pressgo_ai_restore');
			fd.append('nonce', cfg.nonce);
			fd.append('post_id', cfg.postId);
			fd.append('revision_id', ver.id);
			fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
				.then(function (r) { return r.json(); })
				.then(function (j) {
					if (!j || !j.success) throw new Error('restore failed');
					close();
					reloadPreview(j.data.preview_bust || Date.now());
					append(el('pg-msg-system', 'Restored the design from ' + (j.data.restored_from || ver.date) + '. The replaced design was saved to History too, so you can switch back any time.'));
				})
				.catch(function () {
					rbtn.disabled = false;
					rbtn.textContent = 'Restore';
					append(el('pg-msg-error', 'Could not restore that version — try again.'));
				});
		}

		btn.addEventListener('click', open);
	})();

	// Enter to send (Shift+Enter for newline)
	input.addEventListener('keydown', function (e) {
		if (e.key === 'Enter' && !e.shiftKey) {
			e.preventDefault();
			form.dispatchEvent(new Event('submit', { cancelable: true }));
		}
	});

	loadHistory();
	refreshCredits();
})();
