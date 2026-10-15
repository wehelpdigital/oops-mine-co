/**
 * AI Posts and AI Pages.
 *
 * Two steps and a box you can edit in between: say what it is about, read what came back, change
 * anything you like, then save it. What is saved is what is in the box — the preview is the draft,
 * not a picture of one.
 */
( function () {
	'use strict';

	var D = window.WHD_AI_WRITER || {};
	var T = D.i18n || {};

	var root = document.getElementById( 'whd-writer' );
	if ( ! root ) {
		return;
	}

	var idea = document.getElementById( 'whd-writer-idea' );
	var picker = document.getElementById( 'whd-writer-keywords' );
	var length = document.getElementById( 'whd-writer-length' );
	var faq = document.getElementById( 'whd-writer-faq' );
	var go = document.getElementById( 'whd-writer-go' );
	var status = document.getElementById( 'whd-writer-status' );
	var result = document.getElementById( 'whd-writer-result' );
	var title = document.getElementById( 'whd-writer-title' );
	var desc = document.getElementById( 'whd-writer-desc' );
	var descCount = document.getElementById( 'whd-writer-desc-count' );
	var check = document.getElementById( 'whd-writer-check' );
	var body = document.getElementById( 'whd-writer-body' );
	var publish = document.getElementById( 'whd-writer-publish' );
	var draft = document.getElementById( 'whd-writer-draft' );
	var again = document.getElementById( 'whd-writer-again' );
	var imagesMode = document.getElementById( 'whd-writer-images-mode' );
	var askRow = document.getElementById( 'whd-writer-ask-row' );
	var ask = document.getElementById( 'whd-writer-ask' );
	var slotsNote = document.getElementById( 'whd-writer-images-note' );
	var slotsNoteWas = slotsNote ? slotsNote.textContent : '';
	var chosenBox = document.getElementById( 'whd-writer-chosen' );
	var chosenList = document.getElementById( 'whd-writer-chosen-list' );
	var chosenCount = document.getElementById( 'whd-writer-chosen-count' );
	var chosenAdd = document.getElementById( 'whd-writer-chosen-add' );
	var chosenClear = document.getElementById( 'whd-writer-chosen-clear' );
	var chosenHint = document.getElementById( 'whd-writer-chosen-hint' );
	var picked = [];
	var saveStatus = document.getElementById( 'whd-writer-save-status' );
	var savedBox = document.getElementById( 'whd-writer-saved' );

	var doc = null;

	function api( path, body ) {
		return fetch( D.rest.root + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': D.rest.nonce },
			body: JSON.stringify( body )
		} ).then( function ( r ) {
			return r.json().then( function ( json ) {
				if ( ! r.ok ) {
					throw new Error( ( json && json.message ) || T.error );
				}
				return json;
			} );
		} );
	}

	/* ─── keywords ─── */

	function keywords() {
		return window.WHDKeywords ? window.WHDKeywords.chosen( picker ) : [];
	}

	/* ─── pictures chosen before there is anything to put them in ───
	   Saying you will choose them yourself should hand you the library there and then. How many a
	   piece wants is not known until it exists, but it is predictable enough from the length to be
	   worth saying out loud: a hero, then every other section, four at the most. */

	var WANTS = { short: 2, medium: 3, long: 4 };

	function wants() {
		return WANTS[ length.value ] || 3;
	}

	function fill( text, a, b ) {
		return String( text ).replace( '%s', a ).replace( '%1$s', a ).replace( '%2$s', b );
	}

	function paintChosen() {
		if ( ! chosenList ) {
			return;
		}
		chosenList.innerHTML = '';
		picked.forEach( function ( p, i ) {
			var cell = document.createElement( 'span' );
			cell.className = 'whd-writer__chosen-one';
			cell.innerHTML = '<img src="' + p.url + '" alt="" />'
				+ '<span class="whd-writer__chosen-no">' + ( i + 1 ) + '</span>'
				+ '<button type="button" class="whd-writer__chosen-drop" aria-label="Remove">&times;</button>';
			cell.querySelector( '.whd-writer__chosen-drop' ).addEventListener( 'click', function () {
				picked.splice( i, 1 );
				paintChosen();
			} );
			chosenList.appendChild( cell );
		} );

		chosenCount.textContent = ! picked.length ? T.pickNone
			: ( 1 === picked.length ? T.pickOne : fill( T.pickMany, picked.length ) );
		chosenClear.hidden = ! picked.length;
		chosenHint.textContent = fill( T.pickHint, length.value, wants() );
	}

	/** The library, opened on the pictures already chosen so a second visit adds rather than replaces. */
	function openPicker() {
		if ( ! window.wp || ! wp.media ) {
			return;
		}
		var lib = wp.media( {
			title: 'Choose pictures',
			button: { text: 'Use these' },
			library: { type: 'image' },
			multiple: 'add'
		} );
		lib.on( 'open', function () {
			lib.content.mode( 'browse' );
			var selection = lib.state().get( 'selection' );
			picked.forEach( function ( p ) {
				var one = wp.media.attachment( p.id );
				one.fetch();
				selection.add( one );
			} );
		} );
		lib.on( 'select', function () {
			picked = lib.state().get( 'selection' ).map( function ( one ) {
				var a = one.toJSON();
				return {
					id: a.id,
					url: ( a.sizes && a.sizes.thumbnail ? a.sizes.thumbnail.url : a.url ),
					full: ( a.sizes && a.sizes.large ? a.sizes.large.url : a.url ),
					alt: a.alt || a.title || '',
					source: 'library'
				};
			} );
			paintChosen();
		} );
		lib.open();
	}

	if ( chosenAdd ) {
		chosenAdd.addEventListener( 'click', openPicker );
	}
	if ( chosenClear ) {
		chosenClear.addEventListener( 'click', function () {
			picked = [];
			paintChosen();
		} );
	}
	if ( length ) {
		length.addEventListener( 'change', paintChosen );
	}

	if ( imagesMode ) {
		imagesMode.addEventListener( 'change', function () {
			var manual = 'manual' === imagesMode.value;
			if ( chosenBox ) {
				chosenBox.hidden = ! manual;
			}
			if ( askRow ) {
				askRow.hidden = 'auto' !== imagesMode.value;
			}
			if ( manual ) {
				paintChosen();
				if ( ! picked.length ) {
					openPicker();
				}
			}
		} );
	}

	/* ─── writing ─── */

	function paintCheck( c ) {
		if ( ! c ) {
			check.innerHTML = '';
			return;
		}
		var bits = [];
		bits.push( '<span class="whd-writer__badge ' + ( c.ok ? 'is-ok' : 'is-warn' ) + '">'
			+ ( c.ok ? 'Passes every check' : 'Worth a look' ) + '</span>' );
		bits.push( '<span class="whd-writer__stat">' + ( c.words || 0 ) + ' ' + ( T.words || 'words' ) + '</span>' );

		var missing = [];
		Object.keys( c.keywords || {} ).forEach( function ( k ) {
			if ( ! c.keywords[ k ] ) {
				missing.push( k );
			}
		} );
		if ( missing.length ) {
			bits.push( '<span class="whd-writer__stat is-warn">not in the copy: ' + missing.join( ', ' ) + '</span>' );
		}
		( c.issues || [] ).forEach( function ( issue ) {
			bits.push( '<span class="whd-writer__stat is-warn">' + issue + '</span>' );
		} );
		check.innerHTML = bits.join( '' );
	}

	function countDesc() {
		if ( ! descCount ) {
			return;
		}
		var n = desc.value.trim().length;
		descCount.textContent = n + ' / 155';
		descCount.className = 'whd-writer__count' + ( n > 158 || ( n && n < 100 ) ? ' is-warn' : '' );
	}

	desc.addEventListener( 'input', countDesc );

	go.addEventListener( 'click', function () {
		if ( ! idea.value.trim() ) {
			status.textContent = T.noIdea;
			idea.focus();
			return;
		}
		go.disabled = true;
		status.textContent = T.writing;
		check.innerHTML = '';
		if ( savedBox ) {
			savedBox.hidden = true;
		}

		api( 'compose', {
			idea: idea.value,
			keywords: keywords(),
			type: D.type,
			length: length.value,
			faq: faq.value === '1'
		} ).then( function ( r ) {
			doc = r;
			title.value = r.title || '';
			desc.value = r.meta_description || '';
			body.innerHTML = r.html || '';
			paintCheck( r.check );
			countDesc();
			var mode = imagesMode ? imagesMode.value : 'auto';
			slots = 'none' === mode ? [] : ( r.slots || [] ).map( function ( slot ) {
				return { key: slot.key, subject: slot.subject, heading: slot.heading, image: null };
			} );
			var spare = 0;
			if ( 'manual' === mode && picked.length ) {
				slots.forEach( function ( slot, i ) {
					if ( picked[ i ] ) {
						slot.image = picked[ i ];
					}
				} );
				spare = Math.max( 0, picked.length - slots.length );
			}
			if ( slotsNote ) {
				slotsNote.textContent = ( 'auto' === mode && ask && ask.value.trim() )
					? ( T.noteAsk || slotsNoteWas )
					: slotsNoteWas;
			}
			if ( slotsBox ) {
				slotsBox.hidden = ! slots.length;
				slotsStatus.textContent = 'manual' !== mode || ! slots.length ? ''
					: ( spare ? fill( T.pickSpare, slots.length )
						: ( taken().length < slots.length ? ( picked.length ? T.pickFew : T.pickEach ) : T.swapAny ) );
				paintSlots();
			}
			result.hidden = false;
			status.textContent = '';
			go.textContent = 'Write another';
			result.scrollIntoView( { behavior: 'smooth', block: 'start' } );
			if ( 'auto' === mode && slots.length ) {
				fillAll();
			}
		} ).catch( function ( e ) {
			status.textContent = e.message;
		} ).then( function () {
			go.disabled = false;
		} );
	} );

	if ( again ) {
		again.addEventListener( 'click', function () {
			go.click();
		} );
	}

	/* ─── pictures ───
	   The piece says how many it wants. Fill them in one go, or click any slot and choose that one
	   yourself; the two can be mixed, and a slot left empty is simply a slot left empty. */

	var slotsBox = document.getElementById( 'whd-writer-images' );
	var slotsList = document.getElementById( 'whd-writer-slots' );
	var slotsCount = document.getElementById( 'whd-writer-images-count' );
	var slotsAuto = document.getElementById( 'whd-writer-images-auto' );
	var slotsStatus = document.getElementById( 'whd-writer-images-status' );
	var slots = [];
	var frame = null;

	function taken() {
		return slots.filter( function ( s ) {
			return s.image;
		} ).map( function ( s ) {
			return s.image.id;
		} );
	}

	function paintSlots() {
		if ( ! slotsList ) {
			return;
		}
		slotsList.innerHTML = '';
		slots.forEach( function ( slot, i ) {
			var cell = document.createElement( 'button' );
			cell.type = 'button';
			cell.className = 'whd-writer__slot' + ( slot.image ? ' is-filled' : '' );
			cell.title = slot.heading || 'Top of the page';

			if ( slot.image ) {
				cell.innerHTML = '<img src="' + slot.image.url + '" alt="" />'
					+ '<span class="whd-writer__slot-tag">' + ( slot.image.source === 'drawn' ? 'drawn' : 'from your library' ) + '</span>';
			} else {
				cell.innerHTML = '<span class="whd-writer__slot-empty">+</span>';
			}
			cell.innerHTML += '<span class="whd-writer__slot-where">' + ( slot.heading || 'Top of the page' ) + '</span>';
			cell.addEventListener( 'click', function () {
				choose( i );
			} );
			slotsList.appendChild( cell );
		} );

		var filled = taken().length;
		slotsCount.textContent = slots.length === 1
			? ( filled ? '1 picture, chosen' : 'This piece wants 1 picture' )
			: ( 'This piece wants ' + slots.length + ' pictures' + ( filled ? ' — ' + filled + ' chosen' : '' ) );
	}

	/** WordPress's own media window, for the slot that was clicked. */
	function choose( index ) {
		if ( ! window.wp || ! wp.media ) {
			return;
		}
		if ( ! frame ) {
			frame = wp.media( { title: 'Choose a picture', button: { text: 'Use this one' }, multiple: false } );
		}
		frame.off( 'open' );
		frame.on( 'open', function () {
			frame.content.mode( 'browse' );
		} );
		frame.off( 'select' );
		frame.on( 'select', function () {
			var picked = frame.state().get( 'selection' ).first().toJSON();
			slots[ index ].image = {
				id: picked.id,
				url: ( picked.sizes && picked.sizes.medium ? picked.sizes.medium.url : picked.url ),
				full: ( picked.sizes && picked.sizes.large ? picked.sizes.large.url : picked.url ),
				alt: picked.alt || picked.title || '',
				source: 'library'
			};
			paintSlots();
		} );
		frame.open();
	}

	function fillAll() {
		var queue = slots.map( function ( s, i ) {
				return i;
			} ).filter( function ( i ) {
				return ! slots[ i ].image;
			} );
		if ( ! queue.length || ! doc ) {
			return;
		}
		if ( slotsAuto ) {
			slotsAuto.disabled = true;
		}

		function next() {
			if ( ! queue.length ) {
				if ( slotsAuto ) {
					slotsAuto.disabled = false;
				}
				slotsStatus.textContent = T.swapAny;
				return;
			}
			var i = queue.shift();
			slotsStatus.textContent = 'Looking for ' + ( slots[ i ].heading || 'the one at the top' ) + '…';
			api( 'write-image', {
				doc: doc,
				slot: { key: slots[ i ].key, subject: slots[ i ].subject, heading: slots[ i ].heading },
				taken: taken(),
				draw: !! D.canDraw,
				ask: ask ? ask.value : ''
			} ).then( function ( r ) {
				slots[ i ].image = r;
				paintSlots();
				next();
			} ).catch( function ( e ) {
				slotsStatus.textContent = e.message;
				if ( slotsAuto ) {
					slotsAuto.disabled = false;
				}
			} );
		}

		next();
	}

	if ( slotsAuto ) {
		slotsAuto.addEventListener( 'click', fillAll );
	}

	/* ─── saving ─── */

	function save( postStatus, button ) {
		if ( ! doc ) {
			return;
		}
		button.disabled = true;
		saveStatus.textContent = T.saving;

		var payload = JSON.parse( JSON.stringify( doc ) );
		payload.title = title.value;
		payload.meta_description = desc.value;
		payload.html = body.innerHTML;
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
			idea: idea.value,
			type: D.type,
			status: postStatus
		} ).then( function ( r ) {
			/* The result box closes on a save, so the confirmation cannot live inside it — the
			   edit link is the one thing worth keeping on screen. */
			saveStatus.textContent = '';
			var where = savedBox || saveStatus;
			var scored = r.seo && r.seo.score
				? ' <span class="whd-seo-dot whd-seo-dot--' + r.seo.rating + '">' + r.seo.score + '</span>'
					+ ' <span class="whd-writer__scored">SEO ' + r.seo.label.toLowerCase() + '</span>'
				: '';
			where.innerHTML = '<strong>' + ( postStatus === 'publish' ? 'Published.' : 'Saved as a draft.' ) + '</strong>' + scored + ' '
				+ '<a href="' + r.edit_url + '">' + ( postStatus === 'publish' ? 'Edit it' : 'Open it' ) + '</a>'
				+ ' · <a href="' + r.view_url + '" target="_blank" rel="noopener">'
				+ ( postStatus === 'publish' ? 'View it' : 'Preview it' ) + '</a>';
			where.hidden = false;
			addRow( r, postStatus );
			doc = null;
			result.hidden = true;
			idea.value = '';
			go.textContent = 'Write it';
			/* Those pictures belonged to the piece that was just saved. */
			picked = [];
			paintChosen();
		} ).catch( function ( e ) {
			saveStatus.textContent = e.message;
		} ).then( function () {
			button.disabled = false;
		} );
	}

	publish.addEventListener( 'click', function () {
		save( 'publish', publish );
	} );
	draft.addEventListener( 'click', function () {
		save( 'draft', draft );
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
		tr.innerHTML = '<td class="whd-writer__cell-title"><strong><a href="' + saved.edit_url + '">'
			+ saved.title.replace( /</g, '&lt;' ) + '</a></strong></td>'
			+ '<td>' + keywords().slice( 0, 4 ).join( ', ' ) + '</td>'
			+ '<td><span class="whd-pill whd-pill--' + ( postStatus === 'publish' ? 'on' : 'off' ) + '">'
			+ ( postStatus === 'publish' ? 'Published' : 'Draft' ) + '</span></td>'
			+ '<td>' + new Date().toLocaleDateString() + '</td>'
			+ '<td class="whd-writer__cell-actions"><a class="button button-small" href="' + saved.edit_url + '">Edit</a> '
			+ '<a class="button button-small" href="' + saved.view_url + '" target="_blank" rel="noopener">View</a></td>';
		table.insertBefore( tr, table.firstChild );
	}
}() );
