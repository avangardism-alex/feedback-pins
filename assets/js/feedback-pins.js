/**
 * Feedback Pins: shared store + review toolbar on the site.
 *
 * Two parts:
 *
 * 1. `FeedbackPins.store`: the notes, read and written through the
 *    `feedback-pins/v1/notes` REST route. The server is the only source of
 *    truth, `localStorage` is just a warm-up cache. Every open browser reloads
 *    the list every 15 s (and when the tab comes back): what one reviewer
 *    pins shows up for everyone else without reloading.
 *
 * 2. The review toolbar, active only after `?fpins=1` in this browser: click
 *    an element, write a note, a numbered pin stays on it.
 *
 * The DOM is always built with `textContent`, never `innerHTML`: notes are
 * typed by third parties.
 */
(function () {
	'use strict';

	var CONFIG = window.FEEDBACK_PINS || {};

	// Scoped to the site: two WordPress sites of a multisite network can share
	// an origin, they must not share a key.
	var NS = 'fpins:' + (CONFIG.rest || '') + ':';
	var LS_KEY = NS + 'key';
	var LS_AUTHOR = NS + 'author';
	var LS_MODE = NS + 'mode';
	var LS_CACHE = NS + 'cache';

	var STRINGS = CONFIG.i18n || {};
	var SEVERITY_ORDER = ['blocker', 'major', 'minor', 'nice'];

	/* ── Small helpers ──────────────────────────────────────────────────────── */

	/**
	 * Translates a string, then replaces `%s` / `%d` (or `%1$s`) with the
	 * extra arguments. Falls back to the English source.
	 */
	function t(text) {
		var args = Array.prototype.slice.call(arguments, 1);
		var i = 0;
		return String(STRINGS[text] || text).replace(/%(?:(\d)\$)?[sd]/g, function (m, pos) {
			return String(pos ? args[Number(pos) - 1] : args[i++]);
		});
	}

	function severityLabel(s) {
		return (CONFIG.labels && CONFIG.labels.severities && CONFIG.labels.severities[s]) || s;
	}

	function statusLabel(s) {
		return (CONFIG.labels && CONFIG.labels.statuses && CONFIG.labels.statuses[s]) || s;
	}

	function read(key) {
		try {
			return window.localStorage.getItem(key) || '';
		} catch (e) {
			return '';
		}
	}

	function write(key, value) {
		try {
			if (value) window.localStorage.setItem(key, value);
			else window.localStorage.removeItem(key);
		} catch (e) {
			/* strict private browsing: the tool works, without memory */
		}
	}

	/**
	 * Builds an element. `children` accepts strings (set as text) and nodes.
	 */
	function el(tag, attrs, children) {
		var n = document.createElement(tag);
		attrs = attrs || {};
		Object.keys(attrs).forEach(function (k) {
			var v = attrs[k];
			if (v === null || v === undefined || v === false) return;
			if (k === 'class') n.className = v;
			else if (k === 'text') n.textContent = v;
			else if (k.indexOf('on') === 0) n.addEventListener(k.slice(2), v);
			else n.setAttribute(k, v === true ? '' : v);
		});
		[].concat(children || []).forEach(function (c) {
			if (c === null || c === undefined || c === false) return;
			n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
		});
		return n;
	}

	/** "3 minutes ago", "yesterday", otherwise the date, in the site language. */
	function since(iso) {
		if (!iso) return '';
		var time = new Date(iso).getTime();
		if (isNaN(time)) return '';
		var s = Math.round((time - Date.now()) / 1000);
		var locale = CONFIG.locale || undefined;
		if (Math.abs(s) < 172800 && window.Intl && Intl.RelativeTimeFormat) {
			var rtf = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' });
			if (Math.abs(s) < 60) return rtf.format(0, 'second');
			if (Math.abs(s) < 3600) return rtf.format(Math.round(s / 60), 'minute');
			if (Math.abs(s) < 86400) return rtf.format(Math.round(s / 3600), 'hour');
			return rtf.format(-1, 'day');
		}
		return new Date(time).toLocaleDateString(locale, { day: 'numeric', month: 'short' });
	}

	function isClosed(n) {
		return n.status === 'fixed' || n.status === 'wontfix';
	}

	/* ── Shared store ───────────────────────────────────────────────────────── */

	var store = (function () {
		var notes = [];
		var subscribers = [];
		var signature = '';
		var state = { ok: true, message: '', loaded: false };
		var timer = null;

		try {
			var cache = JSON.parse(read(LS_CACHE) || '[]');
			if (Array.isArray(cache)) notes = cache;
		} catch (e) {
			notes = [];
		}

		function notify() {
			subscribers.forEach(function (fn) {
				try {
					fn(notes, state);
				} catch (e) {
					console.error('[feedback-pins]', e);
				}
			});
		}

		function set(list) {
			var sig = JSON.stringify(list);
			if (sig === signature) return;
			signature = sig;
			notes = list;
			write(LS_CACHE, sig);
			notify();
		}

		function status(ok, message) {
			var changed = state.ok !== ok || state.message !== message || !state.loaded;
			state = { ok: ok, message: message || '', loaded: true };
			if (changed) notify();
		}

		function call(method, path, body) {
			var headers = { 'X-Feedback-Pins-Key': read(LS_KEY) };
			var verb = method;
			// DELETE goes as POST + override: some hosts filter the rarer verbs.
			if (method === 'DELETE') {
				verb = 'POST';
				headers['X-HTTP-Method-Override'] = 'DELETE';
			}
			if (body) headers['Content-Type'] = 'application/json';
			return fetch(CONFIG.rest + (path || ''), {
				method: verb,
				headers: headers,
				body: body ? JSON.stringify(body) : undefined,
				cache: 'no-store',
				credentials: 'omit'
			}).then(function (r) {
				return r.json().catch(function () {
					return {};
				}).then(function (json) {
					if (!r.ok) throw new Error(json.message || 'HTTP ' + r.status);
					return json;
				});
			});
		}

		function replace(note) {
			var found = false;
			var list = notes.map(function (n) {
				if (n.id === note.id) {
					found = true;
					return note;
				}
				return n;
			});
			if (!found) list.unshift(note);
			set(list);
		}

		var api = {
			notes: function () {
				return notes;
			},
			state: function () {
				return state;
			},
			key: function () {
				return read(LS_KEY);
			},
			author: function () {
				return read(LS_AUTHOR);
			},
			setKey: function (v) {
				write(LS_KEY, (v || '').trim());
				return api.refresh();
			},
			setAuthor: function (v) {
				write(LS_AUTHOR, (v || '').trim());
			},

			/** Reloads everything from the server. */
			refresh: function () {
				if (!api.key()) {
					status(false, t('Missing review key.'));
					return Promise.resolve();
				}
				return call('GET')
					.then(function (json) {
						set(Array.isArray(json.notes) ? json.notes : []);
						status(true, '');
					})
					.catch(function (err) {
						// Offline or key refused: keep what we have, the tool stays readable.
						status(false, err.message);
					});
			},

			create: function (note) {
				note.author = note.author || api.author();
				return call('POST', '', note).then(function (json) {
					replace(json.note);
					return json.note;
				});
			},

			update: function (id, patch) {
				// Optimistic: the screen changes at once, the server confirms after.
				set(notes.map(function (n) {
					return n.id === id ? Object.assign({}, n, patch) : n;
				}));
				return call('POST', '/' + id, patch)
					.then(function (json) {
						replace(json.note);
					})
					.catch(function (err) {
						api.refresh();
						throw err;
					});
			},

			remove: function (id) {
				set(notes.filter(function (n) {
					return n.id !== id;
				}));
				return call('DELETE', '/' + id).catch(function (err) {
					api.refresh();
					throw err;
				});
			},

			subscribe: function (fn) {
				subscribers.push(fn);
				return function () {
					subscribers = subscribers.filter(function (f) {
						return f !== fn;
					});
				};
			},

			/** Periodic resync, paused while the tab is hidden. */
			start: function () {
				if (timer) return;
				api.refresh();
				timer = window.setInterval(function () {
					if (document.visibilityState === 'visible') api.refresh();
				}, CONFIG.interval || 15000);
				document.addEventListener('visibilitychange', function () {
					if (document.visibilityState === 'visible') api.refresh();
				});
				window.addEventListener('focus', function () {
					api.refresh();
				});
			}
		};

		return api;
	})();

	/**
	 * Reads the review key from the address and stores it. It travels in the
	 * fragment (`#fpins_key=…`), which browsers never send to the server, and
	 * is removed from the address straight away.
	 */
	function takeKeyFromUrl() {
		var url = new URL(window.location.href);
		var hash = new URLSearchParams(url.hash.replace(/^#/, ''));
		var key = hash.get('fpins_key') || url.searchParams.get('fpins_key');
		if (!key) return false;
		write(LS_KEY, key.trim());
		hash.delete('fpins_key');
		url.searchParams.delete('fpins_key');
		var rest = hash.toString();
		window.history.replaceState(null, '', url.pathname + url.search + (rest ? '#' + rest : ''));
		return true;
	}

	/* ── Dialog and messages, shared with the board ─────────────────────────── */

	function dialog(title, content, footer) {
		var backdrop = el('div', { class: 'fpins-backdrop', 'data-fpins': '' });
		var titleId = 'fpins-dialog-' + Date.now();
		var box = el('div', { class: 'fpins-dialog', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': titleId }, [
			el('h2', { class: 'fpins-dialog-title', id: titleId, text: title }),
			content,
			footer ? el('div', { class: 'fpins-dialog-footer' }, footer) : null
		]);
		var previous = document.activeElement;
		function close() {
			backdrop.remove();
			document.removeEventListener('keydown', onKey, true);
			if (previous && previous.focus) previous.focus();
		}
		function onKey(e) {
			if (e.key === 'Escape') {
				e.stopPropagation();
				close();
				return;
			}
			// Keeps keyboard focus inside the dialog while it is open.
			if (e.key === 'Tab') {
				var items = Array.prototype.filter.call(box.querySelectorAll('button, [href], input, select, textarea'), function (n) {
					return !n.disabled && n.offsetParent !== null;
				});
				if (!items.length) return;
				var first = items[0];
				var last = items[items.length - 1];
				if (e.shiftKey && document.activeElement === first) {
					e.preventDefault();
					last.focus();
				} else if (!e.shiftKey && (document.activeElement === last || !box.contains(document.activeElement))) {
					e.preventDefault();
					first.focus();
				}
			}
		}
		backdrop.addEventListener('mousedown', function (e) {
			if (e.target === backdrop) close();
		});
		document.addEventListener('keydown', onKey, true);
		backdrop.appendChild(box);
		document.body.appendChild(backdrop);
		var field = box.querySelector('textarea, input');
		if (field) field.focus();
		return { close: close, box: box };
	}

	/**
	 * Reads a message to screen reader users. The live region exists before
	 * the message is written into it, otherwise most readers stay silent.
	 */
	var liveRegion = null;

	function announce(text) {
		if (!liveRegion) {
			liveRegion = el('div', { class: 'fpins-sr-only', role: 'status', 'aria-live': 'polite', 'data-fpins': '' });
			document.body.appendChild(liveRegion);
		}
		liveRegion.textContent = '';
		window.setTimeout(function () {
			liveRegion.textContent = text;
		}, 100);
	}

	function toast(text, isError) {
		var m = el('div', { class: 'fpins-toast' + (isError ? ' fpins-toast-error' : ''), 'aria-hidden': 'true', 'data-fpins': '', text: text });
		document.body.appendChild(m);
		announce(text);
		window.setTimeout(function () {
			m.remove();
		}, isError ? 6000 : 3000);
	}

	/**
	 * Asks for the key and the first name. `then` runs once both are known.
	 */
	function identify(then) {
		var keyField = el('input', { class: 'fpins-field', type: 'password', id: 'fpins-key', autocomplete: 'off', value: store.key() });
		var nameField = el('input', { class: 'fpins-field', type: 'text', id: 'fpins-name', autocomplete: 'given-name', value: store.author(), placeholder: t('e.g. Camille') });
		var error = el('p', { class: 'fpins-error', role: 'alert' });
		var d;
		function submit() {
			if (!nameField.value.trim() || !keyField.value.trim()) {
				error.textContent = t('Both fields are required.');
				return;
			}
			store.setAuthor(nameField.value);
			store.setKey(keyField.value).then(function () {
				if (!store.state().ok) {
					error.textContent = t('Key refused: %s', store.state().message);
					return;
				}
				d.close();
				if (then) then();
			});
		}
		d = dialog(
			t('Who is reviewing?'),
			el('div', {}, [
				el('p', { class: 'fpins-help', text: t('Your first name signs your notes, so the team knows who spotted what. The key came with your review link.') }),
				el('label', { class: 'fpins-label', for: 'fpins-name', text: t('Your first name') }),
				nameField,
				el('label', { class: 'fpins-label', for: 'fpins-key', text: t('Review key') }),
				keyField,
				error
			]),
			[
				el('button', { type: 'button', class: 'fpins-btn', text: t('Cancel'), onclick: function () { d.close(); } }),
				el('button', { type: 'button', class: 'fpins-btn fpins-btn-primary', text: t('Continue'), onclick: submit })
			]
		);
		d.box.addEventListener('keydown', function (e) {
			if (e.key === 'Enter') submit();
		});
	}

	function loadCss() {
		if (!CONFIG.css || document.getElementById('fpins-css')) return;
		document.head.appendChild(el('link', { id: 'fpins-css', rel: 'stylesheet', href: CONFIG.css }));
	}

	window.FeedbackPins = {
		store: store,
		el: el,
		t: t,
		dialog: dialog,
		toast: toast,
		announce: announce,
		identify: identify,
		since: since,
		isClosed: isClosed,
		takeKeyFromUrl: takeKeyFromUrl,
		severityLabel: severityLabel,
		statusLabel: statusLabel,
		SEVERITY_ORDER: SEVERITY_ORDER
	};

	if (CONFIG.view !== 'site') return;

	/* ── Review toolbar on the site ─────────────────────────────────────────── */

	/**
	 * `?fpins=1` turns review mode on and remembers it, `?fpins=0` turns it off,
	 * `#fpins_key=…` stores the key. Everything is then removed from the address.
	 */
	var targetNote = null; // `?fpins_note=ID`: note to show on arrival

	function readParams() {
		if (takeKeyFromUrl()) write(LS_MODE, '1');
		var url = new URL(window.location.href);
		var p = url.searchParams;
		var touched = false;
		if (p.has('fpins_note')) {
			targetNote = Number(p.get('fpins_note')) || null;
			write(LS_MODE, '1');
			p.delete('fpins_note');
			touched = true;
		}
		if (p.get('fpins') === '1') write(LS_MODE, '1');
		if (p.get('fpins') === '0') write(LS_MODE, '');
		if (p.has('fpins')) {
			p.delete('fpins');
			touched = true;
		}
		if (touched) window.history.replaceState(null, '', url.pathname + (p.toString() ? '?' + p.toString() : '') + url.hash);
		return read(LS_MODE) === '1';
	}

	/** CSS selector precise enough to find the element again on another screen. */
	function cssPath(target) {
		var parts = [];
		var cur = target;
		while (cur && cur.nodeType === 1 && cur !== document.body && parts.length < 8) {
			var part = cur.nodeName.toLowerCase();
			if (cur.id && !/^\d/.test(cur.id)) {
				parts.unshift(part + '#' + CSS.escape(cur.id));
				break;
			}
			var parent = cur.parentElement;
			if (parent) {
				var same = Array.prototype.filter.call(parent.children, function (c) {
					return c.nodeName === cur.nodeName;
				});
				if (same.length > 1) part += ':nth-of-type(' + (same.indexOf(cur) + 1) + ')';
			}
			parts.unshift(part);
			cur = parent;
		}
		return parts.join(' > ');
	}

	/** Name of the section holding the element, to read the note without the page. */
	function sectionOf(target) {
		var cur = target;
		while (cur && cur !== document.body) {
			if (cur.tagName === 'SECTION' || cur.tagName === 'HEADER' || cur.tagName === 'FOOTER' || cur.hasAttribute('data-section')) {
				var name = cur.getAttribute('data-section') || cur.getAttribute('aria-label');
				if (name) return name.slice(0, 80);
				var heading = cur.querySelector('h1, h2, h3');
				if (heading && heading.textContent.trim()) return heading.textContent.trim().replace(/\s+/g, ' ').slice(0, 80);
				if (cur.tagName === 'HEADER') return t('Header');
				if (cur.tagName === 'FOOTER') return t('Footer');
				if (cur.id) return cur.id;
			}
			cur = cur.parentElement;
		}
		return '';
	}

	/* Screenshot: html2canvas, loaded only when a note is being pinned. */

	var screenshotLoader = null;

	function loadScreenshotLib() {
		if (window.html2canvas) return Promise.resolve(window.html2canvas);
		if (!screenshotLoader) {
			screenshotLoader = new Promise(function (ok, ko) {
				var s = el('script', { src: CONFIG.screenshotLib, async: true });
				s.onload = function () { ok(window.html2canvas); };
				s.onerror = function () { screenshotLoader = null; ko(new Error('screenshot script not found')); };
				document.head.appendChild(s);
			});
		}
		return screenshotLoader;
	}

	/**
	 * Takes a picture of the screen as the reviewer sees it, frames the target
	 * element and marks the click point. Resolves with a JPEG data URL.
	 *
	 * @param {DOMRect} rect Element rectangle, in viewport coordinates.
	 * @param {number} px    Click point, in viewport coordinates.
	 * @param {number} py
	 */
	function screenshot(rect, px, py) {
		// Beyond 2× or 1440 px wide, the picture gets heavier without showing more.
		var scale = Math.min(window.devicePixelRatio || 1, 2, 1440 / window.innerWidth);
		// Sticky and fixed elements (headers…): html2canvas paints them where
		// they sit in the flow, at the top of the document, so out of the
		// captured area as soon as the page is scrolled. Remember where the
		// reviewer saw them, to move them back there in the copy.
		var pinned = [];
		Array.prototype.forEach.call(document.body.getElementsByTagName('*'), function (n) {
			// An element inside an already marked element follows its parent.
			if (n.closest('[data-fpins]') || (n.parentElement && n.parentElement.closest('[data-fpins-fixed]'))) return;
			var pos = window.getComputedStyle(n).position;
			if (pos !== 'fixed' && pos !== 'sticky') return;
			var r = n.getBoundingClientRect();
			if (!r.width || !r.height) return;
			n.setAttribute('data-fpins-fixed', String(pinned.length));
			pinned.push({ x: r.left + window.scrollX, y: r.top + window.scrollY });
		});
		function forgetPinned() {
			Array.prototype.forEach.call(document.querySelectorAll('[data-fpins-fixed]'), function (n) {
				n.removeAttribute('data-fpins-fixed');
			});
		}

		var work = loadScreenshotLib().then(function (h2c) {
			// html2canvas shifts the rendering by the scroll offset.
			// `scrollX/scrollY: 0` renders the document in place, `x/y` then
			// crops the area the reviewer had in front of them.
			return h2c(document.body, {
				x: window.scrollX,
				y: window.scrollY,
				width: window.innerWidth,
				height: window.innerHeight,
				scrollX: 0,
				scrollY: 0,
				windowWidth: document.documentElement.clientWidth,
				windowHeight: window.innerHeight,
				scale: scale,
				useCORS: true,
				logging: false,
				backgroundColor: '#ffffff',
				ignoreElements: function (n) {
					return n.hasAttribute && n.hasAttribute('data-fpins');
				},
				onclone: function (copy) {
					// A body in `overflow: auto` is taken for a clipping box: anything
					// below the first screen (footer included) would vanish.
					copy.documentElement.style.overflow = 'visible';
					copy.body.style.overflow = 'visible';
					// The copy is rendered unscrolled: move every sticky or fixed
					// element back to where it was on screen.
					Array.prototype.forEach.call(copy.querySelectorAll('[data-fpins-fixed]'), function (n) {
						var target = pinned[Number(n.getAttribute('data-fpins-fixed'))];
						var r = n.getBoundingClientRect();
						var dx = target.x - r.left;
						var dy = target.y - r.top;
						if (Math.abs(dx) > 0.5 || Math.abs(dy) > 0.5) {
							var tr = copy.defaultView.getComputedStyle(n).transform;
							n.style.transform = 'translate(' + dx + 'px,' + dy + 'px)' + (tr && tr !== 'none' ? ' ' + tr : '');
						}
					});
					// Blocks that fade in: freeze them in their final state.
					var style = copy.createElement('style');
					style.textContent = '*,*::before,*::after{transition:none!important;animation:none!important}';
					copy.head.appendChild(style);
				}
			});
		}).then(function (canvas) {
			var ctx = canvas.getContext('2d');
			ctx.save();
			// html2canvas leaves its own transform on the context: without a
			// reset, the marker would be drawn outside the picture.
			ctx.setTransform(1, 0, 0, 1, 0, 0);
			ctx.lineWidth = 3 * scale;
			ctx.setLineDash([8 * scale, 5 * scale]);
			ctx.strokeStyle = '#714edd';
			ctx.fillStyle = 'rgba(113,78,221,0.10)';
			ctx.fillRect(rect.left * scale, rect.top * scale, rect.width * scale, rect.height * scale);
			ctx.strokeRect(rect.left * scale, rect.top * scale, rect.width * scale, rect.height * scale);
			ctx.setLineDash([]);
			ctx.beginPath();
			ctx.arc(px * scale, py * scale, 13 * scale, 0, Math.PI * 2);
			ctx.fillStyle = '#dc2626';
			ctx.fill();
			ctx.lineWidth = 3 * scale;
			ctx.strokeStyle = '#ffffff';
			ctx.stroke();
			ctx.restore();
			return canvas.toDataURL('image/jpeg', 0.75);
		});
		var timeout = new Promise(function (ok, ko) {
			window.setTimeout(function () { ko(new Error('screenshot took too long')); }, 12000);
		});
		var result = Promise.race([work, timeout]);
		result.then(forgetPinned, forgetPinned);
		return result;
	}

	function startToolbar() {
		loadCss();

		var path = window.location.pathname;
		var addMode = false;
		var openId = null; // note whose bubble is open
		var showClosed = false;

		var layer = el('div', { class: 'fpins-layer', 'data-fpins': '' });
		var hover = el('div', { class: 'fpins-hover', 'data-fpins': '' });
		var banner = el('div', { class: 'fpins-banner', 'data-fpins': '', text: t('Click the element you want to comment on, or reach it with Tab and press Enter · Esc to cancel') });
		var counter = el('span', { class: 'fpins-counter' });
		var syncDot = el('span', { class: 'fpins-sync', role: 'img', title: '' });
		var addButton = el('button', { type: 'button', class: 'fpins-bar-add', text: t('+ Note') });
		var panel = el('aside', { class: 'fpins-panel', 'data-fpins': '', 'aria-label': t('Notes on this page'), hidden: true });

		var bar = el('div', { class: 'fpins-bar', 'data-fpins': '', role: 'toolbar', 'aria-label': t('Feedback Pins') }, [
			syncDot,
			counter,
			addButton,
			el('button', { type: 'button', class: 'fpins-bar-link', text: t('List'), onclick: togglePanel }),
			el('a', { class: 'fpins-bar-link', href: CONFIG.board, target: '_blank', rel: 'noopener', text: t('Board') }),
			el('button', { type: 'button', class: 'fpins-bar-close', title: t('Leave review mode'), 'aria-label': t('Leave review mode'), text: '×', onclick: quit })
		]);

		document.body.appendChild(layer);
		document.body.appendChild(hover);
		document.body.appendChild(panel);
		document.body.appendChild(bar);

		addButton.addEventListener('click', function () {
			if (!store.key() || !store.author()) {
				identify(function () {
					setAddMode(true);
				});
				return;
			}
			setAddMode(!addMode);
		});

		function pageNotes() {
			// Numbered by creation order: the same for everyone.
			return store.notes().filter(function (n) {
				return n.url === path;
			}).slice().sort(function (a, b) {
				return (a.createdAt || '').localeCompare(b.createdAt || '') || a.id - b.id;
			});
		}

		/** Pin position in page coordinates, or null. */
		function position(n) {
			var target = null;
			try {
				target = n.selector ? document.querySelector(n.selector) : null;
			} catch (e) {
				target = null;
			}
			if (target && typeof n.relX === 'number') {
				var r = target.getBoundingClientRect();
				if (r.width || r.height) {
					return { x: r.left + window.scrollX + n.relX * r.width, y: r.top + window.scrollY + n.relY * r.height };
				}
			}
			if (typeof n.x === 'number' && typeof n.y === 'number') {
				return { x: n.x, y: n.y + (n.scrollY || 0) };
			}
			return null;
		}

		var lastSignature = '';

		/**
		 * Redraws pins, bubble and panel. Called often (sync, layout timer):
		 * the DOM is only touched when something changed, otherwise a click in
		 * progress on the bubble would be lost.
		 */
		function draw() {
			var list = pageNotes();
			var open = list.filter(function (n) {
				return !isClosed(n);
			});
			var st = store.state();

			// The layer coordinates are the origin, whatever the body styles.
			var origin = layer.getBoundingClientRect();
			var ox = origin.left + window.scrollX;
			var oy = origin.top + window.scrollY;
			var positions = list.map(function (n) {
				var pos = position(n);
				return pos ? { x: Math.round(pos.x - ox), y: Math.round(pos.y - oy) } : null;
			});

			var signature = JSON.stringify([list, positions, openId, panel.hidden, showClosed, st, window.innerWidth]);
			if (signature === lastSignature) return;
			lastSignature = signature;

			counter.textContent = t('%d open', open.length);
			syncDot.className = 'fpins-sync' + (st.ok ? '' : ' fpins-sync-ko');
			syncDot.title = st.ok ? t('In sync with the team') : t('Sync failed: %s', st.message);
			syncDot.setAttribute('aria-label', syncDot.title);

			layer.textContent = '';
			list.forEach(function (n, i) {
				var pos = positions[i];
				if (!pos || (isClosed(n) && n.id !== openId)) return;
				layer.appendChild(el('button', {
					type: 'button',
					class: 'fpins-pin fpins-sev-' + n.severity + (isClosed(n) ? ' fpins-pin-closed' : ''),
					style: 'left:' + pos.x + 'px;top:' + pos.y + 'px',
					title: (n.author ? n.author + ': ' : '') + n.description,
					'aria-label': t('Note %d', i + 1),
					'aria-expanded': openId === n.id ? 'true' : 'false',
					text: String(i + 1),
					onclick: function (e) {
						e.stopPropagation();
						openId = openId === n.id ? null : n.id;
						draw();
					}
				}));
				if (openId === n.id) layer.appendChild(bubble(n, i + 1, pos, ox));
			});

			if (!panel.hidden) fillPanel(list);
		}

		/** Detail bubble under the pin, kept within the screen width. */
		function bubble(n, number, pos, ox) {
			var width = Math.min(300, window.innerWidth - 32);
			var min = window.scrollX + 16 - ox;
			var max = window.scrollX + window.innerWidth - width - 16 - ox;
			var left = Math.max(min, Math.min(pos.x - 12, max));
			return el('div', { class: 'fpins-bubble fpins-edge-' + n.severity, style: 'left:' + left + 'px;top:' + (pos.y + 18) + 'px;width:' + width + 'px' }, [
				el('div', { class: 'fpins-bubble-head' }, [
					el('strong', { class: 'fpins-txt-' + n.severity, text: '#' + number + ' · ' + severityLabel(n.severity) }),
					el('span', { class: 'fpins-status fpins-status-' + n.status, text: statusLabel(n.status) }),
					el('button', { type: 'button', class: 'fpins-x', 'aria-label': t('Close'), text: '×', onclick: function () { openId = null; draw(); } })
				]),
				el('div', { class: 'fpins-meta', text: [n.author || t('Anonymous'), since(n.createdAt), n.sectionLabel].filter(Boolean).join(' · ') }),
				el('div', { class: 'fpins-description', text: n.description }),
				n.fixNote ? el('div', { class: 'fpins-answer' }, [el('strong', { text: t('Answer:') + ' ' }), n.fixNote]) : null,
				el('div', { class: 'fpins-actions' }, [
					isClosed(n)
						? el('button', { type: 'button', class: 'fpins-btn fpins-btn-small', text: t('Reopen'), onclick: function () { change(n.id, { status: 'open' }); } })
						: el('button', { type: 'button', class: 'fpins-btn fpins-btn-small fpins-btn-primary', text: t('Mark as fixed'), onclick: function () { openId = null; change(n.id, { status: 'fixed' }); } }),
					el('button', { type: 'button', class: 'fpins-btn fpins-btn-small fpins-btn-danger', text: t('Delete'), onclick: function () { remove(n); } })
				])
			]);
		}

		function change(id, patch) {
			store.update(id, patch).catch(function (err) {
				toast(t('Could not update: %s', err.message), true);
			});
		}

		function remove(n) {
			if (!window.confirm(t('Delete this note for the whole team?'))) return;
			openId = null;
			store.remove(n.id).catch(function (err) {
				toast(t('Could not delete: %s', err.message), true);
			});
		}

		/* Side panel: the notes of this page, everyone's included. */

		function togglePanel() {
			panel.hidden = !panel.hidden;
			draw();
		}

		function fillPanel(list) {
			var visible = list.filter(function (n) {
				return showClosed || !isClosed(n);
			});
			var closed = list.filter(isClosed).length;
			panel.textContent = '';
			panel.appendChild(el('div', { class: 'fpins-panel-head' }, [
				el('strong', { text: t('Notes on this page') }),
				el('button', { type: 'button', class: 'fpins-x', 'aria-label': t('Close the list'), text: '×', onclick: togglePanel })
			]));
			if (closed) {
				var box = el('input', { type: 'checkbox', id: 'fpins-closed', checked: showClosed, onchange: function () { showClosed = box.checked; draw(); } });
				panel.appendChild(el('label', { class: 'fpins-option', for: 'fpins-closed' }, [box, ' ' + t('Show %d closed note(s)', closed)]));
			}
			if (!visible.length) {
				panel.appendChild(el('p', { class: 'fpins-empty', text: t('No open note on this page. Click “+ Note” to add one.') }));
				return;
			}
			visible.forEach(function (n) {
				var number = list.indexOf(n) + 1;
				panel.appendChild(el('button', {
					type: 'button',
					class: 'fpins-row fpins-edge-' + n.severity + (isClosed(n) ? ' fpins-row-closed' : ''),
					onclick: function () { goTo(n); }
				}, [
					el('span', { class: 'fpins-meta', text: '#' + number + ' · ' + severityLabel(n.severity) + ' · ' + (n.author || t('Anonymous')) + ' · ' + since(n.createdAt) + (isClosed(n) ? ' · ' + statusLabel(n.status) : '') }),
					el('span', { class: 'fpins-row-text', text: n.description })
				]));
			});
		}

		function goTo(n) {
			openId = n.id;
			var pos = position(n);
			var smooth = !(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
			if (pos) window.scrollTo({ top: Math.max(0, pos.y - window.innerHeight / 3), behavior: smooth ? 'smooth' : 'auto' });
			draw();
		}

		/* Add mode: framed hover, click caught before the site (links included). */

		function setAddMode(on) {
			addMode = on;
			addButton.textContent = on ? t('Cancel') : t('+ Note');
			addButton.classList.toggle('fpins-active', on);
			document.documentElement.classList.toggle('fpins-adding', on);
			if (on) {
				document.body.appendChild(banner);
				announce(banner.textContent);
				loadScreenshotLib().catch(function () {});
			} else {
				banner.remove();
				hover.style.display = 'none';
			}
		}

		function outsideTool(target) {
			return target && !(target.closest && target.closest('[data-fpins]'));
		}

		window.addEventListener('mousemove', function (e) {
			if (!addMode) return;
			var target = document.elementFromPoint(e.clientX, e.clientY);
			if (!outsideTool(target)) {
				hover.style.display = 'none';
				return;
			}
			var r = target.getBoundingClientRect();
			hover.style.cssText = 'display:block;top:' + r.top + 'px;left:' + r.left + 'px;width:' + r.width + 'px;height:' + r.height + 'px';
		});

		window.addEventListener('click', function (e) {
			if (!addMode) {
				// A click elsewhere closes the open bubble.
				if (openId && outsideTool(e.target)) {
					openId = null;
					draw();
				}
				return;
			}
			var target = document.elementFromPoint(e.clientX, e.clientY);
			if (!outsideTool(target)) return;
			e.preventDefault();
			e.stopPropagation();
			pinOn(target, e.clientX, e.clientY);
		}, true);

		/**
		 * Opens the note form for an element. Without a click point (keyboard),
		 * the pin goes to the centre of the element.
		 */
		function pinOn(target, cx, cy) {
			var r = target.getBoundingClientRect();
			if (cx === null) {
				cx = r.left + r.width / 2;
				cy = r.top + r.height / 2;
			}
			setAddMode(false);
			// The picture is taken right away, while the reviewer types.
			var shot = screenshot(r, cx, cy);
			shot.catch(function (err) {
				console.warn('[feedback-pins] screenshot failed:', err.message);
			});
			compose(shot, {
				selector: cssPath(target),
				sectionLabel: sectionOf(target),
				relX: r.width ? (cx - r.left) / r.width : 0,
				relY: r.height ? (cy - r.top) / r.height : 0,
				x: cx + window.scrollX,
				y: cy,
				scrollY: window.scrollY
			});
		}

		window.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && addMode) setAddMode(false);
			else if (e.key === 'Escape' && openId) {
				openId = null;
				draw();
			}
		});

		// Keyboard: in add mode, the frame follows the focus, and Enter pins a
		// note on the focused element instead of activating it.
		document.addEventListener('focusin', function (e) {
			if (!addMode || !outsideTool(e.target) || e.target === document.body) return;
			var r = e.target.getBoundingClientRect();
			hover.style.cssText = 'display:block;top:' + r.top + 'px;left:' + r.left + 'px;width:' + r.width + 'px;height:' + r.height + 'px';
		});

		document.addEventListener('keydown', function (e) {
			var target = document.activeElement;
			if (!addMode || e.key !== 'Enter' || !target || target === document.body || !outsideTool(target)) return;
			e.preventDefault();
			e.stopPropagation();
			pinOn(target, null, null);
		}, true);

		function compose(shot, anchor) {
			var severity = 'major';
			var image = null;
			var attach = true;
			var preview = el('div', { class: 'fpins-preview' }, [el('span', { class: 'fpins-meta', text: t('Taking a screenshot…') })]);
			shot.then(function (dataurl) {
				image = dataurl;
				preview.textContent = '';
				preview.appendChild(el('img', { src: dataurl, alt: t('Screenshot attached to the note') }));
				preview.appendChild(el('button', { type: 'button', class: 'fpins-preview-remove', text: t('Do not attach'), onclick: function () {
					attach = false;
					preview.textContent = '';
					preview.appendChild(el('span', { class: 'fpins-meta', text: t('Screenshot removed.') }));
				} }));
			}, function () {
				preview.textContent = '';
				preview.appendChild(el('span', { class: 'fpins-meta', text: t('No screenshot on this page: the note will be located by its position.') }));
			});
			var text = el('textarea', { class: 'fpins-field', rows: '4', 'aria-label': t('Your note'), placeholder: t('e.g. the button is too small on mobile, the text should say…') });
			var chips = el('div', { class: 'fpins-chips', role: 'group', 'aria-label': t('Severity') });
			SEVERITY_ORDER.forEach(function (s) {
				var b = el('button', { type: 'button', class: 'fpins-chip fpins-chip-' + s, text: severityLabel(s), onclick: function () { severity = s; updateChips(); } });
				b.dataset.sev = s;
				chips.appendChild(b);
			});
			function updateChips() {
				Array.prototype.forEach.call(chips.children, function (b) {
					var active = b.dataset.sev === severity;
					b.classList.toggle('fpins-chip-active', active);
					b.setAttribute('aria-pressed', active ? 'true' : 'false');
				});
			}
			updateChips();

			var d;
			var button = el('button', { type: 'button', class: 'fpins-btn fpins-btn-primary', text: t('Save'), onclick: save });
			function save() {
				if (!text.value.trim()) {
					text.focus();
					return;
				}
				button.disabled = true;
				button.textContent = image ? t('Sending…') : t('Screenshot…');
				// Wait for the picture, but never to the point of losing the note.
				shot.catch(function () { return null; }).then(function () {
					button.textContent = t('Sending…');
					return store.create(Object.assign({}, anchor, {
						screenshot: attach && image ? image : undefined,
						description: text.value.trim(),
						severity: severity,
						url: path,
						pageTitle: document.title,
						viewportWidth: window.innerWidth,
						viewportHeight: window.innerHeight,
						userAgent: navigator.userAgent
					}));
				}).then(function () {
					d.close();
					toast(t('Note saved, the whole team can see it.'));
				}).catch(function (err) {
					button.disabled = false;
					button.textContent = t('Save');
					toast(t('Could not save: %s', err.message), true);
				});
			}

			d = dialog(t('New note'), el('div', {}, [
				anchor.sectionLabel ? el('p', { class: 'fpins-meta', text: t('Section: %s', anchor.sectionLabel) }) : null,
				text,
				preview,
				el('p', { class: 'fpins-label', text: t('Severity') }),
				chips,
				el('p', { class: 'fpins-help', text: t('Signed “%s”. ⌘/Ctrl + Enter to save.', store.author()) })
			]), [
				el('button', { type: 'button', class: 'fpins-btn', text: t('Cancel'), onclick: function () { d.close(); } }),
				button
			]);
			text.addEventListener('keydown', function (e) {
				if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) save();
			});
		}

		function quit() {
			write(LS_MODE, '');
			setAddMode(false);
			[layer, hover, panel, bar].forEach(function (n) {
				n.remove();
			});
			window.clearInterval(relayout);
		}

		// Pins follow the layout (images loading, accordions, resizing).
		var relayout = window.setInterval(draw, 1500);
		window.addEventListener('resize', draw);
		window.addEventListener('load', draw);

		store.subscribe(draw);

		// Arrival from a "View on the page" link (board or Slack): scroll to
		// the note, open its bubble and make the element blink.
		if (targetNote) {
			var show = function () {
				var n = store.notes().filter(function (x) { return x.id === targetNote; })[0];
				if (!n) return false;
				targetNote = null;
				goTo(n);
				var target = null;
				try { target = n.selector ? document.querySelector(n.selector) : null; } catch (e) { target = null; }
				if (target) {
					target.classList.add('fpins-flash');
					window.setTimeout(function () { target.classList.remove('fpins-flash'); }, 4000);
				}
				return true;
			};
			var unsubscribe = store.subscribe(function () {
				if (store.state().loaded && (show() || store.state().ok)) {
					unsubscribe();
					if (targetNote) toast(t('This note has been deleted in the meantime.'), true);
				}
			});
		}

		store.start();
		draw();

		if (!store.key() || !store.author()) identify();
	}

	/**
	 * On the site, labels are fetched on demand: the page only carries a tiny
	 * loader for regular visitors.
	 */
	function loadStrings() {
		if (CONFIG.i18n || !CONFIG.strings) return Promise.resolve();
		return fetch(CONFIG.strings, { credentials: 'omit' })
			.then(function (r) { return r.ok ? r.json() : {}; })
			.then(function (json) {
				STRINGS = json.i18n || {};
				CONFIG.labels = json.labels || CONFIG.labels;
			})
			.catch(function () { /* English it is */ });
	}

	if (!readParams()) return;

	function boot() {
		loadStrings().then(startToolbar);
	}

	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
	else boot();
})();
