/**
 * Image Builder → Image Editor, on the same screen.
 *
 * The editor is loaded with the builder but built only when it is needed: a tile's "Add text or
 * effects" pencil, or "Edit these images" above the results, opens those pictures in the editor
 * right under the results instead of a new tab — so a set can be made and then dressed up without
 * leaving the page. The pictures are already in the media library; saving in the editor adds new
 * ones beside them, as it does on its own screen.
 */
( function () {
	'use strict';

	var section = document.getElementById( 'whd-ib-edit' );
	var tiles = document.getElementById( 'whd-ib-tiles' );
	var all = document.getElementById( 'whd-ib-edit-all' );
	if ( ! section || ! tiles ) {
		return;
	}

	/* The library ids an editor link carries: …&ids=12,13 */
	function idsOf( href ) {
		var m = /[?&]ids=([0-9,]+)/.exec( href || '' );
		return m ? m[ 1 ].split( ',' ).map( Number ).filter( Boolean ) : [];
	}

	function links() {
		return Array.prototype.slice.call( tiles.querySelectorAll( 'a[href*="page=whd-image-editor"]' ) );
	}

	function open( ids ) {
		if ( ! ids.length || ! window.WHDImageEditorScreen ) {
			return false;
		}
		section.hidden = false;
		window.WHDImageEditorScreen.open( ids );
		window.setTimeout( function () {
			section.scrollIntoView( { behavior: 'smooth', block: 'start' } );
		}, 60 );
		return true;
	}

	/* A tile's pencil opens that one picture here. Ctrl/⌘-click still opens the editor's own tab. */
	tiles.addEventListener( 'click', function ( e ) {
		var a = e.target.closest( 'a[href*="page=whd-image-editor"]' );
		if ( ! a || e.metaKey || e.ctrlKey || e.shiftKey || 0 !== e.button ) {
			return;
		}
		if ( open( idsOf( a.getAttribute( 'href' ) ) ) ) {
			e.preventDefault();
		}
	} );

	/* "Edit these images": every picture on the board, newest first, up to what the editor takes. */
	function paintAll() {
		if ( all ) {
			all.hidden = ! links().length;
		}
	}
	if ( all ) {
		all.addEventListener( 'click', function () {
			var ids = [];
			links().forEach( function ( a ) {
				idsOf( a.getAttribute( 'href' ) ).forEach( function ( id ) {
					if ( ids.indexOf( id ) === -1 ) {
						ids.push( id );
					}
				} );
			} );
			open( ids );
		} );
	}
	if ( window.MutationObserver ) {
		new MutationObserver( paintAll ).observe( tiles, { childList: true, subtree: true } );
	}
	paintAll();

	var close = section.querySelector( '[data-ib-edit-close]' );
	if ( close ) {
		close.addEventListener( 'click', function () {
			section.hidden = true;
			tiles.scrollIntoView( { behavior: 'smooth', block: 'start' } );
		} );
	}
}() );
