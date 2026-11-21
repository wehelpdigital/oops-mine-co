/**
 * "Want a text when we go live too?" — the optional second step after an email sign-up on the live card.
 *
 * The theme's newsletter form fires `omc:subscribed` with its answer; for a live-card sign-up that answer
 * carries `whd_sms`, a short-lived token for the address just given. The step (a <template> printed by
 * WHD_Live_Reminders::sms_step) is placed under that form, and posts the token with the number and the
 * consent box to `whd_live_sms`. Email first, phone second, on separate steps: the way Klaviyo and the
 * carriers want SMS consent collected.
 */
( function () {
	'use strict';

	var D = window.WHD_LIVE_SMS || {};
	var T = D.i18n || {};
	var n = 0;

	document.addEventListener( 'omc:subscribed', function ( e ) {
		var d = e.detail || {};
		var token = d.data && d.data.whd_sms;
		var tpl = document.getElementById( 'whd-live-sms-tpl' );
		if ( ! token || ! d.form || ! tpl || ! tpl.content ) {
			return;
		}
		var old = d.form.parentNode && d.form.parentNode.querySelector( '.whd-live-sms' );
		if ( old ) {
			old.remove();
		}
		var box = tpl.content.firstElementChild.cloneNode( true );
		n++;
		var id = 'whd-live-sms-phone-' + n;
		box.querySelector( '[data-id="phone"]' ).id = id;
		box.querySelector( '[data-for="phone"]' ).setAttribute( 'for', id );
		d.form.parentNode.insertBefore( box, d.form.nextSibling );
		bind( box, token );
		window.setTimeout( function () {
			box.classList.add( 'is-in' );
		}, 20 );
	} );

	function bind( box, token ) {
		var note = box.querySelector( '.whd-live-sms__note' );
		var phone = box.querySelector( 'input[name="phone"]' );
		var consent = box.querySelector( 'input[name="consent"]' );
		var go = box.querySelector( '.whd-live-sms__go' );
		box.querySelector( '[data-no]' ).addEventListener( 'click', function () {
			box.remove();
		} );
		box.addEventListener( 'submit', function ( ev ) {
			ev.preventDefault();
			note.classList.remove( 'is-error' );
			if ( phone.value.replace( /\D+/g, '' ).length < 10 ) {
				note.textContent = T.phone;
				note.classList.add( 'is-error' );
				phone.focus();
				return;
			}
			if ( ! consent.checked ) {
				note.textContent = T.consent;
				note.classList.add( 'is-error' );
				consent.focus();
				return;
			}
			go.disabled = true;
			note.textContent = T.wait;
			var body = new FormData();
			body.append( 'action', 'whd_live_sms' );
			body.append( 'token', token );
			body.append( 'phone', phone.value );
			body.append( 'consent', '1' );
			window.fetch( D.ajax, { method: 'POST', body: body, credentials: 'same-origin' } ).then( function ( r ) {
				return r.json();
			} ).then( function ( res ) {
				if ( res && res.success ) {
					box.classList.add( 'is-done' );
					box.innerHTML = '<p class="whd-live-sms__done" role="status"></p>';
					box.firstChild.textContent = res.data.message;
					document.dispatchEvent( new CustomEvent( 'whd:sms-subscribed', { detail: { source: 'fb_live' } } ) );
					return;
				}
				note.textContent = ( res && res.data && res.data.message ) || T.error;
				note.classList.add( 'is-error' );
				go.disabled = false;
			} ).catch( function () {
				note.textContent = T.error;
				note.classList.add( 'is-error' );
				go.disabled = false;
			} );
		} );
	}
}() );
