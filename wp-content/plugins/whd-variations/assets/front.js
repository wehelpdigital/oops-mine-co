/* WHD — Variation Tiers: one level at a time on the product page.
   Drives WooCommerce's own variation form (and the swatches the theme / Variation Swatches plugin render
   from it): rows are the tiers in order; a row is shown once every row above it has a value, options that
   don't exist for the chosen higher levels are hidden, and a level with a single remaining option is picked
   automatically. jQuery is required because WooCommerce fires its events through jQuery. */
(function ($) {
	'use strict';

	var S = (window.WHDV_FRONT && WHDV_FRONT.settings) || {};
	var on = function (k) { return String(S[k]) === '1' || S[k] === true; };

	function setup($form) {
		if ($form.data('whdv')) { return; }
		var $rows = $form.find('table.variations tr').filter(function () { return $(this).find('select[name^="attribute_"]').length > 0; });
		if ($rows.length < 1) { return; }
		$form.data('whdv', true).addClass('whdv-form');

		$rows.each(function (i) {
			var $tr = $(this).addClass('whdv-level whdv-level-' + (i + 1)).attr('data-level', i + 1);
			if (on('step_labels')) {
				var $label = $tr.find('td.label label').first();
				$label.before('<span class="whdv-step" aria-hidden="true">' + (i + 1) + '</span>');
				$label.after('<span class="whdv-chosen"></span>');
			}
		});

		var pending = false;
		function schedule() {
			if (pending) { return; }
			pending = true;
			setTimeout(function () { pending = false; update(); }, 0);
		}

		function update() {
			var unlocked = true;
			$rows.each(function (i) {
				var $tr = $(this), $sel = $tr.find('select[name^="attribute_"]').first(), val = $sel.val() || '';
				var visible = !on('progressive') || unlocked;
				$tr.toggleClass('whdv-locked', !visible);

				if (!visible && val) { $sel.val('').trigger('change'); val = ''; }
				if (on('step_labels')) {
					$tr.find('.whdv-chosen').text(val ? $sel.find('option:selected').text() : '');
					$tr.find('.whdv-step').text(val ? '✓' : String(i + 1));
					$tr.toggleClass('whdv-done', !!val);
				}
				if (on('hide_unavailable')) {
					$tr.find('li.variable-item').each(function () {
						var $li = $(this);
						$li.toggleClass('whdv-hidden', visible && $li.hasClass('disabled') && !$li.hasClass('selected'));
					});
				}
				if (!visible) { return; }
				if (on('auto_select') && !val) {
					var $left = $sel.find('option').filter(function () { return this.value !== '' && !this.disabled; });
					if ($left.length === 1) { $sel.val($left.val()).trigger('change'); }
				}
				unlocked = unlocked && !!val;
			});
		}

		$form.on('woocommerce_update_variation_values reset_data', schedule);
		$form.on('change', 'select[name^="attribute_"]', schedule);
		update();
	}

	$(function () {
		$('.variations_form').each(function () { setup($(this)); });
	});
	// Forms initialised later (quick view, AJAX-loaded content).
	$(document).on('wc_variation_form', '.variations_form', function () { setup($(this)); });
})(jQuery);
