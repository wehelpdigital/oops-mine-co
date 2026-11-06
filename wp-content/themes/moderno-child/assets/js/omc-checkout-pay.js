/* Oops, Mine Co. — the checkout's payment section: nothing open until a way to pay is chosen (inc/checkout-pay.php).

   WooCommerce keeps a radio checked underneath the whole time, and the payment plugins react to that as they
   always have. All of this does is hold the view back until the shopper has actually pressed something, by
   classes on the body:

     omc-pay-ready   this script is running; nothing below applies without it, so a failure here leaves the normal list
     omc-pay-none    nothing chosen yet: every form and button hidden
     omc-pay-wallet  the wallets row is open: same, with their buttons showing

   Only a real press counts. WooCommerce clicks the checked radio itself every time it redraws the section. */
(function ($) {
	'use strict';

	var body = document.body;
	var wallets = document.getElementById('omc-pay-wallets');
	var form = $('form.checkout');
	if (!form.length) { return; }

	var picked = false;

	function radios() { return document.querySelectorAll('.woocommerce-checkout input[name="payment_method"]'); }

	/* With one way to pay there is nothing to choose, and with none (a free order) nothing to wait for. */
	function paint() {
		var count = radios().length + (wallets && wallets.classList.contains('has-wallets') ? 1 : 0);
		var waiting = !picked && count > 1;
		body.classList.toggle('omc-pay-none', waiting);
		mark();
	}

	/* The row that looks chosen. The checked radio is not enough to go by while nothing has been picked. */
	function mark() {
		var open = body.classList.contains('omc-pay-wallet');
		document.querySelectorAll('.wc_payment_method').forEach(function (row) {
			var radio = row.querySelector('input[name="payment_method"]');
			row.classList.toggle('is-chosen', !!radio && radio.checked && !open && !body.classList.contains('omc-pay-none'));
		});
		if (wallets) {
			wallets.classList.toggle('is-open', open);
			wallets.querySelector('.omc-pay-wallets__row').setAttribute('aria-expanded', open ? 'true' : 'false');
		}
	}

	body.classList.add('omc-pay-ready');
	paint();

	/* Somebody chose this one. The forms of the others are closed first, so that WooCommerce's quietly checked
	   radio does not flash its form open on the way past. */
	function press(radio) {
		document.querySelectorAll('.wc_payment_method .payment_box').forEach(function (box) {
			if (!box.classList.contains('payment_method_' + radio.value)) { box.style.display = 'none'; }
		});
		picked = true;
		body.classList.remove('omc-pay-wallet');
		paint();
	}

	/* A press on the row is the only real signal. The browser's click on the radio that a label passes along is
	   not marked as trusted, and neither is WooCommerce's, so the label has to be listened to itself. */
	document.addEventListener('click', function (e) {
		var target = e.target;
		if (!target || !target.closest) { return; }
		var label = target.closest('label[for^="payment_method_"]');
		if (label && e.isTrusted) {
			var pressed = document.getElementById(label.getAttribute('for'));
			if (pressed) { press(pressed); }
		} else if (target.matches('input[name="payment_method"]')) {
			if (e.isTrusted) { press(target); } else { mark(); }
		}
	});

	/* The arrow keys move a radio group, and arrive as a change. WooCommerce's own click does too, so it is checked for focus. */
	document.addEventListener('change', function (e) {
		if (e.target && e.target.matches && e.target.matches('input[name="payment_method"]') && e.target === document.activeElement) {
			press(e.target);
		}
	});

	$(document.body).on('updated_checkout payment_method_selected', paint);

	/* Enter in a field would otherwise place an order with the radio nobody chose. */
	form.on('checkout_place_order', function () {
		if (body.classList.contains('omc-pay-none') || body.classList.contains('omc-pay-wallet')) {
			var section = document.querySelector('.omc-pay-head');
			if (section) { section.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
			return false;
		}
		return true;
	});

	/* The wallets. Which of them this device has is only known once the plugins' scripts have looked, so the row
	   appears when a button does. On a device with none, there is no row. */
	if (wallets) {
		var box = wallets.querySelector('.omc-pay-wallets__box');
		var row = wallets.querySelector('.omc-pay-wallets__row');

		var someButton = function () {
			var found = false;
			box.querySelectorAll('#wc-stripe-express-checkout-element, #wcpay-express-checkout-element, #express-checkout-wrapper, .wcpay-express-checkout-wrapper > div').forEach(function (n) {
				found = found || n.offsetHeight > 8;
			});
			return found;
		};
		var look = function () {
			if (!wallets.classList.contains('has-wallets') && someButton()) {
				wallets.classList.add('has-wallets');
				paint();
			}
			return wallets.classList.contains('has-wallets');
		};
		if ('MutationObserver' in window) {
			new MutationObserver(look).observe(box, { subtree: true, childList: true, attributes: true, attributeFilter: ['style', 'class'] });
		}
		var tries = 0;
		var timer = setInterval(function () {
			if (look() || ++tries > 40) { clearInterval(timer); }
		}, 500);

		row.addEventListener('click', function () {
			picked = true;
			body.classList.add('omc-pay-wallet');
			paint();
		});
	}
})(jQuery);
