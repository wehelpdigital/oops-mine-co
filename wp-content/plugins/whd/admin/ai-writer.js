/**
 * AI Posts and AI Pages.
 *
 * The form is laid out the way Write with AI is on the product screen and on Bulk products: what it
 * is about; what to write, with keywords and anything to specify; then the pictures — how many, and
 * for each one whether it is found or drawn, on a model or a ghost mannequin, with instructions of
 * its own, drawn from photographs of the shop's pieces when some are chosen. One button, the same
 * loader while it works, and the same lightbox for looking at a picture up close.
 *
 * What comes back is a box you can edit: read it, change anything you like, then save it. What is
 * saved is what is in the box — the preview is the draft, not a picture of one.
 */
( function () {
	'use strict';

	var D = window.WHD_AI_WRITER || {};
	var T = D.i18n || {};
	var Kit = window.WHDAIKit;

	var root = document.getElementById( 'whd-writer' );
	if ( ! root || ! Kit ) {
		return;
	}

	var esc = Kit.esc;
	var fmt = Kit.fmt;

	/* The slow steps — the writing, a drawing — run as background jobs where the server has a runner. */
	var client = Kit.client( {
		root: D.rest.root,
		nonce: D.rest.nonce,
		jobs: D.jobs || '',
		last: D.last || '',
		strings: T
	} );
	var api = client.api;
	var lightbox = Kit.lightbox( T );
	var loader = Kit.loader( T );

	var el = {
		idea: document.getElementById( 'whd-writer-idea' ),
		picker: document.getElementById( 'whd-writer-keywords' ),
		notes: document.getElementById( 'whd-writer-notes' ),
		length: document.getElementById( 'whd-writer-length' ),
		photos: document.getElementById( 'whd-writer-photos' ),
		photosNote: document.getElementById( 'whd-writer-photos-note' ),
		counts: document.getElementById( 'whd-writer-counts' ),
		frame: document.getElementById( 'whd-writer-frame' ),
		shots: document.getElementById( 'whd-writer-shots' ),
		go: document.getElementById( 'whd-writer-go' ),
		status: document.getElementById( 'whd-writer-status' ),
		result: document.getElementById( 'whd-writer-result' ),
		title: document.getElementById( 'whd-writer-title' ),
		desc: document.getElementById( 'whd-writer-desc' ),
		descField: document.getElementById( 'whd-writer-desc-field' ),
		descCount: document.getElementById( 'whd-writer-desc-count' ),
		check: document.getElementById( 'whd-writer-check' ),
		body: document.getElementById( 'whd-writer-body' ),
		images: document.getElementById( 'whd-writer-images' ),
		slots: document.getElementById( 'whd-writer-slots' ),
		slotsCount: document.getElementById( 'whd-writer-images-count' ),
		slotsStatus: document.getElementById( 'whd-writer-images-status' ),
		publish: document.getElementById( 'whd-writer-publish' ),
		draft: document.getElementById( 'whd-writer-draft' ),
		again: document.getElementById( 'whd-writer-again' ),
		saveStatus: document.getElementById( 'whd-writer-save-status' ),
		saved: document.getElementById( 'whd-writer-saved' )
	};

	var lengths = D.lengths || [];
	var maxPictures = parseInt( D.maxPictures, 10 ) || 5;
	var length = 'medium';
	var photos = [];          // [ { id, thumb, view, large } ] — the pieces the pictures are drawn from
	var count = 0;            // pictures asked for
	var countPicked = false;  // until somebody picks, the count follows the length
	var frame = '';           // the size drawn pictures are made at; '' = 3:2
	var shots = [];           // [ { mode, ask, own } ] — kept when the count changes, so nothing typed is lost
	var doc = null;
	var slots = [];
	var busy = false;

	function say( text, bad ) {
		el.status.textContent = text || '';
		el.status.className = 'whd-studio__status' + ( bad ? ' is-bad' : '' );
	}

	function keywords() {
		return window.WHDKeywords && el.picker ? window.WHDKeywords.chosen( el.picker ) : [];
	}

	function parts() {
		return Array.prototype.filter.call( root.querySelectorAll( '[data-part]' ), function ( box ) {
			return box.checked;
		} ).map( function ( box ) {
			return box.getAttribute( 'data-part' );
		} );
	}

	function has( part ) {
		return parts().indexOf( part ) !== -1;
	}

	/* ─────────────────────────── how long ─────────────────────────── */

	function lengthOf( key ) {
		var found = lengths[ 1 ] || {};
		lengths.forEach( function ( l ) {
			if ( l.key === key ) {
				found = l;
			}
		} );
		return found;
	}

	function paintLength() {
		if ( ! el.length ) {
			return;
		}
		el.length.innerHTML = '';
		lengths.forEach( function ( l ) {
			var label = document.createElement( 'label' );
			label.className = 'whd-studio__count' + ( l.key === length ? ' is-on' : '' );
			label.innerHTML = '<input type="radio" name="whd-writer-length" value="' + esc( l.key ) + '"' + ( l.key === length ? ' checked' : '' ) + '>' +
				'<span class="whd-studio__count-n">' + esc( l.name ) + '</span>' +
				'<span class="whd-studio__count-all">' + esc( l.note ) + '</span>';
			label.querySelector( 'input' ).addEventListener( 'change', function () {
				length = l.key;
				paintLength();
				paintCounts();
			} );
			el.length.appendChild( label );
		} );
	}

	/* ─────────────────────────── the photographs to work from ─────────────────────────── */

	function photoOf( m ) {
		var a = m.toJSON ? m.toJSON() : m;
		var size = a.sizes && ( a.sizes.thumbnail || a.sizes.medium );
		var view = a.sizes && ( a.sizes.medium || a.sizes.large );
		var large = a.sizes && ( a.sizes.large || a.sizes.full );
		return {
			id: a.id,
			thumb: size ? size.url : a.url,
			view: view ? view.url : a.url,
			large: large ? large.url : a.url,
			alt: a.alt || a.title || ''
		};
	}

	function paintPhotos() {
		if ( ! el.photos ) {
			return;
		}
		el.photos.innerHTML = '';
		photos.forEach( function ( p, i ) {
			var fig = document.createElement( 'figure' );
			fig.className = 'whd-studio__photo';
			fig.innerHTML = '<button type="button" class="whd-studio__photo-view" aria-label="' + esc( fmt( T.viewPhoto, i + 1 ) ) + '">' +
					'<img src="' + esc( p.thumb ) + '" alt="">' +
				'</button>' +
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
				paintShots();
			} );
			el.photos.appendChild( fig );
		} );

		var add = document.createElement( 'button' );
		add.type = 'button';
		add.className = 'whd-studio__add';
		add.innerHTML = '<span class="whd-studio__add-plus" aria-hidden="true">+</span><span>' + esc( photos.length ? T.change : T.choose ) + '</span>';
		add.addEventListener( 'click', pickPhotos );
		el.photos.appendChild( add );
		el.photosNote.textContent = photos.length ? fmt( T.photosSome, photos.length ) : T.photosNone;
	}

	function media( options, onPick ) {
		if ( ! window.wp || ! wp.media ) {
			return;
		}
		var frame = wp.media( options );
		frame.on( 'open', function () {
			/* Open on the library, not on the upload tab: before a mode is chosen the frame says
			   it has none, which is exactly the moment the switch is needed. */
			frame.content.mode( 'browse' );
			if ( options.keep ) {
				var selection = frame.state().get( 'selection' );
				options.keep.forEach( function ( p ) {
					var a = wp.media.attachment( p.id );
					a.fetch();
					selection.add( a );
				} );
			}
		} );
		frame.on( 'select', function () {
			onPick( frame.state().get( 'selection' ).map( photoOf ) );
		} );
		frame.open();
	}

	function pickPhotos() {
		media( { title: T.choose, button: { text: T.use }, library: { type: 'image' }, multiple: 'add', keep: photos }, function ( picked ) {
			photos = picked.slice( 0, 6 );
			paintPhotos();
			paintShots();
		} );
	}

	/* ─────────────────────────── how many pictures ─────────────────────────── */

	/* The size the drawn ones are made at, under the count (ai-kit.js). */
	if ( el.frame && D.canDraw && ( D.frames || [] ).length ) {
		el.frame.appendChild( Kit.framePicker( {
			frames: D.frames,
			value: frame,
			none: T.frameSame,
			noneNote: T.frameSameNote,
			after: T.frameLibrary,
			strings: T,
			onChange: function ( key ) {
				frame = key;
			}
		} ).el );
	}

	function paintCounts() {
		if ( ! el.counts ) {
			return;
		}
		if ( ! countPicked ) {
			count = Math.min( maxPictures, parseInt( lengthOf( length ).pictures, 10 ) || 3 );
		}
		el.counts.innerHTML = '';
		for ( var k = 0; k <= maxPictures; k++ ) {
			( function ( k ) {
				var label = document.createElement( 'label' );
				label.className = 'whd-studio__count' + ( k === count ? ' is-on' : '' );
				label.innerHTML = '<input type="radio" name="whd-writer-count" value="' + k + '"' + ( k === count ? ' checked' : '' ) + '>' +
					'<span class="whd-studio__count-n">' + ( k ? k : esc( T.noNew ) ) + '</span>';
				label.querySelector( 'input' ).addEventListener( 'change', function () {
					count = k;
					countPicked = true;
					paintCounts();
				} );
				el.counts.appendChild( label );
			}( k ) );
		}
		paintShots();
	}

	function paintShots() {
		if ( ! el.shots ) {
			return;
		}
		while ( shots.length < count ) {
			shots.push( { mode: 'auto', ask: '', own: null } );
		}
		el.shots.innerHTML = '';
		for ( var i = 0; i < count; i++ ) {
			el.shots.appendChild( shotCard( i ) );
		}
		if ( count && D.canDraw ) {
			var hint = document.createElement( 'p' );
			hint.className = 'description whd-studio__shots-note';
			hint.textContent = T.leftBlankW;
			el.shots.appendChild( hint );
		}
		el.shots.hidden = ! count;
		if ( el.frame ) {
			el.frame.hidden = ! count || ! D.canDraw;
		}
	}

	/* One card per picture: where it goes, how it is made, and anything to say about it. */
	function shotCard( i ) {
		var s = shots[ i ];
		if ( 'mannequin' === s.mode && ! photos.length ) {
			s.mode = 'model'; // the photographs it was to be drawn from are gone
		}
		var card = document.createElement( 'div' );
		card.className = 'whd-studio__shot whd-writer__shot is-' + ( s.own ? 'own' : s.mode );
		var name = 'whd-writer-mode-' + i;
		var title = fmt( T.pictureN, i + 1 ) + ' · ' + ( i ? T.inBody : T.atTop );

		function choice( mode, label, off ) {
			return '<label class="whd-studio__mode' + ( off ? ' is-off' : '' ) + '"' + ( off ? ' title="' + esc( T.needPhotos ) + '"' : '' ) + '>' +
				'<input type="radio" name="' + name + '" value="' + mode + '"' + ( s.mode === mode ? ' checked' : '' ) + ( off ? ' disabled' : '' ) + '>' +
				'<span>' + esc( label ) + '</span></label>';
		}

		var how = s.own
			? '<span class="whd-writer__own-tag">' + esc( T.mine ) + '</span>'
			: ( D.canDraw
				? '<span class="whd-studio__modes" role="radiogroup" aria-label="' + esc( title ) + '">' +
					choice( 'auto', T.findDraw ) + choice( 'model', T.onModel ) + choice( 'mannequin', T.mannequin, ! photos.length ) +
				'</span>'
				: '<span class="whd-writer__own-tag is-quiet">' + esc( T.fromLib ) + '</span>' );

		card.innerHTML = '<div class="whd-studio__shot-head">' +
				'<span class="whd-studio__shot-n">' + esc( title ) + '</span>' + how +
			'</div>' +
			( s.own
				? '<div class="whd-writer__own"><img src="' + esc( s.own.thumb ) + '" alt=""><button type="button" class="button-link" data-clear>' + esc( T.clearOwn ) + '</button></div>'
				: ( D.canDraw
					? '<label class="screen-reader-text" for="' + name + '-ask">' + esc( T.askLabel ) + '</label>' +
						'<textarea id="' + name + '-ask" rows="2" class="whd-studio__ask"></textarea>'
					: '' ) +
				'<p class="whd-writer__shot-foot"><button type="button" class="button-link" data-own>' + esc( T.chooseOwn ) + '</button></p>' );

		var ask = card.querySelector( 'textarea' );
		function hint() {
			if ( ! ask ) {
				return;
			}
			var ideas = ( 'mannequin' === s.mode ? T.askGhost : ( 'model' === s.mode ? T.askModel : T.askAuto ) ) || [];
			ask.placeholder = ideas.length ? ideas[ i % ideas.length ] : '';
		}
		if ( ask ) {
			ask.value = s.ask;
			hint();
			ask.addEventListener( 'input', function () {
				s.ask = ask.value;
			} );
		}
		Array.prototype.forEach.call( card.querySelectorAll( 'input[type="radio"]' ), function ( radio ) {
			radio.addEventListener( 'change', function () {
				s.mode = radio.value;
				card.className = 'whd-studio__shot whd-writer__shot is-' + s.mode;
				hint();
			} );
		} );
		var own = card.querySelector( '[data-own]' );
		if ( own ) {
			own.addEventListener( 'click', function () {
				media( { title: T.chooseOwn, button: { text: T.use }, library: { type: 'image' }, multiple: false }, function ( picked ) {
					if ( picked[ 0 ] ) {
						s.own = picked[ 0 ];
						paintShots();
					}
				} );
			} );
		}
		var clear = card.querySelector( '[data-clear]' );
		if ( clear ) {
			clear.addEventListener( 'click', function () {
				s.own = null;
				paintShots();
			} );
		}
		return card;
	}

	/* ─────────────────────────── progress ─────────────────────────── */

	function step( label, hint ) {
		loader.step( label, hint );
		var ended = false;
		var end = function () {
			if ( ! ended ) {
				ended = true;
				loader.tick();
			}
		};
		return {
			note: function ( text ) {
				loader.note( text );
			},
			done: end
		};
	}

	/* ─────────────────────────── writing ─────────────────────────── */

	function paintCheck( c ) {
		if ( ! c ) {
			el.check.innerHTML = '';
			return;
		}
		var bits = [];
		bits.push( '<span class="whd-writer__badge ' + ( c.ok ? 'is-ok' : 'is-warn' ) + '">' +
			( c.ok ? 'Passes every check' : 'Worth a look' ) + '</span>' );
		bits.push( '<span class="whd-writer__stat">' + ( c.words || 0 ) + ' ' + esc( T.words || 'words' ) + '</span>' );

		var missing = [];
		Object.keys( c.keywords || {} ).forEach( function ( k ) {
			if ( ! c.keywords[ k ] ) {
				missing.push( k );
			}
		} );
		if ( missing.length ) {
			bits.push( '<span class="whd-writer__stat is-warn">not in the copy: ' + esc( missing.join( ', ' ) ) + '</span>' );
		}
		( c.issues || [] ).forEach( function ( issue ) {
			bits.push( '<span class="whd-writer__stat is-warn">' + esc( issue.detail || issue ) + '</span>' );
		} );
		el.check.innerHTML = bits.join( '' );
	}

	function countDesc() {
		if ( ! el.descCount ) {
			return;
		}
		var n = el.desc.value.trim().length;
		el.descCount.textContent = n + ' / 155';
		el.descCount.className = 'whd-writer__count' + ( n > 158 || ( n && n < 100 ) ? ' is-warn' : '' );
	}

	el.desc.addEventListener( 'input', countDesc );

	/* What a picture slot's request says about it: its mode, what was asked, and what to draw it from. */
	function slotAsk( shot ) {
		return {
			mode: shot && D.canDraw ? shot.mode : 'auto',
			ask: shot ? shot.ask.trim() : '',
			refs: photos.map( function ( p ) {
				return p.id;
			} ),
			frame: frame
		};
	}

	function hintFor( shot ) {
		var refs = photos.length > 0;
		if ( ! shot || 'auto' === shot.mode ) {
			return shot && shot.ask.trim() && D.canDraw ? T.hintAsked : T.hintAuto;
		}
		if ( 'mannequin' === shot.mode ) {
			return T.hintGhostW;
		}
		return refs ? T.hintModelW : T.hintModelN;
	}

	function taken() {
		return slots.filter( function ( s ) {
			return s.image;
		} ).map( function ( s ) {
			return s.image.id;
		} );
	}

	/* Fill the slots one after another, each as its own request, so a slow drawing never times out the rest. */
	function fillSlots( list ) {
		var todo = list.filter( function ( s ) {
			return ! s.image;
		} );
		var n = 0;
		return todo.reduce( function ( chain, slot ) {
			return chain.then( function () {
				n++;
				var line = step( fmt( T.stepPicture, n, todo.length ), hintFor( slot.shot ) );
				var how = slotAsk( slot.shot );
				return api( 'write-image', {
					doc: doc,
					slot: { key: slot.key, subject: slot.subject, heading: slot.heading },
					taken: taken(),
					draw: !! D.canDraw,
					ask: how.ask,
					mode: how.mode,
					refs: how.refs,
					frame: how.frame
				} ).then( function ( r ) {
					slot.image = r;
					if ( r && r.url ) {
						loader.picture( r.url );
					}
					line.done();
				}, function ( e ) {
					slot.error = e.message;
					line.note( fmt( T.pictureFail, e.message ) );
					line.done();
				} );
			} );
		}, Promise.resolve() );
	}

	function run() {
		if ( busy ) {
			return;
		}
		if ( ! el.idea.value.trim() ) {
			say( T.noIdea, true );
			el.idea.focus();
			return;
		}
		busy = true;
		el.go.disabled = true;
		say( '' );
		if ( el.saved ) {
			el.saved.hidden = true;
		}

		// Writing the piece, then a step for every picture that is not one of the shop's own.
		var drawn = shots.slice( 0, count ).filter( function ( s ) {
			return ! s.own;
		} ).length;
		var seconds = { short: 40, medium: 60, long: 90 }[ length ] || 60;
		loader.open( photos[ 0 ] ? ( photos[ 0 ].view || photos[ 0 ].thumb ) : '', 1 + drawn, seconds + 35 * drawn );
		var line = step( T.stepCompose, T.hintCompose );

		api( 'compose', {
			idea: el.idea.value,
			keywords: keywords(),
			notes: el.notes ? el.notes.value : '',
			type: D.type,
			length: length,
			faq: has( 'faq' ),
			parts: parts(),
			pictures: count
		} ).then( function ( r ) {
			line.done();
			doc = r;
			slots = ( r.slots || [] ).slice( 0, count ).map( function ( slot, i ) {
				var shot = shots[ i ] || null;
				return {
					key: slot.key,
					subject: slot.subject,
					heading: slot.heading,
					shot: shot,
					image: shot && shot.own ? {
						id: shot.own.id,
						url: shot.own.view || shot.own.thumb,
						full: shot.own.large || shot.own.view,
						alt: shot.own.alt || '',
						source: 'own'
					} : null
				};
			} );
			return fillSlots( slots );
		} ).then( function () {
			showResult();
			loader.finish( true, T.doneNote, function () {
				el.result.scrollIntoView( { behavior: 'smooth', block: 'start' } );
			} );
		} ).catch( function ( e ) {
			say( ( T.stopped || 'Stopped' ) + ': ' + e.message, true );
			loader.finish( false, e.message );
		} ).then( function () {
			busy = false;
			el.go.disabled = false;
		} );
	}

	function showResult() {
		if ( ! doc ) {
			return;
		}
		el.title.value = doc.title || '';
		el.desc.value = doc.meta_description || '';
		el.descField.hidden = ! has( 'seo' );
		el.body.innerHTML = doc.html || '';
		paintCheck( doc.check );
		countDesc();
		el.images.hidden = ! slots.length;
		var asked = Math.min( count, maxPictures );
		var failed = slots.filter( function ( s ) {
			return s.error && ! s.image;
		} ).map( function ( s ) {
			return ( s.heading || T.top ) + ': ' + s.error;
		} );
		el.slotsStatus.textContent = failed.length
			? failed.join( ' · ' )
			: ( slots.length < asked ? fmt( T.pickSpare, slots.length ) : T.swapAny );
		paintSlots();
		el.result.hidden = false;
		el.go.textContent = T.writeAnother;
	}

	el.go.addEventListener( 'click', run );
	if ( el.again ) {
		el.again.addEventListener( 'click', run );
	}

	/* ─────────────────────────── the pictures in what came back ───────────────────────────
	   One tile per picture, labelled with where it goes. Click it to see it large; "Change" swaps it
	   for one from the library; an empty one is a choice not yet made. */

	function paintSlots() {
		el.slots.innerHTML = '';
		var shown = [];
		slots.forEach( function ( slot, i ) {
			var where = slot.heading || T.top;
			var cell = document.createElement( 'figure' );
			cell.className = 'whd-writer__slot' + ( slot.image ? ' is-filled' : '' );
			var at = -1;
			if ( slot.image ) {
				var tag = 'drawn' === slot.image.source ? T.drawnTag : ( 'own' === slot.image.source ? T.yours : T.fromLibrary );
				at = shown.length;
				shown.push( { src: slot.image.full || slot.image.url, caption: where, state: tag } );
				cell.innerHTML = '<button type="button" class="whd-writer__slot-view" data-view aria-label="' + esc( fmt( T.viewPicture, i + 1 ) ) + '">' +
						'<img src="' + esc( slot.image.url ) + '" alt="">' +
						'<span class="whd-writer__slot-tag">' + esc( tag ) + '</span>' +
					'</button>';
			} else {
				cell.innerHTML = '<button type="button" class="whd-writer__slot-view is-empty" data-choose><span class="whd-writer__slot-empty" aria-hidden="true">+</span><span class="screen-reader-text">' + esc( T.emptySlot ) + '</span></button>';
			}
			// Written in one go before anything listens: adding to innerHTML later would rebuild the
			// button above and drop its listener.
			cell.innerHTML += '<figcaption class="whd-writer__slot-where"><span>' + esc( where ) + '</span>' +
				'<button type="button" class="button-link" data-choose>' + esc( slot.image ? T.change : T.emptySlot ) + '</button></figcaption>';
			var view = cell.querySelector( '[data-view]' );
			if ( view ) {
				view.addEventListener( 'click', function () {
					lightbox.open( shown, at, this );
				} );
			}
			Array.prototype.forEach.call( cell.querySelectorAll( '[data-choose]' ), function ( button ) {
				button.addEventListener( 'click', function () {
					choose( i );
				} );
			} );
			el.slots.appendChild( cell );
		} );

		var filled = taken().length;
		el.slotsCount.textContent = 1 === slots.length ? T.wantOne : fmt( T.wantN, slots.length );
		if ( filled < slots.length && ! el.slotsStatus.textContent ) {
			el.slotsStatus.textContent = T.swapAny;
		}
	}

	/** WordPress's own media window, for the slot that was clicked. */
	function choose( index ) {
		media( { title: T.chooseOwn, button: { text: T.use }, library: { type: 'image' }, multiple: false }, function ( picked ) {
			var p = picked[ 0 ];
			if ( ! p ) {
				return;
			}
			slots[ index ].image = { id: p.id, url: p.view || p.thumb, full: p.large || p.view, alt: p.alt || '', source: 'own' };
			slots[ index ].error = '';
			paintSlots();
		} );
	}

	/* ─────────────────────────── saving ─────────────────────────── */

	function save( postStatus, button ) {
		if ( ! doc ) {
			return;
		}
		button.disabled = true;
		el.saveStatus.textContent = T.saving;

		var payload = JSON.parse( JSON.stringify( doc ) );
		payload.title = el.title.value;
		payload.meta_description = el.desc.value;
		payload.html = el.body.innerHTML;
		payload.parts = parts();
		payload.images = slots.filter( function ( s ) {
			return s.image;
		} ).map( function ( s ) {
			return {
				id: s.image.id,
				full: s.image.full || s.image.url,
				alt: s.image.alt || '',
				heading: s.heading
			};
		} );

		api( 'write-save', {
			doc: payload,
			idea: el.idea.value,
			type: D.type,
			status: postStatus
		} ).then( function ( r ) {
			/* The result box closes on a save, so the confirmation cannot live inside it — the
			   edit link is the one thing worth keeping on screen. */
			el.saveStatus.textContent = '';
			var where = el.saved || el.saveStatus;
			var scored = r.seo && r.seo.score
				? ' <span class="whd-seo-dot whd-seo-dot--' + esc( r.seo.rating ) + '">' + esc( r.seo.score ) + '</span>' +
					' <span class="whd-writer__scored">SEO ' + esc( String( r.seo.label ).toLowerCase() ) + '</span>'
				: '';
			where.innerHTML = '<strong>' + ( 'publish' === postStatus ? 'Published.' : 'Saved as a draft.' ) + '</strong>' + scored + ' ' +
				'<a href="' + esc( r.edit_url ) + '">' + ( 'publish' === postStatus ? 'Edit it' : 'Open it' ) + '</a>' +
				' · <a href="' + esc( r.view_url ) + '" target="_blank" rel="noopener">' +
				( 'publish' === postStatus ? 'View it' : 'Preview it' ) + '</a>';
			where.hidden = false;
			addRow( r, postStatus );
			doc = null;
			slots = [];
			el.result.hidden = true;
			el.idea.value = '';
			el.go.textContent = T.writeIt;
			/* Those pictures belonged to the piece that was just saved. */
			shots.forEach( function ( s ) {
				s.own = null;
			} );
			paintShots();
			where.scrollIntoView( { behavior: 'smooth', block: 'center' } );
		} ).catch( function ( e ) {
			el.saveStatus.textContent = e.message;
		} ).then( function () {
			button.disabled = false;
		} );
	}

	el.publish.addEventListener( 'click', function () {
		save( 'publish', el.publish );
	} );
	el.draft.addEventListener( 'click', function () {
		save( 'draft', el.draft );
	} );

	/** Put what was just saved at the top of the list, so the screen agrees without a reload. */
	function addRow( saved, postStatus ) {
		var table = document.querySelector( '.whd-writer__table tbody' );
		if ( ! table ) {
			window.location.reload();
			return;
		}
		var tr = document.createElement( 'tr' );
		tr.className = 'whd-writer__new';
		tr.innerHTML = '<td class="whd-writer__cell-title"><strong><a href="' + esc( saved.edit_url ) + '">' +
			esc( saved.title ) + '</a></strong></td>' +
			'<td>' + esc( keywords().slice( 0, 4 ).join( ', ' ) ) + '</td>' +
			'<td><span class="whd-pill whd-pill--' + ( 'publish' === postStatus ? 'on' : 'off' ) + '">' +
			( 'publish' === postStatus ? 'Published' : 'Draft' ) + '</span></td>' +
			'<td>' + esc( new Date().toLocaleDateString() ) + '</td>' +
			'<td class="whd-writer__cell-actions"><a class="button button-small" href="' + esc( saved.edit_url ) + '">Edit</a> ' +
			'<a class="button button-small" href="' + esc( saved.view_url ) + '" target="_blank" rel="noopener">View</a></td>';
		table.insertBefore( tr, table.firstChild );
	}

	/* ─────────────────────────── start ─────────────────────────── */

	window.addEventListener( 'beforeunload', function ( e ) {
		if ( busy ) {
			e.preventDefault();
			e.returnValue = '';
		}
	} );

	paintLength();
	paintPhotos();
	paintCounts();
}() );
