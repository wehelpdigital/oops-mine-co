/**
 * Image Editor screen: the editor mounted on the page, opened on the images a link brought along
 * (?ids= from the media library or Image Builder) — or, with none, on its "choose images" start.
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

	editor.mount( root, {
		strings: T,
		client: window.WHDAIKit.client( { root: D.rest.root, nonce: D.rest.nonce, strings: T } ),
		fonts: D.fonts || [],
		logos: D.logos || [],
		brand: D.brand || {},
		currency: D.currency || ''
	} );
	( D.start || [] ).forEach( editor.open );

	window.addEventListener( 'beforeunload', function ( e ) {
		if ( editor.dirty() ) {
			e.preventDefault();
			e.returnValue = T.unsaved;
			return T.unsaved;
		}
	} );
}() );
