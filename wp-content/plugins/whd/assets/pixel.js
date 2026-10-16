/**
 * The two Meta events that happen without a page load.
 *
 * Everything else the pixel reports is printed into the page it belongs to, where it is certain
 * and needs no JavaScript. These two do not have a page of their own: WooCommerce's add-to-cart
 * button answers over AJAX and stays put, and a newsletter form reports back in place.
 *
 * Both fire on something that has actually happened — WooCommerce's own added_to_cart event, and
 * the theme's omc:subscribed — rather than on a click, which may yet fail.
 */
( function () {
	'use strict';

	var S = window.WHD_PIXEL || {};

	function track( event, params ) {
		if ( window.fbq ) {
			window.fbq( 'track', event, params || {} );
		}
	}

	/* ─── into the basket ───
	   WooCommerce fires this through jQuery, so jQuery is where it has to be heard. */
	if ( S.cart && window.jQuery ) {
		window.jQuery( document.body ).on( 'added_to_cart', function ( e, fragments, hash, button ) {
			var params = { content_type: 'product', currency: S.currency };
			if ( button && button.data ) {
				var id = button.data( 'product_id' );
				var qty = parseInt( button.data( 'quantity' ), 10 ) || 1;
				if ( id ) {
					params.content_ids = [ String( id ) ];
					params.contents = [ { id: String( id ), quantity: qty } ];
				}
			}
			track( 'AddToCart', params );
		} );
	}

	/* ─── saved for later ─── */
	if ( S.wishlist && window.jQuery ) {
		window.jQuery( document.body ).on( 'added_to_wishlist yith_wcwl_add_item_response', function () {
			track( 'AddToWishlist', { currency: S.currency } );
		} );
	}

	/* ─── an email address left behind ───
	   The theme announces a successful subscribe on the document. Listening for the submit
	   instead would count the ones that bounced. */
	if ( S.lead ) {
		document.addEventListener( 'omc:subscribed', function ( e ) {
			track( 'Lead', {
				content_name: ( e.detail && e.detail.source ) || 'newsletter',
				currency: S.currency
			} );
		} );
	}
}() );
