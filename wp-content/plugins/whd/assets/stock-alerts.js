/**
 * The "notify me when it's back" form on a product page.
 *
 * The form is printed once and shown when it applies. For a simple product that is out of stock
 * it applies immediately; for a variable one it waits until a shopper has picked a combination
 * WooCommerce reports as unavailable, because until then there is no particular size to ask about.
 *
 * jQuery, because WooCommerce announces variation changes through jQuery events and there is no
 * other way to hear them.
 */
( function ( $ ) {
	'use strict';

	var D = window.WHD_STOCK || {};
	var T = ( D.i18n || {} );

	$( function () {
		var $box = $( '#whd-stock' );
		if ( ! $box.length ) {
			return;
		}

		var $form = $box.find( '.whd-stock__form' );
		var $which = $box.find( '.whd-stock__which' );
		var $note = $box.find( '.whd-stock__note' );
		var $variationField = $form.find( 'input[name="variation_id"]' );
		var $submit = $box.find( '.whd-stock__submit' );
		var variable = $box.attr( 'data-variable' ) === '1';

		function show( variationId, label ) {
			$variationField.val( variationId || 0 );
			$which.text( label ? '— ' + label : '' );
			$box.addClass( 'is-open' );
		}

		function hide() {
			$box.removeClass( 'is-open' );
			$note.text( '' ).removeClass( 'is-ok is-bad' );
		}

		if ( ! variable ) {
			show( 0, '' );
		}

		/* ── Variable products ──
		   found_variation fires with the whole variation object, including whether it is in stock
		   and the name of the combination. hide_variation fires when the choice is incomplete or
		   the combination does not exist at all — neither is something to collect an address for. */
		var $wcForm = $( '.variations_form' ).first();
		if ( variable && $wcForm.length ) {
			$wcForm.on( 'found_variation', function ( event, variation ) {
				if ( variation && variation.is_in_stock === false ) {
					show( variation.variation_id, labelFor( variation ) );
				} else {
					hide();
				}
			} );
			$wcForm.on( 'hide_variation reset_data', hide );
		}

		/** "Black / M" from the variation's own attributes, falling back to what WooCommerce prints. */
		function labelFor( variation ) {
			var bits = [];
			$.each( variation.attributes || {}, function ( key, value ) {
				if ( ! value ) {
					return;
				}
				var name = key.replace( /^attribute_(pa_)?/, '' );
				var $sel = $wcForm.find( 'select[name="' + key + '"]' );
				var text = $sel.length ? $sel.find( 'option[value="' + value + '"]' ).text() : '';
				bits.push( text || decodeURIComponent( String( value ) ).replace( /-/g, ' ' ) );
				void name;
			} );
			return bits.join( ' / ' );
		}

		/* ── Submitting ── */
		$form.on( 'submit', function ( e ) {
			e.preventDefault();
			var email = $form.find( 'input[name="email"]' ).val();
			if ( ! email ) {
				return;
			}

			$submit.prop( 'disabled', true ).text( T.sending || 'Sending…' );
			$note.text( '' ).removeClass( 'is-ok is-bad' );

			$.post( D.ajax, {
				action: 'whd_stock_alert',
				nonce: D.nonce,
				product_id: $box.attr( 'data-product' ),
				variation_id: $variationField.val(),
				email: email
			} ).done( function ( res ) {
				if ( res && res.success ) {
					$note.text( res.data.message ).addClass( 'is-ok' );
					$form.slideUp( 180 );
				} else {
					$note.text( ( res && res.data && res.data.message ) || T.error ).addClass( 'is-bad' );
				}
			} ).fail( function () {
				$note.text( T.error ).addClass( 'is-bad' );
			} ).always( function () {
				$submit.prop( 'disabled', false ).text( T.button || 'Notify me' );
			} );
		} );
	} );
}( jQuery ) );
