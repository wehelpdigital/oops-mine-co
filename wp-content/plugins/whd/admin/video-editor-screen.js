/**
 * The Video Editor's own screen: opens on the clips in ?ids= (from the media library's "Edit in Video
 * Editor"), or empty, offering back an edit left unsaved in this browser. On the Video Builder the editor
 * is loaded lazily instead (WHD_VE.lazy) and opened there on whatever was just made.
 */
( function () {
	'use strict';
	var D = window.WHD_VE || {};
	var el = document.getElementById( 'whd-ve' );
	if ( ! el || D.lazy || ! window.WHDVideoEditor ) {
		return;
	}
	window.WHDVideoEditor.open( el, { clips: D.start || [] } );
}() );
