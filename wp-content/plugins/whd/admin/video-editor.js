/**
 * The Video Editor's screen: panels to add things, the preview, an inspector for whatever is chosen, and a
 * timeline with a track each for the video, text, stickers, effects and sound.
 *
 * Everything is one project object (video-engine.js); every change goes through commit(), which keeps an
 * undo history, redraws what shows it and keeps a draft in the browser. The preview is the engine's own
 * renderer, so a text dragged on the preview lands in the saved video exactly there.
 *
 *   WHDVideoEditor.open( element, { clips, size, made, exportAs, onSaved } ) → editor
 */
( function () {
	'use strict';

	var D = window.WHD_VE || {};
	var T = D.i18n || {};
	var V = window.WHDVideo;
	var P = window.WHDVideoPresets;
	if ( ! V || ! P ) {
		return;
	}

	var DRAFT = 'whd-ve-draft';
	var PREVIEW_LONG = 960;

	function esc( s ) {
		var d = document.createElement( 'div' );
		d.textContent = s == null ? '' : String( s );
		return d.innerHTML;
	}
	function h( tag, cls, html ) {
		var e = document.createElement( tag );
		if ( cls ) {
			e.className = cls;
		}
		if ( html != null ) {
			e.innerHTML = html;
		}
		return e;
	}
	function time( t, fine ) {
		t = Math.max( 0, t || 0 );
		var m = Math.floor( t / 60 );
		var s = t - m * 60;
		return m + ':' + ( s < 10 ? '0' : '' ) + ( fine ? s.toFixed( 1 ) : Math.floor( s ) );
	}
	function num( v, d ) {
		var n = parseFloat( v );
		return isNaN( n ) ? d : n;
	}
	function clone( o ) {
		return JSON.parse( JSON.stringify( o ) );
	}

	var ICON = {
		media: '<rect x="3.5" y="5.5" width="13" height="13" rx="2"/><path d="m16.5 10 4-2.5v9l-4-2.5"/>',
		text: '<path d="M5 6h14"/><path d="M12 6v13"/><path d="M9 19h6"/>',
		stickers: '<path d="M14.5 20.5H7A3.5 3.5 0 0 1 3.5 17V7A3.5 3.5 0 0 1 7 3.5h10A3.5 3.5 0 0 1 20.5 7v7.5z"/><path d="M14.5 20.5v-3a3 3 0 0 1 3-3h3"/><path d="M8.5 10h.01M15.5 10h.01"/><path d="M9 14c1.5 1.3 4.5 1.3 6 0"/>',
		effects: '<path d="M12 3.5 13.9 9l5.6 1.9-5.6 1.9L12 18.5l-1.9-5.7-5.6-1.9L10.1 9z"/><path d="M19 3v3M17.5 4.5h3"/>',
		music: '<path d="M9 18V6l11-2v12"/><circle cx="6.5" cy="18" r="2.5"/><circle cx="17.5" cy="16" r="2.5"/>',
		filters: '<circle cx="9" cy="10" r="5.5"/><circle cx="15" cy="10" r="5.5"/><circle cx="12" cy="15" r="5.5"/>',
		project: '<rect x="3.5" y="4.5" width="17" height="15" rx="2"/><path d="M3.5 9h17"/>',
		play: '<path d="M7 4.5v15l12-7.5z" fill="currentColor"/>',
		pause: '<path d="M7 4.5h3.5v15H7zM13.5 4.5H17v15h-3.5z" fill="currentColor"/>',
		undo: '<path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 0 11H11"/>',
		redo: '<path d="m15 14 5-5-5-5"/><path d="M20 9H9.5a5.5 5.5 0 0 0 0 11H13"/>',
		loop: '<path d="M17 2.5 20.5 6 17 9.5"/><path d="M3.5 11V9.5A3.5 3.5 0 0 1 7 6h13.5"/><path d="M7 21.5 3.5 18 7 14.5"/><path d="M20.5 13v1.5A3.5 3.5 0 0 1 17 18H3.5"/>',
		sound: '<path d="M4 9.5h3.5L12 5.5v13l-4.5-4H4z"/><path d="M15.5 9a4 4 0 0 1 0 6M18 6.5a7.5 7.5 0 0 1 0 11"/>',
		mute: '<path d="M4 9.5h3.5L12 5.5v13l-4.5-4H4z"/><path d="m16 9.5 5 5M21 9.5l-5 5"/>',
		x: '<path d="M7 7l10 10M17 7 7 17"/>',
		plus: '<path d="M12 5v14M5 12h14"/>',
		upload: '<path d="M12 16V5"/><path d="m7.5 9.5 4.5-4.5 4.5 4.5"/><path d="M5 19h14"/>',
		library: '<rect x="3.5" y="4.5" width="17" height="15" rx="2"/><circle cx="9" cy="10" r="1.6"/><path d="m20.5 16-4.5-4.5L7 19.5"/>',
		save: '<path d="M5 4.5h11l3.5 3.5v11.5h-14.5z"/><path d="M8 4.5v5h7v-5"/><path d="M8 19.5v-6h8v6"/>',
		download: '<path d="M12 4v11"/><path d="m7.5 10.5 4.5 4.5 4.5-4.5"/><path d="M5 19.5h14"/>',
		search: '<circle cx="11" cy="11" r="6.5"/><path d="m20 20-4.2-4.2"/>',
		trash: '<path d="M4.5 7h15"/><path d="M9.5 7V4.8h5V7"/><path d="M6.5 7l.9 12.2a1.5 1.5 0 0 0 1.5 1.3h6.2a1.5 1.5 0 0 0 1.5-1.3L17.5 7"/>',
		copy: '<rect x="8.5" y="8.5" width="11" height="11" rx="2"/><path d="M15.5 8.5V6a1.5 1.5 0 0 0-1.5-1.5H6A1.5 1.5 0 0 0 4.5 6v8A1.5 1.5 0 0 0 6 15.5h2.5"/>'
	};
	function svg( name, size ) {
		return '<svg viewBox="0 0 24 24" width="' + ( size || 18 ) + '" height="' + ( size || 18 ) + '" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' + ICON[ name ] + '</svg>';
	}

	/* ─────────────────────────── the editor ─────────────────────────── */

	function Editor( root, opts ) {
		this.root = root;
		this.opts = opts || {};
		this.tab = 'media';
		this.musicTab = 'library';
		this.sel = null;          // { kind: 'clip'|'layer'|'audio'|'project', id }
		this.history = [];
		this.future = [];
		this.dirty = false;
		this.pxs = 60;            // timeline pixels per second
		this.build();
		this.project = V.newProject( this.opts.size || { w: 1080, h: 1920 } );
		this.player = new V.Player( {
			canvas: this.canvas,
			project: this.project,
			onTime: this.onTime.bind( this ),
			onState: this.onState.bind( this )
		} );
		this.fitCanvas();
		this.bindKeys();
		this.paintAll();
	}

	Editor.prototype.build = function () {
		var self = this;
		var r = this.root;
		r.innerHTML = '';
		r.classList.add( 'whd-ve' );
		var app = h( 'div', 'whd-ve__app' );
		app.innerHTML =
			'<div class="whd-ve__bar">' +
				'<input type="text" class="whd-ve__name" maxlength="120" placeholder="' + esc( T.name ) + '" aria-label="' + esc( T.name ) + '">' +
				'<label class="whd-ve__size"><span class="screen-reader-text">' + esc( T.sizePreset ) + '</span><select class="whd-ve__sizes"></select></label>' +
				'<button type="button" class="whd-ve__icon" data-act="undo" title="' + esc( T.undo ) + '" aria-label="' + esc( T.undo ) + '">' + svg( 'undo' ) + '</button>' +
				'<button type="button" class="whd-ve__icon" data-act="redo" title="' + esc( T.redo ) + '" aria-label="' + esc( T.redo ) + '">' + svg( 'redo' ) + '</button>' +
				'<span class="whd-ve__spacer"></span>' +
				'<button type="button" class="whd-ve__btn" data-act="download">' + svg( 'download', 16 ) + '<span>' + esc( T.download ) + '</span></button>' +
				'<button type="button" class="whd-ve__btn whd-ve__btn--dark" data-act="save">' + svg( 'save', 16 ) + '<span>' + esc( T.save ) + '</span></button>' +
			'</div>' +
			'<div class="whd-ve__restore" hidden></div>' +
			'<div class="whd-ve__main">' +
				'<nav class="whd-ve__rail" role="tablist"></nav>' +
				'<div class="whd-ve__panel"></div>' +
				'<div class="whd-ve__stage">' +
					'<div class="whd-ve__screen"><div class="whd-ve__frame"><canvas class="whd-ve__canvas"></canvas><div class="whd-ve__overlay"></div></div>' +
						'<div class="whd-ve__empty"><p class="whd-ve__empty-h">' + esc( T.emptyTitle ) + '</p><p>' + esc( T.emptyNote ) + '</p>' +
						'<p class="whd-ve__empty-btns"><button type="button" class="whd-ve__btn whd-ve__btn--dark" data-act="pick">' + svg( 'library', 16 ) + '<span>' + esc( T.chooseClips ) + '</span></button>' +
						'<button type="button" class="whd-ve__btn" data-act="upload">' + svg( 'upload', 16 ) + '<span>' + esc( T.uploadClip ) + '</span></button></p></div>' +
					'</div>' +
					'<div class="whd-ve__transport">' +
						'<button type="button" class="whd-ve__play" data-act="play" aria-label="' + esc( T.play ) + '">' + svg( 'play', 20 ) + '</button>' +
						'<span class="whd-ve__clock"><span class="whd-ve__now">0:00.0</span> / <span class="whd-ve__dur">0:00.0</span></span>' +
						'<span class="whd-ve__spacer"></span>' +
						'<button type="button" class="whd-ve__icon" data-act="loop" aria-pressed="false" title="' + esc( T.loop ) + '">' + svg( 'loop' ) + '</button>' +
						'<button type="button" class="whd-ve__icon" data-act="mute" aria-pressed="false" title="' + esc( T.mute ) + '">' + svg( 'sound' ) + '</button>' +
					'</div>' +
				'</div>' +
				'<aside class="whd-ve__inspect"></aside>' +
			'</div>' +
			'<div class="whd-ve__timeline">' +
				'<div class="whd-ve__tl-head"><span class="whd-ve__keys">' + esc( T.keyHelp ) + '</span><span class="whd-ve__spacer"></span>' +
				'<label class="whd-ve__tl-zoom">' + esc( T.zoomTimeline ) + ' <input type="range" min="12" max="240" step="1" value="60"></label></div>' +
				'<div class="whd-ve__tl-body"><div class="whd-ve__tl-labels"></div><div class="whd-ve__tl-scroll"><div class="whd-ve__tl-inner">' +
					'<div class="whd-ve__ruler"></div><div class="whd-ve__tracks"></div><div class="whd-ve__playhead"><span></span></div>' +
				'</div></div></div>' +
			'</div>' +
			'<input type="file" class="whd-ve__file" accept="video/mp4,video/webm,video/quicktime,image/jpeg,image/png,image/webp" multiple hidden>' +
			'<input type="file" class="whd-ve__afile" accept="audio/mpeg,audio/mp4,audio/x-m4a,audio/wav,audio/ogg" hidden>';
		r.appendChild( app );
		this.app = app;
		this.$ = function ( sel ) {
			return app.querySelector( sel );
		};
		this.canvas = this.$( '.whd-ve__canvas' );
		this.frameEl = this.$( '.whd-ve__frame' );
		this.overlay = this.$( '.whd-ve__overlay' );
		this.panel = this.$( '.whd-ve__panel' );
		this.inspect = this.$( '.whd-ve__inspect' );
		this.tracks = this.$( '.whd-ve__tracks' );
		this.ruler = this.$( '.whd-ve__ruler' );
		this.playhead = this.$( '.whd-ve__playhead' );
		this.scroll = this.$( '.whd-ve__tl-scroll' );
		this.inner = this.$( '.whd-ve__tl-inner' );
		this.labels = this.$( '.whd-ve__tl-labels' );

		var sizes = this.$( '.whd-ve__sizes' );
		P.sizes.forEach( function ( s ) {
			var o = document.createElement( 'option' );
			o.value = s.key;
			o.textContent = s.name;
			sizes.appendChild( o );
		} );
		sizes.addEventListener( 'change', function () {
			var s = V.find( P.sizes, sizes.value );
			if ( s ) {
				self.project.w = s.w;
				self.project.h = s.h;
				self.commit();
				self.fitCanvas();
			}
		} );
		this.$( '.whd-ve__name' ).addEventListener( 'change', function () {
			self.project.name = this.value;
			self.commit();
		} );

		var rail = this.$( '.whd-ve__rail' );
		[ [ 'media', T.media ], [ 'text', T.text ], [ 'stickers', T.stickers ], [ 'effects', T.effects ], [ 'music', T.music ], [ 'filters', T.filters ], [ 'project', T.project ] ].forEach( function ( tab ) {
			var b = h( 'button', 'whd-ve__tab', svg( tab[ 0 ], 20 ) + '<span>' + esc( tab[ 1 ] ) + '</span>' );
			b.type = 'button';
			b.setAttribute( 'role', 'tab' );
			b.dataset.tab = tab[ 0 ];
			b.addEventListener( 'click', function () {
				if ( 'project' === tab[ 0 ] ) {
					self.select( { kind: 'project' } );
					return;
				}
				self.tab = tab[ 0 ];
				self.paintPanel();
			} );
			rail.appendChild( b );
		} );

		app.addEventListener( 'click', function ( e ) {
			var b = e.target.closest( '[data-act]' );
			if ( ! b || ! app.contains( b ) ) {
				return;
			}
			var act = b.dataset.act;
			if ( 'undo' === act ) {
				self.undo();
			} else if ( 'redo' === act ) {
				self.redo();
			} else if ( 'play' === act ) {
				self.player.toggle();
			} else if ( 'loop' === act ) {
				self.player.loop = ! self.player.loop;
				b.setAttribute( 'aria-pressed', self.player.loop ? 'true' : 'false' );
			} else if ( 'mute' === act ) {
				self.player.muted = ! self.player.muted;
				b.setAttribute( 'aria-pressed', self.player.muted ? 'true' : 'false' );
				b.innerHTML = svg( self.player.muted ? 'mute' : 'sound' );
				self.player.seek( self.player.t );
			} else if ( 'save' === act ) {
				self.exportDialog( 'save' );
			} else if ( 'download' === act ) {
				self.exportDialog( 'download' );
			} else if ( 'pick' === act ) {
				self.pickClips();
			} else if ( 'upload' === act ) {
				self.$( '.whd-ve__file' ).click();
			}
		} );
		this.$( '.whd-ve__file' ).addEventListener( 'change', function () {
			self.uploadClips( this.files );
			this.value = '';
		} );
		this.$( '.whd-ve__afile' ).addEventListener( 'change', function () {
			self.uploadAudio( this.files[ 0 ] );
			this.value = '';
		} );
		this.$( '.whd-ve__tl-zoom input' ).addEventListener( 'input', function () {
			self.pxs = num( this.value, 60 );
			self.paintTimeline();
		} );

		// The preview: click to choose a text or sticker, drag to move it.
		this.canvas.addEventListener( 'pointerdown', function ( e ) {
			var hit = self.hitTest( e );
			if ( hit ) {
				self.select( { kind: 'layer', id: hit } );
				self.dragLayer( e, 'move' );
			} else if ( self.sel && 'layer' === self.sel.kind ) {
				self.select( null );
			}
		} );
		window.addEventListener( 'resize', this.fitCanvas.bind( this ) );
		this.ro = 'ResizeObserver' in window ? new ResizeObserver( this.fitCanvas.bind( this ) ) : null;
		if ( this.ro ) {
			this.ro.observe( this.$( '.whd-ve__screen' ) );
		}

		// The timeline: click the ruler or empty space to move the playhead there.
		this.inner.addEventListener( 'pointerdown', function ( e ) {
			if ( e.target.closest( '.whd-ve__item' ) ) {
				return;
			}
			self.scrub( e );
		} );
		window.addEventListener( 'beforeunload', this.onLeave = function ( e ) {
			if ( self.dirty ) {
				e.preventDefault();
				e.returnValue = T.unsaved;
			}
		} );
	};

	Editor.prototype.destroy = function () {
		this.player.destroy();
		if ( this.ro ) {
			this.ro.disconnect();
		}
		window.removeEventListener( 'beforeunload', this.onLeave );
		document.removeEventListener( 'keydown', this.onKey );
		this.stopPreview();
		this.root.innerHTML = '';
	};

	/* ─────────────────────────── loading ─────────────────────────── */

	/* Start on these library clips; a clip the editor saved before opens with its edit. */
	Editor.prototype.load = function ( clips, opts ) {
		opts = opts || {};
		clips = ( clips || [] ).filter( Boolean );
		var self = this;
		var withDesign = clips.length === 1 && clips[ 0 ].design;
		var p;
		if ( withDesign ) {
			try {
				p = V.normalize( JSON.parse( clips[ 0 ].design ) );
			} catch ( e ) {
				p = null;
			}
		}
		if ( ! p ) {
			var first = clips[ 0 ];
			var size = opts.size || ( first && first.width && first.height ? this.sizeFor( first.width, first.height ) : { w: 1080, h: 1920 } );
			p = V.fromClips( clips, size );
		}
		this.project = p;
		this.history = [];
		this.future = [];
		this.player.setProject( p );
		this.fitCanvas();
		this.dirty = false;
		this.paintAll();
		this.probeClips();
	};

	/* The library's lengths can be missing or rounded for a new file: read them from the files themselves. */
	Editor.prototype.probeClips = function () {
		var self = this;
		this.probed = this.probed || {};
		var waits = this.project.clips.filter( function ( c ) {
			return 'video' === c.kind && ! self.probed[ c.url ];
		} ).map( function ( c ) {
			self.probed[ c.url ] = V.probe( c.url );
			return self.probed[ c.url ];
		} );
		if ( ! waits.length ) {
			return;
		}
		Promise.all( waits ).then( function () {
			var changed = false;
			self.project.clips.forEach( function ( c ) {
				if ( 'video' !== c.kind || ! self.probed[ c.url ] ) {
					return;
				}
				self.probed[ c.url ].then( function ( info ) {
					if ( info && isFinite( info.seconds ) && info.seconds > 0 && Math.abs( info.seconds - c.seconds ) > 0.05 ) {
						var whole = c.out >= c.seconds - 0.05;
						c.seconds = info.seconds;
						if ( whole || c.out > info.seconds ) {
							c.out = info.seconds;
						}
						changed = true;
					}
				} );
			} );
			// The promises above have all settled, so their changes are in by the next turn.
			setTimeout( function () {
				if ( changed ) {
					self.lastSnap = JSON.stringify( self.project );
					self.player.refresh();
					self.paintAll();
				}
			}, 0 );
		} );
	};

	/* The preset nearest a clip's own shape. */
	Editor.prototype.sizeFor = function ( w, h ) {
		var ar = w / h;
		var best = P.sizes[ 0 ];
		var diff = 99;
		P.sizes.forEach( function ( s ) {
			if ( /720/.test( s.key ) ) {
				return;
			}
			var d = Math.abs( Math.log( ( s.w / s.h ) / ar ) );
			if ( d < diff ) {
				diff = d;
				best = s;
			}
		} );
		return { w: best.w, h: best.h };
	};

	/* An edit left in this browser, offered back when the editor opens empty. */
	Editor.prototype.offerDraft = function () {
		var self = this;
		var draft = null;
		try {
			draft = JSON.parse( window.localStorage.getItem( DRAFT ) || 'null' );
		} catch ( e ) {
			draft = null;
		}
		if ( ! draft || ! draft.project || ! ( draft.project.clips || [] ).length ) {
			return;
		}
		var box = this.$( '.whd-ve__restore' );
		box.hidden = false;
		box.innerHTML = '<span>' + esc( T.restore ) + ' <em>' + esc( draft.project.name || '' ) + '</em></span>' +
			'<button type="button" class="whd-ve__btn whd-ve__btn--dark">' + esc( T.restoreIt ) + '</button>' +
			'<button type="button" class="whd-ve__btn">' + esc( T.discardIt ) + '</button>';
		var b = box.querySelectorAll( 'button' );
		b[ 0 ].addEventListener( 'click', function () {
			self.project = V.normalize( draft.project );
			self.player.setProject( self.project );
			self.fitCanvas();
			self.paintAll();
			box.hidden = true;
		} );
		b[ 1 ].addEventListener( 'click', function () {
			try {
				window.localStorage.removeItem( DRAFT );
			} catch ( e ) {}
			box.hidden = true;
		} );
	};

	/* ─────────────────────────── changes ─────────────────────────── */

	/* After any change: one step of undo, the player and screens brought up to date, a draft kept. */
	Editor.prototype.commit = function ( quiet ) {
		var snap = JSON.stringify( this.project );
		if ( this.lastSnap !== snap ) {
			if ( this.lastSnap ) {
				this.history.push( this.lastSnap );
				if ( this.history.length > 80 ) {
					this.history.shift();
				}
			}
			this.future = [];
			this.lastSnap = snap;
			this.dirty = true;
		}
		this.player.refresh();
		if ( ! quiet ) {
			this.paintAll();
		} else {
			this.paintTimeline();
			this.paintOverlay();
		}
		var self = this;
		clearTimeout( this.draftTimer );
		this.draftTimer = setTimeout( function () {
			try {
				window.localStorage.setItem( DRAFT, JSON.stringify( { at: Date.now(), project: self.project } ) );
			} catch ( e ) {}
		}, 800 );
	};

	Editor.prototype.undo = function () {
		if ( ! this.history.length ) {
			return;
		}
		this.future.push( this.lastSnap );
		this.lastSnap = this.history.pop();
		this.project = V.normalize( JSON.parse( this.lastSnap ) );
		this.player.setProject( this.project );
		this.fitCanvas();
		this.paintAll();
	};

	Editor.prototype.redo = function () {
		if ( ! this.future.length ) {
			return;
		}
		this.history.push( this.lastSnap );
		this.lastSnap = this.future.pop();
		this.project = V.normalize( JSON.parse( this.lastSnap ) );
		this.player.setProject( this.project );
		this.fitCanvas();
		this.paintAll();
	};

	/* A live change while a control is being dragged: drawn at once, kept as a step when let go. */
	Editor.prototype.live = function () {
		this.player.refresh();
		this.paintOverlay();
	};

	Editor.prototype.item = function ( sel ) {
		sel = sel || this.sel;
		if ( ! sel ) {
			return null;
		}
		var list = 'clip' === sel.kind ? this.project.clips : ( 'layer' === sel.kind ? this.project.layers : ( 'audio' === sel.kind ? this.project.audio : null ) );
		if ( ! list ) {
			return null;
		}
		for ( var i = 0; i < list.length; i++ ) {
			if ( list[ i ].id === sel.id ) {
				return list[ i ];
			}
		}
		return null;
	};

	Editor.prototype.select = function ( sel ) {
		this.sel = sel;
		this.paintInspector();
		this.paintTimeline();
		this.paintOverlay();
	};

	Editor.prototype.remove = function () {
		var sel = this.sel;
		var it = this.item();
		if ( ! it ) {
			return;
		}
		var list = 'clip' === sel.kind ? this.project.clips : ( 'layer' === sel.kind ? this.project.layers : this.project.audio );
		list.splice( list.indexOf( it ), 1 );
		this.sel = null;
		this.commit();
	};

	Editor.prototype.duplicate = function () {
		var sel = this.sel;
		var it = this.item();
		if ( ! it ) {
			return;
		}
		var copy = clone( it );
		copy.id = V.uid();
		var list = 'clip' === sel.kind ? this.project.clips : ( 'layer' === sel.kind ? this.project.layers : this.project.audio );
		if ( 'layer' === sel.kind ) {
			copy.x = Math.min( 0.95, copy.x + 0.04 );
			copy.y = Math.min( 0.95, copy.y + 0.04 );
		}
		list.splice( list.indexOf( it ) + 1, 0, copy );
		this.sel = { kind: sel.kind, id: copy.id };
		this.commit();
	};

	/* ─────────────────────────── the preview ─────────────────────────── */

	Editor.prototype.fitCanvas = function () {
		var p = this.project;
		if ( ! p ) {
			return;
		}
		var s = Math.min( 1, PREVIEW_LONG / Math.max( p.w, p.h ) );
		var w = Math.round( p.w * s ), hh = Math.round( p.h * s );
		if ( this.canvas.width !== w || this.canvas.height !== hh ) {
			this.canvas.width = w;
			this.canvas.height = hh;
		}
		var screen = this.$( '.whd-ve__screen' );
		var maxW = Math.max( 120, screen.clientWidth - 24 );
		var maxH = Math.max( 160, screen.clientHeight - 24 );
		var k = Math.min( maxW / p.w, maxH / p.h );
		this.frameEl.style.width = Math.floor( p.w * k ) + 'px';
		this.frameEl.style.height = Math.floor( p.h * k ) + 'px';
		var sizes = this.$( '.whd-ve__sizes' );
		var match = P.sizes.filter( function ( z ) {
			return z.w === p.w && z.h === p.h;
		} )[ 0 ];
		sizes.value = match ? match.key : '';
		if ( this.player ) {
			this.player.draw();
		}
		this.paintOverlay();
	};

	Editor.prototype.onTime = function ( t ) {
		this.$( '.whd-ve__now' ).textContent = time( t, true );
		this.playhead.style.transform = 'translateX(' + ( t * this.pxs ) + 'px)';
		if ( this.player && this.player.playing ) {
			// Keep the playhead in view while it plays.
			var x = t * this.pxs;
			if ( x > this.scroll.scrollLeft + this.scroll.clientWidth - 40 ) {
				this.scroll.scrollLeft = x - 80;
			}
		}
		this.paintOverlay();
	};

	Editor.prototype.onState = function ( playing ) {
		var b = this.$( '[data-act="play"]' );
		b.innerHTML = svg( playing ? 'pause' : 'play', 20 );
		b.setAttribute( 'aria-label', playing ? T.pause : T.play );
	};

	/* Which text or sticker is under the pointer on the preview, topmost first. */
	Editor.prototype.hitTest = function ( e ) {
		var rect = this.canvas.getBoundingClientRect();
		var x = ( e.clientX - rect.left ) * this.canvas.width / rect.width;
		var y = ( e.clientY - rect.top ) * this.canvas.height / rect.height;
		var boxes = this.player.renderer.boxes;
		var layers = this.project.layers;
		for ( var i = layers.length - 1; i >= 0; i-- ) {
			var b = boxes[ layers[ i ].id ];
			if ( ! b ) {
				continue;
			}
			var a = -b.rot * Math.PI / 180;
			var dx = x - b.cx, dy = y - b.cy;
			var lx = dx * Math.cos( a ) - dy * Math.sin( a );
			var ly = dx * Math.sin( a ) + dy * Math.cos( a );
			if ( Math.abs( lx ) <= Math.max( 12, b.w / 2 ) && Math.abs( ly ) <= Math.max( 12, b.h / 2 ) ) {
				return layers[ i ].id;
			}
		}
		return null;
	};

	/* The box around the chosen text or sticker, with handles to move, size and turn it. */
	Editor.prototype.paintOverlay = function () {
		var o = this.overlay;
		var it = this.sel && 'layer' === this.sel.kind ? this.item() : null;
		var b = it ? this.player.renderer.boxes[ it.id ] : null;
		if ( ! it || ! b || 'effect' === it.type ) {
			o.innerHTML = '';
			return;
		}
		var k = this.frameEl.clientWidth / this.canvas.width;
		var box = o.querySelector( '.whd-ve__sel' );
		if ( ! box ) {
			o.innerHTML = '<div class="whd-ve__sel"><span class="whd-ve__h whd-ve__h--rot" data-h="rot" title="' + esc( T.rotate ) + '"></span><span class="whd-ve__h whd-ve__h--size" data-h="size" title="' + esc( T.size ) + '"></span></div>';
			box = o.firstChild;
			var self = this;
			box.addEventListener( 'pointerdown', function ( e ) {
				var hnd = e.target.dataset.h;
				self.dragLayer( e, hnd || 'move' );
			} );
		}
		box.style.width = Math.max( 16, b.w * k ) + 'px';
		box.style.height = Math.max( 16, b.h * k ) + 'px';
		box.style.left = ( b.cx * k ) + 'px';
		box.style.top = ( b.cy * k ) + 'px';
		box.style.transform = 'translate(-50%,-50%) rotate(' + b.rot + 'deg)';
	};

	/* Dragging a text or sticker on the preview: move it, size it (corner) or turn it (top). */
	Editor.prototype.dragLayer = function ( e, mode ) {
		var self = this;
		var it = this.item();
		if ( ! it ) {
			return;
		}
		e.preventDefault();
		e.stopPropagation();
		var rect = this.canvas.getBoundingClientRect();
		var start = { x: e.clientX, y: e.clientY, lx: it.x, ly: it.y, scale: it.scale, rot: it.rot || 0, size: it.size };
		var b = this.player.renderer.boxes[ it.id ] || { cx: it.x * this.canvas.width, cy: it.y * this.canvas.height };
		var cx = rect.left + b.cx * rect.width / this.canvas.width;
		var cy = rect.top + b.cy * rect.height / this.canvas.height;
		var d0 = Math.hypot( e.clientX - cx, e.clientY - cy ) || 1;
		var a0 = Math.atan2( e.clientY - cy, e.clientX - cx );
		var moved = false;
		function move( ev ) {
			moved = true;
			if ( 'move' === mode ) {
				it.x = V.clamp( start.lx + ( ev.clientX - start.x ) / rect.width, -0.2, 1.2 );
				it.y = V.clamp( start.ly + ( ev.clientY - start.y ) / rect.height, -0.2, 1.2 );
				// A gentle snap to the middle, where things usually belong.
				if ( Math.abs( it.x - 0.5 ) < 0.012 ) {
					it.x = 0.5;
				}
				if ( Math.abs( it.y - 0.5 ) < 0.012 ) {
					it.y = 0.5;
				}
			} else if ( 'size' === mode ) {
				var d = Math.hypot( ev.clientX - cx, ev.clientY - cy );
				it.scale = V.clamp( start.scale * d / d0, 0.1, 8 );
			} else if ( 'rot' === mode ) {
				var a = Math.atan2( ev.clientY - cy, ev.clientX - cx );
				var deg = start.rot + ( a - a0 ) * 180 / Math.PI;
				deg = Math.round( deg );
				if ( Math.abs( mod360( deg ) ) < 4 ) {
					deg = 0;
				}
				it.rot = deg;
			}
			self.live();
		}
		function up() {
			window.removeEventListener( 'pointermove', move );
			window.removeEventListener( 'pointerup', up );
			if ( moved ) {
				self.commit( true );
				self.paintInspector();
			}
		}
		window.addEventListener( 'pointermove', move );
		window.addEventListener( 'pointerup', up );
	};
	function mod360( d ) {
		d = ( ( d % 360 ) + 360 ) % 360;
		return d > 180 ? d - 360 : d;
	}

	/* ─────────────────────────── painting the screens ─────────────────────────── */

	Editor.prototype.paintAll = function () {
		var p = this.project;
		this.$( '.whd-ve__name' ).value = p.name || '';
		this.$( '.whd-ve__dur' ).textContent = time( this.player.duration(), true );
		this.app.classList.toggle( 'is-empty', ! p.clips.length && ! p.layers.length );
		this.$( '[data-act="undo"]' ).disabled = ! this.history.length;
		this.$( '[data-act="redo"]' ).disabled = ! this.future.length;
		this.paintPanel();
		this.paintInspector();
		this.paintTimeline();
		this.paintOverlay();
	};

	Editor.prototype.paintPanel = function () {
		var self = this;
		var pane = this.panel;
		Array.prototype.forEach.call( this.app.querySelectorAll( '.whd-ve__tab' ), function ( b ) {
			var on = b.dataset.tab === self.tab || ( 'project' === b.dataset.tab && self.sel && 'project' === self.sel.kind );
			b.classList.toggle( 'is-on', on );
			b.setAttribute( 'aria-selected', on ? 'true' : 'false' );
		} );
		pane.innerHTML = '';
		var fn = {
			media: this.panelMedia,
			text: this.panelText,
			stickers: this.panelStickers,
			effects: this.panelEffects,
			music: this.panelMusic,
			filters: this.panelFilters
		}[ this.tab ];
		if ( fn ) {
			fn.call( this, pane );
		}
	};

	function section( pane, title ) {
		var s = h( 'section', 'whd-ve__sec' );
		if ( title ) {
			s.appendChild( h( 'h3', 'whd-ve__sec-h', esc( title ) ) );
		}
		pane.appendChild( s );
		return s;
	}

	/* ── Clips ── */
	Editor.prototype.panelMedia = function ( pane ) {
		var self = this;
		var s = section( pane, T.media );
		var btns = h( 'div', 'whd-ve__stack' );
		btns.innerHTML = '<button type="button" class="whd-ve__btn whd-ve__btn--dark" data-act="pick">' + svg( 'library', 16 ) + '<span>' + esc( T.addClips ) + '</span></button>' +
			'<button type="button" class="whd-ve__btn" data-act="upload">' + svg( 'upload', 16 ) + '<span>' + esc( T.uploadClip ) + '</span></button>';
		s.appendChild( btns );
		this.uploadNote = h( 'p', 'whd-ve__note' );
		s.appendChild( this.uploadNote );
		var recent = ( D.recent || [] ).filter( function ( c ) {
			return 0 === String( c.mime ).indexOf( 'video/' );
		} );
		if ( recent.length ) {
			var r = section( pane, T.fromBuilder );
			var grid = h( 'div', 'whd-ve__grid whd-ve__grid--clips' );
			recent.forEach( function ( c ) {
				var b = h( 'button', 'whd-ve__clipcard' );
				b.type = 'button';
				b.title = c.title;
				b.innerHTML = ( c.poster ? '<img src="' + esc( c.poster ) + '" alt="">' : '<video src="' + esc( c.url ) + '#t=0.5" preload="metadata" muted></video>' ) +
					'<span>' + esc( time( c.seconds ) ) + '</span>';
				b.addEventListener( 'click', function () {
					self.addClips( [ c ] );
				} );
				grid.appendChild( b );
			} );
			r.appendChild( grid );
		}
	};

	Editor.prototype.pickClips = function () {
		var self = this;
		if ( ! window.wp || ! wp.media ) {
			return;
		}
		var frame = wp.media( { title: T.chooseClips, button: { text: T.useThese }, library: { type: [ 'video', 'image' ] }, multiple: 'add' } );
		frame.on( 'select', function () {
			var chosen = frame.state().get( 'selection' ).map( function ( m ) {
				var a = m.toJSON();
				var s = a.sizes || {};
				return {
					id: a.id,
					url: a.url,
					mime: a.mime,
					title: a.title,
					seconds: a.fileLength ? toSeconds( a.fileLength ) : ( a.meta && a.meta.length ) || 0,
					width: a.width || ( a.meta && a.meta.width ) || 0,
					height: a.height || ( a.meta && a.meta.height ) || 0,
					poster: a.image && a.image.src && ! /\/wp-includes\//.test( a.image.src ) ? a.image.src : ( s.medium ? s.medium.url : '' )
				};
			} );
			self.addClips( chosen );
		} );
		frame.open();
	};
	function toSeconds( text ) {
		var parts = String( text ).split( ':' ).map( Number );
		var s = 0;
		parts.forEach( function ( v ) {
			s = s * 60 + ( v || 0 );
		} );
		return s;
	}

	Editor.prototype.addClips = function ( list ) {
		var self = this;
		var p = this.project;
		var wasEmpty = ! p.clips.length;
		if ( wasEmpty && list[ 0 ] && list[ 0 ].width && list[ 0 ].height && ! this.opts.size ) {
			var s = this.sizeFor( list[ 0 ].width, list[ 0 ].height );
			p.w = s.w;
			p.h = s.h;
		}
		list.forEach( function ( c ) {
			if ( 0 === String( c.mime ).indexOf( 'image/' ) && 'image/gif' !== c.mime ) {
				p.clips.push( V.clipDefaults( { att: c.id, url: c.url, mime: c.mime, title: c.title, poster: c.poster || c.url, seconds: 3, dur: 3 } ) );
			} else {
				p.clips.push( V.clipDefaults( { att: c.id, url: c.url, mime: c.mime || 'video/mp4', title: c.title, poster: c.poster || '', seconds: c.seconds || 5, out: c.seconds || 5 } ) );
			}
		} );
		if ( ! p.name && list[ 0 ] ) {
			p.name = list[ 0 ].title || '';
		}
		this.normalize();
		this.commit();
		this.fitCanvas();
		this.probeClips();
		void self;
	};

	Editor.prototype.normalize = function () {
		this.project = V.normalize( this.project );
	};

	/* Files from the computer go to the media library first, in pieces, then onto the timeline. */
	Editor.prototype.uploadClips = function ( files ) {
		var self = this;
		Array.prototype.forEach.call( files || [], function ( file ) {
			self.uploadNote && ( self.uploadNote.textContent = T.uploadingTo + ' ' + file.name );
			V.upload( file, { title: file.name.replace( /\.[^.]+$/, '' ), kind: 'source' }, function ( f ) {
				if ( self.uploadNote ) {
					self.uploadNote.textContent = T.uploadingTo + ' ' + Math.round( f * 100 ) + '%';
				}
			} ).then( function ( clip ) {
				if ( self.uploadNote ) {
					self.uploadNote.textContent = '';
				}
				self.addClips( [ clip ] );
			} ).catch( function ( e ) {
				if ( self.uploadNote ) {
					self.uploadNote.textContent = e.message;
				}
			} );
		} );
	};

	/* ── Text ── */
	Editor.prototype.panelText = function ( pane ) {
		var self = this;
		var s = section( pane, T.text );
		var grid = h( 'div', 'whd-ve__grid whd-ve__grid--text' );
		P.texts.forEach( function ( t ) {
			var b = h( 'button', 'whd-ve__textcard' );
			b.type = 'button';
			var bg = 'box' === t.style || 'pill' === t.style || 'glass' === t.style ? t.color2 : 'transparent';
			b.innerHTML = '<span class="whd-ve__textcard-in" style="font-family:\'' + esc( t.font ) + '\';font-weight:' + ( t.weight || 400 ) + ';' + ( t.italic ? 'font-style:italic;' : '' ) + 'color:' + esc( t.color ) + ';background:' + esc( bg ) + ';' + ( 'pill' === t.style ? 'border-radius:99px;' : '' ) + ( t.spacing ? 'letter-spacing:' + ( t.spacing / 4 ) + 'px;' : '' ) + '">' + esc( String( t.text ).replace( '{price}', D.currency || '$' ) ) + '</span><small>' + esc( t.name ) + '</small>';
			b.addEventListener( 'click', function () {
				self.addText( t );
			} );
			grid.appendChild( b );
		} );
		s.appendChild( grid );
	};

	Editor.prototype.addText = function ( preset ) {
		var t = this.player.t;
		var end = Math.min( this.player.duration(), t + 3 );
		if ( end - t < 0.5 ) {
			t = Math.max( 0, end - 3 );
		}
		var l = Object.assign( clone( preset ), { id: V.uid(), type: 'text', start: t, end: Math.max( t + 0.5, end ), x: preset.x || 0.5, y: preset.y || 0.5 } );
		delete l.name;
		this.project.layers.push( l );
		this.normalize();
		this.sel = { kind: 'layer', id: l.id };
		this.commit();
	};

	/* ── Stickers ── */
	Editor.prototype.panelStickers = function ( pane ) {
		var self = this;
		if ( ( D.stickers || [] ).length ) {
			var s = section( pane, T.sticker3d );
			var grid = h( 'div', 'whd-ve__grid whd-ve__grid--stickers' );
			D.stickers.forEach( function ( st ) {
				var b = h( 'button', 'whd-ve__stickercard' );
				b.type = 'button';
				b.title = st.label;
				b.innerHTML = '<img src="' + esc( st.url ) + '" alt="' + esc( st.label ) + '" loading="lazy">';
				b.addEventListener( 'click', function () {
					self.addSticker( { src: st.url, loop: st.anim || 'wobble' } );
				} );
				grid.appendChild( b );
			} );
			s.appendChild( grid );
		}
		var e = section( pane, T.emoji );
		var eg = h( 'div', 'whd-ve__grid whd-ve__grid--emoji' );
		P.emoji.forEach( function ( em ) {
			var b = h( 'button', 'whd-ve__emoji', esc( em ) );
			b.type = 'button';
			b.addEventListener( 'click', function () {
				self.addSticker( { emoji: em, loop: 'float' } );
			} );
			eg.appendChild( b );
		} );
		e.appendChild( eg );
		var l = section( pane, T.logos );
		var lg = h( 'div', 'whd-ve__grid whd-ve__grid--stickers' );
		( D.logos || [] ).forEach( function ( logo ) {
			var b = h( 'button', 'whd-ve__stickercard whd-ve__stickercard--logo' );
			b.type = 'button';
			b.title = logo.name;
			b.innerHTML = '<img src="' + esc( logo.url ) + '" alt="' + esc( logo.name ) + '">';
			b.addEventListener( 'click', function () {
				self.addSticker( { src: logo.url, loop: 'none', size: 0.3, anim: { in: 'fade', out: 'fade', loop: 'none' } } );
			} );
			lg.appendChild( b );
		} );
		l.appendChild( lg );
		var lib = h( 'button', 'whd-ve__btn', svg( 'library', 16 ) + '<span>' + esc( T.fromLibrary ) + '</span>' );
		lib.type = 'button';
		lib.addEventListener( 'click', function () {
			if ( ! window.wp || ! wp.media ) {
				return;
			}
			var frame = wp.media( { title: T.fromLibrary, library: { type: 'image' }, multiple: false } );
			frame.on( 'select', function () {
				var a = frame.state().get( 'selection' ).first().toJSON();
				self.addSticker( { src: a.url, loop: 'none', size: 0.35, anim: { in: 'pop', out: 'fade', loop: 'none' } } );
			} );
			frame.open();
		} );
		l.appendChild( lib );
	};

	Editor.prototype.addSticker = function ( o ) {
		var t = this.player.t;
		var end = Math.min( this.player.duration(), t + 3 );
		if ( end - t < 0.5 ) {
			t = Math.max( 0, end - 3 );
		}
		var l = {
			id: V.uid(),
			type: 'sticker',
			src: o.src || '',
			emoji: o.emoji || '',
			size: o.size || 0.22,
			x: 0.5 + ( Math.random() - 0.5 ) * 0.2,
			y: 0.45 + ( Math.random() - 0.5 ) * 0.2,
			start: t,
			end: Math.max( t + 0.5, end ),
			anim: o.anim || { in: 'pop', out: 'pop', loop: o.loop || 'none', dur: 0.4 }
		};
		this.project.layers.push( l );
		this.normalize();
		this.sel = { kind: 'layer', id: l.id };
		if ( l.src ) {
			var self = this;
			V.loadImage( l.src ).then( function () {
				self.player.draw();
				self.paintOverlay();
			} );
		}
		this.commit();
	};

	/* ── Effects ── */
	Editor.prototype.panelEffects = function ( pane ) {
		var self = this;
		var s = section( pane, T.effects );
		var grid = h( 'div', 'whd-ve__grid whd-ve__grid--fx' );
		P.effects.forEach( function ( fx ) {
			var b = h( 'button', 'whd-ve__fxcard', '<span class="whd-ve__fxcard-i" aria-hidden="true">' + esc( fx.icon ) + '</span><span>' + esc( fx.name ) + '</span>' );
			b.type = 'button';
			b.addEventListener( 'click', function () {
				self.addEffect( fx );
			} );
			grid.appendChild( b );
		} );
		s.appendChild( grid );
	};

	Editor.prototype.addEffect = function ( fx ) {
		var dur = this.player.duration();
		var whole = [ 'vignette', 'grain', 'letterbox', 'lightleak', 'glow', 'bokeh' ].indexOf( fx.key ) !== -1;
		var t = whole ? 0 : this.player.t;
		var end = whole ? dur : Math.min( dur, t + ( 'flash' === fx.key || 'zoompunch' === fx.key ? 1 : 3 ) );
		if ( end - t < 0.3 ) {
			t = Math.max( 0, end - 3 );
		}
		var l = { id: V.uid(), type: 'effect', effect: fx.key, intensity: 0.6, color: '', start: t, end: Math.max( t + 0.3, end ) };
		this.project.layers.push( l );
		this.normalize();
		this.sel = { kind: 'layer', id: l.id };
		this.commit();
	};

	/* ── Music and sound effects ── */
	Editor.prototype.panelMusic = function ( pane ) {
		var self = this;
		var tabs = h( 'div', 'whd-ve__subtabs' );
		[ [ 'library', T.library ], [ 'search', T.search ], [ 'mine', T.mine ] ].forEach( function ( t ) {
			var b = h( 'button', 'whd-ve__subtab' + ( self.musicTab === t[ 0 ] ? ' is-on' : '' ), esc( t[ 1 ] ) );
			b.type = 'button';
			b.addEventListener( 'click', function () {
				self.musicTab = t[ 0 ];
				self.paintPanel();
			} );
			tabs.appendChild( b );
		} );
		pane.appendChild( tabs );
		if ( 'search' === this.musicTab ) {
			this.musicSearch( pane );
		} else if ( 'mine' === this.musicTab ) {
			this.musicMine( pane );
		} else {
			this.musicLibrary( pane );
		}
	};

	Editor.prototype.soundRow = function ( item, kind, onAdd ) {
		var self = this;
		var row = h( 'div', 'whd-ve__sound' );
		row.innerHTML = '<button type="button" class="whd-ve__sound-play" aria-label="' + esc( T.preview ) + '">' + svg( 'play', 14 ) + '</button>' +
			'<span class="whd-ve__sound-t"><strong>' + esc( item.title ) + '</strong><small>' + esc( [ item.by, item.seconds ? time( item.seconds ) : '', item.licence ].filter( Boolean ).join( ' · ' ) ) + '</small></span>' +
			'<button type="button" class="whd-ve__sound-add">' + esc( T.add ) + '</button>';
		var play = row.querySelector( '.whd-ve__sound-play' );
		play.addEventListener( 'click', function () {
			self.previewSound( item.preview || item.url, play );
		} );
		row.querySelector( '.whd-ve__sound-add' ).addEventListener( 'click', function () {
			var b = this;
			b.disabled = true;
			b.textContent = T.adding;
			Promise.resolve( onAdd ? onAdd( item ) : item ).then( function ( ready ) {
				self.addAudio( ready, kind );
				b.textContent = T.add;
				b.disabled = false;
			} ).catch( function ( e ) {
				b.textContent = T.add;
				b.disabled = false;
				window.alert( ( T.importFailed || '' ) + ( e && e.message ? ' ' + e.message : '' ) );
			} );
		} );
		return row;
	};

	Editor.prototype.previewSound = function ( url, btn ) {
		if ( this.previewing && this.previewing.url === url ) {
			this.stopPreview();
			return;
		}
		this.stopPreview();
		var a = new Audio( url );
		a.volume = 0.8;
		a.play().catch( function () {} );
		a.addEventListener( 'ended', this.stopPreview.bind( this ) );
		this.previewing = { url: url, audio: a, btn: btn };
		btn.innerHTML = svg( 'pause', 14 );
		btn.classList.add( 'is-on' );
	};

	Editor.prototype.stopPreview = function () {
		if ( this.previewing ) {
			this.previewing.audio.pause();
			this.previewing.btn.innerHTML = svg( 'play', 14 );
			this.previewing.btn.classList.remove( 'is-on' );
			this.previewing = null;
		}
	};

	Editor.prototype.musicLibrary = function ( pane ) {
		var self = this;
		var lib = D.sounds || { music: [], fx: [] };
		var groups = {};
		( lib.music || [] ).forEach( function ( m ) {
			( groups[ m.group ] = groups[ m.group ] || [] ).push( m );
		} );
		Object.keys( groups ).forEach( function ( g ) {
			var s = section( pane, g );
			groups[ g ].forEach( function ( m ) {
				s.appendChild( self.soundRow( m, 'music' ) );
			} );
		} );
		var fx = section( pane, T.kindFx );
		( lib.fx || [] ).forEach( function ( m ) {
			fx.appendChild( self.soundRow( m, 'sfx' ) );
		} );
		var note = h( 'p', 'whd-ve__note', esc( T.cc0 ) );
		pane.appendChild( note );
	};

	Editor.prototype.musicSearch = function ( pane ) {
		var self = this;
		var s = section( pane );
		s.innerHTML = '<p class="whd-ve__note">' + esc( T.searchHint ) + '</p>' +
			'<form class="whd-ve__search"><input type="search" placeholder="' + esc( T.searchPh ) + '" aria-label="' + esc( T.search ) + '">' +
			'<select aria-label="' + esc( T.kindAny ) + '"><option value="">' + esc( T.kindAny ) + '</option><option value="music">' + esc( T.kindMusic ) + '</option><option value="sound_effect">' + esc( T.kindFx ) + '</option></select>' +
			'<button type="submit" class="whd-ve__btn whd-ve__btn--dark" aria-label="' + esc( T.search ) + '">' + svg( 'search', 16 ) + '</button></form>' +
			'<div class="whd-ve__results"></div>';
		var form = s.querySelector( 'form' );
		var input = form.querySelector( 'input' );
		var kind = form.querySelector( 'select' );
		var out = s.querySelector( '.whd-ve__results' );
		if ( this.lastSearch ) {
			input.value = this.lastSearch.q;
			kind.value = this.lastSearch.kind;
		}
		function run( page, append ) {
			var q = input.value.trim();
			if ( ! q ) {
				return;
			}
			self.lastSearch = { q: q, kind: kind.value };
			if ( ! append ) {
				out.innerHTML = '<p class="whd-ve__note">' + esc( T.searching ) + '</p>';
			}
			V.call( 'sounds/search', { q: q, kind: kind.value, page: page } ).then( function ( r ) {
				if ( ! append ) {
					out.innerHTML = '';
				}
				var more = out.querySelector( '.whd-ve__more' );
				if ( more ) {
					more.remove();
				}
				if ( ! r.results.length && ! append ) {
					out.innerHTML = '<p class="whd-ve__note">' + esc( T.noResults ) + '</p>';
					return;
				}
				r.results.forEach( function ( item ) {
					out.appendChild( self.soundRow( item, item.seconds && item.seconds < 12 ? 'sfx' : 'music', function ( it ) {
						return V.call( 'sounds/import', { id: it.id } ).then( function ( saved ) {
							return Object.assign( {}, it, saved );
						} );
					} ) );
				} );
				if ( r.more ) {
					var b = h( 'button', 'whd-ve__btn whd-ve__more', esc( T.more ) );
					b.type = 'button';
					b.addEventListener( 'click', function () {
						run( page + 1, true );
					} );
					out.appendChild( b );
				}
			} ).catch( function ( e ) {
				out.innerHTML = '<p class="whd-ve__note is-bad">' + esc( e.message ) + '</p>';
			} );
		}
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			run( 1, false );
		} );
		setTimeout( function () {
			input.focus();
		}, 0 );
	};

	Editor.prototype.musicMine = function ( pane ) {
		var self = this;
		var s = section( pane );
		var up = h( 'button', 'whd-ve__btn', svg( 'upload', 16 ) + '<span>' + esc( T.uploadAudio ) + '</span>' );
		up.type = 'button';
		up.addEventListener( 'click', function () {
			self.$( '.whd-ve__afile' ).click();
		} );
		s.appendChild( up );
		this.audioNote = h( 'p', 'whd-ve__note' );
		s.appendChild( this.audioNote );
		var list = h( 'div', 'whd-ve__results', '<p class="whd-ve__note">' + esc( T.searching ) + '</p>' );
		s.appendChild( list );
		V.call( 'sounds/mine', {} ).then( function ( items ) {
			list.innerHTML = '';
			if ( ! items.length ) {
				list.innerHTML = '<p class="whd-ve__note">' + esc( T.noResults ) + '</p>';
			}
			items.forEach( function ( item ) {
				list.appendChild( self.soundRow( item, item.seconds && item.seconds < 12 ? 'sfx' : 'music' ) );
			} );
		} ).catch( function ( e ) {
			list.innerHTML = '<p class="whd-ve__note is-bad">' + esc( e.message ) + '</p>';
		} );
	};

	Editor.prototype.uploadAudio = function ( file ) {
		var self = this;
		if ( ! file ) {
			return;
		}
		V.upload( file, { title: file.name.replace( /\.[^.]+$/, '' ), kind: 'source' }, function ( f ) {
			if ( self.audioNote ) {
				self.audioNote.textContent = T.uploadingTo + ' ' + Math.round( f * 100 ) + '%';
			}
		} ).then( function ( saved ) {
			if ( self.audioNote ) {
				self.audioNote.textContent = '';
			}
			self.addAudio( { url: saved.url, title: saved.title, seconds: saved.seconds, id: saved.id }, saved.seconds && saved.seconds < 12 ? 'sfx' : 'music' );
			self.paintPanel();
		} ).catch( function ( e ) {
			if ( self.audioNote ) {
				self.audioNote.textContent = e.message;
			}
		} );
	};

	/* Music under the whole video, looped and faded out; a sound effect at the playhead. */
	Editor.prototype.addAudio = function ( item, kind ) {
		var dur = this.player.duration();
		var music = 'music' === kind;
		var len = item.seconds || 0;
		var a = {
			id: V.uid(),
			src: item.url,
			att: item.id || 0,
			name: item.title || '',
			kind: music ? 'music' : 'sfx',
			start: music ? 0 : this.player.t,
			offset: 0,
			fileDur: len,
			dur: music ? dur : Math.max( 0.3, Math.min( len || 2, dur - this.player.t ) ),
			volume: music ? 0.7 : 1,
			fadeIn: music ? 0.4 : 0,
			fadeOut: music ? Math.min( 1.5, dur / 4 ) : 0,
			loop: music
		};
		this.stopPreview();
		this.project.audio.push( a );
		this.normalize();
		this.sel = { kind: 'audio', id: a.id };
		this.commit();
	};

	/* ── Filters ── */
	Editor.prototype.panelFilters = function ( pane ) {
		var self = this;
		var clip = this.sel && 'clip' === this.sel.kind ? this.item() : this.clipAt( this.player.t );
		var s = section( pane, T.filters );
		if ( ! clip ) {
			s.appendChild( h( 'p', 'whd-ve__note', esc( T.saveFirst ) ) );
			return;
		}
		var grid = h( 'div', 'whd-ve__grid whd-ve__grid--filters' );
		var src = this.canvas;
		P.filters.forEach( function ( f ) {
			var b = h( 'button', 'whd-ve__filtercard' + ( clip.filter === f.key ? ' is-on' : '' ) );
			b.type = 'button';
			var c = document.createElement( 'canvas' );
			c.width = 96;
			c.height = Math.round( 96 * src.height / src.width );
			var g = c.getContext( '2d' );
			g.filter = f.css || 'none';
			g.drawImage( src, 0, 0, c.width, c.height );
			if ( f.tint ) {
				g.filter = 'none';
				g.globalCompositeOperation = 'soft-light';
				g.fillStyle = f.tint;
				g.fillRect( 0, 0, c.width, c.height );
			}
			b.appendChild( c );
			b.appendChild( h( 'span', '', esc( f.name ) ) );
			b.addEventListener( 'click', function () {
				clip.filter = f.key;
				self.sel = { kind: 'clip', id: clip.id };
				self.commit();
			} );
			grid.appendChild( b );
		} );
		s.appendChild( grid );
		var all = h( 'button', 'whd-ve__btn', esc( T.applyAll ) );
		all.type = 'button';
		all.addEventListener( 'click', function () {
			self.project.clips.forEach( function ( c ) {
				c.filter = clip.filter;
				c.adjust = clone( clip.adjust );
			} );
			self.commit();
		} );
		s.appendChild( all );
	};

	Editor.prototype.clipAt = function ( t ) {
		var vis = V.visible( this.player.tl, t );
		return vis.length ? vis[ vis.length - 1 ].clip : ( this.project.clips[ 0 ] || null );
	};

	/* ─────────────────────────── the inspector ─────────────────────────── */

	/*
	 * Controls built from a list. Each: { type, label, get, set, min, max, step, options, unit }. Moving a
	 * control draws at once; letting go keeps it as one step of undo.
	 */
	Editor.prototype.controls = function ( box, list ) {
		var self = this;
		list.forEach( function ( c ) {
			if ( ! c ) {
				return;
			}
			if ( 'heading' === c.type ) {
				box.appendChild( h( 'h4', 'whd-ve__ih', esc( c.label ) ) );
				return;
			}
			if ( 'buttons' === c.type ) {
				var row = h( 'div', 'whd-ve__btnrow' );
				c.buttons.forEach( function ( b ) {
					var e = h( 'button', 'whd-ve__btn' + ( b.danger ? ' whd-ve__btn--danger' : '' ), ( b.icon ? svg( b.icon, 15 ) : '' ) + '<span>' + esc( b.label ) + '</span>' );
					e.type = 'button';
					e.addEventListener( 'click', b.run );
					row.appendChild( e );
				} );
				box.appendChild( row );
				return;
			}
			var wrap = h( 'label', 'whd-ve__ctl whd-ve__ctl--' + c.type );
			var val = c.get();
			var input;
			if ( 'range' === c.type ) {
				wrap.innerHTML = '<span class="whd-ve__ctl-l">' + esc( c.label ) + ' <output>' + esc( show( val, c ) ) + '</output></span>';
				input = document.createElement( 'input' );
				input.type = 'range';
				input.min = c.min;
				input.max = c.max;
				input.step = c.step || 0.01;
				input.value = val;
			} else if ( 'select' === c.type ) {
				wrap.innerHTML = '<span class="whd-ve__ctl-l">' + esc( c.label ) + '</span>';
				input = document.createElement( 'select' );
				c.options.forEach( function ( o ) {
					var op = document.createElement( 'option' );
					op.value = o[ 0 ];
					op.textContent = o[ 1 ];
					if ( o[ 2 ] ) {
						op.style.fontFamily = o[ 2 ];
					}
					input.appendChild( op );
				} );
				input.value = val;
			} else if ( 'color' === c.type ) {
				wrap.innerHTML = '<span class="whd-ve__ctl-l">' + esc( c.label ) + '</span>';
				input = document.createElement( 'input' );
				input.type = 'color';
				input.value = toHex( val );
				var sw = h( 'span', 'whd-ve__swatches' );
				( D.brand && D.brand.colors || [] ).forEach( function ( col ) {
					var s = h( 'button', 'whd-ve__swatch' );
					s.type = 'button';
					s.style.background = col;
					s.title = col;
					s.addEventListener( 'click', function ( e ) {
						e.preventDefault();
						c.set( col );
						input.value = toHex( col );
						self.commit( true );
					} );
					sw.appendChild( s );
				} );
				wrap.appendChild( input );
				wrap.appendChild( sw );
			} else if ( 'textarea' === c.type ) {
				wrap.innerHTML = '<span class="whd-ve__ctl-l">' + esc( c.label ) + '</span>';
				input = document.createElement( 'textarea' );
				input.rows = 3;
				input.value = val;
			} else if ( 'number' === c.type ) {
				wrap.innerHTML = '<span class="whd-ve__ctl-l">' + esc( c.label ) + '</span>';
				input = document.createElement( 'input' );
				input.type = 'number';
				input.min = c.min;
				input.max = c.max;
				input.step = c.step || 0.1;
				input.value = Math.round( val * 100 ) / 100;
			} else if ( 'check' === c.type ) {
				input = document.createElement( 'input' );
				input.type = 'checkbox';
				input.checked = !! val;
				wrap.appendChild( input );
				wrap.appendChild( h( 'span', 'whd-ve__ctl-l', esc( c.label ) ) );
			} else if ( 'text' === c.type ) {
				wrap.innerHTML = '<span class="whd-ve__ctl-l">' + esc( c.label ) + '</span>';
				input = document.createElement( 'input' );
				input.type = 'text';
				input.value = val;
			}
			if ( input.parentNode !== wrap ) {
				wrap.appendChild( input );
			}
			function read() {
				if ( 'check' === c.type ) {
					return input.checked;
				}
				if ( 'range' === c.type || 'number' === c.type ) {
					return num( input.value, val );
				}
				return input.value;
			}
			input.addEventListener( 'input', function () {
				c.set( read() );
				var out = wrap.querySelector( 'output' );
				if ( out ) {
					out.textContent = show( read(), c );
				}
				self.live();
			} );
			input.addEventListener( 'change', function () {
				c.set( read() );
				self.commit( true );
				if ( c.repaint ) {
					self.paintInspector();
				}
			} );
			box.appendChild( wrap );
		} );
	};
	function show( v, c ) {
		if ( c.unit === '%' ) {
			return Math.round( v * 100 ) + '%';
		}
		if ( c.unit === 's' ) {
			return ( Math.round( v * 10 ) / 10 ) + ' s';
		}
		if ( c.unit === '°' ) {
			return Math.round( v ) + '°';
		}
		if ( c.unit === '×' ) {
			return ( Math.round( v * 100 ) / 100 ) + '×';
		}
		return Math.round( v * 100 ) / 100;
	}
	function toHex( c ) {
		if ( /^#[0-9a-f]{6}$/i.test( c ) ) {
			return c;
		}
		if ( /^#[0-9a-f]{3}$/i.test( c ) ) {
			return '#' + c[ 1 ] + c[ 1 ] + c[ 2 ] + c[ 2 ] + c[ 3 ] + c[ 3 ];
		}
		var m = String( c ).match( /rgba?\((\d+),\s*(\d+),\s*(\d+)/ );
		if ( m ) {
			return '#' + [ m[ 1 ], m[ 2 ], m[ 3 ] ].map( function ( x ) {
				return ( '0' + parseInt( x, 10 ).toString( 16 ) ).slice( -2 );
			} ).join( '' );
		}
		return '#000000';
	}
	function opts( list ) {
		return list.map( function ( o ) {
			return [ o.key, o.name ];
		} );
	}

	Editor.prototype.paintInspector = function () {
		var box = this.inspect;
		box.innerHTML = '';
		var it = this.item();
		var sel = this.sel;
		if ( sel && 'project' === sel.kind ) {
			this.inspectProject( box );
		} else if ( ! it ) {
			box.appendChild( h( 'p', 'whd-ve__note', esc( T.nothingSel ) ) );
			this.inspectProject( box );
		} else if ( 'clip' === sel.kind ) {
			this.inspectClip( box, it );
		} else if ( 'audio' === sel.kind ) {
			this.inspectAudio( box, it );
		} else if ( 'text' === it.type ) {
			this.inspectText( box, it );
		} else if ( 'sticker' === it.type ) {
			this.inspectSticker( box, it );
		} else if ( 'effect' === it.type ) {
			this.inspectEffect( box, it );
		}
		this.paintPanelTabs();
	};

	Editor.prototype.paintPanelTabs = function () {
		var self = this;
		Array.prototype.forEach.call( this.app.querySelectorAll( '.whd-ve__tab' ), function ( b ) {
			var on = b.dataset.tab === self.tab && ! ( self.sel && 'project' === self.sel.kind ) || ( 'project' === b.dataset.tab && self.sel && 'project' === self.sel.kind );
			b.classList.toggle( 'is-on', on );
		} );
	};

	Editor.prototype.timing = function ( it ) {
		var self = this;
		var dur = this.player.duration();
		return [
			{ type: 'number', label: T.start, min: 0, max: dur, step: 0.1, get: function () {
				return it.start;
			}, set: function ( v ) {
				var len = it.end - it.start;
				it.start = V.clamp( v, 0, Math.max( 0, dur - 0.1 ) );
				it.end = Math.min( dur, it.start + len );
			} },
			{ type: 'number', label: T.end, min: 0.1, max: dur, step: 0.1, get: function () {
				return it.end;
			}, set: function ( v ) {
				it.end = V.clamp( v, it.start + 0.1, dur );
			} },
			{ type: 'buttons', buttons: [
				{ label: T.wholeVideo, run: function () {
					it.start = 0;
					it.end = dur;
					self.commit();
				} },
				{ label: T.fromHere, run: function () {
					var len = it.end - it.start;
					it.start = self.player.t;
					it.end = Math.min( dur, it.start + len );
					self.commit();
				} }
			] }
		];
	};

	Editor.prototype.animControls = function ( it, textual ) {
		var ins = textual ? P.animsIn : P.animsIn.filter( function ( a ) {
			return [ 'rise', 'typewriter' ].indexOf( a.key ) === -1;
		} );
		return [
			{ type: 'heading', label: T.animIn + ' · ' + T.animOut },
			{ type: 'select', label: T.animIn, options: opts( ins ), get: function () {
				return it.anim.in;
			}, set: function ( v ) {
				it.anim.in = v;
			} },
			{ type: 'select', label: T.animOut, options: opts( P.animsOut ), get: function () {
				return it.anim.out;
			}, set: function ( v ) {
				it.anim.out = v;
			} },
			{ type: 'select', label: T.animLoop, options: opts( P.loops ), get: function () {
				return it.anim.loop;
			}, set: function ( v ) {
				it.anim.loop = v;
			} },
			{ type: 'range', label: T.animTime, min: 0.1, max: 2, step: 0.05, unit: 's', get: function () {
				return it.anim.dur;
			}, set: function ( v ) {
				it.anim.dur = v;
			} }
		];
	};

	Editor.prototype.common = function () {
		var self = this;
		return { type: 'buttons', buttons: [
			{ label: T.duplicate, icon: 'copy', run: function () {
				self.duplicate();
			} },
			{ label: T.delete, icon: 'trash', danger: true, run: function () {
				self.remove();
			} }
		] };
	};

	Editor.prototype.inspectText = function ( box, it ) {
		box.appendChild( h( 'h3', 'whd-ve__sec-h', esc( T.text ) ) );
		var fonts = ( D.fonts || [] ).map( function ( f ) {
			return [ f, f, '"' + f + '"' ];
		} );
		this.controls( box, [
			{ type: 'textarea', label: T.yourText, get: function () {
				return it.text;
			}, set: function ( v ) {
				it.text = v;
			} },
			{ type: 'select', label: T.font, options: fonts, get: function () {
				return it.font;
			}, set: function ( v ) {
				it.font = v;
			}, repaint: true },
			{ type: 'select', label: T.weight, options: P.weights.map( function ( w ) {
				return [ String( w ), String( w ) ];
			} ), get: function () {
				return String( it.weight );
			}, set: function ( v ) {
				it.weight = parseInt( v, 10 );
			} },
			{ type: 'select', label: T.style, options: opts( P.textStyles ), get: function () {
				return it.style;
			}, set: function ( v ) {
				it.style = v;
			} },
			{ type: 'color', label: T.color, get: function () {
				return it.color;
			}, set: function ( v ) {
				it.color = v;
			} },
			{ type: 'color', label: T.accent, get: function () {
				return it.color2;
			}, set: function ( v ) {
				it.color2 = v;
			} },
			{ type: 'range', label: T.size, min: 0.015, max: 0.25, step: 0.001, unit: '%', get: function () {
				return it.size;
			}, set: function ( v ) {
				it.size = v;
			} },
			{ type: 'select', label: T.align, options: [ [ 'left', '←' ], [ 'center', '↔' ], [ 'right', '→' ] ], get: function () {
				return it.align;
			}, set: function ( v ) {
				it.align = v;
			} },
			{ type: 'range', label: T.spacing, min: -4, max: 30, step: 0.5, get: function () {
				return it.spacing;
			}, set: function ( v ) {
				it.spacing = v;
			} },
			{ type: 'check', label: T.caps, get: function () {
				return it.upper;
			}, set: function ( v ) {
				it.upper = v;
			} },
			{ type: 'check', label: T.italic, get: function () {
				return it.italic;
			}, set: function ( v ) {
				it.italic = v;
			} },
			{ type: 'range', label: T.rotate, min: -180, max: 180, step: 1, unit: '°', get: function () {
				return it.rot;
			}, set: function ( v ) {
				it.rot = v;
			} },
			{ type: 'range', label: T.opacity, min: 0, max: 1, step: 0.01, unit: '%', get: function () {
				return it.opacity;
			}, set: function ( v ) {
				it.opacity = v;
			} }
		].concat( this.animControls( it, true ) ).concat( [ { type: 'heading', label: T.start + ' · ' + T.end } ] ).concat( this.timing( it ) ).concat( [ this.common() ] ) );
		// A font chosen: draw again once its letters have arrived.
		var self = this;
		if ( document.fonts && document.fonts.load ) {
			document.fonts.load( '700 40px "' + it.font + '"', it.text || 'A' ).then( function () {
				self.player.draw();
				self.paintOverlay();
			} ).catch( function () {} );
		}
	};

	Editor.prototype.inspectSticker = function ( box, it ) {
		box.appendChild( h( 'h3', 'whd-ve__sec-h', esc( T.stickers ) ) );
		this.controls( box, [
			{ type: 'range', label: T.size, min: 0.03, max: 0.9, step: 0.005, unit: '%', get: function () {
				return it.size;
			}, set: function ( v ) {
				it.size = v;
			} },
			{ type: 'range', label: T.rotate, min: -180, max: 180, step: 1, unit: '°', get: function () {
				return it.rot;
			}, set: function ( v ) {
				it.rot = v;
			} },
			{ type: 'range', label: T.opacity, min: 0, max: 1, step: 0.01, unit: '%', get: function () {
				return it.opacity;
			}, set: function ( v ) {
				it.opacity = v;
			} },
			{ type: 'check', label: T.flip, get: function () {
				return it.flip;
			}, set: function ( v ) {
				it.flip = v;
			} }
		].concat( this.animControls( it, false ) ).concat( [ { type: 'heading', label: T.start + ' · ' + T.end } ] ).concat( this.timing( it ) ).concat( [ this.common() ] ) );
	};

	Editor.prototype.inspectEffect = function ( box, it ) {
		box.appendChild( h( 'h3', 'whd-ve__sec-h', esc( T.effects ) ) );
		var def = V.find( P.effects, it.effect ) || {};
		this.controls( box, [
			{ type: 'select', label: T.effects, options: opts( P.effects ), repaint: true, get: function () {
				return it.effect;
			}, set: function ( v ) {
				it.effect = v;
				it.color = '';
			} },
			{ type: 'range', label: T.intensity, min: 0.05, max: 1, step: 0.01, unit: '%', get: function () {
				return it.intensity;
			}, set: function ( v ) {
				it.intensity = v;
			} },
			def.camera || 'grain' === it.effect ? null : { type: 'color', label: T.color, get: function () {
				return it.color || def.color || '#ffffff';
			}, set: function ( v ) {
				it.color = v;
			} },
			{ type: 'heading', label: T.start + ' · ' + T.end }
		].concat( this.timing( it ) ).concat( [ this.common() ] ) );
	};

	Editor.prototype.inspectClip = function ( box, c ) {
		var self = this;
		var image = 'image' === c.kind;
		box.appendChild( h( 'h3', 'whd-ve__sec-h', esc( c.title || T.track_video ) ) );
		var list = [];
		if ( image ) {
			list.push( { type: 'range', label: T.duration, min: 0.5, max: 20, step: 0.1, unit: 's', get: function () {
				return c.dur;
			}, set: function ( v ) {
				c.dur = v;
			} } );
		} else {
			list.push(
				{ type: 'range', label: T.trimIn, min: 0, max: c.seconds, step: 0.05, unit: 's', get: function () {
					return c.in;
				}, set: function ( v ) {
					c.in = V.clamp( v, 0, c.out - 0.1 );
				} },
				{ type: 'range', label: T.trimOut, min: 0, max: c.seconds, step: 0.05, unit: 's', get: function () {
					return c.out;
				}, set: function ( v ) {
					c.out = V.clamp( v, c.in + 0.1, c.seconds );
				} },
				{ type: 'range', label: T.speed, min: 0.25, max: 4, step: 0.05, unit: '×', get: function () {
					return c.speed;
				}, set: function ( v ) {
					c.speed = v;
				} },
				{ type: 'range', label: T.volume, min: 0, max: 1, step: 0.01, unit: '%', get: function () {
					return c.volume;
				}, set: function ( v ) {
					c.volume = v;
				} },
				{ type: 'check', label: T.mute, get: function () {
					return c.muted;
				}, set: function ( v ) {
					c.muted = v;
				} },
				{ type: 'check', label: T.reverse, get: function () {
					return c.reverse;
				}, set: function ( v ) {
					c.reverse = v;
				} }
			);
		}
		list.push(
			{ type: 'heading', label: T.fit },
			{ type: 'select', label: T.fit, options: [ [ 'cover', T.fill ], [ 'contain', T.whole ], [ 'blur', T.whole + ' + blur' ] ], get: function () {
				return c.fit;
			}, set: function ( v ) {
				c.fit = v;
			} },
			{ type: 'range', label: T.zoom, min: 0.5, max: 3, step: 0.01, unit: '×', get: function () {
				return c.zoom;
			}, set: function ( v ) {
				c.zoom = v;
			} },
			{ type: 'range', label: T.posX, min: 0, max: 1, step: 0.01, unit: '%', get: function () {
				return c.px;
			}, set: function ( v ) {
				c.px = v;
			} },
			{ type: 'range', label: T.posY, min: 0, max: 1, step: 0.01, unit: '%', get: function () {
				return c.py;
			}, set: function ( v ) {
				c.py = v;
			} },
			{ type: 'select', label: T.motion, options: opts( P.motions ), get: function () {
				return c.motion;
			}, set: function ( v ) {
				c.motion = v;
			} },
			{ type: 'heading', label: T.adjust },
			{ type: 'select', label: T.filter, options: opts( P.filters ), get: function () {
				return c.filter;
			}, set: function ( v ) {
				c.filter = v;
			} },
			{ type: 'range', label: T.brightness, min: -100, max: 100, step: 1, get: function () {
				return c.adjust.brightness;
			}, set: function ( v ) {
				c.adjust.brightness = v;
			} },
			{ type: 'range', label: T.contrast, min: -100, max: 100, step: 1, get: function () {
				return c.adjust.contrast;
			}, set: function ( v ) {
				c.adjust.contrast = v;
			} },
			{ type: 'range', label: T.saturation, min: -100, max: 100, step: 1, get: function () {
				return c.adjust.saturation;
			}, set: function ( v ) {
				c.adjust.saturation = v;
			} },
			{ type: 'range', label: T.warmth, min: -100, max: 100, step: 1, get: function () {
				return c.adjust.warmth;
			}, set: function ( v ) {
				c.adjust.warmth = v;
			} },
			{ type: 'heading', label: T.fadeIn + ' · ' + T.transition },
			{ type: 'range', label: T.fadeIn, min: 0, max: 3, step: 0.05, unit: 's', get: function () {
				return c.fadeIn;
			}, set: function ( v ) {
				c.fadeIn = v;
			} },
			{ type: 'range', label: T.fadeOut, min: 0, max: 3, step: 0.05, unit: 's', get: function () {
				return c.fadeOut;
			}, set: function ( v ) {
				c.fadeOut = v;
			} },
			{ type: 'select', label: T.transition, options: opts( P.transitions ), get: function () {
				return c.trans.type;
			}, set: function ( v ) {
				c.trans.type = v;
			} },
			{ type: 'range', label: T.duration, min: 0.2, max: 2, step: 0.05, unit: 's', get: function () {
				return c.trans.dur;
			}, set: function ( v ) {
				c.trans.dur = v;
			} },
			{ type: 'buttons', buttons: [
				{ label: T.split, run: function () {
					self.split();
				} },
				image ? null : { label: T.boomerang, run: function () {
					var copy = clone( c );
					copy.id = V.uid();
					copy.reverse = ! c.reverse;
					self.project.clips.splice( self.project.clips.indexOf( c ) + 1, 0, copy );
					self.commit();
				} },
				{ label: T.moveLeft, run: function () {
					self.moveClip( c, -1 );
				} },
				{ label: T.moveRight, run: function () {
					self.moveClip( c, 1 );
				} }
			].filter( Boolean ) },
			this.common()
		);
		this.controls( box, list );
	};

	Editor.prototype.moveClip = function ( c, by ) {
		var list = this.project.clips;
		var i = list.indexOf( c );
		var j = i + by;
		if ( j < 0 || j >= list.length ) {
			return;
		}
		list.splice( i, 1 );
		list.splice( j, 0, c );
		this.commit();
	};

	/* Cut the clip under the playhead in two there. */
	Editor.prototype.split = function () {
		var t = this.player.t;
		var vis = V.visible( this.player.tl, t );
		var it = vis[ vis.length - 1 ];
		if ( ! it || t - it.start < 0.1 || it.end - t < 0.1 ) {
			return;
		}
		var c = it.clip;
		var copy = clone( c );
		copy.id = V.uid();
		if ( 'image' === c.kind ) {
			var first = t - it.start;
			copy.dur = c.dur - first;
			c.dur = first;
		} else {
			var at = V.sourceTime( it, t );
			if ( c.reverse ) {
				copy.out = at;
				c.in = at;
			} else {
				c.out = at;
				copy.in = at;
			}
			copy.fadeIn = 0;
			c.fadeOut = 0;
		}
		c.trans = { type: 'none', dur: 0.6 };
		this.project.clips.splice( this.project.clips.indexOf( c ) + 1, 0, copy );
		this.sel = { kind: 'clip', id: copy.id };
		this.commit();
	};

	Editor.prototype.inspectAudio = function ( box, a ) {
		var self = this;
		var dur = this.player.duration();
		box.appendChild( h( 'h3', 'whd-ve__sec-h', esc( a.name || T.music ) ) );
		this.controls( box, [
			{ type: 'range', label: T.volume, min: 0, max: 1.5, step: 0.01, unit: '%', get: function () {
				return a.volume;
			}, set: function ( v ) {
				a.volume = v;
			} },
			{ type: 'number', label: T.start, min: 0, max: dur, step: 0.1, get: function () {
				return a.start;
			}, set: function ( v ) {
				a.start = V.clamp( v, 0, Math.max( 0, dur - 0.1 ) );
				a.dur = Math.min( a.dur, dur - a.start );
			} },
			{ type: 'number', label: T.duration, min: 0.1, max: dur, step: 0.1, get: function () {
				return a.dur;
			}, set: function ( v ) {
				a.dur = V.clamp( v, 0.1, dur - a.start );
			} },
			{ type: 'number', label: T.offset, min: 0, max: a.fileDur || 600, step: 0.1, get: function () {
				return a.offset;
			}, set: function ( v ) {
				a.offset = Math.max( 0, v );
			} },
			{ type: 'range', label: T.fadeIn, min: 0, max: 5, step: 0.1, unit: 's', get: function () {
				return a.fadeIn;
			}, set: function ( v ) {
				a.fadeIn = v;
			} },
			{ type: 'range', label: T.fadeOut, min: 0, max: 5, step: 0.1, unit: 's', get: function () {
				return a.fadeOut;
			}, set: function ( v ) {
				a.fadeOut = v;
			} },
			{ type: 'check', label: T.loop, get: function () {
				return a.loop;
			}, set: function ( v ) {
				a.loop = v;
			} },
			{ type: 'buttons', buttons: [
				{ label: T.wholeVideo, run: function () {
					a.start = 0;
					a.dur = dur;
					self.commit();
				} },
				{ label: T.mute, run: function () {
					self.project.clips.forEach( function ( c ) {
						c.muted = true;
					} );
					self.commit();
				} }
			] },
			this.common()
		] );
	};

	Editor.prototype.inspectProject = function ( box ) {
		var self = this;
		var p = this.project;
		box.appendChild( h( 'h3', 'whd-ve__sec-h', esc( T.project ) ) );
		this.controls( box, [
			{ type: 'select', label: T.sizePreset, options: P.sizes.map( function ( s ) {
				return [ s.key, s.name ];
			} ), get: function () {
				var m = P.sizes.filter( function ( s ) {
					return s.w === p.w && s.h === p.h;
				} )[ 0 ];
				return m ? m.key : 'reel';
			}, set: function ( v ) {
				var s = V.find( P.sizes, v );
				if ( s ) {
					p.w = s.w;
					p.h = s.h;
					self.fitCanvas();
				}
			} },
			{ type: 'select', label: T.fps, options: P.fps.map( function ( f ) {
				return [ String( f ), String( f ) ];
			} ), get: function () {
				return String( p.fps );
			}, set: function ( v ) {
				p.fps = parseInt( v, 10 );
			} },
			{ type: 'color', label: T.background, get: function () {
				return p.bg;
			}, set: function ( v ) {
				p.bg = v;
			} }
		] );
	};

	/* ─────────────────────────── the timeline ─────────────────────────── */

	Editor.prototype.paintTimeline = function () {
		var self = this;
		var p = this.project;
		var tl = this.player.tl;
		var dur = tl.duration;
		var pxs = this.pxs;
		var width = Math.max( this.scroll.clientWidth, dur * pxs + 160 );
		this.inner.style.width = width + 'px';
		this.$( '.whd-ve__dur' ).textContent = time( dur, true );
		this.$( '[data-act="undo"]' ).disabled = ! this.history.length;
		this.$( '[data-act="redo"]' ).disabled = ! this.future.length;
		this.app.classList.toggle( 'is-empty', ! p.clips.length && ! p.layers.length );

		// The ruler: a mark every second, a number at a sensible spacing.
		var step = pxs >= 90 ? 1 : ( pxs >= 40 ? 2 : ( pxs >= 20 ? 5 : 10 ) );
		var r = '';
		for ( var s = 0; s <= dur + step; s += step ) {
			r += '<span style="left:' + ( s * pxs ) + 'px">' + time( s ) + '</span>';
		}
		this.ruler.innerHTML = r;

		var rows = [
			{ key: 'video', label: T.track_video, items: tl.items.map( function ( it ) {
				return { kind: 'clip', obj: it.clip, start: it.start, end: it.end, it: it };
			} ) },
			{ key: 'text', label: T.track_text, items: layerItems( 'text' ) },
			{ key: 'sticker', label: T.track_sticker, items: layerItems( 'sticker' ) },
			{ key: 'effect', label: T.track_effect, items: layerItems( 'effect' ) },
			{ key: 'audio', label: T.track_audio, items: p.audio.map( function ( a ) {
				return { kind: 'audio', obj: a, start: a.start, end: a.start + a.dur };
			} ) }
		];
		function layerItems( type ) {
			return p.layers.filter( function ( l ) {
				return l.type === type;
			} ).map( function ( l ) {
				return { kind: 'layer', obj: l, start: l.start, end: l.end };
			} );
		}

		this.tracks.innerHTML = '';
		this.labels.innerHTML = '<div class="whd-ve__tl-gap"></div>';
		rows.forEach( function ( row ) {
			// Lanes, so things that overlap in time sit one above the other.
			var lanes = [];
			row.items.forEach( function ( item ) {
				if ( 'video' === row.key ) {
					item.lane = 0;
					return;
				}
				for ( var i = 0; i < 6; i++ ) {
					if ( ! lanes[ i ] || lanes[ i ] <= item.start + 0.001 ) {
						lanes[ i ] = item.end;
						item.lane = i;
						return;
					}
				}
				item.lane = 5;
			} );
			var count = Math.max( 1, lanes.length );
			var laneH = 'video' === row.key ? 52 : 26;
			var track = h( 'div', 'whd-ve__track whd-ve__track--' + row.key );
			track.style.height = ( count * laneH + 6 ) + 'px';
			var label = h( 'div', 'whd-ve__tl-label', esc( row.label ) );
			label.style.height = track.style.height;
			self.labels.appendChild( label );
			row.items.forEach( function ( item ) {
				var o = item.obj;
				var on = self.sel && self.sel.id === o.id;
				var e = h( 'div', 'whd-ve__item whd-ve__item--' + row.key + ( on ? ' is-on' : '' ) );
				e.style.left = ( item.start * pxs ) + 'px';
				e.style.width = Math.max( 6, ( item.end - item.start ) * pxs ) + 'px';
				e.style.top = ( 3 + item.lane * laneH ) + 'px';
				e.style.height = ( laneH - 4 ) + 'px';
				var name = '';
				if ( 'clip' === item.kind ) {
					name = ( o.reverse ? '⟲ ' : '' ) + ( o.title || '' );
					if ( o.poster ) {
						e.style.backgroundImage = 'url("' + o.poster.replace( /"/g, '%22' ) + '")';
					}
				} else if ( 'audio' === item.kind ) {
					name = '♪ ' + ( o.name || '' );
				} else if ( 'text' === o.type ) {
					name = o.text;
				} else if ( 'sticker' === o.type ) {
					name = o.emoji || '★';
				} else {
					var fx = V.find( P.effects, o.effect );
					name = fx ? fx.icon + ' ' + fx.name : o.effect;
				}
				e.innerHTML = '<span class="whd-ve__item-l">' + esc( name ) + '</span><span class="whd-ve__grip whd-ve__grip--l" data-grip="l"></span><span class="whd-ve__grip whd-ve__grip--r" data-grip="r"></span>';
				e.addEventListener( 'pointerdown', function ( ev ) {
					self.select( { kind: item.kind, id: o.id } );
					self.dragItem( ev, item, ev.target.dataset.grip || 'move' );
				} );
				track.appendChild( e );
			} );
			self.tracks.appendChild( track );
		} );
		this.playhead.style.transform = 'translateX(' + ( this.player.t * pxs ) + 'px)';
		this.playhead.style.height = ( this.tracks.offsetHeight + this.ruler.offsetHeight ) + 'px';
	};

	/* Dragging on the timeline: move a text, sticker, effect or sound; trim any of them by its ends. */
	Editor.prototype.dragItem = function ( e, item, grip ) {
		var self = this;
		var o = item.obj;
		var x0 = e.clientX;
		var start = clone( o );
		var dur = this.player.duration();
		var moved = false;
		e.preventDefault();
		function move( ev ) {
			var d = ( ev.clientX - x0 ) / self.pxs;
			if ( Math.abs( ev.clientX - x0 ) > 2 ) {
				moved = true;
			}
			if ( ! moved ) {
				return;
			}
			if ( 'clip' === item.kind ) {
				if ( 'image' === o.kind ) {
					if ( 'r' === grip ) {
						o.dur = V.clamp( start.dur + d, 0.3, 60 );
					}
				} else if ( 'l' === grip ) {
					o.in = V.clamp( start.in + d * o.speed, 0, start.out - 0.1 );
				} else if ( 'r' === grip ) {
					o.out = V.clamp( start.out + d * o.speed, start.in + 0.1, o.seconds );
				}
			} else if ( 'audio' === item.kind ) {
				if ( 'l' === grip ) {
					var nd = V.clamp( d, -start.offset, start.dur - 0.2 );
					o.start = Math.max( 0, start.start + nd );
					o.offset = start.offset + nd;
					o.dur = start.dur - nd;
				} else if ( 'r' === grip ) {
					o.dur = V.clamp( start.dur + d, 0.2, dur - o.start );
				} else {
					o.start = V.clamp( snap( start.start + d ), 0, Math.max( 0, dur - 0.1 ) );
				}
			} else {
				if ( 'l' === grip ) {
					o.start = V.clamp( snap( start.start + d ), 0, start.end - 0.1 );
				} else if ( 'r' === grip ) {
					o.end = V.clamp( snap( start.end + d ), start.start + 0.1, dur );
				} else {
					var len = start.end - start.start;
					o.start = V.clamp( snap( start.start + d ), 0, Math.max( 0, dur - len ) );
					o.end = o.start + len;
				}
			}
			self.player.refresh();
			self.paintTimeline();
		}
		function snap( v ) {
			var t = self.player.t;
			return Math.abs( v - t ) * self.pxs < 8 ? t : v;
		}
		function up() {
			window.removeEventListener( 'pointermove', move );
			window.removeEventListener( 'pointerup', up );
			if ( moved ) {
				self.commit( true );
				self.paintInspector();
			}
		}
		window.addEventListener( 'pointermove', move );
		window.addEventListener( 'pointerup', up );
	};

	Editor.prototype.scrub = function ( e ) {
		var self = this;
		function at( ev ) {
			var r = self.inner.getBoundingClientRect();
			self.player.seek( Math.max( 0, ( ev.clientX - r.left ) / self.pxs ) );
		}
		at( e );
		function move( ev ) {
			at( ev );
		}
		function up() {
			window.removeEventListener( 'pointermove', move );
			window.removeEventListener( 'pointerup', up );
		}
		window.addEventListener( 'pointermove', move );
		window.addEventListener( 'pointerup', up );
	};

	/* ─────────────────────────── keys ─────────────────────────── */

	Editor.prototype.bindKeys = function () {
		var self = this;
		this.onKey = function ( e ) {
			if ( ! self.root.isConnected || self.root.offsetParent === null ) {
				return;
			}
			var tag = ( e.target.tagName || '' ).toLowerCase();
			if ( 'input' === tag || 'textarea' === tag || 'select' === tag || e.target.isContentEditable || document.querySelector( 'dialog[open]' ) ) {
				return;
			}
			var k = e.key;
			var ctrl = e.ctrlKey || e.metaKey;
			if ( ' ' === k ) {
				e.preventDefault();
				self.player.toggle();
			} else if ( ( 'Delete' === k || 'Backspace' === k ) && self.sel && self.item() ) {
				e.preventDefault();
				self.remove();
			} else if ( ctrl && ( 'z' === k || 'Z' === k ) ) {
				e.preventDefault();
				if ( e.shiftKey ) {
					self.redo();
				} else {
					self.undo();
				}
			} else if ( ctrl && ( 'y' === k || 'Y' === k ) ) {
				e.preventDefault();
				self.redo();
			} else if ( ctrl && ( 'd' === k || 'D' === k ) && self.item() ) {
				e.preventDefault();
				self.duplicate();
			} else if ( 0 === k.indexOf( 'Arrow' ) ) {
				var it = self.sel && 'layer' === self.sel.kind ? self.item() : null;
				e.preventDefault();
				if ( it && 'effect' !== it.type ) {
					var step = e.shiftKey ? 0.02 : 0.004;
					if ( 'ArrowLeft' === k ) {
						it.x -= step;
					} else if ( 'ArrowRight' === k ) {
						it.x += step;
					} else if ( 'ArrowUp' === k ) {
						it.y -= step;
					} else {
						it.y += step;
					}
					self.commit( true );
				} else if ( 'ArrowLeft' === k || 'ArrowRight' === k ) {
					var by = ( e.shiftKey ? 1 : 1 / self.project.fps ) * ( 'ArrowLeft' === k ? -1 : 1 );
					self.player.seek( self.player.t + by );
				}
			}
		};
		document.addEventListener( 'keydown', this.onKey );
	};

	/* ─────────────────────────── saving ─────────────────────────── */

	Editor.prototype.exportDialog = function ( mode ) {
		var self = this;
		if ( ! this.project.clips.length ) {
			window.alert( T.saveFirst );
			return;
		}
		this.player.pause();
		var asGif = 'gif' === this.opts.exportAs;
		var d = document.createElement( 'dialog' );
		d.className = 'whd-ve__dialog';
		d.innerHTML = '<form method="dialog" class="whd-ve__dialog-in">' +
			'<h2>' + esc( 'save' === mode ? T.save : T.download ) + '</h2>' +
			'<fieldset class="whd-ve__kind"><legend>' + esc( T.exportAs ) + '</legend>' +
				'<label><input type="radio" name="kind" value="mp4"' + ( asGif ? '' : ' checked' ) + '> ' + esc( T.asVideo ) + '</label>' +
				'<label><input type="radio" name="kind" value="gif"' + ( asGif ? ' checked' : '' ) + '> ' + esc( T.asGif ) + '</label>' +
			'</fieldset>' +
			'<div class="whd-ve__opt-mp4"><label>' + esc( T.quality ) + ' <select name="long"><option value="0">' + this.project.w + ' × ' + this.project.h + '</option><option value="1280">720p</option><option value="854">480p</option></select></label></div>' +
			'<div class="whd-ve__opt-gif"><label>' + esc( T.gifWidth ) + ' <select name="gw"><option value="320">320</option><option value="480" selected>480</option><option value="640">640</option><option value="800">800</option></select></label>' +
				'<label>' + esc( T.gifFps ) + ' <select name="gf"><option value="10">10</option><option value="12" selected>12</option><option value="15">15</option><option value="20">20</option></select></label></div>' +
			'<div class="whd-ve__progress" hidden><p class="whd-ve__progress-t"></p><div class="whd-ve__meter"><span></span></div></div>' +
			'<p class="whd-ve__result" hidden></p>' +
			'<p class="whd-ve__dialog-btns"><button type="button" class="whd-ve__btn" data-x>' + esc( T.cancel ) + '</button>' +
			'<button type="button" class="whd-ve__btn whd-ve__btn--dark" data-go>' + esc( 'save' === mode ? T.save : T.download ) + '</button></p>' +
		'</form>';
		document.body.appendChild( d );
		var kindInputs = d.querySelectorAll( 'input[name="kind"]' );
		function paintKind() {
			var gif = d.querySelector( 'input[name="kind"]:checked' ).value === 'gif';
			d.querySelector( '.whd-ve__opt-mp4' ).hidden = gif;
			d.querySelector( '.whd-ve__opt-gif' ).hidden = ! gif;
		}
		Array.prototype.forEach.call( kindInputs, function ( i ) {
			i.addEventListener( 'change', paintKind );
		} );
		paintKind();
		var signal = { cancelled: false };
		var busy = false;
		d.addEventListener( 'cancel', function ( e ) {
			if ( busy ) {
				e.preventDefault();
			}
		} );
		d.querySelector( '[data-x]' ).addEventListener( 'click', function () {
			if ( busy ) {
				signal.cancelled = true;
				return;
			}
			d.close();
			d.remove();
		} );
		d.querySelector( '[data-go]' ).addEventListener( 'click', function () {
			var go = this;
			var gif = d.querySelector( 'input[name="kind"]:checked' ).value === 'gif';
			var prog = d.querySelector( '.whd-ve__progress' );
			var bar = d.querySelector( '.whd-ve__meter span' );
			var words = d.querySelector( '.whd-ve__progress-t' );
			var result = d.querySelector( '.whd-ve__result' );
			busy = true;
			go.disabled = true;
			prog.hidden = false;
			result.hidden = true;
			function step( f, w ) {
				bar.style.width = Math.round( Math.max( 0.02, f ) * 100 ) + '%';
				if ( w ) {
					words.textContent = w;
				}
			}
			var made = gif ? V.exportGif( self.project, { width: d.querySelector( '[name="gw"]' ).value, fps: d.querySelector( '[name="gf"]' ).value, onProgress: step, signal: signal } ) :
				V.exportVideo( self.project, { long: parseInt( d.querySelector( '[name="long"]' ).value, 10 ) || 0, onProgress: step, signal: signal } );
			made.then( function ( out ) {
				var name = ( self.project.name || 'video' ).replace( /[^\w\- ]+/g, '' ).trim() || 'video';
				if ( 'download' === mode ) {
					var a = document.createElement( 'a' );
					a.href = URL.createObjectURL( out.blob );
					a.download = name + '.' + out.ext;
					document.body.appendChild( a );
					a.click();
					setTimeout( function () {
						URL.revokeObjectURL( a.href );
						a.remove();
					}, 4000 );
					return null;
				}
				step( 0, T.uploadingTo );
				var first = self.project.clips[ 0 ];
				return V.upload( out.blob, {
					title: self.project.name || ( first && first.title ) || 'Video',
					kind: gif ? 'gif' : ( self.opts.kind || 'edit' ),
					design: self.project,
					source: first ? first.att : 0,
					poster: out.poster,
					made: !! self.opts.made
				}, function ( f ) {
					step( f, T.uploadingTo );
				} );
			} ).then( function ( saved ) {
				busy = false;
				go.disabled = false;
				prog.hidden = true;
				self.dirty = false;
				if ( saved ) {
					result.hidden = false;
					result.innerHTML = esc( T.savedTo ) + ' <a href="' + esc( saved.library ) + '" target="_blank" rel="noopener">' + esc( T.openSaved ) + '</a> · <a href="' + esc( saved.edit ) + '">' + esc( T.editSaved ) + '</a>';
					try {
						window.localStorage.removeItem( DRAFT );
					} catch ( e ) {}
					if ( self.opts.onSaved ) {
						self.opts.onSaved( saved );
					}
				} else {
					d.close();
					d.remove();
				}
				d.querySelector( '[data-x]' ).textContent = T.close;
			} ).catch( function ( e ) {
				busy = false;
				go.disabled = false;
				prog.hidden = true;
				result.hidden = false;
				result.classList.add( 'is-bad' );
				result.textContent = 'cancelled' === e.message ? '' : ( e.message || T.error );
				if ( 'cancelled' === e.message ) {
					d.close();
					d.remove();
				}
			} );
		} );
		d.showModal();
	};

	/* ─────────────────────────── the one open editor ─────────────────────────── */

	var current = null;
	window.WHDVideoEditor = {
		/* Open the editor in `el` on library clips. opts: clips, size, made, kind, exportAs, onSaved. */
		open: function ( el, opts ) {
			opts = opts || {};
			if ( current ) {
				current.destroy();
			}
			current = new Editor( el, opts );
			if ( opts.clips && opts.clips.length ) {
				current.load( opts.clips, { size: opts.size } );
			} else if ( opts.project ) {
				current.project = V.normalize( opts.project );
				current.player.setProject( current.project );
				current.fitCanvas();
				current.paintAll();
			} else {
				current.offerDraft();
			}
			return current;
		},
		current: function () {
			return current;
		}
	};
}() );
