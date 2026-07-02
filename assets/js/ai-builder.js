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
	var lastCreditValue = null;
	// Build mode (Ada=basic recipe, Iris=recipe+A(eyes) review, Nova=freeform).
	var pgMode = 'basic';

	// ─── Inline composer error toast ───────────────────────────────────
	var composerError = document.getElementById('pg-composer-error');
	function showComposerError(msg, retryFn) {
		if (!composerError) return;
		composerError.innerHTML = '';
		composerError.textContent = msg;
		if (retryFn) {
			var retry = document.createElement('a');
			retry.textContent = 'Retry';
			retry.className = 'pg-composer-error-retry';
			retry.href = '#';
			retry.addEventListener('click', function (e) { e.preventDefault(); hideComposerError(); retryFn(); });
			composerError.appendChild(retry);
		}
		composerError.hidden = false;
	}
	function hideComposerError() {
		if (composerError) { composerError.hidden = true; composerError.innerHTML = ''; }
	}

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
			updateSendState();
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
			updateSendState();
		};
		reader.readAsDataURL(file);
	}
	function removePendingImage(id) {
		pendingImages = pendingImages.filter(function (im) { return im.id !== id; });
		renderStrip();
		updateSendState();
	}
	function clearPendingImages() {
		pendingImages = [];
		if (attachInput) attachInput.value = '';
		renderStrip();
		updateSendState();
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

	// ── Composer: auto-grow textarea + send-button disabled state ──
	function autoGrow() {
		if (!input) return;
		input.style.height = 'auto';
		input.style.height = Math.min(input.scrollHeight, 200) + 'px';
	}
	function updateSendState() {
		if (!sendBtn) return;
		if (sendBtn.classList.contains('is-responding')) return; // stay clickable as Stop
		var hasText = input.value.trim().length > 0;
		var hasImages = pendingImages.length > 0;
		sendBtn.disabled = !hasText && !hasImages;
	}
	input.addEventListener('input', autoGrow);
	input.addEventListener('input', updateSendState);
	autoGrow();
	updateSendState();

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

	// ─── Build mode selector (Ada / Iris / Nova) ────────────────────────
	// One dropdown replaces the old vision + Pro-mode toggles. Modes are
	// mutually exclusive: basic = recipe build, eyes = recipe + A(eyes)
	// self-review pass, freeform = Nova "build anything" composer.
	var MODE_NAMES = { basic: 'Ada', eyes: 'Iris', freeform: 'Nova' };
	var modeWrap    = document.getElementById('pg-mode');
	var modeBtn     = document.getElementById('pg-mode-btn');
	var modeMenu    = document.getElementById('pg-mode-menu');
	var modeCurrent = document.getElementById('pg-mode-current');
	try {
		var storedMode = localStorage.getItem('pgMode');
		if (storedMode && MODE_NAMES[storedMode]) pgMode = storedMode;
		else if (cfg.firstRun || localStorage.getItem('pgVision') === '1') pgMode = 'eyes'; // default/migrate to self-review
	} catch (e) {}

	function applyMode(m) {
		if (!MODE_NAMES[m]) m = 'basic';
		pgMode = m;
		try { localStorage.setItem('pgMode', m); } catch (e) {}
		if (modeCurrent) modeCurrent.textContent = MODE_NAMES[m];
		if (modeWrap) {
			modeWrap.classList.toggle('is-eyes', m === 'eyes');
			modeWrap.classList.toggle('is-freeform', m === 'freeform');
		}
		if (modeMenu) modeMenu.querySelectorAll('.pg-mode-opt').forEach(function (o) {
			o.classList.toggle('is-active', o.getAttribute('data-mode') === m);
		});
	}
	function closeModeMenu() {
		if (modeMenu) { modeMenu.hidden = true; if (modeBtn) modeBtn.setAttribute('aria-expanded', 'false'); }
	}
	if (modeBtn && modeMenu) {
		applyMode(pgMode);
		modeBtn.addEventListener('click', function (e) {
			e.stopPropagation();
			var open = modeMenu.hidden;
			modeMenu.hidden = !open;
			modeBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
		});
		modeMenu.querySelectorAll('.pg-mode-opt').forEach(function (o) {
			o.addEventListener('click', function () { applyMode(o.getAttribute('data-mode')); closeModeMenu(); });
		});
		document.addEventListener('click', function (e) {
			if (modeWrap && !modeWrap.contains(e.target)) closeModeMenu();
		});
	}

	// ─── Daily usage view (Claude-Code-style bar) ───────────────────────
	var usageEl    = document.getElementById('pg-usage');
	var usageFill  = document.getElementById('pg-usage-fill');
	var usageReset = document.getElementById('pg-usage-reset');
	var usageUpg   = document.getElementById('pg-usage-upgrade');
	var tiersPop   = document.getElementById('pg-tiers-pop');
	var tiersPopX  = document.getElementById('pg-tiers-pop-x');
	var usageResetTarget = 0; // epoch secs when the bar resets

	function fmtReset(secs) {
		if (secs <= 0) return 'resetting…';
		var h = Math.floor(secs / 3600), m = Math.floor((secs % 3600) / 60);
		return 'resets in ' + (h > 0 ? h + 'h ' + m + 'm' : (m > 0 ? m + 'm' : '<1m'));
	}
	function renderUsage(u) {
		if (!usageEl || !u) return;
		var used = u.used || 0, cap = u.cap || 0;
		var pct  = cap > 0 ? Math.min(100, Math.round(used / cap * 100)) : 0;
		usageFill.style.width = pct + '%';
		var full = cap > 0 && used >= cap, warn = !full && pct >= 80;
		usageEl.classList.toggle('is-warn', warn);
		usageEl.classList.toggle('is-full', full);
		if (usageUpg) {
			var show = warn || full;
			usageUpg.hidden = !show;
			usageUpg.classList.toggle('is-show', show);
			usageUpg.classList.toggle('is-full', full);
		}
		usageResetTarget = Math.floor(Date.now() / 1000) + (u.resets_in || 0);
		usageReset.textContent = fmtReset(u.resets_in || 0);
		// Make the bar legible: hover shows builds-left + that edits are free.
		if (u.builds_left != null) {
			usageEl.title = u.builds_left + ' new-section build' + (u.builds_left === 1 ? '' : 's') +
				' left today. Editing, reordering, delete and "make it flow" are free. Click to see plans.';
		}
	}
	function refreshUsage() {
		var fd = new FormData();
		fd.append('action', 'pressgo_ai_usage');
		fd.append('nonce', cfg.nonce);
		fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
			.then(function (r) { return r.json(); })
			.then(function (j) { if (j && j.success) renderUsage(j.data); })
			.catch(function () {});
	}
	if (usageEl) {
		renderUsage(cfg.usage);
		setInterval(function () {
			if (usageResetTarget) usageReset.textContent = fmtReset(usageResetTarget - Math.floor(Date.now() / 1000));
		}, 30000);
		function toggleTiers(e) { if (e) e.stopPropagation(); if (tiersPop) tiersPop.hidden = !tiersPop.hidden; }
		usageEl.addEventListener('click', toggleTiers);          // the whole meter is the upgrade entry point
		if (usageUpg)  usageUpg.addEventListener('click', toggleTiers);
		if (tiersPopX) tiersPopX.addEventListener('click', function (e) { e.stopPropagation(); tiersPop.hidden = true; });
		document.addEventListener('click', function (e) {
			if (tiersPop && !tiersPop.hidden && !tiersPop.contains(e.target) && !usageEl.contains(e.target)) tiersPop.hidden = true;
		});
	}

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
		append(el('pg-msg-system', 'You\'re in. Tell me the page you want to build, or tap a starter below to fill in an idea, then hit Send.'));
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
				autoGrow();        // grow to fit the filled-in text
				updateSendState(); // enable Send now, not on the next keystroke
				input.focus();
			});
			chips.appendChild(b);
		});
		append(chips);
		// Leave the box blank — the placeholder + starter chips carry the
		// first run. Tapping a chip fills the box (and enables Send); we never
		// auto-drop text the user then has to delete.
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
		if (busy) {
			startElapsed();
			sendBtn.classList.add('is-responding');
			sendBtn.disabled = false; // clickable as Stop
		} else {
			stopElapsed();
			sendBtn.classList.remove('is-responding');
			updateSendState(); // re-evaluate disabled based on input/images
		}
	}

	// Stop button: clicking the send button while responding aborts the
	// in-flight stream (if one exists) instead of submitting a new message.
	sendBtn.addEventListener('click', function (e) {
		if (sendBtn.classList.contains('is-responding')) {
			e.preventDefault();
			try { if (window.__pgActiveStream) window.__pgActiveStream.abort(); } catch (err) {}
			setBusy(false);
		}
	});

	// Lightweight elapsed-time readout (kept for potential status UI; the
	// send button itself now morphs to a stop icon while responding, so we
	// no longer overwrite its label here).
	var elapsedTimer = null;
	function startElapsed() {
		stopElapsed();
		var t0 = Date.now();
		elapsedTimer = setInterval(function () {
			var s = Math.round((Date.now() - t0) / 1000);
			sendBtn.title = s < 1 ? 'Generating…' : ( s < 30 ? s + 's elapsed' : 'Almost there' );
		}, 1000);
		sendBtn.title = 'Generating…';
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
		if (pgMode === 'eyes') fd.append('vision', '1');
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
					// A fresh AI change invalidates any pending redo branch.
					if (window.__pgResetUndo) window.__pgResetUndo();
					var built = el('pg-msg pg-msg-built');
					built.innerHTML = '<strong>Built:</strong> ' + escapeHtml(evt.summary || '(page updated)');
					append(built);
					reloadPreview(evt.preview_bust);
					if (typeof evt.credits_remaining === 'number') flashCredits(evt.credits_remaining);
					// Stagger: when the review ask renders this turn, hold the
					// next-page chips for the following build — two cards
					// stacking after one event buries both.
					refreshUsage(); // a build just landed — update the daily bar
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
					if (window.__pgResetUndo) window.__pgResetUndo();
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

	// Pro mode (beta): compose a freeform "build anything" section instead of
	// routing through the recipe chat. Non-streaming — appends one custom
	// section to the page per message.
	// Nova discovery state: when the server is mid-interview, the stage we owe an
	// answer to. While set, a typed message is routed into the same answer slot as
	// a chip tap (the free-text fallback). Cleared once a section actually builds.
	var currentDiscoveryStage = null;
	var defaultPlaceholder = input ? input.placeholder : '';
	// Assigned by the brand-panel module below; opens the panel showing the saved
	// brand so the user watches discovery become their brand on lock-in.
	var refreshBrandPanel = function () {};

	// One shared POST + response path for Nova, used by both a typed message and a
	// discovery chip tap. `fields` are extra POST fields; `userText`, if given, is
	// echoed as the user's chat bubble first.
	// Contextual "thinking chain" shown beside the typing dots while GLM (a
	// reasoning model) composes — so a ~15s build reads as working through steps,
	// not a frozen bubble. Captions are progress, not the literal model tokens.
	var THINK = {
		hero:    ['Reading your brief…', 'Sketching the hero layout…', 'Choosing an on-brand palette…', 'Writing the headline and copy…', 'Finding the right photo…', 'Laying out the section…', 'Assembling your hero…', 'Polishing the details…', 'Almost there…'],
		section: ['Planning the section…', 'Matching your brand…', 'Choosing the right layout…', 'Writing the copy…', 'Finding imagery…', 'Putting it together…', 'Polishing the details…', 'Almost there…'],
		recolor: ['Rethinking the look…', 'Trying a fresh palette and type…', 'Rewriting to fit…', 'Rebuilding your hero…', 'Polishing the details…', 'Almost there…'],
		cohesion: ['Stepping back to look at your whole page…', 'Reading the flow, section by section…', 'Finding a smarter order…', 'Balancing the dark and light so it all flows…', 'Recoloring each section to stay readable…', 'Putting it back together…', 'Checking it all reads well…', 'Almost there…'],
		edit:    ['Finding that section…', 'Reworking it in place…', 'Keeping the rest untouched…', 'Applying your change…', 'Polishing the details…', 'Almost there…'],
		chat:    ['Thinking…', 'Looking at your page…', 'Weighing it up…', 'Gathering my thoughts…'],
		quick:   ['Thinking…']
	};
	// Pick a thinking-caption set that matches what the user actually asked, so the
	// status reads true to the request (an edit/chat shouldn't say "building").
	function freeformThinkKind(text) {
		var t = (text || '').toLowerCase();
		if (/\b(make .*(flow|cohesive)|flow better|re-?organi|fix the order|redo the order|balance the colou?rs?|tidy (it|this)( up)?|clean (it|this) up|smart order)\b/.test(t)) return 'cohesion';
		if (/^\s*(undo|put it back|revert|no\b|nope|nah|not that|stop|wait|hold on|never ?mind|forget)/.test(t)) return 'quick';
		if (/\?\s*$/.test(text) || /^(what|how|why|should|could|would|which|is it|are there|do you|any\b)/.test(t)) return 'chat';
		if (/\b(not sure|thoughts?|suggest|recommend|ideas?|feels? (off|basic|flat|empty)|too (plain|basic)|make it pop|more (energy|modern|premium)|cleaner|something more)\b/.test(t)) return 'chat';
		if (!/\badd\b/.test(t) && /\b(make|change|update|recolou?r|rewrite|reword|swap|rename|move|replace|edit|tweak|adjust|fix|shorten|lengthen|bigger|smaller|darker|lighter|bolder|brighter|cleaner|shorter|longer|should be)\b/.test(t)) return 'edit';
		return 'section';
	}
	function makeThinking(steps) {
		var node = document.createElement('div');
		node.className = 'pg-thinking';
		node.style.cssText = 'display:flex;align-items:center;gap:10px;margin:4px 0;';
		node.appendChild(typingNode());
		var timer = null;
		if (steps && steps.length) {
			var cap = document.createElement('span');
			cap.className = 'pg-thinking-cap';
			cap.style.cssText = 'font-size:13px;color:#6b7280;font-style:italic;';
			cap.textContent = steps[0];
			node.appendChild(cap);
			var i = 0;
			// Loop (don't stop at the last step) so a slow build/reorganize never sits
			// frozen on one caption — it keeps visibly working until the response lands.
			timer = setInterval(function () {
				i = ( i + 1 ) % steps.length;
				cap.textContent = steps[i];
			}, 2400);
		}
		return { node: node, stop: function () { if (timer) { clearInterval(timer); timer = null; } } };
	}

	function postFreeform(fields, userText, thinkKind, done) {
		if (userText != null) {
			var userBubble = el('pg-msg pg-msg-user');
			var txt = document.createElement('div');
			txt.textContent = userText;
			userBubble.appendChild(txt);
			append(userBubble);
		}
		var think = makeThinking(THINK[thinkKind] || THINK.quick);
		append(think.node);
		setBusy(true);

		var fd = new FormData();
		fd.append('action', 'pressgo_ai_freeform');
		fd.append('nonce', cfg.nonce);
		fd.append('post_id', cfg.postId);
		Object.keys(fields).forEach(function (k) {
			if (fields[k] != null) fd.append(k, fields[k]);
		});

		fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
			.then(function (r) {
				// Read as text first so a non-JSON body (PHP warning/HTML) surfaces a
				// real message instead of a blind "network error".
				return r.text().then(function (body) {
					var json = null;
					try { json = JSON.parse(body); } catch (e) {}
					return { json: json, body: body };
				});
			})
			.then(function (res) {
				think.stop(); think.node.remove();
				handleFreeformResult(res);
				if (done) done(res && res.json && res.json.data);
			})
			.catch(function () {
				think.stop(); think.node.remove();
				append(el('pg-msg-error', 'Network error during Pro mode compose — try again.'));
				setBusy(false);
				if (done) done(null);
			});
	}

	function handleFreeformResult(res) {
		var d = res.json;
		if (d && d.success) {
			var data = d.data || {};
			// Mid-interview / confirm: render the question + chips and wait. A confirm
			// step (brand lock) shows the freshly-built hero, so reload the preview
			// first, then render its swatches + chips.
			if (data.needs_discovery) {
				if (data.preview_bust) reloadPreview(data.preview_bust);
				// The brand-lock step rides brand_synced + a note alongside its chips —
				// fill the panel and confirm the lock before asking the build-mode fork.
				if (data.brand_synced) refreshBrandPanel(data.brand);
				if (data.note) append(el('pg-msg pg-msg-ai', data.note));
				renderDiscovery(data);
				return;
			}
			// "Build the whole page": the server returns an ordered plan; build each
			// section one at a time so the user watches it fill in and can stop.
			if (data.whole_page_plan && data.whole_page_plan.length) {
				append(el('pg-msg pg-msg-ai', data.note || 'Building your whole page…'));
				currentDiscoveryStage = null;
				if (input) input.placeholder = defaultPlaceholder;
				runWholePagePlan(data.whole_page_plan);
				return;
			}
			// Conversational reply (not a build): a softer "talking to you" bubble,
			// visually distinct from the terse build/system notes, then its chips.
			if (data.chat_mode) {
				append(el('pg-msg pg-msg-ai pg-msg-convo', data.note || ''));
				if (data.suggest && !wholeRunning) renderSuggestions(data.suggest);
				setBusy(false);
				return;
			}
			append(el('pg-msg pg-msg-ai', data.note || 'Composed a freeform section.'));
			currentDiscoveryStage = null; // a section built — discovery is done
			if (input) input.placeholder = defaultPlaceholder;
			if (data.brand_synced) refreshBrandPanel(data.brand); // watch the panel fill in
			reloadPreview(data.preview_bust || Date.now());
			if (data.usage) renderUsage(data.usage); else refreshUsage();
			if (data.suggest && !wholeRunning) renderSuggestions(data.suggest); // keep the wizard leading
		} else if (d && d.data) {
			append(el('pg-msg-error', typeof d.data === 'string' ? d.data : (d.data.message || 'Pro mode compose failed.')));
		} else {
			// Non-JSON body — show a trimmed snippet so the real cause is visible.
			var snip = (res.body || '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 160);
			append(el('pg-msg-error', 'Pro mode compose failed' + (snip ? ': ' + snip : ' — try again.')));
		}
		setBusy(false);
	}

	// Render a discovery step: the question as an AI bubble, then a row of tappable
	// chips. Tapping sends the value back; the user can still type a free answer.
	function renderDiscovery(data) {
		append(el('pg-msg pg-msg-ai', data.question || 'Quick question:'));
		// Brand-confirm: show the hero's real colors as dots + the chosen fonts.
		if (data.swatches && data.swatches.length) {
			var sw = document.createElement('div');
			sw.className = 'pg-brand-swatches';
			sw.style.cssText = 'display:flex;flex-wrap:wrap;gap:14px;align-items:center;margin:8px 0 4px;';
			data.swatches.forEach(function (s) {
				var item = document.createElement('div');
				item.style.cssText = 'display:flex;align-items:center;gap:6px;font-size:12px;color:#555;';
				var dot = document.createElement('span');
				dot.style.cssText = 'width:18px;height:18px;border-radius:50%;border:1px solid rgba(0,0,0,0.12);display:inline-block;background:' + (s.color || '#ccc') + ';';
				item.appendChild(dot);
				item.appendChild(document.createTextNode(s.label || ''));
				sw.appendChild(item);
			});
			if (data.fonts && data.fonts.heading) {
				var ft = document.createElement('div');
				ft.style.cssText = 'font-size:12px;color:#555;font-weight:600;';
				ft.textContent = 'Aa ' + data.fonts.heading + ' / ' + (data.fonts.body || data.fonts.heading);
				sw.appendChild(ft);
			}
			append(sw);
		}
		var wrap = document.createElement('div');
		wrap.className = 'pg-discovery-chips';
		wrap.style.cssText = 'display:flex;flex-wrap:wrap;gap:8px;margin:8px 0 2px;';
		(data.chips || []).forEach(function (chip) {
			var b = document.createElement('button');
			b.type = 'button';
			b.textContent = chip.label;
			b.className = 'pg-discovery-chip' + (chip.selected ? ' pg-chip-selected' : '');
			b.style.cssText = 'border:1px solid #d9d6ff;background:' + (chip.selected ? '#e7e3ff' : '#f3f1ff') +
				';color:#5b4fff;border-radius:999px;padding:6px 14px;font-size:13px;cursor:pointer;line-height:1.2;font-weight:' +
				(chip.selected ? '600' : '500') + ';';
			b.addEventListener('click', function () { onDiscoveryChip(wrap, chip); });
			wrap.appendChild(b);
		});
		append(wrap);
		currentDiscoveryStage = data.stage || null;
		if (input && data.freetext_hint) input.placeholder = data.freetext_hint;
		setBusy(false); // keep the box live so the free-text fallback works
		setTimeout(function () { if (input) input.focus(); }, 50);
	}

	// "Build the whole page" — drive the server's ordered plan one section at a
	// time. Each step is a normal build (so the preview fills in live and usage
	// ticks), but suggestions are suppressed mid-run and re-shown once at the end.
	// A Stop button halts after the current section; "stop" typed also works.
	var wholeRunning = false;
	var wholeStop = false;
	function runWholePagePlan(plan) {
		wholeRunning = true;
		wholeStop = false;
		var lastData = null;
		// Stop affordance.
		var bar = el('pg-msg pg-msg-ai');
		var stopBtn = document.createElement('button');
		stopBtn.type = 'button';
		stopBtn.textContent = '■ Stop building';
		stopBtn.style.cssText = 'border:1px solid #e5b3b3;background:#fff5f5;color:#b54141;border-radius:999px;padding:6px 14px;font-size:13px;cursor:pointer;font-weight:600;';
		stopBtn.addEventListener('click', function () { wholeStop = true; stopBtn.disabled = true; stopBtn.style.opacity = '0.5'; stopBtn.textContent = 'Stopping after this one…'; });
		bar.appendChild(stopBtn);
		append(bar);

		var i = 0;
		function finish() {
			wholeRunning = false;
			bar.remove();
			refreshUsage();
			if (wholeStop) {
				append(el('pg-msg pg-msg-ai', 'Stopped there. Tap what to add next, or tell me your own.'));
				if (lastData && lastData.suggest) renderSuggestions(lastData.suggest);
				return;
			}
			// Core run done: offer the extended tier (deeper gold-standard sections).
			if (lastData && lastData.extend) {
				if ((lastData.extend.chips || []).length) renderSuggestions(lastData.extend);
				else append(el('pg-msg pg-msg-ai', lastData.extend.note || "That's your page."));
				return;
			}
			append(el('pg-msg pg-msg-ai', "That's your page. Want me to ✨ make it flow, or tweak anything?"));
			if (lastData && lastData.suggest) renderSuggestions(lastData.suggest);
		}
		function step() {
			if (wholeStop || i >= plan.length) { finish(); return; }
			var s = plan[i++];
			postFreeform({ message: s.request, section_key: s.key || '', whole_page: '1' }, null, 'section', function (data) {
				if (data) lastData = data;
				setTimeout(step, 200);
			});
		}
		step();
	}

	// After a build, the server suggests the next sections for this page type.
	// Tapping one fires a normal build request (which may hit a drip), then the
	// response suggests the rest — the wizard keeps leading section by section.
	function renderSuggestions(s) {
		if (!s || !s.chips || !s.chips.length) return;
		append(el('pg-msg pg-msg-ai', s.note || 'What should we add next?'));
		var wrap = document.createElement('div');
		wrap.className = 'pg-suggest-chips';
		wrap.style.cssText = 'display:flex;flex-wrap:wrap;gap:8px;margin:8px 0 2px;';
		s.chips.forEach(function (c) {
			var b = document.createElement('button');
			b.type = 'button';
			var isFlow = (c.key === 'cohesion');
			var isNoop = (c.op === 'continue' || c.request === '__noop');
			var noPlus = (s.clarify || s.suggested); // choices/answers, not "+ add"
			// Clarify/suggested chips are choices (a question or a suggested answer),
			// not "+ add a section" actions. The no-op "let me type" chip is neutral.
			var prefix = isNoop ? '✍️ ' : (isFlow ? '✨ ' : (noPlus ? '' : '+ '));
			b.textContent = prefix + c.label;
			b.className = 'pg-suggest-chip' + (isFlow ? ' pg-suggest-flow' : '');
			// Flow = accent gradient; no-op = dashed ghost; a chat SUGGESTED answer =
			// accent-outlined "reply" pill (tap to confirm/act); default = soft add chip.
			b.style.cssText = isFlow
				? 'border:none;background:linear-gradient(135deg,#5b4fff,#8b5cf6);color:#fff;border-radius:999px;padding:7px 16px;font-size:13px;cursor:pointer;line-height:1.2;font-weight:700;box-shadow:0 2px 10px rgba(91,79,255,0.4);margin-right:2px;'
				: isNoop
					? 'border:1px dashed #cbd5e1;background:#fff;color:#64748b;border-radius:999px;padding:6px 14px;font-size:13px;cursor:pointer;line-height:1.2;font-weight:500;'
					: s.suggested
						? 'border:1.5px solid #5b4fff;background:#fff;color:#5b4fff;border-radius:999px;padding:7px 15px;font-size:13px;cursor:pointer;line-height:1.2;font-weight:600;'
						: 'border:1px solid #c7d2fe;background:#eef2ff;color:#4338ca;border-radius:999px;padding:6px 14px;font-size:13px;cursor:pointer;line-height:1.2;font-weight:500;';
			b.addEventListener('click', function () {
				// "Go deeper": run the extended tier through the same whole-page runner
				// (stop button, drip suppression, per-step usage all included).
				if (c.op === 'extend' && c.plan && c.plan.length) {
					Array.prototype.forEach.call(wrap.querySelectorAll('button'), function (x) { x.disabled = true; x.style.opacity = '0.4'; });
					append(el('pg-msg pg-msg-ai', 'Going deeper — ' + c.plan.length + ' more sections coming up.'));
					runWholePagePlan(c.plan);
					return;
				}
				// No-op "let me type something else": just clear the chips and hand the
				// floor back to the input — never forces a build.
				if (isNoop) { wrap.remove(); if (input) input.focus(); return; }
				Array.prototype.forEach.call(wrap.querySelectorAll('button'), function (x) {
					x.disabled = true; x.style.cursor = 'default';
					if (x !== b) x.style.opacity = '0.4';
				});
				if (!isFlow) { b.style.background = '#4338ca'; b.style.color = '#fff'; b.style.borderColor = '#4338ca'; }
				postFreeform({ message: c.request, section_key: c.key }, c.label, isFlow ? 'cohesion' : 'section');
			});
			wrap.appendChild(b);
		});
		append(wrap);
		setTimeout(function () { if (input) input.focus(); }, 50);
	}

	function onDiscoveryChip(wrap, chip) {
		// Lock the row and show the choice (the answer also echoes as a user bubble).
		Array.prototype.forEach.call(wrap.querySelectorAll('button'), function (btn) {
			btn.disabled = true;
			btn.style.cursor = 'default';
			if (btn.textContent === chip.label) {
				btn.style.background = '#5b4fff'; btn.style.color = '#fff'; btn.style.borderColor = '#5b4fff';
			} else {
				btn.style.opacity = '0.4';
			}
		});
		var stage = currentDiscoveryStage;
		var kind = 'quick';
		if (stage === 'photos') kind = 'hero';
		else if (stage === 'brand_confirm' && (chip.value === 'recolor' || chip.value === 'refont')) kind = 'recolor';
		else if (stage === 'proof' || stage === 'offer') kind = 'section';
		postFreeform({ message: chip.label, discovery_stage: stage, discovery_value: chip.value }, chip.label, kind);
	}

	function sendFreeform(text) {
		chatStarted = true;
		input.value = '';
		var fields = { message: text };
		// Attached screenshots ride along (same wire format as the recipe chat) so
		// "don't need icons here" + a screenshot actually reaches the builder.
		if (pendingImages.length) {
			fields.images = JSON.stringify(pendingImages.map(function (im) {
				return { base64: im.base64, mediaType: im.mediaType };
			}));
			clearPendingImages();
		}
		var kind = freeformThinkKind(text); // status caption matches the request intent
		// Typed answer during an interview: route it into the awaited stage so it
		// lands in the same slot a chip tap would (server parses the text).
		if (currentDiscoveryStage) {
			fields.discovery_stage = currentDiscoveryStage;
			fields.discovery_value = '';
			kind = (currentDiscoveryStage === 'brand_confirm') ? 'recolor'
				: (currentDiscoveryStage === 'photos') ? 'hero' : 'quick';
		} else if (selectedSectionKey) {
			// A section is selected in the preview — scope this edit to it (the server
			// re-composes that section in place instead of adding a new one).
			fields.selected_section = selectedSectionKey;
		}
		postFreeform(fields, text, kind);
	}

	form.addEventListener('submit', function (e) {
		e.preventDefault();
		var text = (input.value || '').trim();
		if (!text) return;
		if (pgMode === 'freeform') { sendFreeform(text); }
		else { sendMessage(text); }
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

	// ===== Site Brand panel =====
	// Full design-system controller: identity, colors, fonts, logo/favicon.
	// Saves re-render the current page so changes are immediately visible.
	(function () {
		var b = cfg.brand || {};
		var actions = document.querySelector('.pg-builder-actions');
		if (!actions) return;

		// -- Chip in the topbar --
		var chip = document.createElement('button');
		chip.type = 'button';
		chip.className = 'pg-builder-ghost pg-brand-chip';
		var on = !!b.enabled;
		var shortName = (b.name || '').length > 16 ? b.name.slice(0, 15) + '\u2026' : (b.name || '');
		function paint() {
			var label = on ? ('Brand: ' + (shortName ? escapeHtml(shortName) : 'on')) : 'Brand: off';
			chip.innerHTML = '<span class="pg-brand-dot' + (on ? ' is-on' : '') + '"></span>' + label;
			chip.style.opacity = on ? '1' : '0.65';
		}
		paint();
		if (!b.exists) chip.style.display = 'none';
		actions.insertBefore(chip, actions.firstChild);

		// -- Panel state --
		var brandPanel = null;
		function closeBrandPanel() {
			if (brandPanel) { brandPanel.remove(); brandPanel = null; document.removeEventListener('click', onBrandDocClick, true); }
		}
		function onBrandDocClick(e) {
			if (brandPanel && !brandPanel.contains(e.target) && e.target !== chip && !chip.contains(e.target)) closeBrandPanel();
		}

		// -- Helpers --
		function el(tag, cls, text) {
			var e = document.createElement(tag);
			if (cls) e.className = cls;
			if (text != null) e.textContent = text;
			return e;
		}
		function isHex(v) { return /^#[0-9a-fA-F]{3,8}$/.test(String(v || '')); }
		function isColorLike(v) { return isHex(v) || /^rgba?\(/i.test(String(v || '')); }

		// Ordered color groups for the swatch grid.
		// Only roles the global recolor engine actually reads — no dead controls.
		// (primary + gold were removed: the repaint never used them.)
		var COLOR_GROUPS = [
			{ label: 'Brand', keys: ['accent', 'primary_dark'] },
			{ label: 'Backgrounds', keys: ['dark_bg', 'light_bg', 'white'] },
			{ label: 'Text', keys: ['text_dark', 'text_muted', 'text_light'] },
		];

		function textInput(value, placeholder) {
			var i = document.createElement('input');
			i.type = 'text';
			i.value = value || '';
			if (placeholder) i.placeholder = placeholder;
			return i;
		}
		function selectInput(selected, groups) {
			var sel = document.createElement('select');
			sel.className = 'pg-brand-select';
			var blank = document.createElement('option');
			blank.value = '';
			blank.textContent = 'Choose a font\u2026';
			sel.appendChild(blank);
			// If the saved/learned font isn't in our list, surface it as a selected
			// option so the dropdown doesn't fall back to blank and clobber it on save.
			var inList = false;
			Object.keys(groups).forEach(function (cat) {
				(groups[cat] || []).forEach(function (n) { if (n === selected) inList = true; });
			});
			if (selected && !inList) {
				var cur = document.createElement('option');
				cur.value = selected;
				cur.textContent = selected + ' (current)';
				cur.selected = true;
				cur.style.fontFamily = "'" + selected + "', sans-serif";
				sel.appendChild(cur);
			}
			Object.keys(groups).forEach(function (cat) {
				var og = document.createElement('optgroup');
				og.label = cat;
				groups[cat].forEach(function (name) {
					var opt = document.createElement('option');
					opt.value = name;
					opt.textContent = name;
					opt.style.fontFamily = "'" + name + "', sans-serif";
					if (name === selected) opt.selected = true;
					og.appendChild(opt);
				});
				sel.appendChild(og);
			});
			if (!selected) { sel.selectedIndex = 0; }
			return sel;
		}

		// A color swatch with hex readout + label.
		function colorSwatch(key, hex) {
			var wrap = document.createElement('div');
			wrap.className = 'pg-brand-swatch2';
			var ci = document.createElement('input');
			ci.type = 'color';
			ci.dataset.key = key;
			var safeHex = isHex(hex)
				? (hex.length === 4
					? '#' + hex[1] + hex[1] + hex[2] + hex[2] + hex[3] + hex[3]
					: hex.slice(0, 7))
				: '#000000';
			ci.value = safeHex;
			var preview = document.createElement('span');
			preview.className = 'pg-brand-swatch2-color';
			preview.style.background = hex || safeHex;
			var label = document.createElement('span');
			label.className = 'pg-brand-swatch2-label';
			label.textContent = key.replace(/_/g, ' ');
			var hexVal = document.createElement('span');
			hexVal.className = 'pg-brand-swatch2-hex';
			hexVal.textContent = isHex(hex) ? hex.toUpperCase() : String(hex || '').slice(0, 20);
			// Only colors the user actually drags get sent on save. Without this, an
			// <input type=color> reports its coerced value (#000000 for an rgba role
			// like text_light) for every swatch, so a plain Save would overwrite those
			// roles with black. The dirty flag means an untouched swatch is left alone.
			if (!isHex(hex)) { wrap.classList.add('is-nonhex'); }
			ci.dataset.dirty = '';
			ci.addEventListener('input', function () {
				ci.dataset.dirty = '1';
				preview.style.background = ci.value;
				hexVal.textContent = ci.value.toUpperCase();
			});
			wrap.appendChild(ci);
			wrap.appendChild(preview);
			wrap.appendChild(label);
			wrap.appendChild(hexVal);
			return wrap;
		}

		// Logo / favicon selector using the WordPress media library.
		function uploadRow(field, label, currentUrl) {
			var row = document.createElement('div');
			row.className = 'pg-brand-upload';
			var preview = document.createElement('div');
			preview.className = 'pg-brand-upload-preview';
			if (currentUrl) {
				preview.style.backgroundImage = 'url("' + currentUrl + '")';
			} else {
				preview.classList.add('is-empty');
			}
			var info = document.createElement('div');
			info.className = 'pg-brand-upload-info';
			var lbl = el('span', 'pg-brand-upload-label', label);
			var urlSpan = el('span', 'pg-brand-upload-url', currentUrl ? currentUrl.split('/').pop() : 'No file selected');
			info.appendChild(lbl);
			info.appendChild(urlSpan);
			var btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'pg-brand-upload-btn';
			btn.textContent = currentUrl ? 'Replace' : 'Choose';
			btn.addEventListener('click', function () {
				if (typeof wp === 'undefined' || !wp.media) {
					alert('Media library not available. Make sure you are logged in as admin.');
					return;
				}
				var frame = wp.media({
					title: 'Select ' + label,
					button: { text: 'Use as ' + label },
					library: { type: 'image' },
					multiple: false,
				});
				frame.on('select', function () {
					var attachment = frame.state().get('selection').first().toJSON();
					var imgUrl = attachment.url;
					preview.classList.remove('is-empty');
					preview.style.backgroundImage = 'url("' + imgUrl + '")';
					urlSpan.textContent = attachment.filename || imgUrl.split('/').pop();
					btn.textContent = 'Replace';
					// Save to the brand foundation immediately.
					var payload = {};
					payload[field] = imgUrl;
					var fd = new FormData();
					fd.append('action', 'pressgo_ai_brand_save');
					fd.append('nonce', cfg.nonce);
					fd.append('brand', JSON.stringify(payload));
					fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
						.then(function (r) { return r.json(); })
						.then(function (j) {
							if (j && j.success) {
								append(el('pg-msg-system', label + ' updated.'));
							}
						});
				});
				frame.open();
			});
			row.appendChild(preview);
			row.appendChild(info);
			row.appendChild(btn);
			return row;
		}

		function openBrandPanel(state, justSaved) {
			closeBrandPanel();
			var b = state.brand || {};
			brandPanel = document.createElement('div');
			brandPanel.className = 'pg-brand-panel';

			// -- Header --
			var head = document.createElement('div');
			head.className = 'pg-brand-head';
			var title = el('strong', null, 'Site brand');
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

			// -- Saved banner --
			if (justSaved) {
				var saved = el('div', 'pg-brand-saved', '\u2713 Saved. This brand now applies to every new page automatically. Tweak anything below if you like.');
				brandPanel.appendChild(saved);
			}

			// -- Section: Identity --
			var idSection = el('div', 'pg-brand-section');
			idSection.appendChild(el('div', 'pg-brand-section-title', 'Identity'));
			var nameI = textInput(b.brand_name, 'Business name');
			idSection.appendChild((function () {
				var r = document.createElement('label'); r.className = 'pg-brand-row';
				r.appendChild(el('span', null, 'Name')); r.appendChild(nameI); return r;
			})());
			var indI = textInput(b.industry, 'Industry');
			idSection.appendChild((function () {
				var r = document.createElement('label'); r.className = 'pg-brand-row';
				r.appendChild(el('span', null, 'Industry')); r.appendChild(indI); return r;
			})());
			var voiceI = document.createElement('textarea');
			voiceI.rows = 2;
			voiceI.placeholder = 'Voice (e.g. warm and plainspoken)';
			voiceI.value = b.voice || '';
			idSection.appendChild((function () {
				var r = document.createElement('label'); r.className = 'pg-brand-row';
				r.appendChild(el('span', null, 'Voice')); r.appendChild(voiceI); return r;
			})());
			brandPanel.appendChild(idSection);

			// -- Section: Logo & Favicon --
			var logoSection = el('div', 'pg-brand-section');
			logoSection.appendChild(el('div', 'pg-brand-section-title', 'Logo & Favicon'));
			logoSection.appendChild(uploadRow('logo_url', 'Logo', b.logo_url || ''));
			logoSection.appendChild(uploadRow('favicon_url', 'Favicon', b.favicon_url || ''));
			brandPanel.appendChild(logoSection);

			// -- Section: Colors --
			var colors = b.colors || {};
			var colorInputs = {};
			var colorSection = el('div', 'pg-brand-section');
			colorSection.appendChild(el('div', 'pg-brand-section-title', 'Colors'));
			var usedKeys = {};
			COLOR_GROUPS.forEach(function (grp) {
				var groupKeys = grp.keys.filter(function (k) {
					return colors[k] && !usedKeys[k] && (isHex(colors[k]) || isColorLike(colors[k]));
				});
				if (!groupKeys.length) return;
				if (grp.label !== 'Brand') colorSection.appendChild(el('div', 'pg-brand-color-group-label', grp.label));
				var grid = document.createElement('div');
				grid.className = 'pg-brand-colors';
				groupKeys.forEach(function (k) {
					usedKeys[k] = true;
					var sw = colorSwatch(k, colors[k]);
					colorInputs[k] = sw.querySelector('input[type="color"]');
					grid.appendChild(sw);
				});
				colorSection.appendChild(grid);
			});
			brandPanel.appendChild(colorSection);

			// -- Section: Typography --
			var fonts = b.fonts || {};
			var fontGroups = cfg.fontList || {};
			var fontSection = el('div', 'pg-brand-section');
			fontSection.appendChild(el('div', 'pg-brand-section-title', 'Typography'));
			var headF = selectInput(fonts.heading, fontGroups);
			var bodyF = selectInput(fonts.body, fontGroups);
			fontSection.appendChild((function () {
				var r = document.createElement('label'); r.className = 'pg-brand-row';
				r.appendChild(el('span', null, 'Headings')); r.appendChild(headF); return r;
			})());
			fontSection.appendChild((function () {
				var r = document.createElement('label'); r.className = 'pg-brand-row';
				r.appendChild(el('span', null, 'Body')); r.appendChild(bodyF); return r;
			})());
			brandPanel.appendChild(fontSection);

			// -- Section: Page Options --
			var pageSection = el('div', 'pg-brand-section');
			var optWrap = document.createElement('label');
			optWrap.className = 'pg-brand-toggle';
			var optCb = document.createElement('input');
			optCb.type = 'checkbox';
			optCb.checked = !!state.page_optout;
			optWrap.appendChild(optCb);
			optWrap.appendChild(document.createTextNode(' skip the brand on THIS page only'));
			pageSection.appendChild(optWrap);
			optCb.addEventListener('change', function () {
				var fd = new FormData();
				fd.append('action', 'pressgo_ai_brand_optout');
				fd.append('nonce', cfg.nonce);
				fd.append('post_id', cfg.postId);
				fd.append('optout', optCb.checked ? '1' : '');
				fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd });
			});
			brandPanel.appendChild(pageSection);

			// -- Footer: Save + Danger Zone --
			var foot = document.createElement('div');
			foot.className = 'pg-brand-foot';
			var saveBtn = document.createElement('button');
			saveBtn.type = 'button';
			saveBtn.className = 'pg-modal-btn is-primary';
			saveBtn.textContent = 'Save & apply';
			var clearBtn = document.createElement('button');
			clearBtn.type = 'button';
			clearBtn.className = 'pg-modal-btn is-danger';
			clearBtn.textContent = 'Clear & relearn';
			clearBtn.title = 'Forget this brand. The next first build on a fresh page learns a new one.';
			foot.appendChild(clearBtn);
			foot.appendChild(saveBtn);
			brandPanel.appendChild(foot);

			// -- Toggle handler --
			toggle.addEventListener('change', function () {
				on = toggle.checked;
				paint();
				var fd = new FormData();
				fd.append('action', 'pressgo_ai_brand_toggle');
				fd.append('nonce', cfg.nonce);
				fd.append('enabled', on ? '1' : '');
				fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd });
			});

			// -- Save handler --
			saveBtn.addEventListener('click', function () {
				saveBtn.disabled = true;
				saveBtn.textContent = 'Saving\u2026';
				var payload = {
					brand_name: nameI.value.trim(),
					industry: indI.value.trim(),
					voice: voiceI.value.trim(),
					colors: {},
				};
				// Only send fonts the user actually chose — an empty select must not
				// overwrite a real brand font with ''.
				var fonts = {};
				if (headF.value.trim()) fonts.heading = headF.value.trim();
				if (bodyF.value.trim()) fonts.body = bodyF.value.trim();
				if (Object.keys(fonts).length) payload.fonts = fonts;
				// Only send swatches the user edited (see colorSwatch dirty flag).
				Object.keys(colorInputs).forEach(function (k) {
					if (colorInputs[k].dataset.dirty === '1') payload.colors[k] = colorInputs[k].value;
				});
				var fd = new FormData();
				fd.append('action', 'pressgo_ai_brand_save');
				fd.append('nonce', cfg.nonce);
				fd.append('brand', JSON.stringify(payload));
				fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
					.then(function (r) { return r.json(); })
					.then(function (j) {
						if (!j || !j.success) {
							saveBtn.disabled = false;
							saveBtn.textContent = 'Save & apply';
							return;
						}
						var nm = (j.data.brand && j.data.brand.brand_name) || '';
						shortName = nm.length > 16 ? nm.slice(0, 15) + '\u2026' : nm;
						paint();
						saveBtn.textContent = 'Applying\u2026';
						append(el('pg-msg-system', 'Brand saved. Re-rendering page\u2026'));
						// Re-apply the updated brand to the current page.
						var rfd = new FormData();
						rfd.append('action', 'pressgo_ai_brand_reapply');
						rfd.append('nonce', cfg.nonce);
						rfd.append('post_id', cfg.postId);
						fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: rfd })
							.then(function (r) { return r.json(); })
							.then(function (rj) {
								saveBtn.disabled = false;
								saveBtn.textContent = 'Save & apply';
								if (rj && rj.success) {
									reloadPreview(rj.data.preview_bust || Date.now());
									closeBrandPanel();
									append(el('pg-msg-system', 'Done. ' + (rj.data.sections || 0) + ' sections recolored to the new brand. Say "undo" to put it back.'));
								} else {
									closeBrandPanel();
									append(el('pg-msg-system', 'Brand saved. New pages will follow it.'));
								}
							})
							.catch(function () {
								saveBtn.disabled = false;
								saveBtn.textContent = 'Save & apply';
								closeBrandPanel();
								append(el('pg-msg-system', 'Brand saved. New pages will follow it.'));
							});
					})
					.catch(function () { saveBtn.disabled = false; saveBtn.textContent = 'Save & apply'; });
			});

			// -- Clear handler --
			clearBtn.addEventListener('click', function () {
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

		// -- Chip click: open panel --
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

		// -- Exposed to chat: on brand lock-in, reveal + open panel --
		refreshBrandPanel = function () {
			var fd = new FormData();
			fd.append('action', 'pressgo_ai_brand_get');
			fd.append('nonce', cfg.nonce);
			fd.append('post_id', cfg.postId);
			fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
				.then(function (r) { return r.json(); })
				.then(function (j) {
					if (!j || !j.success) return;
					var st = j.data || {};
					if (st.brand) {
						on = st.enabled !== false;
						var nm = st.brand.brand_name || st.brand.name || '';
						shortName = nm.length > 16 ? nm.slice(0, 15) + '\u2026' : nm;
						chip.style.display = '';
						paint();
					}
					closeBrandPanel();
					openBrandPanel(st, true);
				})
				.catch(function () {});
		};
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

	// Enter to send (Shift+Enter for newline) — desktop only. On touch
	// devices Enter inserts a newline; the send button is the only way to
	// submit, which avoids the mobile soft-keyboard "go" trap.
	function isMobileDevice() {
		return (navigator.maxTouchPoints || 0) > 0 || window.innerWidth < 768;
	}
	input.addEventListener('keydown', function (e) {
		if (e.key === 'Enter' && !e.shiftKey && !isMobileDevice()) {
			e.preventDefault();
			form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
		}
	});

	// ════════════════════════════════════════════════════════════════════
	// Visual editor — Select mode + schema-driven property panel + inline
	// text editing (click text in the preview and just type, Word-doc style).
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
		var inline     = null;   // active inline text edit (see Inline editing block)

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
				if (inline) commitInline();  // a focused inline edit saves too
				if (isDirty()) flushApply(); // autosave anything pending on exit
				disarmDoc();
				selectedSectionKey = '';
				renderChatChip();
				renderSecChip();
				closeImagePicker();
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
					'box-shadow: 0 2px 8px rgba(91,79,255,0.35); letter-spacing: 0.02em; }',
				// Inline text editing: discoverability (I-beam + dotted underline
				// shimmer on editable text inside the SELECTED section), the
				// hairline outline while editing, and the tiny floating hint.
				'@keyframes pg-ed-shimmer { 0%,100% { text-decoration-color: rgba(91,79,255,0.25); } 50% { text-decoration-color: rgba(91,79,255,0.8); } }',
				'.pg-sec.pg-ed-selected .pg-ed-text { cursor: text !important; }',
				'.pg-sec.pg-ed-selected .pg-ed-text:hover { text-decoration-line: underline !important; text-decoration-style: dotted !important;' +
					'text-decoration-color: rgba(91,79,255,0.55) !important; text-underline-offset: 3px;' +
					'animation: pg-ed-shimmer 1.6s ease-in-out infinite; }',
				'.pg-inline-editing { outline: 1px solid rgba(91,79,255,0.95) !important; outline-offset: 3px !important;' +
					'box-shadow: 0 0 0 5px rgba(91,79,255,0.10); cursor: text !important; border-radius: 2px;' +
					'text-decoration: none !important; animation: none !important; caret-color: #5b4fff; }',
				'#pg-inline-hint { position: absolute; z-index: 2147483646; background: rgba(17,17,26,0.92); color: #fff;' +
					'font: 500 10px/1 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;' +
					'padding: 5px 8px; border-radius: 5px; pointer-events: none; display: none;' +
					'letter-spacing: 0.04em; white-space: nowrap; box-shadow: 0 2px 8px rgba(0,0,0,0.3); }',
				// Drag-to-reorder chrome: grip handle (top-right of the hovered
				// section), the translucent ghost pill that follows the pointer,
				// the insert line between sections, and the lifted source state.
				'#pg-drag-handle { position: fixed; z-index: 2147483645; width: 28px; height: 28px; display: none;' +
					'align-items: center; justify-content: center; background: #fff; color: #5b4fff;' +
					'border: 1px solid #d9d6ff; border-radius: 7px; cursor: grab;' +
					'box-shadow: 0 2px 8px rgba(20,16,60,0.18); touch-action: none; user-select: none; }',
				'#pg-drag-handle:hover { background: #5b4fff; color: #fff; }',
				'#pg-drag-handle.is-dragging { cursor: grabbing; }',
				'#pg-drag-ghost { position: fixed; z-index: 2147483646; pointer-events: none; display: none;' +
					'background: rgba(91,79,255,0.92); color: #fff; border-radius: 999px; padding: 6px 14px;' +
					'font: 600 12px/1.2 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;' +
					'box-shadow: 0 8px 24px rgba(91,79,255,0.45); white-space: nowrap; }',
				'#pg-drop-line { position: fixed; z-index: 2147483645; pointer-events: none; display: none;' +
					'left: 0; right: 0; height: 0; border-top: 3px solid #5b4fff; box-shadow: 0 0 0 1px rgba(255,255,255,0.7), 0 2px 10px rgba(91,79,255,0.5); }',
				'#pg-drop-line::before { content: ""; position: absolute; left: 10px; top: -7px; width: 11px; height: 11px;' +
					'border-radius: 50%; background: #5b4fff; box-shadow: 0 0 0 2px #fff; }',
				'.pg-drag-source { opacity: 0.35 !important; outline: 2px dashed rgba(91,79,255,0.6) !important; outline-offset: -2px; }',
				'body.pg-dragging, body.pg-dragging * { cursor: grabbing !important; user-select: none !important; }'
			].join('\n');
			(doc.head || doc.body).appendChild(style);

			var chip = doc.createElement('div');
			chip.id = 'pg-select-chip';
			doc.body.appendChild(chip);

			// Drag-to-reorder grip: one handle per document, repositioned onto
			// whichever section is hovered. Pointer events drive the drag.
			var handle = doc.createElement('div');
			handle.id = 'pg-drag-handle';
			handle.title = 'Drag to move this section up or down the page';
			handle.innerHTML = '<svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor"><circle cx="9" cy="5" r="1.7"/><circle cx="15" cy="5" r="1.7"/><circle cx="9" cy="12" r="1.7"/><circle cx="15" cy="12" r="1.7"/><circle cx="9" cy="19" r="1.7"/><circle cx="15" cy="19" r="1.7"/></svg>';
			doc.body.appendChild(handle);
			handle.addEventListener('pointerdown', function (e) { startSectionDrag(e, handle); });

			var hovered = null;
			function positionHandle(sec) {
				if (!sec || dragState) { if (!dragState) handle.style.display = 'none'; return; }
				var r = sec.getBoundingClientRect();
				var cw = doc.documentElement.clientWidth;
				// The property panel overlays the preview's right edge — shift
				// the handle left of the covered strip so it stays clickable.
				var cover = 0;
				try {
					if (panelEl && panelEl.classList.contains('is-open')) {
						var pr = panelEl.getBoundingClientRect();
						var fr = frame.getBoundingClientRect();
						cover = Math.max(0, Math.min(fr.right, pr.right) - Math.max(fr.left, pr.left));
					}
				} catch (e) {}
				handle.__pgSec = sec;
				handle.style.left = (Math.min(cw - cover, Math.min(cw, r.right)) - 36) + 'px';
				handle.style.top = (Math.max(0, r.top) + 8) + 'px';
				handle.style.display = 'flex';
			}
			function onOver(e) {
				if (!selectMode) return;
				if (dragState) return; // mid-drag: ghost + line own the pointer
				if (e.target === handle || (handle.contains && handle.contains(e.target))) return;
				var sec = e.target && e.target.closest ? e.target.closest('.pg-sec') : null;
				if (sec === hovered) return;
				if (hovered) hovered.classList.remove('pg-ed-hover');
				hovered = sec;
				if (sec) {
					sec.classList.add('pg-ed-hover');
					var r = sec.getBoundingClientRect();
					var k = keyOfSection(sec);
					// Discoverability: the chip on the SELECTED section teaches
					// the inline-edit gesture.
					chip.textContent = sectionLabel(k) +
						(k && k === selectedSectionKey ? ' · double-click text to edit' : '');
					chip.style.left = Math.max(0, r.left) + 'px';
					chip.style.top = Math.max(0, r.top) + 'px';
					chip.style.display = 'block';
					positionHandle(sec);
				} else {
					chip.style.display = 'none';
					handle.style.display = 'none';
					handle.__pgSec = null;
				}
			}
			function onClick(e) {
				if (!selectMode) return;
				// A drag just ended — the pointerup synthesizes a click; eat it.
				if (Date.now() < dragClickGuard) { e.preventDefault(); e.stopPropagation(); return; }
				if (e.target === handle || (handle.contains && handle.contains(e.target))) {
					e.preventDefault(); e.stopPropagation(); return;
				}
				// Mid inline edit: clicks INSIDE the editable element must reach
				// the browser (caret placement) — only links stay inert.
				if (inline && inline.el && (inline.el === e.target || inline.el.contains(e.target))) {
					if (e.target.closest && e.target.closest('a')) e.preventDefault();
					return;
				}
				// Select mode owns clicks: never follow links / fire toggles.
				e.preventDefault();
				e.stopPropagation();
				var sec = e.target && e.target.closest ? e.target.closest('.pg-sec') : null;
				if (!sec) { clearSelection(); return; }
				var key = keyOfSection(sec);
				if (!key) { clearSelection(); return; }
				if (key === selectedSectionKey) {
					// A drag-selection of text just finished — that's the AI
					// micro-toolbar's moment, not click-to-type's.
					try {
						var sl = doc.defaultView.getSelection();
						if (sl && !sl.isCollapsed && String(sl).trim()) { scheduleTbCheck(); return; }
					} catch (err) {}
					// Click on an image → image hot-swap picker.
					var img = e.target.tagName === 'IMG' ? e.target
						: (e.target.closest ? e.target.closest('img') : null);
					if (img && openPickerForImage(img, key)) return;
					// Second click inside the selected section: click-to-type.
					// Exact-text match → edit in place; no/ambiguous match →
					// the existing focus-the-panel-field heuristic.
					if (!attemptInlineEdit(e.target, e.clientX, e.clientY)) {
						// Background-image sections: clicking non-text area of a
						// section whose only image lives as a background opens
						// the picker for that field.
						var bgEntry = bgImageEntry(e.target, sec, key);
						if (bgEntry) { openPickerForEntry(bgEntry, null); return; }
						focusFieldForElement(e.target);
					}
					return;
				}
				selectSection(key);
			}
			function onDblClick(e) {
				if (!selectMode) return;
				if (inline && inline.el && (inline.el === e.target || inline.el.contains(e.target))) return;
				e.preventDefault();
				e.stopPropagation();
				var sec = e.target && e.target.closest ? e.target.closest('.pg-sec') : null;
				if (!sec) return;
				var key = keyOfSection(sec);
				if (!key) return;
				if (key !== selectedSectionKey) selectSection(key);
				if (!attemptInlineEdit(e.target, e.clientX, e.clientY)) {
					focusFieldForElement(e.target);
				}
			}
			// Text-selection watcher for the AI micro-toolbar (✨ Punchier …).
			function onSelMouseUp() { scheduleTbCheck(); }
			function onSelChange() { scheduleTbCheck(); }
			function onDocScroll() {
				hideTextToolbar();
				if (hovered) { positionHandle(hovered); var r = hovered.getBoundingClientRect(); chip.style.left = Math.max(0, r.left) + 'px'; chip.style.top = Math.max(0, r.top) + 'px'; }
			}
			// Viewport switch (desktop/tablet/mobile) resizes the iframe — every
			// fixed-position overlay inside it (grip handle, label chip, inline
			// hint) keeps stale coordinates until repositioned. The iframe's own
			// resize event fires throughout the width transition, so this also
			// covers parent-window resizes for free.
			function onWinResize() {
				hideTextToolbar();
				if (dragState) return; // mid-drag: the rAF loop owns positioning
				if (hovered && hovered.isConnected) {
					positionHandle(hovered);
					var r = hovered.getBoundingClientRect();
					chip.style.left = Math.max(0, r.left) + 'px';
					chip.style.top = Math.max(0, r.top) + 'px';
				} else {
					handle.style.display = 'none';
					chip.style.display = 'none';
				}
				if (inline) showInlineHint(inline); // re-anchor the floating hint
			}
			doc.addEventListener('mouseover', onOver, true);
			doc.addEventListener('click', onClick, true);
			doc.addEventListener('dblclick', onDblClick, true);
			doc.addEventListener('mouseup', onSelMouseUp, true);
			doc.addEventListener('selectionchange', onSelChange);
			doc.defaultView && doc.defaultView.addEventListener('scroll', onDocScroll, { passive: true });
			doc.defaultView && doc.defaultView.addEventListener('resize', onWinResize);
			docState = { doc: doc, style: style, chip: chip, handle: handle, onOver: onOver, onClick: onClick, onDblClick: onDblClick, onSelMouseUp: onSelMouseUp, onSelChange: onSelChange, onDocScroll: onDocScroll, onWinResize: onWinResize };
			markSelected();

			if (!doc.querySelector('.pg-sec') && !noSecToastShown) {
				noSecToastShown = true;
				showToast('No selectable sections found — ask the AI for any small change to refresh the page markers.', true);
			}
		}

		function disarmDoc() {
			if (!docState) return;
			cancelSectionDrag();
			hideTextToolbar();
			try {
				docState.doc.removeEventListener('mouseover', docState.onOver, true);
				docState.doc.removeEventListener('click', docState.onClick, true);
				if (docState.onDblClick) docState.doc.removeEventListener('dblclick', docState.onDblClick, true);
				if (docState.onSelMouseUp) docState.doc.removeEventListener('mouseup', docState.onSelMouseUp, true);
				if (docState.onSelChange) docState.doc.removeEventListener('selectionchange', docState.onSelChange);
				if (docState.onDocScroll && docState.doc.defaultView) docState.doc.defaultView.removeEventListener('scroll', docState.onDocScroll);
				if (docState.onWinResize && docState.doc.defaultView) docState.doc.defaultView.removeEventListener('resize', docState.onWinResize);
				if (docState.handle) docState.handle.remove();
				var marked2 = docState.doc.querySelectorAll('.pg-ed-text');
				for (var j = 0; j < marked2.length; j++) marked2[j].classList.remove('pg-ed-text');
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
				markEditableText();
			} catch (e) {}
			renderSecChip();
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
			if (inline && inline.key !== key) commitInline(); // leaving an edit = save it
			if (!confirmDiscard()) return;
			if (selectedSectionKey !== key) { closeImagePicker(); hideTextToolbar(); }
			selectedSectionKey = key;
			markSelected();
			renderChatChip();
			openPanel();
			renderPanel(false);
		}

		function clearSelection(keepQuiet) {
			if (inline) commitInline();
			if (!keepQuiet && !confirmDiscard()) return;
			closeImagePicker();
			hideTextToolbar();
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
					if (spec && spec.bare && typeof cur === 'string') {
						// Bare-string section (disclaimer): promote to the {text}
						// object shape the panel + renderers both understand.
						working = {};
						working[spec.bare] = cur;
					} else {
						working = (cur && typeof cur === 'object') ? deepClone(cur) : {};
					}
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

			// Explanatory note (e.g. blog: posts are pulled automatically) —
			// rendered above the fields so a sparse panel reads intentional.
			if (spec && spec.note) {
				var noteEl = document.createElement('div');
				noteEl.className = 'pg-ed-note';
				noteEl.textContent = spec.note;
				body.appendChild(noteEl);
			}

			if (!spec || !Object.keys(spec.fields || {}).length) {
				if (!spec || !spec.note) {
					var empty = document.createElement('div');
					empty.className = 'pg-ed-empty';
					empty.textContent = pageMode
						? 'No page-level settings available.'
						: 'No quick-edit fields for this section type yet — describe the change in chat and the AI will handle it.';
					body.appendChild(empty);
				}
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
			sel.className = 'pg-ed-variant-sel';
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
				renderSecChip(); // keep the floating carousel chip in step
			});
			return fieldRow('Layout', sel, 'How this section is arranged. Tip: ←/→ arrow keys flip through layouts.');
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
					// Thumb (or the swap button) opens the image hot-swap picker.
					thumb.title = 'Click to choose a different image';
					thumb.classList.add('pg-ed-image-swappable');
					function openPick() {
						openImagePicker({
							current: ii.value,
							onPick: function (url) {
								ii.value = url;
								working[fkey] = url;
								markDirty(fkey);
								paintThumb();
								fastFlush();
							}
						});
					}
					thumb.addEventListener('click', openPick);
					var swapBtn = document.createElement('button');
					swapBtn.type = 'button';
					swapBtn.className = 'pg-ed-image-swap';
					swapBtn.textContent = 'Swap';
					swapBtn.title = 'Pick from your media library or this page’s images';
					swapBtn.addEventListener('click', openPick);
					wrap.appendChild(thumb);
					wrap.appendChild(ii);
					wrap.appendChild(swapBtn);
					return fieldRow(f.label, wrap, f.hint);
				}
				case 'color': {
					// No stored value → show the validator's default (what the
					// generator actually renders with), never a gray “–” swatch.
					// First edit writes the value into the config explicitly.
					var hex = toHex6(value) || toHex6(f.default || '');
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
						var srow = document.createElement('div');
						srow.className = 'pg-ed-slider-row';
						var rg = document.createElement('input');
						rg.type = 'range';
						rg.min = f.min;
						rg.max = f.max;
						if (f.step != null) rg.step = f.step;
						rg.setAttribute('aria-label', f.label);
						var initial = (value == null || isNaN(parseFloat(value)))
							? (SLIDER_DEFAULTS[fkey] != null ? SLIDER_DEFAULTS[fkey] : (Number(f.min) + Number(f.max)) / 2)
							: parseFloat(value);
						rg.value = initial;
						var out = document.createElement('span');
						out.className = 'pg-ed-slider-val';
						out.textContent = String(initial);
						// 'input' fires continuously while dragging AND on every
						// keyboard arrow nudge — readout + live CSS track both.
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
						srow.appendChild(rg);
						srow.appendChild(out);
						sbox.appendChild(srow);
						// Visible min/max range under the track.
						var scale = document.createElement('div');
						scale.className = 'pg-ed-slider-scale';
						var smin = document.createElement('span');
						smin.textContent = String(f.min);
						var smax = document.createElement('span');
						smax.textContent = String(f.max);
						scale.appendChild(smin);
						scale.appendChild(smax);
						sbox.appendChild(scale);
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

		// ════════════════════════════════════════════════════════════════
		// Inline text editing — click text on the page and just type.
		//
		// A value→path INDEX is built from cfg.pageConfig, restricted to
		// section types + fields present in cfg.editorFields (the safety
		// whitelist — same fields the panel exposes, incl. repeater
		// sub-fields and list items). A second click (or double-click) on a
		// text element inside the selected section matches its normalized
		// textContent against the index: exact match required, section keys
		// scoped first, whole page as fallback; ambiguous or no match falls
		// back to the panel-field heuristic above. On match the element goes
		// contentEditable in place — typing IS the optimistic preview. Blur
		// or Enter commits the text into `working` at the matched path and
		// rides the EXISTING markDirty → auto-apply → buffered-swap save
		// path (no second save pipeline). Esc reverts. If a swap lands
		// mid-edit, the frameReady hook below carries the editing state into
		// the fresh document.
		// ════════════════════════════════════════════════════════════════

		// 'plaintext-only' keeps paste/Enter sane; fall back where unsupported.
		var ceMode = (function () {
			try {
				var d = document.createElement('div');
				d.contentEditable = 'plaintext-only';
				return d.contentEditable === 'plaintext-only' ? 'plaintext-only' : 'true';
			} catch (e) { return 'true'; }
		})();

		function getAtPath(obj, pathArr) {
			var o = obj;
			for (var i = 0; i < pathArr.length; i++) {
				if (o == null || typeof o !== 'object') return undefined;
				o = o[pathArr[i]];
			}
			return o;
		}
		function setAtPath(obj, pathArr, val) {
			var o = obj;
			for (var i = 0; i < pathArr.length - 1; i++) {
				var k = pathArr[i];
				if (o[k] == null || typeof o[k] !== 'object') {
					o[k] = (typeof pathArr[i + 1] === 'number') ? [] : {};
				}
				o = o[k];
			}
			o[pathArr[pathArr.length - 1]] = val;
		}
		function readText(el) {
			var t;
			try { t = el.innerText; } catch (e) { t = el.textContent; }
			return String(t == null ? '' : t).replace(/\u00a0/g, ' ');
		}
		function setElText(el, text) {
			try { el.innerText = text; }
			catch (e) { el.textContent = text; }
		}

		// ── Value→path index ──────────────────────────────────────────
		// Entry: { key, path:[fkey,(i),(subkey)], norm, single, dirtyKey }.
		// `single` = Enter commits (headline/eyebrow/cta text…); textarea-ish
		// fields allow Shift+Enter newlines. Rebuilt on demand (the config is
		// small), with `working` overlaying the selected section so freshly
		// committed-but-unsaved text still matches.
		function pushEntry(idx, key, path, val, single, dirtyKey) {
			if (typeof val !== 'string') return;
			var n = normText(val);
			if (!n || n.length > 400) return;
			idx.push({ key: key, path: path, norm: n, single: !!single, dirtyKey: dirtyKey });
		}
		function pushItemEntries(idx, key, fkey, i, item, sub) {
			Object.keys(item).forEach(function (sk) {
				if (typeof item[sk] !== 'string') return;
				// Whitelist: declared repeater sub-fields use the schema kind;
				// inferred (list-of-objects) mirror the panel's guessKind.
				var kind = sub ? (sub[sk] && sub[sk].kind) : guessKind(sk);
				if (kind !== 'text' && kind !== 'textarea') return;
				pushEntry(idx, key, [fkey, i, sk], item[sk], kind === 'text', fkey);
			});
		}
		function buildValueIndex() {
			var idx = [];
			var pc = cfg.pageConfig || {};
			Object.keys(pc).forEach(function (key) {
				var spec = edFields[baseType(key)];
				if (!spec || !spec.fields) return; // not a section (colors/fonts/…)
				var sec = (!pageMode && key === selectedSectionKey && working) ? working : pc[key];
				if (spec.bare && typeof sec === 'string') {
					// Bare-string section (disclaimer) — index it under the same
					// {text} shape the panel edits, so click-to-type works on it.
					var bareObj = {};
					bareObj[spec.bare] = sec;
					sec = bareObj;
				}
				if (!sec || typeof sec !== 'object') return;
				Object.keys(spec.fields).forEach(function (fkey) {
					var f = spec.fields[fkey] || {};
					var v = sec[fkey];
					if (v == null) return;
					switch (f.kind) {
						case 'text':
							pushEntry(idx, key, [fkey], v, true, fkey);
							break;
						case 'textarea':
							pushEntry(idx, key, [fkey], v, false, fkey);
							break;
						case 'cta':
							if (typeof v === 'string') pushEntry(idx, key, [fkey], v, true, fkey);
							else if (typeof v === 'object') pushEntry(idx, key, [fkey, 'text'], v.text, true, fkey + '.text');
							break;
						case 'list':
							if (!Array.isArray(v)) break;
							for (var i = 0; i < v.length; i++) {
								if (typeof v[i] === 'string') pushEntry(idx, key, [fkey, i], v[i], true, fkey);
								else if (v[i] && typeof v[i] === 'object') pushItemEntries(idx, key, fkey, i, v[i], null);
							}
							break;
						case 'repeater':
							if (!Array.isArray(v)) break;
							var sub = (f.fields && Object.keys(f.fields).length) ? f.fields : null;
							for (var j = 0; j < v.length; j++) {
								if (v[j] && typeof v[j] === 'object') pushItemEntries(idx, key, fkey, j, v[j], sub);
							}
							break;
					}
				});
			});
			return idx;
		}

		// ── Matcher ───────────────────────────────────────────────────
		// Walk target → section root; first element whose normalized text
		// equals exactly ONE index value (section scope first, then page)
		// becomes editable. Ambiguity at any level = give up (panel opens).
		function attemptInlineEdit(target, x, y) {
			if (!target || !target.closest) return false;
			var sec = target.closest('.pg-sec');
			if (!sec) return false;
			var key = keyOfSection(sec);
			if (!key) return false;
			var idx = buildValueIndex();
			var el = target.nodeType === 1 ? target : target.parentElement;
			while (el && el !== sec) {
				var tc = el.textContent || '';
				if (tc.trim() && tc.length <= 600) {
					var n = normText(tc);
					var inSec = [], anywhere = [];
					for (var i = 0; i < idx.length; i++) {
						if (idx[i].norm !== n) continue;
						anywhere.push(idx[i]);
						if (idx[i].key === key) inSec.push(idx[i]);
					}
					var entry = inSec.length === 1 ? inSec[0]
						: (inSec.length === 0 && anywhere.length === 1 ? anywhere[0] : null);
					if (entry) return startInline(el, entry, x, y);
					if (anywhere.length > 1) return false; // ambiguous → panel
				}
				el = el.parentElement;
			}
			return false;
		}

		// ── Editing state machine ─────────────────────────────────────
		function startInline(el, entry, x, y) {
			if (inline && inline.el === el) return true; // already editing it
			if (inline) commitInline();
			if (entry.key !== selectedSectionKey) selectSection(entry.key); // sets `working`
			var st = {
				el: el,
				key: entry.key,
				path: entry.path,
				dirtyKey: entry.dirtyKey,
				single: entry.single,
				original: readText(el),
				originalHTML: el.innerHTML
			};
			st.onKey = function (e) {
				if (e.key === 'Escape') {
					e.preventDefault();
					e.stopPropagation();
					cancelInline();
				} else if (e.key === 'Enter') {
					if (!st.single && e.shiftKey) return; // newline in textarea-kind
					e.preventDefault();
					e.stopPropagation();
					commitInline();
				}
			};
			st.onBlur = function () { commitInline(); };
			st.onInput = function () { syncPanelControl(st.path.join('.'), readText(st.el)); };
			inline = st;
			dressInline(st);
			try { st.el.focus(); } catch (e) {}
			if (x != null) placeCaretAtPoint(st.el, x, y);
			else placeCaretAtEnd(st.el);
			showInlineHint(st);
			return true;
		}

		function dressInline(st) {
			try {
				st.el.setAttribute('contenteditable', ceMode);
				st.el.setAttribute('spellcheck', 'true');
				st.el.classList.add('pg-inline-editing');
				st.el.addEventListener('keydown', st.onKey, true);
				st.el.addEventListener('blur', st.onBlur);
				st.el.addEventListener('input', st.onInput);
			} catch (e) {}
		}
		function undressInline(st) {
			try {
				st.el.removeEventListener('keydown', st.onKey, true);
				st.el.removeEventListener('blur', st.onBlur);
				st.el.removeEventListener('input', st.onInput);
				st.el.removeAttribute('contenteditable');
				st.el.removeAttribute('spellcheck');
				st.el.classList.remove('pg-inline-editing');
			} catch (e) {}
			hideInlineHint(st);
		}

		// Commit = Word-doc save: the DOM already shows the text; write it
		// into `working` at the matched path and let the EXISTING autosave
		// machinery (markDirty → flush → apply → buffered swap) persist it.
		function commitInline() {
			if (!inline) return;
			var st = inline;
			inline = null;
			undressInline(st);
			var raw = readText(st.el);
			var text = st.single ? raw.replace(/\s*\n+\s*/g, ' ').trim() : raw.replace(/^\n+|\n+$/g, '');
			var origCmp = st.single ? st.original.replace(/\s*\n+\s*/g, ' ').trim() : st.original.replace(/^\n+|\n+$/g, '');
			if (text === origCmp) {
				// Unchanged — restore the exact original markup (nested spans,
				// highlights) the browser may have flattened while editing.
				try { st.el.innerHTML = st.originalHTML; } catch (e) {}
				return;
			}
			if (!normText(text)) {
				try { st.el.innerHTML = st.originalHTML; } catch (e) {}
				showToast('Kept the original text — clear a field from the panel instead.');
				return;
			}
			if (st.single && raw !== text) setElText(st.el, text); // show what we save
			if (pageMode || selectedSectionKey !== st.key) selectSection(st.key);
			if (!working || typeof working !== 'object') return; // section vanished
			setAtPath(working, st.path, text);
			syncPanelControl(st.path.join('.'), text);
			markDirty(st.dirtyKey);
			markEditableText(); // re-anchor affordance marks to the new text
		}

		// Esc: put the element back exactly as it was, save nothing.
		function cancelInline() {
			if (!inline) return;
			var st = inline;
			inline = null;
			undressInline(st);
			try { st.el.innerHTML = st.originalHTML; } catch (e) { setElText(st.el, st.original); }
			syncPanelControl(st.path.join('.'), st.original);
		}

		// ── Panel sync (no focus steal) ───────────────────────────────
		// Inline edits mirror into the open panel's matching control. The
		// registry paths use the same dotted shape as entry paths.
		function syncPanelControl(pathStr, text) {
			if (!panelEl || !panelEl.classList.contains('is-open')) return;
			for (var i = 0; i < controls.length; i++) {
				if (controls[i].path === pathStr) {
					var c = controls[i].el;
					if (document.activeElement !== c) c.value = text;
					return;
				}
			}
		}

		// ── Caret helpers ─────────────────────────────────────────────
		function placeCaretAtPoint(el, x, y) {
			try {
				var doc = el.ownerDocument;
				var win = doc.defaultView;
				var range = null;
				if (doc.caretRangeFromPoint) {
					range = doc.caretRangeFromPoint(x, y);
				} else if (doc.caretPositionFromPoint) {
					var p = doc.caretPositionFromPoint(x, y);
					if (p) {
						range = doc.createRange();
						range.setStart(p.offsetNode, p.offset);
					}
				}
				if (!range || !el.contains(range.startContainer)) { placeCaretAtEnd(el); return; }
				range.collapse(true);
				var sel = win.getSelection();
				sel.removeAllRanges();
				sel.addRange(range);
			} catch (e) { placeCaretAtEnd(el); }
		}
		function placeCaretAtEnd(el) {
			try {
				var doc = el.ownerDocument;
				var range = doc.createRange();
				range.selectNodeContents(el);
				range.collapse(false);
				var sel = doc.defaultView.getSelection();
				sel.removeAllRanges();
				sel.addRange(range);
			} catch (e) {}
		}

		// ── Floating hint ("esc to cancel") ───────────────────────────
		function showInlineHint(st) {
			try {
				var doc = st.el.ownerDocument;
				var h = doc.getElementById('pg-inline-hint');
				if (!h) {
					h = doc.createElement('div');
					h.id = 'pg-inline-hint';
					doc.body.appendChild(h);
				}
				h.textContent = st.single
					? 'enter to save · esc to cancel'
					: 'enter to save · shift+enter = new line · esc to cancel';
				var r = st.el.getBoundingClientRect();
				var win = doc.defaultView;
				var top = r.top + (win.pageYOffset || 0) - 28;
				if (r.top < 34) top = r.bottom + (win.pageYOffset || 0) + 8;
				h.style.top = top + 'px';
				h.style.left = Math.max(4, r.left + (win.pageXOffset || 0)) + 'px';
				h.style.display = 'block';
			} catch (e) {}
		}
		function hideInlineHint(st) {
			var docs = [];
			try { if (st && st.el && st.el.ownerDocument) docs.push(st.el.ownerDocument); } catch (e) {}
			try { if (frame.contentDocument) docs.push(frame.contentDocument); } catch (e) {}
			for (var i = 0; i < docs.length; i++) {
				try {
					var h = docs[i].getElementById('pg-inline-hint');
					if (h) h.style.display = 'none';
				} catch (e) {}
			}
		}

		// ── Affordance marks ──────────────────────────────────────────
		// Tag the deepest element rendering each editable value of the
		// SELECTED section with .pg-ed-text → I-beam cursor + underline
		// shimmer on hover, so click-to-type is discoverable.
		function markEditableText() {
			if (!docState) return;
			try {
				var doc = docState.doc;
				var old = doc.querySelectorAll('.pg-ed-text');
				for (var i = 0; i < old.length; i++) old[i].classList.remove('pg-ed-text');
				if (!selectedSectionKey) return;
				var root = doc.querySelector('.pg-key--' + encodeKey(selectedSectionKey));
				if (!root) return;
				var norms = {};
				var idx = buildValueIndex();
				for (var j = 0; j < idx.length; j++) {
					if (idx[j].key === selectedSectionKey) norms[idx[j].norm] = 1;
				}
				var els = root.querySelectorAll('*');
				var matches = [];
				for (var k = 0; k < els.length; k++) {
					var tc = els[k].textContent || '';
					if (!tc.trim() || tc.length > 600) continue;
					if (norms[normText(tc)]) matches.push(els[k]);
				}
				for (var m = 0; m < matches.length; m++) {
					var deepest = true;
					for (var o = 0; o < matches.length; o++) {
						if (o !== m && matches[m].contains(matches[o])) { deepest = false; break; }
					}
					if (deepest) matches[m].classList.add('pg-ed-text');
				}
			} catch (e) {}
		}

		// ── Mid-edit swap carry-over ──────────────────────────────────
		// A buffered swap mustn't eat a focused inline edit (e.g. an earlier
		// apply on ANOTHER section lands while the user is typing). Re-find
		// the element in the fresh document by section key + the value the
		// server rendered at the path, replay the in-progress text, restore
		// caret to end. Runs after the re-arm hook above (registration order).
		function findDeepestByNorm(root, norm) {
			if (!norm) return null;
			var els = root.querySelectorAll('*');
			var matches = [];
			for (var i = 0; i < els.length; i++) {
				var tc = els[i].textContent || '';
				if (!tc.trim() || tc.length > 600) continue;
				if (normText(tc) === norm) matches.push(els[i]);
			}
			for (var m = 0; m < matches.length; m++) {
				var deepest = true;
				for (var o = 0; o < matches.length; o++) {
					if (o !== m && matches[m].contains(matches[o])) { deepest = false; break; }
				}
				if (deepest) return matches[m];
			}
			return null;
		}
		onFrameReady(function () {
			if (!inline) return;
			var st = inline;
			var txt = readText(st.el); // old doc still parked in the spare frame
			undressInline(st);
			var target = null;
			try {
				var doc = frame.contentDocument;
				var root = doc && doc.querySelector('.pg-key--' + encodeKey(st.key));
				if (root) {
					var savedVal = getAtPath((cfg.pageConfig || {})[st.key], st.path);
					target = findDeepestByNorm(root, normText(savedVal)) ||
						findDeepestByNorm(root, normText(st.original)) ||
						findDeepestByNorm(root, normText(txt));
				}
			} catch (e) {}
			if (!target) {
				// Can't carry the caret — but never lose the typing: commit
				// the in-progress text through the normal save path.
				inline = null;
				var text = st.single ? txt.replace(/\s*\n+\s*/g, ' ').trim() : txt.replace(/^\n+|\n+$/g, '');
				var origCmp = st.single ? st.original.replace(/\s*\n+\s*/g, ' ').trim() : st.original;
				if (normText(text) && text !== origCmp && !pageMode && selectedSectionKey === st.key && working) {
					setAtPath(working, st.path, text);
					syncPanelControl(st.path.join('.'), text);
					markDirty(st.dirtyKey);
				}
				return;
			}
			if (readText(target) !== txt) setElText(target, txt);
			st.el = target;
			inline = st;
			dressInline(st);
			try { st.el.focus(); } catch (e) {}
			placeCaretAtEnd(st.el);
			showInlineHint(st);
			window.__pgPerf.inlineCarries = (window.__pgPerf.inlineCarries || 0) + 1;
		});

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
						resetUndoChain(); // a fresh edit invalidates the redo branch
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
						// Chip/drag mutations can run with the panel closed —
						// surface the failure somewhere visible.
						if (!panelEl || !panelEl.classList.contains('is-open')) showToast(msg, true);
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

		// ════════════════════════════════════════════════════════════════
		// Phase 3 — the wow features.
		//
		// 1. Variant carousel: ←/→ (and ‹ › on the floating section chip)
		//    flip through cfg.editorFields[base].variants — deterministic,
		//    zero AI, rides the normal patch pipeline with a snappy debounce.
		// 2. Drag-to-reorder: a grip on the hovered section lifts a ghost;
		//    drop between sections patches the full `sections` array.
		//    ▲/▼ on the chip do the same one step at a time.
		// 3. ⌘Z / ⌘⇧Z: undo/redo over the server-side snapshot history
		//    (pressgo_ai_restore snapshots current state first — that
		//    snapshot becomes the redo target; ids are walked client-side).
		// 4. Image hot-swap: click any image in the selected section (or a
		//    panel thumb) → mini-picker of media-library + on-page images.
		// 5. AI text micro-toolbar: select text in the selected section →
		//    ✨ Punchier · ✂ Shorter · ✓ Fix, sent as a scoped chat message.
		//
		// Every mutation goes through the ONE existing save pipeline
		// (markDirty → flushApply → sendPatch, or applyDirect → sendPatch
		// for whole-page patches like reorders).
		// ════════════════════════════════════════════════════════════════

		// Snappier debounce for direct-manipulation controls (carousel,
		// image pick) — panel typing keeps the relaxed 1.2s.
		function fastFlush(ms) {
			clearTimeout(autoTimer);
			autoTimer = setTimeout(function () { flushApply(); }, ms || 300);
		}

		// Whole-page patches (e.g. {sections:[...]}) that don't belong to the
		// panel's working copy. Same in-flight/queue semantics as flushApply;
		// disjoint keys merge into anything already queued so nothing is lost.
		function applyDirect(patch, label) {
			if (inline) commitInline();
			if (isDirty()) flushApply(); // panel edits snapshot synchronously
			var built = { patch: patch, label: label || 'editor action' };
			if (queuedPatch) {
				var mergedP = {};
				Object.keys(queuedPatch.patch).forEach(function (k) { mergedP[k] = queuedPatch.patch[k]; });
				Object.keys(built.patch).forEach(function (k) { mergedP[k] = built.patch[k]; });
				queuedPatch = { patch: mergedP, label: built.label };
				return;
			}
			if (applyBusy) { queuedPatch = built; return; }
			if (retryPatch) {
				queuedPatch = built;
				var rp = retryPatch;
				retryPatch = null;
				sendPatch(rp);
				return;
			}
			setSaveState('saving');
			sendPatch(built);
		}

		// Nova pages have no recipe pageConfig — persist a reorder by sending the new
		// pg-key order to the freeform endpoint (which rewrites _elementor_data + the
		// section records together). Recipe pages keep the config-patch path.
		function novaReorder(order) {
			try { setSaveState('saving'); } catch (e) {}
			var fd = new FormData();
			fd.append('action', 'pressgo_ai_freeform');
			fd.append('nonce', cfg.nonce);
			fd.append('post_id', cfg.postId);
			fd.append('reorder_keys', JSON.stringify(order));
			fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
				.then(function (r) { return r.json(); })
				.then(function (j) {
					try { setSaveState('saved'); } catch (e) {}
					reloadPreview((j && j.data && j.data.preview_bust) || Date.now());
				})
				.catch(function () { try { setSaveState('error'); } catch (e) {} });
		}
		function persistReorder(order, label) {
			if (pgMode === 'freeform') { novaReorder(order); }
			else { applyDirect({ sections: order }, label); }
		}

		// ── 1+2. Floating section chip: ‹ layout › + ▲ ▼ move ─────────
		var secChip = null, secChipUI = null, secChipFlashT = null;

		function variantList(key) {
			var spec = edFields[baseType(key)];
			var vars = (spec && spec.variants && spec.variants.length) ? spec.variants.slice() : ['default'];
			return vars;
		}

		function renderSecChip() {
			if (!selectMode || !selectedSectionKey) {
				if (secChip) { secChip.remove(); secChip = null; secChipUI = null; }
				return;
			}
			if (!secChip) {
				secChip = document.createElement('div');
				secChip.className = 'pg-sec-chip';
				var prev = document.createElement('button');
				prev.type = 'button';
				prev.className = 'pg-sec-chip-btn pg-sec-chip-prev';
				prev.innerHTML = '&lsaquo;';
				prev.title = 'Previous layout (←)';
				var mid = document.createElement('div');
				mid.className = 'pg-sec-chip-mid';
				var lbl = document.createElement('strong');
				lbl.className = 'pg-sec-chip-label';
				var sub = document.createElement('span');
				sub.className = 'pg-sec-chip-variant';
				mid.appendChild(lbl);
				mid.appendChild(sub);
				var next = document.createElement('button');
				next.type = 'button';
				next.className = 'pg-sec-chip-btn pg-sec-chip-next';
				next.innerHTML = '&rsaquo;';
				next.title = 'Next layout (→)';
				var sep = document.createElement('span');
				sep.className = 'pg-sec-chip-sep';
				var up = document.createElement('button');
				up.type = 'button';
				up.className = 'pg-sec-chip-btn';
				up.innerHTML = '&uarr;';
				up.title = 'Move this section up';
				var down = document.createElement('button');
				down.type = 'button';
				down.className = 'pg-sec-chip-btn';
				down.innerHTML = '&darr;';
				down.title = 'Move this section down';
				secChip.appendChild(prev);
				secChip.appendChild(mid);
				secChip.appendChild(next);
				secChip.appendChild(sep);
				secChip.appendChild(up);
				secChip.appendChild(down);
				prev.addEventListener('click', function () { cycleVariant(-1); });
				next.addEventListener('click', function () { cycleVariant(1); });
				up.addEventListener('click', function () { moveSection(-1); });
				down.addEventListener('click', function () { moveSection(1); });
				previewWrap.appendChild(secChip);
				secChipUI = { lbl: lbl, sub: sub, prev: prev, next: next, up: up, down: down };
			}
			var vars = variantList(selectedSectionKey);
			var curSec = (!pageMode && working) ? working : ((cfg.pageConfig || {})[selectedSectionKey] || {});
			var cur = String(curSec.variant || 'default');
			var i = vars.indexOf(cur);
			secChipUI.lbl.textContent = sectionLabel(selectedSectionKey);
			if (vars.length > 1) {
				secChipUI.sub.textContent = 'Layout ' + (i === -1 ? '?' : (i + 1)) + '/' + vars.length + ' — ' + titleize(cur);
				secChipUI.prev.disabled = secChipUI.next.disabled = false;
			} else {
				secChipUI.sub.textContent = 'One layout';
				secChipUI.prev.disabled = secChipUI.next.disabled = true;
			}
			var order = sectionsOrder();
			var oi = order.indexOf(selectedSectionKey);
			secChipUI.up.disabled = oi <= 0;
			secChipUI.down.disabled = oi === -1 || oi >= order.length - 1;
		}

		function flashSecChip() {
			if (!secChip) return;
			secChip.classList.remove('is-flash');
			void secChip.offsetWidth;
			secChip.classList.add('is-flash');
			clearTimeout(secChipFlashT);
			secChipFlashT = setTimeout(function () { if (secChip) secChip.classList.remove('is-flash'); }, 600);
		}

		// ── 1. Variant carousel ────────────────────────────────────────
		function cycleVariant(dir) {
			if (!selectedSectionKey || pageMode || !working) return;
			var vars = variantList(selectedSectionKey);
			if (vars.length < 2) { showToast('This section type has only one layout.'); return; }
			var cur = String(working.variant || 'default');
			if (vars.indexOf(cur) === -1) vars.unshift(cur);
			var nextV = vars[(vars.indexOf(cur) + dir + vars.length) % vars.length];
			working.variant = nextV;
			// Mirror into the open panel's Layout select without a re-render.
			if (panelEl) {
				var s = panelEl.querySelector('.pg-ed-variant-sel');
				if (s) s.value = nextV;
			}
			markDirty('variant');
			fastFlush(280); // template-flipping must feel snappy
			renderSecChip();
			flashSecChip();
		}

		// ── 2. Reorder (chip buttons + drag) ───────────────────────────
		function sectionsOrder() {
			var pc = cfg.pageConfig || {};
			if (Array.isArray(pc.sections) && pc.sections.length) {
				return pc.sections.filter(function (k) { return typeof k === 'string'; });
			}
			// Fallback: read the DOM order of marked sections.
			try {
				return collectSections(frame.contentDocument).map(function (s) { return s.key; });
			} catch (e) { return []; }
		}

		function collectSections(doc) {
			var out = [], list = doc ? doc.querySelectorAll('.pg-sec') : [];
			for (var i = 0; i < list.length; i++) {
				var k = keyOfSection(list[i]);
				if (k) out.push({ key: k, el: list[i] });
			}
			return out;
		}

		// Optimistic DOM move so the drop lands instantly; the buffered swap
		// brings the authoritative render a beat later.
		function optimisticMove(key, order) {
			try {
				var doc = frame.contentDocument;
				var els = {};
				collectSections(doc).forEach(function (s) { els[s.key] = s.el; });
				var moved = els[key];
				if (!moved) return;
				var parent = moved.parentNode;
				var idx = order.indexOf(key);
				var ref = null;
				for (var n = idx + 1; n < order.length; n++) {
					if (els[order[n]] && els[order[n]].parentNode === parent) { ref = els[order[n]]; break; }
				}
				parent.insertBefore(moved, ref); // null ref = append at end
				try { moved.scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch (e2) {}
			} catch (e) {}
		}

		function moveSection(dir) {
			if (!selectedSectionKey) return;
			var order = sectionsOrder();
			var i = order.indexOf(selectedSectionKey);
			if (i === -1) return;
			var j = i + dir;
			if (j < 0 || j >= order.length) {
				showToast(dir < 0 ? 'Already at the top.' : 'Already at the bottom.');
				return;
			}
			order.splice(j, 0, order.splice(i, 1)[0]);
			if (cfg.pageConfig) cfg.pageConfig.sections = order.slice();
			optimisticMove(selectedSectionKey, order);
			renderSecChip();
			persistReorder(order, 'Move ' + sectionLabel(selectedSectionKey) + (dir < 0 ? ' up' : ' down'));
		}

		// Drag state. The grip handle lives in the iframe document (created in
		// armDoc); pointer capture keeps the stream alive across the drag.
		var dragState = null, dragClickGuard = 0;

		function startSectionDrag(e, handleEl) {
			if (!selectMode || dragState) return;
			var sec = handleEl.__pgSec;
			if (!sec || !sec.ownerDocument) return;
			var key = keyOfSection(sec);
			if (!key) return;
			e.preventDefault();
			e.stopPropagation();
			if (inline) commitInline();
			var doc = sec.ownerDocument, win = doc.defaultView;
			var ghost = doc.createElement('div');
			ghost.id = 'pg-drag-ghost';
			ghost.textContent = sectionLabel(key);
			doc.body.appendChild(ghost);
			var line = doc.createElement('div');
			line.id = 'pg-drop-line';
			doc.body.appendChild(line);
			sec.classList.add('pg-drag-source');
			doc.body.classList.add('pg-dragging');
			handleEl.classList.add('is-dragging');
			var st = {
				key: key, sec: sec, doc: doc, win: win, handle: handleEl,
				ghost: ghost, line: line, x: e.clientX, y: e.clientY,
				insertAt: -1, order: null, raf: 0, pointerId: e.pointerId
			};
			dragState = st;
			try { handleEl.setPointerCapture(e.pointerId); } catch (err) {}
			st.onMove = function (ev) { st.x = ev.clientX; st.y = ev.clientY; };
			st.onUp = function () { finishSectionDrag(true); };
			st.onCancel = function () { finishSectionDrag(false); };
			handleEl.addEventListener('pointermove', st.onMove);
			handleEl.addEventListener('pointerup', st.onUp);
			handleEl.addEventListener('pointercancel', st.onCancel);
			function tick() {
				if (dragState !== st) return;
				var vh = win.innerHeight;
				// Autoscroll near the edges, faster the deeper you push.
				if (st.y < 70) win.scrollBy(0, -(Math.ceil((70 - st.y) / 3) + 4));
				else if (st.y > vh - 70) win.scrollBy(0, Math.ceil((st.y - (vh - 70)) / 3) + 4);
				ghost.style.display = 'block';
				ghost.style.left = (st.x + 14) + 'px';
				ghost.style.top = (st.y + 12) + 'px';
				// Drop slot = gap whose boundary is nearest the pointer.
				var secs = collectSections(doc);
				var insertAt = secs.length;
				var lineY = null;
				for (var i = 0; i < secs.length; i++) {
					var r = secs[i].el.getBoundingClientRect();
					if (st.y < r.top + r.height / 2) { insertAt = i; lineY = r.top; break; }
					lineY = r.bottom;
				}
				st.insertAt = insertAt;
				st.order = secs.map(function (s) { return s.key; });
				if (lineY != null) {
					line.style.display = 'block';
					line.style.top = Math.max(0, Math.min(vh - 3, lineY)) + 'px';
				}
				st.raf = win.requestAnimationFrame(tick);
			}
			tick();
		}

		function finishSectionDrag(commit) {
			var st = dragState;
			if (!st) return;
			dragState = null;
			try { st.win.cancelAnimationFrame(st.raf); } catch (e) {}
			try { st.handle.releasePointerCapture(st.pointerId); } catch (e) {}
			st.handle.removeEventListener('pointermove', st.onMove);
			st.handle.removeEventListener('pointerup', st.onUp);
			st.handle.removeEventListener('pointercancel', st.onCancel);
			st.handle.classList.remove('is-dragging');
			st.handle.style.display = 'none';
			try { st.ghost.remove(); st.line.remove(); } catch (e) {}
			try {
				st.sec.classList.remove('pg-drag-source');
				st.doc.body.classList.remove('pg-dragging');
			} catch (e) {}
			dragClickGuard = Date.now() + 350; // eat the synthesized click
			if (!commit || st.insertAt < 0 || !st.order) return;
			var domOrder = st.order;
			var from = domOrder.indexOf(st.key);
			if (from === -1) return;
			var to = st.insertAt > from ? st.insertAt - 1 : st.insertAt;
			if (to === from) return;
			// Rebuild on the CONFIG's authoritative order, positioned relative
			// to the DOM neighbor the user dropped in front of.
			var order = sectionsOrder();
			var ci = order.indexOf(st.key);
			if (ci === -1) return;
			order.splice(ci, 1);
			var beforeKey = null;
			for (var n = st.insertAt; n < domOrder.length; n++) {
				if (domOrder[n] !== st.key) { beforeKey = domOrder[n]; break; }
			}
			var ti = beforeKey ? order.indexOf(beforeKey) : order.length;
			if (ti < 0) ti = order.length;
			order.splice(ti, 0, st.key);
			if (cfg.pageConfig) cfg.pageConfig.sections = order.slice();
			optimisticMove(st.key, order);
			renderSecChip();
			persistReorder(order, 'Reorder: move ' + sectionLabel(st.key));
			showToast('Moved ' + sectionLabel(st.key));
		}

		function cancelSectionDrag() { if (dragState) finishSectionDrag(false); }

		// ── 3. ⌘Z / ⌘⇧Z undo·redo over snapshot history ────────────────
		// restore() snapshots the replaced state first, so every restore is
		// itself restorable. Client-side we track which revision ids we've
		// already applied (skip on the next undo) and which snapshot ids were
		// minted BY our restores (the redo stack).
		var undoBusy = false, redoIds = [], appliedIds = {};
		function resetUndoChain() { redoIds = []; appliedIds = {}; }
		window.__pgResetUndo = resetUndoChain; // chat 'built' events invalidate redo

		function ajaxForm(action, extra) {
			var fd = new FormData();
			fd.append('action', action);
			fd.append('nonce', cfg.nonce);
			fd.append('post_id', cfg.postId);
			if (extra) Object.keys(extra).forEach(function (k) { fd.append(k, extra[k]); });
			return fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
				.then(function (r) { return r.json(); });
		}
		function fetchVersions() {
			return ajaxForm('pressgo_ai_versions').then(function (j) {
				if (!j || !j.success) throw new Error('versions failed');
				return j.data.versions || [];
			});
		}
		function refreshStoredConfig() {
			return ajaxForm('pressgo_ai_get_config').then(function (j) {
				if (j && j.success) cfg.pageConfig = j.data.config || null;
			}).catch(function () { /* stale local config is survivable */ });
		}
		function restoreRevision(revId) {
			return ajaxForm('pressgo_ai_restore', { revision_id: revId }).then(function (j) {
				if (!j || !j.success) throw new Error('restore failed');
				return j.data || {};
			});
		}
		function waitForIdle(cb, tries) {
			tries = tries == null ? 40 : tries;
			if ((!applyBusy && !queuedPatch && !isDirty()) || tries <= 0) { cb(); return; }
			setTimeout(function () { waitForIdle(cb, tries - 1); }, 150);
		}
		function afterRestore(data, msg) {
			return refreshStoredConfig().then(function () {
				reloadPreview((data && data.preview_bust) || Date.now());
				if (selectedSectionKey && (!cfg.pageConfig || !cfg.pageConfig[selectedSectionKey])) {
					selectedSectionKey = '';
					renderChatChip();
				}
				if (panelEl && panelEl.classList.contains('is-open')) renderPanel(false);
				renderSecChip();
				showToast(msg);
			});
		}
		function doUndo() {
			if (undoBusy) return;
			undoBusy = true;
			if (inline) commitInline();
			if (isDirty()) flushApply();
			waitForIdle(function () {
				fetchVersions().then(function (vers) {
					var target = null;
					for (var i = 0; i < vers.length; i++) {
						if (redoIds.indexOf(vers[i].id) === -1 && !appliedIds[vers[i].id]) { target = vers[i]; break; }
					}
					if (!target) { undoBusy = false; showToast('Nothing to undo.'); return; }
					restoreRevision(target.id).then(function (data) {
						appliedIds[target.id] = 1;
						// The restore snapshotted the replaced state — that new
						// newest revision is the redo target.
						return fetchVersions().then(function (v2) {
							if (v2.length && v2[0].id !== target.id && !appliedIds[v2[0].id] && redoIds.indexOf(v2[0].id) === -1) {
								redoIds.push(v2[0].id);
							}
							return afterRestore(data, 'Undid — ⌘⇧Z to redo');
						});
					}).then(function () { undoBusy = false; })
						.catch(function () { undoBusy = false; showToast('Could not undo — try the History panel.', true); });
				}).catch(function () { undoBusy = false; showToast('Could not undo — try the History panel.', true); });
			});
		}
		function doRedo() {
			if (undoBusy) return;
			if (!redoIds.length) { showToast('Nothing to redo.'); return; }
			undoBusy = true;
			var id = redoIds[redoIds.length - 1];
			restoreRevision(id).then(function (data) {
				redoIds.pop();
				appliedIds[id] = 1;
				return afterRestore(data, 'Redone — ⌘Z to undo');
			}).then(function () { undoBusy = false; })
				.catch(function () { undoBusy = false; showToast('Could not redo.', true); });
		}

		// Keyboard wiring — parent document AND every preview document (focus
		// usually lives in the iframe). Never while typing.
		function isEditableTarget(t) {
			if (!t) return false;
			var tag = (t.tagName || '').toLowerCase();
			if (tag === 'input' || tag === 'textarea' || tag === 'select') return true;
			return !!t.isContentEditable;
		}
		function globalKeys(e) {
			var k = e.key;
			var meta = e.metaKey || e.ctrlKey;
			if (meta && (k === 'z' || k === 'Z')) {
				if (inline || isEditableTarget(e.target)) return; // native text undo
				e.preventDefault();
				e.stopPropagation();
				if (e.shiftKey) doRedo(); else doUndo();
				return;
			}
			// Esc unwinds ONE layer at a time: drag → picker/toolbar → inline
			// (handled by the editable element's own keydown) → panel field
			// focus → section selection → select mode off. Never all at once.
			if (k === 'Escape' && selectMode) {
				if (document.querySelector('.pg-modal-backdrop')) return; // modal owns Esc
				if (inline) return; // the inline editor's keydown cancels just the edit
				if (dragState) { e.preventDefault(); cancelSectionDrag(); return; }
				if (imgPicker) { e.preventDefault(); closeImagePicker(); return; }
				if (tbEl) { e.preventDefault(); hideTextToolbar(); return; }
				if (isEditableTarget(e.target)) {
					// Focus in a PANEL field: Esc leaves the field (one layer).
					// Editables elsewhere (page title rename, chat box) keep
					// their own Esc behavior untouched.
					if (panelEl && panelEl.contains(e.target)) {
						e.preventDefault();
						try { e.target.blur(); } catch (err) {}
					}
					return;
				}
				if (selectedSectionKey) { e.preventDefault(); clearSelection(); return; }
				e.preventDefault();
				setSelectMode(false);
				return;
			}
			if ((k === 'ArrowLeft' || k === 'ArrowRight') && selectMode && selectedSectionKey && !pageMode) {
				if (inline || isEditableTarget(e.target) || meta || e.altKey) return;
				if (imgPicker) return; // picker owns the keyboard while open
				e.preventDefault();
				cycleVariant(k === 'ArrowRight' ? 1 : -1);
			}
		}
		document.addEventListener('keydown', globalKeys, true);
		function armFrameKeys() {
			try {
				var doc = frame.contentDocument;
				if (!doc || doc.__pgP3Keys) return;
				doc.__pgP3Keys = 1;
				doc.addEventListener('keydown', globalKeys, true);
			} catch (e) {}
		}
		onFrameReady(armFrameKeys);
		armFrameKeys();

		// ── 4. Image hot-swap ──────────────────────────────────────────
		// Value→path index over every image-kind field in the config
		// (top-level image fields, list items that are image URLs, repeater
		// sub-fields whose kind is image).
		function buildImageIndex() {
			var out = [];
			var pc = cfg.pageConfig || {};
			Object.keys(pc).forEach(function (key) {
				var spec = edFields[baseType(key)];
				if (!spec || !spec.fields) return;
				var sec = (!pageMode && key === selectedSectionKey && working) ? working : pc[key];
				if (!sec || typeof sec !== 'object') return;
				Object.keys(spec.fields).forEach(function (fkey) {
					var f = spec.fields[fkey] || {};
					var v = sec[fkey];
					if (v == null) return;
					if (f.kind === 'image' && typeof v === 'string' && v) {
						out.push({ key: key, path: [fkey], url: v });
					} else if ((f.kind === 'list' || f.kind === 'repeater') && Array.isArray(v)) {
						var sub = (f.fields && Object.keys(f.fields).length) ? f.fields : null;
						v.forEach(function (it, i) {
							if (typeof it === 'string' && looksLikeImageUrl(it)) {
								out.push({ key: key, path: [fkey, i], url: it });
							} else if (it && typeof it === 'object') {
								Object.keys(it).forEach(function (sk) {
									if (typeof it[sk] !== 'string' || !it[sk]) return;
									var kind = sub ? (sub[sk] && sub[sk].kind) : guessKind(sk);
									if (kind === 'image') out.push({ key: key, path: [fkey, i, sk], url: it[sk] });
								});
							}
						});
					}
				});
			});
			return out;
		}
		function looksLikeImageUrl(s) {
			return /^https?:\/\//.test(s) &&
				(/\.(jpe?g|png|gif|webp|avif|svg)(\?|#|$)/i.test(s) || /images\.(pexels|unsplash)\.com/.test(s) || /\/wp-content\/uploads\//.test(s));
		}
		function normImgUrl(u) {
			return String(u || '').replace(/^https?:\/\//, '').split('?')[0].split('#')[0];
		}
		// Match a rendered <img> src back to a config path. Exact first, then
		// query-stripped; section scope first, page-wide as fallback; any
		// ambiguity = no match (we'd rather do nothing than swap the wrong one).
		function matchImageEntry(src, key) {
			var idx = buildImageIndex();
			var inSec = idx.filter(function (e2) { return e2.key === key; });
			var i;
			for (i = 0; i < inSec.length; i++) if (inSec[i].url === src) return inSec[i];
			var ns = normImgUrl(src);
			var hits = inSec.filter(function (e2) { return normImgUrl(e2.url) === ns; });
			if (hits.length === 1) return hits[0];
			if (hits.length > 1) return null;
			var anyExact = idx.filter(function (e2) { return e2.url === src; });
			if (anyExact.length === 1) return anyExact[0];
			var anyNorm = idx.filter(function (e2) { return normImgUrl(e2.url) === ns; });
			return anyNorm.length === 1 ? anyNorm[0] : null;
		}
		// Background-image sections: when the section has exactly ONE
		// image-kind field and the clicked area paints a background image,
		// that field is the unambiguous target.
		function bgImageEntry(target, sec, key) {
			var spec = edFields[baseType(key)];
			if (!spec || !spec.fields) return null;
			var imgFields = Object.keys(spec.fields).filter(function (k) { return spec.fields[k].kind === 'image'; });
			if (imgFields.length !== 1) return null;
			var secObj = (!pageMode && key === selectedSectionKey && working) ? working : ((cfg.pageConfig || {})[key] || {});
			var v = secObj[imgFields[0]];
			if (typeof v !== 'string' || !v) return null;
			try {
				var win = sec.ownerDocument.defaultView;
				var el = target.nodeType === 1 ? target : target.parentElement;
				while (el && el !== sec.parentNode) {
					var bi = win.getComputedStyle(el).backgroundImage;
					if (bi && bi !== 'none' && bi.indexOf('url(') !== -1) {
						return { key: key, path: [imgFields[0]], url: v };
					}
					if (el === sec) break;
					el = el.parentElement;
				}
			} catch (e) {}
			return null;
		}

		function openPickerForImage(imgEl, key) {
			var src = imgEl.currentSrc || imgEl.src || '';
			if (!src) return false;
			var entry = matchImageEntry(src, key);
			if (!entry) return false;
			openPickerForEntry(entry, imgEl);
			return true;
		}
		function openPickerForEntry(entry, imgEl) {
			openImagePicker({
				current: entry.url,
				onPick: function (url) { applyImageSwap(entry, url, imgEl); }
			});
		}

		function applyImageSwap(entry, url, imgEl) {
			url = String(url || '').trim();
			if (!url) return;
			if (pageMode || entry.key !== selectedSectionKey) selectSection(entry.key);
			if (!working || typeof working !== 'object') return;
			// Adapt the path to working's shape: the panel may have promoted
			// list strings to {url:...} objects (normalizeRepeaterItems).
			var path = entry.path.slice();
			if (path.length === 2) {
				var arr = working[path[0]];
				var item = Array.isArray(arr) ? arr[path[1]] : null;
				if (item && typeof item === 'object') {
					var sk = null;
					Object.keys(item).forEach(function (k) { if (!sk && item[k] === entry.url) sk = k; });
					path.push(sk || ('url' in item ? 'url' : 'url'));
				}
			}
			setAtPath(working, path, url);
			markDirty(String(path[0]));
			fastFlush(300);
			// Optimistic: show the new image immediately.
			if (imgEl) {
				try { imgEl.src = url; imgEl.removeAttribute('srcset'); imgEl.removeAttribute('sizes'); } catch (e) {}
			}
			// Refresh panel control values if the panel shows this section.
			if (panelEl && panelEl.classList.contains('is-open') && !pageMode) renderPanel(true);
		}

		// The mini-picker popover (parent DOM, floats over the preview).
		var imgPicker = null, libCache = null;
		function closeImagePicker() {
			if (imgPicker) { imgPicker.remove(); imgPicker = null; }
			document.removeEventListener('mousedown', onPickerDocDown, true);
		}
		function onPickerDocDown(e) {
			if (imgPicker && !imgPicker.contains(e.target)) closeImagePicker();
		}
		function thumbCell(url, thumbUrl, isCurrent, pick) {
			var cell = document.createElement('button');
			cell.type = 'button';
			cell.className = 'pg-imgp-cell' + (isCurrent ? ' is-current' : '');
			cell.title = url;
			var im = document.createElement('img');
			im.loading = 'lazy';
			im.src = thumbUrl || url;
			im.alt = '';
			im.addEventListener('error', function () { cell.remove(); });
			cell.appendChild(im);
			cell.addEventListener('click', function () { pick(url); });
			return cell;
		}
		function openImagePicker(opts) {
			closeImagePicker();
			var pick = function (url) {
				closeImagePicker();
				opts.onPick(url);
			};
			imgPicker = document.createElement('div');
			imgPicker.className = 'pg-img-picker';
			var head = document.createElement('div');
			head.className = 'pg-imgp-head';
			var ttl = document.createElement('strong');
			ttl.textContent = 'Choose an image';
			var x = document.createElement('button');
			x.type = 'button';
			x.className = 'pg-imgp-x';
			x.innerHTML = '&times;';
			x.setAttribute('aria-label', 'Close');
			x.addEventListener('click', closeImagePicker);
			head.appendChild(ttl);
			head.appendChild(x);
			imgPicker.appendChild(head);

			// Paste-a-URL row.
			var row = document.createElement('div');
			row.className = 'pg-imgp-paste';
			var pi = document.createElement('input');
			pi.type = 'url';
			pi.placeholder = 'Paste an image URL…';
			var use = document.createElement('button');
			use.type = 'button';
			use.textContent = 'Use';
			function useUrl() {
				var u = (pi.value || '').trim();
				if (!/^https?:\/\//.test(u)) { pi.focus(); return; }
				pick(u);
			}
			use.addEventListener('click', useUrl);
			pi.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); useUrl(); } });
			row.appendChild(pi);
			row.appendChild(use);
			imgPicker.appendChild(row);

			var body = document.createElement('div');
			body.className = 'pg-imgp-body';
			imgPicker.appendChild(body);

			// On this page — every unique image URL already in the config.
			var pageUrls = [];
			var seenU = {};
			buildImageIndex().forEach(function (e2) {
				if (!seenU[e2.url]) { seenU[e2.url] = 1; pageUrls.push(e2.url); }
			});
			if (pageUrls.length) {
				var h1 = document.createElement('div');
				h1.className = 'pg-imgp-sect';
				h1.textContent = 'On this page';
				body.appendChild(h1);
				var g1 = document.createElement('div');
				g1.className = 'pg-imgp-grid';
				pageUrls.forEach(function (u) {
					g1.appendChild(thumbCell(u, u, u === opts.current, pick));
				});
				body.appendChild(g1);
			}

			// Media library — fetched once per builder session.
			var h2 = document.createElement('div');
			h2.className = 'pg-imgp-sect';
			h2.textContent = 'Media library';
			body.appendChild(h2);
			var g2 = document.createElement('div');
			g2.className = 'pg-imgp-grid';
			var note = document.createElement('div');
			note.className = 'pg-imgp-note';
			note.textContent = 'Loading…';
			body.appendChild(g2);
			body.appendChild(note);
			function renderLib(images) {
				g2.innerHTML = '';
				if (!images.length) {
					note.textContent = 'No images in the media library yet — drop some into wp-admin → Media, or paste a URL above.';
					return;
				}
				note.remove();
				images.forEach(function (im) {
					if (seenU[im.url]) return; // already shown under "On this page"
					g2.appendChild(thumbCell(im.url, im.thumb, im.url === opts.current, pick));
				});
			}
			if (libCache) {
				renderLib(libCache);
			} else {
				ajaxForm('pressgo_ai_list_images').then(function (j) {
					libCache = (j && j.success && j.data && j.data.images) || [];
					if (imgPicker) renderLib(libCache);
				}).catch(function () { note.textContent = 'Couldn’t load the media library — paste a URL above instead.'; });
			}

			previewWrap.appendChild(imgPicker);
			setTimeout(function () { document.addEventListener('mousedown', onPickerDocDown, true); }, 0);
		}

		// ── 5. AI text micro-toolbar (✨ Punchier · ✂ Shorter · ✓ Fix) ──
		var tbEl = null, tbTimer = null, tbText = '';
		function scheduleTbCheck() {
			clearTimeout(tbTimer);
			tbTimer = setTimeout(checkTextSelection, 140);
		}
		function hideTextToolbar() {
			if (tbEl) { tbEl.remove(); tbEl = null; }
			tbText = '';
		}
		function checkTextSelection() {
			if (!selectMode || !selectedSectionKey || inline || dragState) { hideTextToolbar(); return; }
			var win, doc;
			try { win = frame.contentWindow; doc = frame.contentDocument; } catch (e) { hideTextToolbar(); return; }
			if (!win || !doc) { hideTextToolbar(); return; }
			var sel = win.getSelection();
			if (!sel || sel.isCollapsed || !sel.rangeCount) { hideTextToolbar(); return; }
			var text = String(sel.toString()).replace(/\s+/g, ' ').trim();
			if (text.length < 3 || text.length > 300) { hideTextToolbar(); return; }
			var range = sel.getRangeAt(0);
			var node = range.commonAncestorContainer;
			var el = node.nodeType === 1 ? node : node.parentElement;
			if (!el || !el.closest) { hideTextToolbar(); return; }
			if (el.closest('[contenteditable]')) { hideTextToolbar(); return; } // inline edit = native
			var sec = el.closest('.pg-sec');
			if (!sec || keyOfSection(sec) !== selectedSectionKey) { hideTextToolbar(); return; }
			showTextToolbar(range, text);
		}
		function showTextToolbar(range, text) {
			tbText = text;
			if (!tbEl) {
				tbEl = document.createElement('div');
				tbEl.className = 'pg-ai-tb';
				[
					['✨ Punchier', 'punchier', 'Rewrite this selection to hit harder (1 credit)'],
					['✂ Shorter', 'shorter', 'Tighten this selection (1 credit)'],
					['✓ Fix', 'fix', 'Fix spelling and grammar (1 credit)']
				].forEach(function (def) {
					var b = document.createElement('button');
					b.type = 'button';
					b.textContent = def[0];
					b.title = def[2];
					b.addEventListener('mousedown', function (e) { e.preventDefault(); }); // keep the iframe selection
					b.addEventListener('click', function () { quickRewrite(def[1]); });
					tbEl.appendChild(b);
				});
				document.body.appendChild(tbEl);
			}
			var fr, r;
			try {
				fr = frame.getBoundingClientRect();
				r = range.getBoundingClientRect();
			} catch (e) { hideTextToolbar(); return; }
			if (!r || (!r.width && !r.height) || r.bottom < 0 || r.top > fr.height) { hideTextToolbar(); return; }
			var left = fr.left + r.left + r.width / 2;
			left = Math.max(fr.left + 90, Math.min(fr.left + fr.width - 90, left));
			var top = fr.top + r.top - 42;
			if (top < fr.top + 6) top = fr.top + r.bottom + 10;
			tbEl.style.left = left + 'px';
			tbEl.style.top = top + 'px';
		}
		function quickRewrite(kind) {
			var t = tbText;
			hideTextToolbar();
			try { frame.contentWindow.getSelection().removeAllRanges(); } catch (e) {}
			if (!t) return;
			if (sendBtn.disabled) { showToast('The AI is still working — try again in a moment.', true); return; }
			var q = '"' + t.replace(/"/g, '”') + '"';
			var msgs = {
				punchier: 'Rewrite this text to be punchier (keep the meaning, similar length): ' + q + ' — change ONLY that text, leave everything else exactly as it is.',
				shorter:  'Rewrite this text to be shorter and tighter (keep the meaning): ' + q + ' — change ONLY that text, leave everything else exactly as it is.',
				fix:      'Fix any spelling or grammar mistakes in this text: ' + q + ' — change ONLY that text, leave everything else exactly as it is.'
			};
			sendMessage(msgs[kind]); // scoped: selectedSectionKey rides the request
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
			},
			// Inline-editing introspection + a real apply trigger (used by the
			// mid-edit swap-carry test: applying ANOTHER section's patch while
			// an inline edit is focused must not eat the typing).
			inline: function () {
				return inline ? { key: inline.key, path: inline.path.join('.'), single: inline.single } : null;
			},
			indexSize: function () { return buildValueIndex().length; },
			config: function () { return cfg.pageConfig; },
			testApply: function (patch, label) { sendPatch({ patch: patch || {}, label: label || 'test apply' }); },
			// Phase 3 hooks.
			cycleVariant: cycleVariant,
			moveSection: moveSection,
			undo: doUndo,
			redo: doRedo,
			undoState: function () { return { busy: undoBusy, redoIds: redoIds.slice(), applied: Object.keys(appliedIds) }; },
			dragInfo: function () { return dragState ? { key: dragState.key, insertAt: dragState.insertAt, order: dragState.order } : null; },
			imageIndex: buildImageIndex,
			openImagePicker: openImagePicker,
			pickerOpen: function () { return !!imgPicker; },
			toolbar: function () { return { open: !!tbEl, text: tbText }; },
			checkSelection: checkTextSelection,
			quickRewrite: quickRewrite,
			applyDirect: applyDirect
		};
	})();

	loadHistory();
	refreshCredits();

	// ===== Voice input (mic button + live waveform) =====
	// States: idle → recording → transcribing → error → idle
	// Records audio via MediaRecorder, shows a live canvas waveform driven by
	// AnalyserNode — a scrolling amplitude trace (newest sample on the right,
	// older samples ease left), in the composer accent color. POSTs to
	// pressgo_ai_transcribe, fills the textarea. Does NOT auto-send — the spec
	// says let the user edit before sending.
	(function () {
		var micBtn = document.querySelector('.pg-mic-btn') || document.getElementById('pg-mic-btn');
		if (!micBtn) return;
		if (!navigator.mediaDevices || !window.MediaRecorder || !window.AudioContext) {
			micBtn.style.display = 'none';
			return;
		}

		var voiceBar   = document.getElementById('pg-voice-bar');
		var voiceTimer = document.getElementById('pg-voice-timer');
		var voiceHint  = document.getElementById('pg-voice-hint');
		var canvas     = document.getElementById('pg-voice-canvas');
		var ctx        = canvas ? canvas.getContext('2d') : null;
		var composerEl = document.querySelector('.pg-composer');

		var mediaRecorder = null;
		var audioChunks   = [];
		var stream        = null;
		var audioCtx      = null;
		var analyser      = null;
		var sourceNode    = null;
		var timeData      = null;   // time-domain buffer (for RMS amplitude)
		var rafId         = null;
		var recording     = false;
		var timerInterval = null;
		var timerStart    = 0;

		// Cap a runaway recording so the payload can't blow past PHP limits —
		// auto-stops and transcribes what we have. Mirrors the server guard.
		var MAX_SECONDS = 60;

		// Accent color for the trace (matches --pg-accent: #5b4fff).
		var ACCENT = '91, 79, 255';

		// Scrolling amplitude trace: `levels` holds recent mic amplitudes
		// (0–1, oldest → newest); `displayed` are the eased heights we draw, so
		// the motion is fluid rather than steppy. We advance the scroll on a
		// fixed cadence (not every frame) so it reads at a calm, voice-like pace.
		var NUM_BARS  = 40;
		var levels    = [];
		var displayed = [];
		for (var i = 0; i < NUM_BARS; i++) { levels.push(0); displayed.push(0); }
		var lastShift = 0;          // timestamp of the last scroll advance
		var SHIFT_MS  = 55;         // ~18 new bars/sec → ~2.2s of trace on screen

		function setState(s) {
			micBtn.setAttribute('data-state', s);
		}

		function showVoiceBar(hint) {
			if (voiceHint) voiceHint.textContent = hint;
			// Voice bar replaces the textarea area inside the composer.
			if (input) input.style.display = 'none';
			if (voiceBar) {
				voiceBar.hidden = false;
				requestAnimationFrame(function () { voiceBar.classList.add('is-visible'); });
			}
			if (composerEl) composerEl.classList.add('pg-voice-active');
		}

		function hideVoiceBar() {
			if (voiceBar) {
				voiceBar.classList.remove('is-visible');
				voiceBar.hidden = true;
			}
			if (composerEl) composerEl.classList.remove('pg-voice-active');
			if (input) {
				input.style.display = '';
				input.focus();
			}
		}

		function startTimer() {
			timerStart = Date.now();
			if (voiceTimer) voiceTimer.textContent = '0:00';
			timerInterval = setInterval(function () {
				var secs = Math.floor((Date.now() - timerStart) / 1000);
				// Auto-stop at the cap so the payload can't exceed server limits.
				if (secs >= MAX_SECONDS) {
					if (recording) stopRecording();
					return;
				}
				if (!voiceTimer) return;
				var m = Math.floor(secs / 60);
				var s = secs % 60;
				voiceTimer.textContent = m + ':' + (s < 10 ? '0' + s : s);
			}, 250);
		}

		function stopTimer() {
			if (timerInterval) { clearInterval(timerInterval); timerInterval = null; }
		}

		// ── Canvas waveform rendering ──
		function resizeCanvas() {
			if (!canvas || !ctx) return;
			var rect = canvas.getBoundingClientRect();
			var dpr = window.devicePixelRatio || 1;
			canvas.width = rect.width * dpr;
			canvas.height = rect.height * dpr;
			ctx.setTransform(1, 0, 0, 1, 0, 0);
			ctx.scale(dpr, dpr);
		}

		// Rounded-rect path helper (caps clamp to half-height for pill ends).
		function roundRect(c, x, y, ww, hh, rad) {
			if (rad > hh / 2) rad = hh / 2;
			if (rad > ww / 2) rad = ww / 2;
			c.beginPath();
			c.moveTo(x + rad, y);
			c.lineTo(x + ww - rad, y);
			c.quadraticCurveTo(x + ww, y, x + ww, y + rad);
			c.lineTo(x + ww, y + hh - rad);
			c.quadraticCurveTo(x + ww, y + hh, x + ww - rad, y + hh);
			c.lineTo(x + rad, y + hh);
			c.quadraticCurveTo(x, y + hh, x, y + hh - rad);
			c.lineTo(x, y + rad);
			c.quadraticCurveTo(x, y, x + rad, y);
			c.closePath();
		}

		function drawWaveform() {
			rafId = requestAnimationFrame(drawWaveform);
			if (!canvas || !ctx) return;

			var rect = canvas.getBoundingClientRect();
			var w = rect.width;
			var h = rect.height;
			ctx.clearRect(0, 0, w, h);

			// Current mic amplitude — RMS of the time-domain signal (0–1).
			// Time domain (not frequency) reads as a single "loudness" level,
			// which is what a voice trace should follow.
			var amp = 0;
			if (analyser && timeData) {
				analyser.getByteTimeDomainData(timeData);
				var sum = 0;
				for (var k = 0; k < timeData.length; k++) {
					var s = (timeData[k] - 128) / 128; // −1…1
					sum += s * s;
				}
				var rms = Math.sqrt(sum / timeData.length);
				// Perceptual lift so quiet speech still moves; clamp to 1.
				amp = Math.min(1, Math.pow(rms, 0.7) * 2.4);
			}

			// Advance the scroll on a fixed cadence: drop the oldest bar and
			// append the live amplitude on the right. Between shifts, let the
			// newest bar track the loudest sample so a quick syllable registers.
			var now = Date.now();
			if (now - lastShift >= SHIFT_MS) {
				lastShift = now;
				levels.shift();
				levels.push(amp);
			} else if (amp > levels[NUM_BARS - 1]) {
				levels[NUM_BARS - 1] = amp;
			}

			var barW  = w / NUM_BARS;
			var gap   = Math.max(1, barW * 0.34);
			var drawW = Math.max(1, barW - gap);
			var maxH  = h * 0.92;
			var mid   = h / 2;
			var rad   = Math.min(drawW / 2, 2);

			for (var j = 0; j < NUM_BARS; j++) {
				// Ease the drawn height toward the target — fluid, not steppy.
				displayed[j] += (levels[j] - displayed[j]) * 0.35;
				var lvl = displayed[j];

				var barH = Math.max(2, lvl * maxH);   // thin idle baseline
				var x = j * barW + gap / 2;
				var y = mid - barH / 2;               // mirror around centerline

				// Newer bars (right) read a touch stronger than the fading tail.
				var pos = NUM_BARS > 1 ? j / (NUM_BARS - 1) : 1; // 0 oldest … 1 newest
				var alpha = 0.22 + lvl * 0.62 + pos * 0.16;
				if (alpha > 1) alpha = 1;
				ctx.fillStyle = 'rgba(' + ACCENT + ', ' + alpha.toFixed(3) + ')';

				roundRect(ctx, x, y, drawW, barH, rad);
				ctx.fill();
			}
		}

		// ── Recording lifecycle ──
		function startRecording() {
			hideComposerError();
			navigator.mediaDevices.getUserMedia({ audio: true })
				.then(function (s) {
					stream = s;
					audioChunks = [];

					// Set up AudioContext + AnalyserNode for live waveform
					audioCtx = new AudioContext();
					sourceNode = audioCtx.createMediaStreamSource(s);
					analyser = audioCtx.createAnalyser();
					analyser.fftSize = 1024; // finer time-domain sampling for RMS
					analyser.smoothingTimeConstant = 0.8;
					timeData = new Uint8Array(analyser.fftSize);
					sourceNode.connect(analyser);

					// Reset the scrolling trace for a clean start.
					for (var bi = 0; bi < NUM_BARS; bi++) { levels[bi] = 0; displayed[bi] = 0; }
					lastShift = 0;

					// MediaRecorder for actual recording
					mediaRecorder = new MediaRecorder(s);
					mediaRecorder.ondataavailable = function (e) {
						if (e.data && e.data.size) audioChunks.push(e.data);
					};
					mediaRecorder.onstop = function () {
						var blob = new Blob(audioChunks, { type: mediaRecorder.mimeType || 'audio/webm' });
						audioChunks = [];
						transcribe(blob);
					};
					mediaRecorder.start();
					recording = true;

					setState('recording');
					showVoiceBar('Listening…');
					resizeCanvas();
					drawWaveform();
					startTimer();

					window.addEventListener('resize', resizeCanvas);
				})
				.catch(function (err) {
					var msg = 'Microphone access denied. Check your browser permissions.';
					if (err && err.name === 'NotAllowedError') {
						msg = 'Microphone access denied. Click the camera icon in your address bar to allow.';
					}
					setState('error');
					showComposerError(msg, startRecording);
				});
		}

		function stopRecording() {
			recording = false;
			if (rafId) { cancelAnimationFrame(rafId); rafId = null; }
			stopTimer();
			if (mediaRecorder && mediaRecorder.state !== 'inactive') mediaRecorder.stop();
			if (stream) stream.getTracks().forEach(function (t) { t.stop(); });
			if (sourceNode) { try { sourceNode.disconnect(); } catch (e) {} }
			if (audioCtx) { try { audioCtx.close(); } catch (e) {} audioCtx = null; analyser = null; }
			window.removeEventListener('resize', resizeCanvas);

			setState('transcribing');
			if (voiceBar) voiceBar.classList.add('is-processing');
			if (voiceHint) voiceHint.textContent = 'Transcribing…';
		}

		function transcribe(blob) {
			var reader = new FileReader();
			reader.onload = function () {
				var dataUrl = reader.result;
				var fd = new FormData();
				fd.append('action', 'pressgo_ai_transcribe');
				fd.append('nonce', cfg.nonce);
				fd.append('audio', dataUrl);
				fd.append('mime', blob.type || 'audio/webm');
				fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
					.then(function (r) { return r.json(); })
					.then(function (data) {
						if (data && data.success && data.data && data.data.text) {
							var text = data.data.text;
							if (input.value.trim()) {
								input.value = input.value.replace(/\s+$/, '') + ' ' + text;
							} else {
								input.value = text;
							}
							if (voiceBar) voiceBar.classList.remove('is-processing');
							hideVoiceBar();
							setState('idle');
							// Refresh textarea height + send state — do NOT auto-send.
							if (typeof autoGrow === 'function') autoGrow();
							if (typeof updateSendState === 'function') updateSendState();
						} else {
							failTranscribe();
						}
					})
					.catch(failTranscribe);
			};
			reader.onerror = failTranscribe;
			reader.readAsDataURL(blob);
		}

		function failTranscribe() {
			setState('error');
			if (voiceBar) voiceBar.classList.remove('is-processing');
			hideVoiceBar();
			showComposerError("Couldn't transcribe — try again.", startRecording);
		}

		micBtn.addEventListener('click', function () {
			if (recording) stopRecording();
			else startRecording();
		});

		// Spacebar on the mic button toggles recording (accessibility)
		micBtn.addEventListener('keydown', function (e) {
			if (e.key === ' ' || e.key === 'Spacebar') {
				e.preventDefault();
				if (recording) stopRecording();
				else startRecording();
			}
		});

		window.addEventListener('beforeunload', function () {
			if (recording) {
				if (rafId) cancelAnimationFrame(rafId);
				if (mediaRecorder && mediaRecorder.state !== 'inactive') mediaRecorder.stop();
				if (stream) stream.getTracks().forEach(function (t) { t.stop(); });
				if (audioCtx) { try { audioCtx.close(); } catch (e) {} }
			}
		});
	})();
})();
