/**
 * Image Editor screen: the editor mounted on the page, opened on the images a link brought along
 * (?ids= from the media library or Image Builder) — or, with none, on its "choose images" start.
 *
 * On Image Builder it is loaded lazily (WHD_IE.lazy): nothing is built until a picture there is sent
 * to be edited, through WHDImageEditorScreen.open( ids ).
 */
( function () {
	'use strict';

	var D = window.WHD_IE || {};
	var root = document.getElementById( 'whd-ie' );
	if ( ! root || ! window.WHDAIKit || ! window.WHDImageEditor || ! D.rest ) {
		return;
	}
	var T = D.i18n || {};
	var editor = window.WHDImageEditor;

	var mounted = false;

	function mount() {
		if ( mounted ) {
			editor.show();
			return;
		}
		mounted = true;
		editor.mount( root, {
			strings: T,
			client: window.WHDAIKit.client( { root: D.rest.root, nonce: D.rest.nonce, strings: T } ),
			fonts: D.fonts || [],
			logos: D.logos || [],
			brand: D.brand || {},
			currency: D.currency || ''
		} );
	}

	if ( ! D.lazy ) {
		mount();
		( D.start || [] ).forEach( editor.open );
	}

	window.WHDImageEditorScreen = {
		/* Build the editor if it is not there yet, then open these library images in it. */
		open: function ( ids ) {
			mount();
			return editor.openIds( ( ids || [] ).slice( 0, D.max || 12 ) );
		},
		mounted: function () {
			return mounted;
		}
	};

	window.addEventListener( 'beforeunload', function ( e ) {
		if ( mounted && editor.dirty() ) {
			e.preventDefault();
			e.returnValue = T.unsaved;
			return T.unsaved;
		}
	} );
}() );
