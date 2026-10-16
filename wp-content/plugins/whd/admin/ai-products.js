/**
 * Bulk products: a table of rows, each one a photograph waiting to become a product.
 *
 * The work happens a row at a time and in three steps — look, write, make — so the person
 * watching can see which row is being thought about and a slow one never takes the rest with it.
 * Nothing is sent until the button is pressed, and every product it creates is a draft.
 */
( function () {
	'use strict';

	var cfg  = window.WHD_AIP || {};
	var i18n = cfg.i18n || {};
	var rows = document.getElementById( 'whd-aip-rows' );
	var tally = document.getElementById( 'whd-aip-progress' );
	if ( ! rows ) {
		return;
	}

	var seq = 0;
	var busy = false;

	/* ── the API ── */

	function post( step, body ) {
		return window.fetch( cfg.root + step, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			body: JSON.stringify( body )
		} ).then( function ( res ) {
			return res.json().then( function ( data ) {
				if ( ! res.ok ) {
					throw new Error( ( data && data.message ) || ( 'HTTP ' + res.status ) );
				}
				return data;
			} );
		} );
	}

	/* ── a row ── */

	function makeRow( attachment ) {
		var id = ++seq;
		var tr = document.createElement( 'tr' );
		tr.className = 'whd-aip__row';
		tr.dataset.images = '';

		var options = '<option value="">' + '—' + '</option>';
		( cfg.categories || [] ).forEach( function ( c ) {
			options += '<option value="' + c.id + '">' + escape( c.name ) + '</option>';
		} );

		tr.innerHTML =
			'<td class="whd-aip__c-img">' +
				'<div class="whd-aip__thumbs" data-thumbs></div>' +
				'<button type="button" class="button-link whd-aip__pick" data-pick>' + escape( i18n.pickImages || 'Choose' ) + '</button>' +
			'</td>' +
			'<td><input type="text" class="whd-aip__in" data-f="name" placeholder="' + escape( 'worked out from the photo' ) + '"></td>' +
			'<td class="whd-aip__c-num"><input type="text" class="whd-aip__in whd-aip__in--num" data-f="price" placeholder="' + escape( cfg.currency || '' ) + '"></td>' +
			'<td class="whd-aip__c-num"><input type="text" class="whd-aip__in whd-aip__in--num" data-f="sale"></td>' +
			'<td class="whd-aip__c-num"><input type="text" class="whd-aip__in whd-aip__in--num" data-f="stock"></td>' +
			'<td><input type="text" class="whd-aip__in" data-f="sku"></td>' +
			'<td><select class="whd-aip__in" data-f="category">' + options + '</select></td>' +
			'<td><input type="text" class="whd-aip__in" data-f="notes" placeholder="' + escape( 'e.g. runs small, linen blend' ) + '"></td>' +
			'<td class="whd-aip__c-state"><span class="whd-aip__state" data-state></span></td>' +
			'<td class="whd-aip__c-x"><button type="button" class="button-link whd-aip__x" data-remove title="' + escape( i18n.remove || 'Remove' ) + '">&times;</button></td>';

		rows.appendChild( tr );

		tr.querySelector( '[data-pick]' ).addEventListener( 'click', function () {
			pick( tr );
		} );
		tr.querySelector( '[data-remove]' ).addEventListener( 'click', function () {
			tr.parentNode.removeChild( tr );
			count();
		} );

		if ( attachment ) {
			setImages( tr, [ attachment ] );
		}
		count();

		return tr;
	}

	function escape( text ) {
		var d = document.createElement( 'div' );
		d.textContent = text == null ? '' : String( text );
		return d.innerHTML;
	}

	function setImages( tr, list ) {
		var box = tr.querySelector( '[data-thumbs]' );
		box.innerHTML = '';
		var ids = [];
		list.forEach( function ( a ) {
			ids.push( a.id );
			var img = document.createElement( 'img' );
			img.src = a.url;
			img.alt = '';
			img.className = 'whd-aip__thumb';
			box.appendChild( img );
		} );
		tr.dataset.images = ids.join( ',' );
	}

	function imagesOf( tr ) {
		return ( tr.dataset.images || '' ).split( ',' ).filter( Boolean ).map( Number );
	}

	function hintsOf( tr ) {
		var out = {};
		Array.prototype.forEach.call( tr.querySelectorAll( '[data-f]' ), function ( el ) {
			out[ el.dataset.f ] = el.value.trim();
		} );
		var select = tr.querySelector( '[data-f="category"]' );
		if ( select && select.value ) {
			out.category_name = select.options[ select.selectedIndex ].textContent;
		}
		return out;
	}

	function state( tr, text, kind ) {
		var el = tr.querySelector( '[data-state]' );
		el.textContent = text;
		el.className = 'whd-aip__state' + ( kind ? ' is-' + kind : '' );
	}

	/* ── the media library ── */

	function frame( multiple, onPick ) {
		var f = window.wp.media( {
			title: i18n.pickImages || 'Choose',
			button: { text: i18n.useImages || 'Use these' },
			library: { type: 'image' },
			multiple: multiple
		} );
		f.on( 'open', function () {
			/*
			 * Open on the library, not on the upload tab. Asking f.content.get() first and only
			 * switching when it answers does nothing: before a mode is chosen it is null, which
			 * is exactly the moment the switch is needed.
			 */
			f.content.mode( 'browse' );
		} );
		f.on( 'select', function () {
			var picked = f.state().get( 'selection' ).map( function ( m ) {
				var a = m.toJSON();
				var thumb = ( a.sizes && ( a.sizes.thumbnail || a.sizes.medium ) ) || null;
				return { id: a.id, url: thumb ? thumb.url : a.url };
			} );
			onPick( picked );
		} );
		f.open();
	}

	function pick( tr ) {
		frame( true, function ( picked ) {
			setImages( tr, picked );
		} );
	}

	/* ── running them ── */

	function count() {
		var all = rows.querySelectorAll( '.whd-aip__row' ).length;
		var ready = Array.prototype.filter.call( rows.querySelectorAll( '.whd-aip__row' ), function ( tr ) {
			return imagesOf( tr ).length > 0;
		} ).length;
		tally.textContent = all ? ready + ' / ' + all : '';
	}

	function runOne( tr ) {
		var images = imagesOf( tr );
		var hints  = hintsOf( tr );

		if ( ! images.length ) {
			state( tr, i18n.noImages || 'Needs a photograph', 'bad' );
			return Promise.resolve( false );
		}

		state( tr, i18n.looking || 'Looking…', 'busy' );
		return post( 'look', { images: images, hints: hints } )
			.then( function ( facts ) {
				state( tr, i18n.writing || 'Writing…', 'busy' );
				return post( 'write', { facts: facts, hints: hints } );
			} )
			.then( function ( draft ) {
				state( tr, draft.second_shot ? ( i18n.drawing || 'Drawing…' ) : ( i18n.making || 'Creating…' ), 'busy' );
				return post( 'make', { draft: draft, hints: hints } );
			} )
			.then( function ( made ) {
				var el = tr.querySelector( '[data-state]' );
				el.className = 'whd-aip__state is-done';
				el.innerHTML = '';

				var link = document.createElement( 'a' );
				link.href = made.edit;
				link.target = '_blank';
				link.rel = 'noopener';
				link.textContent = made.name;
				el.appendChild( link );

				var note = document.createElement( 'span' );
				note.className = 'whd-aip__score';
				note.textContent = ' · SEO ' + made.score + ' · ' + made.images + ' photo' + ( made.images === 1 ? '' : 's' ) +
					( made.drawn ? ' (' + made.drawn + ' drawn)' : '' );
				el.appendChild( note );

				if ( made.house && made.house.length ) {
					var warn = document.createElement( 'div' );
					warn.className = 'whd-aip__warn';
					warn.textContent = made.house.map( function ( i ) { return i.detail; } ).join( ' ' );
					el.appendChild( warn );
				}

				tr.classList.add( 'is-done' );
				return true;
			} )
			.catch( function ( err ) {
				state( tr, ( i18n.failed || 'Failed' ) + ': ' + err.message, 'bad' );
				return false;
			} );
	}

	function runAll() {
		if ( busy ) {
			return;
		}
		var todo = Array.prototype.filter.call( rows.querySelectorAll( '.whd-aip__row' ), function ( tr ) {
			return ! tr.classList.contains( 'is-done' ) && imagesOf( tr ).length;
		} );
		if ( ! todo.length ) {
			return;
		}
		if ( ! window.confirm( ( i18n.confirmRun || 'Create %d product(s)?' ).replace( '%d', todo.length ) ) ) {
			return;
		}

		busy = true;
		document.getElementById( 'whd-aip-run' ).disabled = true;

		var done = 0;
		todo.reduce( function ( chain, tr ) {
			return chain.then( function () {
				return runOne( tr ).then( function () {
					done++;
					tally.textContent = done + ' / ' + todo.length;
				} );
			} );
		}, Promise.resolve() ).then( function () {
			busy = false;
			document.getElementById( 'whd-aip-run' ).disabled = false;
		} );
	}

	/* ── the buttons ── */

	document.getElementById( 'whd-aip-add' ).addEventListener( 'click', function () {
		makeRow( null );
	} );

	document.getElementById( 'whd-aip-add-many' ).addEventListener( 'click', function () {
		frame( true, function ( picked ) {
			picked.forEach( function ( a ) {
				makeRow( a );
			} );
		} );
	} );

	document.getElementById( 'whd-aip-run' ).addEventListener( 'click', runAll );

	/* Something to type into on arrival. */
	makeRow( null );
	makeRow( null );
	makeRow( null );
}() );
