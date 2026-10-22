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

	if (moments.length) {
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
