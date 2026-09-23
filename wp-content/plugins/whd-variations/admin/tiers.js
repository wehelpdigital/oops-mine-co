/* WHD — Variation Tiers: the product-screen app (tier builder + combination grid).
   Vanilla JS. State is mirrored into #whdv-state as JSON and saved with the product; WHDV_Model::apply()
   turns it into real attributes and variations. wp.media is used for image picking. */
(function () {
	'use strict';

	var D = window.WHDV;
	var root = document.getElementById('whdv-app');
	var hidden = document.getElementById('whdv-state');
	if (!D || !root || !hidden) { return; }

	var I = D.i18n;
	var MAX = parseInt(D.maxLevels, 10) || 3;
	var catalog = D.catalog || [];
	var byTax = {};
	catalog.forEach(function (c) { byTax[c.taxonomy] = c; });

	/* ───────── helpers ───────── */

	function slugify(s) { return String(s).toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, ''); }
	function fmt(s) { var a = [].slice.call(arguments, 1), i = 0; return String(s).replace(/%(\d+\$)?[sd]/g, function () { return a[i++]; }); }
	function el(tag, attrs, children) {
		var n = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) {
			var v = attrs[k];
			if (k === 'class') { n.className = v; }
			else if (k === 'text') { n.textContent = v; }
			else if (k.indexOf('on') === 0) { n.addEventListener(k.slice(2), v); }
			else if (v !== null && v !== undefined && v !== false) { n.setAttribute(k, v === true ? '' : v); }
		});
		(children || []).forEach(function (c) { if (c) { n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); } });
		return n;
	}
	function termToOption(t) { return { key: t.slug, id: t.id, name: t.name, slug: t.slug, color: t.color || '', image: t.image || 0, image_url: t.image_url || '' }; }
	function pickImage(cb) {
		if (!window.wp || !wp.media) { return; }
		var frame = wp.media({ title: I.pickImage, multiple: false, library: { type: 'image' } });
		frame.on('select', function () {
			var a = frame.state().get('selection').first().toJSON();
			cb(a.id, (a.sizes && a.sizes.thumbnail) ? a.sizes.thumbnail.url : a.url);
		});
		frame.open();
	}

	/* ───────── state ───────── */

	function normalise(s) {
		var out = { levels: [], combos: {}, groups: {}, notices: s.notices || [], dirty: false };
		(s.levels || []).slice(0, MAX).forEach(function (l) {
			out.levels.push({
				taxonomy: l.taxonomy || '', label: l.label || '', type: l.type || 'select', isNew: !!l.new,
				options: (l.options || []).map(function (o) { return { key: o.key || o.slug || ('new:' + slugify(o.name)), id: o.id || 0, name: o.name || '', slug: o.slug || '', color: o.color || '', image: o.image || 0, image_url: o.image_url || '' }; })
			});
		});
		Object.keys(s.combos || {}).forEach(function (k) {
			var c = s.combos[k];
			out.combos[k] = { on: !(c.on === false || c.on === 0), id: c.id || 0, sku: c.sku || '', regular_price: c.regular_price || '', sale_price: c.sale_price || '', stock: (c.stock === null || c.stock === undefined) ? '' : String(c.stock), oos: !!c.oos, image: c.image || 0, image_url: c.image_url || '' };
		});
		Object.keys(s.groups || {}).forEach(function (k) { out.groups[k] = { image: s.groups[k].image || 0, image_url: s.groups[k].image_url || '' }; });
		return out;
	}

	var state = normalise(D.state || {});
	var loadedCount = Object.keys(state.combos).filter(function (k) { return state.combos[k].id; }).length;

	function sync() {
		hidden.value = JSON.stringify({
			levels: state.levels.map(function (l) { return { taxonomy: l.taxonomy, label: l.label, type: l.type, new: l.isNew, options: l.options }; }),
			combos: state.combos, groups: state.groups, dirty: state.dirty
		});
	}
	function ensureVariable() {
		var sel = document.getElementById('product-type');
		if (sel && state.levels.length && sel.value !== 'variable') {
			sel.value = 'variable';
			if (window.jQuery) { window.jQuery(sel).trigger('change'); } else { sel.dispatchEvent(new Event('change', { bubbles: true })); }
			// WooCommerce's type-change handler jumps to the first tab; come back to ours.
			var tab = document.querySelector('li.whdv_options a');
			if (tab) { tab.click(); }
		}
	}
	function touch() {
		state.dirty = true;
		ensureVariable();
		sync();
		var n = root.querySelector('.whdv-dirty');
		if (n) { n.hidden = false; }
	}

	function comboKeys() {
		var rows = [[]];
		state.levels.forEach(function (l) {
			var next = [];
			rows.forEach(function (r) { l.options.forEach(function (o) { next.push(r.concat([o.key])); }); });
			rows = next;
		});
		return rows;
	}
	function levelsReady() { return state.levels.length > 0 && state.levels.every(function (l) { return l.options.length > 0; }); }
	function defaultCombo() {
		var def = document.getElementById('_regular_price');
		return { on: true, id: 0, sku: '', regular_price: def && def.value ? def.value : '', sale_price: '', stock: '', oos: false, image: 0, image_url: '' };
	}
	/* When levels or options change, keep the data of every combination that still exists and seed new
	   combinations from the closest old one (most option keys in common), so prices survive adding a level. */
	function rebuildCombos() {
		if (!levelsReady()) { return; }
		var old = state.combos, oldKeys = Object.keys(old), next = {};
		comboKeys().forEach(function (parts) {
			var k = parts.join('|');
			if (old[k]) { next[k] = old[k]; return; }
			var src = null, best = 0;
			oldKeys.forEach(function (ok) {
				var score = ok.split('|').filter(function (p) { return parts.indexOf(p) !== -1; }).length;
				if (score > best) { best = score; src = old[ok]; }
			});
			next[k] = src ? Object.assign({}, src, { id: 0 }) : defaultCombo();
		});
		state.combos = next;
	}
	function swapLevels(a, b) {
		var tmp = state.levels[a]; state.levels[a] = state.levels[b]; state.levels[b] = tmp;
		var next = {};
		Object.keys(state.combos).forEach(function (k) {
			var p = k.split('|');
			if (p.length === state.levels.length) { var t = p[a]; p[a] = p[b]; p[b] = t; }
			next[p.join('|')] = state.combos[k];
		});
		state.combos = next;
		if (a === 0 || b === 0) { state.groups = {}; }
		touch(); render();
	}

	/* ───────── render ───────── */

	function render() {
		root.innerHTML = '';
		root.appendChild(el('p', { class: 'whdv-intro', text: I.intro }));
		(state.notices || []).forEach(function (n) { root.appendChild(el('div', { class: 'notice notice-warning inline whdv-notice' }, [el('p', { text: n })])); });
		if (loadedCount) { root.appendChild(el('p', { class: 'whdv-muted', text: fmt(I.loaded, loadedCount) })); }
		root.appendChild(el('div', { class: 'notice notice-info inline whdv-dirty', hidden: !state.dirty }, [el('p', { text: I.dirty })]));

		var levels = el('div', { class: 'whdv-levels' });
		if (!state.levels.length) { levels.appendChild(el('p', { class: 'whdv-muted', text: I.noLevels })); }
		state.levels.forEach(function (l, i) { levels.appendChild(renderLevel(l, i)); });
		root.appendChild(levels);

		if (state.levels.length < MAX) {
			root.appendChild(el('button', { type: 'button', class: 'button whdv-add-level', text: '+ ' + I.addLevel, onclick: function () {
				state.levels.push({ taxonomy: '', label: '', type: 'select', isNew: false, options: [] });
				touch(); render();
			} }));
		} else {
			root.appendChild(el('p', { class: 'whdv-muted', text: I.levelsFull }));
		}
		if (levelsReady()) { root.appendChild(renderGrid()); }
		sync();
	}

	function renderLevel(l, i) {
		var card = el('div', { class: 'whdv-level' });
		var head = el('div', { class: 'whdv-level__head' });
		head.appendChild(el('strong', { class: 'whdv-level__n', text: fmt(I.level, i + 1) }));

		var sel = el('select', { class: 'whdv-tax' });
		sel.appendChild(el('option', { value: '', text: I.chooseAttr }));
		catalog.forEach(function (c) {
			var used = state.levels.some(function (x, j) { return j !== i && x.taxonomy === c.taxonomy; });
			sel.appendChild(el('option', { value: c.taxonomy, text: c.label + ' (' + (D.types[c.type] || c.type) + ')', disabled: used }));
		});
		sel.appendChild(el('option', { value: '__new', text: I.newAttr }));
		sel.value = l.isNew ? '__new' : l.taxonomy;
		sel.addEventListener('change', function () {
			if (sel.value === '__new') { l.isNew = true; l.taxonomy = ''; l.label = ''; l.type = 'select'; }
			else { var c = byTax[sel.value]; l.isNew = false; l.taxonomy = sel.value; l.label = c ? c.label : ''; l.type = c ? c.type : 'select'; }
			l.options = [];
			touch(); render();
		});
		head.appendChild(sel);

		if (l.isNew) {
			head.appendChild(el('input', { type: 'text', class: 'whdv-new-label', placeholder: I.newAttrLabel, value: l.label, oninput: function (e) { l.label = e.target.value; touch(); } }));
			var ts = el('select', { class: 'whdv-new-type', title: I.typeLabel });
			Object.keys(D.types).forEach(function (t) { ts.appendChild(el('option', { value: t, text: I.typeLabel + ': ' + D.types[t] })); });
			ts.value = l.type;
			ts.addEventListener('change', function () { l.type = ts.value; touch(); render(); });
			head.appendChild(ts);
		}

		var ctl = el('span', { class: 'whdv-level__ctl' });
		ctl.appendChild(el('button', { type: 'button', class: 'button-link', title: I.up, text: '↑', disabled: i === 0, onclick: function () { swapLevels(i, i - 1); } }));
		ctl.appendChild(el('button', { type: 'button', class: 'button-link', title: I.down, text: '↓', disabled: i === state.levels.length - 1, onclick: function () { swapLevels(i, i + 1); } }));
		ctl.appendChild(el('button', { type: 'button', class: 'button-link whdv-danger', title: I.remove, text: '✕', onclick: function () {
			if (!l.options.length || window.confirm(I.removeLevel)) { state.levels.splice(i, 1); if (i === 0) { state.groups = {}; } rebuildCombos(); touch(); render(); }
		} }));
		head.appendChild(ctl);
		card.appendChild(head);
		if (l.isNew || l.taxonomy) { card.appendChild(renderOptions(l, i)); }
		return card;
	}

	function renderOptions(l, i) {
		var wrap = el('div', { class: 'whdv-options' });
		var chips = el('div', { class: 'whdv-chips' });
		l.options.forEach(function (o, oi) {
			var chip = el('span', { class: 'whdv-chip' + (o.id ? '' : ' whdv-chip--new') });
			if (l.type === 'color') {
				var ci = el('input', { type: 'color', class: 'whdv-chip__color', title: I.colour, value: /^#[0-9a-f]{6}$/i.test(o.color) ? o.color : '#e9d3ca' });
				ci.addEventListener('input', function () { o.color = ci.value; touch(); });
				chip.appendChild(ci);
			} else if (l.type === 'image') {
				var ib = el('button', { type: 'button', class: 'whdv-chip__img', title: I.swatchImage, onclick: function () { pickImage(function (id, url) { o.image = id; o.image_url = url; touch(); render(); }); } });
				if (o.image_url) { ib.appendChild(el('img', { src: o.image_url, alt: '' })); } else { ib.textContent = '+'; }
				chip.appendChild(ib);
			}
			chip.appendChild(el('span', { class: 'whdv-chip__name', text: o.name }));
			chip.appendChild(el('button', { type: 'button', class: 'whdv-chip__x', title: I.remove, text: '×', onclick: function () { l.options.splice(oi, 1); rebuildCombos(); touch(); render(); } }));
			chips.appendChild(chip);
		});
		wrap.appendChild(chips);

		var cat = byTax[l.taxonomy];
		var terms = cat ? cat.terms : [];
		var rest = terms.filter(function (t) { return !l.options.some(function (o) { return o.id === t.id; }); });
		var row = el('div', { class: 'whdv-add' });
		var input = el('input', { type: 'text', class: 'whdv-add__input', placeholder: I.optionPlace, list: 'whdv-dl-' + i });
		var dl = el('datalist', { id: 'whdv-dl-' + i });
		rest.forEach(function (t) { dl.appendChild(el('option', { value: t.name })); });
		function add(name) {
			name = String(name || '').trim();
			if (!name) { return; }
			if (l.options.some(function (o) { return o.name.toLowerCase() === name.toLowerCase(); })) { window.alert(I.exists); return; }
			var t = terms.filter(function (x) { return x.name.toLowerCase() === name.toLowerCase() || x.slug === slugify(name); })[0];
			l.options.push(t ? termToOption(t) : { key: 'new:' + slugify(name), id: 0, name: name, slug: '', color: '', image: 0, image_url: '' });
			rebuildCombos(); touch(); render();
			var again = root.querySelectorAll('.whdv-level')[i];
			var ni = again && again.querySelector('.whdv-add__input');
			if (ni) { ni.focus(); }
		}
		input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); add(input.value); } });
		row.appendChild(input);
		row.appendChild(dl);
		row.appendChild(el('button', { type: 'button', class: 'button', text: I.addOption, onclick: function () { add(input.value); } }));
		if (rest.length && rest.length <= 60) {
			row.appendChild(el('button', { type: 'button', class: 'button-link', text: fmt(I.addAll, rest.length), onclick: function () {
				rest.forEach(function (t) { l.options.push(termToOption(t)); });
				rebuildCombos(); touch(); render();
			} }));
		}
		wrap.appendChild(row);
		return wrap;
	}

	function renderGrid() {
		var wrap = el('div', { class: 'whdv-grid' });
		var keys = comboKeys();
		keys.forEach(function (p) { if (!state.combos[p.join('|')]) { state.combos[p.join('|')] = defaultCombo(); } });
		var on = keys.filter(function (p) { return state.combos[p.join('|')].on; }).length;

		var head = el('div', { class: 'whdv-grid__head' });
		head.appendChild(el('h4', { text: I.combos + ' — ' + fmt(I.summary, keys.length, on) }));
		var bulk = el('div', { class: 'whdv-bulk' });
		bulk.appendChild(el('span', { class: 'whdv-muted', text: I.bulk + ':' }));
		[['regular_price', I.regular], ['sale_price', I.sale], ['stock', I.stock]].forEach(function (f) {
			var inp = el('input', { type: 'text', class: 'whdv-bulk__input', placeholder: f[1] });
			bulk.appendChild(inp);
			bulk.appendChild(el('button', { type: 'button', class: 'button button-small', text: I.applyAll, onclick: function () {
				keys.forEach(function (p) { state.combos[p.join('|')][f[0]] = inp.value; });
				touch(); render();
			} }));
		});
		bulk.appendChild(el('button', { type: 'button', class: 'button-link', text: I.allOn, onclick: function () { setAll(keys, true); } }));
		bulk.appendChild(el('button', { type: 'button', class: 'button-link', text: I.allOff, onclick: function () { setAll(keys, false); } }));
		head.appendChild(bulk);
		wrap.appendChild(head);

		if (state.levels.length === 1) {
			wrap.appendChild(renderTable(keys, 0));
		} else {
			state.levels[0].options.forEach(function (o) {
				wrap.appendChild(renderGroup(o, keys.filter(function (p) { return p[0] === o.key; })));
			});
		}
		wrap.appendChild(el('p', { class: 'whdv-muted', text: I.stockHint }));
		return wrap;
	}
	function setAll(keys, on) { keys.forEach(function (p) { state.combos[p.join('|')].on = on; }); touch(); render(); }

	function swatchOf(level, o) {
		if (level.type === 'color' && o.color) { return el('span', { class: 'whdv-sw', style: 'background:' + o.color }); }
		if (level.type === 'image' && o.image_url) { return el('img', { class: 'whdv-sw', src: o.image_url, alt: '' }); }
		return el('span', { class: 'whdv-sw whdv-sw--none' });
	}
	function labelOf(parts, from) {
		return parts.slice(from).map(function (k, idx) {
			var lvl = state.levels[from + idx];
			var o = lvl.options.filter(function (x) { return x.key === k; })[0];
			return o ? o.name : k;
		}).join(' / ');
	}

	function renderGroup(o, keys) {
		var g = state.groups[o.key] || (state.groups[o.key] = { image: 0, image_url: '' });
		var box = el('div', { class: 'whdv-group' });
		var h = el('div', { class: 'whdv-group__head' });
		h.appendChild(swatchOf(state.levels[0], o));
		h.appendChild(el('strong', { text: o.name }));
		var on = keys.filter(function (p) { return state.combos[p.join('|')].on; }).length;
		h.appendChild(el('span', { class: 'whdv-muted', text: on + '/' + keys.length }));
		var imgBtn = el('button', { type: 'button', class: 'button button-small whdv-img-btn', onclick: function () { pickImage(function (id, url) { g.image = id; g.image_url = url; touch(); render(); }); } });
		if (g.image_url) { imgBtn.appendChild(el('img', { src: g.image_url, alt: '' })); }
		imgBtn.appendChild(document.createTextNode(' ' + fmt(I.groupImage, o.name)));
		h.appendChild(imgBtn);
		if (g.image) { h.appendChild(el('button', { type: 'button', class: 'button-link', text: I.clear, onclick: function () { g.image = 0; g.image_url = ''; touch(); render(); } })); }
		h.appendChild(el('button', { type: 'button', class: 'button-link', text: I.allOn, onclick: function () { setAll(keys, true); } }));
		h.appendChild(el('button', { type: 'button', class: 'button-link', text: I.allOff, onclick: function () { setAll(keys, false); } }));
		box.appendChild(h);
		box.appendChild(el('div', { class: 'whdv-group__body' }, [renderTable(keys, 1)]));
		return box;
	}

	function renderTable(keys, from) {
		var t = el('table', { class: 'widefat striped whdv-table' });
		var tr = el('tr');
		[I.enabled, '', I.sku, I.regular + ' (' + D.currency + ')', I.sale, I.stock, I.image].forEach(function (h) { tr.appendChild(el('th', { text: h })); });
		t.appendChild(el('thead', {}, [tr]));
		var tb = el('tbody');
		keys.forEach(function (p) {
			var k = p.join('|'), c = state.combos[k];
			var row = el('tr', { class: c.on ? '' : 'whdv-off' });
			var cb = el('input', { type: 'checkbox', checked: c.on });
			cb.addEventListener('change', function () { c.on = cb.checked; row.classList.toggle('whdv-off', !c.on); touch(); });
			row.appendChild(el('td', { class: 'whdv-table__on' }, [cb]));
			row.appendChild(el('td', { class: 'whdv-table__name' }, [el('span', { text: labelOf(p, from) }), c.id ? el('small', { class: 'whdv-muted', text: ' #' + c.id }) : null]));
			[['sku', 'text'], ['regular_price', 'text'], ['sale_price', 'text'], ['stock', 'number']].forEach(function (f) {
				var inp = el('input', { type: f[1], value: c[f[0]], class: 'whdv-in whdv-in--' + f[0], min: f[1] === 'number' ? '0' : null, step: f[1] === 'number' ? '1' : null });
				inp.addEventListener('input', function () { c[f[0]] = inp.value; touch(); });
				row.appendChild(el('td', {}, [inp]));
			});
			var imgTd = el('td', { class: 'whdv-table__img' });
			var ib = el('button', { type: 'button', class: 'whdv-img-btn whdv-img-btn--cell', title: I.pickImage, onclick: function () { pickImage(function (id, url) { c.image = id; c.image_url = url; touch(); render(); }); } });
			if (c.image_url) { ib.appendChild(el('img', { src: c.image_url, alt: '' })); } else { ib.textContent = '+'; }
			imgTd.appendChild(ib);
			if (c.image) { imgTd.appendChild(el('button', { type: 'button', class: 'button-link', text: '×', title: I.clear, onclick: function () { c.image = 0; c.image_url = ''; touch(); render(); } })); }
			row.appendChild(imgTd);
			tb.appendChild(row);
		});
		t.appendChild(tb);
		return t;
	}

	/* ───────── boot ───────── */

	render();
	var form = document.getElementById('post');
	if (form) { form.addEventListener('submit', sync); }
})();
