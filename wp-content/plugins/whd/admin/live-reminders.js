/**
 * WHD → Live reminders: the screen's small helpers. Which fields show for where the live is, when each
 * reminder goes (in shop time, the way the server works it out), a text message's length and how it
 * will read, merge-tag chips, and the "send me a test via Klaviyo" buttons. Saving is a plain form post.
 */
( function () {
	'use strict';

	var D = window.WHD_LR || {};
	var T = D.i18n || {};
	var form = document.getElementById( 'whd-lr-form' );
	if ( ! form ) {
		return;
	}
	var WEEK = 7 * 86400000;

	function fmt( s ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var i = 0;
		return String( s ).replace( /%(\d\$)?[sd]/g, function ( m, pos ) {
			return pos ? args[ parseInt( pos, 10 ) - 1 ] : args[ i++ ];
		} );
	}
	function q( sel, root ) {
		return ( root || document ).querySelector( sel );
	}
	function qa( sel, root ) {
		return Array.prototype.slice.call( ( root || document ).querySelectorAll( sel ) );
	}

	/* ── where the live is ── */
	function source() {
		var r = q( 'input[name="lr[source]"]:checked' );
		return r ? r.value : 'manual';
	}
	function paintSource() {
		qa( '[data-source]' ).forEach( function ( g ) {
			g.hidden = g.getAttribute( 'data-source' ) !== source();
		} );
		paintAll();
	}
	qa( 'input[name="lr[source]"]' ).forEach( function ( r ) {
		r.addEventListener( 'change', paintSource );
	} );

	/*
	 * Times are kept as "shop wall clock" milliseconds: a Date built with Date.UTC from the shop's own
	 * date and time, so arithmetic and formatting never pass through this browser's timezone. The real
	 * moment is that minus the shop's offset.
	 */
	var off = ( parseInt( D.tzOffset, 10 ) || 0 ) * 1000;
	function liveWall() {
		if ( 'stream' === source() ) {
			var sel = q( '#whd-lr-stream' );
			var st = sel && D.streams ? D.streams[ sel.value ] : null;
			return st ? st.ts * 1000 + off : null;
		}
		var v = ( q( '#whd-lr-when' ) || {} ).value || '';
		var m = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/.exec( v );
		if ( ! m ) {
			return null;
		}
		var wall = Date.UTC( +m[ 1 ], +m[ 2 ] - 1, +m[ 3 ], +m[ 4 ], +m[ 5 ] );
		if ( 'weekly' === ( q( '#whd-lr-repeat' ) || {} ).value ) {
			while ( wall - off + 7200000 < Date.now() ) {
				wall += WEEK;
			}
		}
		return wall;
	}
	var DAYS = [ 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat' ];
	var MONTHS = [ 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec' ];
	function clock( d ) {
		var h = d.getUTCHours();
		var m = d.getUTCMinutes();
		return ( h % 12 || 12 ) + ':' + ( m < 10 ? '0' : '' ) + m + ' ' + ( h < 12 ? 'am' : 'pm' );
	}
	function when( wall ) {
		var d = new Date( wall );
		return DAYS[ d.getUTCDay() ] + ' ' + d.getUTCDate() + ' ' + MONTHS[ d.getUTCMonth() ] + ', ' + clock( d );
	}

	/* ── a text message ── */
	var GSM = /^[@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&'()*+,\-.\/0-9:;<=>?¡A-ZÄÖÑÜ§¿a-zäöñüà^{}\\\[~\]|€]*$/;
	function smsText( raw, wall ) {
		var d = wall ? new Date( wall ) : null;
		var sample = D.sample || {};
		var tags = {
			live_time: d ? clock( d ) : '8:00 pm',
			live_date: d ? DAYS[ d.getUTCDay() ] + ', ' + MONTHS[ d.getUTCMonth() ] + ' ' + d.getUTCDate() : 'Saturday, Nov 22',
			live_title: ( q( '#whd-lr-title' ) || {} ).value || sample.live_title || '',
			live_url: ( q( '#whd-lr-url' ) || {} ).value || sample.live_url || '',
			live_starts_in: '',
			first_name: 'Anna',
			site_name: sample.site_name || ''
		};
		return function ( startsIn ) {
			tags.live_starts_in = startsIn;
			return String( raw ).replace( /\{([a-z_]+)\}/g, function ( m, k ) {
				return Object.prototype.hasOwnProperty.call( tags, k ) ? tags[ k ] : m;
			} );
		};
	}
	function startsIn( before ) {
		before = parseInt( before, 10 ) || 0;
		if ( ! before ) {
			return 'now';
		}
		if ( before < 60 ) {
			return 'in ' + before + ' minutes';
		}
		if ( 60 === before ) {
			return 'in an hour';
		}
		if ( before < 1440 ) {
			return 'in ' + Math.round( before / 60 ) + ' hours';
		}
		return 1440 === before ? 'tomorrow' : 'in ' + Math.round( before / 1440 ) + ' days';
	}

	/* ── one reminder ── */
	function paint( card ) {
		var on = q( '[data-on]', card ).checked;
		var email = q( '[data-email]', card ).checked;
		var sms = q( '[data-sms]', card ).checked;
		var before = parseInt( q( '[data-before]', card ).value, 10 ) || 0;
		card.classList.toggle( 'is-off', ! on );
		q( '[data-email-box]', card ).hidden = ! email;
		q( '[data-sms-box]', card ).hidden = ! sms;

		var whenEl = q( '[data-when]', card );
		var live = liveWall();
		var sendWall = live === null ? null : live - before * 60000;
		whenEl.classList.remove( 'is-bad' );
		if ( sendWall === null ) {
			whenEl.textContent = T.noDate;
		} else if ( sendWall - off < Date.now() ) {
			whenEl.textContent = T.past;
			whenEl.classList.add( 'is-bad' );
		} else if ( sendWall - off < Date.now() + 600000 ) {
			whenEl.textContent = T.tooSoon;
			whenEl.classList.add( 'is-bad' );
		} else {
			whenEl.textContent = fmt( T.sends, when( sendWall ) );
		}

		// Quiet hours: several US states forbid marketing texts before 8 am or after 9 pm.
		var quiet = q( '[data-quiet]', card );
		var h = sendWall === null ? 12 : new Date( sendWall ).getUTCHours();
		var late = sms && on && sendWall !== null && ( h < 8 || h >= 21 );
		quiet.hidden = ! late;
		quiet.textContent = late ? T.quiet : '';

		var text = q( '[data-text]', card ).value;
		var body = smsText( text, live )( startsIn( before ) );
		var prefix = q( '#whd-lr-prefix' ) && q( '#whd-lr-prefix' ).checked ? ( D.company || '' ) + ': ' : '';
		var stop = q( '#whd-lr-optout' ) && q( '#whd-lr-optout' ).checked ? '\nReply STOP to opt out' : '';
		var full = prefix + body + stop;
		q( '[data-bubble]', card ).textContent = full;
		// Klaviyo shortens links, so a long address is counted at the length of a short one.
		var counted = full.replace( /https?:\/\/\S+/g, 'https://klvy.io/abcdef' );
		var plain = GSM.test( counted );
		var per = plain ? ( counted.length > 160 ? 153 : 160 ) : ( counted.length > 70 ? 67 : 70 );
		var parts = Math.max( 1, Math.ceil( counted.length / per ) );
		var count = q( '[data-count]', card );
		count.textContent = fmt( T.chars, counted.length, parts ) + ( plain ? '' : ' — ' + T.unicode );
	}
	function paintAll() {
		qa( '[data-reminder]' ).forEach( paint );
	}
	qa( '[data-reminder]' ).forEach( function ( card ) {
		qa( 'input, select, textarea', card ).forEach( function ( el ) {
			el.addEventListener( 'input', function () {
				paint( card );
			} );
			el.addEventListener( 'change', function () {
				paint( card );
			} );
		} );
		qa( '[data-tag]', card ).forEach( function ( b ) {
			b.addEventListener( 'click', function () {
				var ta = q( '[data-text]', card );
				var tag = b.getAttribute( 'data-tag' );
				var at = typeof ta.selectionStart === 'number' ? ta.selectionStart : ta.value.length;
				var end = typeof ta.selectionEnd === 'number' ? ta.selectionEnd : at;
				ta.value = ta.value.slice( 0, at ) + tag + ta.value.slice( end );
				ta.focus();
				ta.selectionStart = ta.selectionEnd = at + tag.length;
				paint( card );
			} );
		} );
	} );
	[ '#whd-lr-when', '#whd-lr-repeat', '#whd-lr-stream', '#whd-lr-title', '#whd-lr-url', '#whd-lr-prefix', '#whd-lr-optout' ].forEach( function ( sel ) {
		var el = q( sel );
		if ( el ) {
			el.addEventListener( 'input', paintAll );
			el.addEventListener( 'change', paintAll );
		}
	} );

	/* ── a test through Klaviyo ── */
	qa( '[data-test]' ).forEach( function ( b ) {
		b.addEventListener( 'click', function () {
			var card = b.closest( '[data-reminder]' );
			var note = q( '[data-test-note]', card );
			b.disabled = true;
			note.className = 'whd-lr__test';
			note.textContent = T.testing;
			window.fetch( D.rest.root + 'test', {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': D.rest.nonce },
				body: JSON.stringify( { reminder: b.getAttribute( 'data-test' ) } )
			} ).then( function ( r ) {
				return r.json();
			} ).then( function ( j ) {
				note.textContent = ( j && j.message ) || T.error;
				note.classList.add( j && j.ok ? 'is-ok' : 'is-bad' );
			} ).catch( function () {
				note.textContent = T.error;
				note.classList.add( 'is-bad' );
			} ).then( function () {
				b.disabled = false;
			} );
		} );
	} );

	/* ── saving takes a few seconds: Klaviyo is asked once per message ── */
	form.addEventListener( 'submit', function () {
		var btn = q( '#whd-lr-save' );
		if ( btn ) {
			btn.classList.add( 'is-busy' );
			btn.textContent = T.saving;
		}
	} );

	paintSource();
}() );
