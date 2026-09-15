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

	/* Hero headline: the italic line rotates through alternative phrases (data-phrases, pipe-separated).
	   The printed phrase stays in the markup for crawlers and is the accessible name; each swap lifts
	   the old phrase out and floats the next one in. Runs only while on screen, never under reduced motion. */
	document.querySelectorAll('.js-omc-rotate').forEach(function (el) {
		var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
		var extra = (el.getAttribute('data-phrases') || '').split('|').map(function (w) { return w.trim(); }).filter(Boolean);
		var base = el.textContent.trim();
		if (!extra.length || reduce) { return; }
		var list = [base].concat(extra), i = 0, timer = null, running = false;
		var delay = parseInt(el.getAttribute('data-delay'), 10) || 3200;
		el.setAttribute('aria-label', base);
		var swap = function () {
			el.classList.add('is-out');
			timer = setTimeout(function () {
				i = (i + 1) % list.length;
				el.textContent = list[i];
				el.classList.remove('is-out');
				el.classList.add('is-pre');
				requestAnimationFrame(function () { requestAnimationFrame(function () {
					el.classList.remove('is-pre');
					if (running) { timer = setTimeout(swap, delay); }
				}); });
			}, 520);
		};
		var start = function () { if (!running) { running = true; timer = setTimeout(swap, delay); } };
		var stop = function () { running = false; clearTimeout(timer); };
		if ('IntersectionObserver' in window) {
			new IntersectionObserver(function (entries) { if (entries[0].isIntersecting) { start(); } else { stop(); } }, { threshold: 0.3 }).observe(el);
		} else { start(); }
	});

	/* Facebook Live band: counts down to data-until (ISO 8601). Inside the live window (data-live-window
	   seconds) the card says "live now"; after that, "wrapped". Ticks once a second while on screen. */
	document.querySelectorAll('.js-omc-countdown').forEach(function (box) {
		var until = Date.parse(box.getAttribute('data-until') || '');
		if (isNaN(until)) { return; }
		var windowMs = (parseInt(box.getAttribute('data-live-window'), 10) || 7200) * 1000;
		var cells = {};
		box.querySelectorAll('.omc-live__num').forEach(function (n) { cells[n.getAttribute('data-unit')] = n; });
		var pad = function (n) { return (n < 10 ? '0' : '') + n; };
		var timer = null;
		var tick = function () {
			var diff = until - Date.now();
			box.classList.toggle('is-live', diff <= 0 && diff > -windowMs);
			box.classList.toggle('is-over', diff <= -windowMs);
			var s = Math.max(0, Math.floor(diff / 1000));
			var parts = { d: Math.floor(s / 86400), h: Math.floor((s % 86400) / 3600), m: Math.floor((s % 3600) / 60), s: s % 60 };
			Object.keys(parts).forEach(function (k) { if (cells[k]) { cells[k].textContent = pad(parts[k]); } });
		};
		tick();
		var start = function () { if (!timer) { timer = setInterval(tick, 1000); } };
		var stop = function () { clearInterval(timer); timer = null; };
		if ('IntersectionObserver' in window) {
			new IntersectionObserver(function (entries) { if (entries[0].isIntersecting) { start(); } else { stop(); } }, { threshold: 0.1 }).observe(box);
		} else { start(); }
	});

	/* Testimonial spotlight: one quote at a time, cross-fading. Arrows, arrow keys, swipe; autoplay while on
	   screen, paused while hovered or focused, off under reduced motion. */
	document.querySelectorAll('.js-omc-quotes').forEach(function (root) {
		var slides = root.querySelectorAll('.omc-quotes__slide');
		if (slides.length < 2) { return; }
		var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
		var delay = reduce ? 0 : (parseInt(root.getAttribute('data-autoplay'), 10) || 0);
		var counter = root.querySelector('.omc-quotes__count b');
		var index = 0, timer = null, visible = false, paused = false, downX = null;
		var pad = function (n) { return (n < 10 ? '0' : '') + n; };
		var show = function (i) {
			slides[index].classList.remove('is-active');
			slides[index].setAttribute('aria-hidden', 'true');
			index = (i + slides.length) % slides.length;
			slides[index].classList.add('is-active');
			slides[index].removeAttribute('aria-hidden');
			if (counter) { counter.textContent = pad(index + 1); }
		};
		var stop = function () { if (timer) { clearInterval(timer); timer = null; } };
		var start = function () { stop(); if (delay && visible && !paused) { timer = setInterval(function () { show(index + 1); }, delay); } };
		var prev = root.querySelector('.omc-quotes__btn--prev'), next = root.querySelector('.omc-quotes__btn--next');
		if (prev) { prev.addEventListener('click', function () { show(index - 1); start(); }); }
		if (next) { next.addEventListener('click', function () { show(index + 1); start(); }); }
		root.addEventListener('keydown', function (ev) {
			if (ev.key === 'ArrowLeft') { show(index - 1); start(); ev.preventDefault(); }
			if (ev.key === 'ArrowRight') { show(index + 1); start(); ev.preventDefault(); }
		});
		root.addEventListener('pointerdown', function (ev) { downX = ev.clientX; });
		root.addEventListener('pointerup', function (ev) {
			if (downX === null) { return; }
			var dx = ev.clientX - downX; downX = null;
			if (Math.abs(dx) > 40) { show(dx < 0 ? index + 1 : index - 1); start(); }
		});
		root.addEventListener('pointerenter', function () { paused = true; stop(); });
		root.addEventListener('pointerleave', function () { paused = false; start(); });
		root.addEventListener('focusin', function () { paused = true; stop(); });
		root.addEventListener('focusout', function () { paused = false; start(); });
		if ('IntersectionObserver' in window) {
			new IntersectionObserver(function (entries) { visible = entries[0].isIntersecting; start(); }, { threshold: 0.3 }).observe(root);
		} else { visible = true; start(); }
		document.addEventListener('visibilitychange', function () { if (document.hidden) { stop(); } else { start(); } });
	});

	/* Banners with several photos: cross-fade through them, pause while hovered, only while visible. */
	(function () {
		var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
		document.querySelectorAll('.js-omc-fade').forEach(function (banner) {
			var imgs = banner.querySelectorAll('.omc-banner__img, .omc-hero__img');
			if (imgs.length < 2 || reduce) { return; }
			var delay = parseInt(banner.getAttribute('data-fade'), 10) || 4500;
			var pauseOnHover = banner.getAttribute('data-fade-hover') !== 'no'; /* the hero keeps sliding under the cursor */
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
			if (pauseOnHover) {
				banner.addEventListener('pointerenter', function () { hovered = true; stop(); });
				banner.addEventListener('pointerleave', function () { hovered = false; start(); });
			}
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
			/* getAttribute, not form.action: the hidden <input name="action"> shadows that property and would turn the URL into "[object HTMLInputElement]" */
			fetch(form.getAttribute('action'), { method: 'POST', body: data, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (res && res.success) {
						note.textContent = res.data.message;
						form.reset();
						form.classList.add('is-done');
						var after = form.getAttribute('data-after') && document.querySelector(form.getAttribute('data-after'));
						if (after) { after.hidden = false; } /* e.g. the live card reveals its "open the live" button */
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
