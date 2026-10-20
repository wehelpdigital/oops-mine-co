/* WHD — a returning shopper's size, picked for them.
   The sizes somebody chose before (size, fit, length, cup… never a colour) live in the whd_fit cookie,
   which the server writes when they add to bag, order, or come back through a reminder. On a variation
   form, a level that is open, still empty and offers that value has it chosen — once: a shopper who
   clears it, or picks another, is left alone. With Variation Tiers it waits until the levels above
   are chosen, so the colour still comes first. jQuery, because WooCommerce fires its events there. */
( function ( $ ) {
	'use strict';

	function remembered() {
		var m = document.cookie.match( /(?:^|;\s*)whd_fit=([^;]*)/ );
		if ( ! m ) {
			return null;
		}
		var text = m[ 1 ];
		// Decoded until it reads as JSON: a cookie from the first version was encoded twice.
		for ( var i = 0; i < 3; i++ ) {
			try {
				var fit = JSON.parse( text );
				return fit && 'object' === typeof fit ? fit : null;
			} catch ( e ) {
				try {
					text = decodeURIComponent( text );
				} catch ( e2 ) {
					return null;
				}
			}
		}
		return null;
	}

	var fit = remembered();
	if ( ! fit || ! Object.keys( fit ).length ) {
		return;
	}

	function apply( $form ) {
		$form.find( 'table.variations select[name^="attribute_"]' ).each( function () {
			var $sel = $( this );
			var key = String( $sel.attr( 'name' ) ).replace( /^attribute_/, '' ).toLowerCase();
			var want = fit[ key ];
			if ( ! want || $sel.val() || $sel.data( 'whdFit' ) ) {
				return;
			}
			var $tr = $sel.closest( 'tr' );
			if ( $tr.hasClass( 'whdv-locked' ) ) {
				return; // a level not reached yet — the ones above it come first
			}
			var option = null;
			$sel.find( 'option' ).each( function () {
				if ( this.value && this.value.toLowerCase() === String( want ).toLowerCase() ) {
					option = this;
				}
			} );
			if ( ! option || option.disabled ) {
				return;
			}
			// The swatch for it, where there is one, must not be greyed out as unavailable.
			var unavailable = false;
			$tr.find( 'li.variable-item' ).each( function () {
				if ( String( $( this ).attr( 'data-value' ) ) === option.value && $( this ).hasClass( 'disabled' ) ) {
					unavailable = true;
				}
			} );
			if ( unavailable ) {
				return;
			}
			$sel.data( 'whdFit', true );
			$sel.val( option.value ).trigger( 'change' );
		} );
	}

	function setup( $form ) {
		if ( $form.data( 'whdFitReady' ) ) {
			return;
		}
		$form.data( 'whdFitReady', true );
		var timer = null;
		// After Variation Tiers has opened or closed its levels for this change, not before.
		var later = function () {
			window.clearTimeout( timer );
			timer = window.setTimeout( function () {
				apply( $form );
			}, 60 );
		};
		$form.on( 'woocommerce_update_variation_values', later );
		$form.on( 'change', 'select[name^="attribute_"]', later );
		// Cleared on purpose: nothing is picked again on this form.
		$form.on( 'click', '.reset_variations', function () {
			$form.find( 'select[name^="attribute_"]' ).data( 'whdFit', true );
		} );
		later();
	}

	$( function () {
		$( '.variations_form' ).each( function () {
			setup( $( this ) );
		} );
	} );
	// Forms that arrive later: quick view, content loaded on scroll.
	$( document ).on( 'wc_variation_form', '.variations_form', function () {
		setup( $( this ) );
	} );
}( jQuery ) );
