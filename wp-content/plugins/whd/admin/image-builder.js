/**
 * Image Builder, step 1: product photographs in, new images out.
 *
 * The same chain the product screen runs for its new pictures, through the same server steps (run
 * here with no product): the photographs read edge to edge, the details cut out and counted again,
 * then for every image a drawing, a check of it against the real photographs, and one more drawing
 * with the mistakes named if the check found any — the better of the two kept, the other deleted.
 *
 * Every image lands in the media library as it is made. Tick the ones to keep, delete the rest, and
 * take the kept ones on to step 2, post production, which is image-editor.js.
 */
( function () {
	'use strict';

	var D = window.WHD_IB || {};
	var T = D.i18n || {};
	var Kit = window.WHDAIKit;
	var P = window.WHDImagePresets;
	var root = document.getElementById( 'whd-ib' );
	if ( ! root || ! Kit || ! P ) {
		return;
	}

	var esc = Kit.esc;
	var fmt = Kit.fmt;
	var client = Kit.client( { root: D.rest.root, nonce: D.rest.nonce, jobs: D.rest.jobs, last: D.rest.last, strings: T } );
	var api = client.api;
	var lightbox = Kit.lightbox( T );
	var loader = Kit.loader( T );
	var max = parseInt( D.max, 10 ) || 8;
	var lookMax = parseInt( D.lookMax, 10 ) || 6;

	var photos = [];
	var mode = 'model';
	var format = '';
	var count = 4;
	var shots = [];          // [ { mode: '' (as above) | model | mannequin | flatlay | still, ask } ]
	var results = [];        // [ { pic, verdict, keep, shot, position, tile } ]
	var facts = null;        // the reading of the current photographs, kept for "Draw again"
	var factsFor = '';
	var busy = false;

	var el = {
		photos: document.getElementById( 'whd-ib-photos' ),
		photosNote: document.getElementById( 'whd-ib-photos-note' ),
		modes: document.getElementById( 'whd-ib-modes' ),
		bg: document.getElementById( 'whd-ib-bg' ),
		bgChips: document.getElementById( 'whd-ib-bg-chips' ),
		asks: document.getElementById( 'whd-ib-asks' ),
		askChips: document.getElementById( 'whd-ib-ask-chips' ),
		format: document.getElementById( 'whd-ib-format' ),
		count: document.getElementById( 'whd-ib-count' ),
		shots: document.getElementById( 'whd-ib-shots' ),
		go: document.getElementById( 'whd-ib-go' ),
		status: document.getElementById( 'whd-ib-status' ),
		results: document.getElementById( 'whd-ib-results' ),
		gen: document.getElementById( 'whd-ib-gen' ),
		edit: document.getElementById( 'whd-ib-edit' ),
		editor: document.getElementById( 'whd-ie' )
	};

	var MODES = [
		{ key: 'model', name: T.modeModel, note: T.modeModelD, art: '<circle cx="24" cy="11" r="5"/><path d="M15 44l3-20c1-5 3-7 6-7s5 2 6 7l3 20M20 30h8"/>' },
		{ key: 'mannequin', name: T.modeGhost, note: T.modeGhostD, art: '<path d="M18 9c2 3 4 4 6 4s4-1 6-4l7 4-3 8-4-2v24H18V19l-4 2-3-8z" stroke-dasharray="3 2.5"/>' },
		{ key: 'flatlay', name: T.modeFlat, note: T.modeFlatD, art: '<rect x="7" y="7" width="34" height="34" rx="2"/><path d="M17 14l4-3h6l4 3 5 3-2 5-3-1v14H17V21l-3 1-2-5z"/>' },
		{ key: 'still', name: T.modeStill, note: T.modeStillD, art: '<path d="M24 7v4M14 13h20M24 11l-8 4M24 11l8 4"/><path d="M16 15l-3 6 4 1v18h14V22l4-1-3-6"/>' }
	];

	function say( text, bad ) {
		el.status.textContent = text || '';
		el.status.className = 'whd-studio__status' + ( bad ? ' is-bad' : '' );
	}

	/* ─────────────────────────── the steps ─────────────────────────── */

	function show( which ) {
		var edit = 'edit' === which;
		el.gen.hidden = edit;
		el.edit.hidden = ! edit;
		Array.prototype.forEach.call( root.querySelectorAll( '[data-go]' ), function ( b ) {
			var on = b.getAttribute( 'data-go' ) === which;
			b.classList.toggle( 'is-on', on );
			b.setAttribute( 'aria-selected', on ? 'true' : 'false' );
		} );
		if ( edit ) {
			window.WHDImageEditor.mount( el.editor, {
				strings: T,
				client: client,
				fonts: D.fonts || [],
				logos: D.logos || [],
				brand: D.brand || {},
				currency: D.currency || ''
			} );
			window.WHDImageEditor.show();
		}
	}

	Array.prototype.forEach.call( root.querySelectorAll( '[data-go]' ), function ( b ) {
		b.addEventListener( 'click', function () {
			show( b.getAttribute( 'data-go' ) );
		} );
	} );

	/* ─────────────────────────── 1. photographs ─────────────────────────── */

	function photoOf( m ) {
		var a = m.toJSON ? m.toJSON() : m;
		var size = a.sizes && ( a.sizes.thumbnail || a.sizes.medium );
		var view = a.sizes && ( a.sizes.medium || a.sizes.large );
		var large = a.sizes && ( a.sizes.large || a.sizes.full );
		return { id: a.id, thumb: size ? size.url : a.url, view: view ? view.url : a.url, large: large ? large.url : a.url, title: a.title || '' };
	}

	function paintPhotos() {
		el.photos.innerHTML = '';
		photos.forEach( function ( p, i ) {
			var fig = document.createElement( 'figure' );
			fig.className = 'whd-studio__photo';
			fig.innerHTML = '<button type="button" class="whd-studio__photo-view" aria-label="' + esc( fmt( T.viewPhoto, i + 1 ) ) + '"><img src="' + esc( p.thumb ) + '" alt=""></button>' +
				'<span class="whd-studio__photo-n" aria-hidden="true">' + ( i + 1 ) + '</span>' +
				'<button type="button" class="whd-studio__photo-x" aria-label="' + esc( T.remove ) + '"></button>';
			fig.querySelector( '.whd-studio__photo-view' ).addEventListener( 'click', function () {
				lightbox.open( photos.map( function ( q, j ) {
					return { src: q.large || q.view || q.thumb, caption: fmt( T.photoN, j + 1 ) };
				} ), i, this );
			} );
			fig.querySelector( '.whd-studio__photo-x' ).addEventListener( 'click', function () {
				photos.splice( i, 1 );
				paintPhotos();
			} );
			el.photos.appendChild( fig );
		} );
		var add = document.createElement( 'button' );
		add.type = 'button';
		add.className = 'whd-studio__add';
		add.innerHTML = '<span class="whd-studio__add-plus" aria-hidden="true">+</span><span>' + esc( photos.length ? T.change : T.choose ) + '</span>';
		add.addEventListener( 'click', pick );
		el.photos.appendChild( add );
		el.photos.classList.toggle( 'is-empty', ! photos.length );
		el.photosNote.textContent = photos.length
			? fmt( T.some, photos.length ).replace( / Any not yet on the product.*$/, '' ) + ( photos.length > lookMax ? ' ' + fmt( T.tooMany, lookMax ) : '' )
			: T.none;
	}

	function pick() {
		if ( ! window.wp || ! wp.media ) {
			return;
		}
		var frame = wp.media( { title: T.choose, button: { text: T.use }, library: { type: 'image' }, multiple: 'add' } );
		frame.on( 'open', function () {
			frame.content.mode( 'browse' );
			var selection = frame.state().get( 'selection' );
			photos.forEach( function ( p ) {
				var a = wp.media.attachment( p.id );
				a.fetch();
				selection.add( a );
			} );
		} );
		frame.on( 'select', function () {
			photos = frame.state().get( 'selection' ).map( photoOf ).slice( 0, 12 );
			paintPhotos();
		} );
		frame.open();
	}

	/* ─────────────────────────── 2. how it is shown ─────────────────────────── */

	function paintModes() {
		el.modes.innerHTML = MODES.map( function ( m ) {
			return '<label class="whd-ib__mode' + ( m.key === mode ? ' is-on' : '' ) + '">' +
				'<input type="radio" name="whd-ib-mode" value="' + m.key + '"' + ( m.key === mode ? ' checked' : '' ) + '>' +
				'<svg viewBox="0 0 48 48" width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + m.art + '</svg>' +
				'<span class="whd-ib__mode-n">' + esc( m.name ) + '</span><span class="whd-ib__mode-d">' + esc( m.note ) + '</span></label>';
		} ).join( '' );
		Array.prototype.forEach.call( el.modes.querySelectorAll( 'input' ), function ( r ) {
			r.addEventListener( 'change', function () {
				mode = r.value;
				paintModes();
				paintShots();
			} );
		} );
	}

	/* ─────────────────────────── 3. the scene ─────────────────────────── */

	function chips( box, list, field, append ) {
		box.innerHTML = list.map( function ( c, i ) {
			return '<button type="button" class="whd-ib__chip" data-chip="' + i + '">' + esc( c.name ) + '</button>';
		} ).join( '' );
		Array.prototype.forEach.call( box.querySelectorAll( '[data-chip]' ), function ( b ) {
			b.addEventListener( 'click', function () {
				var text = list[ +b.getAttribute( 'data-chip' ) ].text;
				field.value = append && field.value.trim() ? field.value.trim().replace( /[.,;]?$/, ', ' ) + text : text;
				field.focus();
			} );
		} );
	}

	/* ─────────────────────────── 4. format and how many ─────────────────────────── */

	function pills( box, list, current, onPick ) {
		box.innerHTML = '';
		list.forEach( function ( item ) {
			var label = document.createElement( 'label' );
			label.className = 'whd-studio__count' + ( item.value === current ? ' is-on' : '' );
			label.innerHTML = '<input type="radio" name="' + box.id + '" value="' + esc( item.value ) + '"' + ( item.value === current ? ' checked' : '' ) + '>' +
				'<span class="whd-studio__count-n">' + esc( item.name ) + '</span>' + ( item.note ? '<span class="whd-studio__count-all">' + esc( item.note ) + '</span>' : '' );
			label.querySelector( 'input' ).addEventListener( 'change', function () {
				onPick( item.value );
			} );
			box.appendChild( label );
		} );
	}

	function paintFormat() {
		var names = { match: T.matchPhoto, square: T.formatSquare, portrait: T.formatPortrait, story: T.formatStory, wide: T.formatWide };
		pills( el.format, P.formats.map( function ( f ) {
			return { value: f.key, name: names[ f.name ] || f.name, note: f.key && names[ f.name ] ? f.key : '' };
		} ), format, function ( v ) {
			format = v;
			paintFormat();
		} );
	}

	function paintCount() {
		var list = [];
		for ( var k = 1; k <= max; k++ ) {
			list.push( { value: k, name: String( k ) } );
		}
		pills( el.count, list, count, function ( v ) {
			count = v;
			paintCount();
			paintShots();
		} );
	}

	/* ─────────────────────────── 5. each image ─────────────────────────── */

	function variety( m, i ) {
		var list = ( T.variety && T.variety[ m ] ) || [];
		return list.length ? list[ i % list.length ] : '';
	}

	function paintShots() {
		while ( shots.length < count ) {
			shots.push( { mode: '', ask: '' } );
		}
		el.shots.innerHTML = '';
		for ( var i = 0; i < count; i++ ) {
			el.shots.appendChild( shotCard( i ) );
		}
	}

	function shotCard( i ) {
		var s = shots[ i ];
		var card = document.createElement( 'div' );
		card.className = 'whd-studio__shot whd-ib__shot';
		var id = 'whd-ib-shot-' + i;
		var as = s.mode || mode;
		card.innerHTML = '<div class="whd-studio__shot-head">' +
				'<span class="whd-studio__shot-n">' + esc( fmt( T.imageN, i + 1 ) ) + '</span>' +
				'<select class="whd-ib__shot-mode" aria-label="' + esc( fmt( T.imageN, i + 1 ) ) + '">' +
					'<option value="">' + esc( ( MODES.filter( function ( m ) {
						return m.key === mode;
					} )[ 0 ] || {} ).name ) + '</option>' +
					MODES.filter( function ( m ) {
						return m.key !== mode;
					} ).map( function ( m ) {
						return '<option value="' + m.key + '"' + ( s.mode === m.key ? ' selected' : '' ) + '>' + esc( m.name ) + '</option>';
					} ).join( '' ) +
				'</select>' +
			'</div>' +
			'<label class="screen-reader-text" for="' + id + '">' + esc( T.instructions ) + '</label>' +
			'<textarea id="' + id + '" rows="2" class="whd-studio__ask" placeholder="' + esc( 'e.g. ' + variety( as, i ) ) + '"></textarea>';
		var ask = card.querySelector( 'textarea' );
		ask.value = s.ask;
		ask.addEventListener( 'input', function () {
			s.ask = ask.value;
		} );
		card.querySelector( 'select' ).addEventListener( 'change', function () {
			s.mode = this.value;
			ask.placeholder = 'e.g. ' + variety( s.mode || mode, i );
		} );
		return card;
	}

	/* ─────────────────────────── generating ─────────────────────────── */

	/* What one image is asked to be: the scene and the requests for all of them, then its own words. */
	function shotFor( i ) {
		var s = shots[ i ] || { mode: '', ask: '' };
		var m = s.mode || mode;
		var own = s.ask.trim() || variety( m, i );
		var bits = [];
		if ( el.bg.value.trim() ) {
			bits.push( 'Background: ' + el.bg.value.trim().replace( /\.$/, '' ) + '.' );
		}
		if ( el.asks.value.trim() ) {
			bits.push( el.asks.value.trim().replace( /\.$/, '' ) + '.' );
		}
		if ( own ) {
			bits.push( 'This image: ' + own.replace( /\.$/, '' ) + '.' );
		}
		return { mode: m, ask: bits.join( ' ' ).slice( 0, 500 ), brief: ( own || 'The whole piece.' ) };
	}

	function key() {
		return photos.map( function ( p ) {
			return p.id;
		} ).join( ',' );
	}

	function read() {
		if ( facts && factsFor === key() ) {
			return Promise.resolve( facts );
		}
		var ids = photos.map( function ( p ) {
			return p.id;
		} );
		var line = step( T.stepLook, T.hintLook );
		return api( 'look', { images: ids, commands: el.asks.value } ).then( function ( f ) {
			line();
			step( T.stepZoom, T.hintZoom );
			return api( 'inspect', { facts: f, commands: el.asks.value } ).catch( function () {
				return f; // the close-ups are a second opinion; the first look is still good
			} );
		} ).then( function ( f ) {
			loader.tick();
			facts = f;
			factsFor = key();
			return f;
		} );
	}

	function hintOf( m ) {
		return { model: T.hintModel, mannequin: T.hintGhost, flatlay: T.hintFlat, still: T.hintStill }[ m ] || T.hintModel;
	}

	function step( label, hint ) {
		loader.step( label, hint );
		return function () {
			loader.tick();
		};
	}

	/* Draw, check, and draw once more with the mistakes named; the better kept, the other deleted. */
	function drawOne( r ) {
		var ids = photos.map( function ( p ) {
			return p.id;
		} );
		var body = { images: ids, facts: facts, shot: r.shot, position: r.position, name: facts && facts.garment ? facts.garment : '', aspect: format };
		var check = function ( pic ) {
			return api( 'check', { image: pic.id, images: ids, facts: facts, shot: r.shot } ).catch( function ( e ) {
				return { ok: false, score: 0, problems: [], unchecked: e.message };
			} );
		};
		var best = null;
		return api( 'draw', body ).then( function ( pic ) {
			loader.note( T.stepCheck );
			return check( pic ).then( function ( verdict ) {
				best = { pic: pic, verdict: verdict };
				if ( verdict.ok || ! ( verdict.problems || [] ).length ) {
					return null;
				}
				loader.note( T.stepRedraw );
				return api( 'draw', Object.assign( {}, body, { fixes: verdict.problems } ) ).then( function ( again ) {
					return check( again ).then( function ( second ) {
						var better = second.ok || ( ! second.unchecked && ( second.score > verdict.score ||
							( second.score === verdict.score && ( second.problems || [] ).length < verdict.problems.length ) ) );
						api( 'discard', { image: ( better ? pic : again ).id } ).catch( function () {} );
						if ( better ) {
							best = { pic: again, verdict: second };
						}
					} );
				}, function () {
					return null;
				} );
			} );
		} ).then( function () {
			r.pic = best.pic;
			r.verdict = best.verdict;
			// Kept unless somebody unticks it: the check's note says what to look at, and a stray
			// detail is often quicker to fix in post production than to draw again.
			r.keep = true;
			r.error = '';
			loader.picture( best.pic.thumb || best.pic.url );
		} ).catch( function ( e ) {
			r.error = e.message;
		} ).then( function () {
			paintTile( r );
			paintFoot();
		} );
	}

	function generate() {
		if ( busy ) {
			return;
		}
		if ( ! D.draw ) {
			say( T.needDraw, true );
			return;
		}
		if ( ! photos.length ) {
			say( T.needPhotos, true );
			return;
		}
		busy = true;
		el.go.disabled = true;
		say( '' );
		var fresh = ! facts || factsFor !== key();
		loader.open( photos[ 0 ].view || photos[ 0 ].thumb, ( fresh ? 2 : 0 ) + count, ( fresh ? 70 : 0 ) + 50 * count );
		var start = results.length;
		var batch = [];
		for ( var i = 0; i < count; i++ ) {
			var r = { shot: shotFor( i ), position: start + i + 1, keep: false };
			results.push( r );
			batch.push( r );
		}
		paintResults();
		read().then( function () {
			return batch.reduce( function ( chain, r, j ) {
				return chain.then( function () {
					step( fmt( T.stepImage, j + 1, batch.length ), hintOf( r.shot.mode ) );
					return drawOne( r ).then( function () {
						loader.tick();
					} );
				} );
			}, Promise.resolve() );
		} ).then( function () {
			loader.finish( true, T.genDone, function () {
				el.results.scrollIntoView( { behavior: 'smooth', block: 'start' } );
			} );
		} ).catch( function ( e ) {
			batch.forEach( function ( r ) {
				if ( ! r.pic && ! r.error ) {
					r.error = e.message;
					paintTile( r );
				}
			} );
			say( ( T.stopped || 'Stopped' ) + ': ' + e.message, true );
			loader.finish( false, e.message );
		} ).then( function () {
			busy = false;
			el.go.disabled = false;
			paintFoot();
		} );
	}

	/* ─────────────────────────── results ─────────────────────────── */

	function paintResults() {
		el.results.hidden = ! results.length;
		el.results.innerHTML = '<div class="whd-ib__results-head"><h3 class="whd-studio__h">' + esc( T.resultsHead ) + '</h3><p class="description">' + esc( T.resultsNote ) + '</p></div>' +
			'<div class="whd-ib__tiles"></div><div class="whd-ib__foot"></div>';
		results.forEach( function ( r ) {
			r.tile = document.createElement( 'figure' );
			el.results.querySelector( '.whd-ib__tiles' ).appendChild( r.tile );
			paintTile( r );
		} );
		paintFoot();
	}

	function paintTile( r ) {
		if ( ! r.tile ) {
			return;
		}
		var t = r.tile;
		if ( r.error && ! r.pic ) {
			t.className = 'whd-ib__tile is-bad';
			t.innerHTML = '<div class="whd-ib__tile-img"><span class="whd-ib__tile-x" aria-hidden="true">&times;</span></div><figcaption><span class="whd-ib__tile-state">' + esc( T.failedDraw ) + '</span><span class="whd-ib__tile-note">' + esc( r.error ) + '</span></figcaption>';
			return;
		}
		if ( ! r.pic ) {
			t.className = 'whd-ib__tile is-busy';
			t.innerHTML = '<div class="whd-ib__tile-img"><span class="spinner is-active"></span></div><figcaption><span class="whd-ib__tile-state">' + esc( fmt( T.imageN, r.position ) ) + '</span></figcaption>';
			return;
		}
		var good = r.verdict && r.verdict.ok && ! r.verdict.unchecked;
		var notes = ( r.verdict && r.verdict.problems || [] ).slice();
		if ( r.verdict && r.verdict.unchecked ) {
			notes.push( r.verdict.unchecked );
		}
		t.className = 'whd-ib__tile ' + ( good ? 'is-ok' : 'is-warn' ) + ( r.keep ? ' is-kept' : '' );
		t.innerHTML = '<div class="whd-ib__tile-img"><button type="button" class="whd-ib__tile-view" data-view aria-label="' + esc( T.view ) + '"><img src="' + esc( r.pic.url || r.pic.thumb ) + '" alt=""></button>' +
				'<label class="whd-ib__keep"><input type="checkbox" data-keep' + ( r.keep ? ' checked' : '' ) + '> <span>' + esc( r.keep ? T.kept : T.keep ) + '</span></label></div>' +
			'<figcaption>' +
				'<span class="whd-ib__tile-state">' + esc( good ? T.matches : T.lookAgain ) + '</span>' +
				( notes.length ? '<span class="whd-ib__tile-note">' + esc( notes.join( ' · ' ) ) + '</span>' : '' ) +
				'<span class="whd-ib__tile-acts">' +
					'<button type="button" class="button-link" data-redo>' + esc( T.redo ) + '</button>' +
					'<button type="button" class="button button-small" data-edit>' + esc( T.editThis ) + '</button>' +
				'</span>' +
			'</figcaption>';
		t.querySelector( '[data-view]' ).addEventListener( 'click', function () {
			var shown = results.filter( function ( x ) {
				return x.pic;
			} );
			lightbox.open( shown.map( function ( x ) {
				var ok = x.verdict && x.verdict.ok && ! x.verdict.unchecked;
				return { src: x.pic.large || x.pic.url, caption: fmt( T.imageN, x.position ), state: ok ? T.matches : T.lookAgain, tone: ok ? 'ok' : 'warn', notes: x.verdict ? x.verdict.problems || [] : [] };
			} ), shown.indexOf( r ), this );
		} );
		t.querySelector( '[data-keep]' ).addEventListener( 'change', function () {
			r.keep = this.checked;
			paintTile( r );
			paintFoot();
		} );
		t.querySelector( '[data-redo]' ).addEventListener( 'click', function () {
			redo( r );
		} );
		t.querySelector( '[data-edit]' ).addEventListener( 'click', function () {
			r.keep = true;
			toEditor( [ r ] );
		} );
	}

	/* Draw just this one again: the old one is deleted once the new one is in. */
	function redo( r ) {
		if ( busy ) {
			return;
		}
		busy = true;
		var old = r.pic;
		r.pic = null;
		r.error = '';
		paintTile( r );
		loader.open( photos[ 0 ] ? ( photos[ 0 ].view || photos[ 0 ].thumb ) : '', 1, 50 );
		step( fmt( T.imageN, r.position ), hintOf( r.shot.mode ) );
		( facts ? Promise.resolve() : read() ).then( function () {
			return drawOne( r );
		} ).then( function () {
			if ( old && r.pic && r.pic.id !== old.id ) {
				api( 'discard', { image: old.id } ).catch( function () {} );
			} else if ( ! r.pic ) {
				r.pic = old;
				paintTile( r );
			}
			loader.finish( !! r.pic && ! r.error, r.error || T.genDone );
		} ).then( function () {
			busy = false;
		} );
	}

	function paintFoot() {
		var foot = el.results.querySelector( '.whd-ib__foot' );
		if ( ! foot ) {
			return;
		}
		var kept = results.filter( function ( r ) {
			return r.pic && r.keep;
		} );
		var rest = results.filter( function ( r ) {
			return r.pic && ! r.keep;
		} );
		foot.innerHTML = ( rest.length ? '<button type="button" class="button-link whd-ib__drop" data-drop>' + esc( fmt( T.dropRest, rest.length ) ) + '</button>' : '' ) +
			'<button type="button" class="button button-primary button-large" data-tokept' + ( kept.length ? '' : ' disabled' ) + '>' + esc( kept.length ? fmt( T.editKept, kept.length ) : T.editNone ) + '</button>';
		var drop = foot.querySelector( '[data-drop]' );
		if ( drop ) {
			drop.addEventListener( 'click', function () {
				rest.forEach( function ( r ) {
					api( 'discard', { image: r.pic.id } ).catch( function () {} );
				} );
				results = results.filter( function ( r ) {
					return rest.indexOf( r ) === -1;
				} );
				paintResults();
				say( T.dropped );
			} );
		}
		foot.querySelector( '[data-tokept]' ).addEventListener( 'click', function () {
			toEditor( kept );
		} );
	}

	/* To post production: the editor opens on each one, from the library, at full size. */
	function toEditor( list ) {
		show( 'edit' );
		window.WHDImageEditor.openIds( list.map( function ( r ) {
			return r.pic.id;
		} ) );
	}

	/* ─────────────────────────── start ─────────────────────────── */

	el.go.addEventListener( 'click', generate );
	chips( el.bgChips, P.backgrounds, el.bg, false );
	chips( el.askChips, P.asks, el.asks, true );
	paintPhotos();
	paintModes();
	paintFormat();
	paintCount();
	paintShots();

	window.addEventListener( 'beforeunload', function ( e ) {
		if ( busy || ( window.WHDImageEditor && window.WHDImageEditor.dirty() ) ) {
			e.preventDefault();
			e.returnValue = T.unsaved;
			return T.unsaved;
		}
	} );

	// Opened from the media library on one photo: straight to post production.
	if ( D.start ) {
		show( 'edit' );
		window.WHDImageEditor.open( D.start );
	}
}() );
