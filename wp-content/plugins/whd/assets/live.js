/**
 * The live page.
 *
 * Everything that changes comes from one small JSON file, revalidated every couple of seconds with
 * no-cache so an unchanged file is a 304 from the web server and no PHP runs. Only a comment reaches
 * PHP, and its answer is what opens the side panel: the viewer's link is not in any public file.
 *
 * The video joins the Daily room as a hidden participant with no camera or microphone asked for. The
 * library is only loaded once the stream is on air, so a page waiting for a live costs nothing.
 *
 * @package whd
 */
( function ( $ ) {
	'use strict';

	var D = window.WHD_LIVE;
	if ( ! D ) {
		return;
	}
	var T = D.i18n;

	function byId( id ) {
		return document.getElementById( 'whdl-' + id );
	}
	var root = document.getElementById( 'whdl' );
	var video = byId( 'video' );

	var state = D.state || {};
	var pieces = D.pieces || [];
	var seen = {};
	var call = null;
	var joining = false;
	var joined = false;
	var hasPicture = false;
	var retries = 0;
	var pv = state.pv || 0;
	var sheetPiece = 0;
	var pollTimer = null;

	function el( tag, cls, text ) {
		var node = document.createElement( tag );
		if ( cls ) {
			node.className = cls;
		}
		if ( null !== text && undefined !== text ) {
			node.textContent = text;
		}
		return node;
	}

	function pieceById( id ) {
		return pieces.filter( function ( p ) {
			return p.id === id;
		} )[ 0 ] || null;
	}

	/* ─────────────────────────── who is commenting ─────────────────────────── */

	var KEY = 'whd_live_viewer';
	var viewer = {};
	try {
		viewer = JSON.parse( window.localStorage.getItem( KEY ) || '{}' ) || {};
	} catch ( e ) {
		viewer = {};
	}

	function knownViewer() {
		return viewer.name && viewer.email;
	}

	/* On a phone the name and email need the room: everything else in the column steps aside. */
	function showWho( on ) {
		byId( 'who' ).hidden = ! on;
		root.classList.toggle( 'is-asking', on );
	}

	function renderWho() {
		var as = byId( 'as' );
		if ( knownViewer() ) {
			showWho( false );
			as.hidden = false;
			as.textContent = '';
			as.appendChild( document.createTextNode( T.as.replace( '%s', viewer.name ) + ' · ' ) );
			var change = el( 'button', 'whdl-as__change', T.change );
			change.type = 'button';
			change.addEventListener( 'click', function () {
				byId( 'name' ).value = viewer.name;
				byId( 'email' ).value = viewer.email;
				viewer = {};
				try {
					window.localStorage.removeItem( KEY );
				} catch ( e ) {}
				renderWho();
				showWho( true );
				byId( 'name' ).focus();
			} );
			as.appendChild( change );
		} else {
			as.hidden = true;
		}
	}

	/* ─────────────────────────── comments ─────────────────────────── */

	function appendMessage( c, mine ) {
		if ( seen[ c.i ] ) {
			return;
		}
		seen[ c.i ] = 1;
		var box = byId( 'messages' );
		var stick = box.scrollHeight - box.scrollTop - box.clientHeight < 80;
		var row = el( 'li', 'whdl-message' + ( mine ? ' is-mine' : '' ) );
		row.setAttribute( 'data-i', c.i );
		row.appendChild( el( 'strong', '', c.n ) );
		row.appendChild( el( 'span', '', c.m ) );
		box.appendChild( row );
		while ( box.children.length > 120 ) {
			box.removeChild( box.firstChild );
		}
		if ( stick || mine ) {
			box.scrollTop = box.scrollHeight;
		}
	}

	function renderComments() {
		var live = {};
		( state.comments || [] ).forEach( function ( c ) {
			live[ c.i ] = 1;
			appendMessage( c, false );
		} );
		// A comment the host has hidden leaves the page. Older ones out of the window simply stay.
		var oldest = state.comments && state.comments[ 0 ] ? state.comments[ 0 ].i : Infinity;
		Array.prototype.forEach.call( byId( 'messages' ).children, function ( row ) {
			var i = +row.getAttribute( 'data-i' );
			if ( i >= oldest && ! live[ i ] && ! row.classList.contains( 'is-mine' ) ) {
				row.hidden = true;
			}
		} );
	}

	function showError( text ) {
		var box = byId( 'error' );
		box.textContent = text || '';
		box.hidden = ! text;
	}

	function send( e ) {
		e.preventDefault();
		var input = byId( 'message' );
		var message = input.value.trim();
		if ( 'live' !== state.stage || ! message ) {
			return;
		}
		if ( ! knownViewer() ) {
			var name = byId( 'name' ).value.trim();
			var email = byId( 'email' ).value.trim();
			if ( ! name || ! /^\S+@\S+\.\S+$/.test( email ) ) {
				showWho( true );
				showError( byId( 'who' ).getAttribute( 'data-asked' ) ? T.needName : '' );
				byId( 'who' ).setAttribute( 'data-asked', '1' );
				( name ? byId( 'email' ) : byId( 'name' ) ).focus();
				return;
			}
			viewer = { name: name, email: email };
		}
		showError( '' );
		var button = byId( 'form' ).querySelector( '.whdl-send' );
		button.disabled = true;

		window.fetch( D.comment, {
			method: 'POST',
			credentials: 'omit', // nothing about the visitor's session belongs in this request
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( {
				name: viewer.name,
				email: viewer.email,
				message: message,
				optin: byId( 'optin' ).checked ? 1 : 0,
				website: byId( 'website' ).value
			} )
		} ).then( function ( r ) {
			return r.json().then( function ( data ) {
				return { ok: r.ok, status: r.status, data: data };
			} );
		} ).then( function ( res ) {
			if ( ! res.ok ) {
				if ( 400 === res.status && /email/i.test( res.data.message || '' ) ) {
					viewer = {};
					showWho( true );
				}
				showError( res.data.message || T.sendFailed );
				return;
			}
			try {
				window.localStorage.setItem( KEY, JSON.stringify( viewer ) );
			} catch ( err ) {}
			byId( 'optin' ).checked = false;
			input.value = '';
			renderWho();
			if ( res.data.comment ) {
				appendMessage( res.data.comment, true );
			}
			if ( res.data.piece ) {
				openSheet( res.data.piece, res.data.reply || '', true );
			}
			if ( res.data.lead ) {
				document.dispatchEvent( new CustomEvent( 'whd:subscribed', { detail: { event_id: res.data.lead, source: 'live' } } ) );
			}
		} ).catch( function () {
			showError( T.sendFailed );
		} ).then( function () {
			button.disabled = false;
		} );
	}

	/* ─────────────────────────── the pieces ─────────────────────────── */

	function renderPieces() {
		var box = byId( 'pieces' );
		var showing = state.showing ? state.showing.id : 0;
		box.textContent = '';
		// What is on screen first; the rest in the host's order.
		var list = pieces.slice().sort( function ( a, b ) {
			return ( b.id === showing ? 1 : 0 ) - ( a.id === showing ? 1 : 0 );
		} );
		list.forEach( function ( piece ) {
			var row = el( 'li', 'whdl-piece' + ( piece.id === showing ? ' is-on' : '' ) );
			var btn = el( 'button' );
			btn.type = 'button';
			var img = el( 'img' );
			img.src = piece.thumb;
			img.alt = '';
			img.loading = 'lazy';
			btn.appendChild( img );
			var words = el( 'span', 'whdl-piece__words' );
			if ( piece.id === showing ) {
				words.appendChild( el( 'small', '', T.nowShowing ) );
			}
			words.appendChild( el( 'strong', '', piece.name ) );
			var price = el( 'em', '', piece.price );
			words.appendChild( price );
			if ( ! piece.stock ) {
				words.appendChild( el( 'small', 'whdl-piece__out', T.soldOut ) );
			}
			btn.appendChild( words );
			btn.addEventListener( 'click', function () {
				openSheet( piece, '', false );
			} );
			row.appendChild( btn );
			box.appendChild( row );
		} );
		root.classList.toggle( 'has-pieces', pieces.length > 0 );
	}

	function renderShowing() {
		var showing = state.showing;
		var piece = showing ? pieceById( showing.id ) : null;
		var now = byId( 'now' );
		var hit = byId( 'hit' );
		now.hidden = ! piece || 'live' !== state.stage;
		if ( piece ) {
			byId( 'now-img' ).src = piece.thumb;
			byId( 'now-name' ).textContent = piece.name;
			byId( 'now-price' ).textContent = piece.price;
		}
		// The card itself is part of the video, so what makes it clickable is a box laid over the spot.
		var rect = showing && showing.rect;
		var on = piece && rect && 4 === rect.length && hasPicture && ! state.covered && 'live' === state.stage;
		hit.hidden = ! on;
		if ( on ) {
			hit.style.left = rect[ 0 ] * 100 + '%';
			hit.style.top = rect[ 1 ] * 100 + '%';
			hit.style.width = rect[ 2 ] * 100 + '%';
			hit.style.height = rect[ 3 ] * 100 + '%';
		}
	}

	function showingPiece() {
		return state.showing ? pieceById( state.showing.id ) : null;
	}

	/* ─────────────────────────── the side panel ─────────────────────────── */

	function fitCookie() {
		var m = document.cookie.match( /(?:^|;\s*)whd_fit=([^;]*)/ );
		if ( ! m ) {
			return {};
		}
		var text = m[ 1 ];
		for ( var i = 0; i < 3; i++ ) {
			try {
				var fit = JSON.parse( text );
				return fit && 'object' === typeof fit ? fit : {};
			} catch ( e ) {
				try {
					text = decodeURIComponent( text );
				} catch ( e2 ) {
					return {};
				}
			}
		}
		return {};
	}

	/* The only size left, or the one they wore last time when that points at a single option. */
	function preferred( piece ) {
		var open = piece.options.filter( function ( o ) {
			return o.stock;
		} );
		if ( 1 === open.length ) {
			return open[ 0 ].id;
		}
		var fit = fitCookie();
		var keys = Object.keys( fit );
		if ( ! keys.length ) {
			return 0;
		}
		var hits = open.filter( function ( o ) {
			return keys.every( function ( k ) {
				return ! o.attrs[ k ] || String( o.attrs[ k ] ).toLowerCase() === String( fit[ k ] ).toLowerCase();
			} ) && keys.some( function ( k ) {
				return o.attrs[ k ];
			} );
		} );
		return 1 === hits.length ? hits[ 0 ].id : 0;
	}

	function setBagCount( fragments ) {
		var html = fragments && fragments[ '.whdl-bag__n' ];
		if ( ! html ) {
			return;
		}
		var holder = el( 'div' );
		holder.innerHTML = html;
		var fresh = holder.firstChild;
		var mine = document.querySelector( '.whdl-bag__n' );
		if ( fresh && mine ) {
			mine.textContent = fresh.textContent;
			mine.setAttribute( 'data-n', fresh.getAttribute( 'data-n' ) );
		}
	}

	function addToBag( id, button ) {
		var body = new FormData();
		body.append( 'product_id', id );
		body.append( 'quantity', 1 );
		button.disabled = true;
		button.textContent = T.adding;
		window.fetch( D.addUrl, { method: 'POST', body: body, credentials: 'same-origin' } ).then( function ( r ) {
			return r.json();
		} ).then( function ( res ) {
			if ( ! res || res.error || ! res.fragments ) {
				throw new Error( 'refused' );
			}
			setBagCount( res.fragments );
			button.textContent = T.added;
			button.classList.add( 'is-done' );
			if ( $ ) {
				// What the pixel and anything else on the site already listen to.
				button.setAttribute( 'data-product_id', id );
				button.setAttribute( 'data-quantity', '1' );
				$( document.body ).trigger( 'added_to_cart', [ res.fragments, res.cart_hash, $( button ) ] );
			}
			setTimeout( function () {
				button.disabled = false;
				button.textContent = T.add;
				button.classList.remove( 'is-done' );
			}, 2400 );
		} ).catch( function () {
			button.disabled = false;
			button.textContent = T.add;
			var note = byId( 'buy' ).querySelector( '.whdl-buy__note' );
			if ( note ) {
				note.textContent = T.addFailed;
				note.hidden = false;
			}
		} );
	}

	function renderBuy( piece ) {
		var box = byId( 'buy' );
		box.textContent = '';
		var note = el( 'p', 'whdl-buy__note' );
		note.hidden = true;

		if ( ! piece.stock ) {
			box.appendChild( el( 'p', 'whdl-buy__out', T.soldOut ) );
			return;
		}
		if ( ! D.addUrl || piece.choose || ( 'simple' === piece.type && ! piece.add ) ) {
			var open = el( 'a', 'whdl-cta', T.options );
			open.href = piece.link + '/o';
			open.target = '_blank';
			open.rel = 'noopener';
			box.appendChild( open );
			return;
		}

		var select = null;
		if ( 'variable' === piece.type ) {
			var label = el( 'label', 'whdl-buy__size' );
			label.appendChild( el( 'span', '', T.size ) );
			select = el( 'select' );
			var first = el( 'option', '', T.chooseSize );
			first.value = '';
			select.appendChild( first );
			piece.options.forEach( function ( o ) {
				var opt = el( 'option', '', o.stock ? o.label : o.label + ' · ' + T.gone );
				opt.value = o.id;
				opt.disabled = ! o.stock;
				select.appendChild( opt );
			} );
			var start = preferred( piece );
			if ( start ) {
				select.value = start;
			}
			label.appendChild( select );
			box.appendChild( label );
		}

		var add = el( 'button', 'whdl-cta whdl-cta--wide', T.add );
		add.type = 'button';
		add.addEventListener( 'click', function () {
			note.hidden = true;
			if ( select && ! select.value ) {
				select.focus();
				note.textContent = T.chooseSize;
				note.hidden = false;
				return;
			}
			addToBag( select ? +select.value : piece.id, add );
		} );
		box.appendChild( add );
		box.appendChild( note );
	}

	function openSheet( piece, greeting, isReply ) {
		sheetPiece = piece.id;
		byId( 'sheet-img' ).src = piece.image;
		byId( 'sheet-img' ).alt = piece.name;
		byId( 'sheet-kicker' ).textContent = isReply ? T.yourLink : T.fromLive;
		byId( 'sheet-name' ).textContent = piece.name;
		byId( 'sheet-price' ).textContent = piece.price;
		byId( 'sheet-regular' ).textContent = piece.regular || '';
		byId( 'sheet-text' ).textContent = piece.short || '';
		var note = byId( 'sheet-note' );
		note.textContent = greeting || '';
		note.hidden = ! greeting;
		var link = byId( 'sheet-link' );
		link.href = piece.link + '/o';
		link.textContent = T.view;
		renderBuy( piece );
		var sheet = byId( 'sheet' );
		sheet.classList.add( 'is-open' );
		sheet.setAttribute( 'aria-hidden', 'false' );
		root.classList.add( 'sheet-open' );
		sheet.focus( { preventScroll: true } );
	}

	function closeSheet() {
		var sheet = byId( 'sheet' );
		sheet.classList.remove( 'is-open' );
		sheet.setAttribute( 'aria-hidden', 'true' );
		root.classList.remove( 'sheet-open' );
		sheetPiece = 0;
	}

	/* ─────────────────────────── the screen over the video ─────────────────────────── */

	function pad( n ) {
		return ( n < 10 ? '0' : '' ) + n;
	}

	function renderClock() {
		var clock = byId( 'clock' );
		var at = state.startsAt || 0;
		var left = at - Math.floor( Date.now() / 1000 );
		if ( 'scheduled' !== state.stage || ! at || left <= 0 ) {
			clock.hidden = true;
			return;
		}
		clock.hidden = false;
		var days = Math.floor( left / 86400 );
		var parts = days > 0 ? [ [ days, 'd' ], [ Math.floor( left / 3600 ) % 24, 'h' ], [ Math.floor( left / 60 ) % 60, 'm' ] ] : [ [ Math.floor( left / 3600 ), 'h' ], [ Math.floor( left / 60 ) % 60, 'm' ], [ left % 60, 's' ] ];
		clock.textContent = '';
		parts.forEach( function ( part ) {
			var cell = el( 'span' );
			cell.appendChild( el( 'b', '', pad( part[ 0 ] ) ) );
			cell.appendChild( el( 'i', '', part[ 1 ] ) );
			clock.appendChild( cell );
		} );
	}

	/* What is laid over the video when there is no picture to show. */
	function renderScreen() {
		var screen = byId( 'screen' );
		var kicker = byId( 'screen-kicker' );
		var text = byId( 'screen-text' );
		var spinner = byId( 'spinner' );
		var stage = state.stage;
		byId( 'tap' ).hidden = true;
		byId( 'share' ).hidden = 'scheduled' !== stage;

		if ( 'live' === stage && hasPicture ) {
			screen.hidden = true;
			return;
		}
		screen.hidden = false;
		spinner.hidden = true;
		text.hidden = false;

		if ( 'ended' === stage ) {
			kicker.textContent = T.wrap;
			text.textContent = pieces.length ? T.wrapPieces : T.wrapText;
		} else if ( 'live' === stage ) {
			kicker.textContent = joined ? T.back : T.connecting;
			spinner.hidden = false;
			text.hidden = true;
		} else {
			kicker.textContent = state.startsAt && state.startsAt > Date.now() / 1000 ? T.startsIn : T.soon;
		}
	}

	function renderStage() {
		var live = 'live' === state.stage;
		root.setAttribute( 'data-stage', state.stage );
		byId( 'badge' ).hidden = ! live;
		byId( 'count' ).hidden = ! live;
		var input = byId( 'message' );
		input.disabled = ! live;
		var showing = showingPiece();
		input.placeholder = ! live ? T.closed : ( showing && showing.say && D.cta ? D.cta.replace( /\{code\}/g, showing.say ) : T.say );
		byId( 'form' ).querySelector( '.whdl-send' ).disabled = ! live;
		renderScreen();
		renderShowing();
	}

	function setViewers( n ) {
		byId( 'count-n' ).textContent = String( Math.max( 0, n ) );
	}

	/* ─────────────────────────── the video ─────────────────────────── */

	function loadDaily() {
		if ( window.Daily || window.DailyIframe ) {
			return Promise.resolve();
		}
		return new Promise( function ( resolve, reject ) {
			var s = el( 'script' );
			s.src = D.daily;
			s.crossOrigin = 'anonymous';
			s.onload = resolve;
			s.onerror = reject;
			document.head.appendChild( s );
		} );
	}

	function playVideo() {
		// With sound if the browser allows it, which it often does for somebody who has been here before.
		video.muted = false;
		var tried = video.play();
		if ( ! tried || ! tried.then ) {
			return;
		}
		tried.then( function () {
			byId( 'sound' ).hidden = true;
		} ).catch( function () {
			video.muted = true;
			video.play().then( function () {
				byId( 'sound' ).hidden = false;
			} ).catch( function () {
				// Even muted video refused (a phone in low-power mode): it takes a tap.
				var tap = byId( 'tap' );
				tap.textContent = T.tapPlay;
				tap.hidden = false;
				byId( 'screen' ).hidden = false;
				byId( 'spinner' ).hidden = true;
			} );
		} );
	}

	/* Take the host's picture and sound from the call, whatever state the call is in. Safe to repeat. */
	function attach() {
		if ( ! call ) {
			return;
		}
		var people = call.participants();
		var host = null;
		Object.keys( people ).forEach( function ( key ) {
			if ( 'local' !== key && ! host ) {
				host = people[ key ];
			}
		} );
		var tracks = host && host.tracks ? host.tracks : {};
		var v = tracks.video && 'playable' === tracks.video.state ? tracks.video.persistentTrack : null;
		var a = tracks.audio && 'playable' === tracks.audio.state ? tracks.audio.persistentTrack : null;

		var had = hasPicture;
		hasPicture = !! v;
		if ( v ) {
			var current = video.srcObject;
			var ids = current ? current.getTracks().map( function ( t ) {
				return t.id;
			} ) : [];
			var same = ids.indexOf( v.id ) >= 0 && ( ! a || ids.indexOf( a.id ) >= 0 ) && ids.length === ( a ? 2 : 1 );
			if ( ! same ) {
				video.srcObject = new MediaStream( a ? [ v, a ] : [ v ] );
				playVideo();
			}
		}
		if ( had !== hasPicture ) {
			renderStage();
		}
	}

	function leave() {
		if ( call ) {
			var c = call;
			call = null;
			c.leave().catch( function () {} ).then( function () {
				try {
					c.destroy();
				} catch ( e ) {}
			} );
		}
		joined = false;
		joining = false;
		hasPicture = false;
		video.srcObject = null;
		byId( 'sound' ).hidden = true;
	}

	function retryLater() {
		if ( 'live' === state.stage && retries < 6 ) {
			retries++;
			setTimeout( join, 3000 * retries );
		}
	}

	function join() {
		if ( joining || joined || 'live' !== state.stage || ! state.room ) {
			return;
		}
		joining = true;
		renderScreen();
		loadDaily().then( function () {
			var Factory = window.Daily || window.DailyIframe;
			call = Factory.createCallObject( {
				// Never asks for a camera or a microphone: viewers only receive.
				audioSource: false,
				videoSource: false,
				startAudioOff: true,
				startVideoOff: true,
				subscribeToTracksAutomatically: true
			} );
			[ 'track-started', 'track-stopped', 'participant-joined', 'participant-updated', 'participant-left' ].forEach( function ( name ) {
				call.on( name, attach );
			} );
			call.on( 'participant-counts-updated', function ( e ) {
				var counts = e.participantCounts || {};
				setViewers( ( counts.hidden || 0 ) + Math.max( 0, ( counts.present || 0 ) - 1 ) ); // the host is not a viewer
			} );
			call.on( 'error', function ( e ) {
				var type = e.error && e.error.type;
				leave();
				if ( 'meeting-full' === type ) {
					byId( 'screen' ).hidden = false;
					byId( 'screen-kicker' ).textContent = T.full;
					byId( 'spinner' ).hidden = true;
					retryLater();
				} else if ( 'exp-room' === type || 'no-room' === type ) {
					poll( true );
				} else {
					byId( 'screen-kicker' ).textContent = T.failed;
					byId( 'spinner' ).hidden = true;
					retryLater();
				}
			} );
			call.on( 'left-meeting', function () {
				if ( joined ) {
					joined = false;
					hasPicture = false;
					renderStage();
					retryLater();
				}
			} );
			return call.join( { url: state.room } );
		} ).then( function () {
			joined = true;
			joining = false;
			retries = 0;
			attach();
			renderStage();
		} ).catch( function () {
			joining = false;
			if ( call ) {
				leave();
			}
			byId( 'screen-kicker' ).textContent = T.failed;
			byId( 'spinner' ).hidden = true;
			retryLater();
		} );
	}

	/* ─────────────────────────── the state file ─────────────────────────── */

	function apply( next ) {
		var before = state.stage;
		state = next;
		renderComments();
		if ( next.pv !== pv ) {
			pv = next.pv;
			window.fetch( D.piecesUrl, { cache: 'no-cache' } ).then( function ( r ) {
				return r.json();
			} ).then( function ( list ) {
				pieces = Array.isArray( list ) ? list : pieces;
				renderPieces();
				renderShowing();
			} ).catch( function () {} );
		}
		renderPieces();
		if ( ! joined ) {
			setViewers( next.viewers || 0 );
		}
		if ( 'live' === next.stage ) {
			join();
		} else if ( 'live' === before ) {
			leave();
		}
		renderStage();
	}

	function poll( now ) {
		clearTimeout( pollTimer );
		if ( ! now && 'ended' === state.stage ) {
			return;
		}
		window.fetch( D.stateUrl, { cache: 'no-cache', credentials: 'omit' } ).then( function ( r ) {
			return r.ok ? r.json() : null;
		} ).then( function ( next ) {
			if ( next && next.stage ) {
				apply( next );
			}
		} ).catch( function () {} ).then( function () {
			if ( 'ended' === state.stage ) {
				return;
			}
			var hidden = 'hidden' === document.visibilityState;
			var wait = 'live' === state.stage ? ( hidden ? 6000 : 2000 ) : 15000;
			// A touch of jitter, so a crowd that loaded together does not poll together.
			pollTimer = setTimeout( poll, wait + Math.random() * 400 );
		} );
	}

	/* ─────────────────────────── start ─────────────────────────── */

	byId( 'copy' ).addEventListener( 'click', function () {
		var btn = this;
		var url = btn.getAttribute( 'data-url' );
		var done = function () {
			btn.textContent = T.copied;
			setTimeout( function () {
				btn.textContent = T.copy;
			}, 1600 );
		};
		var oldWay = function () {
			var box = el( 'textarea' );
			box.value = url;
			box.style.position = 'fixed';
			box.style.opacity = '0';
			document.body.appendChild( box );
			box.select();
			var worked = document.execCommand( 'copy' );
			box.remove();
			if ( worked ) {
				done();
			}
		};
		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( url ).then( done, oldWay ); // a browser can refuse it even on https
			return;
		}
		oldWay();
	} );
	byId( 'form' ).addEventListener( 'submit', send );
	byId( 'sheet-x' ).addEventListener( 'click', closeSheet );
	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key && sheetPiece ) {
			closeSheet();
		}
	} );
	byId( 'sound' ).addEventListener( 'click', function () {
		video.muted = false;
		video.play();
		this.hidden = true;
	} );
	byId( 'tap' ).addEventListener( 'click', function () {
		video.muted = false;
		video.play().catch( function () {} );
		this.hidden = true;
		renderScreen();
	} );
	var openShowing = function () {
		var piece = showingPiece();
		if ( piece ) {
			openSheet( piece, '', false );
		}
	};
	byId( 'hit' ).addEventListener( 'click', openShowing );
	byId( 'now' ).addEventListener( 'click', openShowing );

	renderWho();
	renderPieces();
	renderComments();
	renderStage();
	renderClock();
	setInterval( renderClock, 1000 );
	if ( 'live' === state.stage ) {
		join();
	}
	poll();
}( window.jQuery ) );
