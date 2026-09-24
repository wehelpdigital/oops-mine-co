/**
 * Oops, Mine Co. — cart persuasion layer.
 *
 * Vanilla JS; jQuery is only used to listen to WooCommerce's own events (it
 * fires them through jQuery on document.body) and to click the parent theme's
 * drawer close button, which the theme binds with jQuery.
 *
 * Markup: inc/cart.php. Styles: assets/css/omc-cart.css.
 */
(function () {
	'use strict';

	var BAR = '.omc-ship';
	var painted = {};   // variant -> last painted percent, so a refresh animates from where it was
	var reduce = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);

	/* ── Progress bar ───────────────────────────────────────────────────── */

	function paint(bar) {
		var fill = bar.querySelector('.omc-ship__fill');

		if (!fill) {
			return;
		}

		var key = bar.getAttribute('data-omc-ship') || 'standalone';
		var pct = parseFloat(bar.getAttribute('data-pct'));
		var had = typeof painted[key] === 'number';
		var from = had ? painted[key] : 0;

		if (isNaN(pct)) {
			pct = 0;
		}

		bar.classList.add('omc-ship--ready');
		painted[key] = pct;

		// One pulse the first time the bar reaches the threshold.
		if (!reduce && had && from < 100 && pct >= 100) {
			bar.classList.add('is-just-complete');
			window.setTimeout(function () {
				bar.classList.remove('is-just-complete');
			}, 800);
		}

		if (reduce || from === pct) {
			fill.style.width = pct + '%';
			return;
		}

		fill.style.transition = 'none';
		fill.style.width = from + '%';
		void fill.offsetWidth;          // reflow, so the next width change animates
		fill.style.transition = '';

		window.requestAnimationFrame(function () {
			fill.style.width = pct + '%';
		});
	}

	function sync() {
		var bars = document.querySelectorAll(BAR + '[data-pct]');

		for (var i = 0; i < bars.length; i++) {
			paint(bars[i]);
		}
	}

	var queued = false;

	function syncSoon() {
		if (queued) {
			return;
		}

		queued = true;
		window.requestAnimationFrame(function () {
			queued = false;
			sync();
		});
	}

	/* ── "Continue shopping": close the drawer instead of loading the shop ── */

	function onClick(e) {
		if (e.defaultPrevented || e.button > 0 || e.metaKey || e.ctrlKey || e.shiftKey) {
			return;
		}

		var target = e.target;
		var link = target && target.closest ? target.closest('[data-omc-cart-close]') : null;

		if (!link) {
			return;
		}

		var drawer = link.closest('.js-cart-sidebar');

		// No open drawer (dropdown layout, cart page widget): let the link go to the shop.
		if (!drawer || !drawer.classList.contains('c-shop-sidebar--active')) {
			return;
		}

		var close = drawer.querySelector('.js-cart-sidebar-close');

		if (!close) {
			return;
		}

		e.preventDefault();

		if (window.jQuery) {
			window.jQuery(close).trigger('click');
		} else {
			close.click();
		}
	}

	/* ── Wiring ─────────────────────────────────────────────────────────── */

	function watchFragments() {
		if (!window.MutationObserver) {
			return;
		}

		// WooCommerce replaces `div.widget_shopping_cart_content` wholesale; watching
		// its container catches refreshes that fire no event (cached fragments).
		var hosts = document.querySelectorAll('.widget_shopping_cart_content');
		var seen = [];
		var observer = new MutationObserver(syncSoon);

		for (var i = 0; i < hosts.length; i++) {
			var host = hosts[i].parentNode;

			if (host && seen.indexOf(host) === -1) {
				seen.push(host);
				observer.observe(host, { childList: true, subtree: true });
			}
		}
	}

	function init() {
		document.addEventListener('click', onClick);
		watchFragments();
		sync();

		if (window.jQuery) {
			window.jQuery(document.body).on(
				'added_to_cart removed_from_cart wc_fragments_loaded wc_fragments_refreshed updated_wc_div updated_cart_totals',
				syncSoon
			);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
}());
