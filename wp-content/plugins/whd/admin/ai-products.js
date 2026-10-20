/**
 * Bulk products: one card per piece, each one the Write with AI panel from the product screen.
 *
 * A card asks what the panel asks — the photographs, what to write, keywords, anything to
 * specify, how many new pictures and of what — plus the details somebody already knows. Each piece
 * then runs the panel's own chain of steps against the panel's own endpoints: look, zoom, write
 * (and a repair when the checks ask for one), a draw and a check for every new picture. Two steps
 * are this screen's: `create` first, because the steps work on a product and there is none yet,
 * and `save` last, because there is no product screen whose fields and Update would keep the
 * result — the server writes it into the draft instead.
 *
 * Pieces run one after another, so the person watching sees which one is being worked on, a slow
 * one never takes the others with it, and one that stops never stops the rest.
 */
( function () {
	'use strict';

	var D = window.WHD_AIP || {};
	var T = D.i18n || {};
	var Kit = window.WHDAIKit;
	var list = document.getElementById( 'whd-aip-rows' );
	var tally = document.getElementById( 'whd-aip-progress' );
	var runAllButton = document.getElementById( 'whd-aip-run' );
	if ( ! list || ! Kit || ! D.studio ) {
		return;
	}

	var esc = Kit.esc;
	var fmt = Kit.fmt;
	var lookMax = parseInt( D.lookMax, 10 ) || 6;
	var totalMax = parseInt( D.totalMax, 10 ) || 12;

	/* The panel's steps, and this screen's two, through the same client: jobs waited for, a dead
	   request reported with what the server was doing when it died. */
	var studio = Kit.client( { root: D.studio, nonce: D.rest.nonce, last: D.studio + 'last', strings: T } );
	var products = Kit.client( { root: D.product, nonce: D.rest.nonce, last: D.studio + 'last', strings: T } );
	var lightbox = Kit.lightbox( T );
	var loader = Kit.loader( T );

	var pieces = [];
	var seq = 0;
	var busy = false;

	/* ─────────────────────────── one piece ─────────────────────────── */

	function makePiece( first ) {
		var n = ++seq;
		var p = {
			n: n,
			photos: first ? [ first ] : [],
			shots: [],        // [ { mode, ask } ] — kept when the count changes, so nothing typed is lost
			want: 0,          // the number of new pictures somebody picked
			picked: false,    // until somebody picks, the count follows the photographs
			count: 0,         // what that comes to with these photographs
			postId: 0,        // the draft, once one is made; generating again carries on with it
			editUrl: '',
			done: false,
			made: '',         // the name it was created under
			active: null,     // the progress line being worked on
			units: 0,
			ticked: 0
		};

		var card = document.createElement( 'article' );
		card.className = 'whd-aip__card';
		card.innerHTML = cardHtml( n );
		list.appendChild( card );
		p.card = card;

		var q = function ( sel ) {
			return card.querySelector( sel );
		};
		p.el = {
			face: q( '[data-face]' ),
			title: q( '[data-title]' ),
			meta: q( '[data-meta]' ),
			badge: q( '[data-badge]' ),
			fold: q( '[data-fold]' ),
			body: q( '[data-body]' ),
			photos: q( '[data-photos]' ),
			photosNote: q( '[data-photos-note]' ),
			counts: q( '[data-counts]' ),
			shots: q( '[data-shots]' ),
			keywords: q( '[data-keywords]' ),
			notes: q( '[data-notes]' ),
			go: q( '[data-go]' ),
			status: q( '[data-status]' ),
			out: q( '[data-out]' ),
			result: q( '[data-result]' ),
			drawn: q( '[data-drawn]' ),
			log: q( '[data-log]' ),
			progress: q( '[data-progress]' ),
			saw: q( '[data-saw]' )
		};

		if ( window.WHDKeywords && p.el.keywords ) {
			window.WHDKeywords.mount( p.el.keywords );
		}

		q( '[data-remove]' ).addEventListener( 'click', function () {
			if ( busy ) {
				return;
			}
			pieces.splice( pieces.indexOf( p ), 1 );
			card.remove();
			paintTally();
		} );
		p.el.fold.addEventListener( 'click', function () {
			fold( p, ! card.classList.contains( 'is-folded' ) );
		} );
		Array.prototype.forEach.call( card.querySelectorAll( '[data-parts]' ), function ( button ) {
			button.addEventListener( 'click', function () {
				var on = 'all' === button.getAttribute( 'data-parts' );
				Array.prototype.forEach.call( card.querySelectorAll( '[data-part]' ), function ( box ) {
					box.checked = on;
				} );
			} );
		} );
		q( '[data-copy-all]' ).addEventListener( 'click', function () {
			copyToAll( p );
		} );
		q( '[data-f="name"]' ).addEventListener( 'input', function () {
			paintHead( p );
		} );
		p.el.go.addEventListener( 'click', function () {
			if ( ! busy ) {
				runBatch( [ p ], false );
			}
		} );

		pieces.push( p );
		changedPhotos( p );
		return p;
	}

	function cardHtml( n ) {
		var parts = ( D.parts || [] ).map( function ( part ) {
			return '<label class="whd-studio__part">' +
				'<input type="checkbox" data-part="' + esc( part.key ) + '" checked>' +
				'<span class="whd-studio__part-name">' + esc( part.name ) + '</span>' +
				'<span class="whd-studio__part-note">' + esc( part.note ) + '</span>' +
			'</label>';
		} ).join( '' );

		var cats = '<option value="">' + esc( T.pickedForMe ) + '</option>' + ( D.categories || [] ).map( function ( c ) {
			return '<option value="' + c.id + '">' + esc( c.name ) + '</option>';
		} ).join( '' );

		var field = function ( label, key, attrs, wide ) {
			return '<label class="whd-aip__f' + ( wide ? ' is-wide' : '' ) + '">' +
				'<span class="whd-aip__f-label">' + esc( label ) + '</span>' +
				( 'category' === key
					? '<select class="whd-aip__in" data-f="category">' + cats + '</select>'
					: '<input type="text" class="whd-aip__in" data-f="' + key + '"' + ( attrs || '' ) + '>' ) +
			'</label>';
		};

		var more = D.draw
			? '<p class="whd-studio__label" id="whd-aip-count-label-' + n + '">' + esc( T.countLabel ) + '</p>' +
				'<div class="whd-studio__counts" data-counts role="radiogroup" aria-labelledby="whd-aip-count-label-' + n + '"></div>' +
				'<p class="description">' + esc( T.countNote ) + '</p>' +
				'<div class="whd-studio__shots" data-shots hidden></div>'
			: '<p class="description">' + esc( T.noDraw ) + '</p>';

		return '' +
			'<header class="whd-aip__head">' +
				'<span class="whd-aip__n" aria-hidden="true"></span>' +
				'<span class="whd-aip__face" data-face aria-hidden="true"></span>' +
				'<div class="whd-aip__title">' +
					'<h2 class="whd-aip__name" data-title>' + esc( T.untitled ) + '</h2>' +
					'<span class="whd-aip__meta" data-meta></span>' +
				'</div>' +
				'<span class="whd-aip__badge" data-badge></span>' +
				'<button type="button" class="whd-aip__fold" data-fold aria-expanded="true" aria-controls="whd-aip-body-' + n + '" aria-label="' + esc( T.fold ) + '"></button>' +
				'<button type="button" class="whd-aip__x" data-remove aria-label="' + esc( T.removePiece ) + '"></button>' +
			'</header>' +

			'<div class="whd-aip__body whd-studio" id="whd-aip-body-' + n + '" data-body>' +
				'<div class="whd-aip__top">' +
					'<section class="whd-aip__sec whd-aip__sec--photos">' +
						'<h3 class="whd-studio__h"><span class="whd-studio__n" aria-hidden="true">1</span>' + esc( T.secPhotos ) + '</h3>' +
						'<div class="whd-studio__photos" data-photos></div>' +
						'<p class="description" data-photos-note></p>' +
					'</section>' +

					'<section class="whd-aip__sec whd-aip__sec--write">' +
						'<h3 class="whd-studio__h"><span class="whd-studio__n" aria-hidden="true">2</span>' + esc( T.secWrite ) + '</h3>' +
						'<div class="whd-studio__parts whd-aip__parts" role="group" aria-label="' + esc( T.secWrite ) + '">' + parts + '</div>' +
						'<p class="whd-studio__quick">' +
							'<button type="button" class="button-link" data-parts="all">' + esc( T.tickAll ) + '</button>' +
							'<span aria-hidden="true"> · </span>' +
							'<button type="button" class="button-link" data-parts="none">' + esc( T.untickAll ) + '</button>' +
							'<span aria-hidden="true"> · </span>' +
							'<button type="button" class="button-link" data-copy-all>' + esc( T.copyAll ) + '</button>' +
						'</p>' +
						'<div class="whd-aip__pair">' +
							'<div class="whd-studio__field">' +
								'<span class="whd-studio__label">' + esc( T.kwLabel ) + '</span>' +
								'<div class="whd-kw" data-keywords data-placeholder="' + esc( T.kwPlaceholder ) + '"></div>' +
								'<p class="description">' + esc( T.kwNote ) + '</p>' +
							'</div>' +
							'<div class="whd-studio__field">' +
								'<label class="whd-studio__label" for="whd-aip-notes-' + n + '">' + esc( T.notesLabel ) + '</label>' +
								'<textarea id="whd-aip-notes-' + n + '" rows="3" class="whd-studio__notes" data-notes placeholder="' + esc( T.notesHint ) + '"></textarea>' +
								'<p class="description">' + esc( T.notesNote ) + '</p>' +
							'</div>' +
						'</div>' +
					'</section>' +
				'</div>' +

				'<section class="whd-aip__sec whd-aip__sec--more">' +
					'<h3 class="whd-studio__h"><span class="whd-studio__n" aria-hidden="true">3</span>' + esc( T.secMore ) + '</h3>' +
					more +
				'</section>' +

				'<details class="whd-aip__known">' +
					'<summary><span class="whd-aip__known-t">' + esc( T.known ) + '</span> <span class="whd-aip__known-n">' + esc( T.knownNote ) + '</span></summary>' +
					'<div class="whd-aip__grid">' +
						field( T.fName, 'name', ' placeholder="' + esc( T.fromPhotos ) + '"', true ) +
						field( T.fPrice + ( D.currency ? ' (' + D.currency + ')' : '' ), 'price', ' inputmode="decimal"' ) +
						field( T.fSale, 'sale', ' inputmode="decimal"' ) +
						field( T.fStock, 'stock', ' inputmode="numeric"' ) +
						field( T.fSku, 'sku', '' ) +
						field( T.fCat, 'category', '', true ) +
					'</div>' +
				'</details>' +

				'<div class="whd-aip__go">' +
					'<button type="button" class="button" data-go>' + esc( T.goOne ) + '</button>' +
					'<span class="whd-studio__status" data-status role="status" aria-live="polite"></span>' +
				'</div>' +
			'</div>' +

			'<div class="whd-aip__out whd-studio" data-out hidden>' +
				'<div class="whd-aip__result" data-result hidden></div>' +
				// The new pictures sit with the steps: in view while they are drawn, folded away with the
				// steps once the result above shows the finished set.
				'<details class="whd-aip__log" data-log>' +
					'<summary>' + esc( T.logHead ) + '</summary>' +
					'<ol class="whd-studio__progress" data-progress></ol>' +
					'<div class="whd-studio__drawn" data-drawn hidden></div>' +
					'<div class="whd-studio__saw" data-saw hidden></div>' +
				'</details>' +
			'</div>';
	}

	/* ─────────────────────────── its head ─────────────────────────── */

	var BADGES = {
		needs: 'stateNeeds',
		ready: 'stateReady',
		waiting: 'stateWaiting',
		working: 'stateWorking',
		done: 'stateDone',
		failed: 'stateFailed'
	};

	function details( p ) {
		var out = {};
		Array.prototype.forEach.call( p.card.querySelectorAll( '[data-f]' ), function ( input ) {
			out[ input.getAttribute( 'data-f' ) ] = input.value.trim();
		} );
		return out;
	}

	function paintHead( p ) {
		var typed = details( p ).name;
		p.el.title.textContent = p.made || typed || T.untitled;
		p.el.face.innerHTML = p.photos[ 0 ] ? '<img src="' + esc( p.photos[ 0 ].thumb ) + '" alt="">' : '';
		p.el.meta.textContent = p.photos.length
			? fmt( T.photosN, p.photos.length ) + ( p.count ? ' · ' + fmt( T.newN, p.count ) : '' )
			: '';
	}

	function setState( p, state ) {
		p.state = state;
		p.card.className = 'whd-aip__card is-' + state + ( p.card.classList.contains( 'is-folded' ) ? ' is-folded' : '' );
		p.el.badge.textContent = T[ BADGES[ state ] ] || '';
		p.el.badge.className = 'whd-aip__badge is-' + state;
	}

	/* Waiting for photographs, or ready to go — unless it is already somewhere further along. */
	function settle( p ) {
		if ( [ 'waiting', 'working', 'done' ].indexOf( p.state ) === -1 ) {
			setState( p, p.photos.length ? ( 'failed' === p.state ? 'failed' : 'ready' ) : 'needs' );
		}
	}

	function fold( p, shut ) {
		p.card.classList.toggle( 'is-folded', shut );
		p.el.body.hidden = shut;
		p.el.fold.setAttribute( 'aria-expanded', shut ? 'false' : 'true' );
		p.el.fold.setAttribute( 'aria-label', shut ? T.unfold : T.fold );
	}

	function say( p, text, bad ) {
		p.el.status.textContent = text || '';
		p.el.status.className = 'whd-studio__status' + ( bad ? ' is-bad' : '' );
	}

	/* ─────────────────────────── 1. the photographs ─────────────────────────── */

	function photoOf( m ) {
		var a = m.toJSON ? m.toJSON() : m;
		var size = a.sizes && ( a.sizes.thumbnail || a.sizes.medium );
		var view = a.sizes && ( a.sizes.medium || a.sizes.large );
		var large = a.sizes && ( a.sizes.large || a.sizes.full );
		return { id: a.id, thumb: size ? size.url : a.url, view: view ? view.url : a.url, large: large ? large.url : a.url };
	}

	function paintPhotos( p ) {
		var box = p.el.photos;
		box.innerHTML = '';
		p.photos.forEach( function ( photo, i ) {
			var fig = document.createElement( 'figure' );
			fig.className = 'whd-studio__photo';
			fig.innerHTML = '<button type="button" class="whd-studio__photo-view" aria-label="' + esc( fmt( T.viewPhoto, i + 1 ) ) + '">' +
					'<img src="' + esc( photo.thumb ) + '" alt="">' +
				'</button>' +
				'<span class="whd-studio__photo-n" aria-hidden="true">' + ( i + 1 ) + '</span>' +
				'<button type="button" class="whd-studio__photo-x" aria-label="' + esc( T.remove ) + '"></button>';
			fig.querySelector( '.whd-studio__photo-view' ).addEventListener( 'click', function () {
				lightbox.open( p.photos.map( function ( ph, j ) {
					return { src: ph.large || ph.view || ph.thumb, caption: fmt( T.photoN, j + 1 ) };
				} ), i, this );
			} );
			fig.querySelector( '.whd-studio__photo-x' ).addEventListener( 'click', function () {
				p.photos.splice( i, 1 );
				changedPhotos( p );
			} );
			box.appendChild( fig );
		} );

		var add = document.createElement( 'button' );
		add.type = 'button';
		add.className = 'whd-studio__add';
		add.innerHTML = '<span class="whd-studio__add-plus" aria-hidden="true">+</span><span>' + esc( p.photos.length ? T.change : T.choose ) + '</span>';
		add.addEventListener( 'click', function () {
			pick( p );
		} );
		box.appendChild( add );

		box.classList.toggle( 'is-empty', ! p.photos.length );
		p.el.photosNote.textContent = p.photos.length
			? fmt( T.somePiece, p.photos.length ) + ( p.photos.length > lookMax ? ' ' + fmt( T.tooMany, lookMax ) : '' )
			: T.nonePiece;
	}

	function pick( p ) {
		if ( ! window.wp || ! wp.media ) {
			return;
		}
		var frame = wp.media( {
			title: T.choose,
			button: { text: T.use },
			library: { type: 'image' },
			multiple: 'add'
		} );
		frame.on( 'open', function () {
			/*
			 * Open on the library, not on the upload tab. Asking frame.content.get() first and only
			 * switching when it answers does nothing: before a mode is chosen it is null, which is
			 * exactly the moment the switch is needed.
			 */
			frame.content.mode( 'browse' );
			var selection = frame.state().get( 'selection' );
			p.photos.forEach( function ( photo ) {
				var a = wp.media.attachment( photo.id );
				a.fetch();
				selection.add( a );
			} );
		} );
		frame.on( 'select', function () {
			p.photos = frame.state().get( 'selection' ).map( photoOf ).slice( 0, totalMax );
			changedPhotos( p );
		} );
		frame.open();
	}

	function changedPhotos( p ) {
		paintPhotos( p );
		paintCounts( p );
		paintHead( p );
		settle( p );
		paintTally();
	}

	/* ─────────────────────────── 3. how many new pictures ─────────────────────────── */

	/*
	 * The choices that leave an even number in all. The story lays copy and picture out in rows that
	 * swap sides, so an odd total leaves the last row with nothing opposite it: three photographs
	 * offer 1, 3 or 5 (four, six or eight in all), four offer 2, 4 or 6.
	 */
	function options( p ) {
		var n = p.photos.length;
		var out = [];
		if ( ! n ) {
			return out;
		}
		for ( var k = n % 2 ? 1 : 2; out.length < 3 && n + k <= totalMax; k += 2 ) {
			out.push( k );
		}
		return out;
	}

	function paintCounts( p ) {
		if ( ! p.el.counts ) {
			p.count = 0;
			return;
		}
		var opts = options( p );
		if ( ! p.picked ) {
			p.count = D.draw && opts.length ? opts[ 0 ] : 0;
		} else {
			// The photographs changed under the choice: as close to it as still balances.
			p.count = p.want && opts.length ? opts.reduce( function ( best, k ) {
				return Math.abs( k - p.want ) < Math.abs( best - p.want ) ? k : best;
			}, opts[ 0 ] ) : 0;
		}

		p.el.counts.innerHTML = '';
		[ 0 ].concat( opts ).forEach( function ( k ) {
			var label = document.createElement( 'label' );
			label.className = 'whd-studio__count' + ( k === p.count ? ' is-on' : '' );
			label.innerHTML = '<input type="radio" name="whd-aip-count-' + p.n + '" value="' + k + '"' + ( k === p.count ? ' checked' : '' ) + '>' +
				'<span class="whd-studio__count-n">' + ( k ? '+' + k : esc( T.noNew ) ) + '</span>' +
				( k ? '<span class="whd-studio__count-all">' + esc( fmt( T.inAll, p.photos.length + k ) ) + '</span>' : '' );
			label.querySelector( 'input' ).addEventListener( 'change', function () {
				p.want = k;
				p.picked = true;
				paintCounts( p );
				paintHead( p );
			} );
			p.el.counts.appendChild( label );
		} );
		paintShots( p );
	}

	function paintShots( p ) {
		if ( ! p.el.shots ) {
			return;
		}
		while ( p.shots.length < p.count ) {
			p.shots.push( { mode: 'model', ask: '' } );
		}
		p.el.shots.innerHTML = '';
		for ( var i = 0; i < p.count; i++ ) {
			p.el.shots.appendChild( shotCard( p, i ) );
		}
		if ( p.count ) {
			var hint = document.createElement( 'p' );
			hint.className = 'description whd-studio__shots-note';
			hint.textContent = T.leftBlank;
			p.el.shots.appendChild( hint );
		}
		p.el.shots.hidden = ! p.count;
	}

	function shotCard( p, i ) {
		var s = p.shots[ i ];
		var card = document.createElement( 'div' );
		card.className = 'whd-studio__shot is-' + s.mode;
		var name = 'whd-aip-mode-' + p.n + '-' + i;
		var title = fmt( T.picture, p.photos.length + i + 1 );

		function choice( mode, label ) {
			return '<label class="whd-studio__mode">' +
				'<input type="radio" name="' + name + '" value="' + mode + '"' + ( s.mode === mode ? ' checked' : '' ) + '>' +
				'<span>' + esc( label ) + '</span></label>';
		}

		card.innerHTML = '<div class="whd-studio__shot-head">' +
				'<span class="whd-studio__shot-n">' + esc( title ) + '</span>' +
				'<span class="whd-studio__modes" role="radiogroup" aria-label="' + esc( title ) + '">' +
					choice( 'model', T.onModel ) + choice( 'mannequin', T.mannequin ) +
				'</span>' +
			'</div>' +
			'<label class="screen-reader-text" for="' + name + '-ask">' + esc( T.askLabel ) + '</label>' +
			'<textarea id="' + name + '-ask" rows="2" class="whd-studio__ask"></textarea>';

		var ask = card.querySelector( 'textarea' );
		function hint() {
			var ideas = ( 'mannequin' === s.mode ? T.askGhost : T.askModel ) || [];
			ask.placeholder = ideas.length ? ideas[ i % ideas.length ] : '';
		}
		ask.value = s.ask;
		hint();
		ask.addEventListener( 'input', function () {
			s.ask = ask.value;
		} );
		Array.prototype.forEach.call( card.querySelectorAll( 'input[type="radio"]' ), function ( radio ) {
			radio.addEventListener( 'change', function () {
				s.mode = radio.value;
				card.className = 'whd-studio__shot is-' + s.mode;
				hint();
			} );
		} );

		return card;
	}

	/* ─────────────────────────── 2. what to write ─────────────────────────── */

	function partsOf( p ) {
		return Array.prototype.filter.call( p.card.querySelectorAll( '[data-part]' ), function ( box ) {
			return box.checked;
		} ).map( function ( box ) {
			return box.getAttribute( 'data-part' );
		} );
	}

	function keywordsOf( p ) {
		return window.WHDKeywords && p.el.keywords ? window.WHDKeywords.chosen( p.el.keywords ) : [];
	}

	/* What to write and which pictures to draw, from this card onto every other one not yet made.
	   The notes, keywords and details stay where they are: those are about one piece. */
	function copyToAll( p ) {
		var chosen = partsOf( p );
		var others = pieces.filter( function ( o ) {
			return o !== p && ! o.done;
		} );
		others.forEach( function ( o ) {
			Array.prototype.forEach.call( o.card.querySelectorAll( '[data-part]' ), function ( box ) {
				box.checked = chosen.indexOf( box.getAttribute( 'data-part' ) ) !== -1;
			} );
			o.want = p.count;
			o.picked = true;
			o.shots = p.shots.map( function ( s ) {
				return { mode: s.mode, ask: s.ask };
			} );
			paintCounts( o );
			paintHead( o );
		} );
		say( p, others.length ? fmt( T.copiedAll, others.length ) : T.copiedNone );
	}

	/* ─────────────────────────── progress ─────────────────────────── */

	function step( p, label, hint ) {
		var li = document.createElement( 'li' );
		li.className = 'whd-studio__s is-busy';
		li.innerHTML = '<span class="whd-studio__s-mark" aria-hidden="true"></span>' +
			'<span class="whd-studio__s-label">' + esc( label ) + '</span>' +
			'<span class="whd-studio__s-note"></span>';
		p.el.progress.appendChild( li );
		loader.step( label, hint );
		var note = li.querySelector( '.whd-studio__s-note' );
		var ended = false;
		// The loader's bar moves once per line, however the line ends.
		var end = function ( state, text ) {
			li.className = 'whd-studio__s ' + state;
			note.textContent = text ? ' — ' + text : '';
			if ( ! ended ) {
				ended = true;
				p.ticked++;
				loader.tick();
			}
		};
		var line = {
			note: function ( text ) {
				note.textContent = text ? ' — ' + text : '';
				loader.note( text );
			},
			done: function ( text ) {
				end( 'is-done', text );
			},
			warn: function ( text ) {
				end( 'is-warn', text );
			},
			fail: function ( text ) {
				end( 'is-bad', text );
			}
		};
		p.active = line;
		return line;
	}

	/* What the AI saw, so a miscount is caught by a person before it goes anywhere. */
	function paintSaw( p, facts ) {
		var html = '<h4 class="whd-studio__sub">' + esc( T.saw ) + '</h4>';
		var ways = ( facts.colourways || [] ).length > 1 ? facts.colourways.map( function ( w ) {
			return w.name;
		} ).join( ' / ' ) : facts.colour;
		var what = [ facts.garment, ways, facts.pattern ].filter( Boolean ).join( ' · ' );
		if ( what ) {
			html += '<p class="whd-studio__saw-what">' + esc( what ) + '</p>';
		}
		if ( ( facts.countables || [] ).length ) {
			html += '<p class="whd-studio__saw-label">' + esc( T.counted ) + '</p><ul class="whd-studio__saw-list">';
			facts.countables.forEach( function ( c ) {
				html += '<li><strong class="whd-studio__saw-n">' + ( c.all_visible ? '' : esc( T.atLeast ) + ' ' ) + esc( c.count ) + '</strong> ' + esc( c.item ) +
					( c.where ? ' <span class="whd-studio__saw-where">— ' + esc( c.where ) + '</span>' : '' ) +
					( 'high' !== c.confidence ? ' <em class="whd-studio__saw-conf is-' + esc( c.confidence ) + '">' + esc( c.confidence ) + '</em>' : '' ) +
					( c.checked ? ' <span class="whd-studio__saw-tick" title="' + esc( T.corrected ) + '">&#10003;</span>' : '' ) +
					'</li>';
			} );
			html += '</ul>';
		}
		if ( ( facts.absent || [] ).length ) {
			html += '<p class="whd-studio__saw-label">' + esc( T.without ) + '</p><p class="whd-studio__saw-line">' + esc( facts.absent.join( '; ' ) ) + '</p>';
		}
		if ( ( facts.corrections || [] ).length ) {
			html += '<p class="whd-studio__saw-label">' + esc( T.corrected ) + '</p><ul class="whd-studio__saw-list whd-studio__saw-list--fix">' +
				facts.corrections.map( function ( c ) {
					return '<li>' + esc( c ) + '</li>';
				} ).join( '' ) + '</ul>';
		}
		p.el.saw.innerHTML = html;
		p.el.saw.hidden = false;
	}

	/* ─────────────────────────── drawing ─────────────────────────── */

	function tile( p, position ) {
		var fig = document.createElement( 'figure' );
		fig.className = 'whd-studio__tile is-busy';
		fig.innerHTML = '<div class="whd-studio__tile-img"><span class="spinner is-active"></span></div>' +
			'<figcaption><span class="whd-studio__tile-n">' + esc( fmt( T.picture, position ) ) + '</span><span class="whd-studio__tile-state"></span></figcaption>';
		p.el.drawn.querySelector( '.whd-studio__tiles' ).appendChild( fig );
		var state = fig.querySelector( '.whd-studio__tile-state' );
		return {
			show: function ( pic, verdict ) {
				var good = verdict.ok && ! verdict.unchecked;
				var notes = ( verdict.problems || [] ).slice();
				if ( verdict.unchecked ) {
					notes.push( verdict.unchecked );
				}
				// What the lightbox shows for this one: large, with the check's verdict beside it.
				fig.whdLightbox = {
					src: pic.large || pic.url,
					caption: fmt( T.picture, position ),
					state: good ? T.matches : T.lookAgain,
					tone: good ? 'ok' : 'warn',
					notes: notes
				};
				fig.querySelector( '.whd-studio__tile-img' ).innerHTML = '<button type="button" class="whd-studio__tile-view" aria-label="' + esc( fmt( T.viewPicture, position ) ) + '">' +
					'<img src="' + esc( pic.url ) + '" alt=""></button>';
				fig.querySelector( '.whd-studio__tile-view' ).addEventListener( 'click', function () {
					var figs = Array.prototype.filter.call( p.el.drawn.querySelectorAll( '.whd-studio__tile' ), function ( f ) {
						return !! f.whdLightbox;
					} );
					lightbox.open( figs.map( function ( f ) {
						return f.whdLightbox;
					} ), figs.indexOf( fig ), this );
				} );
				fig.className = 'whd-studio__tile ' + ( good ? 'is-ok' : 'is-warn' );
				state.textContent = good ? T.matches : T.lookAgain;
				if ( notes.length ) {
					var ul = document.createElement( 'ul' );
					ul.className = 'whd-studio__tile-notes';
					notes.forEach( function ( text ) {
						var li = document.createElement( 'li' );
						li.textContent = text;
						ul.appendChild( li );
					} );
					fig.appendChild( ul );
				}
			},
			fail: function ( message ) {
				fig.className = 'whd-studio__tile is-bad';
				fig.querySelector( '.whd-studio__tile-img' ).innerHTML = '<span class="whd-studio__tile-x" aria-hidden="true">&times;</span>';
				state.textContent = T.failedDraw + ': ' + message;
			}
		};
	}

	function drawAll( p, ctx ) {
		var plan = ctx.draft.shots || [];
		if ( ! plan.length || ! D.draw ) {
			return Promise.resolve();
		}
		p.el.drawn.innerHTML = '<h4 class="whd-studio__sub">' + esc( T.drawnHead ) + '</h4><div class="whd-studio__tiles"></div>';
		p.el.drawn.hidden = false;

		return plan.reduce( function ( chain, shot, j ) {
			return chain.then( function () {
				return drawOne( p, ctx, shot, j, plan.length );
			} );
		}, Promise.resolve() );
	}

	/*
	 * Draw, check, and draw once more with the mistakes named if the check found any. The better of
	 * the two is kept and the other deleted; a picture that still disagrees with the real piece is
	 * kept too, but marked, because a person can fix a stray button faster than a third attempt can.
	 */
	function drawOne( p, ctx, shot, j, total ) {
		var position = ctx.ids.length + j + 1;
		var line = step( p, fmt( T.stepDraw, j + 1, total ), 'mannequin' === shot.mode ? T.hintGhost : T.hintModel );
		var spot = tile( p, position );
		var body = { post_id: p.postId, images: ctx.ids, facts: ctx.facts, shot: shot, position: position, name: ctx.draft.name };
		var best = null;

		function check( pic ) {
			return studio.api( 'check', { post_id: p.postId, image: pic.id, images: ctx.ids, facts: ctx.facts, shot: shot } ).catch( function ( e ) {
				return { ok: false, score: 0, problems: [], unchecked: e.message };
			} );
		}

		return studio.api( 'draw', body ).then( function ( pic ) {
			line.note( T.stepCheck );
			return check( pic ).then( function ( verdict ) {
				best = { pic: pic, verdict: verdict };
				if ( verdict.ok || ! ( verdict.problems || [] ).length ) {
					return null;
				}
				line.note( T.stepRedraw );
				return studio.api( 'draw', Object.assign( {}, body, { fixes: verdict.problems } ) ).then( function ( again ) {
					line.note( T.stepCheck );
					return check( again ).then( function ( second ) {
						var better = second.ok ||
							( ! second.unchecked && ( second.score > verdict.score ||
								( second.score === verdict.score && ( second.problems || [] ).length < verdict.problems.length ) ) );
						var loser = better ? pic : again;
						if ( better ) {
							best = { pic: again, verdict: second };
						}
						studio.api( 'discard', { post_id: p.postId, image: loser.id } ).catch( function () {} );
					} );
				}, function () {
					return null; // the second attempt failed outright: the first one stands
				} );
			} );
		} ).then( function () {
			ctx.finals.push( { id: best.pic.id, slot: ctx.ids.length + j } );
			ctx.verdicts[ best.pic.id ] = best.verdict;
			spot.show( best.pic, best.verdict );
			loader.picture( best.pic.thumb || best.pic.url );
			if ( best.verdict.ok && ! best.verdict.unchecked ) {
				line.done( T.matches );
			} else {
				line.warn( T.lookAgain );
			}
		} ).catch( function ( e ) {
			spot.fail( e.message );
			line.fail( e.message );
			// One picture that would not draw is no reason to stop the rest.
		} );
	}

	/* ─────────────────────────── one piece's run ─────────────────────────── */

	/* The work a piece is asking for, or why it cannot start. */
	function planOf( p ) {
		var known = details( p );
		var chosen = partsOf( p );
		if ( ! p.photos.length ) {
			return { error: T.needPhoto };
		}
		if ( ! chosen.length && ! p.count ) {
			return { error: T.needWork };
		}
		/* A name typed in is the name, so the writer is told it rather than asked for one; with none
		   typed, one has to be written whatever is ticked — a product cannot go without. The same
		   for a category picked by hand. */
		var write = chosen.filter( function ( part ) {
			return ! ( 'name' === part && known.name ) && ! ( 'categories' === part && known.category );
		} );
		if ( ! known.name && write.indexOf( 'name' ) === -1 ) {
			write.unshift( 'name' );
		}
		return { known: known, parts: write, notes: p.el.notes.value.trim(), keywords: keywordsOf( p ) };
	}

	function runPiece( p, plan ) {
		var ids = p.photos.map( function ( photo ) {
			return photo.id;
		} );
		var ctx = {
			ids: ids,
			finals: ids.map( function ( id, i ) {
				return { id: id, slot: i };
			} ),
			verdicts: {}
		};
		var want = p.shots.slice( 0, p.count ).map( function ( s ) {
			return { mode: s.mode, ask: s.ask.trim() };
		} );
		var asked = null;

		p.done = false;
		setState( p, 'working' );
		say( p, '' );
		p.el.out.hidden = false;
		p.el.result.hidden = true;
		p.el.result.innerHTML = '';
		p.el.drawn.hidden = true;
		p.el.drawn.innerHTML = '';
		p.el.progress.innerHTML = '';
		p.el.saw.hidden = true;
		p.el.log.open = true;
		p.ticked = 0;

		var line = step( p, T.stepCreate, T.hintCreate );
		return products.api( 'create', { images: ids, name: plan.known.name, post_id: p.postId } ).then( function ( made ) {
			p.postId = made.id;
			p.editUrl = made.edit;
			line.done();
			line = step( p, T.stepLook, T.hintLook );
			return studio.api( 'look', { post_id: p.postId, images: ids, commands: plan.notes } );
		} ).then( function ( facts ) {
			line.done( fmt( T.looked, Math.min( ids.length, lookMax ) ) );
			line = step( p, T.stepZoom, T.hintZoom );
			/* The close-ups are a second opinion. If they cannot be had, the first look's facts are
			   still good facts, so the piece goes on with those; the line says what happened. */
			return studio.api( 'inspect', { post_id: p.postId, facts: facts, commands: plan.notes } ).catch( function ( e ) {
				facts.inspected = -1;
				facts.skipped = e.message;
				return facts;
			} );
		} ).then( function ( facts ) {
			if ( facts.inspected < 0 ) {
				line.warn( fmt( T.zoomSkipped, facts.skipped ) );
			} else {
				line.done( facts.inspected ? fmt( T.zoomed, facts.inspected ) : T.zoomNone );
			}
			ctx.facts = facts;
			paintSaw( p, facts );
			line = step( p, T.stepWrite, T.hintWrite );
			asked = {
				post_id: p.postId,
				facts: facts,
				parts: plan.parts,
				keywords: plan.keywords,
				commands: plan.notes,
				images: ids,
				shots: want,
				// Nothing on the page yet: the name, if one was typed, is all there is to go on.
				current: { title: plan.known.name || '', short: '', description: '' }
			};
			return studio.api( 'write', asked );
		} ).then( function ( draft ) {
			line.done( draft.model );
			if ( ! draft.repair ) {
				return draft;
			}
			// A draft that failed a check goes back once, as a request of its own.
			p.units++;
			loader.more( 1 );
			line = step( p, fmt( T.stepRepair, draft.issues.length ), T.hintRepair );
			return studio.api( 'repair', Object.assign( {}, asked, { raw: draft.raw } ) ).then( function ( fixed ) {
				line.done( ( fixed.repaired || [] ).length ? fmt( T.fixedN, fixed.repaired.length ) : T.keptFirst );
				return fixed;
			}, function ( e ) {
				line.warn( e.message );
				return draft; // the first draft stands; its faults are listed with the result
			} );
		} ).then( function ( draft ) {
			ctx.draft = draft;
			p.made = draft.name || p.made;
			paintHead( p );
			return drawAll( p, ctx );
		} ).then( function () {
			line = step( p, T.stepSave, T.hintSave );
			return products.api( 'save', {
				post_id: p.postId,
				screen: ctx.draft.screen || {},
				details: plan.known,
				parts: plan.parts,
				images: ctx.finals,
				draft: {
					name: ctx.draft.name,
					keyphrase: ctx.draft.keyphrase,
					keywords: ctx.draft.keywords || [],
					images: ctx.draft.images,
					story: ctx.draft.story || [],
					used: ctx.draft.used || []
				}
			} );
		} ).then( function ( saved ) {
			line.done( fmt( T.scoreN, saved.score ) );
			p.done = true;
			p.made = saved.name || p.made;
			p.editUrl = saved.edit || p.editUrl;
			paintResult( p, ctx, saved );
			setState( p, 'done' );
			paintHead( p );
			p.el.go.textContent = T.again;
			p.el.log.open = false;
			fold( p, true );
			return true;
		} ).catch( function ( e ) {
			if ( p.active ) {
				p.active.fail( e.message );
			}
			setState( p, 'failed' );
			fold( p, false );
			say( p, ( T.stopped || 'Stopped' ) + ': ' + e.message + ( p.postId ? ( /[.!?]$/.test( e.message ) ? ' ' : '. ' ) + T.draftKept : '' ), true );
			p.error = e.message;
			return false;
		} ).then( function ( ok ) {
			// A piece that stopped early still fills its share of the bar, so the next one starts level.
			while ( p.ticked < p.units ) {
				p.ticked++;
				loader.tick();
			}
			return ok;
		} );
	}

	function paintResult( p, ctx, saved ) {
		var draft = ctx.draft || {};
		var html = '<div class="whd-aip__result-head">' +
				'<h4 class="whd-studio__sub">' + esc( T.savedHead ) + '</h4>' +
				'<span class="whd-aip__score is-' + esc( saved.rating || 'none' ) + '">' + esc( fmt( T.scoreN, saved.score ) ) + '</span>' +
				( saved.edit ? '<a class="button button-primary whd-aip__open" href="' + esc( saved.edit ) + '" target="_blank" rel="noopener">' + esc( T.openDraft ) + '</a>' : '' ) +
			'</div>';

		if ( ( saved.pictures || [] ).length ) {
			html += '<div class="whd-aip__strip">' + saved.pictures.map( function ( pic, i ) {
				return '<button type="button" class="whd-aip__pic' + ( pic.drawn ? ' is-drawn' : '' ) + '" data-pic="' + i + '" aria-label="' + esc( fmt( T.viewPicture, i + 1 ) ) + '">' +
					'<img src="' + esc( pic.thumb ) + '" alt="">' +
					( pic.drawn ? '<span class="whd-aip__pic-tag">' + esc( T.drawnTag ) + '</span>' : '' ) +
				'</button>';
			} ).join( '' ) + '</div>';
		}

		html += '<ul class="whd-studio__placed">' + ( saved.rows || [] ).map( function ( r ) {
			return '<li class="is-ok"><span class="whd-studio__placed-mark" aria-hidden="true">&#10003;</span>' +
				esc( r.label ) + ( r.detail ? ' <span class="whd-studio__placed-detail">' + esc( r.detail ) + '</span>' : '' ) + '</li>';
		} ).join( '' ) + '</ul>';

		/* What the page is aimed at, and where that came from: the list, the shop's own pick, or —
		   only when the list has nothing for this piece — the product's name. */
		if ( draft.keyphrase ) {
			var from = 'picked' === draft.keyphrase_from ? T.focusPicked
				: ( 'list' === draft.keyphrase_from
					? ( draft.keyphrase_volume ? fmt( T.focusListVol, Number( draft.keyphrase_volume ).toLocaleString() ) : T.focusList )
					: T.focusName );
			html += '<p class="whd-studio__saw-label">' + esc( T.focusHead ) + '</p>' +
				'<p class="whd-studio__focus"><span class="whd-ai-kwtag is-in">' + esc( draft.keyphrase ) + '</span> ' +
				'<span class="whd-studio__placed-detail' + ( 'name' === draft.keyphrase_from ? ' is-warn' : '' ) + '">' + esc( from ) + '</span></p>';
		}
		if ( ( draft.used || [] ).length ) {
			html += '<p class="whd-studio__saw-label">' + esc( T.keywordsIn ) + '</p><p class="whd-studio__chips">' +
				draft.used.map( function ( k ) {
					return '<span class="whd-ai-kwtag is-in">' + esc( k ) + '</span>';
				} ).join( ' ' ) + '</p>';
		}
		if ( ( draft.repaired || [] ).length ) {
			html += '<p class="whd-studio__saw-label">' + esc( T.repaired ) + '</p><ul class="whd-studio__fixed">' +
				draft.repaired.map( function ( i ) {
					return '<li>' + esc( i ) + '</li>';
				} ).join( '' ) + '</ul>';
		}
		var still = ( draft.issues || [] ).concat( saved.notices || [] );
		if ( still.length ) {
			html += '<p class="whd-studio__saw-label">' + esc( T.stillWrong ) + '</p><ul class="whd-studio__issues">' +
				still.map( function ( i ) {
					return '<li>' + esc( i ) + '</li>';
				} ).join( '' ) + '</ul>';
		}

		p.el.result.innerHTML = html;
		p.el.result.hidden = false;

		/* The finished set in the lightbox, a drawn picture with what the check said about it. */
		var set = ( saved.pictures || [] ).map( function ( pic, i ) {
			var verdict = ctx.verdicts[ pic.id ];
			var good = verdict && verdict.ok && ! verdict.unchecked;
			return {
				src: pic.large || pic.thumb,
				caption: fmt( T.picture, i + 1 ) + ( pic.drawn ? ' · ' + T.drawnTag : '' ),
				state: verdict ? ( good ? T.matches : T.lookAgain ) : '',
				tone: verdict ? ( good ? 'ok' : 'warn' ) : '',
				notes: verdict ? ( verdict.problems || [] ) : []
			};
		} );
		Array.prototype.forEach.call( p.el.result.querySelectorAll( '[data-pic]' ), function ( button ) {
			button.addEventListener( 'click', function () {
				lightbox.open( set, parseInt( button.getAttribute( 'data-pic' ), 10 ), button );
			} );
		} );
	}

	/* ─────────────────────────── the batch ─────────────────────────── */

	function ready() {
		return pieces.filter( function ( p ) {
			return ! p.done && p.photos.length;
		} );
	}

	function paintTally() {
		var waiting = ready().length;
		var made = pieces.filter( function ( p ) {
			return p.done;
		} ).length;
		var left = pieces.length - made;
		tally.textContent = [ left ? fmt( T.readyOf, waiting, left ) : '', made ? fmt( T.madeN, made ) : '' ].filter( Boolean ).join( ' · ' );
		runAllButton.textContent = waiting > 1 ? fmt( T.goAllN, waiting ) : T.goAll;
		runAllButton.disabled = busy || ! waiting;
	}

	/* How long a run like this usually takes: the words about two and a half minutes, a minute more a picture. */
	function seconds( todo ) {
		return todo.reduce( function ( sum, p ) {
			return sum + 150 + 55 * p.count;
		}, 0 );
	}

	/**
	 * The question before a batch, as a modal: what is about to be made, roughly how long it takes.
	 *
	 * @return {Promise<boolean>}
	 */
	function ask( todo ) {
		return new Promise( function ( resolve ) {
			var mins = Math.max( 1, Math.round( seconds( todo ) / 60 ) );
			var dialog = document.createElement( 'dialog' );
			dialog.className = 'whd-studio-modal whd-aip-ask';
			dialog.setAttribute( 'aria-labelledby', 'whd-aip-ask-h' );
			dialog.innerHTML = '<h2 class="whd-studio-modal__h" id="whd-aip-ask-h">' + esc( fmt( T.askTitle, todo.length ) ) + '</h2>' +
				'<p class="whd-studio-modal__lead">' + esc( T.askLead ) + '</p>' +
				'<ul class="whd-aip-ask__list">' + todo.map( function ( p ) {
					return '<li>' +
						( p.photos[ 0 ] ? '<img src="' + esc( p.photos[ 0 ].thumb ) + '" alt="">' : '' ) +
						'<span class="whd-aip-ask__name">' + esc( details( p ).name || p.made || ( T.untitled + ' ' + ( pieces.indexOf( p ) + 1 ) ) ) + '</span>' +
						'<span class="whd-aip-ask__what">' + esc( fmt( T.askLine, p.photos.length, p.count ) ) + '</span>' +
					'</li>';
				} ).join( '' ) + '</ul>' +
				'<p class="whd-aip-ask__time">' + esc( fmt( T.askTime, mins, mins + Math.max( 1, Math.round( mins / 4 ) ) ) ) + '</p>' +
				'<p class="whd-studio-modal__foot">' +
					'<button type="button" class="button" data-answer="">' + esc( T.cancel ) + '</button> ' +
					'<button type="button" class="button button-primary" data-answer="go">' + esc( T.askGo ) + '</button>' +
				'</p>';
			Array.prototype.forEach.call( dialog.querySelectorAll( '[data-answer]' ), function ( button ) {
				button.addEventListener( 'click', function () {
					dialog.close( button.getAttribute( 'data-answer' ) );
				} );
			} );
			dialog.addEventListener( 'click', function ( e ) {
				if ( e.target === dialog ) {
					dialog.close( '' );
				}
			} );
			dialog.addEventListener( 'close', function () {
				var go = 'go' === dialog.returnValue;
				dialog.remove();
				resolve( go );
			} );
			document.body.appendChild( dialog );
			dialog.showModal();
			dialog.querySelector( '[data-answer="go"]' ).focus();
		} );
	}

	/*
	 * Run pieces one after another under one loader. Each piece's photograph comes into the
	 * viewfinder as its turn starts, with "Piece 2 of 5" above the step, and the bar runs across
	 * the whole batch. A piece that stops is marked on its card and the batch moves on.
	 */
	function runBatch( todo, confirm ) {
		var plans = [];
		var go = [];
		todo.forEach( function ( p ) {
			var plan = planOf( p );
			if ( plan.error ) {
				say( p, plan.error, true );
				fold( p, false );
			} else {
				plans.push( plan );
				go.push( p );
			}
		} );
		if ( ! go.length ) {
			return;
		}
		( confirm ? ask( go ) : Promise.resolve( true ) ).then( function ( yes ) {
			if ( ! yes ) {
				return;
			}
			busy = true;
			document.body.classList.add( 'whd-aip-busy' );
			paintTally();
			go.forEach( function ( p ) {
				// Create, look, zoom, write, save — and one for each new picture.
				p.units = 5 + p.count;
				setState( p, 'waiting' );
			} );
			loader.open( go[ 0 ].photos[ 0 ].view || go[ 0 ].photos[ 0 ].thumb, go.reduce( function ( sum, p ) {
				return sum + p.units;
			}, 0 ), seconds( go ) );

			var made = 0;
			var last = '';
			go.reduce( function ( chain, p, i ) {
				return chain.then( function () {
					loader.photo( p.photos[ 0 ].view || p.photos[ 0 ].thumb );
					loader.kicker( go.length > 1 ? fmt( T.pieceOf, i + 1, go.length ) + ' · ' + ( details( p ).name || p.made || T.untitled ) : '' );
					return runPiece( p, plans[ i ] ).then( function ( ok ) {
						if ( ok ) {
							made++;
						} else {
							last = p.error || '';
						}
					} );
				} );
			}, Promise.resolve() ).then( function () {
				busy = false;
				document.body.classList.remove( 'whd-aip-busy' );
				paintTally();
				if ( made === go.length ) {
					loader.finish( true, fmt( T.batchDone, made ) );
				} else {
					loader.finish( false, go.length > 1 ? fmt( T.batchSome, made, go.length ) : last );
				}
			} );
		} );
	}

	/* ─────────────────────────── the buttons ─────────────────────────── */

	document.getElementById( 'whd-aip-add' ).addEventListener( 'click', function () {
		var p = makePiece( null );
		p.card.scrollIntoView( { behavior: 'smooth', block: 'start' } );
	} );

	document.getElementById( 'whd-aip-add-many' ).addEventListener( 'click', function () {
		if ( ! window.wp || ! wp.media ) {
			return;
		}
		var frame = wp.media( {
			title: T.choose,
			button: { text: T.use },
			library: { type: 'image' },
			multiple: true
		} );
		frame.on( 'open', function () {
			frame.content.mode( 'browse' );
		} );
		frame.on( 'select', function () {
			// An empty card left over is filled first, so this does not leave a blank one on top.
			var spare = pieces.filter( function ( p ) {
				return ! p.photos.length && ! p.done;
			} );
			frame.state().get( 'selection' ).map( photoOf ).forEach( function ( photo ) {
				var p = spare.shift();
				if ( p ) {
					p.photos = [ photo ];
					changedPhotos( p );
				} else {
					makePiece( photo );
				}
			} );
		} );
		frame.open();
	} );

	runAllButton.addEventListener( 'click', function () {
		if ( busy ) {
			return;
		}
		var todo = ready();
		if ( ! todo.length ) {
			tally.textContent = T.nothingReady;
			return;
		}
		runBatch( todo, true );
	} );

	window.addEventListener( 'beforeunload', function ( e ) {
		if ( busy ) {
			e.preventDefault();
			e.returnValue = T.leave;
			return T.leave;
		}
	} );

	/* Something to fill in on arrival. */
	makePiece( null );
}() );
