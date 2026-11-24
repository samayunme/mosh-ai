/* Mosh AI assistant box for the block editor, classic editor and WooCommerce products. */
(function () {
	'use strict';
	var E = window.MoshCWEditor || {};

	function ready(fn) {
		if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', fn); } else { fn(); }
	}

	ready(function () {
		var box = document.getElementById('moshcw-assistant-box');
		if (!box) { return; }
		var isBlock = document.body.classList.contains('block-editor-page') && window.wp && wp.data && wp.data.select('core/editor');
		var result = box.querySelector('.moshcw-result');
		var instruction = box.querySelector('.moshcw-instruction');
		var meta = document.getElementById('moshcw-meta');
		var counter = box.querySelector('.moshcw-meta-count');

		function esc(s) {
			return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
				return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
			});
		}
		function api(data) {
			return fetch(E.rest + 'admin/editor', {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': E.nonce },
				body: JSON.stringify(data)
			}).then(function (r) {
				return r.json().catch(function () { return {}; }).then(function (j) {
					if (!r.ok) { throw new Error(j && j.message ? j.message : 'Request failed (' + r.status + ')'); }
					return j;
				});
			});
		}
		function tiny(id) {
			return window.tinymce && tinymce.get(id) && !tinymce.get(id).isHidden() ? tinymce.get(id) : null;
		}
		function getPost() {
			if (isBlock) {
				var s = wp.data.select('core/editor');
				return { title: s.getEditedPostAttribute('title') || '', content: s.getEditedPostContent() || '' };
			}
			var t = document.getElementById('title');
			var ed = tiny('content');
			var c = document.getElementById('content');
			return { title: t ? t.value : '', content: ed ? ed.getContent() : (c ? c.value : '') };
		}
		function setEditor(id, html, append) {
			var ed = tiny(id);
			if (ed) { ed.setContent(append ? ed.getContent() + html : html); ed.save(); return; }
			var el = document.getElementById(id);
			if (el) { el.value = append && el.value ? el.value + '\n\n' + html : html; el.dispatchEvent(new Event('input', { bubbles: true })); }
		}
		var apply = {
			title: function (v) {
				if (isBlock) { wp.data.dispatch('core/editor').editPost({ title: v }); return; }
				var t = document.getElementById('title');
				if (t) { t.value = v; t.dispatchEvent(new Event('input', { bubbles: true })); var l = document.getElementById('title-prompt-text'); if (l) { l.classList.add('screen-reader-text'); } }
			},
			excerpt: function (v) {
				if (isBlock) { wp.data.dispatch('core/editor').editPost({ excerpt: v }); } else { setEditor('excerpt', v, false); }
			},
			content: function (html) {
				if (isBlock && wp.blocks) {
					wp.data.dispatch('core/block-editor').insertBlocks(wp.blocks.rawHandler({ HTML: html }));
				} else { setEditor('content', html, true); }
			},
			short: function (html) { setEditor('excerpt', html, false); },
			meta: function (v) { if (meta) { meta.value = v; count(); meta.focus(); } }
		};
		function count() { if (meta && counter) { counter.textContent = '(' + meta.value.length + ' chars)'; } }
		if (meta) { meta.addEventListener('input', count); count(); }

		function actions(buttons) {
			var wrap = document.createElement('p');
			wrap.className = 'moshcw-result-actions';
			buttons.forEach(function (b) {
				var btn = document.createElement('button');
				btn.type = 'button';
				btn.className = 'button' + (b.primary ? ' button-primary' : '');
				btn.textContent = b.label;
				btn.addEventListener('click', function () { b.fn(); btn.textContent = '✓ ' + b.label; });
				wrap.appendChild(btn);
			});
			return wrap;
		}
		function copyBtn(text) {
			return { label: 'Copy', fn: function () { if (navigator.clipboard) { navigator.clipboard.writeText(text); } } };
		}

		function show(r) {
			result.innerHTML = '';
			result.hidden = false;
			if (r.task === 'titles' && r.items) {
				r.items.forEach(function (t) {
					var row = document.createElement('div');
					row.className = 'moshcw-title-option';
					row.innerHTML = '<span>' + esc(t) + '</span>';
					var b = document.createElement('button');
					b.type = 'button';
					b.className = 'button button-small';
					b.textContent = 'Use';
					b.addEventListener('click', function () { apply.title(t); b.textContent = '✓'; });
					row.appendChild(b);
					result.appendChild(row);
				});
				return;
			}
			var preview = document.createElement('div');
			preview.className = 'moshcw-result-text';
			var isHtml = ['expand', 'product_description', 'product_short', 'custom'].indexOf(r.task) > -1;
			preview.innerHTML = isHtml ? r.text : esc(r.text);
			result.appendChild(preview);
			var btns = [];
			if (r.task === 'excerpt') { btns.push({ label: 'Use as excerpt', primary: true, fn: function () { apply.excerpt(r.text); } }); }
			if (r.task === 'seo') { btns.push({ label: 'Use as meta description', primary: true, fn: function () { apply.meta(r.text); } }); }
			if (r.task === 'expand' || r.task === 'product_description' || r.task === 'custom') { btns.push({ label: 'Insert into content', primary: true, fn: function () { apply.content(r.text); } }); }
			if (r.task === 'product_short') { btns.push({ label: 'Use as short description', primary: true, fn: function () { apply.short(r.text); } }); }
			btns.push(copyBtn(r.text));
			result.appendChild(actions(btns));
		}

		box.addEventListener('click', function (e) {
			var btn = e.target.closest('[data-task]');
			if (!btn || btn.disabled) { return; }
			var p = getPost();
			var label = btn.textContent;
			box.querySelectorAll('[data-task]').forEach(function (b) { b.disabled = true; });
			btn.textContent = 'Thinking…';
			result.hidden = false;
			result.innerHTML = '<span class="spinner is-active" style="float:none;margin:0"></span>';
			api({
				task: btn.getAttribute('data-task'),
				post_id: box.getAttribute('data-post'),
				title: p.title,
				content: p.content,
				instruction: instruction ? instruction.value : ''
			}).then(show).catch(function (err) {
				result.innerHTML = '<p class="moshcw-err">' + esc(err.message) + '</p>';
			}).then(function () {
				btn.textContent = label;
				box.querySelectorAll('[data-task]').forEach(function (b) { b.disabled = false; });
			});
		});
	});
})();
