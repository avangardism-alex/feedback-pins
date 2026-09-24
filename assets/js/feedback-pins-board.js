/**
 * Feedback board: every note of the team, in three columns.
 *
 * Relies on `FeedbackPins` (feedback-pins.js) for the sync: the board updates
 * itself when someone adds, moves or deletes a note, here or on the site.
 */
(function () {
	'use strict';

	var F = window.FeedbackPins;
	var CONFIG = window.FEEDBACK_PINS || {};
	if (!F) return;

	var el = F.el;
	var t = F.t;
	var store = F.store;
	var root = document.getElementById('fpins-board');

	var COLUMNS = [
		{ key: 'open', title: t('To do'), color: '#714edd' },
		{ key: 'in_progress', title: t('In progress'), color: '#c2410c' },
		{ key: 'fixed', title: t('Done'), color: '#15803d' }
	];
	var SEVERITY_COLOR = { blocker: '#dc2626', major: '#c2410c', minor: '#a16207', nice: '#7c3aed' };

	var filters = { page: 'all', author: 'all', severity: 'all' };
	var dragging = null; // id of the card being dragged

	// The key may come with the address, as on the site: store it, wipe it.
	F.takeKeyFromUrl();

	function sortNotes(a, b) {
		var s = F.SEVERITY_ORDER.indexOf(a.severity) - F.SEVERITY_ORDER.indexOf(b.severity);
		return s || (b.createdAt || '').localeCompare(a.createdAt || '');
	}

	function unique(list, field) {
		var seen = {};
		list.forEach(function (n) {
			if (n[field]) seen[n[field]] = true;
		});
		return Object.keys(seen).sort();
	}

	function select(value, options, onChange, label) {
		var s = el('select', { class: 'fpins-select', 'aria-label': label, onchange: function () { onChange(s.value); } });
		options.forEach(function (o) {
			s.appendChild(el('option', { value: o[0], text: o[1] }));
		});
		s.value = value;
		return s;
	}

	function filtered() {
		return store.notes().filter(function (n) {
			return (filters.page === 'all' || n.url === filters.page) &&
				(filters.author === 'all' || (n.author || '') === filters.author) &&
				(filters.severity === 'all' || n.severity === filters.severity);
		});
	}

	/**
	 * Pin number, computed as on the page: creation order among the notes of
	 * the same page. "#3" here is pin 3 over there.
	 */
	function number(n) {
		return store.notes().filter(function (x) {
			var a = x.createdAt || '';
			var b = n.createdAt || '';
			return x.url === n.url && (a < b || (a === b && x.id <= n.id));
		}).length;
	}

	// Notes store the full path (`/blog/about/` on a site living in `/blog/`):
	// the origin is all that is missing.
	function pageLink(n) {
		return window.location.origin + n.url +(n.url.indexOf('?') === -1 ? '?' : '&') + 'fpins=1&fpins_note=' + n.id;
	}

	function zoom(n) {
		var d = F.dialog('#' + number(n) + ' · ' + n.url, el('div', {}, [
			el('img', { class: 'fpins-screenshot-large', src: n.screenshot, alt: t('Screenshot taken with the note') }),
			el('p', { class: 'fpins-description', text: n.description })
		]), [
			el('button', { type: 'button', class: 'fpins-btn', text: t('Close'), onclick: function () { d.close(); } }),
			el('a', { class: 'fpins-btn fpins-btn-primary', href: pageLink(n), target: '_blank', rel: 'noopener', text: t('View on the page →') })
		]);
		d.box.classList.add('fpins-dialog-screenshot');
	}

	function columnOf(n) {
		return n.status === 'wontfix' ? 'fixed' : n.status;
	}

	/* ── Rendering ──────────────────────────────────────────────────────────── */

	function render() {
		if (dragging) return; // never move the card under the cursor
		var all = store.notes();
		var st = store.state();
		var count = { open: 0, in_progress: 0, fixed: 0 };
		all.forEach(function (n) {
			count[columnOf(n)] = (count[columnOf(n)] || 0) + 1;
		});

		var visible = filtered();

		root.textContent = '';
		var wrap = el('div', { class: 'fpins-wrap' });
		root.appendChild(wrap);

		wrap.appendChild(el('header', { class: 'fpins-head' }, [
			el('div', {}, [
				el('h1', { text: t('Feedback board') }),
				el('p', { class: 'fpins-summary' }, [
					t('%1$d to do · %2$d in progress · %3$d done.', count.open, count.in_progress, count.fixed) + ' ',
					t('Notes are shared: what one person pins shows up for everyone else, without reloading.')
				])
			]),
			el('div', { class: 'fpins-tools' }, [
				el('span', { class: 'fpins-sync' + (st.ok ? '' : ' fpins-sync-ko'), role: 'img', title: st.ok ? t('In sync with the team') : t('Sync failed: %s', st.message), 'aria-label': st.ok ? t('In sync with the team') : t('Sync failed: %s', st.message) }),
				el('button', { type: 'button', class: 'fpins-btn', text: store.author() ? t('You are %s', store.author()) : t('Sign in'), onclick: function () { F.identify(render); } }),
				el('button', { type: 'button', class: 'fpins-btn fpins-btn-dark', text: t('Export'), disabled: !all.length, onclick: exportNotes }),
				el('a', { class: 'fpins-btn fpins-btn-primary', href: CONFIG.home + (CONFIG.home.indexOf('?') === -1 ? '?' : '&') + 'fpins=1', target: '_blank', rel: 'noopener', text: t('Review the site →') })
			])
		]));

		if (!st.ok && st.loaded) {
			wrap.appendChild(el('p', { class: 'fpins-alert', role: 'alert', text: t('Sync failed: %s', st.message) + (store.key() ? '' : ' ' + t('Click “Sign in” and enter the key that came with your review link.')) }));
		}

		var pages = unique(all, 'url');
		var authors = unique(all, 'author');
		wrap.appendChild(el('div', { class: 'fpins-filters' }, [
			select(filters.page, [['all', t('All pages')]].concat(pages.map(function (p) { return [p, p]; })), function (v) { filters.page = v; render(); }, t('Filter by page')),
			select(filters.author, [['all', t('Everyone')]].concat(authors.map(function (a) { return [a, a]; })), function (v) { filters.author = v; render(); }, t('Filter by person')),
			select(filters.severity, [['all', t('All severities')]].concat(F.SEVERITY_ORDER.map(function (s) { return [s, F.severityLabel(s)]; })), function (v) { filters.severity = v; render(); }, t('Filter by severity'))
		]));

		var grid = el('div', { class: 'fpins-columns' });
		wrap.appendChild(grid);

		COLUMNS.forEach(function (col) {
			var notes = visible.filter(function (n) {
				return columnOf(n) === col.key;
			}).sort(sortNotes);

			var cards = el('div', { class: 'fpins-cards' });
			if (!notes.length) {
				cards.appendChild(el('p', { class: 'fpins-empty', text: col.key === 'open' ? t('Nothing to do. Add a note (+) or pin one right on the site.') : '·' }));
			}
			notes.forEach(function (n) {
				cards.appendChild(card(n));
			});

			var column = el('section', { class: 'fpins-column', 'aria-label': col.title }, [
				el('div', { class: 'fpins-column-head' }, [
					el('span', { class: 'fpins-dot', style: 'background:' + col.color }),
					el('strong', { text: col.title }),
					el('span', { text: String(notes.length) }),
					col.key === 'open' ? el('button', { type: 'button', class: 'fpins-plus', title: t('Add a note'), 'aria-label': t('Add a note'), text: '+', onclick: add }) : null
				]),
				cards
			]);

			column.addEventListener('dragover', function (e) {
				e.preventDefault();
				column.classList.add('fpins-column-over');
			});
			column.addEventListener('dragleave', function (e) {
				if (!column.contains(e.relatedTarget)) column.classList.remove('fpins-column-over');
			});
			column.addEventListener('drop', function (e) {
				e.preventDefault();
				var id = Number(e.dataTransfer.getData('text/plain') || dragging);
				dragging = null;
				var n = store.notes().filter(function (x) { return x.id === id; })[0];
				if (n && columnOf(n) !== col.key) move(id, col.key);
				else render();
			});

			grid.appendChild(column);
		});

		if (CONFIG.credit) {
			wrap.appendChild(el('p', { class: 'fpins-credit' }, [
				t('Feedback Pins, a free plugin by'),
				el('a', { href: CONFIG.credit, target: '_blank', rel: 'noopener' }, [
					el('img', { src: CONFIG.logo, width: '105', height: '24', alt: 'AVANGARDISM' })
				])
			]));
		}
	}

	function card(n) {
		var closed = F.isClosed(n);
		var c = el('article', { class: 'fpins-card fpins-edge-' + n.severity + (closed ? ' fpins-card-closed' : ''), draggable: 'true' }, [
			el('div', { class: 'fpins-card-head' }, [
				el('span', { class: 'fpins-badge', style: 'background:' + SEVERITY_COLOR[n.severity], text: F.severityLabel(n.severity) }),
				n.status === 'wontfix' ? el('span', { class: 'fpins-status fpins-status-wontfix', text: F.statusLabel('wontfix') }) : null,
				el('span', { class: 'fpins-number', title: t('Pin number on the page'), text: '#' + number(n) }),
				el('a', { href: pageLink(n), target: '_blank', rel: 'noopener', title: t('Open the page at this spot') + (n.pageTitle ? ' · ' + n.pageTitle : ''), text: n.url }),
				n.sectionLabel ? el('span', { class: 'fpins-meta', style: 'margin:0', text: '· ' + n.sectionLabel }) : null
			]),
			el('div', { class: 'fpins-description', text: n.description }),
			n.screenshot ? el('button', { type: 'button', class: 'fpins-thumb', title: t('Enlarge the screenshot'), onclick: function () { zoom(n); } }, [
				el('img', { src: n.screenshot, alt: t('Screenshot of note #%d', number(n)), loading: 'lazy', draggable: 'false' })
			]) : null,
			n.fixNote ? el('div', { class: 'fpins-answer' }, [el('strong', { text: t('Answer:') + ' ' }), n.fixNote]) : null,
			el('a', { class: 'fpins-view', href: pageLink(n), target: '_blank', rel: 'noopener', text: t('View on the page →') }),
			el('span', { class: 'fpins-meta' }, [
				el('span', { class: 'fpins-author', text: n.author || t('Anonymous') }),
				' · ' + F.since(n.createdAt)
			]),
			el('div', { class: 'fpins-card-foot' }, COLUMNS.map(function (col) {
				var active = columnOf(n) === col.key;
				return el('button', {
					type: 'button',
					class: 'fpins-move',
					'aria-pressed': active ? 'true' : 'false',
					style: active ? 'background:' + col.color + ';border-color:' + col.color : null,
					title: t('Move to %s', col.title),
					text: col.title,
					onclick: function () { if (!active) move(n.id, col.key); }
				});
			}).concat([
				el('button', { type: 'button', class: 'fpins-move', title: t('Add or edit an answer'), text: n.fixNote ? t('Edit the answer') : t('Answer'), onclick: function () { answer(n); } }),
				el('button', { type: 'button', class: 'fpins-btn fpins-btn-small fpins-btn-danger fpins-delete', title: t('Delete'), 'aria-label': t('Delete the note'), text: '✕', onclick: function () { remove(n); } })
			]))
		]);
		c.addEventListener('dragstart', function (e) {
			dragging = n.id;
			e.dataTransfer.setData('text/plain', String(n.id));
			e.dataTransfer.effectAllowed = 'move';
		});
		c.addEventListener('dragend', function () {
			dragging = null;
			render();
		});
		return c;
	}

	/* ── Actions ────────────────────────────────────────────────────────────── */

	function failed(message) {
		return function (err) {
			F.toast(t(message, err.message), true);
		};
	}

	function move(id, status) {
		var column = COLUMNS.filter(function (c) { return c.key === status; })[0];
		if (column) F.announce(t('Moved to %s', column.title));
		store.update(id, { status: status }).catch(failed('Could not move: %s'));
	}

	function remove(n) {
		if (!window.confirm(t('Delete this note for the whole team?'))) return;
		store.remove(n.id).catch(failed('Could not delete: %s'));
	}

	function answer(n) {
		var field = el('textarea', { class: 'fpins-field', rows: '4', 'aria-label': t('Answer the note'), placeholder: t('e.g. fixed, live in 3 minutes · or: won’t fix, because…') });
		field.value = n.fixNote || '';
		var d = F.dialog(t('Answer the note'), el('div', {}, [
			el('p', { class: 'fpins-help', text: '“' + n.description.slice(0, 160) + (n.description.length > 160 ? '…' : '') + '”' }),
			field
		]), [
			el('button', { type: 'button', class: 'fpins-btn', text: t('Cancel'), onclick: function () { d.close(); } }),
			el('button', { type: 'button', class: 'fpins-btn fpins-btn-primary', text: t('Save'), onclick: function () {
				d.close();
				store.update(n.id, { fixNote: field.value.trim() }).catch(failed('Could not save: %s'));
			} })
		]);
	}

	function add() {
		if (!store.key() || !store.author()) {
			F.identify(add);
			return;
		}
		var severity = 'major';
		var text = el('textarea', { class: 'fpins-field', rows: '3', 'aria-label': t('Your note'), placeholder: t('e.g. align the spacing between the result cards') });
		var page = el('input', { class: 'fpins-field', type: 'text', id: 'fpins-add-page', value: filters.page !== 'all' ? filters.page : '/', placeholder: '/' });
		var section = el('input', { class: 'fpins-field', type: 'text', id: 'fpins-add-section', placeholder: t('e.g. Footer') });
		var chips = el('div', { class: 'fpins-chips', role: 'group', 'aria-label': t('Severity') });
		F.SEVERITY_ORDER.forEach(function (s) {
			var b = el('button', { type: 'button', class: 'fpins-chip fpins-chip-' + s, text: F.severityLabel(s), onclick: function () { severity = s; update(); } });
			b.dataset.sev = s;
			chips.appendChild(b);
		});
		function update() {
			Array.prototype.forEach.call(chips.children, function (b) {
				var active = b.dataset.sev === severity;
				b.classList.toggle('fpins-chip-active', active);
				b.setAttribute('aria-pressed', active ? 'true' : 'false');
			});
		}
		update();
		var button = el('button', { type: 'button', class: 'fpins-btn fpins-btn-primary', text: t('Add'), onclick: function () {
			if (!text.value.trim()) {
				text.focus();
				return;
			}
			button.disabled = true;
			store.create({
				description: text.value.trim(),
				severity: severity,
				url: page.value.trim() || '/',
				sectionLabel: section.value.trim()
			}).then(function () {
				d.close();
			}).catch(function (err) {
				button.disabled = false;
				failed('Could not add: %s')(err);
			});
		} });
		var d = F.dialog(t('New “To do” note'), el('div', {}, [
			text,
			el('p', { class: 'fpins-label', text: t('Severity') }),
			chips,
			el('label', { class: 'fpins-label', for: 'fpins-add-page', text: t('Page (path)') }),
			page,
			el('label', { class: 'fpins-label', for: 'fpins-add-section', text: t('Section (optional)') }),
			section
		]), [
			el('button', { type: 'button', class: 'fpins-btn', text: t('Cancel'), onclick: function () { d.close(); } }),
			button
		]);
	}

	/* ── Export: Markdown grouped by page then section, ready to paste ──────── */

	function toMarkdown(notes) {
		var lines = ['# ' + t('Feedback · %d note(s)', notes.length), '_' + t('exported %s', new Date().toISOString()) + '_', ''];
		var byPage = {};
		notes.forEach(function (n) {
			(byPage[n.url] = byPage[n.url] || []).push(n);
		});
		Object.keys(byPage).sort().forEach(function (url) {
			var list = byPage[url];
			lines.push('## ' + url + (list[0].pageTitle ? '  ·  ' + list[0].pageTitle : ''), '');
			var bySection = {};
			list.forEach(function (n) {
				var s = n.sectionLabel || t('(no section)');
				(bySection[s] = bySection[s] || []).push(n);
			});
			Object.keys(bySection).forEach(function (s) {
				lines.push('### ' + s, '');
				bySection[s].sort(sortNotes).forEach(function (n) {
					lines.push('- **[' + F.severityLabel(n.severity).toUpperCase() + '] [' + F.statusLabel(n.status) + ']** ' + n.description.replace(/\n/g, '\n    ') + (n.author ? ' _(' + n.author + ')_' : ''));
					lines.push('    - ' + t('pin #%d', number(n)) + ': ' + pageLink(n));
					if (n.screenshot) lines.push('    - ' + t('screenshot') + ': ' + n.screenshot);
					if (n.selector) lines.push('    - ' + t('selector') + ': `' + n.selector + '`');
					if (n.viewportWidth) lines.push('    - ' + t('screen') + ': ' + n.viewportWidth + '×' + n.viewportHeight);
					if (n.fixNote) lines.push('    - ' + t('answer') + ': ' + n.fixNote);
					lines.push('    - id: `' + n.id + '`');
				});
				lines.push('');
			});
		});
		return lines.join('\n');
	}

	function exportNotes() {
		var format = 'markdown';
		var notes = filtered();
		var area = el('textarea', { class: 'fpins-field fpins-export', readonly: true, 'aria-label': t('Export') });
		var toggle = el('div', { class: 'fpins-chips' });
		function content() {
			return format === 'json' ? JSON.stringify({ exportedAt: new Date().toISOString(), notes: notes }, null, 2) : toMarkdown(notes);
		}
		function update() {
			area.value = content();
			Array.prototype.forEach.call(toggle.children, function (b) {
				b.classList.toggle('fpins-chip-active', b.dataset.f === format);
				b.setAttribute('aria-pressed', b.dataset.f === format ? 'true' : 'false');
			});
		}
		[['markdown', 'Markdown'], ['json', 'JSON']].forEach(function (f) {
			var b = el('button', { type: 'button', class: 'fpins-chip fpins-chip-nice', text: f[1], onclick: function () { format = f[0]; update(); } });
			b.dataset.f = f[0];
			toggle.appendChild(b);
		});
		update();
		var d = F.dialog(t('Export %d note(s)', notes.length), el('div', {}, [
			el('p', { class: 'fpins-help', text: t('Follows the current filters. Grouped by page then section: paste it as is into a ticket or an AI assistant.') }),
			toggle,
			area
		]), [
			el('button', { type: 'button', class: 'fpins-btn', text: t('Download'), onclick: function () {
				var blob = new Blob([content()], { type: format === 'json' ? 'application/json' : 'text/markdown' });
				var link = el('a', { href: URL.createObjectURL(blob), download: 'feedback-' + new Date().toISOString().slice(0, 10) + (format === 'json' ? '.json' : '.md') });
				link.click();
				window.setTimeout(function () { URL.revokeObjectURL(link.href); }, 1000);
			} }),
			el('button', { type: 'button', class: 'fpins-btn fpins-btn-primary', text: t('Copy'), onclick: function () {
				navigator.clipboard.writeText(content()).then(function () {
					F.toast(t('Copied.'));
				}, function () {
					area.select();
					F.toast(t('The browser refused to copy: the text is selected, press ⌘/Ctrl + C.'), true);
				});
			} })
		]);
		d.box.classList.add('fpins-dialog-wide');
	}

	store.subscribe(render);
	render();
	store.start();
	if (!store.key() || !store.author()) F.identify(render);
})();
