/**
 * Write with AI, on the product screen.
 *
 * The panel above the description: choose the photographs, tick what to write, say how many new
 * pictures, press Generate. The work is a chain of requests — look, inspect, write, then a draw and
 * a check for each new picture, then finish — run one after another, so each step shows as it
 * happens and a slow picture never takes the others with it.
 *
 * Whatever has a field on this screen is put into that field here, in the browser, and saved by
 * Update like any other edit. Only the pictures' alt text and the product story, which have no
 * field on this screen, are written by the server.
 */
( function () {
	'use strict';

	var D = window.WHD_AI_STUDIO || {};
	var T = D.i18n || {};
	var root = document.getElementById( 'whd-studio' );
	if ( ! root || ! D.rest ) {
		return;
	}

	var postId = parseInt( D.postId, 10 ) || 0;
	var lookMax = parseInt( D.lookMax, 10 ) || 6;
	var totalMax = parseInt( D.totalMax, 10 ) || 12;

	var photos = ( D.images || [] ).slice(); // [ { id, thumb } ], in the order they are read
	var shots = [];                          // [ { mode, ask } ] — kept when the count changes, so nothing typed is lost
	var count = 0;                           // new pictures asked for
	var countPicked = false;                 // until somebody picks, the count follows the photographs
	var busy = false;
	var active = null;                       // the progress line being worked on

	var el = {
		photos: document.getElementById( 'whd-studio-photos' ),
		photosNote: document.getElementById( 'whd-studio-photos-note' ),
		counts: document.getElementById( 'whd-studio-counts' ),
		shots: document.getElementById( 'whd-studio-shots' ),
		keywords: document.getElementById( 'whd-studio-keywords' ),
		notes: document.getElementById( 'whd-studio-notes' ),
		go: document.getElementById( 'whd-studio-go' ),
		status: document.getElementById( 'whd-studio-status' ),
		progress: document.getElementById( 'whd-studio-progress' ),
		saw: document.getElementById( 'whd-studio-saw' ),
		drawn: document.getElementById( 'whd-studio-drawn' ),
		result: document.getElementById( 'whd-studio-result' ),
		attrs: document.getElementById( 'whd-studio-attrs' )
	};

	/* ─────────────────────────── small things ─────────────────────────── */

	/*
	 * One step of the run. The steps that wait on a model answer at once with a job number and do
	 * the work after the connection has closed — a host that ends quiet requests after a minute
	 * ended "Writing the page" with a bare HTTP 500 — so here a job is waited for, a few seconds at
	 * a time, and the caller gets the step's answer either way.
	 */
	function api( step, body ) {
		return send( step, body ).then( function ( json ) {
			return json && json.job ? wait( json.job ) : json;
		} );
	}

	/* How long a job may run before it is given up on: the slowest step takes about two minutes. */
	var JOB_LIMIT = 8 * 60 * 1000;

	function wait( job ) {
		var started = Date.now();
		var misses = 0;
		return new Promise( function ( resolve, reject ) {
			( function poll() {
				send( 'job', { job: job } ).then( function ( s ) {
					misses = 0;
					if ( 'done' === s.status ) {
						resolve( s.result );
						return;
					}
					if ( 'error' === s.status ) {
						reject( new Error( s.message || T.error ) );
						return;
					}
					if ( Date.now() - started > JOB_LIMIT ) {
						lastWords().then( function ( said ) {
							reject( new Error( T.tooLong + ( said ? ' ' + said : '' ) ) );
						} );
						return;
					}
					window.setTimeout( poll, 2500 );
				}, function ( e ) {
					// One check that did not get through is not the job failing; a run of them is.
					if ( ++misses > 5 ) {
						reject( e );
					} else {
						window.setTimeout( poll, 4000 );
					}
				} );
			}() );
		} );
	}

	function send( step, body ) {
		body.post_id = postId;
		return window.fetch( D.rest.root + step, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': D.rest.nonce },
			body: JSON.stringify( body )
		} ).then( function ( r ) {
			return r.text().then( function ( raw ) {
				var json = null;
				try {
					json = JSON.parse( raw );
				} catch ( e ) {
					/*
					 * A page instead of an answer: the host ended the request — a killed process, a
					 * gateway timeout. The server leaves a note of what it was doing before each step
					 * that could do that; ask for it, so the message says where, not just "500".
					 */
					var plain = ( T.error || 'Something went wrong' ) + ' (HTTP ' + r.status + ')';
					return lastWords().then( function ( said ) {
						throw new Error( said ? plain + '. ' + said : plain );
					} );
				}
				if ( ! r.ok ) {
					throw new Error( ( json && json.message ) || ( T.error || 'Something went wrong' ) );
				}
				return json;
			} );
		} );
	}

	/* What the server was doing when a request died, if it left a note; '' when it did not. */
	function lastWords() {
		return window.fetch( D.rest.root + 'last', {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': D.rest.nonce },
			body: '{}'
		} ).then( function ( r ) {
			return r.ok ? r.json() : {};
		} ).then( function ( j ) {
			return ( j && j.said ) || '';
		} ).catch( function () {
			return '';
		} );
	}

	function esc( text ) {
		var d = document.createElement( 'div' );
		d.textContent = text == null ? '' : String( text );
		return d.innerHTML;
	}

	/* sprintf for the two shapes the strings use: %d / %s, and %1$d / %2$d. */
	function fmt( text ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var i = 0;
		return String( text || '' ).replace( /%(\d+)\$[sd]|%[sd]/g, function ( m, n ) {
			return String( n ? args[ n - 1 ] : args[ i++ ] );
		} );
	}

	function say( text, bad ) {
		el.status.textContent = text || '';
		el.status.className = 'whd-studio__status' + ( bad ? ' is-bad' : '' );
	}

	function fire( node, type ) {
		if ( ! node ) {
			return;
		}
		if ( window.jQuery ) {
			window.jQuery( node ).trigger( type );
		} else {
			node.dispatchEvent( new Event( type, { bubbles: true } ) );
		}
	}

	/* ─────────────────────────── 1. the photographs ─────────────────────────── */

	function paintPhotos() {
		el.photos.innerHTML = '';
		photos.forEach( function ( p, i ) {
			var fig = document.createElement( 'figure' );
			fig.className = 'whd-studio__photo';
			fig.innerHTML = '<img src="' + esc( p.thumb ) + '" alt="">' +
				'<span class="whd-studio__photo-n" aria-hidden="true">' + ( i + 1 ) + '</span>' +
				'<button type="button" class="whd-studio__photo-x" aria-label="' + esc( T.remove ) + '"></button>';
			fig.querySelector( 'button' ).addEventListener( 'click', function () {
				photos.splice( i, 1 );
				changedPhotos();
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
			? fmt( T.some, photos.length ) + ( photos.length > lookMax ? ' ' + fmt( T.tooMany, lookMax ) : '' )
			: T.none;
	}

	function pick() {
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
			var selection = frame.state().get( 'selection' );
			photos.forEach( function ( p ) {
				var a = wp.media.attachment( p.id );
				a.fetch();
				selection.add( a );
			} );
		} );
		frame.on( 'select', function () {
			photos = frame.state().get( 'selection' ).map( function ( m ) {
				var a = m.toJSON();
				var size = a.sizes && ( a.sizes.thumbnail || a.sizes.medium );
				var view = a.sizes && ( a.sizes.medium || a.sizes.large );
				return { id: a.id, thumb: size ? size.url : a.url, view: view ? view.url : a.url };
			} ).slice( 0, totalMax );
			changedPhotos();
		} );
		frame.open();
	}

	function changedPhotos() {
		paintPhotos();
		paintCounts();
	}

	/* ─────────────────────────── 3. how many new pictures ─────────────────────────── */

	/*
	 * The choices that leave an even number in all. The story lays copy and picture out in rows that
	 * swap sides, so an odd total leaves the last row with nothing opposite it: three photographs
	 * offer 1, 3 or 5 (four, six or eight in all), four offer 2, 4 or 6.
	 */
	function options() {
		var n = photos.length;
		var out = [];
		if ( ! n ) {
			return out;
		}
		for ( var k = n % 2 ? 1 : 2; out.length < 3 && n + k <= totalMax; k += 2 ) {
			out.push( k );
		}
		return out;
	}

	function paintCounts() {
		if ( ! el.counts ) {
			return;
		}
		var opts = options();
		if ( ! countPicked ) {
			count = D.draw && opts.length ? opts[ 0 ] : 0;
		} else if ( count && opts.indexOf( count ) === -1 ) {
			// The photographs changed under the choice: keep as close to it as still balances.
			count = opts.reduce( function ( best, k ) {
				return Math.abs( k - count ) < Math.abs( best - count ) ? k : best;
			}, opts.length ? opts[ 0 ] : 0 );
		}

		el.counts.innerHTML = '';
		[ 0 ].concat( opts ).forEach( function ( k ) {
			var label = document.createElement( 'label' );
			label.className = 'whd-studio__count' + ( k === count ? ' is-on' : '' );
			label.innerHTML = '<input type="radio" name="whd-studio-count" form="whd-studio-none" value="' + k + '"' + ( k === count ? ' checked' : '' ) + '>' +
				'<span class="whd-studio__count-n">' + ( k ? '+' + k : esc( T.noNew ) ) + '</span>' +
				( k ? '<span class="whd-studio__count-all">' + esc( fmt( T.inAll, photos.length + k ) ) + '</span>' : '' );
			label.querySelector( 'input' ).addEventListener( 'change', function () {
				count = k;
				countPicked = true;
				paintCounts();
			} );
			el.counts.appendChild( label );
		} );
		paintShots();
	}

	function paintShots() {
		if ( ! el.shots ) {
			return;
		}
		while ( shots.length < count ) {
			shots.push( { mode: 'model', ask: '' } );
		}
		el.shots.innerHTML = '';
		for ( var i = 0; i < count; i++ ) {
			el.shots.appendChild( shotCard( i ) );
		}
		if ( count ) {
			var hint = document.createElement( 'p' );
			hint.className = 'description whd-studio__shots-note';
			hint.textContent = T.leftBlank;
			el.shots.appendChild( hint );
		}
		el.shots.hidden = ! count;
	}

	function shotCard( i ) {
		var s = shots[ i ];
		var card = document.createElement( 'div' );
		card.className = 'whd-studio__shot is-' + s.mode;
		var name = 'whd-studio-mode-' + i;

		function choice( mode, label ) {
			return '<label class="whd-studio__mode">' +
				'<input type="radio" form="whd-studio-none" name="' + name + '" value="' + mode + '"' + ( s.mode === mode ? ' checked' : '' ) + '>' +
				'<span>' + esc( label ) + '</span></label>';
		}

		card.innerHTML = '<div class="whd-studio__shot-head">' +
				'<span class="whd-studio__shot-n">' + esc( fmt( T.picture, photos.length + i + 1 ) ) + '</span>' +
				'<span class="whd-studio__modes" role="radiogroup" aria-label="' + esc( fmt( T.picture, photos.length + i + 1 ) ) + '">' +
					choice( 'model', T.onModel ) + choice( 'mannequin', T.mannequin ) +
				'</span>' +
			'</div>' +
			'<label class="screen-reader-text" for="whd-studio-ask-' + i + '">' + esc( T.askLabel ) + '</label>' +
			'<textarea id="whd-studio-ask-' + i + '" rows="2" class="whd-studio__ask"></textarea>';

		var ask = card.querySelector( 'textarea' );
		function hint() {
			var list = ( 'mannequin' === s.mode ? T.askGhost : T.askModel ) || [];
			ask.placeholder = list.length ? list[ i % list.length ] : '';
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

	function parts() {
		return Array.prototype.filter.call( root.querySelectorAll( '[data-part]' ), function ( box ) {
			return box.checked;
		} ).map( function ( box ) {
			return box.getAttribute( 'data-part' );
		} );
	}

	Array.prototype.forEach.call( root.querySelectorAll( '[data-parts]' ), function ( button ) {
		button.addEventListener( 'click', function () {
			var on = 'all' === button.getAttribute( 'data-parts' );
			Array.prototype.forEach.call( root.querySelectorAll( '[data-part]' ), function ( box ) {
				box.checked = on;
			} );
		} );
	} );

	function keywords() {
		return window.WHDKeywords && el.keywords ? window.WHDKeywords.chosen( el.keywords ) : [];
	}

	/* ─────────────────────────── what the screen holds ─────────────────────────── */

	function editorHtml( id ) {
		var editor = window.tinymce && tinymce.get( id );
		if ( editor && ! editor.isHidden() ) {
			return editor.getContent();
		}
		var field = document.getElementById( id );
		return field ? field.value : '';
	}

	function current() {
		var title = document.getElementById( 'title' );
		return {
			title: title ? title.value : '',
			short: editorHtml( 'excerpt' ),
			description: editorHtml( 'content' )
		};
	}

	/* The fields this run would replace that already have something in them. */
	function filled( chosen ) {
		var now = current();
		var out = [];
		var has = function ( part ) {
			return chosen.indexOf( part ) !== -1;
		};
		var text = function ( html ) {
			return String( html || '' ).replace( /<[^>]*>/g, '' ).trim();
		};
		if ( has( 'name' ) && now.title.trim() ) {
			out.push( T.fName );
		}
		if ( has( 'description' ) && text( now.description ) ) {
			out.push( T.fDesc );
		}
		if ( has( 'short' ) && text( now.short ) ) {
			out.push( T.fShort );
		}
		if ( has( 'story' ) && D.hasStory ) {
			out.push( T.fStory );
		}
		var ticked = Array.prototype.filter.call( document.querySelectorAll( '#product_catchecklist input:checked' ), function ( box ) {
			return parseInt( box.value, 10 ) !== parseInt( D.defaultCat, 10 );
		} );
		if ( has( 'categories' ) && ticked.length ) {
			out.push( T.fCats );
		}
		var chart = document.querySelector( 'select[name="whd_size_chart"]' );
		if ( has( 'size_chart' ) && chart && '0' !== chart.value ) {
			out.push( T.fChart );
		}
		var seoTitle = document.getElementById( 'whd-seo-title' );
		var seoDesc = document.getElementById( 'whd-seo-desc' );
		if ( has( 'seo' ) && ( ( seoTitle && seoTitle.value.trim() ) || ( seoDesc && seoDesc.value.trim() ) ) ) {
			out.push( T.fSeo );
		}
		return out;
	}

	/* ─────────────────────────── putting things in their fields ─────────────────────────── */

	function setTitle( text ) {
		var field = document.getElementById( 'title' );
		if ( ! field ) {
			return false;
		}
		field.value = text;
		field.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		var prompt = document.getElementById( 'title-prompt-text' );
		if ( prompt ) {
			prompt.classList.add( 'screen-reader-text' );
		}
		return true;
	}

	function setSlug( slug ) {
		var field = document.getElementById( 'post_name' );
		if ( field && D.canSlug && slug ) {
			field.value = slug;
		}
	}

	function intoEditor( id, html ) {
		var editor = window.tinymce && tinymce.get( id );
		var field = document.getElementById( id );
		if ( editor && ! editor.isHidden() ) {
			editor.setContent( html );
			editor.fire( 'change' );
			editor.save();
			return true;
		}
		if ( field ) {
			field.value = html;
			field.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			if ( editor ) {
				editor.setContent( html );
			}
			return true;
		}
		return false;
	}

	function setCategories( ids ) {
		var boxes = document.querySelectorAll( '#product_catchecklist input[type="checkbox"], #product_catchecklist-pop input[type="checkbox"]' );
		if ( ! boxes.length ) {
			return false;
		}
		Array.prototype.forEach.call( boxes, function ( box ) {
			box.checked = ids.indexOf( parseInt( box.value, 10 ) ) !== -1;
		} );
		return true;
	}

	function setChart( id ) {
		var select = document.querySelector( 'select[name="whd_size_chart"]' );
		if ( ! select ) {
			return false;
		}
		select.value = String( id || 0 );
		fire( select, 'change' );
		return true;
	}

	function setSeo( seo ) {
		var title = document.getElementById( 'whd-seo-title' );
		var desc = document.getElementById( 'whd-seo-desc' );
		var picker = document.getElementById( 'whd-seo-keyphrase' );
		var hidden = document.getElementById( 'whd-seo-keyphrase-input' );
		if ( ! title && ! desc && ! hidden ) {
			return false;
		}
		if ( title ) {
			title.value = seo.title || '';
			title.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		}
		if ( desc ) {
			desc.value = seo.description || '';
			desc.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		}
		if ( seo.keyphrase ) {
			// The picker holds one phrase, so adding replaces; its event keeps the hidden field in step.
			if ( picker && window.WHDKeywords ) {
				window.WHDKeywords.add( picker, seo.keyphrase );
			}
			if ( hidden ) {
				hidden.value = seo.keyphrase;
			}
		}
		return true;
	}

	function setPrice( price ) {
		var done = false;
		[ [ 'regular', '_regular_price' ], [ 'sale', '_sale_price' ], [ 'sku', '_sku' ] ].forEach( function ( pair ) {
			var field = document.getElementById( pair[ 1 ] );
			if ( field && price[ pair[ 0 ] ] !== undefined ) {
				field.value = price[ pair[ 0 ] ];
				fire( field, 'change' );
				done = true;
			}
		} );
		if ( price.stock !== undefined ) {
			var manage = document.getElementById( '_manage_stock' );
			if ( manage && ! manage.checked ) {
				manage.checked = true;
				fire( manage, 'change' );
			}
			var stock = document.getElementById( '_stock' );
			if ( stock ) {
				stock.value = price.stock;
				done = true;
			}
		}
		return done;
	}

	function loadTiers( state ) {
		if ( ! window.WHDV_App ) {
			return false;
		}
		window.WHDV_App.load( state );
		return true;
	}

	/** Put every value the write step returned into its field; say where each one went. */
	function place( s ) {
		var out = [];
		if ( s.title ) {
			out.push( { label: T.fName, ok: setTitle( s.title ), box: 'titlediv', detail: s.title } );
			setSlug( s.slug );
		}
		if ( s.description ) {
			out.push( { label: T.fDesc, ok: intoEditor( 'content', s.description ), box: 'postdivrich' } );
		}
		if ( s.short ) {
			out.push( { label: T.fShort, ok: intoEditor( 'excerpt', s.short ), box: 'postexcerpt' } );
		}
		if ( s.categories ) {
			out.push( { label: T.fCats, ok: setCategories( s.categories ), box: 'product_catdiv', detail: ( s.category_names || [] ).join( ', ' ) } );
		}
		if ( Object.prototype.hasOwnProperty.call( s, 'size_chart' ) ) {
			out.push( {
				label: s.size_chart ? T.fChart : T.fChartNone,
				ok: setChart( s.size_chart ),
				box: 'whd-size-chart',
				detail: s.size_chart_name || ''
			} );
		}
		if ( s.seo ) {
			out.push( { label: T.fSeo, ok: setSeo( s.seo ), box: 'whd-seo-box', detail: s.seo.keyphrase } );
		}
		var data = [];
		if ( s.attributes && Object.keys( s.attributes ).length ) {
			el.attrs.value = JSON.stringify( s.attributes );
			Object.keys( s.attributes ).forEach( function ( k ) {
				data.push( k + ': ' + s.attributes[ k ] );
			} );
		}
		if ( s.price && Object.keys( s.price ).length && ! s.tiers && setPrice( s.price ) ) {
			Object.keys( s.price ).forEach( function ( k ) {
				data.push( k + ' ' + s.price[ k ] );
			} );
		}
		if ( data.length ) {
			out.push( { label: T.fData, ok: true, box: 'woocommerce-product-data', detail: data.join( ' · ' ) } );
		}
		if ( s.tiers ) {
			out.push( {
				label: T.fVariants,
				ok: loadTiers( s.tiers ),
				box: 'woocommerce-product-data',
				detail: ( s.tier_labels || [] ).join( ' → ' ) + ' · ' + s.variations
			} );
		}
		return out;
	}

	/* ─────────────────────────── the gallery ─────────────────────────── */

	/**
	 * Make pictures the product's: the first becomes the main image when there is none, the rest go
	 * on the end of the gallery. Only the screen is changed; Update saves it, and nothing already
	 * there is moved or removed.
	 */
	function intoGallery( list ) {
		var featured = document.getElementById( '_thumbnail_id' );
		var hidden = document.getElementById( 'product_image_gallery' );
		var ul = document.querySelector( '#product_images_container ul.product_images' );
		var main = featured ? parseInt( featured.value, 10 ) || 0 : 0;
		var gallery = hidden && hidden.value ? hidden.value.split( ',' ).map( Number ).filter( Boolean ) : [];

		list.forEach( function ( p ) {
			if ( p.id === main || gallery.indexOf( p.id ) !== -1 ) {
				return;
			}
			if ( main <= 0 && window.wp && wp.media && wp.media.featuredImage ) {
				wp.media.featuredImage.set( p.id );
				main = p.id;
				return;
			}
			if ( ! hidden || ! ul ) {
				return;
			}
			gallery.push( p.id );
			var li = document.createElement( 'li' );
			li.className = 'image';
			li.setAttribute( 'data-attachment_id', p.id );
			li.innerHTML = '<img src="' + esc( p.thumb ) + '" alt="">' +
				'<ul class="actions"><li><a href="#" class="delete tips" data-tip="' + esc( T.deleteImage ) + '">' + esc( T.deleteImage ) + '</a></li></ul>';
			var add = ul.querySelector( '.add_product_images' );
			if ( add ) {
				ul.insertBefore( li, add );
			} else {
				ul.appendChild( li );
			}
		} );
		if ( hidden ) {
			hidden.value = gallery.join( ',' );
		}
	}

	/* ─────────────────────────── the loader ───────────────────────────
	   A run takes minutes, so while it works the page is covered by a modal that says what is
	   happening: the first photograph in a viewfinder with a scan line passing over it, the step and
	   its detail, a bar, the time so far, and each new picture as it lands. It closes itself when the
	   run is done, and stays open with the reason and a Close button when the run stops. */

	var loader = ( function () {
		var dialog = null;
		var clock = null;
		var started = 0;
		var done = 0;
		var total = 1;
		var estimate = 0;

		function part( sel ) {
			return dialog ? dialog.querySelector( sel ) : null;
		}

		function minutes( s ) {
			var m = Math.floor( s / 60 );
			var r = Math.floor( s % 60 );
			return m + ':' + ( r < 10 ? '0' : '' ) + r;
		}

		function paint() {
			var pct = Math.max( 4, Math.min( 100, Math.round( 100 * done / Math.max( 1, total ) ) ) );
			var bar = part( '.whd-studio-loader__bar' );
			if ( bar ) {
				bar.firstChild.style.width = pct + '%';
				bar.setAttribute( 'aria-valuenow', String( pct ) );
			}
		}

		function tock() {
			var time = part( '.whd-studio-loader__time' );
			if ( ! time ) {
				return;
			}
			var spent = ( Date.now() - started ) / 1000;
			time.textContent = fmt( T.loaderSoFar, minutes( spent ) ) +
				( estimate ? ' · ' + fmt( T.loaderUsually, Math.max( 1, Math.round( estimate / 60 ) ), Math.round( estimate / 60 ) + 1 ) : '' );
		}

		function close( after ) {
			if ( ! dialog ) {
				return;
			}
			var d = dialog;
			window.clearInterval( clock );
			d.classList.add( 'is-leaving' );
			window.setTimeout( function () {
				if ( d.open ) {
					d.close();
				}
				d.remove();
				if ( after ) {
					after();
				}
			}, 260 );
			dialog = null;
		}

		return {
			/* photo: the picture for the viewfinder; units: how many steps make the bar full;
			   seconds: what a run like this usually takes, for the estimate. */
			open: function ( photo, units, seconds ) {
				if ( dialog ) {
					close();
				}
				done = 0;
				total = Math.max( 1, units );
				estimate = seconds || 0;
				started = Date.now();
				dialog = document.createElement( 'dialog' );
				dialog.className = 'whd-studio-loader';
				dialog.setAttribute( 'aria-labelledby', 'whd-studio-loader-h' );
				dialog.innerHTML =
					'<div class="whd-studio-loader__frame">' +
						( photo ? '<img class="whd-studio-loader__img" src="' + esc( photo ) + '" alt="">' : '' ) +
						'<span class="whd-studio-loader__scan" aria-hidden="true"></span>' +
						'<span class="whd-studio-loader__corner is-tl" aria-hidden="true"></span>' +
						'<span class="whd-studio-loader__corner is-tr" aria-hidden="true"></span>' +
						'<span class="whd-studio-loader__corner is-bl" aria-hidden="true"></span>' +
						'<span class="whd-studio-loader__corner is-br" aria-hidden="true"></span>' +
						'<span class="whd-studio-loader__badge" aria-hidden="true"></span>' +
					'</div>' +
					// Focus lands here, not on Hide: a ring round Hide the moment it opens reads as "press me".
					'<h2 class="whd-studio-loader__h" id="whd-studio-loader-h" aria-live="polite" tabindex="-1" autofocus></h2>' +
					'<p class="whd-studio-loader__note"></p>' +
					'<div class="whd-studio-loader__bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-label="' + esc( T.loaderProgress ) + '"><span></span></div>' +
					'<p class="whd-studio-loader__time"></p>' +
					'<div class="whd-studio-loader__pics"></div>';
				/*
				 * Nothing closes it while the run works — not Escape, and no "Hide" link either: a
				 * page that can be scrolled and edited under a run in progress is a page whose fields
				 * get typed into while the run is about to fill them. It closes itself when the run is
				 * done, and gives a Close button only once the run has stopped.
				 */
				dialog.addEventListener( 'cancel', function ( e ) {
					e.preventDefault();
				} );
				document.body.appendChild( dialog );
				dialog.showModal();
				paint();
				tock();
				clock = window.setInterval( tock, 1000 );
			},

			/* hint: a line under the step saying what it is doing, until a note replaces it. */
			step: function ( label, hint ) {
				var h = part( '.whd-studio-loader__h' );
				var n = part( '.whd-studio-loader__note' );
				if ( h ) {
					h.textContent = label;
				}
				if ( n ) {
					n.textContent = hint || '';
				}
			},

			note: function ( text ) {
				var n = part( '.whd-studio-loader__note' );
				if ( n ) {
					n.textContent = text || '';
				}
			},

			/* One step finished; or more steps than were counted (a repair). */
			tick: function () {
				done++;
				paint();
			},
			more: function ( n ) {
				total += n;
				paint();
			},

			picture: function ( url ) {
				var pics = part( '.whd-studio-loader__pics' );
				if ( pics && url ) {
					var img = document.createElement( 'img' );
					img.src = url;
					img.alt = '';
					pics.appendChild( img );
				}
			},

			/* ok: a tick, then gone. Not ok: it stays, with the reason and a Close. */
			finish: function ( ok, message, after ) {
				if ( ! dialog ) {
					if ( after ) {
						after();
					}
					return;
				}
				window.clearInterval( clock );
				tock();
				done = total;
				paint();
				dialog.classList.add( ok ? 'is-done' : 'is-bad' );
				this.step( ok ? T.loaderDone : T.stopped );
				this.note( ok ? T.loaderDoneNote : message );
				if ( ! dialog.open ) {
					dialog.showModal();
				}
				if ( ok ) {
					window.setTimeout( function () {
						close( after );
					}, 1500 );
				} else {
					var foot = document.createElement( 'p' );
					foot.className = 'whd-studio-loader__foot';
					foot.innerHTML = '<button type="button" class="button">' + esc( T.loaderClose ) + '</button>';
					foot.firstChild.addEventListener( 'click', function () {
						close();
					} );
					dialog.appendChild( foot );
					foot.firstChild.focus();
					if ( after ) {
						after();
					}
				}
			}
		};
	}() );

	/* ─────────────────────────── progress ─────────────────────────── */

	function step( label, hint ) {
		var li = document.createElement( 'li' );
		li.className = 'whd-studio__s is-busy';
		li.innerHTML = '<span class="whd-studio__s-mark" aria-hidden="true"></span>' +
			'<span class="whd-studio__s-label">' + esc( label ) + '</span>' +
			'<span class="whd-studio__s-note"></span>';
		el.progress.appendChild( li );
		el.progress.hidden = false;
		loader.step( label, hint );
		var note = li.querySelector( '.whd-studio__s-note' );
		var ended = false;
		// The loader's bar moves once per line, however the line ends.
		var end = function ( state, text ) {
			li.className = 'whd-studio__s ' + state;
			note.textContent = text ? ' — ' + text : '';
			if ( ! ended ) {
				ended = true;
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
		active = line;
		return line;
	}

	/* What the AI saw, so a miscount is caught by a person before it goes anywhere. */
	function paintSaw( facts ) {
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
		html += '<p class="description">' + esc( T.sawNote ) + '</p>';
		el.saw.innerHTML = html;
		el.saw.hidden = false;
	}

	function tile( position ) {
		var fig = document.createElement( 'figure' );
		fig.className = 'whd-studio__tile is-busy';
		fig.innerHTML = '<div class="whd-studio__tile-img"><span class="spinner is-active"></span></div>' +
			'<figcaption><span class="whd-studio__tile-n">' + esc( fmt( T.picture, position ) ) + '</span><span class="whd-studio__tile-state"></span></figcaption>';
		el.drawn.querySelector( '.whd-studio__tiles' ).appendChild( fig );
		var state = fig.querySelector( '.whd-studio__tile-state' );
		return {
			show: function ( pic, verdict ) {
				fig.querySelector( '.whd-studio__tile-img' ).innerHTML = '<a href="' + esc( pic.large || pic.url ) + '" target="_blank" rel="noopener"><img src="' + esc( pic.url ) + '" alt=""></a>';
				var good = verdict.ok && ! verdict.unchecked;
				fig.className = 'whd-studio__tile ' + ( good ? 'is-ok' : 'is-warn' );
				state.textContent = good ? T.matches : T.lookAgain;
				var notes = ( verdict.problems || [] ).slice();
				if ( verdict.unchecked ) {
					notes.push( verdict.unchecked );
				}
				if ( notes.length ) {
					var ul = document.createElement( 'ul' );
					ul.className = 'whd-studio__tile-notes';
					notes.forEach( function ( n ) {
						var li = document.createElement( 'li' );
						li.textContent = n;
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

	/* ─────────────────────────── drawing ─────────────────────────── */

	function drawAll( ctx ) {
		var plan = ctx.draft.shots || [];
		if ( ! plan.length || ! D.draw ) {
			return Promise.resolve();
		}
		el.drawn.innerHTML = '<h4 class="whd-studio__sub">' + esc( T.drawnHead ) + '</h4><div class="whd-studio__tiles"></div>';
		el.drawn.hidden = false;

		return plan.reduce( function ( chain, shot, j ) {
			return chain.then( function () {
				return drawOne( ctx, shot, j, plan.length );
			} );
		}, Promise.resolve() );
	}

	/*
	 * Draw, check, and draw once more with the mistakes named if the check found any. The better of
	 * the two is kept and the other deleted; a picture that still disagrees with the real piece is
	 * kept too, but marked, because a person can fix a stray button faster than a third attempt can.
	 */
	function drawOne( ctx, shot, j, total ) {
		var position = ctx.ids.length + j + 1;
		var line = step( fmt( T.stepDraw, j + 1, total ), 'mannequin' === shot.mode ? T.hintGhost : T.hintModel );
		var spot = tile( position );
		var body = { images: ctx.ids, facts: ctx.facts, shot: shot, position: position, name: ctx.draft.name };
		var best = null;

		function check( pic ) {
			return api( 'check', { image: pic.id, images: ctx.ids, facts: ctx.facts, shot: shot } ).catch( function ( e ) {
				return { ok: false, score: 0, problems: [], unchecked: e.message };
			} );
		}

		return api( 'draw', body ).then( function ( pic ) {
			line.note( T.stepCheck );
			return check( pic ).then( function ( verdict ) {
				best = { pic: pic, verdict: verdict };
				if ( verdict.ok || ! ( verdict.problems || [] ).length ) {
					return null;
				}
				line.note( T.stepRedraw );
				return api( 'draw', Object.assign( {}, body, { fixes: verdict.problems } ) ).then( function ( again ) {
					line.note( T.stepCheck );
					return check( again ).then( function ( second ) {
						var better = second.ok ||
							( ! second.unchecked && ( second.score > verdict.score ||
								( second.score === verdict.score && ( second.problems || [] ).length < verdict.problems.length ) ) );
						var loser = better ? pic : again;
						if ( better ) {
							best = { pic: again, verdict: second };
						}
						api( 'discard', { image: loser.id } ).catch( function () {} );
					} );
				}, function () {
					return null; // the second attempt failed outright: the first one stands
				} );
			} );
		} ).then( function () {
			ctx.finals.push( { id: best.pic.id, slot: ctx.ids.length + j } );
			intoGallery( [ { id: best.pic.id, thumb: best.pic.thumb || best.pic.url } ] );
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

	/* ─────────────────────────── the run ─────────────────────────── */

	/*
	 * Generate. When the run would replace something already on the product, ask first — and offer
	 * to write into a copy instead, so trying the AI on a finished product never costs the finished
	 * product. `skipAsk` is for the copy's own screen, where replacing is the point.
	 */
	function run( skipAsk ) {
		if ( busy ) {
			return;
		}
		var chosen = parts();
		var notes = el.notes ? el.notes.value.trim() : '';
		if ( ! photos.length ) {
			say( T.needPhoto, true );
			return;
		}
		if ( ! chosen.length && ! count ) {
			say( T.needWork, true );
			return;
		}
		var replacing = filled( chosen );
		var existing = window.WHDV_App && window.WHDV_App.existing ? window.WHDV_App.existing() : 0;
		if ( chosen.indexOf( 'product_data' ) !== -1 && notes && existing ) {
			replacing.push( fmt( T.modalVars, existing ) );
		}
		if ( ! replacing.length || skipAsk ) {
			start( chosen, notes );
			return;
		}
		ask( replacing ).then( function ( choice ) {
			if ( 'overwrite' === choice ) {
				start( chosen, notes );
			} else if ( 'copy' === choice ) {
				intoCopy( chosen, notes );
			}
		} );
	}

	/**
	 * The question, as a modal: write into a copy, overwrite, or neither.
	 *
	 * A <dialog> on the body rather than in the panel: the panel sits inside the product's own form,
	 * and a form inside a form is no form at all. Escape and Cancel both answer "neither".
	 *
	 * @return {Promise<string>} 'copy', 'overwrite' or ''.
	 */
	function ask( replacing ) {
		return new Promise( function ( resolve ) {
			var dialog = document.createElement( 'dialog' );
			dialog.className = 'whd-studio-modal';
			dialog.setAttribute( 'aria-labelledby', 'whd-studio-modal-h' );
			dialog.setAttribute( 'aria-describedby', 'whd-studio-modal-lead' );
			dialog.innerHTML = '<h2 class="whd-studio-modal__h" id="whd-studio-modal-h">' + esc( T.modalTitle ) + '</h2>' +
				'<p class="whd-studio-modal__lead" id="whd-studio-modal-lead">' + esc( T.modalLead ) + '</p>' +
				'<ul class="whd-studio-modal__list">' + replacing.map( function ( r ) {
					return '<li>' + esc( r ) + '</li>';
				} ).join( '' ) + '</ul>' +
				'<div class="whd-studio-modal__choices">' +
					'<button type="button" class="whd-studio-modal__choice is-safe" data-choice="copy"' + ( D.canCopy ? '' : ' disabled' ) + '>' +
						'<span class="whd-studio-modal__choice-t">' + esc( T.copyTitle ) + '</span>' +
						'<span class="whd-studio-modal__choice-d">' + esc( D.canCopy ? T.copyNote : T.copyOff ) + '</span>' +
					'</button>' +
					'<button type="button" class="whd-studio-modal__choice" data-choice="overwrite">' +
						'<span class="whd-studio-modal__choice-t">' + esc( T.overTitle ) + '</span>' +
						'<span class="whd-studio-modal__choice-d">' + esc( T.overNote ) + '</span>' +
					'</button>' +
				'</div>' +
				'<p class="whd-studio-modal__foot"><button type="button" class="button" data-choice="">' + esc( T.cancel ) + '</button></p>';

			Array.prototype.forEach.call( dialog.querySelectorAll( '[data-choice]' ), function ( button ) {
				button.addEventListener( 'click', function () {
					dialog.close( button.getAttribute( 'data-choice' ) );
				} );
			} );
			// A click on the dimmed page around the box is a Cancel too.
			dialog.addEventListener( 'click', function ( e ) {
				if ( e.target === dialog ) {
					dialog.close( '' );
				}
			} );
			dialog.addEventListener( 'close', function () {
				var choice = dialog.returnValue || '';
				dialog.remove();
				el.go.focus();
				resolve( choice );
			} );

			document.body.appendChild( dialog );
			dialog.showModal();
		} );
	}

	/* Duplicate the product with this run waiting on it, and go there; the copy's screen starts it. */
	function intoCopy( chosen, notes ) {
		el.go.disabled = true;
		say( T.copying );
		// Up until the copy's screen replaces this one, where the same loader opens again for the run.
		loader.open( photos[ 0 ] ? ( photos[ 0 ].view || photos[ 0 ].thumb ) : '', 2, 0 );
		loader.step( T.copying, T.hintCopy );
		api( 'copy', {
			images: photos.map( function ( p ) {
				return p.id;
			} ),
			parts: chosen,
			keywords: keywords(),
			notes: notes,
			count: count,
			shots: shots.slice( 0, count )
		} ).then( function ( made ) {
			say( T.copied );
			loader.tick();
			loader.step( T.copied );
			window.location.href = made.edit;
		} ).catch( function ( e ) {
			el.go.disabled = false;
			say( e.message, true );
			loader.finish( false, e.message );
		} );
	}

	/* Arriving on a copy made to write into: put the run's settings back on the panel, and start it. */
	function resume( a ) {
		photos = ( a.photos || [] ).length ? a.photos : photos;
		Array.prototype.forEach.call( root.querySelectorAll( '[data-part]' ), function ( box ) {
			box.checked = ( a.parts || [] ).indexOf( box.getAttribute( 'data-part' ) ) !== -1;
		} );
		if ( el.notes ) {
			el.notes.value = a.notes || '';
		}
		if ( window.WHDKeywords && el.keywords && ( a.keywords || [] ).length ) {
			window.WHDKeywords.add( el.keywords, a.keywords );
		}
		shots = ( a.shots || [] ).map( function ( s ) {
			return { mode: 'mannequin' === s.mode ? 'mannequin' : 'model', ask: s.ask || '' };
		} );
		count = D.draw ? parseInt( a.count, 10 ) || 0 : 0;
		countPicked = true;
		changedPhotos();

		var note = document.createElement( 'div' );
		note.className = 'whd-studio__copied';
		note.textContent = fmt( T.isCopy, a.fromName || '' );
		root.insertBefore( note, root.firstChild );

		// Off the address bar, so a reload is a reload and not a second run.
		if ( window.history && window.history.replaceState ) {
			window.history.replaceState( null, '', window.location.href.replace( /([?&])whd_studio=run(&|$)/, '$1' ).replace( /[?&]$/, '' ) );
		}
		var box = document.getElementById( 'whd-ai-studio' );
		if ( box && box.scrollIntoView ) {
			box.scrollIntoView( { block: 'start' } );
		}
		run( true );
	}

	function start( chosen, notes ) {
		busy = true;
		el.go.disabled = true;
		say( '' );
		[ el.progress, el.saw, el.drawn, el.result ].forEach( function ( node ) {
			node.innerHTML = '';
			node.hidden = true;
		} );
		root.classList.add( 'is-running' );
		/* Look, zoom, write, one per new picture, finish. The estimate is what the test runs took:
		   about two and a half minutes for the words, and a minute more for each picture. */
		loader.open( photos[ 0 ] ? ( photos[ 0 ].view || photos[ 0 ].thumb ) : '', 4 + count, 150 + 55 * count );

		var ctx = {
			ids: photos.map( function ( p ) {
				return p.id;
			} ),
			parts: chosen,
			finals: [],
			placed: []
		};
		ctx.finals = ctx.ids.map( function ( id, i ) {
			return { id: id, slot: i };
		} );
		var want = shots.slice( 0, count ).map( function ( s ) {
			return { mode: s.mode, ask: s.ask.trim() };
		} );

		var asked = null;
		var line = step( T.stepLook, T.hintLook );
		api( 'look', { images: ctx.ids, commands: notes } ).then( function ( facts ) {
			line.done( fmt( T.looked, Math.min( ctx.ids.length, lookMax ) ) );
			line = step( T.stepZoom, T.hintZoom );
			/*
			 * The close-ups are a second opinion. If they cannot be had — a host that kills the request,
			 * a model that times out — the first look's facts are still good facts, so the run goes on
			 * with those rather than stopping; the line says what happened.
			 */
			return api( 'inspect', { facts: facts, commands: notes } ).catch( function ( e ) {
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
			paintSaw( facts );
			line = step( T.stepWrite, T.hintWrite );
			asked = {
				facts: facts,
				parts: chosen,
				keywords: keywords(),
				commands: notes,
				images: ctx.ids,
				shots: want,
				current: current()
			};
			return api( 'write', asked );
		} ).then( function ( draft ) {
			line.done( draft.model );
			if ( ! draft.repair ) {
				return draft;
			}
			// A draft that failed a check goes back once, as a request of its own.
			loader.more( 1 );
			line = step( fmt( T.stepRepair, draft.issues.length ), T.hintRepair );
			return api( 'repair', Object.assign( {}, asked, { raw: draft.raw } ) ).then( function ( fixed ) {
				line.done( ( fixed.repaired || [] ).length ? fmt( T.fixedN, fixed.repaired.length ) : T.keptFirst );
				return fixed;
			}, function ( e ) {
				line.warn( e.message );
				return draft; // the first draft stands; its faults are listed with the result
			} );
		} ).then( function ( draft ) {
			ctx.draft = draft;
			ctx.placed = place( draft.screen || {} );
			// The photographs this was all written from are the product's own now, if they were not already.
			intoGallery( photos );
			return drawAll( ctx );
		} ).then( function () {
			line = step( T.stepFinish, T.hintFinish );
			return api( 'finish', {
				parts: chosen,
				images: ctx.finals,
				draft: {
					name: ctx.draft.name,
					images: ctx.draft.images,
					story: ctx.draft.story || [],
					used: ctx.draft.used || []
				}
			} );
		} ).then( function ( finished ) {
			line.done();
			ctx.finished = finished;
			if ( finished.story ) {
				D.hasStory = true;
			}
			paintResult( ctx );
			say( T.done );
			// The score box reads the screen; give the editors a moment to settle first.
			window.setTimeout( function () {
				var refresh = document.getElementById( 'whd-seo-refresh' );
				if ( refresh ) {
					refresh.click();
				}
			}, 400 );
			// Out of the way, and the eye taken to what was filled in.
			loader.finish( true, '', function () {
				if ( el.result.scrollIntoView ) {
					el.result.scrollIntoView( { behavior: 'smooth', block: 'center' } );
				}
			} );
		} ).catch( function ( e ) {
			if ( active ) {
				active.fail( e.message );
			}
			say( ( T.stopped || 'Stopped' ) + ': ' + e.message, true );
			loader.finish( false, e.message );
			if ( ctx.placed.length ) {
				paintResult( ctx );
			}
		} ).then( function () {
			busy = false;
			el.go.disabled = false;
			el.go.textContent = T.again;
			root.classList.remove( 'is-running' );
		} );
	}

	function paintResult( ctx ) {
		var rows = ( ctx.placed || [] ).slice();
		var draft = ctx.draft || {};
		var finished = ctx.finished || {};
		if ( finished.story ) {
			rows.push( { label: T.fStory, ok: true, box: 'whd-product-story', detail: fmt( T.sections, finished.story ) } );
		}
		if ( finished.dressed ) {
			rows.push( { label: T.fAlt, ok: true, box: 'postimagediv', detail: fmt( T.pictures, finished.dressed ) } );
		}

		var html = '<h4 class="whd-studio__sub">' + esc( T.resultHead ) + '</h4><ul class="whd-studio__placed">';
		rows.forEach( function ( r ) {
			html += '<li class="' + ( r.ok ? 'is-ok' : 'is-bad' ) + '">' +
				'<span class="whd-studio__placed-mark" aria-hidden="true">' + ( r.ok ? '&#10003;' : '!' ) + '</span>' +
				'<a href="#' + esc( r.box ) + '" class="whd-ready__jump">' + esc( r.label ) + '</a>' +
				( r.detail ? ' <span class="whd-studio__placed-detail">' + esc( r.detail ) + '</span>' : '' ) +
				( r.ok ? '' : ' <span class="whd-studio__placed-detail">' + esc( T.notPlaced ) + '</span>' ) +
				'</li>';
		} );
		html += '</ul>';

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
		if ( ( draft.issues || [] ).length ) {
			html += '<p class="whd-studio__saw-label">' + esc( T.stillWrong ) + '</p><ul class="whd-studio__issues">' +
				draft.issues.map( function ( i ) {
					return '<li>' + esc( i ) + '</li>';
				} ).join( '' ) + '</ul>';
		}
		el.result.innerHTML = html;
		el.result.hidden = false;
	}

	/* ─────────────────────────── start ─────────────────────────── */

	el.go.addEventListener( 'click', function () {
		run( false );
	} );

	// Typing Enter in the panel must never submit the product form.
	root.addEventListener( 'keydown', function ( e ) {
		if ( 'Enter' === e.key && 'INPUT' === e.target.tagName && 'text' === e.target.type && ! e.target.closest( '.whd-kw' ) ) {
			e.preventDefault();
		}
	} );

	/* Suggestions are offered, not chosen: a chosen keyword has to appear word for word in the copy. */
	var suggest = document.getElementById( 'whd-studio-suggest' );
	if ( suggest && window.WHDKeywords && el.keywords && ( D.suggested || [] ).length ) {
		D.suggested.forEach( function ( word ) {
			var chip = document.createElement( 'button' );
			chip.type = 'button';
			chip.className = 'whd-ai-suggest__chip';
			chip.textContent = word;
			chip.addEventListener( 'click', function () {
				window.WHDKeywords.add( el.keywords, word );
				chip.remove();
			} );
			suggest.appendChild( chip );
		} );
		suggest.hidden = false;
	}

	changedPhotos();

	// A copy made to write into: start once the editors on this screen are up, since the run reads them.
	if ( D.autorun ) {
		if ( 'complete' === document.readyState ) {
			resume( D.autorun );
		} else {
			window.addEventListener( 'load', function () {
				resume( D.autorun );
			} );
		}
	}
}() );
