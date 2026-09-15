/* Oops, Mine Co. — small front-end enhancements (no dependencies). */
(function () {
	'use strict';
	document.documentElement.classList.add('js');

	/* Reveal sections as they scroll into view. */
	var reveal = document.querySelectorAll('.omc-reveal');
	if (reveal.length && 'IntersectionObserver' in window) {
		var io = new IntersectionObserver(function (entries) {
			entries.forEach(function (e) {
				if (e.isIntersecting) { e.target.classList.add('is-in'); io.unobserve(e.target); }
			});
		}, { rootMargin: '0px 0px -10% 0px', threshold: 0.08 });
		reveal.forEach(function (el) { io.observe(el); });
	} else {
		reveal.forEach(function (el) { el.classList.add('is-in'); });
	}

	/* Typewriter: the element's own word first, then each word in data-words (pipe-separated), typed and deleted in turn. */
	document.querySelectorAll('.js-omc-type').forEach(function (el) {
		var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
		var words = (el.getAttribute('data-words') || '').split('|').map(function (w) { return w.trim(); }).filter(Boolean);
		var base = el.textContent.trim();
		if (!words.length || reduce) { return; }
		var list = [base].concat(words), wi = 0, ci = base.length, deleting = false, timer = null, running = false;
		el.setAttribute('aria-label', base);
		var tick = function () {
			var word = list[wi];
			if (!deleting) {
				ci++;
				el.textContent = word.slice(0, ci);
				if (ci >= word.length) { deleting = true; timer = setTimeout(tick, 2400); return; }
				timer = setTimeout(tick, 80 + Math.random() * 70);
				return;
			}
			ci--;
			el.textContent = word.slice(0, ci);
			if (ci <= 0) { deleting = false; wi = (wi + 1) % list.length; timer = setTimeout(tick, 350); return; }
			timer = setTimeout(tick, 40);
		};
		var start = function () { if (!running) { running = true; el.classList.add('is-typing'); deleting = true; timer = setTimeout(tick, 1800); } };
		var stop = function () { running = false; clearTimeout(timer); };
		if ('IntersectionObserver' in window) {
			new IntersectionObserver(function (entries) { if (entries[0].isIntersecting) { start(); } else { stop(); } }, { threshold: 0.5 }).observe(el);
		} else { start(); }
	});

	/* Banners with several photos: cross-fade through them, pause while hovered, only while visible. */
	(function () {
		var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
		document.querySelectorAll('.js-omc-fade').forEach(function (banner) {
			var imgs = banner.querySelectorAll('.omc-banner__img');
			if (imgs.length < 2 || reduce) { return; }
			var delay = parseInt(banner.getAttribute('data-fade'), 10) || 4500;
			var index = 0, timer = null, visible = false, hovered = false;
			var show = function (i) {
				imgs[index].classList.remove('is-active');
				imgs[index].setAttribute('aria-hidden', 'true');
				index = (i + imgs.length) % imgs.length;
				imgs[index].classList.add('is-active');
				imgs[index].removeAttribute('aria-hidden');
			};
			var stop = function () { if (timer) { clearInterval(timer); timer = null; } };
			var start = function () { stop(); if (visible && !hovered) { timer = setInterval(function () { show(index + 1); }, delay); } };
			banner.addEventListener('pointerenter', function () { hovered = true; stop(); });
			banner.addEventListener('pointerleave', function () { hovered = false; start(); });
			if ('IntersectionObserver' in window) {
				new IntersectionObserver(function (entries) { visible = entries[0].isIntersecting; start(); }, { threshold: 0.3 }).observe(banner);
			} else { visible = true; start(); }
			document.addEventListener('visibilitychange', function () { if (document.hidden) { stop(); } else { start(); } });
		});
	})();

	/* Square carousel: native snap-scrolling track, arrow buttons, keyboard, gentle looping autoplay. */
	document.querySelectorAll('.js-omc-carousel').forEach(function (root) {
		var track = root.querySelector('.omc-carousel__track');
		if (!track || track.children.length < 2) { return; }
		var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
		var behavior = reduce ? 'auto' : 'smooth';
		var step = function () {
			var item = track.children[0].getBoundingClientRect();
			var gap = parseFloat(getComputedStyle(track).columnGap) || 0;
			return item.width + gap;
		};
		var atEnd = function () { return track.scrollLeft + track.clientWidth >= track.scrollWidth - 2; };
		var go = function (dir) {
			if (dir > 0 && atEnd()) { track.scrollTo({ left: 0, behavior: behavior }); return; }
			if (dir < 0 && track.scrollLeft <= 2) { track.scrollTo({ left: track.scrollWidth, behavior: behavior }); return; }
			track.scrollBy({ left: dir * step(), behavior: behavior });
		};
		var prev = root.querySelector('.omc-carousel__btn--prev');
		var next = root.querySelector('.omc-carousel__btn--next');
		if (prev) { prev.addEventListener('click', function () { go(-1); }); }
		if (next) { next.addEventListener('click', function () { go(1); }); }
		track.addEventListener('keydown', function (e) {
			if (e.key === 'ArrowRight') { e.preventDefault(); go(1); }
			if (e.key === 'ArrowLeft') { e.preventDefault(); go(-1); }
		});

		var delay = parseInt(root.getAttribute('data-autoplay'), 10) || 0;
		if (!delay || reduce) { return; }
		var timer = null, paused = false, visible = false, resumeTimer = null;
		var stop = function () { if (timer) { clearInterval(timer); timer = null; } };
		var start = function () { stop(); if (!paused && visible) { timer = setInterval(function () { go(1); }, delay); } };
		var pause = function () { paused = true; stop(); clearTimeout(resumeTimer); };
		var resume = function (after) { clearTimeout(resumeTimer); resumeTimer = setTimeout(function () { paused = false; start(); }, after || 0); };
		root.addEventListener('pointerenter', pause);
		root.addEventListener('pointerleave', function () { resume(600); });
		root.addEventListener('focusin', pause);
		root.addEventListener('focusout', function () { resume(600); });
		track.addEventListener('touchstart', pause, { passive: true });
		track.addEventListener('touchend', function () { resume(5000); }, { passive: true });
		if ('IntersectionObserver' in window) {
			new IntersectionObserver(function (entries) { visible = entries[0].isIntersecting; start(); }, { threshold: 0.4 }).observe(root);
		} else { visible = true; start(); }
		document.addEventListener('visibilitychange', function () { if (document.hidden) { stop(); } else { start(); } });
	});

	/* Newsletter: submit in place. */
	document.querySelectorAll('.js-omc-newsletter').forEach(function (form) {
		var note = form.querySelector('.omc-newsletter__note');
		var button = form.querySelector('button[type="submit"]');
		form.addEventListener('submit', function (ev) {
			ev.preventDefault();
			var email = form.querySelector('input[type="email"]');
			if (!email.value || !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email.value)) {
				note.textContent = 'Please enter a valid email address.';
				note.classList.add('is-error');
				email.focus();
				return;
			}
			note.classList.remove('is-error');
			note.textContent = 'One moment…';
			button.disabled = true;
			var data = new FormData(form);
			fetch(form.action, { method: 'POST', body: data, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (res && res.success) {
						note.textContent = res.data.message;
						form.reset();
					} else {
						note.textContent = (res && res.data && res.data.message) || 'Something went wrong. Please try again.';
						note.classList.add('is-error');
					}
				})
				.catch(function () {
					note.textContent = 'Something went wrong. Please try again.';
					note.classList.add('is-error');
				})
				.finally(function () { button.disabled = false; });
		});
	});
})();
