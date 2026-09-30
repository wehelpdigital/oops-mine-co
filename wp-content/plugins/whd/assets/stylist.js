/**
 * The stylist wizard.
 *
 * One overlay, three stages: who you are, the questions, the answer. Steps cross-fade and slide
 * rather than jumping, the progress bar fills as you go, and a single-choice question advances on
 * its own — waiting for someone to press Next after they have already answered is the slowest part
 * of most wizards.
 *
 * No dependencies. Everything it needs is passed in from PHP.
 */
( function () {
	'use strict';

	var D = window.WHD_STYLIST || {};
	var T = D.i18n || {};

	var root, stage, bar, panel;
	var questions = [];
	var answers = {};
	var token = '';
	var at = 0;
	var openedAt = 0;
	var lastFocus = null;

	var reduce = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	function el( tag, cls, html ) {
		var n = document.createElement( tag );
		if ( cls ) { n.className = cls; }
		if ( html !== undefined ) { n.innerHTML = html; }
		return n;
	}

	function esc( s ) {
		var d = document.createElement( 'div' );
		d.textContent = s == null ? '' : String( s );
		return d.innerHTML;
	}

	/* ─────────────────────────── opening and closing ─────────────────────────── */

	function open() {
		root = document.getElementById( 'whd-stylist' );
		if ( ! root ) { return; }
		lastFocus = document.activeElement;
		root.hidden = false;
		root.setAttribute( 'aria-hidden', 'false' );
		document.documentElement.classList.add( 'whd-sty-open' );
		openedAt = Date.now();
		// Let the browser paint the hidden state first, or the transition never runs.
		requestAnimationFrame( function () { root.classList.add( 'is-open' ); } );
		var first = root.querySelector( '#whd-sty-name' );
		if ( first ) { setTimeout( function () { first.focus(); }, reduce ? 0 : 320 ); }
	}

	function close() {
		if ( ! root ) { return; }
		root.classList.remove( 'is-open' );
		document.documentElement.classList.remove( 'whd-sty-open' );
		var done = function () {
			root.hidden = true;
			root.setAttribute( 'aria-hidden', 'true' );
			if ( lastFocus && lastFocus.focus ) { lastFocus.focus(); }
		};
		if ( reduce ) { done(); } else { setTimeout( done, 260 ); }
	}

	/* ─────────────────────────── stepping ─────────────────────────── */

	function showStep( name ) {
		var steps = root.querySelectorAll( '.whd-sty__step' );
		Array.prototype.forEach.call( steps, function ( s ) {
			s.classList.toggle( 'is-on', s.getAttribute( 'data-step' ) === name );
		} );
		if ( panel ) { panel.scrollTop = 0; }
	}

	function progress( done, total ) {
		if ( ! bar ) { return; }
		bar.style.width = total ? Math.round( ( done / total ) * 100 ) + '%' : '0%';
	}

	/* ─────────────────────────── step one ─────────────────────────── */

	function bindIntro() {
		var form = root.querySelector( '.whd-sty__form' );
		var note = root.querySelector( '.whd-sty__note' );
		if ( ! form ) { return; }

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var btn = form.querySelector( '.whd-sty__go' );
			var name = form.querySelector( '[name="name"]' ).value.trim();
			var email = form.querySelector( '[name="email"]' ).value.trim();
			if ( ! name || ! email ) {
				note.textContent = T.error;
				note.className = 'whd-sty__note is-bad';
				return;
			}

			var captcha = '';
			if ( D.recaptcha && window.grecaptcha ) {
				captcha = window.grecaptcha.getResponse();
				if ( ! captcha ) {
					note.textContent = 'Tick the box to show you are human.';
					note.className = 'whd-sty__note is-bad';
					return;
				}
			}

			btn.disabled = true;
			btn.textContent = T.working;
			note.textContent = '';
			note.className = 'whd-sty__note';

			post( 'whd_stylist_start', {
				name: name,
				email: email,
				website: form.querySelector( '[name="website"]' ).value,
				elapsed: Math.round( ( Date.now() - openedAt ) / 1000 ),
				captcha: captcha
			} ).then( function ( res ) {
				if ( ! res.success ) {
					note.textContent = res.data && res.data.message ? res.data.message : T.error;
					note.className = 'whd-sty__note is-bad';
					if ( window.grecaptcha ) { window.grecaptcha.reset(); }
					return;
				}
				token = res.data.token;
				questions = res.data.questions || [];
				answers = {};
				at = 0;
				renderQuestion( res.data.greeting );
				showStep( 'q' );
			} ).catch( function () {
				note.textContent = T.error;
				note.className = 'whd-sty__note is-bad';
			} ).then( function () {
				btn.disabled = false;
				btn.textContent = 'Start';
			} );
		} );
	}

	/* ─────────────────────────── the questions ─────────────────────────── */

	function renderQuestion( greeting ) {
		var q = questions[ at ];
		var host = root.querySelector( '[data-step="q"]' );
		if ( ! q ) { return finish(); }

		progress( at, questions.length + 1 );
		host.innerHTML = '';

		var head = el( 'div', 'whd-sty__qhead' );
		head.appendChild( el( 'p', 'whd-sty__eyebrow', esc(
			( T.step || 'Step %1$d of %2$d' ).replace( '%1$d', at + 1 ).replace( '%2$d', questions.length )
		) ) );
		if ( greeting && at === 0 ) {
			head.appendChild( el( 'p', 'whd-sty__greet', esc( greeting ) ) );
		}
		head.appendChild( el( 'h2', 'whd-sty__title', esc( q.question ) ) );
		if ( q.help ) { head.appendChild( el( 'p', 'whd-sty__lede', esc( q.help ) ) ); }
		host.appendChild( head );

		var list = el( 'div', 'whd-sty__opts' + ( q.type === 'multi' ? ' whd-sty__opts--multi' : '' ) );
		var keys = Object.keys( q.options || {} );
		keys.forEach( function ( key, i ) {
			var b = el( 'button', 'whd-sty__opt' );
			b.type = 'button';
			b.setAttribute( 'data-value', key );
			b.innerHTML = '<span class="whd-sty__tick" aria-hidden="true"></span><span>' + esc( q.options[ key ] ) + '</span>';
			// A small stagger makes the set feel dealt rather than dumped.
			if ( ! reduce ) { b.style.animationDelay = ( i * 45 ) + 'ms'; }
			b.addEventListener( 'click', function () { choose( q, key, b ); } );
			list.appendChild( b );
		} );
		host.appendChild( list );

		var foot = el( 'div', 'whd-sty__foot' );
		if ( at > 0 ) {
			var back = el( 'button', 'whd-sty__back', '&larr; ' + esc( 'Back' ) );
			back.type = 'button';
			back.addEventListener( 'click', function () { at--; renderQuestion(); } );
			foot.appendChild( back );
		}
		if ( q.type === 'multi' ) {
			var next = el( 'button', 'whd-sty__next', esc( at === questions.length - 1 ? ( T.see || 'See what suits me' ) : ( T.next || 'Next' ) ) );
			next.type = 'button';
			next.addEventListener( 'click', function () {
				if ( ! ( answers[ q.key ] || [] ).length ) {
					flash( next, T.pickOne );
					return;
				}
				advance();
			} );
			foot.appendChild( next );
		}
		host.appendChild( foot );

		// Reflect anything already chosen if they came back to this step.
		( [] ).concat( answers[ q.key ] || [] ).forEach( function ( v ) {
			var b = list.querySelector( '[data-value="' + v + '"]' );
			if ( b ) { b.classList.add( 'is-on' ); }
		} );
	}

	function choose( q, key, button ) {
		if ( q.type === 'multi' ) {
			var have = answers[ q.key ] || [];
			var i = have.indexOf( key );
			if ( i >= 0 ) { have.splice( i, 1 ); } else { have.push( key ); }
			answers[ q.key ] = have;
			button.classList.toggle( 'is-on' );
			return;
		}
		answers[ q.key ] = key;
		var sibs = button.parentNode.querySelectorAll( '.whd-sty__opt' );
		Array.prototype.forEach.call( sibs, function ( s ) { s.classList.remove( 'is-on' ); } );
		button.classList.add( 'is-on' );
		// One answer, one question: move on rather than asking them to confirm.
		setTimeout( advance, reduce ? 0 : 260 );
	}

	function advance() {
		var host = root.querySelector( '[data-step="q"]' );
		if ( at >= questions.length - 1 ) { return finish(); }
		host.classList.add( 'is-leaving' );
		setTimeout( function () {
			host.classList.remove( 'is-leaving' );
			at++;
			renderQuestion();
		}, reduce ? 0 : 180 );
	}

	function flash( node, message ) {
		node.classList.add( 'is-shaking' );
		setTimeout( function () { node.classList.remove( 'is-shaking' ); }, 500 );
		var note = node.parentNode.querySelector( '.whd-sty__hint' );
		if ( ! note ) {
			note = el( 'span', 'whd-sty__hint' );
			node.parentNode.appendChild( note );
		}
		note.textContent = message;
	}

	/* ─────────────────────────── the answer ─────────────────────────── */

	function finish() {
		var host = root.querySelector( '[data-step="result"]' );
		progress( questions.length, questions.length + 1 );
		host.innerHTML = '<div class="whd-sty__loading"><span class="whd-sty__spin" aria-hidden="true"></span>' +
			'<p>' + esc( T.working ) + '</p></div>';
		showStep( 'result' );

		post( 'whd_stylist_finish', {
			token: token,
			answers: JSON.stringify( answers )
		} ).then( function ( res ) {
			if ( ! res.success ) {
				host.innerHTML = '<p class="whd-sty__note is-bad">' + esc( ( res.data && res.data.message ) || T.error ) + '</p>';
				return;
			}
			progress( 1, 1 );
			paint( host, res.data );
		} ).catch( function () {
			host.innerHTML = '<p class="whd-sty__note is-bad">' + esc( T.error ) + '</p>';
		} );
	}

	function paint( host, data ) {
		host.innerHTML = '';
		host.appendChild( el( 'p', 'whd-sty__eyebrow', 'Your edit' ) );
		host.appendChild( el( 'h2', 'whd-sty__title', 'Chosen <em>for you</em>' ) );
		if ( data.intro ) { host.appendChild( el( 'p', 'whd-sty__lede', esc( data.intro ) ) ); }

		var grid = el( 'div', 'whd-sty__picks' );
		( data.picks || [] ).forEach( function ( p, i ) {
			var card = el( 'a', 'whd-sty__pick' );
			card.href = p.url;
			if ( ! reduce ) { card.style.animationDelay = ( i * 70 ) + 'ms'; }
			card.innerHTML =
				'<span class="whd-sty__pick-media"><img src="' + esc( p.image ) + '" alt="" loading="lazy" decoding="async"></span>' +
				'<span class="whd-sty__pick-body">' +
					'<span class="whd-sty__pick-name">' + esc( p.name ) + '</span>' +
					'<span class="whd-sty__pick-price">' + esc( p.price ) + '</span>' +
					( p.reason ? '<span class="whd-sty__pick-why">' + esc( p.reason ) + '</span>' : '' ) +
				'</span>';
			grid.appendChild( card );
		} );
		host.appendChild( grid );

		var foot = el( 'div', 'whd-sty__foot whd-sty__foot--end' );
		var again = el( 'button', 'whd-sty__back', esc( T.again || 'Start again' ) );
		again.type = 'button';
		again.addEventListener( 'click', function () {
			at = 0;
			answers = {};
			progress( 0, 1 );
			showStep( 'intro' );
		} );
		foot.appendChild( again );
		if ( data.shopUrl ) {
			var shop = el( 'a', 'whd-sty__next', 'Browse everything' );
			shop.href = data.shopUrl;
			foot.appendChild( shop );
		}
		host.appendChild( foot );
	}

	/* ─────────────────────────── plumbing ─────────────────────────── */

	function post( action, data ) {
		var body = new URLSearchParams();
		body.append( 'action', action );
		body.append( 'nonce', D.nonce );
		Object.keys( data ).forEach( function ( k ) { body.append( k, data[ k ] ); } );

		return fetch( D.ajax, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		} ).then( function ( r ) { return r.json(); } );
	}

	function ready( fn ) {
		if ( document.readyState === 'loading' ) {
			document.addEventListener( 'DOMContentLoaded', fn );
		} else {
			fn();
		}
	}

	ready( function () {
		root = document.getElementById( 'whd-stylist' );
		if ( ! root ) { return; }
		stage = root.querySelector( '.whd-sty__stage' );
		bar = root.querySelector( '.whd-sty__bar-fill' );
		panel = root.querySelector( '.whd-sty__panel' );
		void stage;

		bindIntro();

		document.addEventListener( 'click', function ( e ) {
			if ( e.target.closest( '[data-whd-stylist]' ) ) {
				e.preventDefault();
				open();
				return;
			}
			if ( e.target.closest( '[data-sty-close]' ) ) {
				e.preventDefault();
				close();
			}
		} );

		document.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' && root && ! root.hidden ) { close(); }
		} );
	} );
}() );
