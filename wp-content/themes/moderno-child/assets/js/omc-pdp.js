/**
 * Oops, Mine Co. — product page conversion layer.
 *
 * 1. Moves the "Size guide" trigger beside the Size row of a variable product.
 * 2. Runs the size guide modal (focus trap, Esc, overlay click, cm/in toggle).
 * 3. Makes the review form multipart and checks the photo picks before submit.
 *
 * Vanilla JS. jQuery is only touched when it is already there, to catch the
 * `wc_variation_form` event WooCommerce fires after an AJAX variation form.
 */
(function () {
	'use strict';

	var SIZE_RE = /\bsizes?\b/i;

	/* ───────────────── Size guide: put the trigger beside the Size row ───────────────── */

	function placeSizeGuide() {
		var btn = document.querySelector('[data-omc-sg-move]');

		if (!btn || btn.getAttribute('data-omc-sg-placed') === '1') {
			return;
		}

		var form = btn.closest ? btn.closest('form.variations_form') : null;

		if (!form) {
			form = document.querySelector('form.variations_form');
		}

		if (!form) {
			return;
		}

		var rows = form.querySelectorAll('table.variations tr');

		for (var i = 0; i < rows.length; i++) {
			var row = rows[i];
			var cell = row.querySelector('td.label, th.label');

			if (!cell) {
				continue;
			}

			var label = cell.querySelector('label');
			var select = row.querySelector('select[data-attribute_name], select[name^="attribute_"]');
			var attr = select ? (select.getAttribute('data-attribute_name') || select.getAttribute('name') || '') : '';
			var text = ((label ? label.textContent : cell.textContent) || '') + ' ' + attr.replace(/^attribute_(pa_)?/, '');

			if (!SIZE_RE.test(text.replace(/[_\-]+/g, ' '))) {
				continue;
			}

			cell.appendChild(btn);
			row.classList.add('omc-has-size-guide');
			btn.setAttribute('data-omc-sg-placed', '1');

			var slot = document.querySelector('.omc-sg-slot--variable');

			if (slot && !slot.children.length) {
				slot.parentNode.removeChild(slot);
			}

			return;
		}
	}

	/* ───────────────── Size guide: the modal ───────────────── */

	var modal = null;
	var dialog = null;
	var lastFocus = null;

	var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

	function focusables() {
		if (!dialog) {
			return [];
		}

		return Array.prototype.filter.call(dialog.querySelectorAll(FOCUSABLE), function (el) {
			return el.offsetWidth > 0 || el.offsetHeight > 0 || el === document.activeElement;
		});
	}

	function onKeydown(e) {
		if (!modal || modal.hasAttribute('hidden')) {
			return;
		}

		if (e.key === 'Escape' || e.key === 'Esc') {
			e.preventDefault();
			closeModal();

			return;
		}

		if (e.key !== 'Tab') {
			return;
		}

		var items = focusables();

		if (!items.length) {
			e.preventDefault();

			return;
		}

		var first = items[0];
		var last = items[items.length - 1];

		if (e.shiftKey && (document.activeElement === first || document.activeElement === dialog)) {
			e.preventDefault();
			last.focus();
		} else if (!e.shiftKey && document.activeElement === last) {
			e.preventDefault();
			first.focus();
		}
	}

	function openModal(trigger) {
		if (!modal) {
			return;
		}

		lastFocus = trigger || document.activeElement;
		modal.removeAttribute('hidden');
		document.body.classList.add('omc-sg-open-body');
		document.addEventListener('keydown', onKeydown, true);

		if (dialog) {
			dialog.scrollTop = 0;
			dialog.focus();
		}
	}

	function closeModal() {
		if (!modal || modal.hasAttribute('hidden')) {
			return;
		}

		modal.setAttribute('hidden', '');
		document.body.classList.remove('omc-sg-open-body');
		document.removeEventListener('keydown', onKeydown, true);

		if (lastFocus && typeof lastFocus.focus === 'function' && document.contains(lastFocus)) {
			lastFocus.focus();
		}

		lastFocus = null;
	}

	function setUnit(unit) {
		if (!modal || !unit) {
			return;
		}

		modal.setAttribute('data-unit', unit);

		var buttons = modal.querySelectorAll('[data-omc-sg-unit]');

		for (var i = 0; i < buttons.length; i++) {
			var active = buttons[i].getAttribute('data-omc-sg-unit') === unit;

			buttons[i].classList.toggle('is-active', active);
			buttons[i].setAttribute('aria-pressed', active ? 'true' : 'false');
		}
	}

	function initModal() {
		modal = document.getElementById('omc-size-guide');
		dialog = modal ? modal.querySelector('.omc-sg__dialog') : null;

		// Delegated, so triggers rendered later (quick view, AJAX) keep working.
		document.addEventListener('click', function (e) {
			var target = e.target;

			if (!target || !target.closest) {
				return;
			}

			if (target.closest('[data-omc-sg-open]')) {
				if (!modal) {
					return;
				}

				e.preventDefault();
				openModal(target.closest('[data-omc-sg-open]'));

				return;
			}

			if (target.closest('[data-omc-sg-close]')) {
				e.preventDefault();
				closeModal();

				return;
			}

			var unitBtn = target.closest('[data-omc-sg-unit]');

			if (unitBtn) {
				e.preventDefault();
				setUnit(unitBtn.getAttribute('data-omc-sg-unit'));
			}
		});
	}

	/* ───────────────── Review photos ───────────────── */

	function bytesToMb(bytes) {
		return Math.round((bytes / 1048576) * 10) / 10;
	}

	function initReviewPhotos() {
		var input = document.getElementById('omc_review_photos');

		if (!input) {
			return;
		}

		var form = input.closest ? input.closest('form') : null;

		if (form) {
			// The comment form is printed without an enctype, so a file would
			// never reach PHP. Set it before anyone can submit.
			form.setAttribute('enctype', 'multipart/form-data');
			form.setAttribute('method', 'post');
		}

		var list = document.querySelector('[data-omc-photo-list]');
		var max = parseInt(input.getAttribute('data-omc-max'), 10) || 3;
		var maxSize = parseInt(input.getAttribute('data-omc-max-size'), 10) || 4194304;
		var tooMany = input.getAttribute('data-omc-too-many') || '';
		var tooBig = input.getAttribute('data-omc-too-big') || '';

		function say(message, isError) {
			if (!list) {
				return;
			}

			list.textContent = message || '';
			list.classList.toggle('omc-review-upload__list--error', !!isError);
		}

		input.addEventListener('change', function () {
			var files = input.files ? Array.prototype.slice.call(input.files) : [];

			if (!files.length) {
				say('');

				return;
			}

			if (files.length > max) {
				input.value = '';
				say(tooMany, true);

				return;
			}

			var names = [];

			for (var i = 0; i < files.length; i++) {
				if (files[i].size > maxSize) {
					input.value = '';
					say(tooBig + ' (' + files[i].name + ' — ' + bytesToMb(files[i].size) + ' MB)', true);

					return;
				}

				if (files[i].type && files[i].type.indexOf('image/') !== 0) {
					input.value = '';
					say(files[i].name, true);

					return;
				}

				names.push(files[i].name);
			}

			say(names.join(', '), false);
		});
	}

	/* ───────────────── Boot ───────────────── */

	function boot() {
		initModal();
		placeSizeGuide();
		initReviewPhotos();

		if (window.jQuery) {
			// WooCommerce rebuilds the variations form on quick view / AJAX.
			window.jQuery(document.body).on('wc_variation_form', function () {
				placeSizeGuide();
			});
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
}());
