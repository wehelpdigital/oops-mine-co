/**
 * The stylist wizard.
 *
 * Four stages: what this is, six questions, where to send it, and the edit. The details come after
 * the questions rather than before them — asking for an address before anything has been given is
 * the wrong way round, and someone who has answered six questions is far more willing to finish.
 *
 * Steps cross-fade and slide, options are dealt in with a small stagger, and a single-choice
 * question advances on its own. All of it is off under prefers-reduced-motion.
 *
 * No dependencies. Everything it needs is passed in from PHP.
 */
( function () {
	'use strict';

	var D = window.WHD_STYLIST || {};
	var T = D.i18n || {};

	var root, bar, panel;
	var questions = D.questions || [];
	var answers = {};
	var at = 0;
	var openedAt = 0;
	var captchaId = null;
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
		if ( ! root ) { return; }
		lastFocus = document.activeElement;
		root.hidden = false;
		root.setAttribute( 'aria-hidden', 'false' );
		document.documentElement.classList.add( 'whd-sty-open' );
		openedAt = Date.now();
		requestAnimationFrame( function () { root.classList.add( 'is-open' ); } );
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

	function showStep( name ) {
		var steps = root.querySelectorAll( '.whd-sty__step' );
		Array.prototype.forEach.call( steps, function ( s ) {
			s.classList.toggle( 'is-on', s.getAttribute( 'data-step' ) === name );
		} );
		if ( panel ) { panel.scrollTop = 0; }
	}

	/* Three parts: the questions, the details, the edit. */
	function progress( done ) {
		if ( ! bar ) { return; }
		var total = questions.length + 2;
		bar.style.width = Math.round( ( done / total ) * 100 ) + '%';
	}

	/* ─────────────────────────── the questions ─────────────────────────── */

	function renderQuestion() {
		var q = questions[ at ];
		var host = root.querySelector( '[data-step="q"]' );
		if ( ! q ) { return renderDetails(); }

		progress( at );
		host.innerHTML = '';

		var head = el( 'div', 'whd-sty__qhead' );
		head.appendChild( el( 'p', 'whd-sty__eyebrow', esc(
			( T.step || 'Step %1$d of %2$d' ).replace( '%1$d', at + 1 ).replace( '%2$d', questions.length )
		) ) );
		head.appendChild( el( 'h2', 'whd-sty__title', esc( q.question ) ) );
		if ( q.help ) { head.appendChild( el( 'p', 'whd-sty__lede', esc( q.help ) ) ); }
		host.appendChild( head );

		var list = el( 'div', 'whd-sty__opts' + ( q.type === 'multi' ? ' whd-sty__opts--multi' : '' ) );
		Object.keys( q.options || {} ).forEach( function ( key, i ) {
			var b = el( 'button', 'whd-sty__opt' );
			b.type = 'button';
			b.setAttribute( 'data-value', key );
			b.innerHTML = '<span class="whd-sty__tick" aria-hidden="true"></span><span>' + esc( q.options[ key ] ) + '</span>';
			if ( ! reduce ) { b.style.animationDelay = ( i * 45 ) + 'ms'; }
			b.addEventListener( 'click', function () { choose( q, key, b ); } );
			list.appendChild( b );
		} );
		host.appendChild( list );

		var foot = el( 'div', 'whd-sty__foot' );
		if ( at > 0 ) {
			var back = el( 'button', 'whd-sty__back', '&larr; ' + esc( T.back || 'Back' ) );
			back.type = 'button';
			back.addEventListener( 'click', function () { at--; renderQuestion(); } );
			foot.appendChild( back );
		}
		if ( q.type === 'multi' ) {
			var next = el( 'button', 'whd-sty__next', esc( at === questions.length - 1 ? ( T.see || 'Almost there' ) : ( T.next || 'Next' ) ) );
			next.type = 'button';
			next.addEventListener( 'click', function () {
				if ( ! ( answers[ q.key ] || [] ).length ) { return flash( next, T.pickOne ); }
				advance();
			} );
			foot.appendChild( next );
		}
		host.appendChild( foot );

		( [] ).concat( answers[ q.key ] || [] ).forEach( function ( v ) {
			var b = list.querySelector( '[data-value="' + v + '"]' );
			if ( b ) { b.classList.add( 'is-on' ); }
		} );

		showStep( 'q' );
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
		Array.prototype.forEach.call( button.parentNode.querySelectorAll( '.whd-sty__opt' ), function ( s ) {
			s.classList.remove( 'is-on' );
		} );
		button.classList.add( 'is-on' );
		setTimeout( advance, reduce ? 0 : 260 );
	}

	function advance() {
		var host = root.querySelector( '[data-step="q"]' );
		if ( at >= questions.length - 1 ) { return renderDetails(); }
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

	/* ─────────────────────────── where to send it ─────────────────────────── */

	function renderDetails() {
		var host = root.querySelector( '[data-step="details"]' );
		progress( questions.length );

		host.innerHTML =
			'<p class="whd-sty__eyebrow">' + esc( ( T.step || '' ).replace( '%1$d', questions.length + 1 ).replace( '%2$d', questions.length + 1 ) ) + '</p>' +
			'<h2 class="whd-sty__title">Where shall we <em>send it</em>?</h2>' +
			'<p class="whd-sty__lede">Your edit appears here as soon as you press the button — and we email you a copy so it is still there tomorrow, with every piece linked.</p>' +
			'<form class="whd-sty__form" novalidate>' +
				'<div class="whd-sty__row">' +
					'<span class="whd-sty__field"><label class="whd-sty__label" for="whd-sty-first">First name</label>' +
					'<input type="text" id="whd-sty-first" name="first_name" autocomplete="given-name" required>' +
					'<span class="whd-sty__err" data-for="first_name"></span></span>' +
					'<span class="whd-sty__field"><label class="whd-sty__label" for="whd-sty-last">Last name</label>' +
					'<input type="text" id="whd-sty-last" name="last_name" autocomplete="family-name" required>' +
					'<span class="whd-sty__err" data-for="last_name"></span></span>' +
				'</div>' +
				'<span class="whd-sty__field"><label class="whd-sty__label" for="whd-sty-email">Email</label>' +
				'<input type="email" id="whd-sty-email" name="email" autocomplete="email" required>' +
				'<span class="whd-sty__err" data-for="email"></span></span>' +
				'<p class="whd-sty__hp" aria-hidden="true"><label>Leave this empty<input type="text" name="website" tabindex="-1" autocomplete="off"></label></p>' +
				captchaMarkup() +
				'<div class="whd-sty__foot">' +
					'<button type="button" class="whd-sty__back" data-sty-back>&larr; ' + esc( T.back || 'Back' ) + '</button>' +
					'<button type="submit" class="whd-sty__next">' + esc( T.send || 'Show me my edit' ) + '</button>' +
				'</div>' +
				'<p class="whd-sty__small">We add you to the list so we can send the edit. One email when something lands, and you can leave any time.</p>' +
				'<p class="whd-sty__note" role="status"></p>' +
			'</form>';

		host.querySelector( '[data-sty-back]' ).addEventListener( 'click', function () {
			at = questions.length - 1;
			renderQuestion();
		} );

		// Validate as they leave a field, so nothing is a surprise at the end.
		Array.prototype.forEach.call( host.querySelectorAll( 'input[name]' ), function ( input ) {
			input.addEventListener( 'blur', function () { validate( host, input.name ); } );
			input.addEventListener( 'input', function () { clearError( host, input.name ); } );
		} );

		host.querySelector( 'form' ).addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			submit( host );
		} );

		renderCaptcha();
		showStep( 'details' );
		var first = host.querySelector( '#whd-sty-first' );
		if ( first ) { setTimeout( function () { first.focus(); }, reduce ? 0 : 300 ); }
	}

	/**
	 * v2 draws a tickbox. v3 draws nothing at all — its floating badge is hidden by the stylesheet,
	 * so the line Google asks for in exchange is printed under the form instead.
	 */
	function captchaMarkup() {
		if ( ! D.recaptcha ) {
			return '';
		}
		if ( D.captchaV3 ) {
			return '<p class="whd-sty__legal">' + esc( T.captchaNote || '' ) + '</p>' +
				'<span class="whd-sty__err" data-for="captcha"></span>';
		}
		return '<div class="whd-sty__captcha" id="whd-sty-captcha"></div>' +
			'<span class="whd-sty__err" data-for="captcha"></span>';
	}

	/**
	 * A token, however this site's reCAPTCHA happens to mint one.
	 *
	 * Rejects with 'tick' when the visitor simply has not ticked the box, and with 'off' when the
	 * API never arrived — two different messages, because only one of them is theirs to fix.
	 */
	function captchaToken() {
		if ( ! D.recaptcha ) {
			return Promise.resolve( '' );
		}
		if ( D.captchaV3 ) {
			return new Promise( function ( resolve, reject ) {
				if ( ! window.grecaptcha || ! window.grecaptcha.ready ) {
					return reject( 'off' );
				}
				window.grecaptcha.ready( function () {
					try {
						window.grecaptcha.execute( D.recaptcha, { action: D.captchaAction || 'whd_stylist' } )
							.then( resolve, function () { reject( 'off' ); } );
					} catch ( e ) {
						reject( 'off' );
					}
				} );
			} );
		}
		if ( ! window.grecaptcha ) {
			return Promise.reject( 'off' );
		}
		var answer = captchaId !== null ? window.grecaptcha.getResponse( captchaId ) : window.grecaptcha.getResponse();

		return answer ? Promise.resolve( answer ) : Promise.reject( 'tick' );
	}

	function renderCaptcha() {
		if ( ! D.recaptcha || D.captchaV3 ) { return; }
		var box = root.querySelector( '#whd-sty-captcha' );
		if ( ! box ) { return; }
		// The widget is built when the visitor reaches this step, so the API may still be loading.
		var tryRender = function () {
			if ( ! window.grecaptcha || ! window.grecaptcha.render ) {
				return setTimeout( tryRender, 250 );
			}
			box.innerHTML = '';
			captchaId = window.grecaptcha.render( box, { sitekey: D.recaptcha } );
		};
		tryRender();
	}

	function fieldValue( host, name ) {
		var input = host.querySelector( '[name="' + name + '"]' );
		return input ? input.value.trim() : '';
	}

	function setError( host, name, message ) {
		var slot = host.querySelector( '.whd-sty__err[data-for="' + name + '"]' );
		var input = host.querySelector( '[name="' + name + '"]' );
		if ( slot ) { slot.textContent = message || ''; }
		if ( input ) { input.classList.toggle( 'is-bad', !! message ); }
	}

	function clearError( host, name ) { setError( host, name, '' ); }

	function validate( host, name ) {
		var v = fieldValue( host, name );
		if ( name === 'first_name' && v.length < 2 ) {
			return setError( host, name, 'Your first name, as you would like it written.' ), false;
		}
		if ( name === 'last_name' && v.length < 2 ) {
			return setError( host, name, 'And your last name.' ), false;
		}
		if ( name === 'email' && ! /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test( v ) ) {
			return setError( host, name, 'That does not look like an email address.' ), false;
		}
		clearError( host, name );
		return true;
	}

	function submit( host ) {
		var ok = [ 'first_name', 'last_name', 'email' ].map( function ( n ) { return validate( host, n ); } );
		if ( ok.indexOf( false ) !== -1 ) {
			var bad = host.querySelector( 'input.is-bad' );
			if ( bad ) { bad.focus(); }
			return;
		}

		captchaToken().then(
			function ( captcha ) {
				clearError( host, 'captcha' );
				send( host, captcha );
			},
			function ( why ) {
				setError( host, 'captcha', 'off' === why
					? ( T.captchaOff || 'The robot check did not load. Reload the page and try again.' )
					: ( T.tick || 'Tick the box to show you are human.' ) );
			}
		);
	}

	function send( host, captcha ) {
		var btn = host.querySelector( '.whd-sty__next' );
		btn.disabled = true;
		btn.textContent = T.working || 'Reading the rail…';
		showWorking();

		post( 'whd_stylist_finish', {
			first_name: fieldValue( host, 'first_name' ),
			last_name: fieldValue( host, 'last_name' ),
			email: fieldValue( host, 'email' ),
			website: fieldValue( host, 'website' ),
			elapsed: Math.round( ( Date.now() - openedAt ) / 1000 ),
			captcha: captcha,
			answers: JSON.stringify( answers )
		} ).then( function ( res ) {
			if ( ! res.success ) {
				showStep( 'details' );
				btn.disabled = false;
				btn.textContent = T.send || 'Show me my edit';
				if ( res.data && res.data.field ) {
					setError( host, res.data.field, res.data.message );
				} else {
					host.querySelector( '.whd-sty__note' ).textContent = ( res.data && res.data.message ) || T.error;
					host.querySelector( '.whd-sty__note' ).className = 'whd-sty__note is-bad';
				}
				if ( window.grecaptcha && captchaId !== null ) { window.grecaptcha.reset( captchaId ); }
				return;
			}
			progress( questions.length + 2 );
			paint( res.data );
		} ).catch( function () {
			showStep( 'details' );
			btn.disabled = false;
			btn.textContent = T.send || 'Show me my edit';
			host.querySelector( '.whd-sty__note' ).textContent = T.error;
			host.querySelector( '.whd-sty__note' ).className = 'whd-sty__note is-bad';
		} );
	}

	/* ─────────────────────────── the edit ─────────────────────────── */

	function showWorking() {
		var host = root.querySelector( '[data-step="result"]' );
		host.innerHTML = '<div class="whd-sty__loading"><span class="whd-sty__spin" aria-hidden="true"></span>' +
			'<p>' + esc( T.working || 'Reading the rail…' ) + '</p></div>';
		showStep( 'result' );
	}

	function paint( data ) {
		var host = root.querySelector( '[data-step="result"]' );
		host.innerHTML = '';
		host.appendChild( el( 'p', 'whd-sty__eyebrow', esc( T.yourEdit || 'Your edit' ) ) );
		if ( data.greeting ) { host.appendChild( el( 'p', 'whd-sty__greet', esc( data.greeting ) ) ); }
		host.appendChild( el( 'h2', 'whd-sty__title', T.chosen || 'Chosen <em>for you</em>' ) );
		if ( data.intro ) { host.appendChild( el( 'p', 'whd-sty__lede', esc( data.intro ) ) ); }
		if ( data.mailNote ) {
			host.appendChild( el( 'p', 'whd-sty__mailed' + ( data.mailed ? '' : ' is-bad' ), esc( data.mailNote ) ) );
		}

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
		again.addEventListener( 'click', reset );
		foot.appendChild( again );
		if ( data.shopUrl ) {
			var shop = el( 'a', 'whd-sty__next', esc( T.browse || 'Browse everything' ) );
			shop.href = data.shopUrl;
			foot.appendChild( shop );
		}
		host.appendChild( foot );
	}

	function reset() {
		at = 0;
		answers = {};
		captchaId = null;
		progress( 0 );
		showStep( 'intro' );
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
		bar = root.querySelector( '.whd-sty__bar-fill' );
		panel = root.querySelector( '.whd-sty__panel' );

		document.addEventListener( 'click', function ( e ) {
			if ( e.target.closest( '[data-whd-stylist]' ) ) {
				e.preventDefault();
				reset();
				open();
				return;
			}
			if ( e.target.closest( '[data-sty-begin]' ) ) {
				e.preventDefault();
				at = 0;
				renderQuestion();
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
