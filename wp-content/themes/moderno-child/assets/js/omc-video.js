/* Oops, Mine Co. — style video pages: chapter jumps, #t= deep links and a "currently playing"
   marker on the chapter list. No dependencies; the player itself is the browser's own. */
(function () {
	'use strict';

	var video = document.querySelector('.js-omc-video');
	if (!video) { return; }

	var chapters = Array.prototype.slice.call(document.querySelectorAll('.js-omc-video-seek'));

	/* The player is preload="none" so the page stays light; asking for a time before any data has
	   arrived is ignored by every browser, so load first and seek once metadata is in. */
	function seek(seconds) {
		var go = function () {
			try { video.currentTime = seconds; } catch (e) { /* seeking not ready yet */ }
			var playing = video.play();
			if (playing && typeof playing.catch === 'function') { playing.catch(function () { /* autoplay refused — the poster stays */ }); }
		};
		if (video.readyState >= 1) { go(); return; }
		video.addEventListener('loadedmetadata', go, { once: true });
		if (video.preload === 'none') { video.preload = 'metadata'; }
		video.load();
	}

	chapters.forEach(function (button) {
		button.addEventListener('click', function () {
			var at = parseInt(button.getAttribute('data-at'), 10) || 0;
			seek(at);
			video.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
		});
	});

	/* Highlight the chapter the video is inside. */
	if (chapters.length) {
		var marks = chapters.map(function (b) { return parseInt(b.getAttribute('data-at'), 10) || 0; });
		video.addEventListener('timeupdate', function () {
			var t = video.currentTime, current = -1;
			for (var i = 0; i < marks.length; i++) { if (t >= marks[i]) { current = i; } }
			chapters.forEach(function (b, i) { b.classList.toggle('is-current', i === current); });
		});
	}

	/* Shoppable moments (inc/video-shop.php): each card seeks to its moment; while the video plays, the
	   card for what is on screen is marked and that piece, with its Add to bag, sits under the player.
	   The picker comes from the shop kit (omc-shop.js), which prints after this file — so it is asked
	   for once the page has finished parsing. */
	var moments = Array.prototype.slice.call(document.querySelectorAll('.omc-vshop__item'));
	var now = document.querySelector('[data-omc-vshop-now]');
	var shown = null;

	function paintNow(li, playing) {
		if (!now || !window.OMCShop) { return; }
		var piece = li ? window.OMCShop.piece(li.getAttribute('data-piece')) : null;
		if (!piece) { now.hidden = true; return; }
		var esc = function (s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; };
		now.innerHTML = '<a class="omc-vshop-now__media" href="' + esc(piece.url) + '" tabindex="-1" aria-hidden="true"><img src="' + esc(piece.image) + '" alt=""></a>' +
			'<div class="omc-vshop-now__body"><p class="omc-vshop-now__k">' + esc(now.getAttribute(playing ? 'data-label-now' : 'data-label-first')) + '</p>' +
			'<a class="omc-vshop-now__name" href="' + esc(piece.url) + '">' + esc(piece.name) + '</a>' +
			'<span class="omc-vshop-now__price">' + esc(piece.price) + '</span></div>';
		now.querySelector('.omc-vshop-now__body').appendChild(window.OMCShop.picker(piece, { compact: true }));
		now.hidden = false;
	}

	function follow() {
		var t = video.currentTime, cur = -1;
		for (var i = 0; i < moments.length; i++) { if (t >= (parseInt(moments[i].getAttribute('data-at'), 10) || 0)) { cur = i; } }
		var li = cur >= 0 ? moments[cur] : moments[0];
		moments.forEach(function (m, i) { m.classList.toggle('is-current', i === cur); });
		if (li !== shown) { shown = li; paintNow(li, cur >= 0 && !video.paused); }
	}

	/* The small window over the player: at each moment its piece slides in over the video and stays
	   for that moment's seconds (data-for), or until the next piece comes in. While somebody is using
	   it — pointer over it, a size being chosen — it stays; × closes it until that moment comes round
	   again. Nothing shows before the video has been started. */
	var frame = video.closest('.omc-video__frame');
	var pop = null, popAt = -1, held = false, started = false, closed = {};
	var num = function (el, attr, fallback) { var n = parseInt(el.getAttribute(attr), 10); return isNaN(n) ? fallback : n; };

	function windowIndex(t) {
		var found = -1;
		moments.forEach(function (m, i) {
			var at = num(m, 'data-at', 0);
			var next = moments[i + 1] ? num(moments[i + 1], 'data-at', Infinity) : Infinity;
			if (t >= at && t < Math.min(at + num(m, 'data-for', 8), next)) { found = i; }
		});
		return found;
	}

	function hidePop() {
		if (!pop || popAt === -1) { return; }
		popAt = -1;
		pop.classList.remove('is-in');
		setTimeout(function () { if (popAt === -1) { pop.hidden = true; } }, 320);
	}

	function showPop(i) {
		var piece = window.OMCShop && window.OMCShop.piece(moments[i].getAttribute('data-piece'));
		if (!piece || !frame) { return; }
		var esc = function (s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; };
		if (!pop) {
			pop = document.createElement('div');
			pop.className = 'omc-vpop';
			pop.setAttribute('role', 'group');
			pop.hidden = true;
			frame.appendChild(pop);
			pop.addEventListener('pointerenter', function () { held = true; });
			pop.addEventListener('pointerleave', function () { held = false; sync(); });
			pop.addEventListener('focusin', function () { held = true; });
			pop.addEventListener('focusout', function () { setTimeout(function () { held = pop.contains(document.activeElement); sync(); }, 0); });
		}
		var T = window.OMCShop.i18n || {};
		var label = (now && now.getAttribute('data-label-now')) || '';
		var bag = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 8h14l-1.2 12H6.2z"/><path d="M9 8V6.5a3 3 0 0 1 6 0V8"/></svg>';
		pop.setAttribute('aria-label', label + ': ' + piece.name);
		pop.classList.remove('is-open');
		// Small by default: the piece and one button. A size is asked for only when the button is pressed.
		pop.innerHTML = '<a class="omc-vpop__media" href="' + esc(piece.url) + '" tabindex="-1" aria-hidden="true"><img src="' + esc(piece.image) + '" alt=""></a>' +
			'<div class="omc-vpop__body"><p class="omc-vpop__k">' + esc(label) + '</p>' +
			'<a class="omc-vpop__name" href="' + esc(piece.url) + '">' + esc(piece.name) + '</a>' +
			'<span class="omc-vpop__price">' + esc(piece.price) + '</span></div>' +
			'<button type="button" class="omc-vpop__bag" aria-label="' + esc(T.add || 'Add to bag') + '" aria-expanded="false">' + bag + '</button>' +
			'<button type="button" class="omc-vpop__x" aria-label="' + esc(T.close || 'Close') + '">&times;</button>' +
			'<div class="omc-vpop__more" hidden></div>';
		var more = pop.querySelector('.omc-vpop__more');
		var go = pop.querySelector('.omc-vpop__bag');
		go.addEventListener('click', function () {
			if (!piece.stock || piece.choose || ('variable' !== piece.type && !piece.add)) { window.location.href = piece.url; return; }
			if ('variable' !== piece.type) {
				go.disabled = true;
				window.OMCShop.add([{ id: piece.id, parent: piece.id }], go).then(function () {
					go.classList.add('is-done');
				}).catch(function () { window.location.href = piece.url; }).then(function () { go.disabled = false; });
				return;
			}
			if (!more.firstChild) { more.appendChild(window.OMCShop.picker(piece, { compact: true })); }
			more.hidden = !more.hidden;
			pop.classList.toggle('is-open', !more.hidden);
			go.setAttribute('aria-expanded', more.hidden ? 'false' : 'true');
			if (!more.hidden) { held = true; var first = more.querySelector('select, .omc-pick__add'); if (first) { first.focus(); } }
		});
		pop.querySelector('.omc-vpop__x').addEventListener('click', function () { closed[i] = true; held = false; hidePop(); });
		popAt = i;
		pop.hidden = false;
		requestAnimationFrame(function () { requestAnimationFrame(function () { pop.classList.add('is-in'); }); });
	}

	function sync() {
		if (!started || !window.OMCShop) { return; }
		var i = windowIndex(video.currentTime);
		if (i !== -1 && closed[i]) { i = -1; }
		if (i === popAt || (held && popAt !== -1)) { return; }
		if (i === -1) { hidePop(); } else { showPop(i); }
	}

	if (moments.length) {
		video.addEventListener('play', function () { started = true; sync(); });
		video.addEventListener('timeupdate', sync);
		video.addEventListener('seeked', function () {
			// Watching a moment again brings its window back, even after it was closed.
			Object.keys(closed).forEach(function (k) { if (video.currentTime < num(moments[k], 'data-at', 0)) { delete closed[k]; } });
			sync();
		});
		document.querySelectorAll('.js-omc-moment').forEach(function (b) {
			b.addEventListener('click', function () {
				seek(parseInt(b.getAttribute('data-at'), 10) || 0);
				video.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
			});
		});
		video.addEventListener('timeupdate', follow);
		video.addEventListener('play', function () { shown = null; follow(); });
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', follow);
		} else {
			follow();
		}
	}

	/* /page/#t=23 jumps straight to that moment — the links Google surfaces as key moments. */
	function fromHash() {
		var m = /(?:^|#)t=(\d+)/.exec(window.location.hash || '');
		if (m) { seek(parseInt(m[1], 10)); }
	}
	window.addEventListener('hashchange', fromHash);
	fromHash();
})();
