/* Oops, Mine Co. — the returns and exchanges request form (inc/returns.php).
   Logged in: choose one of your orders. As a guest: find it by number and email. Then tick the
   pieces, say why, add photos, send — all in place, through admin-ajax. */
(function () {
	'use strict';

	var root = document.querySelector('[data-omc-returns]');
	if (!root) { return; }

	var ajax = root.getAttribute('data-ajax');
	var nonce = root.getAttribute('data-nonce');
	var orders = [];
	try { orders = JSON.parse(root.getAttribute('data-orders') || '[]'); } catch (e) { orders = []; }
	var form = root.querySelector('[data-omc-returns-form]');
	var find = root.querySelector('[data-omc-returns-find]');
	var select = root.querySelector('[data-omc-returns-orders]');
	var list = form.querySelector('.omc-returns__list');
	var orderLine = form.querySelector('.omc-returns__order');
	var note = form.querySelector('.omc-returns__actions .omc-returns__note');
	var done = root.querySelector('.omc-returns__done');
	var swap = form.querySelector('.omc-returns__swap');

	function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

	function post(data) {
		data.append('nonce', nonce);
		return fetch(ajax, { method: 'POST', body: data, credentials: 'same-origin' })
			.then(function (r) { return r.json().catch(function () { return { success: false }; }); })
			.then(function (res) {
				if (!res || !res.success) { throw new Error((res && res.data && res.data.message) || root.getAttribute('data-error')); }
				return res.data;
			});
	}

	/* One order's pieces, ticked off one by one. */
	function show(order) {
		if (!order) { form.hidden = true; return; }
		form.querySelector('[name="token"]').value = order.token;
		orderLine.innerHTML = '<b>#' + esc(order.number) + '</b> · ' + esc(order.date);
		list.innerHTML = '';
		order.items.forEach(function (it) {
			var li = document.createElement('li');
			li.className = 'omc-returns__line';
			var qty = '';
			if (it.qty > 1) {
				qty = '<label class="omc-returns__qty"><span class="screen-reader-text">' + esc(root.getAttribute('data-qty')) + '</span><select data-qty>';
				for (var q = 1; q <= it.qty; q++) { qty += '<option value="' + q + '"' + (q === it.qty ? ' selected' : '') + '>' + q + '</option>'; }
				qty += '</select></label>';
			}
			li.innerHTML = '<label class="omc-returns__piece"><input type="checkbox" data-item="' + it.id + '"' + (1 === order.items.length ? ' checked' : '') + '>' +
				(it.thumb ? '<img src="' + esc(it.thumb) + '" alt="">' : '<span class="omc-returns__ph"></span>') +
				'<span class="omc-returns__pname">' + esc(it.name) + (it.meta ? '<small>' + esc(it.meta) + '</small>' : '') + '</span></label>' + qty;
			list.appendChild(li);
		});
		form.hidden = false;
		note.textContent = '';
	}

	if (select) {
		select.addEventListener('change', function () { show(orders[parseInt(select.value, 10)]); });
		var pick = root.getAttribute('data-pick');
		if (pick) {
			orders.forEach(function (o, i) { if (String(o.number) === pick) { select.value = String(i); show(o); } });
		}
	}

	var guest = root.querySelector('[data-omc-returns-guest]');
	if (guest && find) {
		guest.addEventListener('click', function () {
			find.hidden = false;
			guest.closest('p').hidden = true;
			find.querySelector('input').focus();
		});
	}

	if (find) {
		var findNote = find.querySelector('.omc-returns__note');
		find.addEventListener('submit', function (e) {
			e.preventDefault();
			var btn = find.querySelector('button');
			var data = new FormData(find);
			data.append('action', 'omc_returns_find');
			btn.disabled = true;
			findNote.textContent = '';
			findNote.classList.remove('is-error');
			post(data).then(function (order) {
				show(order);
				form.scrollIntoView({ behavior: 'smooth', block: 'start' });
			}).catch(function (err) {
				findNote.textContent = err.message;
				findNote.classList.add('is-error');
				form.hidden = true;
			}).then(function () { btn.disabled = false; });
		});
	}

	form.addEventListener('change', function (e) {
		if (e.target.name === 'want') { swap.hidden = e.target.value !== 'exchange'; }
		if (e.target.name === 'photos[]' && e.target.files && e.target.files.length > 3) {
			note.textContent = '3 photos at most — the first three will be sent.';
		}
	});

	form.addEventListener('submit', function (e) {
		e.preventDefault();
		var data = new FormData(form);
		var any = false;
		list.querySelectorAll('[data-item]').forEach(function (box) {
			if (box.checked) {
				var q = box.closest('li').querySelector('[data-qty]');
				data.append('items[' + box.getAttribute('data-item') + ']', q ? q.value : '1');
				any = true;
			}
		});
		note.classList.add('is-error');
		if (!any) { note.textContent = root.getAttribute('data-choose'); return; }
		if (!form.querySelector('[name="reason"]').value) { note.textContent = root.getAttribute('data-reason'); form.querySelector('[name="reason"]').focus(); return; }
		note.classList.remove('is-error');
		note.textContent = '…';
		var btn = form.querySelector('button[type="submit"]');
		btn.disabled = true;
		data.append('action', 'omc_returns_submit');
		post(data).then(function (res) {
			form.hidden = true;
			if (find) { find.hidden = true; }
			if (select) { select.closest('.omc-returns__row').hidden = true; }
			done.textContent = res.message;
			done.hidden = false;
			done.scrollIntoView({ behavior: 'smooth', block: 'center' });
		}).catch(function (err) {
			note.textContent = err.message;
			note.classList.add('is-error');
			btn.disabled = false;
		});
	});
}());
