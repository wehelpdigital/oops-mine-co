/* Oops, Mine Co. — "Watch & shop" on the home page (inc/reel.php): the style videos one at a time.

   The row of slides is a native scroll-snap track, so a thumb swipes it and a trackpad scrolls it;
   the arrows, the dots and the arrow keys move it one slide. Whichever slide is in view is "current":
   the counter and dots follow it, and a video that leaves it stops — one plays at a time.

   While a video plays, each piece tagged in it (Page Editor → Shoppable videos) slides in over the
   video at its moment and stays its seconds or until the next piece, as on the video page: the same
   window (.omc-vpop), the same rules — it stays while the pointer or focus is on it, × closes it until
   that moment is replayed. Its row beside the video is marked at the same time. The pieces' data and
   the size picker come from the shop kit (omc-shop.js), asked for when they are needed. No dependencies. */
(function () {
	'use strict';

	var root = document.querySelector('[data-omc-reel]');
	if (!root) { return; }
	var track = root.querySelector('.omc-reel__track');
	var slides = Array.prototype.slice.call(root.querySelectorAll('.omc-reel__slide'));
	if (!track || !slides.length) { return; }
	var dots = Array.prototype.slice.call(root.querySelectorAll('.omc-reel__dot'));
	var nowEl = root.querySelector('.omc-reel__now');
	var still = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	var current = 0;

	var esc = function (s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; };
	var num = function (el, attr, fallback) { var n = parseInt(el.getAttribute(attr), 10); return isNaN(n) ? fallback : n; };
	var pad = function (n) { return (n < 10 ? '0' : '') + n; };

	/* ─────────────────────────── moving along ─────────────────────────── */

	function go(i) {
		i = Math.max(0, Math.min(slides.length - 1, i));
		track.scrollTo({ left: slides[i].offsetLeft - slides[0].offsetLeft, behavior: still ? 'auto' : 'smooth' });
	}

	function settle() {
		var best = 0, dist = Infinity;
		slides.forEach(function (s, i) {
			var d = Math.abs((s.offsetLeft - slides[0].offsetLeft) - track.scrollLeft);
			if (d < dist) { dist = d; best = i; }
		});
		if (best === current) { return; }
		current = best;
		slides.forEach(function (s, i) {
			s.classList.toggle('is-current', i === current);
			if (i !== current) { stop(s); }
		});
		dots.forEach(function (d, i) { d.classList.toggle('is-on', i === current); d.setAttribute('aria-selected', i === current ? 'true' : 'false'); });
		if (nowEl) { nowEl.textContent = pad(current + 1); }
		root.querySelectorAll('[data-reel="prev"]').forEach(function (b) { b.disabled = current === 0; });
		root.querySelectorAll('[data-reel="next"]').forEach(function (b) { b.disabled = current === slides.length - 1; });
	}

	var ticking = false;
	track.addEventListener('scroll', function () {
		if (ticking) { return; }
		ticking = true;
		requestAnimationFrame(function () { ticking = false; settle(); });
	}, { passive: true });

	root.addEventListener('click', function (e) {
		var b = e.target.closest('[data-reel], [data-go]');
		if (!b) { return; }
		if (b.hasAttribute('data-go')) { go(parseInt(b.getAttribute('data-go'), 10) || 0); return; }
		go(current + ('next' === b.getAttribute('data-reel') ? 1 : -1));
	});
	track.addEventListener('keydown', function (e) {
		if (e.target !== track) { return; }
		if ('ArrowRight' === e.key) { e.preventDefault(); go(current + 1); }
		if ('ArrowLeft' === e.key) { e.preventDefault(); go(current - 1); }
	});
	root.querySelectorAll('[data-reel="prev"]').forEach(function (b) { b.disabled = true; });

	/* ─────────────────────────── one video at a time ─────────────────────────── */

	function stop(slide) {
		var v = slide.querySelector('video');
		if (v && !v.paused) { v.pause(); }
	}

	function play(slide, at) {
		var v = slide.querySelector('video');
		if (!v) { return; }
		slides.forEach(function (s) { if (s !== slide) { stop(s); } });
		slide.classList.add('is-started');
		v.controls = true;
		var start = function () {
			if (typeof at === 'number') { try { v.currentTime = at; } catch (e) { /* not seekable yet */ } }
			var p = v.play();
			if (p && typeof p.catch === 'function') { p.catch(function () { /* refused: the controls are there */ }); }
		};
		if (typeof at === 'number' && v.readyState < 1) {
			v.addEventListener('loadedmetadata', start, { once: true });
			if (v.preload === 'none') { v.preload = 'metadata'; }
			v.load();
		} else {
			start();
		}
	}

	/* A video that has scrolled off the screen stops: nobody wants sound from a section they left. */
	if ('IntersectionObserver' in window) {
		new IntersectionObserver(function (entries) {
			entries.forEach(function (en) { if (!en.isIntersecting) { slides.forEach(stop); } });
		}, { threshold: 0.2 }).observe(track);
	}

	/* ─────────────────────────── each slide ─────────────────────────── */

	slides.forEach(function (slide) {
		var video = slide.querySelector('video');
		var frame = slide.querySelector('.omc-reel__frame');
		var rows = Array.prototype.slice.call(slide.querySelectorAll('.omc-reel__piece'));
		var pop = null, popAt = -1, held = false, closed = {};

		slide.querySelector('.omc-reel__play').addEventListener('click', function () { play(slide); });
		video.addEventListener('click', function () { if (!slide.classList.contains('is-started')) { play(slide); } });
		video.addEventListener('play', function () { slides.forEach(function (s) { if (s !== slide) { stop(s); } }); slide.classList.add('is-playing', 'is-started'); sync(); });
		video.addEventListener('pause', function () { slide.classList.remove('is-playing'); });
		video.addEventListener('ended', function () { slide.classList.remove('is-playing'); hidePop(); });

		slide.querySelectorAll('.omc-reel__seek').forEach(function (b) {
			b.addEventListener('click', function () { play(slide, parseInt(b.getAttribute('data-at'), 10) || 0); });
		});

		if (!rows.length) { return; }

		function windowIndex(t) {
			var found = -1;
			rows.forEach(function (m, i) {
				var at = num(m, 'data-at', 0);
				var next = rows[i + 1] ? num(rows[i + 1], 'data-at', Infinity) : Infinity;
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
			var shop = window.OMCShop;
			var piece = shop && shop.piece(rows[i].getAttribute('data-piece'));
			if (!piece) { return; }
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
			var T = shop.i18n || {};
			var label = root.getAttribute('data-label-now') || 'On screen now';
			var bag = '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 8h14l-1.2 12H6.2z"/><path d="M9 8V6.5a3 3 0 0 1 6 0V8"/></svg>';
			pop.setAttribute('aria-label', label + ': ' + piece.name);
			pop.classList.remove('is-open');
			pop.innerHTML = '<a class="omc-vpop__media" href="' + esc(piece.url) + '" tabindex="-1" aria-hidden="true"><img src="' + esc(piece.image) + '" alt=""></a>' +
				'<div class="omc-vpop__body"><p class="omc-vpop__k">' + esc(label) + '</p>' +
				'<a class="omc-vpop__name" href="' + esc(piece.url) + '">' + esc(piece.name) + '</a>' +
				'<span class="omc-vpop__price">' + esc(piece.price) + '</span></div>' +
				'<button type="button" class="omc-vpop__bag" aria-label="' + esc(T.add || 'Add to bag') + '" aria-expanded="false">' + bag + '</button>' +
				'<button type="button" class="omc-vpop__x" aria-label="' + esc(T.close || 'Close') + '">&times;</button>' +
				'<div class="omc-vpop__more" hidden></div>';
			var more = pop.querySelector('.omc-vpop__more');
			var go2 = pop.querySelector('.omc-vpop__bag');
			go2.addEventListener('click', function () {
				if (!piece.stock || piece.choose || ('variable' !== piece.type && !piece.add)) { window.location.href = piece.url; return; }
				if ('variable' !== piece.type) {
					go2.disabled = true;
					shop.add([{ id: piece.id, parent: piece.id }], go2).then(function () {
						go2.classList.add('is-done');
					}).catch(function () { window.location.href = piece.url; }).then(function () { go2.disabled = false; });
					return;
				}
				if (!more.firstChild) { more.appendChild(shop.picker(piece, { compact: true })); }
				more.hidden = !more.hidden;
				pop.classList.toggle('is-open', !more.hidden);
				go2.setAttribute('aria-expanded', more.hidden ? 'false' : 'true');
				if (!more.hidden) { held = true; video.pause(); var first = more.querySelector('select, .omc-pick__add'); if (first) { first.focus(); } }
			});
			pop.querySelector('.omc-vpop__x').addEventListener('click', function () { closed[i] = true; held = false; hidePop(); });
			popAt = i;
			pop.hidden = false;
			requestAnimationFrame(function () { requestAnimationFrame(function () { pop.classList.add('is-in'); }); });
		}

		function sync() {
			var t = video.currentTime;
			// The row of the piece on screen now: the last moment that has begun.
			var on = -1;
			rows.forEach(function (m, i) { if (t >= num(m, 'data-at', 0)) { on = i; } });
			rows.forEach(function (m, i) { m.classList.toggle('is-on', slide.classList.contains('is-started') && i === on); });
			var i = windowIndex(t);
			if (i !== -1 && closed[i]) { i = -1; }
			if (i === popAt || (held && popAt !== -1)) { return; }
			if (i === -1) { hidePop(); } else { showPop(i); }
		}

		video.addEventListener('timeupdate', sync);
		video.addEventListener('seeked', function () {
			// Watching a moment again brings its window back, even after it was closed.
			Object.keys(closed).forEach(function (k) { if (video.currentTime < num(rows[k], 'data-at', 0)) { delete closed[k]; } });
			sync();
		});
	});
})();
