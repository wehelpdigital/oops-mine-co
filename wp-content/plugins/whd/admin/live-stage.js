/**
 * The live stage: the camera and everything on top of it, drawn onto one canvas.
 *
 * That canvas is what goes out as the camera (live-studio.js hands its track to Daily), so whatever is
 * drawn here is in the video itself: the same on the site, Facebook, YouTube and TikTok. Selection
 * handles are not drawn here; they are HTML on top, so they never reach an audience.
 *
 * The clock is a Web Worker. requestAnimationFrame stops and timers are throttled in a background tab,
 * and a host looking at another tab must not freeze the broadcast. Daily's guide says the same.
 *
 * Everything drawn is same-origin or loaded with crossOrigin, because a single tainted image would make
 * captureStream throw. An image that cannot be loaded that way is left out rather than risked.
 *
 * Static layers are drawn once into their own small canvas and only stamped each frame. Text with
 * shadows at 30 fps on a laptop that is also encoding video is where frames would otherwise go.
 *
 * @package whd
 */
( function ( root ) {
	'use strict';

	var SERIF = '"Cormorant Garamond", Georgia, serif';
	var SANS = 'Manrope, "Segoe UI", Arial, sans-serif';
	var ROSE = '#b98b7e';
	var ROSE_DEEP = '#9c6f63';
	var INK = '#2b1f1b';
	var CREAM = '#f7f2ed';
	var FPS = 30;

	var LOOKS = {
		natural: { label: 'Natural', filter: '' },
		warm: { label: 'Warm', filter: 'saturate(1.12) sepia(.12) brightness(1.03)' },
		rosy: { label: 'Rosy', filter: 'saturate(1.1) sepia(.08) hue-rotate(-8deg) brightness(1.05) contrast(.98)' },
		soft: { label: 'Soft', filter: 'brightness(1.08) contrast(.9) saturate(1.04)' },
		vivid: { label: 'Vivid', filter: 'saturate(1.35) contrast(1.06)' },
		cool: { label: 'Cool', filter: 'saturate(.96) hue-rotate(8deg) brightness(1.02)' },
		vintage: { label: 'Vintage', filter: 'sepia(.35) saturate(.9) contrast(.94) brightness(1.02)' },
		mono: { label: 'Mono', filter: 'grayscale(1) contrast(1.06)' }
	};

	var FRAMES = {
		none: { label: 'None' },
		rose: { label: 'Rose line' },
		cream: { label: 'Cream edge' },
		cinema: { label: 'Cinema' },
		vignette: { label: 'Vignette' }
	};

	/* kind: rise (up from the bottom), fall (down from the top), twinkle (in place), burst (out from the middle). */
	var EFFECTS = {
		hearts: { label: 'Hearts', icon: '💖', emoji: [ '💖', '💕', '❤️', '🩷' ], kind: 'rise', count: 34, size: [ 0.05, 0.1 ] },
		confetti: { label: 'Confetti', icon: '🎊', colors: [ ROSE, '#f2d5cb', '#ffffff', '#d4a373', '#e8a0a0', '#3a2b26' ], kind: 'fall', count: 130, size: [ 0.012, 0.024 ] },
		sparkle: { label: 'Sparkle', icon: '✨', emoji: [ '✨', '⭐', '🌟' ], kind: 'twinkle', count: 34, size: [ 0.04, 0.09 ] },
		party: { label: 'Party', icon: '🎉', emoji: [ '🎉', '🎊', '🥳' ], kind: 'burst', count: 30, size: [ 0.05, 0.09 ] },
		money: { label: 'Money', icon: '💸', emoji: [ '💸', '💵' ], kind: 'fall', count: 30, size: [ 0.05, 0.09 ] },
		bags: { label: 'Bags', icon: '🛍️', emoji: [ '🛍️' ], kind: 'rise', count: 20, size: [ 0.06, 0.11 ] },
		balloons: { label: 'Balloons', icon: '🎈', emoji: [ '🎈' ], kind: 'rise', count: 16, size: [ 0.08, 0.14 ] },
		petals: { label: 'Petals', icon: '🌸', emoji: [ '🌸', '🌷', '🌹' ], kind: 'fall', count: 30, size: [ 0.04, 0.08 ] },
		fire: { label: 'Hot', icon: '🔥', emoji: [ '🔥' ], kind: 'rise', count: 26, size: [ 0.05, 0.1 ] },
		snow: { label: 'Snow', icon: '❄️', emoji: [ '❄️' ], kind: 'fall', count: 40, size: [ 0.025, 0.05 ] }
	};

	var ANIMS = {
		none: 'Still',
		float: 'Float',
		pulse: 'Pulse',
		swing: 'Swing',
		bounce: 'Bounce',
		spin: 'Spin'
	};

	/* What each kind of layer starts as. x, y: its centre as a fraction of the picture. w: fraction of the width. */
	var LAYER_DEFAULTS = {
		image: { w: 0.22, enter: 'pop' },
		text: { w: 0.42, enter: 'pop', style: 'ribbon', text: '' },
		product: { w: 0.42, enter: 'slide' },
		lower: { w: 0.5, enter: 'slide', title: '', subtitle: '' },
		countdown: { w: 0.26, enter: 'pop', label: 'Ends in', endsAt: 0 },
		ticker: { w: 1, enter: 'fade', text: '', speed: 110 },
		source: { w: 0.27, enter: 'pop', shape: 'rounded', sourceId: '' }
	};

	var uid = 0;

	function clamp( n, a, b ) {
		return n < a ? a : n > b ? b : n;
	}
	function rand( a, b ) {
		return a + Math.random() * ( b - a );
	}
	function pick( list ) {
		return list[ Math.floor( Math.random() * list.length ) ];
	}
	function easeOutBack( t ) {
		var c = 1.70158;
		return 1 + ( c + 1 ) * Math.pow( t - 1, 3 ) + c * Math.pow( t - 1, 2 );
	}
	function easeOutCubic( t ) {
		return 1 - Math.pow( 1 - t, 3 );
	}

	function makeCanvas( w, h ) {
		var c = document.createElement( 'canvas' );
		c.width = Math.max( 1, Math.ceil( w ) );
		c.height = Math.max( 1, Math.ceil( h ) );
		return c;
	}

	function roundRect( ctx, x, y, w, h, r ) {
		r = Math.min( r, w / 2, h / 2 );
		ctx.beginPath();
		ctx.moveTo( x + r, y );
		ctx.arcTo( x + w, y, x + w, y + h, r );
		ctx.arcTo( x + w, y + h, x, y + h, r );
		ctx.arcTo( x, y + h, x, y, r );
		ctx.arcTo( x, y, x + w, y, r );
		ctx.closePath();
	}

	/* Text with even letter spacing, where the browser cannot do it itself. */
	function spaced( ctx, text, spacing ) {
		if ( 'letterSpacing' in ctx ) {
			ctx.letterSpacing = spacing + 'px';
		}
		return text;
	}

	function wrapLines( ctx, text, width, max ) {
		var words = String( text ).split( /\s+/ );
		var lines = [];
		var line = '';
		for ( var i = 0; i < words.length; i++ ) {
			var test = line ? line + ' ' + words[ i ] : words[ i ];
			if ( ctx.measureText( test ).width > width && line ) {
				lines.push( line );
				line = words[ i ];
				if ( lines.length === max - 1 ) {
					// The last line takes the rest, cut with an ellipsis.
					var rest = words.slice( i ).join( ' ' );
					while ( ctx.measureText( rest + '…' ).width > width && rest.length > 1 ) {
						rest = rest.slice( 0, -1 );
					}
					lines.push( rest === words.slice( i ).join( ' ' ) ? rest : rest.trim() + '…' );
					return lines;
				}
			} else {
				line = test;
			}
		}
		if ( line ) {
			lines.push( line );
		}
		return lines;
	}

	/* ─────────────────────────── the stage ─────────────────────────── */

	function Stage( canvas, options ) {
		options = options || {};
		this.canvas = canvas;
		this.W = options.width || 1280;
		this.H = options.height || 720;
		canvas.width = this.W;
		canvas.height = this.H;
		this.ctx = canvas.getContext( '2d', { alpha: false } );
		this.canFilter = 'filter' in this.ctx;

		this.layers = [];
		this.particles = [];
		this.rain = null;
		this.look = 'natural';
		this.brightness = 1;
		this.frame = 'none';
		this.hideGraphics = false;
		this.placeholder = options.placeholder || '';
		this.logo = options.logo || '';
		this.images = {};
		this.sprites = {};
		this.onTick = null;
		this.onChange = null;
		this.frames = 0;
		this.fps = 0;
		this._fpsAt = 0;
		this._last = 0;
		this._worker = null;
		this._filter = '';
		this._frameCache = {};
		this._capture = null;

		this.cover = false;
		this.coverLine = '';
		this.coverLogo = options.coverLogo || '';
		this.brand = options.brand || '';
		this._coverOff = 0;
		this._coverBase = null;
		this._coverOrbs = null;
		this._coverText = null;
		this._coverSparks = null;
		if ( this.coverLogo ) {
			this.image( this.coverLogo );
		}

		this.sources = {};
		this.main = '';
		this._snap = null;
		this._snapFrom = 0;
		this._snapMs = 0;
	}

	/* The clock, from a worker so a background tab keeps it running. */
	Stage.prototype.start = function () {
		var self = this;
		if ( this._worker ) {
			return;
		}
		var body = 'var t=null;onmessage=function(e){clearInterval(t);if(e.data>0){t=setInterval(function(){postMessage(0)},e.data)}}';
		try {
			var url = URL.createObjectURL( new Blob( [ body ], { type: 'text/javascript' } ) );
			this._worker = new Worker( url );
			this._worker.onmessage = function () {
				self.tick();
			};
			this._worker.postMessage( Math.round( 1000 / FPS ) );
		} catch ( e ) {
			// A blocked worker: the page still works in the foreground.
			this._timer = setInterval( function () {
				self.tick();
			}, Math.round( 1000 / FPS ) );
		}
	};

	Stage.prototype.tick = function () {
		var now = performance.now();
		this.render( now );
		this.frames++;
		if ( now - this._fpsAt >= 1000 ) {
			this.fps = this.frames;
			this.frames = 0;
			this._fpsAt = now;
		}
		if ( this.onTick ) {
			this.onTick( now );
		}
	};

	/* The canvas as a video track. One track for the life of the stage: the camera behind it can change. */
	Stage.prototype.captureTrack = function () {
		if ( ! this._capture ) {
			this._capture = this.canvas.captureStream( FPS ).getVideoTracks()[ 0 ];
		}
		return this._capture;
	};

	/* ─────────────────────────── sources: every camera, and the screen ─────────────────────────── */

	/**
	 * A source is anything with moving pictures: a camera or a shared screen. One is the main picture;
	 * others can sit on top as `source` layers. They all stay open, which is what makes a switch instant.
	 *
	 * @param {string}           id    Stable across reloads for a camera (cam:<device id>).
	 * @param {HTMLVideoElement} video Playing, in the page.
	 * @param {Object}           meta  label, kind (camera|screen), flip, zoom, fit (cover|contain).
	 */
	Stage.prototype.addSource = function ( id, video, meta ) {
		var source = { id: id, video: video, label: '', kind: 'camera', flip: false, zoom: 1, fit: 'cover' };
		for ( var key in meta || {} ) {
			source[ key ] = meta[ key ];
		}
		this.sources[ id ] = source;
		if ( ! this.main ) {
			this.main = id;
		}
		return source;
	};

	Stage.prototype.removeSource = function ( id ) {
		delete this.sources[ id ];
		this.layers = this.layers.filter( function ( layer ) {
			return ! ( 'source' === layer.type && layer.sourceId === id );
		} );
		if ( this.main === id ) {
			this.main = Object.keys( this.sources )[ 0 ] || '';
		}
		this.changed();
	};

	/* Keep the frame on screen now, to fade away over whatever is drawn next. */
	Stage.prototype.holdFrame = function ( ms ) {
		if ( ! ms || this.cover ) {
			return;
		}
		if ( ! this._snap ) {
			this._snap = makeCanvas( this.W, this.H );
		}
		this._snap.getContext( '2d' ).drawImage( this.canvas, 0, 0 );
		this._snapFrom = performance.now();
		this._snapMs = ms;
	};

	/* Make a source the main picture. @param {number} ms Fade length; 0 is a straight cut. */
	Stage.prototype.setMain = function ( id, ms ) {
		if ( ! this.sources[ id ] || this.main === id ) {
			return;
		}
		this.holdFrame( ms );
		this.main = id;
		this.changed();
	};

	Stage.prototype.setSourceProps = function ( id, props ) {
		var source = this.sources[ id ];
		if ( source ) {
			for ( var key in props ) {
				source[ key ] = props[ key ];
			}
			this.changed();
		}
	};

	Stage.prototype.setLook = function ( key ) {
		this.look = LOOKS[ key ] ? key : 'natural';
		this._rebuildFilter();
		this.changed();
	};

	Stage.prototype.setBrightness = function ( value ) {
		this.brightness = clamp( +value || 1, 0.5, 1.6 );
		this._rebuildFilter();
		this.changed();
	};

	Stage.prototype._rebuildFilter = function () {
		var parts = [];
		if ( LOOKS[ this.look ] && LOOKS[ this.look ].filter ) {
			parts.push( LOOKS[ this.look ].filter );
		}
		if ( 1 !== this.brightness ) {
			parts.push( 'brightness(' + this.brightness + ')' );
		}
		this._filter = parts.join( ' ' );
	};

	Stage.prototype.setFrame = function ( key ) {
		this.frame = FRAMES[ key ] ? key : 'none';
		this.changed();
	};

	Stage.prototype.changed = function () {
		if ( this.onChange ) {
			this.onChange();
		}
	};

	/* ─────────────────────────── images ─────────────────────────── */

	Stage.prototype.image = function ( src, done ) {
		var self = this;
		var entry = this.images[ src ];
		if ( entry ) {
			if ( entry.ok && done ) {
				done( entry.img );
			}
			return entry.ok ? entry.img : null;
		}
		var img = new Image();
		img.crossOrigin = 'anonymous';
		entry = this.images[ src ] = { img: img, ok: false };
		img.onload = function () {
			entry.ok = true;
			// Anything drawn from this image is out of date now.
			self.layers.forEach( function ( layer ) {
				layer._key = '';
			} );
			if ( done ) {
				done( img );
			}
		};
		img.onerror = function () {
			entry.failed = true;
		};
		img.src = src;
		return null;
	};

	/* ─────────────────────────── layers ─────────────────────────── */

	Stage.prototype.add = function ( def, quiet ) {
		var base = LAYER_DEFAULTS[ def.type ] || {};
		var layer = {};
		var key;
		for ( key in base ) {
			layer[ key ] = base[ key ];
		}
		layer.x = 0.5;
		layer.y = 0.5;
		layer.rot = 0;
		layer.opacity = 1;
		layer.anim = 'none';
		layer.visible = true;
		for ( key in def ) {
			layer[ key ] = def[ key ];
		}
		layer.id = def.id || 'l' + Date.now().toString( 36 ) + ( ++uid );
		layer._t0 = quiet ? -1e9 : performance.now();
		layer._key = '';
		this.layers.push( layer );
		if ( layer.src ) {
			this.image( layer.src );
		}
		if ( ! quiet ) {
			this.changed();
		}
		return layer;
	};

	Stage.prototype.get = function ( id ) {
		for ( var i = 0; i < this.layers.length; i++ ) {
			if ( this.layers[ i ].id === id ) {
				return this.layers[ i ];
			}
		}
		return null;
	};

	Stage.prototype.update = function ( id, props, quiet ) {
		var layer = this.get( id );
		if ( ! layer ) {
			return null;
		}
		for ( var key in props ) {
			layer[ key ] = props[ key ];
		}
		layer._key = '';
		if ( ! quiet ) {
			this.changed();
		}
		return layer;
	};

	/* Leaves with a short fade; drops off the list once that has played. */
	Stage.prototype.remove = function ( id ) {
		var layer = this.get( id );
		if ( layer && ! layer._gone ) {
			layer._gone = performance.now();
			this.changed();
		}
	};

	Stage.prototype.clear = function () {
		var now = performance.now();
		this.layers.forEach( function ( layer ) {
			layer._gone = layer._gone || now;
		} );
		this.setRain( null );
		this.changed();
	};

	Stage.prototype.raise = function ( id ) {
		var layer = this.get( id );
		if ( layer ) {
			this.layers.splice( this.layers.indexOf( layer ), 1 );
			this.layers.push( layer );
			this.changed();
		}
	};

	/* The fraction of the picture a layer covers, ignoring rotation and idle animation. */
	Stage.prototype.rect = function ( id ) {
		var layer = this.get( id );
		if ( ! layer || ! layer._box ) {
			return null;
		}
		var b = layer._box;
		return [ ( b.cx - b.w / 2 ) / this.W, ( b.cy - b.h / 2 ) / this.H, b.w / this.W, b.h / this.H ];
	};

	/* Topmost layer under a point given in picture fractions. */
	Stage.prototype.hit = function ( nx, ny ) {
		var px = nx * this.W;
		var py = ny * this.H;
		for ( var i = this.layers.length - 1; i >= 0; i-- ) {
			var layer = this.layers[ i ];
			if ( layer._gone || false === layer.visible || ! layer._box ) {
				continue;
			}
			var b = layer._box;
			if ( Math.abs( px - b.cx ) <= b.w / 2 && Math.abs( py - b.cy ) <= b.h / 2 ) {
				return layer;
			}
		}
		return null;
	};

	/* What gets saved. Anything starting with an underscore is only for this page's life. */
	Stage.prototype.toJSON = function () {
		return {
			look: this.look,
			brightness: this.brightness,
			frame: this.frame,
			layers: this.layers.filter( function ( layer ) {
				return ! layer._gone;
			} ).map( function ( layer ) {
				var out = {};
				for ( var key in layer ) {
					if ( '_' !== key.charAt( 0 ) ) {
						out[ key ] = layer[ key ];
					}
				}
				return out;
			} )
		};
	};

	Stage.prototype.load = function ( scene ) {
		var self = this;
		scene = scene || {};
		this.layers = [];
		( scene.layers || [] ).forEach( function ( def ) {
			self.add( def, true );
		} );
		this.look = LOOKS[ scene.look ] ? scene.look : 'natural';
		this.brightness = +scene.brightness || 1;
		this.frame = FRAMES[ scene.frame ] ? scene.frame : 'none';
		this._rebuildFilter();
	};

	/* ─────────────────────────── effects ─────────────────────────── */

	Stage.prototype.spriteFor = function ( emoji ) {
		if ( this.sprites[ emoji ] ) {
			return this.sprites[ emoji ];
		}
		var c = makeCanvas( 160, 160 );
		var x = c.getContext( '2d' );
		x.font = '120px "Segoe UI Emoji","Apple Color Emoji","Noto Color Emoji",sans-serif';
		x.textAlign = 'center';
		x.textBaseline = 'middle';
		x.fillText( emoji, 80, 88 );
		this.sprites[ emoji ] = c;
		return c;
	};

	Stage.prototype.emitOne = function ( effect, src, delay ) {
		var size = this.H * rand( effect.size[ 0 ], effect.size[ 1 ] );
		var W = this.W;
		var H = this.H;
		var p = {
			kind: effect.kind,
			size: size,
			delay: delay || 0,
			age: 0,
			rot: rand( -0.4, 0.4 ),
			spin: rand( -1.2, 1.2 ),
			sway: rand( 0, Math.PI * 2 ),
			swayAmp: rand( 10, 46 ),
			swayRate: rand( 1, 2.4 ),
			alpha: 1
		};
		if ( effect.colors ) {
			p.color = pick( effect.colors );
			p.aspect = rand( 0.4, 1 );
			p.spin = rand( -6, 6 );
		} else if ( src ) {
			p.image = src;
		} else {
			p.sprite = this.spriteFor( pick( effect.emoji ) );
		}
		switch ( effect.kind ) {
			case 'rise':
				p.x = rand( W * 0.04, W * 0.96 );
				p.y = H + size;
				p.vx = 0;
				p.vy = -rand( H * 0.16, H * 0.34 );
				p.life = ( H + size * 2 ) / -p.vy;
				break;
			case 'fall':
				p.x = rand( 0, W );
				p.y = -size;
				p.vx = rand( -24, 24 );
				p.vy = rand( H * 0.16, H * 0.36 );
				p.life = ( H + size * 2 ) / p.vy;
				break;
			case 'twinkle':
				p.x = rand( W * 0.06, W * 0.94 );
				p.y = rand( H * 0.06, H * 0.9 );
				p.vx = 0;
				p.vy = 0;
				p.swayAmp = 0;
				p.life = rand( 0.7, 1.5 );
				break;
			default: { // burst
				var angle = rand( 0, Math.PI * 2 );
				var speed = rand( H * 0.3, H * 0.75 );
				p.x = W / 2;
				p.y = H * 0.55;
				p.vx = Math.cos( angle ) * speed;
				p.vy = Math.sin( angle ) * speed - H * 0.15;
				p.g = H * 0.7;
				p.swayAmp = 0;
				p.life = rand( 1.2, 1.9 );
			}
		}
		if ( this.particles.length < 500 ) {
			this.particles.push( p );
		}
	};

	/* One burst of an effect, spread over a couple of seconds. */
	Stage.prototype.burst = function ( key, src ) {
		var effect = EFFECTS[ key ];
		if ( ! effect ) {
			return;
		}
		for ( var i = 0; i < effect.count; i++ ) {
			this.emitOne( effect, src, 'burst' === effect.kind ? rand( 0, 0.15 ) : rand( 0, 2.2 ) );
		}
	};

	/* Pictures floating up: a burst of a picture of the host's own. */
	Stage.prototype.floatImage = function ( src, kind ) {
		var self = this;
		this.image( src, function () {
			var effect = { kind: kind || 'rise', size: [ 0.1, 0.2 ], count: 12 };
			for ( var i = 0; i < effect.count; i++ ) {
				self.emitOne( effect, src, rand( 0, 2.4 ) );
			}
		} );
	};

	/* An effect that keeps going until it is turned off. */
	Stage.prototype.setRain = function ( key, src ) {
		this.rain = key && ( EFFECTS[ key ] || src ) ? { key: key, src: src || '', acc: 0 } : null;
		if ( src ) {
			this.image( src );
		}
	};

	Stage.prototype._runRain = function ( dt ) {
		var rain = this.rain;
		if ( ! rain ) {
			return;
		}
		var effect = rain.src ? { kind: 'rise', size: [ 0.1, 0.18 ], count: 12 } : EFFECTS[ rain.key ];
		// A burst spread over 2.2 s, repeating: the same density as pressing it over and over.
		rain.acc += dt * ( effect.count / 2.2 );
		while ( rain.acc >= 1 ) {
			rain.acc -= 1;
			this.emitOne( effect, rain.src );
		}
	};

	Stage.prototype._drawParticles = function ( ctx, dt ) {
		var list = this.particles;
		var keep = [];
		for ( var i = 0; i < list.length; i++ ) {
			var p = list[ i ];
			if ( p.delay > 0 ) {
				p.delay -= dt;
				keep.push( p );
				continue;
			}
			p.age += dt;
			if ( p.age > p.life ) {
				continue;
			}
			if ( p.g ) {
				p.vy += p.g * dt;
			}
			p.x += p.vx * dt;
			p.y += p.vy * dt;
			p.rot += p.spin * dt;
			p.sway += p.swayRate * dt;

			var t = p.age / p.life;
			var alpha = 1;
			var scale = 1;
			if ( 'twinkle' === p.kind ) {
				scale = Math.sin( t * Math.PI );
				alpha = scale;
			} else if ( 'burst' === p.kind ) {
				alpha = t > 0.7 ? ( 1 - t ) / 0.3 : 1;
				scale = 0.6 + 0.4 * easeOutCubic( Math.min( 1, t * 3 ) );
			} else {
				alpha = t > 0.85 ? ( 1 - t ) / 0.15 : Math.min( 1, t * 8 );
			}

			ctx.save();
			ctx.globalAlpha = clamp( alpha, 0, 1 );
			ctx.translate( p.x + Math.sin( p.sway ) * p.swayAmp, p.y );
			ctx.rotate( p.rot );
			var s = p.size * scale;
			if ( p.color ) {
				ctx.fillStyle = p.color;
				ctx.fillRect( -s / 2, -s * p.aspect / 2, s, s * p.aspect );
			} else if ( p.sprite ) {
				ctx.drawImage( p.sprite, -s / 2, -s / 2, s, s );
			} else if ( p.image ) {
				var entry = this.images[ p.image ];
				if ( entry && entry.ok ) {
					var ratio = entry.img.naturalHeight / ( entry.img.naturalWidth || 1 );
					ctx.drawImage( entry.img, -s / 2, -s * ratio / 2, s, s * ratio );
				}
			}
			ctx.restore();
			keep.push( p );
		}
		this.particles = keep;
	};

	/* ─────────────────────────── drawing a layer once ─────────────────────────── */

	Stage.prototype._textSprite = function ( layer ) {
		var style = layer.style || 'ribbon';
		var lines = String( layer.text || '' ).split( /\n/ ).slice( 0, 4 );
		var probe = makeCanvas( 4, 4 ).getContext( '2d' );
		var size = 96;
		var font;
		var pad = [ 56, 30 ];
		var spacing = 0;
		switch ( style ) {
			case 'serif':
				font = 'italic 500 ' + size + 'px ' + SERIF;
				pad = [ 36, 24 ];
				break;
			case 'tag':
				font = '600 ' + ( size - 24 ) + 'px ' + SANS;
				size = 72;
				break;
			case 'dark':
				font = '600 ' + ( size - 24 ) + 'px ' + SANS;
				size = 72;
				break;
			case 'neon':
				font = '700 ' + size + 'px ' + SANS;
				pad = [ 60, 50 ];
				break;
			case 'burst':
				font = '800 ' + ( size - 16 ) + 'px ' + SANS;
				size = 80;
				spacing = 2;
				break;
			default: // ribbon
				font = '800 ' + ( size - 28 ) + 'px ' + SANS;
				size = 68;
				spacing = 6;
				lines = lines.map( function ( l ) {
					return l.toUpperCase();
				} );
		}
		probe.font = font;
		spaced( probe, '', spacing );
		var textW = 1;
		lines.forEach( function ( l ) {
			textW = Math.max( textW, probe.measureText( l ).width );
		} );
		var lineH = size * 1.18;
		var textH = lineH * lines.length;

		if ( 'burst' === style ) {
			var d = Math.max( textW + 150, textH + 220 );
			var cb = makeCanvas( d, d );
			var xb = cb.getContext( '2d' );
			var r = d / 2;
			xb.translate( r, r );
			xb.beginPath();
			for ( var i = 0; i < 32; i++ ) {
				var ang = ( i / 32 ) * Math.PI * 2;
				var rad = i % 2 ? r * 0.84 : r * 0.99;
				xb[ i ? 'lineTo' : 'moveTo' ]( Math.cos( ang ) * rad, Math.sin( ang ) * rad );
			}
			xb.closePath();
			xb.fillStyle = ROSE;
			xb.fill();
			xb.lineWidth = 6;
			xb.strokeStyle = 'rgba(255,255,255,.85)';
			xb.beginPath();
			xb.arc( 0, 0, r * 0.74, 0, Math.PI * 2 );
			xb.stroke();
			xb.font = font;
			spaced( xb, '', spacing );
			xb.fillStyle = '#fff';
			xb.textAlign = 'center';
			xb.textBaseline = 'middle';
			lines.forEach( function ( l, n ) {
				xb.fillText( l, 0, ( n - ( lines.length - 1 ) / 2 ) * lineH );
			} );
			return cb;
		}

		var c = makeCanvas( textW + pad[ 0 ] * 2, textH + pad[ 1 ] * 2 );
		var x = c.getContext( '2d' );
		var W = c.width;
		var H = c.height;
		x.font = font;
		spaced( x, '', spacing );
		x.textBaseline = 'middle';
		x.textAlign = 'center';

		if ( 'ribbon' === style ) {
			var g = x.createLinearGradient( 0, 0, W, H );
			g.addColorStop( 0, ROSE );
			g.addColorStop( 1, ROSE_DEEP );
			roundRect( x, 0, 0, W, H, H / 2 );
			x.fillStyle = g;
			x.fill();
			x.fillStyle = '#fff';
		} else if ( 'tag' === style ) {
			roundRect( x, 0, 0, W, H, 18 );
			x.fillStyle = CREAM;
			x.fill();
			x.fillStyle = INK;
		} else if ( 'dark' === style ) {
			roundRect( x, 0, 0, W, H, 18 );
			x.fillStyle = 'rgba(30,22,19,.82)';
			x.fill();
			x.fillStyle = '#fff';
		} else if ( 'neon' === style ) {
			x.shadowColor = '#ff7eb6';
			x.shadowBlur = 34;
			x.fillStyle = '#ffe8f3';
		} else {
			x.shadowColor = 'rgba(0,0,0,.55)';
			x.shadowBlur = 18;
			x.shadowOffsetY = 3;
			x.fillStyle = '#fff';
		}
		lines.forEach( function ( l, n ) {
			var y = H / 2 + ( n - ( lines.length - 1 ) / 2 ) * lineH;
			x.fillText( l, W / 2, y );
			if ( 'neon' === style ) {
				x.fillText( l, W / 2, y ); // twice, for a glow with some body
			}
		} );
		return c;
	};

	Stage.prototype._lowerSprite = function ( layer ) {
		var c = makeCanvas( 900, 170 );
		var x = c.getContext( '2d' );
		roundRect( x, 0, 0, 900, 170, 16 );
		x.fillStyle = 'rgba(247,242,237,.96)';
		x.fill();
		x.fillStyle = ROSE;
		x.fillRect( 0, 18, 10, 134 );
		x.fillStyle = INK;
		x.font = '500 68px ' + SERIF;
		x.textBaseline = 'middle';
		var title = wrapLines( x, layer.title || '', 830, 1 )[ 0 ] || '';
		x.fillText( title, 40, layer.subtitle ? 64 : 85 );
		if ( layer.subtitle ) {
			x.font = '600 30px ' + SANS;
			spaced( x, '', 1 );
			x.fillStyle = ROSE_DEEP;
			x.fillText( wrapLines( x, layer.subtitle, 830, 1 )[ 0 ] || '', 42, 124 );
		}
		return c;
	};

	Stage.prototype._countdownText = function ( layer ) {
		var left = Math.max( 0, Math.ceil( ( ( +layer.endsAt || 0 ) - Date.now() ) / 1000 ) );
		var m = Math.floor( left / 60 );
		var s = left % 60;
		return ( m < 10 ? '0' : '' ) + m + ':' + ( s < 10 ? '0' : '' ) + s;
	};

	Stage.prototype._countdownSprite = function ( layer ) {
		var c = makeCanvas( 520, 220 );
		var x = c.getContext( '2d' );
		var g = x.createLinearGradient( 0, 0, 520, 220 );
		g.addColorStop( 0, 'rgba(43,31,27,.92)' );
		g.addColorStop( 1, 'rgba(90,60,52,.92)' );
		roundRect( x, 0, 0, 520, 220, 26 );
		x.fillStyle = g;
		x.fill();
		x.textAlign = 'center';
		x.textBaseline = 'middle';
		x.font = '700 32px ' + SANS;
		spaced( x, '', 5 );
		x.fillStyle = '#e9c8bc';
		x.fillText( String( layer.label || '' ).toUpperCase(), 260, 58 );
		x.font = '800 104px ' + SANS;
		spaced( x, '', 2 );
		x.fillStyle = '#fff';
		x.fillText( this._countdownText( layer ), 260, 142 );
		return c;
	};

	/**
	 * The product card: photo, name, price, and what to do about it.
	 *
	 * The short link is always on it, because on TikTok it is the only way to the piece.
	 */
	Stage.prototype._productSprite = function ( layer ) {
		var W = 860;
		var H = 384;
		var c = makeCanvas( W, H );
		var x = c.getContext( '2d' );

		x.save();
		x.shadowColor = 'rgba(30,20,16,.35)';
		x.shadowBlur = 24;
		x.shadowOffsetY = 8;
		roundRect( x, 12, 10, W - 24, H - 26, 26 );
		x.fillStyle = '#fffaf6';
		x.fill();
		x.restore();

		x.save();
		roundRect( x, 12, 10, H - 26, H - 26, 26 );
		x.clip();
		x.fillStyle = '#efe6de';
		x.fillRect( 12, 10, H - 26, H - 26 );
		var entry = layer.image ? this.images[ layer.image ] : null;
		if ( entry && entry.ok ) {
			var img = entry.img;
			var box = H - 26;
			var scale = Math.max( box / img.naturalWidth, box / img.naturalHeight );
			var dw = img.naturalWidth * scale;
			var dh = img.naturalHeight * scale;
			x.drawImage( img, 12 + ( box - dw ) / 2, 10 + ( box - dh ) / 2, dw, dh );
		}
		x.restore();

		var tx = H + 8;
		var tw = W - tx - 40;
		var y = 58;
		x.textBaseline = 'alphabetic';
		x.fillStyle = ROSE_DEEP;
		x.font = '800 22px ' + SANS;
		spaced( x, '', 4 );
		x.fillText( String( layer.kicker || '' ).toUpperCase(), tx, y );

		x.font = '500 50px ' + SERIF;
		spaced( x, '', 0 );
		x.fillStyle = INK;
		var lines = wrapLines( x, layer.name || '', tw, 2 );
		y += 14;
		lines.forEach( function ( l ) {
			y += 50;
			x.fillText( l, tx, y );
		} );

		y += 52;
		x.font = '800 42px ' + SANS;
		spaced( x, '', 0 );
		x.fillStyle = INK;
		var price = String( layer.price || '' );
		x.fillText( price, tx, y );
		if ( layer.regular ) {
			var pw = x.measureText( price ).width;
			x.font = '500 28px ' + SANS;
			x.fillStyle = '#9a8a82';
			var rw = x.measureText( layer.regular ).width;
			x.fillText( layer.regular, tx + pw + 16, y );
			x.fillRect( tx + pw + 14, y - 10, rw + 4, 2.5 );
		}

		if ( layer.cta ) {
			x.font = '700 24px ' + SANS;
			var cw = Math.min( x.measureText( layer.cta ).width + 44, tw );
			y += 26;
			roundRect( x, tx, y, cw, 48, 24 );
			x.fillStyle = ROSE;
			x.fill();
			x.fillStyle = '#fff';
			x.textBaseline = 'middle';
			x.fillText( layer.cta, tx + 22, y + 25, tw - 44 );
			x.textBaseline = 'alphabetic';
			y += 48;
		}

		x.font = '600 22px ' + SANS;
		x.fillStyle = ROSE_DEEP;
		x.fillText( String( layer.link || '' ), tx, Math.min( y + 34, H - 34 ), tw );
		return c;
	};

	/* The ready-drawn canvas for a layer, redrawn only when what it shows has changed. */
	Stage.prototype._spriteOf = function ( layer ) {
		if ( 'image' === layer.type ) {
			var entry = this.images[ layer.src ];
			return entry && entry.ok ? entry.img : null;
		}
		var key;
		switch ( layer.type ) {
			case 'text':
				key = 't|' + layer.style + '|' + layer.text;
				break;
			case 'lower':
				key = 'l|' + layer.title + '|' + layer.subtitle;
				break;
			case 'countdown':
				key = 'c|' + layer.label + '|' + this._countdownText( layer );
				break;
			case 'product':
				key = 'p|' + layer.name + layer.price + layer.regular + layer.cta + layer.link + layer.kicker + ( layer.image && this.images[ layer.image ] && this.images[ layer.image ].ok ? 1 : 0 );
				break;
			default:
				return null;
		}
		if ( layer._key === key && layer._sprite ) {
			return layer._sprite;
		}
		switch ( layer.type ) {
			case 'text':
				layer._sprite = this._textSprite( layer );
				break;
			case 'lower':
				layer._sprite = this._lowerSprite( layer );
				break;
			case 'countdown':
				layer._sprite = this._countdownSprite( layer );
				break;
			case 'product':
				if ( layer.image ) {
					this.image( layer.image );
				}
				layer._sprite = this._productSprite( layer );
				break;
		}
		layer._key = key;
		return layer._sprite;
	};

	Stage.prototype._drawTicker = function ( ctx, layer, now, alpha ) {
		var bandH = Math.round( this.H * 0.075 );
		var cy = layer.y * this.H;
		layer._box = { cx: this.W / 2, cy: cy, w: this.W, h: bandH };
		var key = 'k|' + layer.text;
		if ( layer._key !== key || ! layer._sprite ) {
			var probe = makeCanvas( 4, 4 ).getContext( '2d' );
			var font = '600 ' + Math.round( bandH * 0.46 ) + 'px ' + SANS;
			probe.font = font;
			var text = String( layer.text || '' ) + '      ✦      ';
			var c = makeCanvas( probe.measureText( text ).width + 2, bandH );
			var x = c.getContext( '2d' );
			x.font = font;
			x.textBaseline = 'middle';
			x.fillStyle = '#fff';
			x.fillText( text, 0, bandH / 2 + 1 );
			layer._sprite = c;
			layer._key = key;
		}
		ctx.save();
		ctx.globalAlpha = alpha * ( layer.opacity || 1 );
		ctx.fillStyle = 'rgba(43,31,27,.86)';
		ctx.fillRect( 0, cy - bandH / 2, this.W, bandH );
		ctx.fillStyle = ROSE;
		ctx.fillRect( 0, cy - bandH / 2, this.W, 3 );
		var w = layer._sprite.width;
		var offset = ( now / 1000 * ( layer.speed || 110 ) ) % w;
		for ( var px = -offset; px < this.W; px += w ) {
			ctx.drawImage( layer._sprite, px, cy - bandH / 2 );
		}
		ctx.restore();
	};

	Stage.prototype._drawLayer = function ( ctx, layer, now ) {
		var age = now - layer._t0;
		var leaving = layer._gone ? ( now - layer._gone ) / 260 : 0;
		var enter = clamp( age / 460, 0, 1 );
		var alpha = layer._gone ? 1 - clamp( leaving, 0, 1 ) : clamp( age / 200, 0, 1 );

		if ( 'ticker' === layer.type ) {
			this._drawTicker( ctx, layer, now, alpha );
			return;
		}
		var isSource = 'source' === layer.type;
		var inset = isSource ? this.sources[ layer.sourceId ] : null;
		var sprite = isSource ? ( inset && inset.video.videoWidth ? inset.video : null ) : this._spriteOf( layer );
		if ( ! sprite ) {
			layer._box = null;
			return;
		}
		var sw = sprite.videoWidth || sprite.naturalWidth || sprite.width;
		var sh = sprite.videoHeight || sprite.naturalHeight || sprite.height;
		var dw = ( +layer.w || 0.2 ) * this.W;
		var dh = isSource && 'circle' === layer.shape ? dw : dw * ( sh / sw );
		var cx = layer.x * this.W;
		var cy = layer.y * this.H;
		layer._box = { cx: cx, cy: cy, w: dw, h: dh };

		var t = now / 1000;
		var scale = 1;
		var rot = ( +layer.rot || 0 ) * Math.PI / 180;
		var dx = 0;
		var dy = 0;
		switch ( layer.anim ) {
			case 'float':
				dy = Math.sin( t * 1.5 ) * this.H * 0.014;
				dx = Math.cos( t * 0.9 ) * this.W * 0.004;
				break;
			case 'pulse':
				scale = 1 + Math.sin( t * 3.2 ) * 0.045;
				break;
			case 'swing':
				rot += Math.sin( t * 2 ) * 0.11;
				break;
			case 'bounce':
				dy = -Math.abs( Math.sin( t * 2.6 ) ) * this.H * 0.03;
				break;
			case 'spin':
				rot += t * 0.9;
				break;
		}

		if ( enter < 1 ) {
			if ( 'slide' === layer.enter ) {
				var fromLeft = layer.x < 0.5;
				dx += ( fromLeft ? -1 : 1 ) * ( 1 - easeOutCubic( enter ) ) * ( dw * 0.6 + this.W * 0.1 );
			} else if ( 'pop' === layer.enter ) {
				scale *= 0.55 + 0.45 * easeOutBack( enter );
			}
		}
		if ( layer._gone ) {
			scale *= 1 - 0.06 * clamp( leaving, 0, 1 );
		}

		ctx.save();
		ctx.globalAlpha = alpha * clamp( +layer.opacity, 0, 1 );
		ctx.translate( cx + dx, cy + dy );
		if ( rot ) {
			ctx.rotate( rot );
		}
		ctx.scale( scale, scale );
		if ( isSource ) {
			this._drawInset( ctx, sprite, inset, layer, dw, dh );
		} else {
			ctx.drawImage( sprite, -dw / 2, -dh / 2, dw, dh );
		}
		ctx.restore();
	};

	/* A camera in a corner: cropped to fill its shape, with a thin edge so it reads against the picture. */
	Stage.prototype._drawInset = function ( ctx, video, source, layer, dw, dh ) {
		var circle = 'circle' === layer.shape;
		var path = function () {
			if ( circle ) {
				ctx.beginPath();
				ctx.arc( 0, 0, dw / 2, 0, Math.PI * 2 );
				ctx.closePath();
			} else {
				roundRect( ctx, -dw / 2, -dh / 2, dw, dh, Math.min( dw, dh ) * 0.07 );
			}
		};
		var vw = video.videoWidth;
		var vh = video.videoHeight;
		var fit = Math.max( dw / vw, dh / vh ) * ( +source.zoom || 1 );
		var sw = dw / fit;
		var sh = dh / fit;
		ctx.save();
		path();
		ctx.clip();
		if ( source.flip ) {
			ctx.scale( -1, 1 );
		}
		ctx.drawImage( video, ( vw - sw ) / 2, ( vh - sh ) / 2, sw, sh, -dw / 2, -dh / 2, dw, dh );
		ctx.restore();
		path();
		ctx.lineWidth = Math.max( 2, Math.min( this.W, this.H ) * 0.005 );
		ctx.strokeStyle = 'rgba(247,242,237,.92)';
		ctx.stroke();
	};

	/* ─────────────────────────── drawing a frame ─────────────────────────── */

	Stage.prototype._drawCamera = function ( ctx ) {
		var source = this.sources[ this.main ];
		var video = source ? source.video : null;
		var W = this.W;
		var H = this.H;
		if ( video && video.videoWidth && video.readyState >= 2 ) {
			var vw = video.videoWidth;
			var vh = video.videoHeight;
			var whole = 'contain' === source.fit;
			var fit = whole ? Math.min( W / vw, H / vh ) : Math.max( W / vw, H / vh );
			var scale = fit * ( +source.zoom || 1 );
			// The part of the camera that fills the frame: zooming in crops from the middle.
			var sw = Math.min( vw, W / scale );
			var sh = Math.min( vh, H / scale );
			var dw = sw * scale;
			var dh = sh * scale;
			if ( dw < W || dh < H ) {
				ctx.fillStyle = '#17110f'; // the bars round a whole screen shown on a differently shaped stream
				ctx.fillRect( 0, 0, W, H );
			}
			ctx.save();
			if ( source.flip ) {
				ctx.translate( W, 0 );
				ctx.scale( -1, 1 );
			}
			if ( this.canFilter && this._filter ) {
				ctx.filter = this._filter;
			}
			ctx.drawImage( video, ( vw - sw ) / 2, ( vh - sh ) / 2, sw, sh, ( W - dw ) / 2, ( H - dh ) / 2, dw, dh );
			ctx.restore();
			return;
		}
		// No picture: the shop's colours and a line, rather than black on a live stream.
		if ( ! this._blank ) {
			var c = makeCanvas( W, H );
			var x = c.getContext( '2d' );
			var g = x.createLinearGradient( 0, 0, W, H );
			g.addColorStop( 0, '#2b1f1b' );
			g.addColorStop( 0.6, '#4b342c' );
			g.addColorStop( 1, '#7a5548' );
			x.fillStyle = g;
			x.fillRect( 0, 0, W, H );
			x.fillStyle = 'rgba(247,242,237,.86)';
			x.font = 'italic 500 ' + Math.round( Math.min( W, H ) * 0.075 ) + 'px ' + SERIF;
			x.textAlign = 'center';
			x.textBaseline = 'middle';
			x.fillText( this.placeholder, W / 2, H / 2 );
			this._blank = c;
		}
		ctx.drawImage( this._blank, 0, 0 );
	};

	Stage.prototype._drawFrame = function ( ctx ) {
		var key = this.frame;
		if ( 'none' === key ) {
			return;
		}
		if ( ! this._frameCache[ key ] ) {
			var W = this.W;
			var H = this.H;
			var c = makeCanvas( W, H );
			var x = c.getContext( '2d' );
			var u = Math.min( W, H );
			if ( 'rose' === key ) {
				x.strokeStyle = ROSE;
				x.lineWidth = u * 0.008;
				x.strokeRect( u * 0.025, u * 0.025, W - u * 0.05, H - u * 0.05 );
				x.strokeStyle = 'rgba(255,255,255,.5)';
				x.lineWidth = 1.5;
				x.strokeRect( u * 0.04, u * 0.04, W - u * 0.08, H - u * 0.08 );
			} else if ( 'cream' === key ) {
				x.strokeStyle = CREAM;
				x.lineWidth = u * 0.05;
				x.strokeRect( 0, 0, W, H );
			} else if ( 'cinema' === key ) {
				x.fillStyle = '#000';
				var bar = H > W ? H * 0.08 : H * 0.1;
				x.fillRect( 0, 0, W, bar );
				x.fillRect( 0, H - bar, W, bar );
			} else {
				var g = x.createRadialGradient( W / 2, H / 2, u * 0.4, W / 2, H / 2, Math.max( W, H ) * 0.72 );
				g.addColorStop( 0, 'rgba(20,12,10,0)' );
				g.addColorStop( 1, 'rgba(20,12,10,.62)' );
				x.fillStyle = g;
				x.fillRect( 0, 0, W, H );
			}
			this._frameCache[ key ] = c;
		}
		ctx.drawImage( this._frameCache[ key ], 0, 0 );
	};

	/* ─────────────────────────── the cover ─────────────────────────── */

	/**
	 * Cover the picture. On is immediate: this is for when something has gone wrong, and a fade would
	 * show another half second of it. Off fades, because nothing is urgent by then.
	 */
	Stage.prototype.setCover = function ( on, line ) {
		var was = this.cover;
		this.cover = !! on;
		if ( 'string' === typeof line ) {
			this.coverLine = line;
			this._coverText = null;
		}
		if ( this.cover ) {
			this.particles = []; // nothing left to burst out from behind it afterwards
			this._coverOff = 0;
			this._snapMs = 0;
		} else if ( was ) {
			this._coverOff = performance.now();
		}
		this.changed();
	};

	Stage.prototype._buildCover = function () {
		var W = this.W;
		var H = this.H;

		var base = makeCanvas( W, H );
		var x = base.getContext( '2d' );
		var g = x.createLinearGradient( 0, 0, W, H );
		g.addColorStop( 0, '#2b1f1b' );
		g.addColorStop( 0.55, '#3f2c26' );
		g.addColorStop( 1, '#5e4238' );
		x.fillStyle = g;
		x.fillRect( 0, 0, W, H );
		this._coverBase = base;

		// Soft light in the shop's colours, drawn once and only moved after that.
		var orb = function ( colour ) {
			var c = makeCanvas( 512, 512 );
			var o = c.getContext( '2d' );
			var r = o.createRadialGradient( 256, 256, 0, 256, 256, 256 );
			r.addColorStop( 0, colour );
			r.addColorStop( 1, 'rgba(0,0,0,0)' );
			o.fillStyle = r;
			o.fillRect( 0, 0, 512, 512 );
			return c;
		};
		this._coverOrbs = [
			{ sprite: orb( 'rgba(185,139,126,.62)' ), size: 1.15, ax: 0.32, ay: 0.3, rx: 0.17, ry: 0.11, phase: 0 },
			{ sprite: orb( 'rgba(242,213,203,.3)' ), size: 0.8, ax: 0.7, ay: 0.68, rx: 0.14, ry: 0.15, phase: 2.1 },
			{ sprite: orb( 'rgba(156,111,99,.55)' ), size: 1.3, ax: 0.78, ay: 0.22, rx: 0.1, ry: 0.13, phase: 4.2 },
			{ sprite: orb( 'rgba(247,242,237,.14)' ), size: 0.7, ax: 0.24, ay: 0.78, rx: 0.12, ry: 0.09, phase: 1.1 }
		];

		this._coverSparks = [];
		for ( var i = 0; i < 26; i++ ) {
			this._coverSparks.push( {
				x: Math.random(),
				y: Math.random(),
				r: rand( 1.2, 3.2 ),
				rate: rand( 0.3, 1 ),
				phase: rand( 0, Math.PI * 2 ),
				drift: rand( 0.004, 0.014 )
			} );
		}
	};

	Stage.prototype._coverLineSprite = function () {
		if ( this._coverText ) {
			return this._coverText;
		}
		var size = Math.round( Math.min( this.W, this.H ) * 0.062 );
		var probe = makeCanvas( 4, 4 ).getContext( '2d' );
		var font = 'italic 500 ' + size + 'px ' + SERIF;
		probe.font = font;
		var c = makeCanvas( Math.min( this.W, probe.measureText( this.coverLine ).width + 40 ), size * 1.6 );
		var x = c.getContext( '2d' );
		x.font = font;
		x.fillStyle = 'rgba(247,242,237,.92)';
		x.textAlign = 'center';
		x.textBaseline = 'middle';
		x.fillText( this.coverLine, c.width / 2, c.height / 2, c.width - 8 );
		this._coverText = c;
		return c;
	};

	Stage.prototype._drawCover = function ( ctx, now, alpha ) {
		if ( ! this._coverBase ) {
			this._buildCover();
		}
		var W = this.W;
		var H = this.H;
		var U = Math.min( W, H );
		var t = now / 1000;
		var i;

		ctx.save();
		ctx.globalAlpha = alpha;
		ctx.drawImage( this._coverBase, 0, 0 );

		ctx.globalCompositeOperation = 'screen';
		for ( i = 0; i < this._coverOrbs.length; i++ ) {
			var orb = this._coverOrbs[ i ];
			var d = Math.max( W, H ) * orb.size;
			var ox = ( orb.ax + Math.sin( t * 0.23 + orb.phase ) * orb.rx ) * W;
			var oy = ( orb.ay + Math.cos( t * 0.19 + orb.phase ) * orb.ry ) * H;
			ctx.drawImage( orb.sprite, ox - d / 2, oy - d / 2, d, d );
		}
		ctx.globalCompositeOperation = 'source-over';

		for ( i = 0; i < this._coverSparks.length; i++ ) {
			var s = this._coverSparks[ i ];
			var sy = ( ( s.y - t * s.drift ) % 1 + 1 ) % 1;
			ctx.globalAlpha = alpha * ( 0.15 + 0.5 * ( 0.5 + 0.5 * Math.sin( t * s.rate * 2 + s.phase ) ) );
			ctx.fillStyle = '#f2d5cb';
			ctx.beginPath();
			ctx.arc( s.x * W, sy * H, s.r * U / 720, 0, Math.PI * 2 );
			ctx.fill();
		}

		var cx = W / 2;
		var cy = H * 0.46;
		var ring = U * 0.235;

		// Two thin arcs turning slowly round the mark: alive, without asking to be looked at.
		ctx.lineCap = 'round';
		ctx.lineWidth = Math.max( 1.5, U * 0.0028 );
		ctx.globalAlpha = alpha * 0.8;
		ctx.strokeStyle = '#d9b3a6';
		ctx.beginPath();
		ctx.arc( cx, cy, ring, t * 0.5, t * 0.5 + Math.PI * 1.25 );
		ctx.stroke();
		ctx.globalAlpha = alpha * 0.4;
		ctx.strokeStyle = '#f7f2ed';
		ctx.beginPath();
		ctx.arc( cx, cy, ring * 1.1, -t * 0.34, -t * 0.34 + Math.PI * 0.8 );
		ctx.stroke();

		ctx.globalAlpha = alpha;
		var breath = 1 + Math.sin( t * 1.2 ) * 0.016;
		var logo = this.coverLogo && this.images[ this.coverLogo ] && this.images[ this.coverLogo ].ok ? this.images[ this.coverLogo ].img : null;
		if ( logo ) {
			var lw = U * 0.3 * breath;
			var lh = lw * ( logo.naturalHeight / ( logo.naturalWidth || 1 ) );
			ctx.drawImage( logo, cx - lw / 2, cy - lh / 2, lw, lh );
		} else if ( this.brand ) {
			ctx.font = '500 ' + Math.round( U * 0.075 * breath ) + 'px ' + SERIF;
			ctx.fillStyle = '#f7f2ed';
			ctx.textAlign = 'center';
			ctx.textBaseline = 'middle';
			ctx.fillText( this.brand, cx, cy, ring * 1.7 );
		}

		if ( this.coverLine ) {
			var line = this._coverLineSprite();
			ctx.globalAlpha = alpha * ( 0.82 + 0.18 * Math.sin( t * 1.2 ) );
			ctx.drawImage( line, cx - line.width / 2, cy + ring * 1.28 );
		}
		ctx.restore();
	};

	/* ─────────────────────────── a frame ─────────────────────────── */

	Stage.prototype.render = function ( now ) {
		var ctx = this.ctx;
		var dt = this._last ? Math.min( 0.1, ( now - this._last ) / 1000 ) : 1 / FPS;
		this._last = now;

		// Nothing of the camera is drawn at all while covered, not even underneath.
		if ( this.cover ) {
			this._drawCover( ctx, now, 1 );
			return;
		}

		this._drawCamera( ctx );

		if ( ! this.hideGraphics ) {
			this._drawGraphics( ctx, now, dt );
		}

		// The last frame of what was on before a switch, fading away over the new one.
		if ( this._snapMs ) {
			var left = 1 - ( now - this._snapFrom ) / this._snapMs;
			if ( left > 0 ) {
				ctx.save();
				ctx.globalAlpha = left;
				ctx.drawImage( this._snap, 0, 0 );
				ctx.restore();
			} else {
				this._snapMs = 0;
			}
		}

		if ( this._coverOff ) {
			var fade = 1 - ( now - this._coverOff ) / 450;
			if ( fade > 0 ) {
				this._drawCover( ctx, now, fade );
			} else {
				this._coverOff = 0;
			}
		}
	};

	Stage.prototype._drawGraphics = function ( ctx, now, dt ) {
		this._drawFrame( ctx );

		var drop = false;
		for ( var i = 0; i < this.layers.length; i++ ) {
			var layer = this.layers[ i ];
			if ( layer._gone && now - layer._gone > 280 ) {
				drop = true;
				continue;
			}
			if ( false !== layer.visible ) {
				this._drawLayer( ctx, layer, now );
			} else {
				layer._box = null;
			}
		}
		if ( drop ) {
			this.layers = this.layers.filter( function ( l ) {
				return ! ( l._gone && now - l._gone > 280 );
			} );
		}

		this._runRain( dt );
		this._drawParticles( ctx, dt );
	};

	root.WHDStage = {
		Stage: Stage,
		LOOKS: LOOKS,
		FRAMES: FRAMES,
		EFFECTS: EFFECTS,
		ANIMS: ANIMS
	};
}( window ) );
