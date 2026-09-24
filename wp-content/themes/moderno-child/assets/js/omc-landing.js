/* Oops, Mine Co. — content pages (landing / FAQ / contact / policy).
   Three small enhancements, no dependencies: accordion polish, the sticky
   in-page nav's scroll-spy, and the contact form submitted in place.
   Everything works without this file: <details> opens, the nav links are plain
   anchors, and the form posts to admin-ajax and redirects back. */
(function () {
	'use strict';

	/* ── FAQ accordions ─────────────────────────────────────────────────
	   The markup is native <details>/<summary>. Here we only close the
	   siblings when a group asks for it, and open the answer named in the
	   URL (a link like /faq/#q-how-long-does-delivery-take). */
	var accordions = document.querySelectorAll('.js-omc-faq');
	accordions.forEach(function (group) {
		var exclusive = group.getAttribute('data-exclusive') === '1';
		var items = Array.prototype.slice.call(group.querySelectorAll('details'));
		items.forEach(function (item) {
			item.addEventListener('toggle', function () {
				if (!item.open || !exclusive) { return; }
				items.forEach(function (other) {
					if (other !== item) { other.open = false; }
				});
			});
		});
	});

	var openFromHash = function () {
		var hash = window.location.hash;
		if (!hash || !/^#[A-Za-z][\w-]*$/.test(hash)) { return; }
		var target = document.getElementById(hash.slice(1));
		if (!target) { return; }
		if (target.tagName === 'DETAILS') {
			target.open = true;
			var summary = target.querySelector('summary');
			if (summary) { summary.focus({ preventScroll: true }); }
		}
	};
	if (accordions.length) {
		openFromHash();
		window.addEventListener('hashchange', openFromHash);
	}

	/* ── Sticky in-page nav: mark the section you are reading ───────────── */
	document.querySelectorAll('.js-omc-spy').forEach(function (nav) {
		var links = Array.prototype.slice.call(nav.querySelectorAll('a[href^="#"]'));
		var map = {};
		var targets = [];
		links.forEach(function (link) {
			var id = link.getAttribute('href').slice(1);
			var section = id && document.getElementById(id);
			if (!section) { return; }
			map[id] = link;
			targets.push(section);
		});
		if (!targets.length) { return; }

		var setCurrent = function (id) {
			links.forEach(function (link) { link.classList.remove('is-current'); });
			if (map[id]) {
				map[id].classList.add('is-current');
				map[id].setAttribute('aria-current', 'true');
				links.forEach(function (link) { if (link !== map[id]) { link.removeAttribute('aria-current'); } });
			}
		};

		/* The section you are reading: the last one whose top has passed the
		   upper third of the viewport (the first until you get there). */
		var update = function () {
			var line = window.innerHeight * 0.35;
			var id = targets[0].id;
			for (var i = 0; i < targets.length; i++) {
				if (targets[i].getBoundingClientRect().top <= line) { id = targets[i].id; }
			}
			setCurrent(id);
		};
		update();

		var ticking = false;
		var schedule = function () {
			if (ticking) { return; }
			ticking = true;
			window.requestAnimationFrame(function () { ticking = false; update(); });
		};
		window.addEventListener('scroll', schedule, { passive: true });
		window.addEventListener('resize', schedule, { passive: true });

		/* Mark the clicked link at once, before the smooth scroll lands. */
		nav.addEventListener('click', function (ev) {
			var link = ev.target.closest && ev.target.closest('a[href^="#"]');
			if (link) { setCurrent(link.getAttribute('href').slice(1)); }
		});
	});

	/* ── Contact form: submit in place ──────────────────────────────────── */
	document.querySelectorAll('.js-omc-contact').forEach(function (form) {
		var note = form.querySelector('.omc-form__note');
		var button = form.querySelector('button[type="submit"]');
		var flag = form.querySelector('input[name="omc_js"]');
		if (flag) { flag.value = '1'; }

		var say = function (message, isError) {
			if (!note) { return; }
			note.textContent = message;
			note.classList.toggle('is-error', !!isError);
		};

		form.addEventListener('submit', function (ev) {
			var email = form.querySelector('input[type="email"]');
			var message = form.querySelector('textarea');
			if (email && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email.value)) {
				ev.preventDefault();
				say('Please enter a valid email address.', true);
				email.focus();
				return;
			}
			if (message && !message.value.trim()) {
				ev.preventDefault();
				say('Please add a short message.', true);
				message.focus();
				return;
			}
			if (!window.fetch || !window.FormData) { return; } /* let the browser post it */

			ev.preventDefault();
			say('Sending…', false);
			if (button) { button.disabled = true; }

			var data = new FormData(form);
			/* getAttribute, not form.action: the hidden <input name="action"> shadows that property. */
			fetch(form.getAttribute('action'), { method: 'POST', body: data, credentials: 'same-origin' })
				.then(function (response) { return response.json(); })
				.then(function (result) {
					if (result && result.success) {
						say(result.data.message, false);
						form.reset();
						if (flag) { flag.value = '1'; }
						form.classList.add('is-done');
					} else {
						say((result && result.data && result.data.message) || 'Something went wrong. Please try again.', true);
					}
				})
				.catch(function () {
					say('Something went wrong. Please email hello@oopsmineco.com instead.', true);
				})
				.finally(function () { if (button) { button.disabled = false; } });
		});
	});
})();
