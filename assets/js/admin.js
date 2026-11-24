/* Mosh AI admin app. No dependencies. */
(function () {
	'use strict';
	var A = window.MoshCWAdmin || {};
	var app = document.querySelector('.moshcw-app');
	if (!app) { return; }

	/* ---------- Helpers ---------- */

	function api(path, data) {
		return fetch(A.rest + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': A.nonce },
			body: JSON.stringify(data || {})
		}).then(function (r) {
			return r.json().catch(function () { return {}; }).then(function (j) {
				if (!r.ok) { throw new Error(j && j.message ? j.message : 'Request failed (' + r.status + ')'); }
				return j;
			});
		});
	}
	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}
	function $(sel, ctx) { return (ctx || document).querySelector(sel); }
	function $$(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }
	function collect(box) {
		var o = {};
		$$('[name]', box).forEach(function (f) {
			if (f.closest('[data-ignore]')) { return; }
			o[f.name] = f.type === 'checkbox' ? (f.checked ? 1 : 0) : f.value;
		});
		return o;
	}
	function fill(box, data) {
		$$('[name]', box).forEach(function (f) {
			var v = data[f.name];
			if (f.type === 'checkbox') { f.checked = !!(+v); }
			else { f.value = v == null ? '' : v; }
		});
		syncModelLists(box);
	}
	function busy(btn, on, label) {
		if (!btn) { return; }
		if (on) { btn.dataset.label = btn.textContent; btn.textContent = label || 'Working…'; btn.disabled = true; btn.classList.add('is-busy'); }
		else { btn.textContent = btn.dataset.label || btn.textContent; btn.disabled = false; btn.classList.remove('is-busy'); }
	}
	var toastEl = $('.mx-toast', app), toastTimer;
	function toast(msg, type) {
		toastEl.textContent = msg;
		toastEl.className = 'mx-toast' + (type ? ' is-' + type : '');
		toastEl.hidden = false;
		clearTimeout(toastTimer);
		toastTimer = setTimeout(function () { toastEl.hidden = true; }, type === 'error' ? 6000 : 2600);
	}
	function out(box, html, isError) {
		var o = $('.moshcw-output', box);
		if (!o) { return; }
		o.innerHTML = html;
		o.classList.toggle('is-error', !!isError);
		o.hidden = false;
	}
	function fail(box) { return function (e) { if (box) { out(box, esc(e.message), true); } toast(e.message, 'error'); }; }
	function usage(r) { return (r.tokens_in || r.tokens_out) ? (r.tokens_in || 0) + ' in · ' + (r.tokens_out || 0) + ' out tokens' : ''; }
	function syncModelLists(scope) {
		$$('[data-moshcw-provider]', scope).forEach(function (sel) {
			var holder = sel.closest('.mx-grid, .mx-row, [data-fields], .moshcw-tool') || scope;
			var input = $('[data-moshcw-model]', holder);
			if (input) { input.setAttribute('list', 'moshcw-models-' + (sel.value || A.defaultProvider)); }
		});
	}

	/* ---------- Tabs ---------- */

	function showTab(tab, sub) {
		if (!$('[data-panel="' + tab + '"]', app)) { tab = 'home'; }
		$$('.mx-panel', app).forEach(function (p) { p.hidden = p.getAttribute('data-panel') !== tab; });
		$$('.mx-tab', app).forEach(function (t) { t.classList.toggle('is-active', t.getAttribute('data-go') === tab); });
		var slug = A.slugs[tab] || 'moshcw';
		if (window.history && history.replaceState) { history.replaceState(null, '', A.adminUrl + '?page=' + slug + (sub ? '#' + sub : '')); }
		// Keep the WordPress sidebar in sync.
		$$('#toplevel_page_moshcw .wp-submenu li').forEach(function (li) {
			var a = $('a', li);
			li.classList.toggle('current', !!a && /[?&]page=([^&#]+)/.test(a.getAttribute('href')) && RegExp.$1 === slug);
		});
		if (sub && tab === 'studio') { showSub(sub); }
		if (sub && tab === 'settings') { var t = document.getElementById('mx-s-' + sub); if (t) { t.scrollIntoView({ behavior: 'smooth' }); } }
		window.scrollTo({ top: 0 });
	}
	document.addEventListener('click', function (e) {
		var go = e.target.closest && e.target.closest('[data-go]');
		if (!go || e.metaKey || e.ctrlKey) { return; }
		e.preventDefault();
		var parts = go.getAttribute('data-go').split(':');
		showTab(parts[0], parts[1]);
	});
	// Sidebar submenu links switch tabs without reloading.
	$$('#toplevel_page_moshcw .wp-submenu a').forEach(function (a) {
		a.addEventListener('click', function (e) {
			var m = /[?&]page=([^&#]+)/.exec(a.getAttribute('href') || '');
			if (!m) { return; }
			for (var k in A.slugs) { if (A.slugs[k] === m[1]) { e.preventDefault(); showTab(k); return; } }
		});
	});
	var hash = (location.hash || '').slice(1);
	if (hash) { showTab(A.tab, hash); }

	/* ---------- Global behaviours ---------- */

	document.addEventListener('change', function (e) {
		if (e.target.matches && e.target.matches('[data-moshcw-provider]')) { syncModelLists(e.target.closest('.mx-panel') || document); }
	});
	document.addEventListener('click', function (e) {
		var c = e.target.closest && e.target.closest('.moshcw-copy');
		if (!c || !navigator.clipboard) { return; }
		navigator.clipboard.writeText(c.textContent.trim()).then(function () { toast('Copied'); });
	});
	// Instant settings: save as soon as they change.
	$$('[data-instant]', app).forEach(function (f) {
		f.addEventListener('change', function () {
			var data = {};
			data[f.name] = f.type === 'checkbox' ? (f.checked ? 1 : 0) : f.value;
			api('admin/settings', data).then(function (r) {
				if ('popup_bot' in data) { A.popupBot = r.popup_bot; $$('[data-popup-select]').forEach(function (s) { s.value = r.popup_bot; }); }
				toast('Saved');
			}).catch(fail());
		});
	});
	syncModelLists(app);

	/* ---------- Home: one-click connect ---------- */

	var connect = $('[data-connect]', app);
	if (connect) {
		connect.addEventListener('click', function () {
			var box = $('[data-connect-box]', app);
			var d = collect(box);
			if (!d.key) { toast('Paste your key first', 'error'); return; }
			busy(connect, true, 'Checking…');
			api('admin/test-key', { provider: d.provider, key: d.key, save: 1, make_default: 1 }).then(function () {
				toast('Connected. Reloading…', 'success');
				setTimeout(function () { location.reload(); }, 700);
			}).catch(function (e) { busy(connect, false); toast(e.message, 'error'); });
		});
	}

	/* ---------- Settings ---------- */

	$$('[data-settings]', app).forEach(function (settings) {
		var bar = $('[data-savebar]', settings);
		var initial = JSON.stringify(collect(settings));
		var markDirty = function () { bar.hidden = JSON.stringify(collect(settings)) === initial; };
		settings.addEventListener('input', markDirty);
		settings.addEventListener('change', markDirty);
		$('[data-discard]', settings).addEventListener('click', function () { location.reload(); });

		var applyKeys = function (keys) {
			$$('.mx-key', settings).forEach(function (card) {
				var p = card.getAttribute('data-provider');
				var k = keys[p] || {};
				var badge = $('[data-key-status]', card);
				var input = $('[data-key-input]', card);
				var rm = $('[data-remove-key]', card);
				var on = !!(k.connected || k.mask);
				badge.textContent = on ? 'Connected' : 'Not set';
				badge.className = 'mx-badge ' + (on ? 'is-on' : 'is-off');
				if (input) { input.value = ''; input.placeholder = k.mask || (p === 'custom' ? 'API key (leave empty for Ollama / LM Studio)' : 'Paste your key'); }
				if (rm) { rm.hidden = !on; }
			});
		};
		var saveBtn = $('[data-save-settings]', settings);
		var save = function () {
			var data = collect(settings);
			Object.keys(data).forEach(function (k) { if (/_key$/.test(k) && data[k] === '') { delete data[k]; } });
			busy(saveBtn, true, 'Saving…');
			return api('admin/settings', data).then(function (r) {
				applyKeys(r.keys);
				initial = JSON.stringify(collect(settings));
				bar.hidden = true;
				toast('Settings saved', 'success');
				// Locks and the header depend on which AI is connected.
				if (typeof r.has_ai !== 'undefined' && r.has_ai !== A.hasAI) { setTimeout(function () { location.reload(); }, 900); }
			}).catch(fail()).then(function () { busy(saveBtn, false); });
		};
		saveBtn.addEventListener('click', save);
		document.addEventListener('keydown', function (e) {
			if ((e.ctrlKey || e.metaKey) && e.key === 's' && !settings.closest('.mx-panel').hidden) { e.preventDefault(); save(); }
		});

		$$('[data-test-key]', settings).forEach(function (btn) {
			btn.addEventListener('click', function () {
				var p = btn.getAttribute('data-test-key');
				var card = btn.closest('.mx-key');
				var input = $('[data-key-input]', card);
				var payload = { provider: p, key: input ? input.value : '', save: input && input.value ? 1 : 0 };
				if (p === 'custom') {
					payload.url = $('[name=custom_url]', card).value;
					payload.model = $('[name=custom_model]', card).value;
					payload.name = $('[name=custom_name]', card).value;
					payload.save = 1;
				}
				busy(btn, true, 'Testing…');
				api('admin/test-key', payload).then(function (r) {
					applyKeys(r.keys);
					var dl = document.getElementById('moshcw-models-' + p);
					if (dl && r.models) { dl.innerHTML = r.models.map(function (m) { return '<option value="' + esc(m) + '"></option>'; }).join(''); }
					markDirty();
					toast('Key works · ' + r.count + ' models available', 'success');
				}).catch(fail()).then(function () { busy(btn, false); });
			});
		});
		$$('[data-remove-key]', settings).forEach(function (btn) {
			btn.addEventListener('click', function () {
				if (!window.confirm('Remove this API key?')) { return; }
				var data = {};
				data[btn.getAttribute('data-remove-key') + '_key'] = '';
				if (btn.getAttribute('data-remove-key') === 'custom') { data.custom_url = ''; }
				api('admin/settings', data).then(function (r) { applyKeys(r.keys); toast('Key removed'); }).catch(fail());
			});
		});
		// Side nav scroll + highlight.
		$$('.mx-side a', settings).forEach(function (a) {
			a.addEventListener('click', function (e) {
				e.preventDefault();
				var t = $(a.getAttribute('href'));
				if (t) { t.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
			});
		});
	});

	/* ---------- Chatbots + forms editors ---------- */

	function parseFields(text) {
		return String(text || '').split(/\r?\n/).map(function (l) { return l.trim(); }).filter(Boolean).map(function (l) {
			var p = l.split('|').map(function (x) { return x.trim(); });
			return { name: p[0].toLowerCase().replace(/[^a-z0-9_\-]/g, ''), label: p[1] || p[0], type: (p[2] || 'text').toLowerCase(), options: p[3] ? p[3].split(',').map(function (o) { return o.trim(); }).filter(Boolean) : [] };
		}).filter(function (f) { return f.name; });
	}

	var editors = {
		bots: {
			items: function () { return A.bots; },
			set: function (v) { A.bots = v; },
			defaults: A.botDefaults,
			save: 'admin/bot/save',
			del: 'admin/bot/delete',
			noun: 'chatbot',
			shortcode: function (id) { return '[mosh_ai_chatbot id="' + id + '"]'; },
			extra: function (item) { return { site_popup: A.popupBot === item.id ? 1 : 0 }; },
			badge: function (item) { return A.popupBot === item.id ? '<span class="mx-badge is-on">bubble</span>' : ''; },
			preview: function (item) {
				var pub = { id: item.id, title: item.title, start: item.start, placeholder: item.placeholder, send: item.send, max_input: +item.max_input, popup: false };
				return '<div class="moshcw-chatbot moshcw-inline" data-moshcw="' + esc(JSON.stringify(pub)) + '" style="--moshcw-color:' + esc(item.color) + ';--moshcw-on:' + onColor(item.color) + '"></div>';
			},
			after: function (r) { A.popupBot = r.popup; $$('[data-popup-select]').forEach(function (s) { s.value = r.popup || ''; }); }
		},
		forms: {
			items: function () { return A.forms; },
			set: function (v) { A.forms = v; },
			defaults: A.formDefaults,
			save: 'admin/form/save',
			del: 'admin/form/delete',
			noun: 'form',
			shortcode: function (id) { return '[mosh_ai_form id="' + id + '"]'; },
			extra: function () { return {}; },
			badge: function () { return ''; },
			preview: function (item) {
				var h = '<form class="moshcw-form" data-form="' + esc(item.id) + '">';
				parseFields(item.fields).forEach(function (f) {
					h += '<p class="moshcw-field"><label>' + esc(f.label) + '</label>';
					if (f.type === 'textarea') { h += '<textarea name="' + esc(f.name) + '" rows="3" required></textarea>'; }
					else if (f.type === 'select') { h += '<select name="' + esc(f.name) + '">' + f.options.map(function (o) { return '<option>' + esc(o) + '</option>'; }).join('') + '</select>'; }
					else { h += '<input type="' + esc(['number', 'email', 'url'].indexOf(f.type) > -1 ? f.type : 'text') + '" name="' + esc(f.name) + '" required>'; }
					h += '</p>';
				});
				return h + '<p><button type="submit" class="moshcw-form-submit">' + esc(item.button || 'Generate') + '</button></p><div class="moshcw-form-output" hidden></div></form>';
			},
			after: function () {}
		}
	};

	$$('[data-editor]', app).forEach(function (root) {
		var cfg = editors[root.getAttribute('data-editor')];
		if (!cfg) { return; }
		var list = $('[data-list]', root), fields = $('[data-fields]', root), preview = $('[data-preview]', root);
		var current = null;

		function renderList() {
			var items = cfg.items();
			list.innerHTML = Object.keys(items).map(function (id) {
				return '<li><button type="button" data-pick="' + esc(id) + '" class="' + (current === id ? 'is-active' : '') + '"><strong>' + esc(items[id].name || id) + '</strong>' + cfg.badge(items[id]) + '<small>' + esc(id) + '</small></button></li>';
			}).join('') || '<li class="mx-help">Nothing yet. Click + New.</li>';
		}
		function renderPreview(item) {
			if (!item) { preview.innerHTML = '<p class="mx-help">Save to try it here.</p>'; return; }
			preview.innerHTML = cfg.preview(item);
			if (window.MoshCW) { window.MoshCW.boot(); }
		}
		function pick(id) {
			var items = cfg.items();
			var isNew = !id || !items[id];
			current = isNew ? null : id;
			var item = isNew ? Object.assign({}, cfg.defaults, { id: '', name: '' }) : items[id];
			fill(fields, Object.assign({}, item, cfg.extra(item), { slug: '' }));
			root.classList.toggle('is-new', isNew);
			$('[data-edit-title]', root).textContent = isNew ? 'New ' + cfg.noun : (item.name || id);
			var sc = $('[data-shortcode]', root);
			sc.textContent = isNew ? '' : cfg.shortcode(id);
			sc.hidden = isNew;
			$('[data-delete]', root).hidden = isNew || id === 'default';
			renderList();
			renderPreview(isNew ? null : item);
		}
		list.addEventListener('click', function (e) {
			var b = e.target.closest('[data-pick]');
			if (b) { pick(b.getAttribute('data-pick')); }
		});
		$('[data-new]', root).addEventListener('click', function () { pick(null); $('[name="name"]', fields).focus(); });
		var saveBtn = $('[data-save]', root);
		saveBtn.addEventListener('click', function () {
			var data = collect(fields);
			if (!data.name) { toast('Give it a name', 'error'); return; }
			busy(saveBtn, true, 'Saving…');
			api(cfg.save, data).then(function (r) {
				cfg.set(r.bots || r.forms);
				cfg.after(r);
				pick((r.bot || r.form).id);
				toast('Saved', 'success');
			}).catch(fail()).then(function () { busy(saveBtn, false); });
		});
		$('[data-delete]', root).addEventListener('click', function () {
			if (!current || !window.confirm('Delete this ' + cfg.noun + '?')) { return; }
			api(cfg.del, { id: current }).then(function (r) {
				cfg.set(r.bots || r.forms);
				cfg.after(r);
				var ids = Object.keys(cfg.items());
				pick(ids[0] || null);
				toast('Deleted');
			}).catch(fail());
		});
		var first = Object.keys(cfg.items())[0];
		pick(first || null);
	});

	/* ---------- Studio ---------- */

	function showSub(sub) {
		var studio = $('[data-panel="studio"]', app);
		if (!studio || !$('[data-subpanel="' + sub + '"]', studio)) { return; }
		$$('[data-subpanel]', studio).forEach(function (p) { p.hidden = p.getAttribute('data-subpanel') !== sub; });
		$$('[data-sub]', studio).forEach(function (b) { b.classList.toggle('is-active', b.getAttribute('data-sub') === sub); });
	}
	$$('[data-sub]', app).forEach(function (b) { b.addEventListener('click', function () { showSub(b.getAttribute('data-sub')); }); });

	var tools = {
		playground: function (box) {
			var btn = $('[data-run]', box);
			var run = function () {
				busy(btn, true, 'Thinking…');
				$('.moshcw-meta', box).textContent = '';
				api('admin/generate', collect(box)).then(function (r) {
					out(box, '<pre class="mx-pre">' + esc(r.text) + '</pre><button type="button" class="button button-small mx-copy-out">Copy</button>');
					$('.mx-copy-out', box).addEventListener('click', function () { navigator.clipboard.writeText(r.text); toast('Copied'); });
					$('.moshcw-meta', box).textContent = usage(r);
				}).catch(fail(box)).then(function () { busy(btn, false); });
			};
			btn.addEventListener('click', run);
			$('[name="prompt"]', box).addEventListener('keydown', function (e) { if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') { run(); } });
		},
		content: function (box) {
			var btn = $('[data-run]', box), res = $('.moshcw-content-result', box), preview = $('.moshcw-preview', box);
			var f = function (n) { return $('[data-field="' + n + '"]', box); };
			btn.addEventListener('click', function () {
				var data = collect(box);
				if (!data.topic) { toast('Give it a topic', 'error'); return; }
				busy(btn, true, 'Writing… (up to a minute)');
				$('.moshcw-output', box).hidden = true;
				api('admin/content', data).then(function (r) {
					f('title').value = r.title || '';
					f('excerpt').value = r.excerpt || '';
					f('content').value = r.content || '';
					preview.innerHTML = r.content || '';
					res.hidden = false;
					$('.moshcw-meta', box).textContent = usage(r);
				}).catch(fail(box)).then(function () { busy(btn, false); });
			});
			f('content').addEventListener('input', function () { preview.innerHTML = f('content').value; });
			$$('[data-save-draft]', box).forEach(function (b) {
				b.addEventListener('click', function () {
					busy(b, true, 'Saving…');
					api('admin/create-post', { title: f('title').value, excerpt: f('excerpt').value, content: f('content').value, post_type: b.getAttribute('data-save-draft') }).then(function (r) {
						$('.moshcw-saved', box).innerHTML = 'Draft saved. <a href="' + esc(r.edit) + '">Open it &rarr;</a>';
						toast('Draft saved', 'success');
					}).catch(fail(box)).then(function () { busy(b, false); });
				});
			});
		},
		image: function (box) {
			var btn = $('[data-run]', box), gallery = $('.moshcw-gallery', box);
			btn.addEventListener('click', function () {
				var data = collect(box);
				if (!data.prompt) { toast('Describe the image first', 'error'); return; }
				busy(btn, true, 'Painting… (20-60s)');
				$('.moshcw-output', box).hidden = true;
				api('admin/image', data).then(function (r) {
					var fig = document.createElement('figure');
					fig.className = 'mx-image';
					fig.innerHTML = '<a href="' + esc(r.edit) + '"><img src="' + esc(r.url) + '" alt=""></a><figcaption><a href="' + esc(r.edit) + '">Open in Media Library</a><span class="moshcw-copy">' + esc(r.url) + '</span></figcaption>';
					gallery.insertBefore(fig, gallery.firstChild);
					toast('Saved to Media Library', 'success');
				}).catch(fail(box)).then(function () { busy(btn, false); });
			});
		},
		kb: function (box) {
			var rows = $('.moshcw-kb-rows', box), total = $('.moshcw-kb-total', box);
			function load() {
				api('admin/kb/list').then(function (r) {
					total.textContent = r.total;
					rows.innerHTML = r.items.length ? r.items.map(function (it) {
						return '<tr><td><strong>' + esc(it.title) + '</strong></td><td>' + esc(it.preview) + '…</td><td>' + (+it.post_id ? 'Post #' + esc(it.post_id) : 'Custom') + '</td><td><button type="button" class="button-link button-link-delete" data-kb-del="' + esc(it.id) + '">Delete</button></td></tr>';
					}).join('') : '<tr><td colspan="4">Nothing indexed yet. Hit "Sync all content".</td></tr>';
				}).catch(fail(box));
			}
			rows.addEventListener('click', function (e) {
				var id = e.target.getAttribute('data-kb-del');
				if (id) { api('admin/kb/delete', { id: id }).then(load).catch(fail(box)); }
			});
			var add = $('[data-kb-add]', box);
			add.addEventListener('click', function () {
				busy(add, true);
				api('admin/kb/add', { title: $('[data-kb-title]', box).value, content: $('[data-kb-content]', box).value }).then(function (r) {
					toast('Added ' + r.added + ' chunk(s)', 'success');
					$('[data-kb-title]', box).value = '';
					$('[data-kb-content]', box).value = '';
					load();
				}).catch(fail(box)).then(function () { busy(add, false); });
			});
			var sync = $('[data-kb-sync]', box), prog = $('.moshcw-progress', box), pbar = $('.mx-progressbar', box);
			sync.addEventListener('click', function () {
				busy(sync, true, 'Syncing…');
				pbar.hidden = false;
				var added = 0;
				(function step(offset) {
					api('admin/kb/sync', { offset: offset }).then(function (r) {
						added += r.added;
						var done = Math.min(r.next, r.total);
						prog.textContent = done + ' / ' + r.total + ' items · ' + added + ' chunks';
						$('span', pbar).style.width = (r.total ? Math.round(100 * done / r.total) : 100) + '%';
						if (r.done) { busy(sync, false); toast('Knowledge synced', 'success'); load(); }
						else { step(r.next); }
					}).catch(function (e) { busy(sync, false); fail(box)(e); load(); });
				})(0);
			});
			$('[data-kb-clear]', box).addEventListener('click', function () {
				if (!window.confirm('Delete the whole knowledge base?')) { return; }
				api('admin/kb/clear').then(function () { toast('Knowledge cleared'); load(); }).catch(fail(box));
			});
			var sbtn = $('[data-kb-search]', box);
			sbtn.addEventListener('click', function () {
				busy(sbtn, true);
				api('admin/kb/search', { query: $('[data-kb-query]', box).value }).then(function (r) {
					$('.moshcw-kb-hits', box).innerHTML = r.items.length ? r.items.map(function (h) {
						return '<div class="mx-hit"><strong>' + esc(h.title) + '</strong> <span class="mx-badge">' + Math.round(h.score * 100) + '% match</span><p>' + esc(String(h.content).slice(0, 280)) + '…</p></div>';
					}).join('') : '<p class="mx-help">No relevant content found.</p>';
				}).catch(fail(box)).then(function () { busy(sbtn, false); });
			});
			load();
		}
	};
	$$('.moshcw-tool[data-tool]', app).forEach(function (box) {
		var fn = tools[box.getAttribute('data-tool')];
		if (fn) { fn(box); }
	});

	/* ---------- Activity ---------- */

	$$('[data-clear]', app).forEach(function (b) {
		b.addEventListener('click', function () {
			var what = b.getAttribute('data-clear');
			if (!window.confirm(what === 'logs' ? 'Reset the usage log? Daily limits restart from zero.' : 'Delete all chat conversations?')) { return; }
			api('admin/clear', { what: what }).then(function () { toast('Done'); setTimeout(function () { location.reload(); }, 600); }).catch(fail());
		});
	});

	var preset = $('[data-custom-preset]', app);
	if (preset) {
		preset.addEventListener('change', function () {
			var o = preset.options[preset.selectedIndex];
			if (!o || !o.value) { return; }
			var card = preset.closest('.mx-key');
			$('[name=custom_name]', card).value = o.getAttribute('data-name');
			$('[name=custom_url]', card).value = o.getAttribute('data-url');
			$('[name=custom_model]', card).value = o.getAttribute('data-model');
			card.dispatchEvent(new Event('input', { bubbles: true }));
			toast('Preset filled in. Add the key and hit Test and save.');
		});
	}

	function onColor(hex) {
		hex = String(hex || '').replace('#', '');
		if (hex.length === 3) { hex = hex.split('').map(function (c) { return c + c; }).join(''); }
		if (!/^[0-9a-f]{6}$/i.test(hex)) { return '#fff'; }
		var w = [0.2126, 0.7152, 0.0722], lum = 0;
		for (var i = 0; i < 3; i++) {
			var c = parseInt(hex.substr(i * 2, 2), 16) / 255;
			c = c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
			lum += w[i] * c;
		}
		return lum > 0.35 ? '#000' : '#fff';
	}
})();
