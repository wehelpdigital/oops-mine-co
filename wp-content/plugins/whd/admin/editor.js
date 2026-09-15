/* WHD block editor — drag-and-drop canvas, inspector and live preview. Vanilla JS, no build step. */
(function () {
	'use strict';
	if (typeof WHD_EDITOR === 'undefined') { return; }

	var CFG = WHD_EDITOR;
	var I18N = CFG.i18n;
	var root = document.getElementById('whd-editor');
	if (!root) { return; }

	/* ─────────── state ─────────── */
	var state = {
		design: JSON.parse(JSON.stringify(CFG.design || { settings: {}, blocks: [] })),
		selected: -1,          // index of the selected block, -1 = none
		tab: 'block',          // block | settings
		dirty: false,
		device: 'desktop'
	};
	state.design.blocks = state.design.blocks || [];
	state.design.settings = state.design.settings || {};

	var previewTimer = null, saveTimer = null;

	/* ─────────── helpers ─────────── */
	function h(tag, attrs, children) {
		var el = document.createElement(tag);
		if (attrs) {
			Object.keys(attrs).forEach(function (k) {
				if (k === 'class') { el.className = attrs[k]; }
				else if (k === 'html') { el.innerHTML = attrs[k]; }
				else if (k === 'text') { el.textContent = attrs[k]; }
				else if (k.indexOf('on') === 0) { el.addEventListener(k.slice(2), attrs[k]); }
				else if (attrs[k] !== null && attrs[k] !== undefined && attrs[k] !== false) { el.setAttribute(k, attrs[k] === true ? '' : attrs[k]); }
			});
		}
		(children || []).forEach(function (c) { if (c === null || c === undefined) { return; } el.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
		return el;
	}
	function esc(s) { return String(s === undefined || s === null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
	function typeDef(type) { return CFG.types[type]; }
	function defaultsFor(type) {
		var props = {}, def = typeDef(type);
		if (def) { Object.keys(def.fields).forEach(function (n) { props[n] = def.fields[n].default; }); }
		return props;
	}
	function markDirty() {
		state.dirty = true;
		updateTopbar();
		schedulePreview();
	}
	function toast(msg, isError) {
		var t = h('div', { class: 'whd-ed__toast' + (isError ? ' is-error' : ''), text: msg });
		root.appendChild(t);
		requestAnimationFrame(function () { t.classList.add('is-in'); });
		setTimeout(function () { t.classList.remove('is-in'); setTimeout(function () { t.remove(); }, 300); }, 2600);
	}
	function api(path, method, body) {
		return fetch(CFG.rest.root + path, {
			method: method || 'GET',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.rest.nonce },
			credentials: 'same-origin',
			body: body ? JSON.stringify(body) : undefined
		}).then(function (r) { return r.json().then(function (j) { if (!r.ok) { throw new Error(j && j.message ? j.message : r.statusText); } return j; }); });
	}

	/* ─────────── layout ─────────── */
	var ui = {};
	function build() {
		root.innerHTML = '';
		ui.top = h('div', { class: 'whd-ed__top' });
		ui.palette = h('aside', { class: 'whd-ed__palette' });
		ui.canvasWrap = h('section', { class: 'whd-ed__canvas' });
		ui.inspector = h('aside', { class: 'whd-ed__inspector' });
		ui.preview = h('section', { class: 'whd-ed__preview' });
		var main = h('div', { class: 'whd-ed__main' }, [ui.palette, ui.canvasWrap, ui.inspector, ui.preview]);
		root.appendChild(ui.top);
		root.appendChild(main);
		renderTopbar();
		renderPalette();
		renderCanvas();
		renderInspector();
		renderPreviewPane();
		schedulePreview(0);
	}

	/* ─────────── top bar ─────────── */
	function renderTopbar() {
		ui.top.innerHTML = '';
		ui.status = h('span', { class: 'whd-ed__status' });
		ui.saveBtn = h('button', { class: 'button button-primary whd-ed__save', type: 'button', onclick: save }, [I18N.save]);
		var left = h('div', { class: 'whd-ed__top-left' }, [
			h('a', { class: 'whd-ed__back', href: CFG.backUrl, onclick: function (e) { if (state.dirty && !confirm(I18N.leave)) { e.preventDefault(); } } }, [I18N.back]),
			h('div', {}, [h('h1', { class: 'whd-ed__title', text: CFG.label }), CFG.desc ? h('p', { class: 'whd-ed__desc', text: CFG.desc }) : null])
		]);
		var right = h('div', { class: 'whd-ed__top-right' }, [ui.status]);
		if (CFG.mode === 'email') {
			right.appendChild(h('button', { class: 'button', type: 'button', onclick: sendTest }, [I18N.sendTest]));
		} else if (CFG.previewUrl) {
			right.appendChild(h('a', { class: 'button', href: CFG.previewUrl, target: '_blank' }, [I18N.openSite]));
		}
		right.appendChild(ui.saveBtn);
		ui.top.appendChild(left);
		ui.top.appendChild(right);
		updateTopbar();
	}
	function updateTopbar() {
		if (!ui.status) { return; }
		ui.status.textContent = state.dirty ? I18N.unsaved : '';
		ui.status.className = 'whd-ed__status' + (state.dirty ? ' is-dirty' : '');
	}

	/* ─────────── palette ─────────── */
	function renderPalette() {
		ui.palette.innerHTML = '';
		ui.palette.appendChild(h('h2', { class: 'whd-ed__h', text: I18N.blocks }));
		ui.palette.appendChild(h('p', { class: 'whd-ed__hint', text: I18N.dragHint }));
		var list = h('div', { class: 'whd-ed__blocks' });
		Object.keys(CFG.types).forEach(function (type) {
			var def = CFG.types[type];
			var item = h('button', { class: 'whd-ed__pal', type: 'button', draggable: 'true', 'data-type': type, title: def.label,
				onclick: function () { insertBlock(type, state.design.blocks.length); },
				ondragstart: function (e) { e.dataTransfer.setData('text/plain', 'new:' + type); e.dataTransfer.effectAllowed = 'copy'; } },
				[h('span', { class: 'whd-ed__pal-icon', text: def.icon || '▪' }), h('span', { class: 'whd-ed__pal-label', text: def.label })]);
			list.appendChild(item);
		});
		ui.palette.appendChild(list);

		ui.palette.appendChild(h('h2', { class: 'whd-ed__h', text: I18N.tags }));
		ui.palette.appendChild(h('p', { class: 'whd-ed__hint', text: I18N.tagHint }));
		var tags = h('div', { class: 'whd-ed__tags' });
		Object.keys(CFG.tags).forEach(function (tag) {
			tags.appendChild(h('button', { class: 'whd-ed__tag', type: 'button', title: CFG.tags[tag], onclick: function () {
				var txt = '{' + tag + '}';
				var done = function () { toast(I18N.copied + ' ' + txt); };
				if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(txt).then(done, done); } else { done(); }
			} }, ['{' + tag + '}']));
		});
		ui.palette.appendChild(tags);
	}

	/* ─────────── canvas ─────────── */
	function summary(block) {
		var p = block.props || {};
		var s = p.text || p.code || p.label || p.alt || p.url || '';
		s = String(s).replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
		return s.length > 60 ? s.slice(0, 60) + '…' : s;
	}
	function renderCanvas() {
		ui.canvasWrap.innerHTML = '';
		ui.canvasWrap.appendChild(h('h2', { class: 'whd-ed__h', text: 'Canvas' }));
		var list = h('div', { class: 'whd-ed__list', ondragover: onDragOver, ondrop: onDrop, ondragleave: function (e) { if (e.target === list) { clearDropLine(); } } });
		ui.list = list;
		if (!state.design.blocks.length) {
			list.appendChild(h('div', { class: 'whd-ed__empty', text: I18N.empty }));
		}
		state.design.blocks.forEach(function (block, i) {
			var def = typeDef(block.type) || { label: block.type, icon: '?' };
			var card = h('div', { class: 'whd-ed__block' + (i === state.selected ? ' is-selected' : ''), draggable: 'true', 'data-index': i,
				onclick: function () { select(i); },
				ondragstart: function (e) { e.dataTransfer.setData('text/plain', 'move:' + i); e.dataTransfer.effectAllowed = 'move'; card.classList.add('is-dragging'); },
				ondragend: function () { card.classList.remove('is-dragging'); clearDropLine(); } }, [
				h('span', { class: 'whd-ed__handle', title: 'Drag to reorder', html: '⋮⋮' }),
				h('span', { class: 'whd-ed__block-icon', text: def.icon || '▪' }),
				h('span', { class: 'whd-ed__block-main' }, [h('strong', { text: def.label }), h('span', { class: 'whd-ed__block-summary', text: summary(block) })]),
				h('span', { class: 'whd-ed__block-actions' }, [
					h('button', { type: 'button', class: 'whd-ed__ib', title: I18N.up, onclick: function (e) { e.stopPropagation(); move(i, i - 1); }, html: '↑' }),
					h('button', { type: 'button', class: 'whd-ed__ib', title: I18N.down, onclick: function (e) { e.stopPropagation(); move(i, i + 1); }, html: '↓' }),
					h('button', { type: 'button', class: 'whd-ed__ib', title: I18N.duplicate, onclick: function (e) { e.stopPropagation(); duplicate(i); }, html: '⧉' }),
					h('button', { type: 'button', class: 'whd-ed__ib is-danger', title: I18N.delete, onclick: function (e) { e.stopPropagation(); remove(i); }, html: '×' })
				])
			]);
			list.appendChild(card);
		});
		ui.canvasWrap.appendChild(list);
	}

	var dropIndex = -1, dropLine = null;
	function clearDropLine() { if (dropLine) { dropLine.remove(); dropLine = null; } dropIndex = -1; }
	function onDragOver(e) {
		e.preventDefault();
		var cards = Array.prototype.slice.call(ui.list.querySelectorAll('.whd-ed__block'));
		var idx = cards.length;
		for (var i = 0; i < cards.length; i++) {
			var r = cards[i].getBoundingClientRect();
			if (e.clientY < r.top + r.height / 2) { idx = i; break; }
		}
		if (idx === dropIndex && dropLine) { return; }
		clearDropLine();
		dropIndex = idx;
		dropLine = h('div', { class: 'whd-ed__dropline' });
		if (idx >= cards.length) { ui.list.appendChild(dropLine); } else { ui.list.insertBefore(dropLine, cards[idx]); }
	}
	function onDrop(e) {
		e.preventDefault();
		var data = e.dataTransfer.getData('text/plain') || '';
		var idx = dropIndex < 0 ? state.design.blocks.length : dropIndex;
		clearDropLine();
		if (data.indexOf('new:') === 0) { insertBlock(data.slice(4), idx); }
		else if (data.indexOf('move:') === 0) {
			var from = parseInt(data.slice(5), 10);
			var to = idx > from ? idx - 1 : idx;
			move(from, to);
		}
	}

	/* ─────────── block operations ─────────── */
	function insertBlock(type, at) {
		if (!typeDef(type)) { return; }
		state.design.blocks.splice(at, 0, { type: type, props: defaultsFor(type) });
		state.selected = at;
		state.tab = 'block';
		markDirty();
		renderCanvas();
		renderInspector();
	}
	function move(from, to) {
		var b = state.design.blocks;
		if (to < 0 || to >= b.length || from === to) { return; }
		var item = b.splice(from, 1)[0];
		b.splice(to, 0, item);
		state.selected = to;
		markDirty();
		renderCanvas();
		renderInspector();
	}
	function duplicate(i) {
		var copy = JSON.parse(JSON.stringify(state.design.blocks[i]));
		state.design.blocks.splice(i + 1, 0, copy);
		state.selected = i + 1;
		markDirty();
		renderCanvas();
		renderInspector();
	}
	function remove(i) {
		state.design.blocks.splice(i, 1);
		state.selected = Math.min(state.selected, state.design.blocks.length - 1);
		if (!state.design.blocks.length) { state.selected = -1; }
		markDirty();
		renderCanvas();
		renderInspector();
	}
	function select(i) {
		state.selected = i;
		state.tab = 'block';
		renderCanvas();
		renderInspector();
	}

	/* ─────────── inspector ─────────── */
	function renderInspector() {
		ui.inspector.innerHTML = '';
		var tabs = h('div', { class: 'whd-ed__tabs' }, [
			h('button', { type: 'button', class: 'whd-ed__tab' + (state.tab === 'block' ? ' is-active' : ''), onclick: function () { state.tab = 'block'; renderInspector(); } }, [I18N.block]),
			h('button', { type: 'button', class: 'whd-ed__tab' + (state.tab === 'settings' ? ' is-active' : ''), onclick: function () { state.tab = 'settings'; renderInspector(); } }, [I18N.settings])
		]);
		ui.inspector.appendChild(tabs);
		var body = h('div', { class: 'whd-ed__fields' });
		ui.inspector.appendChild(body);

		if (state.tab === 'settings') {
			Object.keys(CFG.settingsFields).forEach(function (name) {
				var f = CFG.settingsFields[name];
				body.appendChild(field(f, name, state.design.settings[name], function (v) { state.design.settings[name] = v; markDirty(); }));
			});
			return;
		}
		var block = state.design.blocks[state.selected];
		if (!block) { body.appendChild(h('p', { class: 'whd-ed__hint', text: I18N.selectHint })); return; }
		var def = typeDef(block.type);
		body.appendChild(h('h3', { class: 'whd-ed__h', text: def.label }));
		Object.keys(def.fields).forEach(function (name) {
			var f = def.fields[name];
			body.appendChild(field(f, name, block.props[name], function (v) {
				block.props[name] = v;
				markDirty();
				var card = ui.list.querySelector('.whd-ed__block[data-index="' + state.selected + '"] .whd-ed__block-summary');
				if (card) { card.textContent = summary(block); }
			}));
		});
	}

	/** One labelled form control for a field definition. */
	function field(f, name, value, onChange) {
		var wrap = h('div', { class: 'whd-ed__field whd-ed__field--' + f.type });
		var id = 'whd-f-' + name + '-' + Math.random().toString(36).slice(2, 7);
		if (f.type !== 'toggle') { wrap.appendChild(h('label', { for: id, text: f.label })); }
		var input;
		switch (f.type) {
			case 'textarea':
				input = h('textarea', { id: id, rows: 4 });
				input.value = value || '';
				input.addEventListener('input', function () { onChange(input.value); });
				break;
			case 'number':
				input = h('input', { id: id, type: 'number', min: f.min, max: f.max, step: f.step || 1 });
				input.value = value === undefined ? '' : value;
				input.addEventListener('input', function () { onChange(parseInt(input.value, 10) || 0); });
				break;
			case 'color':
				input = h('div', { class: 'whd-ed__color' });
				var swatch = h('input', { type: 'color' }); swatch.value = /^#[0-9a-f]{6}$/i.test(value || '') ? value : '#000000';
				var text = h('input', { id: id, type: 'text', maxlength: 7 }); text.value = value || '';
				swatch.addEventListener('input', function () { text.value = swatch.value; onChange(swatch.value); });
				text.addEventListener('input', function () { if (/^#[0-9a-f]{6}$/i.test(text.value)) { swatch.value = text.value; onChange(text.value); } });
				input.appendChild(swatch); input.appendChild(text);
				break;
			case 'select':
				input = h('select', { id: id });
				Object.keys(f.options).forEach(function (k) { var o = h('option', { value: k, text: f.options[k] }); if (k === value) { o.selected = true; } input.appendChild(o); });
				input.addEventListener('change', function () { onChange(input.value); });
				break;
			case 'align':
				input = h('div', { class: 'whd-ed__align', id: id });
				['left', 'center', 'right'].forEach(function (a) {
					var b = h('button', { type: 'button', class: a === value ? 'is-active' : '', title: a, html: a === 'left' ? '≡' : a === 'center' ? '☰' : '≡' });
					b.style.textAlign = a;
					b.addEventListener('click', function () { Array.prototype.forEach.call(input.children, function (c) { c.classList.remove('is-active'); }); b.classList.add('is-active'); onChange(a); });
					input.appendChild(b);
				});
				break;
			case 'toggle':
				var cb = h('input', { id: id, type: 'checkbox' }); cb.checked = !!(value && value !== '0');
				cb.addEventListener('change', function () { onChange(cb.checked ? 1 : 0); });
				input = h('label', { class: 'whd-ed__toggle', for: id }, [cb, h('span', { text: f.label })]);
				break;
			case 'image':
				input = h('div', { class: 'whd-ed__image' });
				var url = h('input', { id: id, type: 'text', placeholder: 'https://…' }); url.value = value || '';
				url.addEventListener('input', function () { onChange(url.value); });
				var pick = h('button', { type: 'button', class: 'button' }, [I18N.choose]);
				pick.addEventListener('click', function () {
					if (!window.wp || !wp.media) { return; }
					var frame = wp.media({ title: f.label, multiple: false, library: { type: 'image' } });
					frame.on('select', function () { var att = frame.state().get('selection').first().toJSON(); var u = (att.sizes && att.sizes.large ? att.sizes.large.url : att.url); url.value = u; onChange(u); });
					frame.open();
				});
				input.appendChild(url); input.appendChild(pick);
				break;
			default: // text, url
				input = h('input', { id: id, type: 'text' });
				input.value = value || '';
				input.addEventListener('input', function () { onChange(input.value); });
		}
		wrap.appendChild(input);
		if (f.help) { wrap.appendChild(h('p', { class: 'whd-ed__help', text: f.help })); }
		return wrap;
	}

	/* ─────────── preview ─────────── */
	function renderPreviewPane() {
		ui.preview.innerHTML = '';
		var bar = h('div', { class: 'whd-ed__preview-bar' }, [
			h('h2', { class: 'whd-ed__h', text: I18N.preview }),
			h('div', { class: 'whd-ed__devices' }, [
				h('button', { type: 'button', class: 'is-active', title: 'Desktop', html: '🖥', onclick: function (e) { setDevice('desktop', e.currentTarget); } }),
				h('button', { type: 'button', title: 'Mobile', html: '📱', onclick: function (e) { setDevice('mobile', e.currentTarget); } })
			])
		]);
		ui.iframeWrap = h('div', { class: 'whd-ed__frame whd-ed__frame--desktop' });
		ui.iframe = h('iframe', { class: 'whd-ed__iframe', title: 'Preview', sandbox: 'allow-same-origin allow-scripts' });
		ui.iframeWrap.appendChild(ui.iframe);
		ui.preview.appendChild(bar);
		ui.preview.appendChild(ui.iframeWrap);
	}
	function setDevice(d, btn) {
		state.device = d;
		ui.iframeWrap.className = 'whd-ed__frame whd-ed__frame--' + d;
		Array.prototype.forEach.call(btn.parentNode.children, function (c) { c.classList.remove('is-active'); });
		btn.classList.add('is-active');
	}
	function schedulePreview(delay) {
		clearTimeout(previewTimer);
		previewTimer = setTimeout(refreshPreview, delay === undefined ? 400 : delay);
	}
	function refreshPreview() {
		ui.preview.classList.add('is-loading');
		api('render', 'POST', { type: CFG.mode, id: CFG.id, design: state.design })
			.then(function (res) { ui.iframe.srcdoc = res.html || ''; })
			.catch(function (err) { toast(I18N.error + ': ' + err.message, true); })
			.finally(function () { ui.preview.classList.remove('is-loading'); });
	}

	/* ─────────── save / test ─────────── */
	function save() {
		ui.saveBtn.disabled = true;
		ui.saveBtn.textContent = I18N.saving;
		api('design', 'POST', { type: CFG.mode, id: CFG.id, design: state.design })
			.then(function (res) {
				if (res.design) { state.design = res.design; state.design.blocks = state.design.blocks || []; renderCanvas(); renderInspector(); }
				state.dirty = false;
				updateTopbar();
				toast(I18N.saved);
			})
			.catch(function (err) { toast(I18N.error + ': ' + err.message, true); })
			.finally(function () { ui.saveBtn.disabled = false; ui.saveBtn.textContent = I18N.save; });
	}
	function sendTest() {
		api('test-email', 'POST', { id: CFG.id, design: state.design })
			.then(function (res) { toast(res.sent ? I18N.sent + ' ' + res.to : I18N.error, !res.sent); })
			.catch(function (err) { toast(I18N.error + ': ' + err.message, true); });
	}

	window.addEventListener('beforeunload', function (e) { if (state.dirty) { e.preventDefault(); e.returnValue = ''; } });
	document.addEventListener('keydown', function (e) {
		if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); save(); }
	});

	build();
})();
