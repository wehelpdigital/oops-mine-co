/**
 * The live studio.
 *
 * Going live, in order: the server makes the room and readies the platforms (`start`), this browser
 * joins with the canvas as its camera and the mixer as its microphone, then the server starts the RTMP
 * copy and opens the page (`on-air`). Both are safe to repeat, so a reload mid-stream comes back through
 * the same path with the same room, and the five minutes Daily keeps an idle stream alive cover it.
 *
 * Everything the host sets up here is saved as the scene, so a reload brings it all back, including a
 * cover that is on. A reload must never put a camera back on air that the host had just covered.
 *
 * @package whd
 */
( function () {
	'use strict';

	var D = window.WHD_STUDIO;
	if ( ! D ) {
		return;
	}
	var T = D.i18n;
	var Stage = window.WHDStage;
	var Sound = window.WHDMixer;

	function $( id ) {
		return document.getElementById( 'whdls-' + id );
	}
	function el( tag, cls, text ) {
		var node = document.createElement( tag );
		if ( cls ) {
			node.className = cls;
		}
		if ( null !== text && undefined !== text ) {
			node.textContent = text;
		}
		return node;
	}
	function fmt( text ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var i = 0;
		return String( text ).replace( /%(?:\d\$)?[sd]/g, function () {
			return args[ i++ ];
		} );
	}

	var root = $( '' ) || document.getElementById( 'whdls' );
	var portrait = 'portrait' === D.format;
	var saved = D.scene || {};

	/*
	 * Where each kind of thing appears, set ahead of time with a dashed box only the host can see, so they can stand
	 * beside it and point. Places are [ x, y, width ]; ratio is the box's height as a share of its width.
	 */
	var SPOTS = {
		photos: {
			ratio: 1.22,
			first: portrait ? 'top' : 'right',
			places: portrait ? { top: [ 0.5, 0.265, 0.6 ], middle: [ 0.5, 0.42, 0.6 ], lower: [ 0.5, 0.6, 0.6 ] } : { left: [ 0.2, 0.5, 0.34 ], centre: [ 0.5, 0.5, 0.4 ], right: [ 0.8, 0.5, 0.34 ] }
		},
		card: {
			ratio: 384 / 860,
			first: portrait ? 'middle' : 'left',
			// Portrait stays above the bottom third, where TikTok lays its own comments over the video.
			places: portrait ? { top: [ 0.5, 0.2, 0.86 ], middle: [ 0.5, 0.58, 0.86 ], lower: [ 0.5, 0.68, 0.86 ] } : { left: [ 0.25, 0.8, 0.44 ], centre: [ 0.5, 0.8, 0.44 ], right: [ 0.75, 0.8, 0.44 ] }
		},
		picture: {
			ratio: 1,
			first: portrait ? 'middle' : 'centre',
			places: portrait ? { top: [ 0.5, 0.2, 0.45 ], middle: [ 0.5, 0.5, 0.45 ], lower: [ 0.5, 0.7, 0.45 ] } : { left: [ 0.2, 0.45, 0.24 ], centre: [ 0.5, 0.5, 0.24 ], right: [ 0.8, 0.45, 0.24 ] }
		},
		gif: {
			ratio: 1,
			first: portrait ? 'top' : 'centre',
			places: portrait ? { top: [ 0.5, 0.17, 0.36 ], middle: [ 0.5, 0.4, 0.36 ], lower: [ 0.5, 0.64, 0.36 ] } : { left: [ 0.18, 0.42, 0.2 ], centre: [ 0.46, 0.28, 0.2 ], right: [ 0.82, 0.32, 0.2 ] }
		}
	};

	function startingSpots() {
		var out = {};
		Object.keys( SPOTS ).forEach( function ( kind ) {
			var first = SPOTS[ kind ].places[ SPOTS[ kind ].first ];
			var kept = ( saved.spots || {} )[ kind ] || ( 'photos' === kind ? saved.spot : null ); // `spot` was the photos' alone
			out[ kind ] = kept ? { x: kept.x, y: kept.y, w: kept.w } : { x: first[ 0 ], y: first[ 1 ], w: first[ 2 ] };
		} );
		return out;
	}

	var S = {
		stage: D.stage,
		onAir: false,
		ending: false,
		call: null,
		pieces: ( D.pieces || [] ).slice(),
		showing: D.showing || 0,
		comments: {},
		after: 0,
		filter: 'all',
		dests: D.dests || {},
		destState: {},
		startedAt: D.startedAt || 0,
		viewers: 0,
		selected: null,
		drag: null,
		effectMode: 'burst',
		rain: '',
		transition: undefined !== saved.transition ? +saved.transition : 400,
		muted: !! saved.muted,
		coverMuted: !! saved.coverMuted,
		library: saved.library || [],
		floats: saved.floats || [],
		music: saved.music || [],
		ownPads: saved.ownPads || [],
		gifs: saved.gifs || [],
		stickerGroup: '',
		occWords: saved.occWords || {},
		occMusic: false !== saved.occMusic,
		occRain: false !== saved.occRain,
		musicBefore: saved.musicBefore || null,
		photos: 0,
		spots: startingSpots(),
		spotsOn: false !== saved.spotsOn && ! ( saved.spot && false === saved.spot.on ),
		tab: 'pieces',
		scenes: saved.scenes || [],
		activeScene: saved.activeScene || '',
		net: '',
		polling: false,
		ticks: 0,
		cameras: {},
		devices: []
	};

	/* ─────────────────────────── the server ─────────────────────────── */

	function api( method, path, body ) {
		var opts = {
			method: method,
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': D.rest.nonce }
		};
		if ( body ) {
			opts.headers[ 'Content-Type' ] = 'application/json';
			opts.body = JSON.stringify( body );
		}
		return window.fetch( D.rest.root + path, opts ).then( function ( r ) {
			return r.json().catch( function () {
				return {};
			} ).then( function ( data ) {
				if ( ! r.ok ) {
					var error = new Error( data.message || ( 'HTTP ' + r.status ) );
					error.status = r.status;
					throw error;
				}
				return data;
			} );
		} );
	}

	/* ─────────────────────────── messages ─────────────────────────── */

	function banner( key, text, kind, action ) {
		var box = $( 'banners' );
		var node = box.querySelector( '[data-key="' + key + '"]' );
		if ( ! node ) {
			node = el( 'div', 'whdls-banner' );
			node.setAttribute( 'data-key', key );
			box.appendChild( node );
		}
		node.className = 'whdls-banner whdls-banner--' + ( kind || 'warn' );
		node.textContent = '';
		node.appendChild( el( 'span', '', text ) );
		if ( action ) {
			var btn;
			if ( action.href ) {
				btn = el( 'a', 'whdls-banner__do', action.label );
				btn.href = action.href;
				btn.target = '_blank'; // the studio may well be on air
				btn.rel = 'noopener';
			} else {
				btn = el( 'button', 'whdls-banner__do', action.label );
				btn.type = 'button';
				btn.addEventListener( 'click', action.run );
			}
			node.appendChild( btn );
		}
		var close = el( 'button', 'whdls-banner__x', '×' );
		close.type = 'button';
		close.setAttribute( 'aria-label', 'Close' );
		close.addEventListener( 'click', function () {
			node.remove();
		} );
		node.appendChild( close );
	}

	function clearBanner( key ) {
		var node = $( 'banners' ).querySelector( '[data-key="' + key + '"]' );
		if ( node ) {
			node.remove();
		}
	}

	/* ─────────────────────────── stage and mixer ─────────────────────────── */

	var canvas = $( 'canvas' );
	var stage = new Stage.Stage( canvas, {
		width: portrait ? 720 : 1280,
		height: portrait ? 1280 : 720,
		placeholder: T.placeholder,
		coverLogo: D.coverLogo,
		coverLink: D.pagePretty,
		brand: D.brand
	} );
	var mixer = Sound.supported ? new Sound.Mixer() : null;

	stage.load( saved.stage || {} );
	stage.setCover( !! saved.cover, undefined !== saved.coverLine ? saved.coverLine : $( 'cover-line' ).options[ 0 ].text );
	if ( undefined !== saved.coverLine ) {
		$( 'cover-line' ).value = saved.coverLine;
	}

	if ( ! saved.stage && D.logo ) {
		// A first visit: the shop's mark in the corner, which every stream will want.
		stage.add( { type: 'image', src: D.logo, x: portrait ? 0.14 : 0.08, y: portrait ? 0.07 : 0.11, w: portrait ? 0.18 : 0.1, opacity: 0.9 }, true );
	}
	if ( galleryLayer() ) {
		S.photos = galleryLayer().productId;
		S.showing = productLayer() ? productLayer().productId : 0; // the server's "showing" is the piece, not its card
	}
	ensureSpots();

	document.fonts && document.fonts.ready.then( function () {
		[ '500 40px "Cormorant Garamond"', 'italic 500 40px "Cormorant Garamond"', '800 40px Manrope', '600 40px Manrope' ].forEach( function ( font ) {
			document.fonts.load( font );
		} );
		setTimeout( function () {
			stage.layers.forEach( function ( layer ) {
				layer._key = '';
			} );
			stage._coverText = null;
			stage._frameSig = ''; // frames with words on them
			stage._occasionFrame = null;
		}, 700 );
	} );

	/* Saving is debounced: dragging something around must not be a request per pixel. */
	var saveTimer = null;
	function sceneData() {
		var sources = [];
		Object.keys( stage.sources ).forEach( function ( id ) {
			var s = stage.sources[ id ];
			if ( 'camera' === s.kind ) {
				sources.push( { id: id, deviceId: s.deviceId, label: s.label, flip: !! s.flip, zoom: +s.zoom || 1, fit: s.fit } );
			}
		} );
		return {
			stage: stage.toJSON(),
			sources: sources,
			main: stage.main,
			cover: stage.cover,
			coverLine: stage.coverLine,
			muted: S.muted,
			coverMuted: S.coverMuted,
			transition: S.transition,
			library: S.library,
			floats: S.floats,
			music: S.music,
			ownPads: S.ownPads,
			gifs: S.gifs,
			gifStay: +$( 'gif-stay' ).value,
			occWords: S.occWords,
			occMusic: S.occMusic,
			occRain: S.occRain,
			musicBefore: S.musicBefore,
			spots: S.spots,
			spotsOn: S.spotsOn,
			scenes: S.scenes,
			activeScene: S.activeScene,
			mic: S.micId || '',
			volumes: mixer ? mixer.volumes : null,
			duck: mixer ? mixer.duck : true,
			loop: mixer ? mixer.loop : true,
			hear: mixer ? mixer.hearing : true
		};
	}
	function saveSoon() {
		clearTimeout( saveTimer );
		saveTimer = setTimeout( function () {
			if ( 'ended' !== S.stage ) {
				api( 'POST', D.id + '/scene', { scene: sceneData() } ).catch( function () {} );
			}
		}, 900 );
	}
	stage.onChange = function () {
		ensureSpots(); // something arriving or leaving can put a box in or out of the way
		renderLayers();
		saveSoon();
	};

	/* ─────────────────────────── cameras ─────────────────────────── */

	function videoConstraints( deviceId ) {
		var c = portrait ? { width: { ideal: 1920 }, height: { ideal: 1080 } } : { width: { ideal: 1280 }, height: { ideal: 720 } };
		c.frameRate = { ideal: 30 };
		if ( deviceId ) {
			c.deviceId = { exact: deviceId };
		}
		return c;
	}

	var AUDIO_RULES = { echoCancellation: true, noiseSuppression: true, autoGainControl: true };

	function makeVideo( stream ) {
		var video = el( 'video' );
		video.muted = true;
		video.playsInline = true;
		video.autoplay = true;
		video.srcObject = stream;
		$( 'videos' ).appendChild( video );
		var played = video.play();
		if ( played && played.catch ) {
			played.catch( function () {} );
		}
		return video;
	}

	function labelFor( deviceId, fallback ) {
		var found = S.devices.filter( function ( d ) {
			return d.deviceId === deviceId;
		} )[ 0 ];
		return found && found.label ? found.label.replace( /\s*\([0-9a-f]{4}:[0-9a-f]{4}\)\s*$/i, '' ) : fallback;
	}

	function camId( deviceId ) {
		return 'cam:' + ( deviceId || 'default' );
	}

	/* A stream already open becomes a source: one for each camera, all kept running. */
	function addCameraStream( stream, deviceId, meta ) {
		var id = camId( deviceId );
		var count = Object.keys( stage.sources ).length + 1;
		var props = {
			kind: 'camera',
			deviceId: deviceId,
			stream: stream,
			label: labelFor( deviceId, T.camera + ' ' + count ),
			flip: false,
			zoom: 1,
			fit: 'cover'
		};
		for ( var k in meta || {} ) {
			props[ k ] = meta[ k ];
		}
		stage.addSource( id, makeVideo( stream ), props );
		renderSources();
		return id;
	}

	function openCamera( deviceId, meta ) {
		if ( stage.sources[ camId( deviceId ) ] ) {
			return Promise.resolve( camId( deviceId ) );
		}
		return navigator.mediaDevices.getUserMedia( { video: videoConstraints( deviceId ), audio: false } ).then( function ( stream ) {
			// The id it was asked for, not the one the track reports: that is what the device list has.
			return addCameraStream( stream, deviceId || stream.getVideoTracks()[ 0 ].getSettings().deviceId, meta );
		} );
	}

	function stopSource( id ) {
		var source = stage.sources[ id ];
		if ( ! source ) {
			return;
		}
		( source.stream ? source.stream.getTracks() : [] ).forEach( function ( t ) {
			t.stop();
		} );
		if ( source.video && source.video.parentNode ) {
			source.video.parentNode.removeChild( source.video );
		}
		if ( 'screen' === source.kind && mixer ) {
			mixer.setScreen( null );
		}
		stage.removeSource( id );
		renderSources();
	}

	function refreshDevices() {
		return navigator.mediaDevices.enumerateDevices().then( function ( list ) {
			S.devices = list;
			var mics = list.filter( function ( d ) {
				return 'audioinput' === d.kind;
			} );
			var box = $( 'mic-select' );
			box.textContent = '';
			mics.forEach( function ( d, i ) {
				var o = el( 'option', '', d.label || ( 'Microphone ' + ( i + 1 ) ) );
				o.value = d.deviceId;
				box.appendChild( o );
			} );
			if ( S.micId ) {
				box.value = S.micId;
			}
			renderAddCamera();
		} );
	}

	function renderAddCamera() {
		var box = $( 'add-cam' );
		box.textContent = '';
		var free = S.devices.filter( function ( d ) {
			return 'videoinput' === d.kind && ! stage.sources[ camId( d.deviceId ) ];
		} );
		free.forEach( function ( d, i ) {
			var o = el( 'option', '', d.label || ( T.camera + ' ' + ( i + 1 ) ) );
			o.value = d.deviceId;
			box.appendChild( o );
		} );
		if ( ! free.length ) {
			var none = el( 'option', '', T.noMoreCams );
			none.value = '';
			box.appendChild( none );
		}
		box.disabled = ! free.length;
		$( 'add-cam-btn' ).disabled = ! free.length;
	}

	/* The first camera and microphone, then whatever else this stream was using last time. */
	function openDevices() {
		if ( ! navigator.mediaDevices || ! navigator.mediaDevices.getUserMedia ) {
			banner( 'https', window.isSecureContext ? T.unsupported : T.needHttps, 'error' );
			return Promise.resolve();
		}
		var wanted = saved.sources || [];
		var first = wanted[ 0 ] || {};
		var ask = function ( video, audio ) {
			return navigator.mediaDevices.getUserMedia( {
				video: video ? videoConstraints( first.deviceId ) : false,
				audio: audio ? AUDIO_RULES : false
			} );
		};
		return ask( true, true ).catch( function ( err ) {
			// A saved camera that is gone, or a computer with no camera or no microphone: take what there is.
			if ( first.deviceId ) {
				first = {};
			}
			return ask( true, true ).catch( function () {
				return ask( false, true ).catch( function () {
					return ask( true, false ).catch( function () {
						throw err;
					} );
				} );
			} );
		} ).then( function ( stream ) {
			var video = stream.getVideoTracks();
			var audio = stream.getAudioTracks();
			if ( audio.length && mixer ) {
				S.micId = audio[ 0 ].getSettings().deviceId || '';
				mixer.setMic( new MediaStream( audio ) );
			}
			return refreshDevices().then( function () {
				if ( video.length ) {
					var deviceId = video[ 0 ].getSettings().deviceId || '';
					addCameraStream( new MediaStream( video ), deviceId, first.deviceId === deviceId ? { flip: first.flip, zoom: first.zoom, fit: first.fit } : null );
				}
				// The rest one at a time: opening several together can fail on some cameras.
				return wanted.slice( 1 ).reduce( function ( chain, w ) {
					return chain.then( function () {
						return openCamera( w.deviceId, { flip: w.flip, zoom: w.zoom, fit: w.fit } ).catch( function () {} );
					} );
				}, Promise.resolve() );
			} ).then( function () {
				if ( saved.main && stage.sources[ saved.main ] ) {
					stage.main = saved.main;
				}
				renderSources();
				renderAddCamera();
			} );
		} ).catch( function () {
			banner( 'camera', T.noCamera, 'error' );
		} );
	}

	/* What each camera card and each chip above the stage shows. */
	function renderSources() {
		var box = $( 'sources' );
		var chips = $( 'cut-sources' );
		var ids = Object.keys( stage.sources );
		box.textContent = '';
		chips.textContent = '';

		ids.forEach( function ( id ) {
			var source = stage.sources[ id ];
			var isMain = stage.main === id;
			var inset = stage.layers.filter( function ( l ) {
				return 'source' === l.type && l.sourceId === id && ! l._gone;
			} )[ 0 ];

			var chip = el( 'button', 'whdls-chip' + ( isMain ? ' is-on' : '' ), source.label );
			chip.type = 'button';
			chip.title = source.label;
			chip.addEventListener( 'click', function () {
				makeMain( id );
			} );
			chips.appendChild( chip );

			var card = el( 'article', 'whdls-source' + ( isMain ? ' is-main' : '' ) );
			var thumb = el( 'video' );
			thumb.muted = true;
			thumb.playsInline = true;
			thumb.autoplay = true;
			thumb.srcObject = source.stream;
			if ( source.flip ) {
				thumb.style.transform = 'scaleX(-1)';
			}
			var hit = el( 'button', 'whdls-source__thumb' );
			hit.type = 'button';
			hit.title = T.makeMain;
			hit.appendChild( thumb );
			if ( isMain ) {
				hit.appendChild( el( 'span', 'whdls-source__on', T.main ) );
			}
			hit.addEventListener( 'click', function () {
				makeMain( id );
			} );
			card.appendChild( hit );
			card.appendChild( el( 'strong', '', source.label ) );

			var row = el( 'div', 'whdls-source__do' );
			var corner = el( 'button', 'whdls-mini' + ( inset ? ' is-on' : '' ), inset ? T.cornerOff : T.corner );
			corner.type = 'button';
			corner.disabled = isMain && ! inset;
			corner.addEventListener( 'click', function () {
				if ( inset ) {
					stage.remove( inset.id );
				} else {
					var layer = stage.add( {
						type: 'source',
						sourceId: id,
						x: portrait ? 0.76 : 0.84,
						y: portrait ? 0.2 : 0.2,
						w: portrait ? 0.38 : 0.26,
						shape: 'rounded'
					} );
					select( layer.id );
				}
				renderSources();
			} );
			row.appendChild( corner );

			var flip = el( 'button', 'whdls-mini' + ( source.flip ? ' is-on' : '' ), T.flip );
			flip.type = 'button';
			flip.addEventListener( 'click', function () {
				stage.setSourceProps( id, { flip: ! source.flip } );
				renderSources();
			} );
			row.appendChild( flip );

			if ( 'screen' === source.kind || ids.length > 1 ) {
				var drop = el( 'button', 'whdls-mini whdls-mini--danger', T.remove );
				drop.type = 'button';
				drop.addEventListener( 'click', function () {
					stopSource( id );
					renderAddCamera();
				} );
				row.appendChild( drop );
			}
			card.appendChild( row );

			var zoom = el( 'label', 'whdls-range' );
			zoom.appendChild( el( 'span', '', T.zoom ) );
			var range = el( 'input' );
			range.type = 'range';
			range.min = 1;
			range.max = 3;
			range.step = 0.01;
			range.value = source.zoom || 1;
			range.addEventListener( 'input', function () {
				stage.setSourceProps( id, { zoom: +range.value } );
			} );
			zoom.appendChild( range );
			card.appendChild( zoom );

			var whole = el( 'label', 'whdls-switch' );
			var check = el( 'input' );
			check.type = 'checkbox';
			check.checked = 'contain' === source.fit;
			check.addEventListener( 'change', function () {
				stage.setSourceProps( id, { fit: check.checked ? 'contain' : 'cover' } );
			} );
			whole.appendChild( check );
			whole.appendChild( el( 'span' ) );
			whole.appendChild( document.createTextNode( T.whole ) );
			card.appendChild( whole );

			box.appendChild( card );
		} );
	}

	function makeMain( id ) {
		stage.setMain( id, S.transition );
		S.activeScene = '';
		renderScenes();
		renderSources();
		updatePeek();
	}

	$( 'add-cam-btn' ).addEventListener( 'click', function () {
		var deviceId = $( 'add-cam' ).value;
		if ( ! deviceId ) {
			return;
		}
		openCamera( deviceId ).then( function () {
			renderAddCamera();
			saveSoon();
		} ).catch( function () {
			banner( 'camera', T.camFailed, 'error' );
		} );
	} );

	$( 'share' ).addEventListener( 'click', function () {
		if ( ! navigator.mediaDevices || ! navigator.mediaDevices.getDisplayMedia ) {
			banner( 'share', T.shareFailed, 'error' );
			return;
		}
		navigator.mediaDevices.getDisplayMedia( { video: { frameRate: 30 }, audio: true } ).then( function ( stream ) {
			stopSource( 'screen' );
			stage.addSource( 'screen', makeVideo( stream ), { kind: 'screen', label: T.screen, stream: stream, fit: 'contain' } );
			if ( mixer ) {
				mixer.setScreen( stream );
			}
			stream.getVideoTracks()[ 0 ].addEventListener( 'ended', function () {
				stopSource( 'screen' );
			} );
			makeMain( 'screen' );
		} ).catch( function ( err ) {
			if ( err && 'NotAllowedError' !== err.name ) {
				banner( 'share', T.shareFailed, 'error' );
			}
		} );
	} );

	$( 'mic-select' ).addEventListener( 'change', function () {
		var deviceId = this.value;
		var rules = Object.assign( {}, AUDIO_RULES, { deviceId: { exact: deviceId } } );
		navigator.mediaDevices.getUserMedia( { audio: rules } ).then( function ( stream ) {
			S.micId = deviceId;
			if ( mixer ) {
				mixer.setMic( stream );
			}
			saveSoon();
		} ).catch( function () {
			banner( 'camera', T.noCamera, 'error' );
		} );
	} );

	/* ─────────────────────────── scenes ─────────────────────────── */

	function renderScenes() {
		var box = $( 'scenes' );
		box.textContent = '';
		S.scenes.forEach( function ( scene, i ) {
			var chip = el( 'button', 'whdls-chip whdls-chip--scene' + ( S.activeScene === scene.id ? ' is-on' : '' ) );
			chip.type = 'button';
			chip.title = scene.name + ( i < 9 ? ' (' + ( i + 1 ) + ')' : '' );
			chip.appendChild( el( 'span', '', scene.name ) );
			var x = el( 'i', 'whdls-chip__x', '×' );
			x.addEventListener( 'click', function ( e ) {
				e.stopPropagation();
				if ( window.confirm( T.sceneRemove ) ) {
					S.scenes.splice( i, 1 );
					if ( S.activeScene === scene.id ) {
						S.activeScene = '';
					}
					renderScenes();
					saveSoon();
				}
			} );
			chip.appendChild( x );
			chip.addEventListener( 'click', function () {
				applyScene( i );
			} );
			chip.addEventListener( 'dblclick', function () {
				var name = window.prompt( T.sceneRename, scene.name );
				if ( name ) {
					scene.name = name.slice( 0, 30 );
					renderScenes();
					saveSoon();
				}
			} );
			box.appendChild( chip );
		} );
	}

	$( 'scene-save' ).addEventListener( 'click', function () {
		var id = 's' + Date.now().toString( 36 );
		S.scenes.push( {
			id: id,
			name: fmt( T.sceneName, S.scenes.length + 1 ),
			stage: stage.toJSON(),
			main: stage.main
		} );
		S.activeScene = id;
		renderScenes();
		saveSoon();
	} );

	function applyScene( i ) {
		var scene = S.scenes[ i ];
		if ( ! scene ) {
			return;
		}
		stage.holdFrame( S.transition );
		stage.load( JSON.parse( JSON.stringify( scene.stage ) ) );
		if ( scene.main && stage.sources[ scene.main ] ) {
			stage.main = scene.main;
		}
		S.activeScene = scene.id;
		select( null );
		syncLooks();
		renderScenes();
		renderSources();
		renderLayers();
		updatePeek();
		refreshCard();
		syncShowing();
		renderOccasions();
		saveSoon();
	}

	/* A scene can bring a piece on or take it off: the page and the piece list follow. */
	function syncShowing() {
		var layer = productLayer();
		var photos = galleryLayer();
		S.showing = layer ? layer.productId : 0;
		S.photos = photos ? photos.productId : 0;
		S.cardBack = null;
		ensureSpots();
		renderPieces();
		postShowing();
	}

	/* ─────────────────────────── mute and cover ─────────────────────────── */

	function setMuted( on, byCover ) {
		S.muted = !! on;
		if ( ! byCover ) {
			S.coverMuted = false;
		}
		if ( mixer ) {
			mixer.setMuted( S.muted );
		}
		var btn = $( 'mute' );
		btn.classList.toggle( 'is-on', S.muted );
		btn.setAttribute( 'aria-pressed', S.muted ? 'true' : 'false' );
		$( 'mute-label' ).textContent = S.muted ? T.unmute : T.mute;
		root.classList.toggle( 'is-muted', S.muted );
		saveSoon();
	}

	function updatePeek() {
		var peek = $( 'peek' );
		var source = stage.sources[ stage.main ];
		peek.hidden = ! stage.cover || ! source;
		var video = $( 'peek-video' );
		if ( stage.cover && source && video.srcObject !== source.stream ) {
			video.srcObject = source.stream;
			video.style.transform = source.flip ? 'scaleX(-1)' : '';
		}
		if ( ! stage.cover && video.srcObject ) {
			video.srcObject = null;
		}
	}

	function setCover( on ) {
		on = !! on;
		stage.setCover( on, $( 'cover-line' ).value );
		var btn = $( 'cover' );
		btn.classList.toggle( 'is-on', on );
		btn.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
		$( 'cover-label' ).textContent = on ? T.uncover : T.cover;
		root.classList.toggle( 'is-covered', on );

		if ( on ) {
			if ( $( 'cover-mute' ).checked && ! S.muted ) {
				S.coverMuted = true;
				setMuted( true, true );
			}
			select( null );
		} else if ( S.coverMuted ) {
			S.coverMuted = false;
			setMuted( false, true );
		}
		updatePeek();
		saveSoon();
		if ( S.onAir ) {
			api( 'POST', D.id + '/cover', { covered: on } ).catch( function () {} );
		}
	}

	$( 'cover' ).addEventListener( 'click', function () {
		setCover( ! stage.cover );
	} );
	$( 'cover-line' ).addEventListener( 'change', function () {
		stage.setCover( stage.cover, this.value );
	} );
	$( 'mute' ).addEventListener( 'click', function () {
		setMuted( ! S.muted );
	} );

	/* ─────────────────────────── selecting things on stage ─────────────────────────── */

	function select( id ) {
		S.selected = id;
		var layer = id ? stage.get( id ) : null;
		var bar = $( 'selbar' );
		bar.hidden = ! layer;
		if ( ! layer ) {
			$( 'handles' ).textContent = '';
			renderLayers();
			return;
		}
		$( 'sel-name' ).textContent = 'spot' === layer.type ? T.spotNames[ layer.spotKind ] : ( T.typeLayer[ layer.type ] || T.camera );
		$( 'sel-size' ).value = layer.w;
		$( 'sel-size' ).disabled = 'ticker' === layer.type;
		$( 'sel-opacity' ).value = layer.opacity;
		$( 'sel-opacity' ).disabled = 'spot' === layer.type;
		$( 'sel-rot' ).value = layer.rot || 0;
		$( 'sel-rot' ).disabled = 'ticker' === layer.type || 'spot' === layer.type;
		$( 'sel-anim' ).value = layer.anim || 'none';
		$( 'sel-anim' ).disabled = 'ticker' === layer.type || 'spot' === layer.type;
		$( 'sel-shape-box' ).hidden = 'source' !== layer.type;
		$( 'sel-shape' ).value = layer.shape || 'rounded';

		var field = { text: 'text', ticker: 'text', lower: 'title', countdown: 'label' }[ layer.type ];
		var input = $( 'sel-text' );
		input.hidden = ! field;
		input.value = field ? layer[ field ] || '' : '';
		renderLayers();
	}

	function drawHandles() {
		var layer = S.selected ? stage.get( S.selected ) : null;
		var box = $( 'handles' );
		if ( ! layer || ! layer._box || layer._gone || stage.cover || stage.hideGraphics ) {
			if ( box.firstChild ) {
				box.textContent = '';
			}
			return;
		}
		var sel = box.firstChild;
		if ( ! sel ) {
			sel = el( 'div', 'whdls-sel' );
			[ 'nw', 'ne', 'sw', 'se' ].forEach( function ( corner ) {
				var knob = el( 'i', 'whdls-sel__knob whdls-sel__knob--' + corner );
				knob.setAttribute( 'data-handle', corner );
				sel.appendChild( knob );
			} );
			box.appendChild( sel );
		}
		var b = layer._box;
		sel.style.left = ( ( b.cx - b.w / 2 ) / stage.W * 100 ) + '%';
		sel.style.top = ( ( b.cy - b.h / 2 ) / stage.H * 100 ) + '%';
		sel.style.width = ( b.w / stage.W * 100 ) + '%';
		sel.style.height = ( b.h / stage.H * 100 ) + '%';
		sel.classList.toggle( 'is-band', 'ticker' === layer.type );
	}

	/* The dashed boxes. They live in the page, so they cannot reach the video. */
	function drawSpots() {
		Object.keys( SPOTS ).forEach( function ( kind ) {
			var layer = spotLayer( kind );
			var box = $( 'spot-' + kind );
			var on = layer && layer._box && false !== layer.visible && ! stage.cover && ! stage.hideGraphics;
			if ( box.hidden === !! on ) {
				box.hidden = ! on;
			}
			if ( on ) {
				var b = layer._box;
				box.style.left = ( ( b.cx - b.w / 2 ) / stage.W * 100 ) + '%';
				box.style.top = ( ( b.cy - b.h / 2 ) / stage.H * 100 ) + '%';
				box.style.width = ( b.w / stage.W * 100 ) + '%';
				box.style.height = ( b.h / stage.H * 100 ) + '%';
			}
		} );
	}

	function pointAt( e ) {
		var r = canvas.getBoundingClientRect();
		return { x: ( e.clientX - r.left ) / r.width, y: ( e.clientY - r.top ) / r.height };
	}

	var frame = $( 'frame' );
	frame.addEventListener( 'pointerdown', function ( e ) {
		if ( stage.cover || 'ended' === S.stage ) {
			return;
		}
		var p = pointAt( e );
		var handle = e.target.getAttribute && e.target.getAttribute( 'data-handle' );
		if ( handle && S.selected ) {
			S.drag = { mode: 'size', id: S.selected };
			frame.setPointerCapture( e.pointerId );
			e.preventDefault();
			return;
		}
		var hit = stage.hit( p.x, p.y );
		select( hit ? hit.id : null );
		if ( hit ) {
			S.drag = { mode: 'move', id: hit.id, dx: hit.x - p.x, dy: hit.y - p.y };
			frame.setPointerCapture( e.pointerId );
			e.preventDefault();
		}
	} );
	frame.addEventListener( 'pointermove', function ( e ) {
		if ( ! S.drag ) {
			return;
		}
		var layer = stage.get( S.drag.id );
		if ( ! layer ) {
			return;
		}
		var p = pointAt( e );
		if ( 'size' === S.drag.mode ) {
			if ( 'ticker' !== layer.type ) {
				layer.w = Math.max( 0.04, Math.min( 1, Math.abs( p.x - layer.x ) * 2 ) );
				$( 'sel-size' ).value = layer.w;
			}
		} else {
			if ( 'ticker' !== layer.type ) {
				layer.x = Math.max( 0, Math.min( 1, p.x + S.drag.dx ) );
			}
			layer.y = Math.max( 0, Math.min( 1, p.y + S.drag.dy ) );
		}
	} );
	var endDrag = function () {
		if ( ! S.drag ) {
			return;
		}
		var layer = stage.get( S.drag.id );
		S.drag = null;
		S.activeScene = '';
		renderScenes();
		saveSoon();
		if ( layer && ( 'product' === layer.type || 'gallery' === layer.type ) ) {
			postShowing();
		}
		if ( layer && ( 'spot' === layer.type || 'gallery' === layer.type || 'product' === layer.type ) ) {
			rememberSpot( layer );
		}
		ensureSpots(); // a picture dragged off its box lets the box show again
	};
	frame.addEventListener( 'pointerup', endDrag );
	frame.addEventListener( 'pointercancel', endDrag );

	function selProp( id, key, parse ) {
		$( id ).addEventListener( 'input', function () {
			if ( ! S.selected ) {
				return;
			}
			var props = {};
			props[ key ] = parse ? parse( this.value ) : this.value;
			stage.update( S.selected, props );
			S.activeScene = '';
			renderScenes();
			if ( 'w' === key ) {
				var layer = stage.get( S.selected );
				if ( layer && ( 'product' === layer.type || 'gallery' === layer.type ) ) {
					postShowingSoon();
				}
				if ( layer && ( 'spot' === layer.type || 'gallery' === layer.type || 'product' === layer.type ) ) {
					rememberSpot( layer );
				}
			}
		} );
	}
	selProp( 'sel-size', 'w', Number );
	selProp( 'sel-opacity', 'opacity', Number );
	selProp( 'sel-rot', 'rot', Number );
	selProp( 'sel-anim', 'anim' );
	selProp( 'sel-shape', 'shape' );

	$( 'sel-text' ).addEventListener( 'input', function () {
		var layer = S.selected ? stage.get( S.selected ) : null;
		if ( ! layer ) {
			return;
		}
		var field = { text: 'text', ticker: 'text', lower: 'title', countdown: 'label' }[ layer.type ];
		if ( field ) {
			var props = {};
			props[ field ] = this.value;
			stage.update( layer.id, props );
		}
	} );
	$( 'sel-raise' ).addEventListener( 'click', function () {
		if ( S.selected ) {
			stage.raise( S.selected );
		}
	} );
	$( 'sel-remove' ).addEventListener( 'click', function () {
		removeLayer( S.selected );
	} );

	var animBox = $( 'sel-anim' );
	Object.keys( Stage.ANIMS ).forEach( function ( key ) {
		var o = el( 'option', '', Stage.ANIMS[ key ] );
		o.value = key;
		animBox.appendChild( o );
	} );

	function removeLayer( id ) {
		var layer = id ? stage.get( id ) : null;
		if ( ! layer ) {
			return;
		}
		if ( 'spot' === layer.type ) {
			setSpotsOn( false );
			select( null );
			return;
		}
		var wasProduct = 'product' === layer.type;
		stage.remove( id );
		if ( S.selected === id ) {
			select( null );
		}
		if ( wasProduct ) {
			S.showing = 0;
			ensureSpots();
			renderPieces();
			postShowing();
		}
		if ( 'gallery' === layer.type ) {
			closePhotos();
		}
		if ( 'source' === layer.type ) {
			setTimeout( renderSources, 300 );
		}
	}

	/* ─────────────────────────── on stage list ─────────────────────────── */

	function layerName( layer ) {
		switch ( layer.type ) {
			case 'text':
			case 'ticker':
				return layer.text;
			case 'lower':
				return layer.title;
			case 'product':
				return layer.name;
			case 'gallery':
				return layer.caption;
			case 'countdown':
				return layer.label;
			case 'source':
				return stage.sources[ layer.sourceId ] ? stage.sources[ layer.sourceId ].label : T.camera;
			default:
				return T.typeLayer.image;
		}
	}

	function renderLayers() {
		var box = $( 'layers' );
		if ( ! box ) {
			return;
		}
		box.textContent = '';
		var list = stage.layers.filter( function ( l ) {
			return ! l._gone && 'spot' !== l.type;
		} ).slice().reverse();
		if ( ! list.length ) {
			box.appendChild( el( 'li', 'whdls-empty', T.nothingOn ) );
		}
		list.forEach( function ( layer ) {
			var row = el( 'li', 'whdls-layer' + ( S.selected === layer.id ? ' is-on' : '' ) );
			var pick = el( 'button', 'whdls-layer__name' );
			pick.type = 'button';
			pick.appendChild( el( 'small', '', T.typeLayer[ layer.type ] || T.camera ) );
			pick.appendChild( el( 'span', '', layerName( layer ) ) );
			pick.addEventListener( 'click', function () {
				select( layer.id );
			} );
			row.appendChild( pick );

			var eye = el( 'button', 'whdls-mini' + ( false === layer.visible ? '' : ' is-on' ), false === layer.visible ? 'Off' : 'On' );
			eye.type = 'button';
			eye.addEventListener( 'click', function () {
				stage.update( layer.id, { visible: false === layer.visible } );
			} );
			row.appendChild( eye );

			var x = el( 'button', 'whdls-mini whdls-mini--danger', '×' );
			x.type = 'button';
			x.setAttribute( 'aria-label', T.remove );
			x.addEventListener( 'click', function () {
				removeLayer( layer.id );
			} );
			row.appendChild( x );
			box.appendChild( row );
		} );
	}

	$( 'clear' ).addEventListener( 'click', function () {
		var hadProduct = !! productLayer();
		stage.clear();
		S.rain = '';
		renderEffects();
		select( null );
		photoToken++;
		var hadPhotos = !! S.photos;
		S.photos = 0;
		S.cardBack = null;
		ensureSpots();
		if ( hadProduct || hadPhotos ) {
			S.showing = 0;
			postShowing();
		}
		renderPieces();
	} );

	/* ─────────────────────────── pieces ─────────────────────────── */

	function productLayer() {
		return stage.layers.filter( function ( l ) {
			return 'product' === l.type && ! l._gone;
		} )[ 0 ] || null;
	}

	var showingTimer = null;
	function postShowingSoon() {
		clearTimeout( showingTimer );
		showingTimer = setTimeout( postShowing, 300 );
	}

	/* Where the card is, so the page can put a clickable area over it. Waits a frame for the box. */
	function postShowing() {
		setTimeout( function () {
			var layer = productLayer() || galleryLayer();
			var rect = layer ? stage.rect( layer.id ) : null;
			if ( 'ended' !== S.stage ) {
				api( 'POST', D.id + '/showing', { product: layer ? layer.productId : 0, rect: rect } ).catch( function () {} );
			}
			if ( rec && ! rec.stopped ) {
				recWatch( rec ); // the moment exactly, rather than at the next look
			}
		}, 80 );
	}

	/* What to comment for this piece: the host's code, or its place in the list. */
	function sayFor( piece ) {
		return String( piece.code || '' ).trim() || String( S.pieces.indexOf( piece ) + 1 );
	}

	function ctaFor( piece ) {
		return D.cta ? D.cta.replace( /\{code\}/g, sayFor( piece ) ) : '';
	}

	/* The card in the video follows the code as it is typed, and the numbers when the list changes. */
	function refreshCard() {
		var layer = productLayer();
		var piece = layer ? S.pieces.filter( function ( p ) {
			return p.id === layer.productId;
		} )[ 0 ] : null;
		if ( piece && layer.cta !== ctaFor( piece ) ) {
			stage.update( layer.id, { cta: ctaFor( piece ) } );
		}
		var photos = galleryLayer();
		var shown = photos ? S.pieces.filter( function ( p ) {
			return p.id === photos.productId;
		} )[ 0 ] : null;
		if ( shown && photos.cta !== ctaFor( shown ) ) {
			stage.update( photos.id, { cta: ctaFor( shown ) } );
		}
	}

	function showPiece( piece, at ) {
		if ( S.photos ) {
			closePhotos( true ); // the card or the photos, never both: the photos already say what the card says
		}
		preloadPhotos( piece ); // so that Photos, when it is pressed, is instant
		var old = productLayer() || at || S.spots.card;
		var props = {
			type: 'product',
			productId: piece.id,
			name: piece.name,
			price: piece.price,
			regular: piece.regular,
			image: piece.image,
			link: piece.shortUrl,
			cta: ctaFor( piece ),
			kicker: T.nowShowing,
			x: old.x,
			y: old.y,
			w: old.w
		};
		if ( old ) {
			stage.remove( old.id );
		}
		var layer = stage.add( props );
		ensureSpots();
		S.showing = piece.id;
		S.activeScene = '';
		renderScenes();
		renderPieces();
		select( layer.id );
		postShowing();
	}

	function hidePiece() {
		var layer = productLayer();
		if ( layer ) {
			removeLayer( layer.id ); // also tells the page
		}
	}

	/* ─ a piece's photos, large, at the spot the host has chosen ─ */

	function galleryLayer() {
		return stage.layers.filter( function ( l ) {
			return 'gallery' === l.type && ! l._gone;
		} )[ 0 ] || null;
	}

	function spotLayer( kind ) {
		return stage.layers.filter( function ( l ) {
			return 'spot' === l.type && kind === l.spotKind && ! l._gone;
		} )[ 0 ] || null;
	}

	/* Is a picture standing in its box? Its label over the top would look as if it were part of the picture. */
	function pictureInBox() {
		var spot = S.spots.picture;
		return stage.layers.some( function ( l ) {
			return 'image' === l.type && ! l._gone && ! l._gif && Math.abs( l.x - spot.x ) < 0.01 && Math.abs( l.y - spot.y ) < 0.01;
		} );
	}

	function gifIsUp() {
		return stage.layers.some( function ( l ) {
			return l._gif && ! l._gone;
		} );
	}

	/* Only the boxes for what this tab puts on stage, and not for something already up there. */
	function spotWanted( kind ) {
		if ( ! S.spotsOn ) {
			return false;
		}
		switch ( kind ) {
			case 'photos':
				return 'pieces' === S.tab && ! galleryLayer();
			case 'card':
				return 'pieces' === S.tab && ! productLayer() && ! galleryLayer();
			case 'picture':
				return 'images' === S.tab && ! pictureInBox();
			default:
				return 'gifs' === S.tab && ! gifIsUp();
		}
	}

	/* The markers are layers so they drag and size like everything else. At the bottom, so they never get in the way. */
	function ensureSpots() {
		Object.keys( SPOTS ).forEach( function ( kind ) {
			var layer = spotLayer( kind );
			if ( ! layer ) {
				var spot = S.spots[ kind ];
				layer = stage.add( { type: 'spot', spotKind: kind, ratio: SPOTS[ kind ].ratio, x: spot.x, y: spot.y, w: spot.w, _brief: true }, true );
				stage.layers.splice( stage.layers.indexOf( layer ), 1 );
				stage.layers.unshift( layer );
			}
			layer.visible = spotWanted( kind );
			if ( ! layer.visible && S.selected === layer.id ) {
				select( null );
			}
		} );
	}

	/* A card or the photos dragged where they stand is the same as moving their box. */
	function rememberSpot( layer ) {
		var kind = 'spot' === layer.type ? layer.spotKind : ( 'gallery' === layer.type ? 'photos' : 'card' );
		var spot = S.spots[ kind ];
		spot.x = layer.x;
		spot.y = layer.y;
		spot.w = layer.w;
		var marker = spotLayer( kind );
		if ( marker ) {
			marker.x = layer.x;
			marker.y = layer.y;
			marker.w = layer.w;
		}
		renderSpotPlaces( kind );
		saveSoon();
	}

	function placeSpot( kind, key ) {
		var place = SPOTS[ kind ].places[ key ];
		var spot = S.spots[ kind ];
		spot.x = place[ 0 ];
		spot.y = place[ 1 ];
		spot.w = place[ 2 ];
		// Something already up there moves with its box.
		var up = 'photos' === kind ? galleryLayer() : ( 'card' === kind ? productLayer() : null );
		[ spotLayer( kind ), up ].forEach( function ( layer ) {
			if ( layer ) {
				layer.x = spot.x;
				layer.y = spot.y;
				layer.w = spot.w;
			}
		} );
		renderSpotPlaces( kind );
		if ( up ) {
			postShowingSoon();
		}
		saveSoon();
	}

	function setSpotsOn( on ) {
		S.spotsOn = !! on;
		document.querySelectorAll( '.whdls-spots-on' ).forEach( function ( box ) {
			box.checked = !! on;
		} );
		ensureSpots();
		saveSoon();
	}

	function renderSpotPlaces( kind ) {
		var box = $( 'places-' + kind );
		var spot = S.spots[ kind ];
		box.textContent = '';
		Object.keys( SPOTS[ kind ].places ).forEach( function ( key ) {
			var place = SPOTS[ kind ].places[ key ];
			var here = Math.abs( place[ 0 ] - spot.x ) < 0.005 && Math.abs( place[ 1 ] - spot.y ) < 0.005;
			var btn = el( 'button', here ? 'is-on' : '', T.spotPlaces[ key ] );
			btn.type = 'button';
			btn.addEventListener( 'click', function () {
				placeSpot( kind, key );
			} );
			box.appendChild( btn );
		} );
	}

	function photosOf( piece ) {
		return piece.images && piece.images.length ? piece.images : ( piece.image ? [ piece.image ] : [] );
	}

	function preloadPhotos( piece ) {
		photosOf( piece ).forEach( function ( src ) {
			stage.image( src );
		} );
	}

	var photoToken = 0;

	function openPhotos( piece ) {
		var images = photosOf( piece );
		if ( ! images.length ) {
			return;
		}
		if ( S.photos ) {
			closePhotos( true );
		}
		S.photos = piece.id;
		// The photos stand in for the card while they are up. If it was this piece's card, it comes back afterwards.
		var card = productLayer();
		S.cardBack = card && card.productId === piece.id ? { piece: piece, x: card.x, y: card.y, w: card.w } : null;
		if ( card ) {
			if ( S.selected === card.id ) {
				select( null );
			}
			stage.remove( card.id );
			S.showing = 0;
		}
		var token = ++photoToken;
		var started = false;
		var start = function () {
			if ( started || token !== photoToken ) {
				return;
			}
			started = true;
			stage.add( {
				type: 'gallery',
				productId: piece.id,
				images: images,
				caption: piece.name,
				price: piece.price,
				cta: ctaFor( piece ),
				x: S.spots.photos.x,
				y: S.spots.photos.y,
				w: S.spots.photos.w
			} );
			ensureSpots();
			select( null );
			// This is what makes the piece public: the photos say what to comment, and a number only works once it has been on air.
			postShowing();
			saveSoon();
		};
		// The first photo ready before anything appears, unless the connection is slow: then the card comes anyway.
		stage.image( images[ 0 ], start );
		preloadPhotos( piece );
		setTimeout( start, 1200 );
		renderPieces();
	}

	function closePhotos( noCard ) {
		photoToken++;
		var layer = galleryLayer();
		if ( layer ) {
			stage.remove( layer.id );
		}
		var back = noCard ? null : S.cardBack;
		S.cardBack = null;
		S.photos = 0;
		ensureSpots();
		if ( back ) {
			showPiece( back.piece, back );
		} else {
			renderPieces();
			postShowing();
		}
		saveSoon();
	}

	function photoControls( piece ) {
		var box = el( 'div', 'whdls-slidectl' );
		var button = function ( label, name, run ) {
			var b = el( 'button', 'whdls-mini', label );
			b.type = 'button';
			b.setAttribute( 'aria-label', name );
			b.addEventListener( 'click', run );
			box.appendChild( b );
			return b;
		};
		var step = function ( by ) {
			var layer = galleryLayer();
			if ( layer ) {
				stage.slide( layer.id, by );
			}
		};
		button( '‹', T.photoPrev, function () {
			step( -1 );
		} );
		var layer = galleryLayer();
		var hold = button( layer && false === layer.auto ? T.photoPlay : T.photoHold, T.photoHold, function () {
			var now = galleryLayer();
			if ( now ) {
				now.auto = false === now.auto;
				hold.textContent = false === now.auto ? T.photoPlay : T.photoHold;
			}
		} );
		button( '›', T.photoNext, function () {
			step( 1 );
		} );
		return box;
	}

	function asked( pieceId ) {
		var n = 0;
		Object.keys( S.comments ).forEach( function ( id ) {
			var c = S.comments[ id ];
			if ( c.product === pieceId && ( 'sent' === c.status || 'queued' === c.status || 'sending' === c.status ) ) {
				n++;
			}
		} );
		return n;
	}

	var piecesTimer = null;
	function savePieces() {
		clearTimeout( piecesTimer );
		piecesTimer = setTimeout( function () {
			api( 'POST', D.id + '/pieces', {
				pieces: S.pieces.map( function ( p ) {
					return { id: p.id, code: p.code || '' };
				} )
			} ).then( function ( res ) {
				// A piece added from the search comes back with its photos.
				( res.pieces || [] ).forEach( function ( full ) {
					S.pieces.forEach( function ( piece ) {
						if ( piece.id === full.id ) {
							piece.images = full.images;
						}
					} );
				} );
			} ).catch( function ( err ) {
				banner( 'pieces', err.message );
			} );
		}, 500 );
	}

	function codeTaken( piece ) {
		var code = String( piece.code || '' ).trim().toLowerCase();
		return !! code && S.pieces.some( function ( p ) {
			return p !== piece && String( p.code || '' ).trim().toLowerCase() === code;
		} );
	}

	function renderPieces() {
		var box = $( 'pieces' );
		box.textContent = '';
		if ( ! S.pieces.length ) {
			box.appendChild( el( 'li', 'whdls-empty', T.noPieces ) );
			return;
		}
		S.pieces.forEach( function ( piece, i ) {
			var on = S.showing === piece.id;
			var open = S.photos === piece.id;
			var row = el( 'li', 'whdls-piece' + ( on || open ? ' is-on' : '' ) );
			row.appendChild( el( 'span', 'whdls-piece__n', String( i + 1 ) ) );
			var img = el( 'img' );
			img.src = piece.thumb;
			img.alt = '';
			row.appendChild( img );

			var mid = el( 'div', 'whdls-piece__mid' );
			mid.appendChild( el( 'strong', '', piece.name ) );
			var line = el( 'span', 'whdls-piece__line' );
			line.appendChild( el( 'b', '', piece.price ) );
			if ( piece.regular ) {
				line.appendChild( el( 's', '', piece.regular ) );
			}
			if ( ! piece.stock ) {
				line.appendChild( el( 'em', '', T.soldOut ) );
			}
			var n = asked( piece.id );
			if ( n ) {
				line.appendChild( el( 'em', 'is-asked', n + ' ' + T.requests ) );
			}
			mid.appendChild( line );
			var key = el( 'input', 'whdls-piece__key' );
			key.type = 'text';
			key.placeholder = fmt( T.codeOr, i + 1 );
			key.value = piece.code || '';
			key.setAttribute( 'aria-label', T.code );
			var markTaken = function () {
				var taken = codeTaken( piece );
				key.classList.toggle( 'is-taken', taken );
				key.title = taken ? T.codeDup : '';
			};
			markTaken();
			key.addEventListener( 'input', function () {
				piece.code = key.value;
				markTaken();
				refreshCard();
				savePieces();
			} );
			mid.appendChild( key );
			row.appendChild( mid );

			var acts = el( 'div', 'whdls-piece__do' );
			var show = el( 'button', 'whdls-show', on ? T.hide : T.show );
			show.type = 'button';
			show.addEventListener( 'click', function () {
				if ( on ) {
					hidePiece();
				} else {
					showPiece( piece );
				}
			} );
			acts.appendChild( show );
			var photos = el( 'button', 'whdls-mini whdls-photos' + ( open ? ' is-on' : '' ), open ? T.photosClose : fmt( T.photos, photosOf( piece ).length ) );
			photos.type = 'button';
			photos.title = T.photosHint;
			photos.addEventListener( 'pointerenter', function () {
				preloadPhotos( piece );
			} );
			photos.addEventListener( 'click', function () {
				if ( open ) {
					closePhotos();
				} else {
					openPhotos( piece );
				}
			} );
			acts.appendChild( photos );
			var drop = el( 'button', 'whdls-mini whdls-mini--danger', T.remove );
			drop.type = 'button';
			drop.addEventListener( 'click', function () {
				if ( on ) {
					hidePiece();
				}
				if ( open ) {
					closePhotos( true );
				}
				S.pieces.splice( i, 1 );
				renderPieces();
				refreshCard(); // the ones after it have new numbers
				savePieces();
			} );
			acts.appendChild( drop );
			row.appendChild( acts );
			if ( open && photosOf( piece ).length > 1 ) {
				row.appendChild( photoControls( piece ) );
			}
			box.appendChild( row );
		} );
	}

	var searchTimer = null;
	$( 'search' ).addEventListener( 'input', function () {
		var q = this.value.trim();
		clearTimeout( searchTimer );
		searchTimer = setTimeout( function () {
			runSearch( q );
		}, 220 );
	} );
	$( 'search' ).addEventListener( 'focus', function () {
		runSearch( this.value.trim() );
	} );
	document.addEventListener( 'click', function ( e ) {
		if ( ! e.target.closest || ! e.target.closest( '.whdls-search' ) ) {
			$( 'results' ).hidden = true;
		}
	} );

	function runSearch( q ) {
		api( 'GET', 'products?q=' + encodeURIComponent( q ) ).then( function ( res ) {
			var box = $( 'results' );
			box.textContent = '';
			( res.items || [] ).forEach( function ( item ) {
				var row = el( 'button', 'whdls-result' );
				row.type = 'button';
				var img = el( 'img' );
				img.src = item.thumb;
				img.alt = '';
				row.appendChild( img );
				var words = el( 'span' );
				words.appendChild( el( 'strong', '', item.name ) );
				words.appendChild( el( 'small', '', item.price ) );
				row.appendChild( words );
				row.addEventListener( 'click', function () {
					$( 'results' ).hidden = true;
					$( 'search' ).value = '';
					if ( ! S.pieces.some( function ( p ) {
						return p.id === item.id;
					} ) ) {
						S.pieces.push( item );
						renderPieces();
						savePieces();
					}
				} );
				box.appendChild( row );
			} );
			box.hidden = ! ( res.items || [] ).length;
		} ).catch( function () {} );
	}

	/* ─────────────────────────── media library ─────────────────────────── */

	function chooseMedia( opts, done ) {
		if ( ! window.wp || ! window.wp.media ) {
			return;
		}
		var frameMedia = window.wp.media( {
			title: opts.title,
			library: { type: opts.type },
			multiple: !! opts.multiple,
			button: { text: opts.button }
		} );
		frameMedia.on( 'select', function () {
			done( frameMedia.state().get( 'selection' ).toJSON() );
		} );
		frameMedia.open();
		// Straight to what is already there: mid-stream is not the moment for the upload tab.
		if ( frameMedia.content && frameMedia.content.mode ) {
			frameMedia.content.mode( 'browse' );
		}
	}

	/* ─────────────────────────── effects ─────────────────────────── */

	function renderEffects() {
		var box = $( 'effects' );
		box.textContent = '';
		Object.keys( Stage.EFFECTS ).forEach( function ( key ) {
			var fx = Stage.EFFECTS[ key ];
			var tile = el( 'button', 'whdls-tile' + ( S.rain === key ? ' is-on' : '' ) );
			tile.type = 'button';
			var icon = el( 'span', 'whdls-tile__icon', fx.icon || '' );
			if ( fx.paint ) {
				var picture = el( 'canvas' );
				picture.width = 72;
				picture.height = 72;
				picture.getContext( '2d' ).drawImage( stage.paintedSprite( fx.paint ), 0, 0, 72, 72 );
				icon.appendChild( picture );
			}
			tile.appendChild( icon );
			tile.appendChild( el( 'span', 'whdls-tile__label', fx.label ) );
			tile.addEventListener( 'click', function () {
				if ( 'rain' === S.effectMode ) {
					S.rain = S.rain === key ? '' : key;
					stage.setRain( S.rain || null );
					renderEffects();
				} else {
					stage.burst( key );
				}
			} );
			box.appendChild( tile );
		} );
		renderFloats();
	}

	function renderFloats() {
		var box = $( 'float-list' );
		box.textContent = '';
		S.floats.forEach( function ( url, i ) {
			var key = 'img:' + url;
			var tile = el( 'button', 'whdls-thumb' + ( S.rain === key ? ' is-on' : '' ) );
			tile.type = 'button';
			var img = el( 'img' );
			img.src = url;
			img.alt = '';
			tile.appendChild( img );
			var x = el( 'i', 'whdls-thumb__x', '×' );
			x.addEventListener( 'click', function ( e ) {
				e.stopPropagation();
				if ( S.rain === key ) {
					S.rain = '';
					stage.setRain( null );
				}
				S.floats.splice( i, 1 );
				renderFloats();
				saveSoon();
			} );
			tile.appendChild( x );
			tile.addEventListener( 'click', function () {
				if ( 'rain' === S.effectMode ) {
					S.rain = S.rain === key ? '' : key;
					stage.setRain( S.rain ? key : null, S.rain ? url : '' );
					renderFloats();
				} else {
					stage.floatImage( url );
				}
			} );
			box.appendChild( tile );
		} );
	}

	$( 'effect-mode' ).addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( 'button' );
		if ( ! btn ) {
			return;
		}
		S.effectMode = btn.getAttribute( 'data-mode' );
		this.querySelectorAll( 'button' ).forEach( function ( b ) {
			b.classList.toggle( 'is-on', b === btn );
		} );
	} );

	$( 'float-pick' ).addEventListener( 'click', function () {
		chooseMedia( { title: T.pickImage, type: 'image', button: T.useImage }, function ( files ) {
			if ( files[ 0 ] ) {
				var url = sizedUrl( files[ 0 ] );
				if ( S.floats.indexOf( url ) < 0 ) {
					S.floats.push( url );
				}
				renderFloats();
				saveSoon();
				stage.floatImage( url );
			}
		} );
	} );

	/* No need to load a 4000px file to draw it at 300. */
	function sizedUrl( file ) {
		// WordPress's smaller copies of a GIF are stills.
		if ( 'image/gif' === file.mime ) {
			return file.url;
		}
		var sizes = file.sizes || {};
		return ( sizes.large || sizes.medium_large || sizes.full || file ).url;
	}

	/* ─────────────────────────── pictures ─────────────────────────── */

	function addImage( url ) {
		var spot = S.spots.picture;
		var layer = stage.add( { type: 'image', src: url, x: spot.x, y: spot.y, w: spot.w, anim: 'none' } );
		select( layer.id );
	}

	function renderImages() {
		var box = $( 'image-list' );
		box.textContent = '';
		S.library.forEach( function ( url, i ) {
			var tile = el( 'button', 'whdls-thumb' );
			tile.type = 'button';
			var img = el( 'img' );
			img.src = url;
			img.alt = '';
			tile.appendChild( img );
			var x = el( 'i', 'whdls-thumb__x', '×' );
			x.addEventListener( 'click', function ( e ) {
				e.stopPropagation();
				S.library.splice( i, 1 );
				renderImages();
				saveSoon();
			} );
			tile.appendChild( x );
			tile.addEventListener( 'click', function () {
				addImage( url );
			} );
			box.appendChild( tile );
		} );
	}

	$( 'image-pick' ).addEventListener( 'click', function () {
		chooseMedia( { title: T.pickImage, type: 'image', button: T.useImage }, function ( files ) {
			if ( files[ 0 ] ) {
				var url = sizedUrl( files[ 0 ] );
				if ( S.library.indexOf( url ) < 0 ) {
					S.library.push( url );
				}
				renderImages();
				addImage( url );
			}
		} );
	} );

	if ( D.logo ) {
		$( 'logo-add' ).hidden = false;
		$( 'logo-add' ).addEventListener( 'click', function () {
			var layer = stage.add( { type: 'image', src: D.logo, x: portrait ? 0.14 : 0.08, y: portrait ? 0.07 : 0.11, w: portrait ? 0.18 : 0.1, opacity: 0.9 } );
			select( layer.id );
		} );
	}

	/* ─────────────────────────── occasions: a frame, its music and something falling, in one tap ─────────────────────────── */

	function occasionNow() {
		return stage.occasion && D.occasions && D.occasions[ stage.occasion.key ] ? D.occasions[ stage.occasion.key ] : null;
	}

	function frameFor( key ) {
		var o = D.occasions[ key ];
		return {
			key: key,
			words: undefined !== S.occWords[ key ] ? S.occWords[ key ] : o.words,
			font: o.font,
			band: o.band,
			line: o.line,
			ribbon: o.ribbon,
			ink: o.ink,
			art: o.art
		};
	}

	/*
	 * Swaps the list. Before going live, or if music is already playing, it starts straight away; on air with
	 * nothing playing it waits for Play, for the same reason adding a track does.
	 */
	function swapMusic( list ) {
		var playing = mixer && ! mixer.audio.paused;
		if ( mixer ) {
			mixer.stop();
		}
		S.music = list.slice();
		if ( mixer ) {
			mixer.setPlaylist( S.music );
			mixer.index = 0;
		}
		renderMusic();
		renderLibrary();
		if ( mixer && S.music.length && ( playing || ! S.onAir ) ) {
			mixer.playIndex( 0 );
		}
	}

	function occasionTracks( o ) {
		return ( o.tracks || [] ).map( function ( t ) {
			return { url: t.url, title: t.title };
		} );
	}

	function occasionRain( o ) {
		var on = S.occRain && o && o.rain;
		S.rain = on ? o.rain : '';
		stage.setRain( on ? o.rain : null, '', 0.34 );
		renderEffects();
	}

	function useOccasion( key ) {
		var o = D.occasions[ key ];
		stage.setOccasion( frameFor( key ) );
		stage.setLight( o.light || 'none' );
		S.activeScene = '';
		renderScenes();
		syncLooks();
		occasionRain( o );
		if ( S.occMusic ) {
			keepOwnMusic();
			swapMusic( occasionTracks( o ) );
		}
		renderOccasions();
		saveSoon();
	}

	/* What comes back when an occasion or a theme comes off: the host's own list, however many were on in between. */
	function keepOwnMusic() {
		if ( ! S.musicBefore ) {
			S.musicBefore = S.music.slice();
		}
	}

	function ownMusicBack() {
		if ( S.occMusic && S.musicBefore ) {
			swapMusic( S.musicBefore );
		}
		S.musicBefore = null;
	}

	function endOccasion() {
		var o = occasionNow();
		stage.setOccasion( null );
		if ( o && S.rain === o.rain ) {
			occasionRain( null );
		}
		if ( o && o.light === stage.light ) {
			stage.setLight( 'none' );
		}
		ownMusicBack();
		S.activeScene = '';
		renderScenes();
		syncLooks();
		renderOccasions();
		saveSoon();
	}

	function renderOccasions() {
		var box = $( 'occasions' );
		var now = occasionNow();
		box.textContent = '';
		Object.keys( D.occasions || {} ).forEach( function ( key ) {
			var o = D.occasions[ key ];
			var on = now && stage.occasion.key === key;
			var tile = el( 'button', 'whdls-tile whdls-tile--sticker' + ( on ? ' is-on' : '' ) );
			tile.type = 'button';
			tile.setAttribute( 'data-key', key );
			var icon = el( 'span', 'whdls-tile__icon' );
			var img = el( 'img' );
			img.src = o.art[ 0 ];
			img.alt = '';
			img.loading = 'lazy';
			icon.appendChild( img );
			tile.appendChild( icon );
			tile.appendChild( el( 'span', 'whdls-tile__label', o.label ) );
			tile.addEventListener( 'click', function () {
				if ( on ) {
					endOccasion();
				} else {
					useOccasion( key );
				}
			} );
			box.appendChild( tile );
		} );

		var panel = $( 'occ-panel' );
		panel.hidden = ! now;
		if ( now ) {
			$( 'occ-name' ).textContent = now.label;
			var words = $( 'occ-words' );
			if ( document.activeElement !== words ) {
				words.value = stage.occasion.words || '';
			}
		}
		renderOccasionTracks();
	}

	function renderOccasionTracks() {
		var now = occasionNow();
		var list = $( 'occ-tracks' );
		list.textContent = '';
		if ( now ) {
			( now.tracks || [] ).forEach( function ( track ) {
				list.appendChild( libraryRow( { url: track.url, title: track.title, mood: now.label, seconds: track.seconds } ) );
			} );
		}
	}

	$( 'occ-words' ).addEventListener( 'input', function () {
		if ( stage.occasion ) {
			S.occWords[ stage.occasion.key ] = this.value;
			stage.setOccasion( frameFor( stage.occasion.key ) );
			saveSoon();
		}
	} );
	$( 'occ-off' ).addEventListener( 'click', endOccasion );
	/* The same two choices for occasions and for themes, each with its own switches because each has its own tab. */
	function syncDressSwitches() {
		$( 'occ-music' ).checked = S.occMusic;
		$( 'theme-music' ).checked = S.occMusic;
		$( 'occ-rain' ).checked = S.occRain;
		$( 'theme-rain' ).checked = S.occRain;
	}
	[ 'occ-music', 'theme-music' ].forEach( function ( id ) {
		$( id ).addEventListener( 'change', function () {
			S.occMusic = this.checked;
			syncDressSwitches();
			saveSoon();
		} );
	} );
	[ 'occ-rain', 'theme-rain' ].forEach( function ( id ) {
		$( id ).addEventListener( 'change', function () {
			S.occRain = this.checked;
			syncDressSwitches();
			var on = occasionNow() || Stage.THEMES[ themeNow() ];
			if ( on ) {
				occasionRain( on );
			}
			saveSoon();
		} );
	} );
	syncDressSwitches();

	/* ─────────────────────────── GIFs and moving stickers ─────────────────────────── */

	/* At their box, and fanned out around it when several are up at once, so a quick run of taps does not pile up. */
	var GIF_FAN = [ [ 0, 0 ], [ 0.13, 0.1 ], [ -0.13, 0.1 ], [ 0.13, -0.1 ], [ -0.13, -0.1 ] ];

	function popGif( src, anim, notOurs ) {
		var spot = S.spots.gif;
		var up = stage.layers.filter( function ( l ) {
			return l._gif && ! l._gone;
		} ).length;
		var fan = GIF_FAN[ up % GIF_FAN.length ];
		var at = [
			Math.max( 0.06, Math.min( 0.94, spot.x + fan[ 0 ] * ( portrait ? 2.2 : 1 ) ) ),
			Math.max( 0.06, Math.min( 0.94, spot.y + fan[ 1 ] * ( portrait ? 0.7 : 1.4 ) ) )
		];
		var stay = +$( 'gif-stay' ).value;
		var def = { type: 'image', src: src, x: at[ 0 ], y: at[ 1 ], w: spot.w, anim: anim || 'none', enter: 'pop', rot: Math.round( Math.random() * 16 - 8 ), _gif: true };
		if ( stay || notOurs ) {
			def._brief = true; // gone in a moment, or KLIPY's, which may not be stored: not part of what a reload brings back
		}
		var layer = stage.add( def );
		if ( stay ) {
			setTimeout( function () {
				if ( S.selected === layer.id ) {
					select( null );
				}
				stage.remove( layer.id );
			}, stay * 1000 );
		} else {
			select( layer.id );
		}
	}

	/*
	 * Searching KLIPY. Its rules shape this: calls and media go straight from this browser with no proxy, URLs are used as
	 * given, results keep the order and number they arrive in, nothing of theirs is stored, and the box says "Search KLIPY".
	 * A key has few calls an hour, so it searches on Enter rather than on every letter.
	 */
	var klipy = { kind: 'gifs', q: '', page: 1, run: 0, busy: false, started: false };

	function klipyCustomer() {
		var id = '';
		try {
			id = window.localStorage.getItem( 'whd_klipy_id' ) || '';
		} catch ( e ) {}
		if ( ! id ) {
			id = window.crypto && window.crypto.randomUUID ? window.crypto.randomUUID() : 'h' + Date.now().toString( 36 ) + Math.random().toString( 36 ).slice( 2 );
			try {
				window.localStorage.setItem( 'whd_klipy_id', id );
			} catch ( e ) {}
		}
		return id;
	}

	function klipyAddress( path ) {
		return 'https://api.klipy.com/api/v1/' + encodeURIComponent( D.klipy ) + '/' + path;
	}

	function klipyNote( text ) {
		var note = $( 'klipy-note' );
		note.textContent = text;
		note.hidden = ! text;
	}

	/* What was picked, which KLIPY uses for its ranking and asks to be told. */
	function klipyShared( item ) {
		window.fetch( klipyAddress( klipy.kind + '/share/' + encodeURIComponent( item.slug ) ), {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( { customer_id: klipyCustomer(), q: klipy.q } )
		} ).catch( function () {} );
	}

	function klipyTile( item ) {
		var file = item.file || {};
		var pick = function ( size, kind ) {
			return file[ size ] && file[ size ][ kind ] ? file[ size ][ kind ].url : '';
		};
		var moving = pick( 'sm', 'webp' ) || pick( 'sm', 'gif' );
		var still = pick( 'sm', 'jpg' ) || moving;
		var full = pick( 'md', 'webp' ) || pick( 'md', 'gif' ) || pick( 'hd', 'webp' ) || pick( 'hd', 'gif' ) || moving;
		if ( ! full ) {
			return null; // an ad, if somebody turns those on for the key: nothing here can show one, and it must not reach the video
		}
		var tile = el( 'button', 'whdls-thumb' );
		tile.type = 'button';
		tile.title = item.title || '';
		var img = el( 'img' );
		img.src = still;
		img.alt = item.title || '';
		img.loading = 'lazy';
		tile.appendChild( img );
		// Still until pointed at: two dozen playing at once is work taken from the video.
		tile.addEventListener( 'pointerenter', function () {
			img.src = moving;
		} );
		tile.addEventListener( 'pointerleave', function () {
			img.src = still;
		} );
		tile.addEventListener( 'click', function () {
			popGif( full, 'none', true );
			klipyShared( item );
		} );
		return tile;
	}

	function klipySearch( more ) {
		if ( ! D.klipy || ( more && klipy.busy ) ) {
			return;
		}
		var run = ++klipy.run;
		var page = more ? klipy.page + 1 : 1;
		var address = new URL( klipyAddress( klipy.kind + ( klipy.q ? '/search' : '/trending' ) ) );
		address.searchParams.set( 'page', page );
		address.searchParams.set( 'per_page', 24 );
		address.searchParams.set( 'customer_id', klipyCustomer() );
		address.searchParams.set( 'content_filter', 'high' ); // customers are watching
		if ( klipy.q ) {
			address.searchParams.set( 'q', klipy.q );
		}
		klipy.busy = true;
		klipyNote( '' );
		window.fetch( address.toString() ).then( function ( r ) {
			return r.json().catch( function () {
				return {};
			} ).then( function ( res ) {
				if ( ! r.ok || false === res.result ) {
					var error = new Error( 'klipy' );
					error.status = r.status;
					throw error;
				}
				return res.data || {};
			} );
		} ).then( function ( data ) {
			if ( run !== klipy.run ) {
				return;
			}
			klipy.busy = false;
			klipy.page = page;
			var grid = $( 'klipy-grid' );
			if ( ! more ) {
				grid.textContent = '';
				grid.scrollTop = 0;
			}
			( data.data || [] ).forEach( function ( item ) {
				var tile = klipyTile( item );
				if ( tile ) {
					grid.appendChild( tile );
				}
			} );
			$( 'klipy-more' ).hidden = ! data.has_next;
			if ( ! grid.children.length ) {
				klipyNote( T.klipyNone );
			}
		} ).catch( function ( err ) {
			if ( run !== klipy.run ) {
				return;
			}
			klipy.busy = false;
			$( 'klipy-more' ).hidden = true;
			// A wrong key comes back as "not found", since the key is part of the address.
			klipyNote( 429 === err.status ? T.klipyLimit : ( 401 === err.status || 403 === err.status || 404 === err.status ? T.klipyKey : T.klipyFail ) );
		} );
	}

	/* Spends a call, so only when somebody actually opens the tab. */
	function klipyFirstLook() {
		if ( D.klipy && ! klipy.started ) {
			klipy.started = true;
			klipySearch( false );
		}
	}

	$( 'klipy-off' ).hidden = !! D.klipy;
	$( 'klipy' ).hidden = ! D.klipy;
	$( 'klipy-form' ).addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		klipy.q = $( 'klipy-q' ).value.trim();
		klipySearch( false );
	} );
	$( 'klipy-more' ).addEventListener( 'click', function () {
		klipySearch( true );
	} );
	$( 'klipy-kind' ).addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( 'button' );
		if ( ! btn || btn.getAttribute( 'data-kind' ) === klipy.kind ) {
			return;
		}
		klipy.kind = btn.getAttribute( 'data-kind' );
		this.querySelectorAll( 'button' ).forEach( function ( b ) {
			b.classList.toggle( 'is-on', b === btn );
		} );
		klipySearch( false );
	} );

	function renderStickers() {
		var all = D.stickers || [];
		var groups = $( 'gif-groups' );
		var box = $( 'stickers' );
		groups.textContent = '';
		box.textContent = '';
		var names = all.reduce( function ( list, item ) {
			return list.indexOf( item.group ) < 0 ? list.concat( item.group ) : list;
		}, [] );
		[ '' ].concat( names ).forEach( function ( name ) {
			var btn = el( 'button', 'whdls-mini' + ( S.stickerGroup === name ? ' is-on' : '' ), name || T.allMoods );
			btn.type = 'button';
			btn.addEventListener( 'click', function () {
				S.stickerGroup = name;
				renderStickers();
			} );
			groups.appendChild( btn );
		} );
		all.forEach( function ( item ) {
			if ( S.stickerGroup && item.group !== S.stickerGroup ) {
				return;
			}
			var tile = el( 'button', 'whdls-tile whdls-tile--sticker' );
			tile.type = 'button';
			var icon = el( 'span', 'whdls-tile__icon' );
			var img = el( 'img' );
			img.src = item.url;
			img.alt = '';
			img.loading = 'lazy';
			icon.appendChild( img );
			tile.appendChild( icon );
			tile.appendChild( el( 'span', 'whdls-tile__label', item.label ) );
			tile.addEventListener( 'click', function () {
				popGif( item.url, item.anim );
				tile.classList.add( 'is-hit' );
				setTimeout( function () {
					tile.classList.remove( 'is-hit' );
				}, 180 );
			} );
			box.appendChild( tile );
		} );
	}

	function renderGifs() {
		var box = $( 'gif-list' );
		box.textContent = '';
		S.gifs.forEach( function ( url, i ) {
			var tile = el( 'button', 'whdls-thumb' );
			tile.type = 'button';
			var img = el( 'img' );
			img.src = url;
			img.alt = '';
			img.loading = 'lazy';
			tile.appendChild( img );
			var x = el( 'i', 'whdls-thumb__x', '×' );
			x.addEventListener( 'click', function ( e ) {
				e.stopPropagation();
				S.gifs.splice( i, 1 );
				renderGifs();
				saveSoon();
			} );
			tile.appendChild( x );
			tile.addEventListener( 'click', function () {
				popGif( url, 'none' );
			} );
			box.appendChild( tile );
		} );
	}

	$( 'gif-pick' ).addEventListener( 'click', function () {
		chooseMedia( { title: T.pickGif, type: [ 'image/gif', 'image/webp' ], multiple: true, button: T.useGif }, function ( files ) {
			files.forEach( function ( file ) {
				if ( S.gifs.indexOf( file.url ) < 0 ) {
					S.gifs.push( file.url );
				}
			} );
			renderGifs();
			saveSoon();
			if ( files[ 0 ] ) {
				popGif( files[ 0 ].url, 'none' );
			}
		} );
	} );
	if ( undefined !== saved.gifStay ) {
		$( 'gif-stay' ).value = String( saved.gifStay );
	}
	$( 'gif-stay' ).addEventListener( 'change', saveSoon );
	renderStickers();
	renderGifs();
	renderOccasions();
	if ( ( occasionNow() || themeNow() ) && S.occRain ) {
		occasionRain( occasionNow() || Stage.THEMES[ themeNow() ] ); // a reload keeps the snow falling
	}

	document.querySelectorAll( '.whdls-spots-on' ).forEach( function ( box ) {
		box.checked = S.spotsOn;
		box.addEventListener( 'change', function () {
			setSpotsOn( this.checked );
		} );
	} );
	Object.keys( SPOTS ).forEach( renderSpotPlaces );

	/* ─────────────────────────── text ─────────────────────────── */

	var TEXT_STYLES = {
		ribbon: 'Ribbon',
		serif: 'Serif',
		tag: 'Tag',
		dark: 'Dark',
		neon: 'Neon',
		burst: 'Burst'
	};
	var textStyle = 'ribbon';

	var TEXT_PRESETS = [
		{ text: 'Flash sale', style: 'ribbon' },
		{ text: 'Just dropped', style: 'serif' },
		{ text: 'Last few left', style: 'dark' },
		{ text: 'New in', style: 'tag' },
		{ text: 'SALE', style: 'burst' },
		{ text: T.commentCode, style: 'neon', code: true },
		{ text: 'Link in comments', style: 'ribbon' },
		{ text: 'Back in stock', style: 'tag' }
	];

	function addText( text, style ) {
		var layer = stage.add( {
			type: 'text',
			text: text,
			style: style,
			x: 0.5,
			y: portrait ? 0.14 : 0.16,
			w: 'burst' === style ? ( portrait ? 0.4 : 0.2 ) : ( portrait ? 0.74 : 0.4 ),
			anim: 'burst' === style ? 'pulse' : 'none'
		} );
		select( layer.id );
	}

	function renderTextTools() {
		var presets = $( 'text-presets' );
		TEXT_PRESETS.forEach( function ( preset ) {
			var btn = el( 'button', 'whdls-preset whdls-preset--' + preset.style, preset.text );
			btn.type = 'button';
			btn.addEventListener( 'click', function () {
				// With a piece on screen, "Comment the code" can say which code.
				var on = preset.code && S.pieces.filter( function ( p ) {
					return p.id === S.showing;
				} )[ 0 ];
				addText( on ? ctaFor( on ) || preset.text : preset.text, preset.style );
			} );
			presets.appendChild( btn );
		} );
		var styles = $( 'text-styles' );
		Object.keys( TEXT_STYLES ).forEach( function ( key ) {
			var btn = el( 'button', 'whdls-mini' + ( key === textStyle ? ' is-on' : '' ), TEXT_STYLES[ key ] );
			btn.type = 'button';
			btn.addEventListener( 'click', function () {
				textStyle = key;
				styles.querySelectorAll( 'button' ).forEach( function ( b ) {
					b.classList.toggle( 'is-on', b === btn );
				} );
			} );
			styles.appendChild( btn );
		} );
	}

	$( 'text-add' ).addEventListener( 'click', function () {
		var text = $( 'text-input' ).value.trim();
		if ( text ) {
			addText( text, textStyle );
			$( 'text-input' ).value = '';
		}
	} );
	$( 'lower-add' ).addEventListener( 'click', function () {
		var title = $( 'lower-title' ).value.trim();
		if ( title ) {
			var layer = stage.add( { type: 'lower', title: title, subtitle: $( 'lower-sub' ).value.trim(), x: portrait ? 0.5 : 0.3, y: portrait ? 0.68 : 0.84, w: portrait ? 0.9 : 0.5 } );
			select( layer.id );
		}
	} );
	$( 'ticker-add' ).addEventListener( 'click', function () {
		var input = $( 'ticker-text' );
		var text = input.value.trim() || input.placeholder;
		var layer = stage.add( { type: 'ticker', text: text, x: 0.5, y: 0.955 } );
		select( layer.id );
	} );
	$( 'count-add' ).addEventListener( 'click', function () {
		var minutes = Math.max( 1, Math.min( 180, +$( 'count-min' ).value || 5 ) );
		var layer = stage.add( {
			type: 'countdown',
			label: $( 'count-label' ).value.trim() || T.endsIn,
			endsAt: Date.now() + minutes * 60000,
			x: portrait ? 0.76 : 0.86,
			y: portrait ? 0.32 : 0.16,
			w: portrait ? 0.4 : 0.22
		} );
		select( layer.id );
	} );

	/* ─────────────────────────── looks ─────────────────────────── */

	function markTiles( id, key ) {
		document.querySelectorAll( '#whdls-' + id + ' .whdls-tile' ).forEach( function ( t ) {
			t.classList.toggle( 'is-on', t.getAttribute( 'data-key' ) === key );
		} );
	}

	/* Everything in the Looks and Themes tabs, from the stage: a scene or an occasion may have just changed it. */
	function syncLooks() {
		markTiles( 'looks', stage.look );
		markTiles( 'frames', stage.occasion ? '' : stage.frame );
		markTiles( 'lights', stage.light );
		markTiles( 'themes', themeNow() );
		syncAdjust();
		syncFrameOptions();
		$( 'light-level' ).value = stage.lightLevel;
		$( 'light-level' ).disabled = 'none' === stage.light;
		$( 'adjust-compare' ).hidden = S.onAir; // it would change what the audience sees
	}

	/*
	 * A tile that shows what it does to the host's own picture. Painted when its tab opens, from a still of the camera at
	 * that moment, and one at a time with room between them, more of it on air, so that browsing does not cost the video frames.
	 */
	var thumbJobs = [];
	var thumbTimer = null;

	function thumbTile( key, label, spec, run ) {
		var tile = el( 'button', 'whdls-tile whdls-tile--thumb' );
		tile.type = 'button';
		tile.setAttribute( 'data-key', key );
		var picture = el( 'canvas' );
		picture.width = portrait ? 96 : 170;
		picture.height = portrait ? 170 : 96;
		tile.appendChild( picture );
		tile.appendChild( el( 'span', 'whdls-tile__label', label ) );
		tile.addEventListener( 'click', run );
		tile.whdSpec = spec;
		return tile;
	}

	function pumpThumbs() {
		if ( thumbTimer || ! thumbJobs.length ) {
			return;
		}
		thumbTimer = setTimeout( function () {
			thumbTimer = null;
			var tile = thumbJobs.shift();
			if ( tile ) {
				stage.paintThumb( tile.querySelector( 'canvas' ), tile.whdSpec );
				pumpThumbs();
			}
		}, S.onAir ? 110 : 40 );
	}

	function paintOpenTiles() {
		stage.takeStill();
		thumbJobs = Array.prototype.slice.call( document.querySelectorAll( '.whdls-pane.is-on .whdls-sub.is-on .whdls-tile--thumb' ) );
		pumpThumbs();
	}

	document.querySelectorAll( '.whdls-subs' ).forEach( function ( seg ) {
		seg.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( 'button' );
			if ( ! btn ) {
				return;
			}
			seg.querySelectorAll( 'button' ).forEach( function ( b ) {
				b.classList.toggle( 'is-on', b === btn );
			} );
			seg.closest( '.whdls-pane' ).querySelectorAll( '.whdls-sub' ).forEach( function ( pane ) {
				pane.classList.toggle( 'is-on', pane.getAttribute( 'data-sub-pane' ) === btn.getAttribute( 'data-sub' ) );
			} );
			paintOpenTiles();
		} );
	} );

	/* ── Adjust ── */

	function paintAdjustRow( row, def ) {
		var value = stage.adjust[ def.key ] || 0;
		row.querySelector( 'input' ).value = value;
		row.querySelector( 'button' ).textContent = value > 0 && def.min < 0 ? '+' + value : String( value );
		row.classList.toggle( 'is-moved', 0 !== value );
	}

	function syncAdjust() {
		Stage.ADJUST.forEach( function ( def ) {
			paintAdjustRow( $( 'adj-' + def.key ), def );
		} );
	}

	function renderAdjust() {
		var box = $( 'adjust' );
		var last = '';
		Stage.ADJUST.forEach( function ( def ) {
			if ( def.group !== last ) {
				box.appendChild( el( 'h4', 'whdls-groupname', T.adjustGroups[ def.group ] ) );
				last = def.group;
			}
			var row = el( 'div', 'whdls-adj' );
			row.id = 'whdls-adj-' + def.key;
			var label = el( 'label', '', def.label );
			var input = el( 'input' );
			input.type = 'range';
			input.id = 'whdls-adj-input-' + def.key;
			input.min = def.min;
			input.max = def.max;
			input.step = 1;
			input.disabled = ! stage.canGrade && ! def.css;
			label.htmlFor = input.id;
			var zero = el( 'button', 'whdls-adj__val' );
			zero.type = 'button';
			zero.title = T.adjustZero;
			zero.disabled = input.disabled;
			var set = function ( value ) {
				stage.setAdjust( def.key, value );
				paintAdjustRow( row, def );
				saveSoon();
			};
			input.addEventListener( 'input', function () {
				set( +input.value );
			} );
			input.addEventListener( 'dblclick', function () {
				set( 0 );
			} );
			zero.addEventListener( 'click', function () {
				set( 0 );
			} );
			row.appendChild( label );
			row.appendChild( input );
			row.appendChild( zero );
			box.appendChild( row );
		} );
		$( 'adjust-note' ).hidden = stage.canGrade;
	}

	$( 'adjust-auto' ).addEventListener( 'click', function () {
		if ( stage.autoAdjust() ) {
			syncAdjust();
			saveSoon();
		}
	} );
	$( 'adjust-reset' ).addEventListener( 'click', function () {
		stage.resetAdjust();
		syncAdjust();
		saveSoon();
	} );
	( function ( btn ) {
		var hold = function ( on ) {
			stage.compare = on && ! S.onAir;
		};
		btn.addEventListener( 'pointerdown', function () {
			hold( true );
		} );
		[ 'pointerup', 'pointerleave', 'pointercancel', 'blur' ].forEach( function ( name ) {
			btn.addEventListener( name, function () {
				hold( false );
			} );
		} );
	}( $( 'adjust-compare' ) ) );

	/* ── Themes ── */

	/* Which theme is on, worked out from the stage, so changing its frame or its look by hand simply ends it. */
	function themeNow() {
		if ( stage.occasion ) {
			return '';
		}
		var keys = Object.keys( Stage.THEMES );
		for ( var i = 0; i < keys.length; i++ ) {
			var t = Stage.THEMES[ keys[ i ] ];
			if ( t.look === stage.look && t.frame === stage.frame && t.light === stage.light ) {
				return keys[ i ];
			}
		}
		return '';
	}

	function moodTracks( mood ) {
		return ( ( D.sounds || {} ).music || [] ).filter( function ( track ) {
			return 0 === String( track.mood ).indexOf( mood );
		} ).map( function ( track ) {
			return { url: track.url, title: track.title };
		} );
	}

	function afterDressing() {
		S.activeScene = '';
		renderScenes();
		syncLooks();
		renderOccasions();
		saveSoon();
	}

	function useTheme( key ) {
		var t = Stage.THEMES[ key ];
		stage.holdFrame( S.transition );
		stage.setLook( t.look );
		stage.setFrame( t.frame, t.tint );
		stage.setLight( t.light, t.level || Stage.LIGHT_LEVEL );
		occasionRain( t );
		if ( S.occMusic ) {
			if ( t.mood ) {
				keepOwnMusic();
				swapMusic( moodTracks( t.mood ) );
			} else {
				ownMusicBack();
			}
		}
		afterDressing();
	}

	function endTheme( key ) {
		var t = Stage.THEMES[ key ];
		stage.holdFrame( S.transition );
		stage.setLook( 'natural' );
		stage.setFrame( 'none' );
		stage.setLight( 'none' );
		if ( t.rain && S.rain === t.rain ) {
			occasionRain( null );
		}
		ownMusicBack();
		afterDressing();
	}

	function renderThemes() {
		var box = $( 'themes' );
		Object.keys( Stage.THEMES ).forEach( function ( key ) {
			var t = Stage.THEMES[ key ];
			box.appendChild( thumbTile( key, t.label, { look: t.look, light: t.light, frame: t.frame, tint: t.tint }, function () {
				if ( themeNow() === key ) {
					endTheme( key );
				} else {
					useTheme( key );
				}
			} ) );
		} );
	}

	/* ── Frames ── */

	function syncFrameOptions() {
		var def = Stage.FRAMES[ stage.frame ] || {};
		var showing = ! stage.occasion && ( def.tint || def.words );
		$( 'frame-opts' ).hidden = ! showing;
		$( 'frame-tints' ).textContent = '';
		$( 'frame-tints' ).hidden = ! def.tint;
		$( 'frame-words-row' ).hidden = ! def.words;
		if ( ! showing ) {
			return;
		}
		if ( def.tint ) {
			var current = stage.frameTint || def.tint;
			Object.keys( Stage.TINTS ).forEach( function ( key ) {
				var swatch = el( 'button', 'whdls-swatch' + ( current === key ? ' is-on' : '' ) );
				swatch.type = 'button';
				swatch.style.background = Stage.TINTS[ key ].main;
				swatch.title = Stage.TINTS[ key ].label;
				swatch.setAttribute( 'aria-label', Stage.TINTS[ key ].label );
				swatch.addEventListener( 'click', function () {
					stage.setFrameTint( key === def.tint ? '' : key );
					syncFrameOptions();
					repaintFrameTile();
					saveSoon();
				} );
				$( 'frame-tints' ).appendChild( swatch );
			} );
		}
		if ( def.words && document.activeElement !== $( 'frame-words' ) ) {
			$( 'frame-words' ).value = stage.frameOptions( stage.frame, '' ).words;
		}
	}

	function repaintFrameTile() {
		var tile = document.querySelector( '#whdls-frames .whdls-tile[data-key="' + stage.frame + '"]' );
		if ( tile ) {
			tile.whdSpec.tint = stage.frameTint;
			stage.paintThumb( tile.querySelector( 'canvas' ), tile.whdSpec );
		}
	}

	$( 'frame-words' ).addEventListener( 'input', function () {
		stage.setFrameWords( this.value );
		repaintFrameTile();
		saveSoon();
	} );

	function renderFrames() {
		var box = $( 'frames' );
		Object.keys( Stage.FRAME_GROUPS ).forEach( function ( group ) {
			box.appendChild( el( 'h4', 'whdls-groupname', Stage.FRAME_GROUPS[ group ] ) );
			var grid = el( 'div', 'whdls-tiles whdls-tiles--thumbs' );
			Object.keys( Stage.FRAMES ).forEach( function ( key ) {
				if ( Stage.FRAMES[ key ].group !== group ) {
					return;
				}
				grid.appendChild( thumbTile( key, Stage.FRAMES[ key ].label, { frame: key }, function () {
					stage.holdFrame( 220 );
					stage.setFrame( key );
					syncLooks();
					renderOccasions(); // a plain frame takes the occasion's place
					saveSoon();
				} ) );
			} );
			box.appendChild( grid );
		} );
	}

	/* ── Light ── */

	function renderLights() {
		var box = $( 'lights' );
		Object.keys( Stage.LIGHTS ).forEach( function ( key ) {
			box.appendChild( thumbTile( key, Stage.LIGHTS[ key ].label, { light: key }, function () {
				stage.setLight( key );
				syncLooks();
				saveSoon();
			} ) );
		} );
	}

	$( 'light-level' ).addEventListener( 'input', function () {
		stage.lightLevel = +this.value;
		saveSoon();
	} );

	/* ── Colour ── */

	function renderLooks() {
		var looks = $( 'looks' );
		Object.keys( Stage.LOOKS ).forEach( function ( key ) {
			looks.appendChild( thumbTile( key, Stage.LOOKS[ key ].label, { look: key }, function () {
				stage.setLook( key );
				syncLooks();
				saveSoon();
			} ) );
		} );
		renderAdjust();
		renderThemes();
		renderFrames();
		renderLights();
		syncLooks();
		if ( ! stage.canFilter ) {
			document.querySelector( '[data-sub="colour"]' ).hidden = true;
		}
	}
	$( 'hide' ).addEventListener( 'change', function () {
		stage.hideGraphics = this.checked;
	} );

	/* ─────────────────────────── sound ─────────────────────────── */

	function renderMusic() {
		var box = $( 'music-list' );
		box.textContent = '';
		if ( ! S.music.length ) {
			box.appendChild( el( 'li', 'whdls-empty', T.noTracks ) );
		}
		if ( mixer ) {
			mixer.setPlaylist( S.music );
		}
		S.music.forEach( function ( track, i ) {
			var playing = mixer && mixer.index === i && ! mixer.audio.paused;
			var row = el( 'li', 'whdls-track' + ( playing ? ' is-on' : '' ) );
			var name = el( 'button', 'whdls-track__name', track.title );
			name.type = 'button';
			name.addEventListener( 'click', function () {
				if ( mixer ) {
					mixer.playIndex( i );
				}
			} );
			row.appendChild( name );
			var x = el( 'button', 'whdls-mini whdls-mini--danger', '×' );
			x.type = 'button';
			x.setAttribute( 'aria-label', T.remove );
			x.addEventListener( 'click', function () {
				if ( mixer && mixer.index === i ) {
					mixer.stop();
				}
				S.music.splice( i, 1 );
				renderMusic();
				renderLibrary();
				renderOccasionTracks();
				saveSoon();
			} );
			row.appendChild( x );
			box.appendChild( row );
		} );
		var now = mixer && S.music[ mixer.index ] && ! mixer.audio.paused ? S.music[ mixer.index ].title : '';
		$( 'music-now' ).textContent = now || ( S.music.length ? T.pressPlay : '' );
		$( 'music-play' ).classList.toggle( 'is-on', !! now );
	}

	/*
	 * Before going live nobody else can hear it, so the first track added starts straight away: that is how you find
	 * out it works. On air it waits for Play, because music starting by itself would surprise the people watching.
	 */
	function firstTrackStarts() {
		if ( mixer && ! S.onAir && ! mixer.audio.src ) {
			mixer.playIndex( S.music.length - 1 );
		}
	}

	/* ─ the ready-made music ─ */
	var mood = '';
	var previewing = '';
	var previewPlayer = new Audio();
	previewPlayer.preload = 'none';
	previewPlayer.addEventListener( 'ended', function () {
		previewing = '';
		renderLibrary();
		renderOccasionTracks();
	} );

	function trackLength( seconds ) {
		return Math.floor( seconds / 60 ) + ':' + pad2( seconds % 60 );
	}

	/* Straight to the speakers, past the mixer, so a track can be judged without the audience hearing. */
	function togglePreview( url ) {
		if ( previewing === url ) {
			previewPlayer.pause();
			previewing = '';
		} else {
			previewPlayer.src = url;
			var played = previewPlayer.play();
			if ( played && played.catch ) {
				played.catch( function () {} );
			}
			previewing = url;
		}
		renderLibrary();
		renderOccasionTracks();
	}

	function renderLibrary() {
		var tracks = ( D.sounds || {} ).music || [];
		var filters = $( 'moods' );
		var list = $( 'library' );
		filters.textContent = '';
		list.textContent = '';
		var moods = tracks.reduce( function ( all, t ) {
			return all.indexOf( t.mood ) < 0 ? all.concat( t.mood ) : all;
		}, [] );
		[ '' ].concat( moods ).forEach( function ( name ) {
			var btn = el( 'button', 'whdls-mini' + ( mood === name ? ' is-on' : '' ), name || T.allMoods );
			btn.type = 'button';
			btn.addEventListener( 'click', function () {
				mood = name;
				renderLibrary();
			} );
			filters.appendChild( btn );
		} );
		tracks.forEach( function ( track ) {
			if ( mood && track.mood !== mood ) {
				return;
			}
			list.appendChild( libraryRow( track ) );
		} );
	}

	function libraryRow( track ) {
			var row = el( 'li', 'whdls-libtrack' );
			var hear = el( 'button', 'whdls-libtrack__hear' + ( previewing === track.url ? ' is-on' : '' ) );
			hear.type = 'button';
			hear.title = previewing === track.url ? T.hearStop : T.hear;
			hear.setAttribute( 'aria-label', hear.title + ': ' + track.title );
			hear.setAttribute( 'aria-pressed', previewing === track.url ? 'true' : 'false' );
			hear.addEventListener( 'click', function () {
				togglePreview( track.url );
			} );
			row.appendChild( hear );
			var words = el( 'span', 'whdls-libtrack__words' );
			words.appendChild( el( 'strong', '', track.title ) );
			words.appendChild( el( 'small', '', track.mood + ' · ' + trackLength( track.seconds ) ) );
			row.appendChild( words );
			var added = S.music.some( function ( m ) {
				return m.url === track.url;
			} );
			var add = el( 'button', 'whdls-mini' + ( added ? ' is-on' : '' ), added ? T.trackAdded : T.addTrack );
			add.type = 'button';
			add.disabled = added;
			add.addEventListener( 'click', function () {
				S.music.push( { url: track.url, title: track.title } );
				renderMusic();
				renderLibrary();
				renderOccasionTracks();
				saveSoon();
				firstTrackStarts();
			} );
			row.appendChild( add );
			return row;
	}

	function renderPads() {
		var box = $( 'pads' );
		box.textContent = '';
		var make = function ( key, icon, label, onRemove ) {
			var tile = el( 'button', 'whdls-tile' );
			tile.type = 'button';
			tile.appendChild( el( 'span', 'whdls-tile__icon', icon ) );
			tile.appendChild( el( 'span', 'whdls-tile__label', label ) );
			tile.addEventListener( 'click', function () {
				if ( mixer ) {
					mixer.playPad( key );
				}
				tile.classList.add( 'is-hit' );
				setTimeout( function () {
					tile.classList.remove( 'is-hit' );
				}, 180 );
			} );
			if ( onRemove ) {
				var x = el( 'i', 'whdls-thumb__x', '×' );
				x.addEventListener( 'click', function ( e ) {
					e.stopPropagation();
					onRemove();
				} );
				tile.appendChild( x );
			}
			box.appendChild( tile );
		};
		( ( D.sounds || {} ).fx || [] ).forEach( function ( fx ) {
			make( fx.url, fx.icon, fx.label );
		} );
		Object.keys( Sound.PADS ).forEach( function ( key ) {
			make( key, Sound.PADS[ key ].icon, Sound.PADS[ key ].label );
		} );
		S.ownPads.forEach( function ( pad, i ) {
			make( pad.url, '🎵', pad.title, function () {
				S.ownPads.splice( i, 1 );
				renderPads();
				saveSoon();
			} );
		} );
	}

	function wireSound() {
		if ( ! mixer ) {
			return;
		}
		if ( saved.volumes ) {
			[ 'mic', 'music', 'pads' ].forEach( function ( bus ) {
				if ( undefined !== saved.volumes[ bus ] ) {
					mixer.volumes[ bus ] = saved.volumes[ bus ];
					$( 'vol-' + bus ).value = saved.volumes[ bus ];
				}
			} );
		}
		if ( undefined !== saved.duck ) {
			mixer.duck = !! saved.duck;
			$( 'music-duck' ).checked = mixer.duck;
		}
		if ( undefined !== saved.loop ) {
			mixer.loop = !! saved.loop;
			$( 'music-loop' ).checked = mixer.loop;
		}
		if ( undefined !== saved.hear ) {
			mixer.setMonitor( !! saved.hear );
			$( 'monitor' ).checked = !! saved.hear;
		}
		mixer.setMuted( S.muted );
		mixer.onMusic = renderMusic;
		mixer.onError = function () {
			banner( 'sound', T.soundFailed, 'error' );
		};

		[ 'mic', 'music', 'pads' ].forEach( function ( bus ) {
			$( 'vol-' + bus ).addEventListener( 'input', function () {
				mixer.setVolume( bus, +this.value );
				saveSoon();
			} );
		} );
		$( 'music-play' ).addEventListener( 'click', function () {
			mixer.toggle();
		} );
		$( 'music-next' ).addEventListener( 'click', function () {
			mixer.next();
		} );
		$( 'music-duck' ).addEventListener( 'change', function () {
			mixer.duck = this.checked;
			saveSoon();
		} );
		$( 'music-loop' ).addEventListener( 'change', function () {
			mixer.loop = this.checked;
			saveSoon();
		} );
		$( 'monitor' ).addEventListener( 'change', function () {
			mixer.wake();
			mixer.setMonitor( this.checked );
			saveSoon();
		} );
		$( 'music-add' ).addEventListener( 'click', function () {
			chooseMedia( { title: T.pickAudio, type: 'audio', multiple: true, button: T.useAudio }, function ( files ) {
				files.forEach( function ( file ) {
					S.music.push( { url: file.url, title: file.title || file.filename } );
				} );
				renderMusic();
				saveSoon();
				firstTrackStarts();
			} );
		} );
		$( 'pad-add' ).addEventListener( 'click', function () {
			chooseMedia( { title: T.pickPad, type: 'audio', button: T.useAudio }, function ( files ) {
				if ( files[ 0 ] ) {
					S.ownPads.push( { url: files[ 0 ].url, title: ( files[ 0 ].title || files[ 0 ].filename ).slice( 0, 18 ) } );
					renderPads();
					saveSoon();
				}
			} );
		} );

		// Audio only starts after a click somewhere; any click will do.
		document.addEventListener( 'pointerdown', function () {
			mixer.wake();
		} );
	}

	/* ─────────────────────────── recording ─────────────────────────── */

	/*
	 * Recording goes straight to the media library, slice by slice as it is made, so stopping has only
	 * the last few seconds left to send. A video in the library is the goal; the slices are also kept
	 * here until it is safely there, because if the server cannot take it (a full hosting quota, say)
	 * the whole recording can still be saved to this computer.
	 *
	 * While on air with a struggling connection the sending waits. The audience comes first, and the
	 * slices are not going anywhere.
	 */
	var REC_SLICE_MS = 4000;
	var REC_GIVE_UP = 8;
	var REC_MARK_MIN = 3; // seconds a piece has to stay up: a tap that was changed straight away is not a moment
	var REC_MARK_JOIN = 10; // the same piece back within this is the same moment
	var rec = null;

	function pickRecorderType() {
		var types = [ 'video/mp4;codecs=avc1,mp4a.40.2', 'video/webm;codecs=vp9,opus', 'video/webm;codecs=vp8,opus', 'video/webm' ];
		for ( var i = 0; i < types.length; i++ ) {
			if ( window.MediaRecorder && MediaRecorder.isTypeSupported( types[ i ] ) ) {
				return types[ i ];
			}
		}
		return '';
	}

	function recSeconds( r ) {
		return Math.round( ( r.ran + ( r.mark ? Date.now() - r.mark : 0 ) ) / 1000 );
	}

	function recClock( seconds ) {
		var h = Math.floor( seconds / 3600 );
		return ( h ? h + ':' : '' ) + pad2( Math.floor( seconds / 60 ) % 60 ) + ':' + pad2( seconds % 60 );
	}

	function renderRecord() {
		var btn = $( 'record' );
		var label = $( 'record-label' );
		var pause = $( 'record-pause' );
		var recording = rec && ! rec.stopped;
		btn.classList.toggle( 'is-on', !! recording );
		btn.classList.toggle( 'is-paused', !! ( recording && rec.paused ) );
		btn.setAttribute( 'aria-pressed', recording ? 'true' : 'false' );
		btn.disabled = !! ( rec && rec.stopped );
		pause.hidden = ! recording;
		if ( recording ) {
			label.textContent = T.recordStop + ' ' + recClock( recSeconds( rec ) );
			$( 'record-pause-label' ).textContent = rec.paused ? T.recordResume : T.recordPause;
		} else if ( rec ) {
			var total = rec.chunks.length || 1;
			label.textContent = fmt( T.recordSaving, Math.min( 99, Math.round( rec.next / total * 100 ) ) );
		} else {
			label.textContent = T.record;
		}
	}

	function announceSaved( saved ) {
		// With Shoppable videos there (the theme), that is where it is ready; the library is only where the file lives.
		var text = saved.shop ? saved.shop.text : fmt( T.recordSaved, saved.title );
		var label = saved.shop ? saved.shop.label : T.recordOpen;
		var href = saved.shop ? saved.shop.url : saved.edit;
		banner( 'rec-' + saved.id, text, 'info', { label: label, href: href } );
		var line = $( 'wrap-rec' );
		if ( $( 'wrap' ).open ) {
			line.hidden = false;
			line.textContent = text + ' ';
			var link = el( 'a', '', label );
			link.href = href;
			line.appendChild( link );
		}
	}

	/* ─ which piece was in the video when ─ */

	function pieceInVideo() {
		if ( stage.cover || stage.hideGraphics ) {
			return 0;
		}
		var layer = productLayer() || galleryLayer();
		return layer ? layer.productId : 0;
	}

	function recStill( r ) {
		try {
			r.poster = canvas.toDataURL( 'image/jpeg', 0.85 );
			r.posterSent = false;
		} catch ( e ) {}
	}

	function sendMarks( r ) {
		clearTimeout( r.marksTimer );
		r.marksTimer = null;
		if ( ! r.id || r.finishing ) {
			return;
		}
		var body = { marks: r.marks };
		// A still is a couple of hundred kilobytes: on a struggling connection it waits, as the slices do.
		var poster = r.poster && ! r.posterSent && ! ( S.onAir && ( 'bad' === S.net || 'warning' === S.net ) ) ? r.poster : '';
		if ( poster ) {
			body.poster = poster;
		}
		api( 'POST', D.id + '/recording/' + r.id + '/marks', body ).then( function () {
			if ( poster && poster === r.poster ) {
				r.posterSent = true;
			}
		} ).catch( function () {} );
	}

	/*
	 * Called about once a second while recording, and at each pause, resume and stop. It goes by what
	 * is in the picture rather than by each way a piece can come and go, and by the recording's own
	 * clock, which leaves paused time out, so a moment lines up with the video that comes of it. Nothing
	 * shown during a pause is in the video, so nothing shown during a pause counts.
	 */
	function recWatch( r, ending ) {
		var t = recSeconds( r );
		var now = ending || r.paused ? 0 : pieceInVideo();
		var changed = false;
		if ( r.open && r.open.product !== now ) {
			r.open.for = t - r.open.at;
			if ( r.open.for < REC_MARK_MIN ) {
				r.marks.splice( r.marks.indexOf( r.open ), 1 );
			}
			r.open = null;
			changed = true;
		}
		if ( now && ! r.open ) {
			var last = r.marks[ r.marks.length - 1 ];
			if ( last && last.product === now && t - last.at - last.for <= REC_MARK_JOIN ) {
				r.open = last;
			} else {
				r.open = { at: t, product: now, for: 0 };
				r.marks.push( r.open );
			}
			changed = true;
		}
		// The cover: the first look at a piece on screen, once its entrance is over, or failing that a look at the room.
		if ( ! r.paused && ! ending && ! stage.cover && ! r.stillOfPiece ) {
			if ( r.open && t - r.open.at >= REC_MARK_MIN ) {
				r.stillOfPiece = true;
				recStill( r );
				changed = true;
			} else if ( ! r.poster && t >= 15 ) {
				recStill( r );
				changed = true;
			}
		}
		if ( changed && ! r.marksTimer && ! ending ) {
			r.marksTimer = setTimeout( function () {
				sendMarks( r );
			}, 1500 );
		}
	}

	function recDone( r ) {
		r.chunks = [];
		if ( rec === r ) {
			rec = null;
		}
		renderRecord();
	}

	/* The whole recording, from what this browser kept: only when the library could not have it. */
	function saveToComputer( r ) {
		var url = URL.createObjectURL( new Blob( r.chunks, { type: r.mime } ) );
		var a = el( 'a' );
		a.href = url;
		a.download = 'live-' + D.id + '-' + new Date().toISOString().slice( 0, 16 ).replace( /[:T]/g, '-' ) + '.' + r.ext;
		document.body.appendChild( a );
		a.click();
		a.remove();
		setTimeout( function () {
			URL.revokeObjectURL( url );
		}, 120000 );
		clearBanner( 'rec-retry' );
		banner( 'rec-local', T.recordLocal, 'warn' );
		if ( $( 'wrap' ).open ) {
			$( 'wrap-rec' ).hidden = false;
			$( 'wrap-rec' ).textContent = T.recordLocal;
		}
		recDone( r );
	}

	function finishRecording( r ) {
		if ( r.finishing ) {
			return;
		}
		r.finishing = true;
		clearTimeout( r.marksTimer );
		api( 'POST', D.id + '/recording/' + r.id + '/finish', { seconds: recSeconds( r ), marks: r.marks, poster: r.posterSent ? '' : r.poster } ).then( function ( saved ) {
			clearBanner( 'rec-retry' );
			announceSaved( saved );
			recDone( r );
		} ).catch( function () {
			saveToComputer( r );
		} );
	}

	function sendSlice( r, seq ) {
		var abort = new AbortController();
		var timer = setTimeout( function () {
			abort.abort();
		}, 90000 );
		var query = '?seq=' + seq + '&t=' + recSeconds( r );
		return window.fetch( D.rest.root + D.id + '/recording/' + r.id + '/slice' + query, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': D.rest.nonce, 'Content-Type': 'application/octet-stream' },
			body: r.chunks[ seq ],
			signal: abort.signal
		} ).then( function ( res ) {
			clearTimeout( timer );
			return res.json().catch( function () {
				return {};
			} ).then( function ( data ) {
				if ( ! res.ok ) {
					var error = new Error( data.message || ( 'HTTP ' + res.status ) );
					error.status = res.status;
					error.next = data.data && undefined !== data.data.next ? data.data.next : null;
					throw error;
				}
				return data;
			} );
		} );
	}

	/* One slice at a time, in order, never holding anything else up. */
	function pump( r ) {
		if ( r.busy || r.waiting || r.finishing ) {
			return;
		}
		if ( ! r.id ) {
			if ( r.stopped ) {
				saveToComputer( r );
			}
			return;
		}
		if ( r.next >= r.chunks.length ) {
			if ( r.stopped ) {
				finishRecording( r );
			}
			return;
		}
		var later = function ( ms ) {
			r.waiting = setTimeout( function () {
				r.waiting = null;
				pump( r );
			}, ms );
		};
		if ( S.onAir && ( 'bad' === S.net || 'warning' === S.net ) ) {
			later( 3000 );
			return;
		}
		r.busy = true;
		sendSlice( r, r.next ).then( function ( res ) {
			r.busy = false;
			r.failed = 0;
			clearBanner( 'rec-retry' );
			r.next = res.next;
			if ( r.stopped ) {
				renderRecord();
			}
			pump( r );
		} ).catch( function ( err ) {
			r.busy = false;
			if ( 409 === err.status && null !== err.next ) {
				r.next = err.next; // told exactly where the server is up to
				pump( r );
				return;
			}
			r.failed = 507 === err.status ? REC_GIVE_UP : r.failed + 1;
			if ( r.failed >= REC_GIVE_UP && r.stopped ) {
				saveToComputer( r );
				return;
			}
			banner( 'rec-retry', 507 === err.status ? err.message : T.recordRetry, 'warn' );
			later( Math.min( 30000, 2000 * r.failed ) );
		} );
	}

	function beginRecording( type, id ) {
		var tracks = [ stage.captureTrack().clone() ];
		if ( mixer ) {
			tracks.push( mixer.track.clone() );
		}
		var recorder = new MediaRecorder( new MediaStream( tracks ), { mimeType: type, videoBitsPerSecond: 2500000, audioBitsPerSecond: 128000 } );
		var r = rec = {
			recorder: recorder,
			id: id,
			mime: type.split( ';' )[ 0 ],
			ext: 0 === type.indexOf( 'video/mp4' ) ? 'mp4' : 'webm',
			chunks: [],
			next: 0,
			failed: 0,
			busy: false,
			waiting: null,
			stopped: false,
			paused: false,
			finishing: false,
			ran: 0,
			mark: Date.now(),
			marks: [],
			open: null,
			marksTimer: null,
			poster: '',
			posterSent: false,
			stillOfPiece: false
		};
		recorder.ondataavailable = function ( e ) {
			if ( e.data && e.data.size ) {
				r.chunks.push( e.data );
				pump( r );
			}
		};
		recorder.onstop = function () {
			tracks.forEach( function ( t ) {
				t.stop();
			} );
			r.stopped = true;
			renderRecord();
			// A wait for a weak connection is over: nothing is left to protect but the last slices.
			pump( r );
		};
		recorder.start( REC_SLICE_MS );
		recWatch( r ); // a piece already up is in the video from its first frame
		renderRecord();
	}

	function stopRecording() {
		if ( ! rec || rec.stopped || 'inactive' === rec.recorder.state ) {
			return;
		}
		recWatch( rec, true );
		if ( ! rec.poster ) {
			recStill( rec ); // a short one, or one under the cover all along: still something to show for it
		}
		if ( rec.mark ) {
			rec.ran += Date.now() - rec.mark;
			rec.mark = 0;
		}
		rec.recorder.stop();
	}

	$( 'record' ).addEventListener( 'click', function () {
		if ( rec ) {
			stopRecording();
			return;
		}
		var type = pickRecorderType();
		if ( ! type ) {
			banner( 'record', T.recordFailed, 'error' );
			return;
		}
		var btn = this;
		btn.disabled = true;
		api( 'POST', D.id + '/recording', { type: 0 === type.indexOf( 'video/mp4' ) ? 'mp4' : 'webm', width: stage.W, height: stage.H } ).then( function ( res ) {
			( res.recovered || [] ).forEach( announceSaved );
			beginRecording( type, res.rec );
		} ).catch( function ( err ) {
			// Recording matters more than where it ends up: it can still be saved to this computer.
			banner( 'rec-retry', err.message, 'warn' );
			beginRecording( type, null );
		} ).then( function () {
			btn.disabled = false;
		} );
	} );

	$( 'record-pause' ).addEventListener( 'click', function () {
		if ( ! rec || rec.stopped ) {
			return;
		}
		if ( rec.paused ) {
			rec.recorder.resume();
			rec.paused = false;
			rec.mark = Date.now();
		} else {
			rec.recorder.pause();
			rec.paused = true;
			rec.ran += Date.now() - rec.mark;
			rec.mark = 0;
		}
		recWatch( rec );
		renderRecord();
	} );

	/* ─────────────────────────── destinations ─────────────────────────── */

	var ENDPOINTS = { whd_facebook: 'facebook', whd_youtube: 'youtube', whd_tiktok: 'tiktok' };

	function renderDests() {
		var box = $( 'dests' );
		box.textContent = '';
		Object.keys( D.destinations || {} ).forEach( function ( key ) {
			var dest = D.destinations[ key ];
			var info = S.dests[ key ] || {};
			var state = S.destState[ key ] || ( S.onAir && 'local' === info.state ? 'connected' : info.state || 'waiting' );
			var words = { waiting: T.waiting, ready: T.waiting, local: T.waiting, connecting: T.connecting, connected: T.connected, interrupted: T.interrupted, failed: T.failed, stopped: T.stopped }[ state ] || state;
			var tag = info.live_url ? 'a' : 'span';
			var chip = el( tag, 'whdls-dest whdls-dest--' + state );
			if ( info.live_url ) {
				chip.href = info.live_url;
				chip.target = '_blank';
				chip.rel = 'noopener';
			}
			chip.title = info.message || words;
			chip.appendChild( el( 'i' ) );
			chip.appendChild( el( 'span', '', dest.label ) );
			box.appendChild( chip );
		} );
	}

	function setAllRtmp( state ) {
		Object.keys( S.dests ).forEach( function ( key ) {
			if ( S.dests[ key ].endpoint && 'failed' !== S.destState[ key ] && 'failed' !== S.dests[ key ].state ) {
				S.destState[ key ] = state;
			}
		} );
		renderDests();
	}

	/* ─────────────────────────── on air ─────────────────────────── */

	var dailyLoading = null;
	function loadDaily() {
		if ( window.Daily || window.DailyIframe ) {
			return Promise.resolve();
		}
		if ( ! dailyLoading ) {
			dailyLoading = new Promise( function ( resolve, reject ) {
				var s = el( 'script' );
				s.src = D.dailyJs;
				s.crossOrigin = 'anonymous';
				s.onload = function () {
					if ( window.Daily || window.DailyIframe ) {
						resolve();
					} else {
						reject( new Error( 'no global' ) );
					}
				};
				s.onerror = function () {
					dailyLoading = null;
					reject( new Error( 'blocked' ) );
				};
				document.head.appendChild( s );
			} );
		}
		return dailyLoading;
	}

	function setState( stageName ) {
		S.stage = stageName;
		root.setAttribute( 'data-stage', stageName );
		var state = $( 'state' );
		state.textContent = S.onAir ? T.onAir : ( 'ended' === stageName ? T.ended : ( 'live' === stageName ? T.offAir : T.preview ) );
		state.className = 'whdls-state' + ( S.onAir ? ' is-on' : '' );
		$( 'onair' ).hidden = ! S.onAir;
		var btn = $( 'golive' );
		btn.classList.toggle( 'whdls-golive--stop', S.onAir );
		btn.disabled = 'ended' === stageName;
		if ( 'ended' === stageName ) {
			btn.textContent = T.ended;
		} else if ( S.onAir ) {
			btn.textContent = T.endLive;
		} else {
			btn.textContent = 'live' === stageName ? T.rejoin : T.goLive;
		}
	}

	function bindCall( call ) {
		call.on( 'participant-counts-updated', function ( e ) {
			var counts = e.participantCounts || {};
			S.viewers = Math.max( 0, ( counts.hidden || 0 ) + ( counts.present || 1 ) - 1 );
			$( 'viewers' ).textContent = String( S.viewers );
		} );
		call.on( 'live-streaming-started', function () {
			setAllRtmp( 'connecting' );
		} );
		call.on( 'live-streaming-updated', function ( e ) {
			var key = e.endpoint ? ENDPOINTS[ e.endpoint.endpoint ] : '';
			if ( key ) {
				S.destState[ key ] = e.state;
				renderDests();
			} else {
				setAllRtmp( e.state );
			}
		} );
		call.on( 'live-streaming-stopped', function () {
			if ( ! S.ending ) {
				setAllRtmp( 'stopped' );
			}
		} );
		call.on( 'live-streaming-error', function ( e ) {
			var names = e.details && e.details.endpoints ? e.details.endpoints : [];
			if ( names.length ) {
				names.forEach( function ( name ) {
					var key = ENDPOINTS[ name ];
					if ( key ) {
						S.destState[ key ] = 'failed';
						banner( 'rtmp-' + key, fmt( T.destFailed, D.destinations[ key ] ? D.destinations[ key ].label : key, e.errorMsg ), 'error' );
					}
				} );
				renderDests();
			} else {
				setAllRtmp( 'failed' );
				banner( 'rtmp', fmt( T.rtmpFailed, e.errorMsg || '' ), 'error', { label: T.tryAgain, run: startRtmp } );
			}
		} );
		call.on( 'network-connection', function ( e ) {
			if ( 'interrupted' === e.event ) {
				banner( 'net', T.lostHost, 'warn' );
			} else if ( 'connected' === e.event ) {
				clearBanner( 'net' );
			}
		} );
		call.on( 'network-quality-change', function ( e ) {
			S.net = e.networkState || '';
			if ( 'bad' === S.net ) {
				banner( 'quality', T.netBanner, 'warn' );
			} else {
				clearBanner( 'quality' );
			}
		} );
		call.on( 'error', function ( e ) {
			S.onAir = false;
			setState( S.stage );
			banner( 'call', fmt( T.joinFailed, ( e.error && e.error.msg ) || e.errorMsg || '' ), 'error', { label: T.rejoin, run: goLive } );
		} );
		call.on( 'left-meeting', function () {
			if ( ! S.ending && S.onAir ) {
				S.onAir = false;
				setState( S.stage );
				banner( 'call', T.lostHost, 'warn', { label: T.rejoin, run: goLive } );
			}
		} );
	}

	function startRtmp() {
		clearBanner( 'rtmp' );
		return api( 'POST', D.id + '/on-air', {} ).then( function ( res ) {
			if ( res.rtmpError ) {
				setAllRtmp( 'failed' );
				banner( 'rtmp', fmt( T.rtmpFailed, res.rtmpError ), 'error', { label: T.tryAgain, run: startRtmp } );
			}
			return res;
		} );
	}

	function goLive() {
		if ( ! D.dailyReady ) {
			banner( 'daily', T.noDaily, 'error', { label: T.settings, href: D.settingsUrl } );
			return Promise.resolve();
		}
		if ( ! canvas.captureStream ) {
			banner( 'browser', T.unsupported, 'error' );
			return Promise.resolve();
		}
		if ( mixer ) {
			mixer.wake();
		}
		clearBanner( 'call' );
		var btn = $( 'golive' );
		btn.disabled = true;
		btn.textContent = T.going;

		var tiktok = $( 'tiktok-key' ).value.trim();

		return loadDaily().catch( function () {
			throw new Error( T.dailyLoad );
		} ).then( function () {
			return api( 'POST', D.id + '/start', tiktok ? { tiktok_key: tiktok } : {} );
		} ).then( function ( start ) {
			S.dests = start.dests || {};
			renderDests();
			Object.keys( S.dests ).forEach( function ( key ) {
				if ( 'failed' === S.dests[ key ].state ) {
					banner( 'rtmp-' + key, fmt( T.destFailed, D.destinations[ key ] ? D.destinations[ key ].label : key, S.dests[ key ].message ), 'error' );
				}
			} );

			var Factory = window.Daily || window.DailyIframe;
			if ( S.call ) {
				try {
					S.call.destroy();
				} catch ( e ) {}
			}
			var call = S.call = Factory.createCallObject( {
				videoSource: stage.captureTrack(),
				audioSource: mixer ? mixer.track : false,
				subscribeToTracksAutomatically: false,
				// Music goes down this track as well as a voice: speech mode would chew it up.
				dailyConfig: { micAudioMode: 'music' }
			} );
			bindCall( call );
			return call.join( { url: start.url, token: start.token } );
		} ).then( function () {
			// The high layer is what the RTMP copy is made from, so it gets the bandwidth.
			return S.call.updateSendSettings( {
				video: {
					encodings: {
						low: { maxBitrate: 200000, scaleResolutionDownBy: 4, maxFramerate: 15 },
						medium: { maxBitrate: 700000, scaleResolutionDownBy: 2, maxFramerate: 24 },
						high: { maxBitrate: 3000000, scaleResolutionDownBy: 1, maxFramerate: 30 }
					},
					maxQuality: 'high'
				}
			} ).catch( function () {} );
		} ).then( startRtmp ).then( function ( res ) {
			S.onAir = true;
			S.startedAt = res.startedAt || S.startedAt || Math.floor( Date.now() / 1000 );
			setState( 'live' );
			renderDests();
			holdScreenAwake();
			postShowing();
			api( 'POST', D.id + '/cover', { covered: stage.cover } ).catch( function () {} );
			btn.disabled = false;
		} ).catch( function ( err ) {
			btn.disabled = false;
			setState( S.stage );
			banner( 'call', fmt( T.joinFailed, err.message || err.errorMsg || '' ), 'error', { label: T.tryAgain, run: goLive } );
		} );
	}

	function endLive() {
		S.ending = true;
		var btn = $( 'end-confirm' );
		btn.disabled = true;
		api( 'POST', D.id + '/end', {} ).then( function ( res ) {
			S.onAir = false;
			setState( 'ended' );
			if ( S.call ) {
				S.call.leave().catch( function () {} ).then( function () {
					try {
						S.call.destroy();
					} catch ( e ) {}
				} );
			}
			if ( mixer ) {
				mixer.stop();
			}
			if ( rec ) {
				$( 'wrap-rec' ).hidden = false;
				$( 'wrap-rec' ).textContent = T.wrapSaving;
				stopRecording();
			}
			releaseScreen();
			$( 'end' ).close();
			showWrap( res );
		} ).catch( function ( err ) {
			S.ending = false;
			btn.disabled = false;
			$( 'end' ).close();
			banner( 'end', err.message, 'error' );
		} );
	}

	function showWrap( res ) {
		$( 'wrap-title' ).textContent = D.title;
		var box = $( 'wrapstats' );
		box.textContent = '';
		var seconds = res.seconds || 0;
		var h = Math.floor( seconds / 3600 );
		var m = Math.round( ( seconds % 3600 ) / 60 );
		var time = h ? fmt( T.hours, h, m ) : fmt( T.minutes, m );
		var stats = res.stats || {};
		[ [ time, T.wrapTime ], [ res.peak || 0, T.wrapPeak ], [ stats.comments || 0, T.wrapComments ], [ stats.links || 0, T.wrapLinks ], [ stats.clicks || 0, T.wrapClicks ] ].forEach( function ( row ) {
			var cell = el( 'div' );
			cell.appendChild( el( 'dd', '', String( row[ 0 ] ) ) );
			cell.appendChild( el( 'dt', '', row[ 1 ] ) );
			box.appendChild( cell );
		} );
		$( 'wrap' ).showModal();
	}

	var wakeLock = null;
	function holdScreenAwake() {
		if ( navigator.wakeLock && S.onAir ) {
			navigator.wakeLock.request( 'screen' ).then( function ( lock ) {
				wakeLock = lock;
			} ).catch( function () {} );
		}
	}
	function releaseScreen() {
		if ( wakeLock ) {
			wakeLock.release().catch( function () {} );
			wakeLock = null;
		}
	}
	document.addEventListener( 'visibilitychange', function () {
		if ( 'visible' === document.visibilityState ) {
			holdScreenAwake();
		}
	} );
	window.addEventListener( 'beforeunload', function ( e ) {
		if ( S.onAir || rec ) {
			e.preventDefault();
			e.returnValue = rec ? T.recordLeave : T.confirmLeave;
			return e.returnValue;
		}
	} );

	/* The top-right button: go live, come back, or end, depending. */
	$( 'golive' ).addEventListener( 'click', function () {
		if ( S.onAir ) {
			$( 'end' ).showModal();
		} else if ( 'live' === S.stage ) {
			goLive();
		} else {
			openPreflight();
		}
	} );

	function openPreflight() {
		var list = $( 'checklist' );
		list.textContent = '';
		Object.keys( D.destinations || {} ).forEach( function ( key ) {
			var dest = D.destinations[ key ];
			var row = el( 'li', dest.ready ? 'is-ok' : 'is-bad' );
			row.appendChild( el( 'strong', '', dest.label ) );
			if ( dest.note ) {
				row.appendChild( el( 'span', '', dest.note ) );
			} else if ( 'site' === key && D.pagePretty ) {
				row.appendChild( el( 'span', 'whdls-checklist__link', D.pagePretty ) );
			}
			list.appendChild( row );
		} );
		$( 'tiktok-box' ).hidden = ! D.needsTikTokKey;
		$( 'start-covered' ).checked = stage.cover;
		$( 'headphones' ).hidden = ! ( mixer && mixer.hearing );
		$( 'preflight' ).showModal();
	}

	$( 'confirm' ).addEventListener( 'click', function () {
		if ( ! $( 'tiktok-box' ).hidden && ! $( 'tiktok-key' ).value.trim() ) {
			$( 'tiktok-key' ).focus();
			return;
		}
		if ( $( 'start-covered' ).checked !== stage.cover ) {
			setCover( $( 'start-covered' ).checked );
		}
		$( 'preflight' ).close();
		goLive();
	} );
	$( 'end-confirm' ).addEventListener( 'click', endLive );

	document.querySelectorAll( '.whdls-dialog [data-close]' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			btn.closest( 'dialog' ).close();
		} );
	} );

	/* ─────────────────────────── comments ─────────────────────────── */

	var STATUS_WORDS = {
		panel: 'statusPanel',
		dm: 'statusDm',
		comment: 'statusComment',
		chat: 'statusChat'
	};

	function statusOf( c ) {
		switch ( c.status ) {
			case 'sent':
				return { text: T[ STATUS_WORDS[ c.channel ] ] || T.statusPanel, cls: 'is-sent' };
			case 'queued':
			case 'sending':
				return { text: T.statusQueued, cls: 'is-wait' };
			case 'failed':
				return { text: T.statusFailed, cls: 'is-bad' };
			case 'skipped':
				return { text: c.note || T.statusSkipped, cls: 'is-quiet' };
			default:
				return null;
		}
	}

	function commentNode( c ) {
		var node = el( 'li', 'whdls-comment whdls-comment--' + c.source + ( c.hidden ? ' is-hidden' : '' ) );
		node.setAttribute( 'data-id', c.id );
		node.setAttribute( 'data-source', c.source );
		var head = el( 'div', 'whdls-comment__head' );
		head.appendChild( el( 'span', 'whdls-source-dot', T[ c.source ] || c.source ) );
		head.appendChild( el( 'strong', '', c.author ) );
		head.appendChild( el( 'time', '', c.time ) );
		node.appendChild( head );
		node.appendChild( el( 'p', '', c.message ) );

		var st = statusOf( c );
		if ( st ) {
			var chip = el( 'span', 'whdls-status ' + st.cls, st.text );
			if ( c.note ) {
				chip.title = c.note;
			}
			node.appendChild( chip );
		}
		var acts = el( 'div', 'whdls-comment__do' );
		if ( 'site' !== c.source && ( D.destinations[ c.source ] || {} ).replies && 'sent' !== c.status ) {
			var send = el( 'button', 'whdls-mini', T.sendLink );
			send.type = 'button';
			send.addEventListener( 'click', function () {
				if ( ! S.showing ) {
					banner( 'piece', T.needPiece );
					return;
				}
				api( 'POST', 'comment/' + c.id + '/send', { product: S.showing } ).then( function ( res ) {
					if ( res.row ) {
						absorb( res.row );
					}
				} ).catch( function ( err ) {
					banner( 'send', err.message );
				} );
			} );
			acts.appendChild( send );
		}
		if ( ! c.hidden ) {
			var hide = el( 'button', 'whdls-mini', T.hideComment );
			hide.type = 'button';
			hide.addEventListener( 'click', function () {
				api( 'POST', 'comment/' + c.id + '/hide', {} ).then( function () {
					c.hidden = true;
					absorb( c );
				} );
			} );
			acts.appendChild( hide );
			var block = el( 'button', 'whdls-mini whdls-mini--danger', T.block );
			block.type = 'button';
			block.addEventListener( 'click', function () {
				api( 'POST', 'comment/' + c.id + '/block', {} ).then( function () {
					c.hidden = true;
					absorb( c );
				} );
			} );
			acts.appendChild( block );
		}
		node.appendChild( acts );
		return node;
	}

	function absorb( c ) {
		var feed = $( 'feed' );
		var old = feed.querySelector( '[data-id="' + c.id + '"]' );
		S.comments[ c.id ] = c;
		var node = commentNode( c );
		node.hidden = 'all' !== S.filter && c.source !== S.filter;
		if ( old ) {
			feed.replaceChild( node, old );
		} else {
			var stick = feed.scrollHeight - feed.scrollTop - feed.clientHeight < 80;
			feed.appendChild( node );
			if ( stick ) {
				feed.scrollTop = feed.scrollHeight;
			}
		}
	}

	function renderFilter() {
		var box = $( 'filter' );
		box.textContent = '';
		var keys = [ 'all' ].concat( Object.keys( D.destinations || {} ).filter( function ( k ) {
			return D.destinations[ k ].replies;
		} ) );
		if ( keys.length < 3 ) {
			box.hidden = true;
			return;
		}
		keys.forEach( function ( key ) {
			var btn = el( 'button', 'whdls-mini' + ( S.filter === key ? ' is-on' : '' ), 'all' === key ? T.allSources : T[ key ] );
			btn.type = 'button';
			btn.addEventListener( 'click', function () {
				S.filter = key;
				renderFilter();
				$( 'feed' ).querySelectorAll( '.whdls-comment' ).forEach( function ( n ) {
					n.hidden = 'all' !== key && n.getAttribute( 'data-source' ) !== key;
				} );
			} );
			box.appendChild( btn );
		} );
	}

	/* The heartbeat. On the worker's clock, so a tab in the background keeps answering comments. */
	function poll() {
		if ( S.polling ) {
			return;
		}
		S.polling = true;
		var path = D.id + '/feed?after=' + S.after + '&viewers=' + S.viewers;
		api( 'GET', path ).then( function ( res ) {
			clearBanner( 'signedout' );
			( res.new || [] ).forEach( function ( c ) {
				absorb( c );
				S.after = Math.max( S.after, c.id );
			} );
			( res.moved || [] ).forEach( absorb );
			// Not while somebody is typing a code: this redraws the list, and the box with it.
			if ( ( ( res.new || [] ).length || ( res.moved || [] ).length ) && ! ( document.activeElement && document.activeElement.classList.contains( 'whdls-piece__key' ) ) ) {
				renderPieces();
			}
			var stats = res.stats || {};
			$( 's-comments' ).textContent = stats.comments || 0;
			$( 's-links' ).textContent = stats.links || 0;
			$( 's-clicks' ).textContent = stats.clicks || 0;
			Object.keys( res.errors || {} ).forEach( function ( key ) {
				banner( 'pull-' + key, fmt( T.destFailed, T[ key ] || key, res.errors[ key ] ), 'warn' );
			} );
			[ 'facebook', 'youtube' ].forEach( function ( key ) {
				if ( ! ( res.errors || {} )[ key ] ) {
					clearBanner( 'pull-' + key );
				}
			} );
		} ).catch( function ( err ) {
			if ( 401 === err.status || 403 === err.status ) {
				banner( 'signedout', T.signedOut, 'warn' );
			}
		} ).then( function () {
			S.polling = false;
		} );
	}

	/* ─────────────────────────── screen ─────────────────────────── */

	function pad2( n ) {
		return ( n < 10 ? '0' : '' ) + n;
	}

	function updateClock() {
		if ( ! S.onAir || ! S.startedAt ) {
			return;
		}
		var left = Math.max( 0, Math.floor( Date.now() / 1000 ) - S.startedAt );
		var h = Math.floor( left / 3600 );
		$( 'timer' ).textContent = ( h ? h + ':' : '' ) + pad2( Math.floor( left / 60 ) % 60 ) + ':' + pad2( left % 60 );
	}

	function updateOut() {
		var net = { good: T.netGood, warning: T.netWarning, bad: T.netBad }[ S.net ] || '';
		$( 'out' ).textContent = stage.fps + ' fps' + ( net ? ' · ' + net : '' );
		$( 'out' ).title = stage.W + '×' + stage.H;
		$( 'out' ).className = 'whdls-out' + ( 'bad' === S.net ? ' is-bad' : '' );
	}

	function updateMeters() {
		if ( ! mixer ) {
			return;
		}
		mixer.tick();
		var mic = mixer.level( 'mic' );
		$( 'meter' ).style.transform = 'scaleX(' + mic + ')';
		$( 'vu-mic' ).style.transform = 'scaleX(' + mic + ')';
		$( 'vu-music' ).style.transform = 'scaleX(' + mixer.level( 'music' ) + ')';
		$( 'vu-pads' ).style.transform = 'scaleX(' + mixer.level( 'pads' ) + ')';
	}

	stage.onTick = function () {
		S.ticks++;
		if ( 0 === S.ticks % 3 ) {
			updateMeters();
			drawHandles();
			drawSpots();
		}
		if ( 0 === S.ticks % 30 ) {
			updateClock();
			updateOut();
			if ( rec && ! rec.stopped ) {
				renderRecord();
				recWatch( rec );
			}
		}
		if ( S.onAir && 0 === S.ticks % 75 ) {
			poll();
		}
	};

	/* Tabs. */
	document.querySelectorAll( '.whdls-tabs button' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var key = btn.getAttribute( 'data-tab' );
			S.tab = key;
			ensureSpots();
			if ( 'gifs' === key ) {
				klipyFirstLook();
			}
			document.querySelectorAll( '.whdls-tabs button' ).forEach( function ( b ) {
				b.classList.toggle( 'is-on', b === btn );
			} );
			document.querySelectorAll( '.whdls-pane' ).forEach( function ( p ) {
				p.classList.toggle( 'is-on', p.getAttribute( 'data-pane' ) === key );
			} );
			if ( 'cameras' === key ) {
				refreshDevices().catch( function () {} );
			}
			if ( 'looks' === key || 'themes' === key ) {
				syncLooks();
				paintOpenTiles();
			}
			if ( 'sound' === key && mixer ) {
				( ( D.sounds || {} ).fx || [] ).forEach( function ( fx ) {
					mixer.preload( fx.url );
				} );
			}
		} );
	} );
	$( 'transition' ).addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( 'button' );
		if ( ! btn ) {
			return;
		}
		S.transition = +btn.getAttribute( 'data-ms' );
		this.querySelectorAll( 'button' ).forEach( function ( b ) {
			b.classList.toggle( 'is-on', b === btn );
		} );
		saveSoon();
	} );

	/*
	 * C for the cover and M for mute are the two things a host needs without looking. Nothing fires
	 * while somebody is typing.
	 */
	document.addEventListener( 'keydown', function ( e ) {
		var tag = e.target && e.target.tagName;
		if ( 'INPUT' === tag || 'TEXTAREA' === tag || 'SELECT' === tag || e.ctrlKey || e.metaKey || e.altKey || document.querySelector( 'dialog[open]' ) ) {
			return;
		}
		var key = e.key.toLowerCase();
		if ( 'c' === key ) {
			setCover( ! stage.cover );
		} else if ( 'm' === key ) {
			setMuted( ! S.muted );
		} else if ( /^[1-9]$/.test( key ) ) {
			applyScene( +key - 1 );
		} else if ( ( 'delete' === key || 'backspace' === key ) && S.selected ) {
			e.preventDefault();
			removeLayer( S.selected );
		} else if ( 'escape' === key ) {
			select( null );
		}
	} );

	/* Copy, with a way round for a page without the clipboard API or permission to use it. */
	function copyText( text ) {
		var oldWay = function () {
			var box = el( 'textarea' );
			box.value = text;
			box.style.position = 'fixed';
			box.style.opacity = '0';
			document.body.appendChild( box );
			box.select();
			var worked = document.execCommand( 'copy' );
			box.remove();
			if ( ! worked ) {
				throw new Error( 'refused' );
			}
		};
		if ( navigator.clipboard && window.isSecureContext ) {
			return navigator.clipboard.writeText( text ).catch( oldWay ); // a browser can refuse it even on https
		}
		return new Promise( function ( resolve ) {
			oldWay();
			resolve();
		} );
	}

	document.querySelectorAll( '[data-copy]' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			copyText( btn.getAttribute( 'data-copy' ) ).then( function () {
				btn.textContent = T.copied;
				setTimeout( function () {
					btn.textContent = T.copy;
				}, 1600 );
			} ).catch( function () {} );
		} );
	} );

	/* ─────────────────────────── start ─────────────────────────── */

	/* A recording a closed tab left on the server becomes a video now. */
	api( 'POST', D.id + '/recording/recover', {} ).then( function ( res ) {
		( res.recovered || [] ).forEach( announceSaved );
	} ).catch( function () {} );

	renderDests();
	renderPieces();
	renderEffects();
	renderImages();
	renderTextTools();
	renderLooks();
	renderMusic();
	renderLibrary();
	renderPads();
	renderScenes();
	renderLayers();
	renderFilter();
	wireSound();
	$( 'feed' ).setAttribute( 'data-empty', T.noComments );

	document.querySelectorAll( '#whdls-transition button' ).forEach( function ( b ) {
		b.classList.toggle( 'is-on', +b.getAttribute( 'data-ms' ) === S.transition );
	} );

	/* A cover or a mute that was on stays on through a reload: the state of the buttons has to agree. */
	setMuted( S.muted, true );
	if ( stage.cover ) {
		$( 'cover' ).classList.add( 'is-on' );
		$( 'cover' ).setAttribute( 'aria-pressed', 'true' );
		$( 'cover-label' ).textContent = T.uncover;
		root.classList.add( 'is-covered' );
	}

	var productNow = productLayer();
	S.showing = productNow ? productNow.productId : 0;
	refreshCard();
	renderPieces();

	setState( S.stage );
	updateOut();
	stage.start();

	if ( 'ended' === S.stage ) {
		banner( 'ended', T.endedBanner, 'info' );
		poll();
	} else {
		openDevices().then( updatePeek );
		if ( ! D.dailyReady ) {
			banner( 'daily', T.noDaily, 'warn', { label: T.settings, href: D.settingsUrl } );
		} else {
			loadDaily().catch( function () {
				banner( 'daily', T.dailyLoad, 'error' );
			} );
		}
		if ( 'live' === S.stage ) {
			poll();
		}
	}
}() );
