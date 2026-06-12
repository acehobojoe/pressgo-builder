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

	var lowCreditNudged = false;
	var brandHintShown = false;
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
		// Credit pressure: at <=3 the pill goes amber and clickable, and the
		// chat gets ONE quiet nudge per session. Every purchase ever made
		// happened around credit pressure — this recreates that moment before
		// the hard zero instead of after it.
		if (newTotal <= 3) {
			credPill.classList.add('is-low');
			credPill.title = 'Running low — click to top up';
			credPill.style.cursor = 'pointer';
			credPill.onclick = function () {
				window.open('https://pressgo.app/dashboard?buy=credits', '_blank');
			};
			if (!lowCreditNudged && newTotal > 0) {
				lowCreditNudged = true;
				var nudge = el('pg-msg pg-msg-note');
				nudge.appendChild(document.createTextNode(
					newTotal + (newTotal === 1 ? ' credit' : ' credits') + ' left this month. A $15 pack adds 75 so you can keep building — '));
				var a = document.createElement('a');
				a.href = 'https://pressgo.app/dashboard?buy=credits';
				a.target = '_blank';
				a.textContent = 'top up here';
				nudge.appendChild(a);
				nudge.appendChild(document.createTextNode('.'));
				append(nudge);
			}
		} else {
			credPill.classList.remove('is-low');
			credPill.onclick = null;
			credPill.style.cursor = '';
		}
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

	// The next-page loop: retained users all follow the same arc unprompted —
	// homepage, then About/Services/Contact/404. After the first successful
	// build of the session, offer that arc as one-click chips. Each chip
	// creates a NEW draft page (building in this chat would overwrite the page
	// just built) and lands in its builder with the prompt pre-filled.
	var NEXT_PAGES = [
		{ chip: 'About',    title: 'About',    text: 'An About page for the same business as my other pages. Our story, what makes us different, and a friendly call to action. Match the site brand.' },
		{ chip: 'Services', title: 'Services', text: 'A Services page for the same business, one section per main service with short benefit-led descriptions, and a strong call to action. Match the site brand.' },
		{ chip: 'Contact',  title: 'Contact',  text: 'A Contact page for the same business with a contact form and our real contact details (ask me for anything you don\'t have). Match the site brand.' },
		{ chip: '404 page', title: '404',      text: 'A friendly 404 page matching the site brand: short apology, one button back to the homepage.' },
	];
	var nextPagesOffered = false;
	function maybeOfferNextPages() {
		if (nextPagesOffered) return;
		nextPagesOffered = true;
		var card = el('pg-msg pg-next-pages');
		card.appendChild(el('pg-next-pages-head', 'Most sites also need a few more pages. One tap starts the next one (same brand, new page):'));
		var row = document.createElement('div');
		row.style.cssText = 'display:flex;flex-wrap:wrap;gap:8px;margin-top:8px;';
		NEXT_PAGES.forEach(function (p) {
			var b = document.createElement('button');
			b.type = 'button';
			b.textContent = p.chip;
			b.className = 'pg-starter-chip';
			b.style.cssText = 'border:1px solid #d9d6ff;background:#f3f1ff;color:#5b4fff;border-radius:999px;padding:5px 12px;font-size:12px;cursor:pointer;line-height:1.2;';
			b.addEventListener('click', function () {
				b.disabled = true;
				b.textContent = 'Creating…';
				var fd = new FormData();
				fd.append('action', 'pressgo_ai_create_page');
				fd.append('nonce', cfg.nonce);
				fd.append('title', p.title);
				fetch(cfg.ajaxUrl, { method: 'POST', body: fd })
					.then(function (r) { return r.json(); })
					.then(function (j) {
						if (j && j.success && j.data && j.data.post_id) {
							window.location = 'admin.php?page=pressgo-ai-builder&action=edit&post_id=' +
								j.data.post_id + '&prefill=' + encodeURIComponent(p.text);
						} else {
							b.disabled = false; b.textContent = p.chip;
						}
					})
					.catch(function () { b.disabled = false; b.textContent = p.chip; });
			});
			row.appendChild(b);
		});
		card.appendChild(row);
		append(card);
	}

	function renderHistory(messages) {
		// Never clobber a live conversation (belt to chatStarted's suspenders).
		if (log.querySelector('.pg-msg-user')) return;
		log.innerHTML = '';
		if (!messages || !messages.length) {
			if (cfg.firstRun) { renderFirstRun(); return; }
			// A next-page chip landed us here with a ready prompt.
			if (cfg.prefill && !input.value) {
				append(el('pg-msg-system', 'New page, same brand. Tweak the prompt below if you like, then hit Send.'));
				input.value = cfg.prefill;
				setTimeout(function () { input.focus(); }, 50);
				return;
			}
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
			// Compact: the long "Thinking… 37s · almost there" label squeezed
			// the textarea into a sliver. Status swaps text, never grows.
			sendBtn.textContent = s < 1 ? 'Thinking…' : ( s < 30 ? s + 's…' : 'Almost…' );
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

	// Visual editor selection (set by the Select-mode module below). When a
	// section is selected, chat requests carry it so the AI scopes its patch.
	var selectedSectionKey = '';

	// ─── Double-buffered preview swap ──────────────────────────────────
	// The old reloadPreview() navigated the visible iframe — a blank flash,
	// a scroll jump, and a second of "where am I" on every apply. Instead,
	// every refresh now loads the new render into a HIDDEN spare iframe;
	// when its load event fires we copy the visible frame's scroll position
	// into it and atomically swap visibility. Ping-pong: the old frame
	// becomes the next spare. The user never sees a loading frame.
	//
	// Perf probe for tests (no console noise): timestamps land here.
	window.__pgPerf = { lastInputAt: 0, flushAt: 0, savedAt: 0, swapDoneAt: 0, pendingSwap: false, swaps: 0 };

	var spareFrame   = null;   // hidden buffer iframe (after first swap: the previous active)
	var swapBusy     = false;  // one swap in flight at a time
	var swapNextBust = null;   // latest-wins: a reload requested mid-swap
	var swapWatchdog = null;

	// Hooks run whenever the ACTIVE frame has a fresh, ready document
	// (initial load, in-frame navigation, or a completed buffered swap).
	// The visual editor re-arms select mode + re-outlines the selection here.
	var frameReadyHooks = [];
	function onFrameReady(fn) { frameReadyHooks.push(fn); }
	function notifyFrameReady() {
		for (var i = 0; i < frameReadyHooks.length; i++) {
			try { frameReadyHooks[i](); } catch (e) { /* keep the rest alive */ }
		}
	}

	// Belt-and-suspenders: even with show_admin_bar(false), some plugins
	// (Elementor Pro Notes, Elementor Debugger, etc.) inject their own
	// toolbars. Same-origin iframe means we can strip them from any doc.
	function scrubDoc(doc) {
		if (!doc) return;
		try {
			if (!doc.getElementById('pg-iframe-scrub')) {
				var css = doc.createElement('style');
				css.id = 'pg-iframe-scrub';
				css.textContent = [
					'#wpadminbar { display: none !important; }',
					'html.wp-toolbar { padding-top: 0 !important; }',
					'html { margin-top: 0 !important; }',
					'#elementor-editor-wrapper-bar, .e-pro-notes, .e-pro-notes-trigger { display: none !important; }'
				].join('\n');
				(doc.head || doc.body).appendChild(css);
			}
			var bar = doc.getElementById('wpadminbar');
			if (bar && bar.parentNode) bar.parentNode.removeChild(bar);
		} catch (e) { /* cross-origin — give up */ }
	}

	function previewUrl(bust) {
		var url = cfg.previewBase;
		var sep = url.indexOf('?') === -1 ? '?' : '&';
		return url + sep + 'pg_clean=1&_t=' + (bust || Date.now()) + '&_r=' + Math.random().toString(36).slice(2, 8);
	}

	// Per-iframe load dispatch: the ACTIVE frame loading = initial load or
	// in-frame navigation; a BUFFER frame loading completes a swap.
	function attachFrameLoad(f) {
		f.addEventListener('load', function () {
			if (f === frame) {
				onActiveFrameLoad();
			} else if (f.__pgSwapPending) {
				f.__pgSwapPending = false;
				completeSwap(f);
			}
		});
	}

	function onActiveFrameLoad() {
		scrubDoc(frame.contentDocument);
		// Restore the scroll position the user was at before a hard reload
		// (the buffered-swap path restores its own scroll in completeSwap).
		try {
			if (savedScrollY > 0 && frame.contentWindow) {
				frame.contentWindow.scrollTo(0, savedScrollY);
			}
		} catch (e) { /* detached */ }
		setTimeout(function () { previewWrap.classList.remove('is-reloading'); }, 180);
		notifyFrameReady();
	}

	function ensureSpare() {
		if (spareFrame) return spareFrame;
		spareFrame = document.createElement('iframe');
		spareFrame.className = 'pg-frame-hidden';
		spareFrame.setAttribute('aria-hidden', 'true');
		spareFrame.setAttribute('tabindex', '-1');
		attachFrameLoad(spareFrame);
		return spareFrame;
	}

	function reloadPreview(bust) {
		if (!stageInner) { hardReloadPreview(bust); return; }
		if (swapBusy) { swapNextBust = bust || Date.now(); return; } // latest-wins queue
		swapBusy = true;
		previewWrap.classList.add('is-refreshing'); // 2px shimmer only — never blur/blank
		var next = ensureSpare();
		next.__pgSwapPending = true;
		var fresh = previewUrl(bust);
		try {
			if (next.parentNode && next.contentWindow && next.contentWindow.location) {
				// location.replace: no history entry per refresh.
				next.contentWindow.location.replace(fresh);
			} else {
				next.src = fresh;
				if (!next.parentNode) stageInner.appendChild(next);
			}
		} catch (e) {
			next.src = fresh;
			if (!next.parentNode) stageInner.appendChild(next);
		}
		clearTimeout(swapWatchdog);
		swapWatchdog = setTimeout(function () {
			// Buffer never finished (dropped connection?) — fall back to a
			// plain reload of the visible frame so the preview never sticks
			// stale. This is the only path that may show the loading state.
			if (!swapBusy) return;
			next.__pgSwapPending = false;
			swapBusy = false;
			previewWrap.classList.remove('is-refreshing');
			hardReloadPreview();
		}, 20000);
	}

	// Legacy single-frame reload — watchdog fallback only.
	function hardReloadPreview(bust) {
		try {
			try {
				if (frame.contentWindow && frame.contentWindow.scrollY !== undefined) {
					savedScrollY = frame.contentWindow.scrollY;
				}
			} catch (e) {}
			previewWrap.classList.add('is-reloading');
			var fresh = previewUrl(bust);
			try {
				if (frame.contentWindow && frame.contentWindow.location) {
					frame.contentWindow.location.replace(fresh);
				} else {
					frame.src = fresh;
				}
			} catch (e) { frame.src = fresh; }
		} catch (e) { /* noop */ }
	}

	function completeSwap(next) {
		clearTimeout(swapWatchdog);
		var old = frame;
		// Carry the user's CURRENT scroll into the fresh document before it
		// becomes visible — captured at swap time, not request time, so any
		// scrolling done while the buffer loaded is preserved too.
		var y = savedScrollY;
		try { y = old.contentWindow.scrollY || 0; } catch (e) {}
		scrubDoc(next.contentDocument);
		try { if (next.contentWindow) next.contentWindow.scrollTo(0, y); } catch (e) {}
		savedScrollY = y;
		// Atomic visibility flip in one synchronous turn — the compositor
		// only ever paints exactly one fully-rendered frame.
		next.classList.remove('pg-frame-hidden');
		old.classList.add('pg-frame-hidden');
		frame = next;
		spareFrame = old;
		swapBusy = false;
		previewWrap.classList.remove('is-refreshing');
		window.__pgPerf.swaps++;
		if (window.__pgPerf.pendingSwap) {
			window.__pgPerf.swapDoneAt = performance.now();
			window.__pgPerf.pendingSwap = false;
		}
		notifyFrameReady();
		if (swapNextBust) { var b = swapNextBust; swapNextBust = null; reloadPreview(b); }
	}

	attachFrameLoad(frame);

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
		// Visual editor scoping: with a section selected, the server narrows
		// the AI's patch to that key.
		if (selectedSectionKey) fd.append('selected_section', selectedSectionKey);
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
					// Stagger: when the review ask renders this turn, hold the
					// next-page chips for the following build — two cards
					// stacking after one event buries both.
					if (!maybeAskReview()) { maybeOfferNextPages(); }
					// First build on a brand-less site just LEARNED the brand —
					// surface it without requiring a reload.
					if (cfg.brand && !cfg.brand.exists && !brandHintShown) {
						brandHintShown = true;
						append(el('pg-msg-system', 'Site brand learned from this build — new pages will match it. Reload the builder to see the Brand control.'));
					}
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
					// Project-aware credit wall: "out of credits" lands harder when
					// it names the work in progress instead of reading like a meter.
					if (evt.code === 'INSUFFICIENT_CREDITS' && cfg.review && cfg.review.builds > 0) {
						msgText = 'You’re ' + cfg.review.builds + ' page' + (cfg.review.builds === 1 ? '' : 's') +
							' into this site and out of credits. A $15 pack (75 credits) finishes it.';
					}
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

	// ===== Page status pill + publish + rename =====
	(function () {
		var pill = document.getElementById('pg-status-pill');
		var titleEl = document.getElementById('pg-page-title');
		var page = cfg.page || {};
		function paintPill() {
			if (!pill) return;
			var live = page.status === 'publish';
			pill.textContent = live ? 'Live' : 'Draft';
			pill.className = 'pg-status-pill' + (live ? ' is-live' : '');
			pill.title = live
				? 'This page is live. Click to view it; shift-click to unpublish.'
				: 'This page is a draft only you can see. Click to publish it.';
		}
		function setStatus(publish) {
			var fd = new FormData();
			fd.append('action', 'pressgo_ai_publish');
			fd.append('nonce', cfg.nonce);
			fd.append('post_id', cfg.postId);
			fd.append('publish', publish ? '1' : '');
			fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
				.then(function (r) { return r.json(); })
				.then(function (j) {
					if (j && j.success) {
						page.status = j.data.status;
						page.url = j.data.url;
						paintPill();
						append(el('pg-msg-system', page.status === 'publish'
							? 'Page is live: ' + page.url
							: 'Page unpublished — back to draft.'));
					}
				});
		}
		if (pill) {
			paintPill();
			pill.addEventListener('click', function (e) {
				if (page.status === 'publish') {
					if (e.shiftKey) { setStatus(false); }
					else if (page.url) { window.open(page.url, '_blank'); }
				} else {
					setStatus(true);
				}
			});
		}
		if (titleEl) {
			titleEl.addEventListener('click', function () {
				if (titleEl.isContentEditable) return;
				titleEl.contentEditable = 'true';
				titleEl.focus();
				document.execCommand && document.execCommand('selectAll', false, null);
			});
			function commit() {
				if (!titleEl.isContentEditable) return;
				titleEl.contentEditable = 'false';
				var t = (titleEl.textContent || '').trim();
				if (!t || t === page.title) { titleEl.textContent = page.title || t; return; }
				var fd = new FormData();
				fd.append('action', 'pressgo_ai_rename');
				fd.append('nonce', cfg.nonce);
				fd.append('post_id', cfg.postId);
				fd.append('title', t);
				fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
					.then(function (r) { return r.json(); })
					.then(function (j) { if (j && j.success) { page.title = j.data.title; titleEl.textContent = j.data.title; } });
			}
			titleEl.addEventListener('blur', commit);
			titleEl.addEventListener('keydown', function (e) {
				if (e.key === 'Enter') { e.preventDefault(); titleEl.blur(); }
				if (e.key === 'Escape') { titleEl.textContent = page.title || ''; titleEl.contentEditable = 'false'; }
			});
		}
	})();

	// Target-builder picker: persists per page, applies on the NEXT build.
	var targetSel = document.getElementById('pg-target-builder');
	if (targetSel) {
		targetSel.addEventListener('change', function () {
			var fd = new FormData();
			fd.append('action', 'pressgo_ai_set_target');
			fd.append('nonce', cfg.nonce);
			fd.append('post_id', cfg.postId);
			fd.append('target', targetSel.value);
			fetch(cfg.ajaxUrl, { method: 'POST', body: fd })
				.then(function (r) { return r.json(); })
				.then(function (j) {
					if (j && j.success) {
						append(el('pg-msg-system', 'Render target set to ' + targetSel.value + '. Your next build or edit renders into it.'));
					} else {
						append(el('pg-msg-error', (j && j.data) || 'Could not set render target.'));
					}
				})
				.catch(function () { append(el('pg-msg-error', 'Could not set render target.')); });
		});
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
		if (!r || !r.ask || reviewAskRendered) return false;
		reviewAskRendered = true;
		// Burn a shown-credit only now that the card actually renders.
		var seenFd = new FormData();
		seenFd.append('action', 'pressgo_ai_review_seen');
		seenFd.append('nonce', cfg.nonce);
		fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: seenFd });
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
		return true;
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
		// Self-explanatory label: the chip names the brand it's applying
		// ("Brand: John's Gym"), so nobody needs the tooltip to know what it does.
		var shortName = (b.name || '').length > 16 ? b.name.slice(0, 15) + '\u2026' : (b.name || '');
		function paint() {
			var label = on ? ('Brand: ' + (shortName ? escapeHtml(shortName) : 'on')) : 'Brand: off';
			chip.innerHTML = '<span style="display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:6px;vertical-align:1px;background:' + (on ? '#22C55E' : '#9CA3AF') + ';"></span>' + label;
			chip.style.opacity = on ? '1' : '0.65';
		}
		paint();
		actions.insertBefore(chip, actions.firstChild);

		// ===== Brand control panel =====
		// The chip opens a real control menu: view/edit the stored foundation
		// (name, industry, voice, colors, fonts), toggle continuous branding,
		// or clear it so the next first build relearns from scratch.
		var brandPanel = null;
		function closeBrandPanel() {
			if (brandPanel) { brandPanel.remove(); brandPanel = null; document.removeEventListener('click', onBrandDocClick, true); }
		}
		function onBrandDocClick(e) {
			if (brandPanel && !brandPanel.contains(e.target) && e.target !== chip && !chip.contains(e.target)) closeBrandPanel();
		}
		function fieldRow(label, input) {
			var row = document.createElement('label');
			row.className = 'pg-brand-row';
			var span = document.createElement('span');
			span.textContent = label;
			row.appendChild(span);
			row.appendChild(input);
			return row;
		}
		function textInput(value, placeholder) {
			var i = document.createElement('input');
			i.type = 'text';
			i.value = value || '';
			if (placeholder) i.placeholder = placeholder;
			return i;
		}
		function isHex(v) { return /^#[0-9a-fA-F]{3,8}$/.test(String(v || '')); }

		function openBrandPanel(state) {
			closeBrandPanel();
			var b = state.brand || {};
			brandPanel = document.createElement('div');
			brandPanel.className = 'pg-brand-panel';

			var head = document.createElement('div');
			head.className = 'pg-brand-head';
			var title = document.createElement('strong');
			title.textContent = 'Site brand';
			head.appendChild(title);
			var toggleWrap = document.createElement('label');
			toggleWrap.className = 'pg-brand-toggle';
			var toggle = document.createElement('input');
			toggle.type = 'checkbox';
			toggle.checked = on;
			toggleWrap.appendChild(toggle);
			toggleWrap.appendChild(document.createTextNode(' apply to new pages'));
			head.appendChild(toggleWrap);
			brandPanel.appendChild(head);

			var nameI = textInput(b.brand_name, 'Business name');
			var indI  = textInput(b.industry, 'Industry');
			brandPanel.appendChild(fieldRow('Name', nameI));
			brandPanel.appendChild(fieldRow('Industry', indI));

			var voiceI = document.createElement('textarea');
			voiceI.rows = 2;
			voiceI.placeholder = 'Voice (e.g. warm and plainspoken)';
			voiceI.value = b.voice || '';
			brandPanel.appendChild(fieldRow('Voice', voiceI));

			// Colors: every stored hex key gets a native color input.
			var colorInputs = {};
			var colors = b.colors || {};
			var colorKeys = Object.keys(colors).filter(function (k) { return isHex(colors[k]); });
			['primary', 'accent', 'background', 'text'].forEach(function (k) {
				if (colorKeys.indexOf(k) === -1 && isHex(colors[k])) colorKeys.push(k);
			});
			if (colorKeys.length) {
				var sw = document.createElement('div');
				sw.className = 'pg-brand-colors';
				colorKeys.forEach(function (k) {
					var wrap = document.createElement('label');
					wrap.className = 'pg-brand-swatch';
					var ci = document.createElement('input');
					ci.type = 'color';
					ci.value = colors[k].length === 4
						? '#' + colors[k][1] + colors[k][1] + colors[k][2] + colors[k][2] + colors[k][3] + colors[k][3]
						: colors[k].slice(0, 7);
					colorInputs[k] = ci;
					wrap.appendChild(ci);
					wrap.appendChild(document.createTextNode(k.replace(/_/g, ' ')));
					sw.appendChild(wrap);
				});
				brandPanel.appendChild(sw);
			}

			var fonts = b.fonts || {};
			var headF = textInput(fonts.heading, 'Heading font (Google Fonts name)');
			var bodyF = textInput(fonts.body, 'Body font');
			brandPanel.appendChild(fieldRow('Headings', headF));
			brandPanel.appendChild(fieldRow('Body', bodyF));

			// Per-PAGE opt-out: one off-brand page without flipping the
			// site-wide toggle (and forgetting to flip it back).
			var optWrap = document.createElement('label');
			optWrap.className = 'pg-brand-toggle';
			optWrap.style.marginTop = '2px';
			var optCb = document.createElement('input');
			optCb.type = 'checkbox';
			optCb.checked = !!state.page_optout;
			optWrap.appendChild(optCb);
			optWrap.appendChild(document.createTextNode(' skip the brand on THIS page only'));
			brandPanel.appendChild(optWrap);
			optCb.addEventListener('change', function () {
				var fd = new FormData();
				fd.append('action', 'pressgo_ai_brand_optout');
				fd.append('nonce', cfg.nonce);
				fd.append('post_id', cfg.postId);
				fd.append('optout', optCb.checked ? '1' : '');
				fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd });
			});

			var foot = document.createElement('div');
			foot.className = 'pg-brand-foot';
			var saveBtn = document.createElement('button');
			saveBtn.type = 'button';
			saveBtn.className = 'pg-modal-btn is-primary';
			saveBtn.textContent = 'Save';
			var clearBtn2 = document.createElement('button');
			clearBtn2.type = 'button';
			clearBtn2.className = 'pg-modal-btn is-danger';
			clearBtn2.textContent = 'Clear & relearn';
			clearBtn2.title = 'Forget this brand. The next first build on a fresh page learns a new one.';
			foot.appendChild(clearBtn2);
			foot.appendChild(saveBtn);
			brandPanel.appendChild(foot);

			toggle.addEventListener('change', function () {
				on = toggle.checked;
				paint();
				var fd = new FormData();
				fd.append('action', 'pressgo_ai_brand_toggle');
				fd.append('nonce', cfg.nonce);
				fd.append('enabled', on ? '1' : '');
				fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd });
			});

			saveBtn.addEventListener('click', function () {
				saveBtn.disabled = true;
				saveBtn.textContent = 'Saving…';
				var payload = {
					brand_name: nameI.value.trim(),
					industry: indI.value.trim(),
					voice: voiceI.value.trim(),
					fonts: { heading: headF.value.trim(), body: bodyF.value.trim() },
					colors: {},
				};
				Object.keys(colorInputs).forEach(function (k) { payload.colors[k] = colorInputs[k].value; });
				var fd = new FormData();
				fd.append('action', 'pressgo_ai_brand_save');
				fd.append('nonce', cfg.nonce);
				fd.append('brand', JSON.stringify(payload));
				fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
					.then(function (r) { return r.json(); })
					.then(function (j) {
						if (j && j.success) {
							var nm = (j.data.brand && j.data.brand.brand_name) || '';
							shortName = nm.length > 16 ? nm.slice(0, 15) + '…' : nm;
							paint();
							closeBrandPanel();
							append(el('pg-msg-system', 'Brand saved. New pages will follow it.'));
						} else {
							saveBtn.disabled = false;
							saveBtn.textContent = 'Save';
						}
					})
					.catch(function () { saveBtn.disabled = false; saveBtn.textContent = 'Save'; });
			});

			clearBtn2.addEventListener('click', function () {
				if (!window.confirm('Forget this brand? Existing pages keep their look; the next first build learns a fresh brand.')) return;
				var fd = new FormData();
				fd.append('action', 'pressgo_ai_brand_clear');
				fd.append('nonce', cfg.nonce);
				fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
					.then(function () {
						closeBrandPanel();
						chip.remove();
						append(el('pg-msg-system', 'Brand cleared. The next first build will learn a new one.'));
					});
			});

			actions.appendChild(brandPanel);
			setTimeout(function () { document.addEventListener('click', onBrandDocClick, true); }, 0);
		}

		chip.addEventListener('click', function () {
			if (brandPanel) { closeBrandPanel(); return; }
			var fd = new FormData();
			fd.append('action', 'pressgo_ai_brand_get');
			fd.append('nonce', cfg.nonce);
			fd.append('post_id', cfg.postId);
			fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
				.then(function (r) { return r.json(); })
				.then(function (j) { if (j && j.success) openBrandPanel(j.data); })
				.catch(function () {});
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

	// ════════════════════════════════════════════════════════════════════
	// Visual editor — Select mode + schema-driven property panel.
	//
	// Every section root in the preview carries `pg-sec pg-key--{key}`
	// classes (the key encodes "#" as "--": gallery#2 → gallery--2). The
	// iframe is same-origin, so this module reaches straight into
	// contentDocument: hover outlines, click-to-select, a floating label
	// chip — then renders a right-side property panel from
	// cfg.editorFields[baseType] prefilled from cfg.pageConfig[key].
	// Apply posts a COMPLETE section object through the zero-token
	// pressgo_ai_apply_patch pipeline (validate → snapshot → render →
	// purge), so panel edits share one undo history with the AI.
	// ════════════════════════════════════════════════════════════════════
	(function () {
		var edFields = cfg.editorFields || {};
		var toolbar  = document.querySelector('.pg-preview-toolbar');
		if (!frame || !toolbar || !Object.keys(edFields).length) return;

		// ── State ─────────────────────────────────────────────────────
		var selectMode = false;
		var working    = null;   // working copy: section object, or {dottedPath:value} in page mode
		var pageMode   = false;
		var dirtyKeys  = {};     // touched field paths
		var controls   = [];     // text-like control registry (click-into-field + live preview)
		var panelEl    = null;
		var chatChip   = null;
		var noSecToastShown = false;

		// Auto-apply (autosave) state — one request in flight at a time,
		// latest-wins queue behind it.
		var AUTO_APPLY_MS = 1200;
		var autoTimer   = null;   // debounce: ~1.2s after the last edit
		var applyBusy   = false;  // a patch request is in flight
		var queuedPatch = null;   // {patch,label} captured while busy (latest wins)
		var retryPatch  = null;   // last FAILED payload, kept for manual retry
		// Page-token live preview: which dotted paths the user actually
		// scrubbed this panel session (only those get live CSS rules).
		var liveTouched = {};

		// Defaults for sliders whose token isn't in the stored config yet
		// (match the generator's own defaults).
		var SLIDER_DEFAULTS = {
			'layout.section_padding': 100,
			'layout.boxed_width':     1200,
			'layout.card_radius':     16,
			'layout.button_radius':   8
		};
		// Which page fields scrub live, and how each maps to preview CSS.
		// Best-effort visual approximation — the authoritative render
		// follows via auto-apply and silently corrects any drift.
		var LIVE_TOKEN_PATHS = {
			'layout.section_padding': function (v) {
				return '.pg-sec{padding-top:' + v + 'px !important;padding-bottom:' + v + 'px !important;}';
			},
			'layout.boxed_width': function (v) {
				// Elementor flex containers cap content via these vars (name
				// changed across versions — set both) + a direct rule.
				return '.pg-sec{--content-width:' + v + 'px !important;--container-max-width:' + v + 'px !important;}' +
					'.pg-sec>.e-con-inner{max-width:' + v + 'px !important;}';
			},
			'layout.card_radius': function (v) {
				// Only visible on child containers that paint a background —
				// rows/cols without one are unaffected visually.
				return '.pg-sec .e-con.e-child{border-radius:' + v + 'px !important;}';
			},
			'layout.button_radius': function (v) {
				return '.pg-sec .elementor-button{border-radius:' + v + 'px !important;}';
			},
			'colors.accent': function (v) {
				return '.pg-sec .elementor-button{background-color:' + v + ' !important;border-color:' + v + ' !important;}';
			},
			'colors.primary': function (v) {
				// Conservative: progress/star/counter accents would need
				// per-widget rules; primary shows on the next real render.
				return '';
			}
		};

		function liveTokenCss() {
			var rules = [];
			Object.keys(liveTouched).forEach(function (p) {
				var fn = LIVE_TOKEN_PATHS[p];
				if (!fn) return;
				var v = working ? working[p] : undefined;
				if (v === undefined || v === null || v === '') return;
				if (p.indexOf('colors.') === 0) {
					v = toHex6(v);
					if (!v) return;
				} else {
					v = parseFloat(v);
					if (isNaN(v)) return;
				}
				var r = fn(v);
				if (r) rules.push(r);
			});
			return rules.join('\n');
		}

		// Inject/refresh the live token stylesheet in the preview document.
		// Pure DOM write — keeps slider drags at 60fps with zero network.
		function updateLiveTokens() {
			try {
				var doc = frame.contentDocument;
				if (!doc) return;
				var st = doc.getElementById('pg-live-tokens');
				if (!st) {
					st = doc.createElement('style');
					st.id = 'pg-live-tokens';
					(doc.head || doc.body).appendChild(st);
				}
				st.textContent = liveTokenCss();
			} catch (e) { /* doc mid-swap */ }
		}

		function deepClone(o) { return JSON.parse(JSON.stringify(o)); }
		function baseType(key) { return String(key).replace(/#\d+$/, ''); }
		function encodeKey(key) { return String(key).replace('#', '--'); }
		// 'pg-key--features--2' → 'features#2' (base types use underscores,
		// never dashes, so the trailing '--{digits}' is unambiguous).
		function decodeKeyClass(cls) {
			var rest = String(cls).slice('pg-key--'.length);
			var m = rest.match(/^(.*)--([0-9]+)$/);
			return m ? m[1] + '#' + m[2] : rest;
		}
		function keyOfSection(secEl) {
			var classes = (secEl.className || '').split(/\s+/);
			for (var i = 0; i < classes.length; i++) {
				if (classes[i].indexOf('pg-key--') === 0) return decodeKeyClass(classes[i]);
			}
			return '';
		}
		function normText(s) { return String(s == null ? '' : s).replace(/\s+/g, ' ').trim().toLowerCase(); }
		function getPath(obj, path) {
			return String(path).split('.').reduce(function (o, k) {
				return (o && typeof o === 'object') ? o[k] : undefined;
			}, obj);
		}
		function setPath(obj, path, value) {
			var keys = String(path).split('.');
			var o = obj;
			for (var i = 0; i < keys.length - 1; i++) {
				if (!o[keys[i]] || typeof o[keys[i]] !== 'object') o[keys[i]] = {};
				o = o[keys[i]];
			}
			o[keys[keys.length - 1]] = value;
		}
		function titleize(s) {
			return String(s).replace(/_/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); });
		}
		function toHex6(v) {
			v = String(v || '');
			if (/^#[0-9a-fA-F]{3}$/.test(v)) return '#' + v[1] + v[1] + v[2] + v[2] + v[3] + v[3];
			if (/^#[0-9a-fA-F]{6}/.test(v)) return v.slice(0, 7);
			return '';
		}

		// ── Toast ─────────────────────────────────────────────────────
		function showToast(msg, isError) {
			var t = document.createElement('div');
			t.className = 'pg-toast' + (isError ? ' is-error' : '');
			t.textContent = msg;
			document.body.appendChild(t);
			setTimeout(function () { t.classList.add('is-out'); }, 2800);
			setTimeout(function () { t.remove(); }, 3300);
		}

		// ── Select-mode toggle button (injected next to the viewport switcher) ──
		var selBtn = document.createElement('button');
		selBtn.type = 'button';
		selBtn.id = 'pg-select-toggle';
		selBtn.className = 'pg-select-toggle';
		selBtn.title = 'Select mode: click any section in the preview to edit it directly (no AI credits)';
		selBtn.innerHTML = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 3 7.07 16.97 2.51-7.39 7.39-2.51L3 3z"/></svg><span>Select</span>';
		toolbar.appendChild(selBtn);

		selBtn.addEventListener('click', function () {
			if (!selectMode && !cfg.pageConfig) {
				showToast('Build the page first — visual editing needs a built page.', true);
				return;
			}
			setSelectMode(!selectMode);
		});

		function setSelectMode(on) {
			selectMode = on;
			selBtn.classList.toggle('is-active', on);
			if (on) {
				armDoc();
				clearSelection(true); // opens the panel on the Page tab
			} else {
				if (isDirty()) flushApply(); // autosave anything pending on exit
				disarmDoc();
				selectedSectionKey = '';
				renderChatChip();
				closePanel();
			}
		}

		// ── Iframe chrome: hover outline + label chip + click-select ──
		// Handlers live in the parent and are re-attached on every iframe
		// load (the doc is brand new each reload).
		var docState = null; // { doc, style, chip, onOver, onClick }

		function armDoc() {
			var doc;
			try { doc = frame.contentDocument; } catch (e) { return; }
			if (!doc || !doc.body) return;
			if (docState && docState.doc === doc) { markSelected(); return; }
			disarmDoc();

			var style = doc.createElement('style');
			style.id = 'pg-select-style';
			style.textContent = [
				'.pg-sec { cursor: pointer !important; }',
				'.pg-sec.pg-ed-hover { outline: 2px dashed rgba(91,79,255,0.65) !important; outline-offset: -2px; }',
				'.pg-sec.pg-ed-selected { outline: 3px solid #5b4fff !important; outline-offset: -3px; }',
				'#pg-select-chip { position: fixed; z-index: 2147483646; background: #5b4fff; color: #fff;' +
					'font: 600 11px/1.2 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;' +
					'padding: 3px 9px; border-radius: 0 0 6px 0; pointer-events: none; display: none;' +
					'box-shadow: 0 2px 8px rgba(91,79,255,0.35); letter-spacing: 0.02em; }'
			].join('\n');
			(doc.head || doc.body).appendChild(style);

			var chip = doc.createElement('div');
			chip.id = 'pg-select-chip';
			doc.body.appendChild(chip);

			var hovered = null;
			function onOver(e) {
				if (!selectMode) return;
				var sec = e.target && e.target.closest ? e.target.closest('.pg-sec') : null;
				if (sec === hovered) return;
				if (hovered) hovered.classList.remove('pg-ed-hover');
				hovered = sec;
				if (sec) {
					sec.classList.add('pg-ed-hover');
					var r = sec.getBoundingClientRect();
					chip.textContent = sectionLabel(keyOfSection(sec));
					chip.style.left = Math.max(0, r.left) + 'px';
					chip.style.top = Math.max(0, r.top) + 'px';
					chip.style.display = 'block';
				} else {
					chip.style.display = 'none';
				}
			}
			function onClick(e) {
				if (!selectMode) return;
				// Select mode owns clicks: never follow links / fire toggles.
				e.preventDefault();
				e.stopPropagation();
				var sec = e.target && e.target.closest ? e.target.closest('.pg-sec') : null;
				if (!sec) { clearSelection(); return; }
				var key = keyOfSection(sec);
				if (!key) { clearSelection(); return; }
				if (key === selectedSectionKey) {
					focusFieldForElement(e.target);
					return;
				}
				selectSection(key);
			}
			doc.addEventListener('mouseover', onOver, true);
			doc.addEventListener('click', onClick, true);
			docState = { doc: doc, style: style, chip: chip, onOver: onOver, onClick: onClick };
			markSelected();

			if (!doc.querySelector('.pg-sec') && !noSecToastShown) {
				noSecToastShown = true;
				showToast('No selectable sections found — ask the AI for any small change to refresh the page markers.', true);
			}
		}

		function disarmDoc() {
			if (!docState) return;
			try {
				docState.doc.removeEventListener('mouseover', docState.onOver, true);
				docState.doc.removeEventListener('click', docState.onClick, true);
				if (docState.style) docState.style.remove();
				if (docState.chip) docState.chip.remove();
				var marked = docState.doc.querySelectorAll('.pg-ed-hover, .pg-ed-selected');
				for (var i = 0; i < marked.length; i++) {
					marked[i].classList.remove('pg-ed-hover');
					marked[i].classList.remove('pg-ed-selected');
				}
			} catch (e) { /* doc already torn down */ }
			docState = null;
		}

		function markSelected() {
			if (!docState) return;
			try {
				var doc = docState.doc;
				var old = doc.querySelectorAll('.pg-ed-selected');
				for (var i = 0; i < old.length; i++) old[i].classList.remove('pg-ed-selected');
				if (selectedSectionKey) {
					var sec = doc.querySelector('.pg-key--' + encodeKey(selectedSectionKey));
					if (sec) sec.classList.add('pg-ed-selected');
				}
			} catch (e) {}
		}

		function findSelectedRoot() {
			if (!selectedSectionKey) return null;
			try {
				var doc = frame.contentDocument;
				return doc ? doc.querySelector('.pg-key--' + encodeKey(selectedSectionKey)) : null;
			} catch (e) { return null; }
		}

		// Re-arm on every preview swap/reload (new contentDocument). Runs
		// AFTER the buffered swap completes, so the selection outline
		// re-appears on the fresh frame with zero visible gap.
		onFrameReady(function () {
			docState = null; // old doc is gone
			if (selectMode) armDoc();
			// Unsaved slider/color drags survive the swap: re-inject the
			// live token CSS into the fresh document.
			if (pageMode && panelEl && panelEl.classList.contains('is-open') && Object.keys(liveTouched).length) {
				updateLiveTokens();
			}
		});

		// ── Selection ─────────────────────────────────────────────────
		function isDirty() { return Object.keys(dirtyKeys).length > 0; }
		// Word-doc model: leaving a dirty selection FLUSHES the pending save
		// instead of interrupting with a confirm dialog. The patch payload is
		// snapshotted synchronously, so the selection can change right after.
		function confirmDiscard() {
			if (isDirty()) flushApply();
			return true;
		}

		function selectSection(key) {
			if (!confirmDiscard()) return;
			selectedSectionKey = key;
			markSelected();
			renderChatChip();
			openPanel();
			renderPanel(false);
		}

		function clearSelection(keepQuiet) {
			if (!keepQuiet && !confirmDiscard()) return;
			selectedSectionKey = '';
			markSelected();
			renderChatChip();
			if (selectMode) {
				openPanel();
				renderPanel(false); // Page tab
			}
		}

		// ── Chat scoping chip ("Editing: Gallery 2 ✕") ────────────────
		function renderChatChip() {
			if (chatChip) { chatChip.remove(); chatChip = null; }
			if (!selectedSectionKey) return;
			chatChip = document.createElement('div');
			chatChip.className = 'pg-edit-chip';
			var lbl = document.createElement('span');
			lbl.textContent = 'Editing: ' + sectionLabel(selectedSectionKey);
			chatChip.appendChild(lbl);
			var x = document.createElement('button');
			x.type = 'button';
			x.className = 'pg-edit-chip-x';
			x.setAttribute('aria-label', 'Stop editing this section');
			x.innerHTML = '&times;';
			x.addEventListener('click', function () { clearSelection(); });
			chatChip.appendChild(x);
			chatPanel.insertBefore(chatChip, attachStrip);
		}

		// ── Property panel shell ──────────────────────────────────────
		function ensurePanel() {
			if (panelEl) return panelEl;
			panelEl = document.createElement('aside');
			panelEl.className = 'pg-editor-panel';
			panelEl.innerHTML =
				'<div class="pg-ed-head">' +
					'<div class="pg-ed-head-text">' +
						'<strong class="pg-ed-title"></strong>' +
						'<span class="pg-ed-save" hidden></span>' +
						'<span class="pg-ed-key"></span>' +
					'</div>' +
					'<button type="button" class="pg-ed-close" aria-label="Close panel">&times;</button>' +
				'</div>' +
				'<div class="pg-ed-body"></div>' +
				'<div class="pg-ed-foot">' +
					'<div class="pg-ed-error" hidden></div>' +
					'<div class="pg-ed-ask">' +
						'<input type="text" class="pg-ed-ask-input" placeholder="Ask AI about this section…">' +
					'</div>' +
					'<div class="pg-ed-actions">' +
						'<button type="button" class="pg-ed-discard" hidden>Discard</button>' +
						'<button type="button" class="pg-ed-apply" disabled>Apply</button>' +
					'</div>' +
				'</div>';
			previewWrap.appendChild(panelEl);
			panelEl.querySelector('.pg-ed-close').addEventListener('click', function () {
				if (!confirmDiscard()) return;
				if (selectedSectionKey) { selectedSectionKey = ''; markSelected(); renderChatChip(); }
				closePanel();
			});
			// Apply = immediate flush (autosave covers the normal path).
			panelEl.querySelector('.pg-ed-apply').addEventListener('click', function () { flushApply(); });
			panelEl.querySelector('.pg-ed-discard').addEventListener('click', function () {
				// Discard = drop UNSAVED edits only (anything already saved
				// or in flight stays; History covers real undo).
				clearTimeout(autoTimer);
				autoTimer = null;
				retryPatch = null;
				queuedPatch = null;
				renderPanel(false);
				setSaveState('idle');
				setError('');
			});
			var ask = panelEl.querySelector('.pg-ed-ask-input');
			function sendAsk() {
				var t = (ask.value || '').trim();
				if (!t) return;
				input.value = t;       // prefill the main chat input —
				input.focus();         // scoping rides the selection chip
				ask.value = '';
			}
			ask.addEventListener('keydown', function (e) {
				if (e.key === 'Enter') { e.preventDefault(); sendAsk(); }
			});
			return panelEl;
		}
		function openPanel() {
			ensurePanel().classList.add('is-open');
			previewWrap.classList.add('pg-ed-open');
		}
		function closePanel() {
			if (panelEl) panelEl.classList.remove('is-open');
			previewWrap.classList.remove('pg-ed-open');
		}

		function setError(msg) {
			if (!panelEl) return;
			var e = panelEl.querySelector('.pg-ed-error');
			if (msg) { e.textContent = msg; e.hidden = false; }
			else { e.hidden = true; }
		}

		function markDirty(path) {
			dirtyKeys[path] = 1;
			window.__pgPerf.lastInputAt = performance.now();
			paintApply();
			setSaveState('saving');
			scheduleAutoApply();
		}
		function paintApply() {
			if (!panelEl) return;
			var n = Object.keys(dirtyKeys).length;
			var pending = n > 0 || !!retryPatch || !!queuedPatch;
			var btn = panelEl.querySelector('.pg-ed-apply');
			btn.disabled = !pending;
			btn.innerHTML = n ? 'Apply now <span class="pg-ed-badge">' + n + '</span>' : 'Apply now';
			panelEl.querySelector('.pg-ed-discard').hidden = !n;
		}

		// ── Save indicator (panel header) ─────────────────────────────
		// One quiet "Saving… / Saved" readout instead of a toast per apply.
		var saveStateTimer = null;
		function setSaveState(state) {
			if (!panelEl) return;
			var s = panelEl.querySelector('.pg-ed-save');
			if (!s) return;
			clearTimeout(saveStateTimer);
			s.classList.remove('is-saving', 'is-saved', 'is-error');
			if (state === 'saving') {
				s.textContent = 'Saving…';
				s.classList.add('is-saving');
				s.hidden = false;
			} else if (state === 'saved') {
				s.textContent = 'Saved';
				s.classList.add('is-saved');
				s.hidden = false;
				saveStateTimer = setTimeout(function () { s.hidden = true; }, 2200);
			} else if (state === 'error') {
				s.textContent = 'Couldn’t save';
				s.classList.add('is-error');
				s.hidden = false;
			} else {
				s.hidden = true;
			}
		}

		// ── Panel rendering (schema-driven) ───────────────────────────
		// preserve=true keeps the current working copy + dirty state
		// (used by repeater add/remove/reorder which re-render the body).
		function renderPanel(preserve) {
			ensurePanel();
			setError('');
			pageMode = !selectedSectionKey;
			var spec = pageMode ? edFields._page : edFields[baseType(selectedSectionKey)];

			if (!preserve) {
				dirtyKeys = {};
				liveTouched = {};
				if (pageMode) {
					working = {};
					if (spec) {
						Object.keys(spec.fields || {}).forEach(function (p) {
							var v = getPath(cfg.pageConfig || {}, p);
							if (v !== undefined) working[p] = (v && typeof v === 'object') ? deepClone(v) : v;
						});
					}
				} else {
					var cur = (cfg.pageConfig || {})[selectedSectionKey];
					working = (cur && typeof cur === 'object') ? deepClone(cur) : {};
				}
			}
			controls = [];

			panelEl.querySelector('.pg-ed-title').textContent =
				pageMode ? 'Page settings' : sectionLabel(selectedSectionKey);
			panelEl.querySelector('.pg-ed-key').textContent =
				pageMode ? 'colors · fonts · layout' : selectedSectionKey;
			panelEl.querySelector('.pg-ed-ask').style.display = pageMode ? 'none' : '';

			var body = panelEl.querySelector('.pg-ed-body');
			body.innerHTML = '';

			if (!spec || !Object.keys(spec.fields || {}).length) {
				var empty = document.createElement('div');
				empty.className = 'pg-ed-empty';
				empty.textContent = pageMode
					? 'No page-level settings available.'
					: 'No quick-edit fields for this section type yet — describe the change in chat and the AI will handle it.';
				body.appendChild(empty);
				paintApply();
				return;
			}

			// Layout (variant) select first — the section's biggest lever.
			if (!pageMode && spec.variants && spec.variants.length > 1) {
				body.appendChild(buildVariantField(spec.variants));
			}

			Object.keys(spec.fields).forEach(function (fkey) {
				var f = spec.fields[fkey];
				var row = buildField(fkey, f);
				if (row) body.appendChild(row);
			});
			paintApply();
		}

		function buildVariantField(variants) {
			var sel = document.createElement('select');
			var current = String(working.variant || 'default');
			var opts = variants.slice();
			if (opts.indexOf(current) === -1) opts.unshift(current);
			opts.forEach(function (v) {
				var o = document.createElement('option');
				o.value = v;
				o.textContent = titleize(v);
				if (v === current) o.selected = true;
				sel.appendChild(o);
			});
			sel.addEventListener('change', function () {
				working.variant = sel.value;
				markDirty('variant');
			});
			return fieldRow('Layout', sel, 'How this section is arranged. Applies on the next Apply.');
		}

		function fieldRow(label, controlEl, hint) {
			var row = document.createElement('div');
			row.className = 'pg-ed-field';
			var l = document.createElement('label');
			l.className = 'pg-ed-label';
			l.textContent = label;
			row.appendChild(l);
			row.appendChild(controlEl);
			if (hint) {
				var h = document.createElement('div');
				h.className = 'pg-ed-hint';
				h.textContent = hint;
				row.appendChild(h);
			}
			return row;
		}

		// Registry entry for text-like controls so click-into-field and the
		// optimistic live preview can find them. orig = the value as rendered
		// in the CURRENT preview (frozen at panel render).
		function registerText(path, inputEl, getVal) {
			var ctl = { path: path, el: inputEl, get: getVal, orig: normText(getVal()), node: null };
			controls.push(ctl);
			inputEl.addEventListener('input', function () { liveUpdateText(ctl); });
			return ctl;
		}

		// Build one control row for a field spec. Value lives in `working`.
		function buildField(fkey, f) {
			var kind = f.kind;
			var value = pageMode ? working[fkey] : working[fkey];

			// `list` whose current value holds objects renders as a repeater
			// inferred from the data (the PHP map can't always tell).
			if (kind === 'list' && Array.isArray(value) && value.some(function (it) { return it && typeof it === 'object'; })) {
				kind = 'repeater';
				f = { kind: 'repeater', label: f.label, hint: f.hint, fields: inferSubfields(value) };
				normalizeRepeaterItems(fkey, f.fields);
			}
			if (kind === 'repeater' && Array.isArray(value) && (!f.fields || !Object.keys(f.fields).length)) {
				f = { kind: 'repeater', label: f.label, hint: f.hint, fields: inferSubfields(value) };
			}

			switch (kind) {
				case 'text':
				case 'textarea': {
					var t = kind === 'textarea' ? document.createElement('textarea') : document.createElement('input');
					if (kind === 'textarea') t.rows = 3; else t.type = 'text';
					t.value = value == null ? '' : String(value);
					t.addEventListener('input', function () {
						working[fkey] = t.value;
						markDirty(fkey);
					});
					registerText(fkey, t, function () { return t.value; });
					return fieldRow(f.label, t, f.hint);
				}
				case 'url': {
					var u = document.createElement('input');
					u.type = 'url';
					u.placeholder = 'https://…  ·  #anchor  ·  tel:…';
					u.value = value == null ? '' : String(value);
					u.addEventListener('input', function () { working[fkey] = u.value; markDirty(fkey); });
					return fieldRow(f.label, u, f.hint);
				}
				case 'image': {
					var wrap = document.createElement('div');
					wrap.className = 'pg-ed-image';
					var thumb = document.createElement('img');
					thumb.className = 'pg-ed-image-thumb';
					thumb.alt = '';
					var ii = document.createElement('input');
					ii.type = 'url';
					ii.placeholder = 'Image URL';
					ii.value = value == null ? '' : String(value);
					function paintThumb() {
						var v = (ii.value || '').trim();
						if (/^https?:\/\//.test(v)) { thumb.src = v; thumb.style.display = ''; }
						else { thumb.removeAttribute('src'); thumb.style.display = 'none'; }
					}
					thumb.addEventListener('error', function () { thumb.style.display = 'none'; });
					paintThumb();
					ii.addEventListener('input', function () { working[fkey] = ii.value; markDirty(fkey); paintThumb(); });
					wrap.appendChild(thumb);
					wrap.appendChild(ii);
					return fieldRow(f.label, wrap, f.hint);
				}
				case 'color': {
					var hex = toHex6(value);
					var c = document.createElement('input');
					c.type = 'color';
					c.value = hex || '#cccccc';
					c.addEventListener('input', function () {
						working[fkey] = c.value;
						// Page colors scrub live in the iframe while picking —
						// zero network until the debounce flush.
						if (pageMode && LIVE_TOKEN_PATHS[fkey]) {
							liveTouched[fkey] = 1;
							updateLiveTokens();
						}
						markDirty(fkey);
					});
					var cw = document.createElement('div');
					cw.className = 'pg-ed-color';
					cw.appendChild(c);
					var cv = document.createElement('span');
					cv.className = 'pg-ed-color-val';
					cv.textContent = hex || (value ? String(value) : '—');
					c.addEventListener('input', function () { cv.textContent = c.value; });
					cw.appendChild(cv);
					return fieldRow(f.label, cw, f.hint);
				}
				case 'select': {
					var s = document.createElement('select');
					var curv = value == null ? '' : String(value);
					var sopts = (f.options || []).slice();
					if (sopts.indexOf(curv) === -1) sopts.unshift(curv);
					sopts.forEach(function (ov) {
						var o = document.createElement('option');
						o.value = ov;
						o.textContent = ov === '' ? '(none)' : titleize(ov);
						if (ov === curv) o.selected = true;
						s.appendChild(o);
					});
					s.addEventListener('change', function () { working[fkey] = s.value; markDirty(fkey); });
					return fieldRow(f.label, s, f.hint);
				}
				case 'number': {
					// Page-level layout tokens (density, content width, radii)
					// become SLIDERS that scrub the preview live while dragging
					// — CSS injection only, no network in the loop. The
					// authoritative render follows via auto-apply.
					if (pageMode && f.min != null && f.max != null) {
						var sbox = document.createElement('div');
						sbox.className = 'pg-ed-slider';
						var rg = document.createElement('input');
						rg.type = 'range';
						rg.min = f.min;
						rg.max = f.max;
						if (f.step != null) rg.step = f.step;
						var initial = (value == null || isNaN(parseFloat(value)))
							? (SLIDER_DEFAULTS[fkey] != null ? SLIDER_DEFAULTS[fkey] : (Number(f.min) + Number(f.max)) / 2)
							: parseFloat(value);
						rg.value = initial;
						var out = document.createElement('span');
						out.className = 'pg-ed-slider-val';
						out.textContent = String(initial);
						rg.addEventListener('input', function () {
							var num = parseFloat(rg.value);
							if (isNaN(num)) return;
							working[fkey] = num;
							out.textContent = String(num);
							if (LIVE_TOKEN_PATHS[fkey]) {
								liveTouched[fkey] = 1;
								updateLiveTokens();
							}
							markDirty(fkey);
						});
						sbox.appendChild(rg);
						sbox.appendChild(out);
						return fieldRow(f.label, sbox, f.hint);
					}
					var n = document.createElement('input');
					n.type = 'number';
					if (f.min != null) n.min = f.min;
					if (f.max != null) n.max = f.max;
					if (f.step != null) n.step = f.step;
					n.value = value == null ? '' : String(value);
					n.addEventListener('input', function () {
						var num = parseFloat(n.value);
						if (isNaN(num)) { delete working[fkey]; }
						else { working[fkey] = num; }
						markDirty(fkey);
					});
					return fieldRow(f.label, n, f.hint);
				}
				case 'checkbox': {
					var cb = document.createElement('input');
					cb.type = 'checkbox';
					cb.checked = !!value;
					cb.addEventListener('change', function () { working[fkey] = cb.checked; markDirty(fkey); });
					var cbw = document.createElement('div');
					cbw.className = 'pg-ed-checkbox';
					cbw.appendChild(cb);
					return fieldRow(f.label, cbw, f.hint);
				}
				case 'list': {
					var lt = document.createElement('textarea');
					lt.rows = 4;
					lt.placeholder = 'One item per line';
					lt.value = Array.isArray(value)
						? value.map(function (it) { return typeof it === 'string' ? it : JSON.stringify(it); }).join('\n')
						: '';
					lt.addEventListener('input', function () {
						working[fkey] = lt.value.split('\n').map(function (l) { return l.trim(); }).filter(Boolean);
						markDirty(fkey);
					});
					return fieldRow(f.label, lt, (f.hint ? f.hint + ' · ' : '') + 'One item per line.');
				}
				case 'cta': {
					var cur = (value && typeof value === 'object') ? value : (value ? { text: String(value) } : {});
					if (!working[fkey] || typeof working[fkey] !== 'object') working[fkey] = cur;
					var box = document.createElement('div');
					box.className = 'pg-ed-cta';
					var ct = document.createElement('input');
					ct.type = 'text';
					ct.placeholder = 'Button text';
					ct.value = cur.text == null ? '' : String(cur.text);
					var cu = document.createElement('input');
					cu.type = 'url';
					cu.placeholder = 'Link (https://… · #anchor · tel:…)';
					cu.value = cur.url == null ? '' : String(cur.url);
					ct.addEventListener('input', function () { working[fkey].text = ct.value; markDirty(fkey + '.text'); });
					cu.addEventListener('input', function () { working[fkey].url = cu.value; markDirty(fkey + '.url'); });
					registerText(fkey + '.text', ct, function () { return ct.value; });
					box.appendChild(ct);
					box.appendChild(cu);
					return fieldRow(f.label, box, f.hint);
				}
				case 'repeater':
					return buildRepeater(fkey, f);
			}
			return null;
		}

		// Infer repeater sub-fields from the shape of existing items (string
		// and number values become editable; nested objects stay chat-only).
		function inferSubfields(items) {
			var sub = {};
			(items || []).forEach(function (it) {
				if (!it || typeof it !== 'object' || Array.isArray(it)) return;
				Object.keys(it).forEach(function (k) {
					if (sub[k]) return;
					var v = it[k];
					if (typeof v === 'number') sub[k] = { kind: 'number', label: titleize(k) };
					else if (typeof v === 'string') sub[k] = { kind: guessKind(k), label: titleize(k) };
				});
			});
			return sub;
		}
		function guessKind(k) {
			if (/image|logo|avatar|photo|img/.test(k)) return 'image';
			if (/url|link|href|video|embed/.test(k)) return 'url';
			if (/color/.test(k)) return 'color';
			if (/^(desc|description|text|quote|answer|a|bio|body|note|subheadline)$/.test(k)) return 'textarea';
			return 'text';
		}
		// Items can be plain strings (gallery images); when rendered as a
		// repeater, promote them to objects so sub-fields have a home.
		// Arrays replace wholesale on merge, so this is safe.
		function normalizeRepeaterItems(fkey, subfields) {
			var arr = working[fkey];
			if (!Array.isArray(arr)) return;
			var first = Object.keys(subfields)[0] || 'url';
			var wrapKey = subfields.url ? 'url' : first;
			for (var i = 0; i < arr.length; i++) {
				if (typeof arr[i] === 'string') {
					var o = {};
					o[wrapKey] = arr[i];
					arr[i] = o;
				}
			}
		}

		function buildRepeater(fkey, f) {
			if (!Array.isArray(working[fkey])) working[fkey] = [];
			var sub = f.fields || {};
			var row = document.createElement('div');
			row.className = 'pg-ed-field pg-ed-repeater';
			var l = document.createElement('label');
			l.className = 'pg-ed-label';
			l.textContent = f.label;
			row.appendChild(l);

			var listEl = document.createElement('div');
			listEl.className = 'pg-ed-rep-list';
			row.appendChild(listEl);

			function itemTitle(it, i) {
				// Prefer human-name keys (title/name/question…) over whatever
				// happens to come first in the spec (often the icon class).
				var preferred = ['title', 'name', 'question', 'q', 'label', 'headline', 'author', 'text', 'caption', 'alt'];
				for (var p = 0; p < preferred.length; p++) {
					var pk = preferred[p];
					if (typeof it[pk] === 'string' && it[pk].trim()) {
						return it[pk].length > 34 ? it[pk].slice(0, 33) + '…' : it[pk];
					}
				}
				for (var k in sub) {
					if (typeof it[k] === 'string' && it[k].trim() && sub[k].kind !== 'image' && sub[k].kind !== 'url') {
						return it[k].length > 34 ? it[k].slice(0, 33) + '…' : it[k];
					}
				}
				for (var k2 in it) {
					if (typeof it[k2] === 'string' && it[k2].trim()) {
						return it[k2].length > 34 ? it[k2].slice(0, 33) + '…' : it[k2];
					}
				}
				return 'Item ' + (i + 1);
			}

			function rerender() {
				listEl.innerHTML = '';
				// Drop stale registry entries for this repeater's sub-fields.
				controls = controls.filter(function (c) { return c.path.indexOf(fkey + '.') !== 0; });
				working[fkey].forEach(function (it, i) { listEl.appendChild(buildRepRow(it, i)); });
			}

			function buildRepRow(it, i) {
				var arr = working[fkey];
				var rowEl = document.createElement('div');
				rowEl.className = 'pg-ed-rep-row';
				var head = document.createElement('div');
				head.className = 'pg-ed-rep-head';
				var caret = document.createElement('span');
				caret.className = 'pg-ed-rep-caret';
				caret.textContent = '▸';
				head.appendChild(caret);
				var title = document.createElement('span');
				title.className = 'pg-ed-rep-title';
				title.textContent = itemTitle(it, i);
				head.appendChild(title);
				var tools = document.createElement('span');
				tools.className = 'pg-ed-rep-tools';
				function toolBtn(txt, label, fn, disabled) {
					var b = document.createElement('button');
					b.type = 'button';
					b.innerHTML = txt;
					b.title = label;
					b.disabled = !!disabled;
					b.addEventListener('click', function (e) { e.stopPropagation(); fn(); });
					tools.appendChild(b);
				}
				toolBtn('&uarr;', 'Move up', function () {
					arr.splice(i - 1, 0, arr.splice(i, 1)[0]);
					markDirty(fkey); rerender();
				}, i === 0);
				toolBtn('&darr;', 'Move down', function () {
					arr.splice(i + 1, 0, arr.splice(i, 1)[0]);
					markDirty(fkey); rerender();
				}, i === arr.length - 1);
				toolBtn('&times;', 'Remove item', function () {
					arr.splice(i, 1);
					markDirty(fkey); rerender();
				});
				head.appendChild(tools);
				rowEl.appendChild(head);

				var bodyEl = document.createElement('div');
				bodyEl.className = 'pg-ed-rep-body';
				bodyEl.hidden = true;
				Object.keys(sub).forEach(function (sk) {
					var sf = sub[sk];
					var sv = it[sk];
					var inp;
					if (sf.kind === 'textarea') { inp = document.createElement('textarea'); inp.rows = 2; }
					else {
						inp = document.createElement('input');
						inp.type = sf.kind === 'number' ? 'number' : (sf.kind === 'url' || sf.kind === 'image' ? 'url' : 'text');
					}
					inp.value = sv == null ? '' : String(sv);
					inp.placeholder = sf.label;
					inp.addEventListener('input', function () {
						it[sk] = sf.kind === 'number' ? (parseFloat(inp.value) || 0) : inp.value;
						markDirty(fkey);
						if (sf.kind === 'text' || sf.kind === 'textarea') title.textContent = itemTitle(it, i);
					});
					if (sf.kind === 'text' || sf.kind === 'textarea') {
						registerText(fkey + '.' + i + '.' + sk, inp, function () { return inp.value; });
					}
					var sw = document.createElement('div');
					sw.className = 'pg-ed-rep-field';
					var sl = document.createElement('span');
					sl.textContent = sf.label;
					sw.appendChild(sl);
					sw.appendChild(inp);
					bodyEl.appendChild(sw);
				});
				rowEl.appendChild(bodyEl);
				head.addEventListener('click', function () {
					bodyEl.hidden = !bodyEl.hidden;
					caret.textContent = bodyEl.hidden ? '▸' : '▾';
				});
				return rowEl;
			}

			var add = document.createElement('button');
			add.type = 'button';
			add.className = 'pg-ed-rep-add';
			add.textContent = '+ Add item';
			add.addEventListener('click', function () {
				var blank = {};
				Object.keys(sub).forEach(function (sk) { blank[sk] = sub[sk].kind === 'number' ? 0 : ''; });
				working[fkey].push(blank);
				markDirty(fkey);
				rerender();
				var rows = listEl.querySelectorAll('.pg-ed-rep-row');
				var last = rows[rows.length - 1];
				if (last) last.querySelector('.pg-ed-rep-head').click();
			});
			row.appendChild(add);
			if (f.hint) {
				var h = document.createElement('div');
				h.className = 'pg-ed-hint';
				h.textContent = f.hint;
				row.appendChild(h);
			}
			rerender();
			return row;
		}

		// ── Click-into-field heuristic ────────────────────────────────
		// Clicking a text element inside the selected section focuses the
		// panel field whose current value best matches the clicked text.
		function focusFieldForElement(el) {
			var t = normText(el && el.textContent);
			if (!t || t.length > 400) return;
			var best = null, bestScore = 0;
			controls.forEach(function (ctl) {
				var v = normText(ctl.get());
				if (!v) return;
				var score = 0;
				if (v === t) score = 3;
				else if (v.length > 3 && t.indexOf(v) !== -1) score = 2;
				else if (t.length > 3 && v.indexOf(t) !== -1) score = 2;
				if (score > bestScore) { bestScore = score; best = ctl; }
			});
			if (best) {
				// Expand the repeater row that holds it, if collapsed.
				var repBody = best.el.closest ? best.el.closest('.pg-ed-rep-body') : null;
				if (repBody && repBody.hidden) {
					var headEl = repBody.parentNode.querySelector('.pg-ed-rep-head');
					if (headEl) headEl.click();
				}
				best.el.focus();
				try { best.el.scrollIntoView({ block: 'center', behavior: 'smooth' }); } catch (e) { best.el.scrollIntoView(); }
				var fieldEl = best.el.closest ? best.el.closest('.pg-ed-field, .pg-ed-rep-field') : null;
				if (fieldEl) {
					fieldEl.classList.remove('pg-ed-flash');
					void fieldEl.offsetWidth;
					fieldEl.classList.add('pg-ed-flash');
				}
			}
		}

		// ── Optimistic text preview ───────────────────────────────────
		// While typing, live-swap the matching text node inside the selected
		// section so the edit feels instant. Authoritative render = Apply.
		function liveUpdateText(ctl) {
			if (pageMode || !selectedSectionKey || !ctl.orig) return;
			var node = ctl.node;
			if (!node || !node.isConnected) {
				node = findTextNode(ctl.orig);
				if (!node) return;
				ctl.node = node;
			}
			node.nodeValue = ctl.get();
		}
		function findTextNode(normOrig) {
			var root = findSelectedRoot();
			if (!root) return null;
			try {
				var walker = (frame.contentDocument).createTreeWalker(root, 4 /* NodeFilter.SHOW_TEXT */);
				var n;
				while ((n = walker.nextNode())) {
					if (normText(n.nodeValue) === normOrig) return n;
				}
			} catch (e) {}
			return null;
		}

		// ── Apply loop (autosave) ─────────────────────────────────────
		// Edits flush automatically ~1.2s after the user stops; the Apply
		// button is just an immediate-flush affordance. One request in
		// flight at a time; edits made mid-flight snapshot into a queued
		// patch (latest wins) and send right after. The user's optimistic
		// DOM state is never torn down — the buffered swap silently
		// replaces it with the authoritative render when it's ready.
		function scheduleAutoApply() {
			clearTimeout(autoTimer);
			autoTimer = setTimeout(function () { flushApply(); }, AUTO_APPLY_MS);
		}

		// Snapshot the current panel state into a complete patch payload.
		function buildPatch() {
			var patch = {};
			var label;
			if (pageMode) {
				// Dotted paths group by first segment; each touched group is
				// sent COMPLETE, merged over the page's current group object.
				var groups = {};
				Object.keys(dirtyKeys).forEach(function (p) { groups[p.split('.')[0]] = 1; });
				Object.keys(groups).forEach(function (g) {
					var merged = deepClone((cfg.pageConfig && cfg.pageConfig[g] && typeof cfg.pageConfig[g] === 'object') ? cfg.pageConfig[g] : {});
					Object.keys(working).forEach(function (p) {
						if (p.split('.')[0] !== g) return;
						if (working[p] === undefined) return;
						setPath(merged, p.split('.').slice(1).join('.'), working[p]);
					});
					patch[g] = merged;
				});
				label = 'edit page settings via panel';
			} else if (selectedSectionKey) {
				// COMPLETE section object — repeaters replace wholesale.
				patch[selectedSectionKey] = deepClone(working);
				label = 'edit ' + selectedSectionKey + ' via panel';
			}
			return { patch: patch, label: label || 'panel edit' };
		}

		function flushApply() {
			clearTimeout(autoTimer);
			autoTimer = null;
			if (!isDirty()) {
				// Nothing new — but a failed payload may be waiting for retry.
				if (!applyBusy && retryPatch) {
					var rp = retryPatch;
					retryPatch = null;
					sendPatch(rp);
				}
				return;
			}
			var built = buildPatch();
			if (!Object.keys(built.patch).length) return;
			dirtyKeys = {}; // the edits now live in the snapshot
			paintApply();
			if (applyBusy) { queuedPatch = built; return; } // latest wins
			if (retryPatch) {
				// A failed save is pending — send it first, then this one.
				queuedPatch = built;
				var rp2 = retryPatch;
				retryPatch = null;
				sendPatch(rp2);
				return;
			}
			sendPatch(built);
		}

		function sendPatch(built) {
			applyBusy = true;
			setSaveState('saving');
			setError('');
			window.__pgPerf.flushAt = performance.now();

			var fd = new FormData();
			fd.append('action', 'pressgo_ai_apply_patch');
			fd.append('nonce', cfg.nonce);
			fd.append('post_id', cfg.postId);
			fd.append('changes', JSON.stringify(built.patch));
			fd.append('label', built.label);
			fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
				.then(function (r) { return r.json(); })
				.then(function (j) {
					applyBusy = false;
					if (j && j.success) {
						// Sync the local config so the next panel open shows
						// what the server now has.
						if (!cfg.pageConfig) cfg.pageConfig = {};
						Object.keys(built.patch).forEach(function (k) { cfg.pageConfig[k] = deepClone(built.patch[k]); });
						// Re-anchor optimistic text matching to the just-saved
						// values (the swapped-in doc will contain these, modulo
						// copy-lint — normText is case-insensitive anyway).
						controls.forEach(function (c) { c.orig = normText(c.get()); c.node = null; });
						window.__pgPerf.savedAt = performance.now();
						window.__pgPerf.pendingSwap = true;
						setSaveState(isDirty() || queuedPatch ? 'saving' : 'saved');
						paintApply();
						reloadPreview((j.data && j.data.preview_bust) || Date.now());
					} else {
						var msg = (j && (typeof j.data === 'string' ? j.data : (j.data && j.data.message))) || 'Could not apply that change.';
						if (!queuedPatch) retryPatch = built; // a newer queued payload supersedes
						setError(msg);
						setSaveState('error');
						paintApply();
					}
					if (queuedPatch) {
						var q = queuedPatch;
						queuedPatch = null;
						sendPatch(q);
					}
				})
				.catch(function () {
					applyBusy = false;
					// Keep the payload recoverable: it retries on the next
					// Apply click or the next flush. Anything queued behind a
					// network failure would likely fail too — drop the chain
					// (its edits still live in `working`, so the next edit
					// re-captures them).
					retryPatch = queuedPatch || built;
					queuedPatch = null;
					setError('Network error — your edits are still here; press Apply now to retry.');
					setSaveState('error');
					paintApply();
				});
		}

		// Test hook (also handy from the console).
		window.__pgEditor = {
			enable: function (on) { setSelectMode(on !== false); },
			select: selectSection,
			deselect: function () { clearSelection(true); },
			apply: flushApply,
			state: function () {
				return {
					selectMode: selectMode, selected: selectedSectionKey, pageMode: pageMode,
					dirty: Object.keys(dirtyKeys), working: working,
					applyBusy: applyBusy, queued: !!queuedPatch, retry: !!retryPatch
				};
			}
		};
	})();

	loadHistory();
	refreshCredits();
})();
