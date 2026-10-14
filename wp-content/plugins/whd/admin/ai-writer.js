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
	var saveStatus = document.getElementById( 'whd-writer-save-status' );

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
			result.hidden = false;
			status.textContent = '';
			go.textContent = 'Write another';
			result.scrollIntoView( { behavior: 'smooth', block: 'start' } );
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

		api( 'write-save', {
			doc: payload,
			idea: idea.value,
			type: D.type,
			status: postStatus
		} ).then( function ( r ) {
			saveStatus.innerHTML = ( postStatus === 'publish' ? 'Published' : 'Saved as a draft' )
				+ ' — <a href="' + r.edit_url + '">edit</a>'
				+ ( postStatus === 'publish' ? ' · <a href="' + r.view_url + '" target="_blank" rel="noopener">view</a>' : '' );
			addRow( r, postStatus );
			doc = null;
			result.hidden = true;
			idea.value = '';
			go.textContent = 'Write it';
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
