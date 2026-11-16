/**
 * The Video Editor's engine: the project, the renderer, the preview player, the exporter and the uploader.
 *
 * One renderer draws every frame — for the preview, from <video> elements playing in the page, and for the
 * export, from frames decoded exactly by Mediabunny — so what is saved is what was seen. Everything that
 * moves (text coming in, a sticker wobbling, confetti) is a pure function of the time, seeded where it is
 * random, so the same moment always draws the same way.
 *
 * The export encodes with the browser's own WebCodecs encoder through Mediabunny (admin/vendor, MPL-2.0,
 * loaded only when it is needed): H.264 + AAC in MP4 where the browser can, otherwise VP9/Opus in WebM.
 * Sound is mixed offline — each clip's own sound and every music and effect track, with their volumes,
 * fades and loops — and encoded with the picture. A GIF goes through gifenc (MIT) instead.
 *
 * Files go to the media library in pieces (WHD_Video_Editor's upload/* routes), so a host's upload limit
 * never meets a long 1080p video.
 *
 *   WHDVideo.newProject( { w, h } )                    → project
 *   WHDVideo.timeline( project )                       → { items, duration }
 *   new WHDVideo.Renderer( canvas )                    → .frame( project, tl, t, source( clip, time ) )
 *   new WHDVideo.Player( { canvas, project, onTime } ) → .play() .pause() .seek( t ) .refresh() .destroy()
 *   WHDVideo.exportVideo( project, { onProgress } )    → Promise<{ blob, mime, ext, poster }>
 *   WHDVideo.exportGif( project, { width, fps } )      → Promise<{ blob, mime, ext, poster }>
 *   WHDVideo.upload( blob, meta, onProgress )          → Promise<clip>
 */
( function () {
	'use strict';

	var D = window.WHD_VE || {};
	var P = window.WHDVideoPresets || {};

	/* ─────────────────────────── small things ─────────────────────────── */

	function clamp( v, a, b ) {
		return v < a ? a : ( v > b ? b : v );
	}
	function mod( a, n ) {
		return ( ( a % n ) + n ) % n;
	}
	function uid() {
		return Math.random().toString( 36 ).slice( 2, 10 );
	}
	function find( list, key ) {
		for ( var i = 0; i < ( list || [] ).length; i++ ) {
			if ( list[ i ].key === key ) {
				return list[ i ];
			}
		}
		return null;
	}
	/* A seeded random number generator: the same seed, the same sequence, preview and export alike. */
	function rng( seed ) {
		var a = ( seed >>> 0 ) || 1;
		return function () {
			a = ( a + 0x6D2B79F5 ) | 0;
			var t = Math.imul( a ^ ( a >>> 15 ), 1 | a );
			t = ( t + Math.imul( t ^ ( t >>> 7 ), 61 | t ) ) ^ t;
			return ( ( t ^ ( t >>> 14 ) ) >>> 0 ) / 4294967296;
		};
	}
	function hash( s ) {
		var h = 2166136261;
		s = String( s );
		for ( var i = 0; i < s.length; i++ ) {
			h ^= s.charCodeAt( i );
			h = Math.imul( h, 16777619 );
		}
		return h >>> 0;
	}

	var ease = {
		out: function ( p ) {
			return 1 - Math.pow( 1 - p, 3 );
		},
		in: function ( p ) {
			return p * p * p;
		},
		inOut: function ( p ) {
			return p < 0.5 ? 4 * p * p * p : 1 - Math.pow( -2 * p + 2, 3 ) / 2;
		},
		back: function ( p ) {
			var c1 = 1.70158, c3 = c1 + 1;
			return 1 + c3 * Math.pow( p - 1, 3 ) + c1 * Math.pow( p - 1, 2 );
		},
		bounce: function ( p ) {
			var n1 = 7.5625, d1 = 2.75;
			if ( p < 1 / d1 ) {
				return n1 * p * p;
			} else if ( p < 2 / d1 ) {
				p -= 1.5 / d1;
				return n1 * p * p + 0.75;
			} else if ( p < 2.5 / d1 ) {
				p -= 2.25 / d1;
				return n1 * p * p + 0.9375;
			}
			p -= 2.625 / d1;
			return n1 * p * p + 0.984375;
		}
	};

	/* ─────────────────────────── the libraries, when needed ─────────────────────────── */

	var mbPromise = null;
	var gifPromise = null;
	function loadMediabunny() {
		if ( ! mbPromise ) {
			mbPromise = import( D.vendor.mediabunny ).catch( function ( e ) {
				mbPromise = null;
				throw e;
			} );
		}
		return mbPromise;
	}
	function loadGifenc() {
		if ( ! gifPromise ) {
			gifPromise = import( D.vendor.gifenc ).catch( function ( e ) {
				gifPromise = null;
				throw e;
			} );
		}
		return gifPromise;
	}
	/* WebCodecs: only in a secure context (https, or localhost), which a live admin always is. */
	function canEncode() {
		return typeof window.VideoEncoder === 'function' && typeof window.AudioEncoder === 'function' && typeof window.VideoDecoder === 'function';
	}

	/* ─────────────────────────── the project ─────────────────────────── */

	function newProject( opts ) {
		opts = opts || {};
		return normalize( {
			v: 1,
			name: opts.name || '',
			w: opts.w || 1080,
			h: opts.h || 1920,
			fps: opts.fps || 30,
			bg: '#000000',
			clips: [],
			layers: [],
			audio: []
		} );
	}

	function clipDefaults( c ) {
		var image = 0 === String( c.mime || '' ).indexOf( 'image/' );
		var len = Math.max( 0.1, parseFloat( c.seconds ) || ( image ? 3 : 5 ) );
		return Object.assign( {
			id: uid(),
			att: 0,
			url: '',
			mime: 'video/mp4',
			title: '',
			poster: '',
			seconds: len,
			kind: image ? 'image' : 'video',
			in: 0,
			out: image ? len : len,
			dur: image ? 3 : 0,
			speed: 1,
			volume: 1,
			muted: false,
			reverse: false,
			fit: 'cover',
			zoom: 1,
			px: 0.5,
			py: 0.5,
			motion: image ? 'in' : 'none',
			filter: 'none',
			adjust: { brightness: 0, contrast: 0, saturation: 0, warmth: 0 },
			fadeIn: 0,
			fadeOut: 0,
			trans: { type: 'none', dur: 0.6 }
		}, c );
	}

	/* Fill in what an older or a hand-made project left out, so the renderer never meets an undefined. */
	function normalize( p ) {
		p.w = Math.round( clamp( parseInt( p.w, 10 ) || 1080, 64, 4096 ) / 2 ) * 2;
		p.h = Math.round( clamp( parseInt( p.h, 10 ) || 1920, 64, 4096 ) / 2 ) * 2;
		p.fps = [ 24, 25, 30, 60 ].indexOf( parseInt( p.fps, 10 ) ) !== -1 ? parseInt( p.fps, 10 ) : 30;
		p.bg = p.bg || '#000000';
		p.clips = ( p.clips || [] ).map( function ( c ) {
			c = clipDefaults( c );
			c.adjust = Object.assign( { brightness: 0, contrast: 0, saturation: 0, warmth: 0 }, c.adjust || {} );
			c.trans = Object.assign( { type: 'none', dur: 0.6 }, c.trans || {} );
			if ( 'video' === c.kind ) {
				c.out = clamp( c.out || c.seconds, 0.05, Math.max( 0.05, c.seconds ) );
				c.in = clamp( c.in || 0, 0, c.out - 0.05 );
			}
			return c;
		} );
		p.layers = ( p.layers || [] ).map( function ( l ) {
			l = Object.assign( { id: uid(), start: 0, end: 3, x: 0.5, y: 0.5, rot: 0, opacity: 1, scale: 1 }, l );
			l.anim = Object.assign( { in: 'fade', out: 'fade', loop: 'none', dur: 0.5 }, l.anim || {} );
			if ( 'text' === l.type ) {
				l = Object.assign( { text: 'Text', font: 'Montserrat', weight: 700, italic: false, size: 0.06, color: '#ffffff', color2: '#000000', style: 'shadow', align: 'center', spacing: 0, upper: false, lh: 1.15 }, l );
			} else if ( 'sticker' === l.type ) {
				l = Object.assign( { src: '', emoji: '', size: 0.22, flip: false }, l );
			} else if ( 'effect' === l.type ) {
				l = Object.assign( { effect: 'sparkles', intensity: 0.6, color: '', seed: hash( l.id ) % 100000 }, l );
			}
			return l;
		} );
		p.audio = ( p.audio || [] ).map( function ( a ) {
			return Object.assign( { id: uid(), src: '', att: 0, name: '', kind: 'music', start: 0, offset: 0, dur: 5, fileDur: 0, volume: 0.8, fadeIn: 0, fadeOut: 0, loop: false }, a );
		} );
		return p;
	}

	function clipDur( c ) {
		if ( 'image' === c.kind ) {
			return Math.max( 0.2, c.dur || 3 );
		}
		return Math.max( 0.05, ( c.out - c.in ) / Math.max( 0.1, c.speed || 1 ) );
	}

	function overlaps( type ) {
		var t = find( P.transitions, type );
		return !! ( t && t.overlap );
	}

	/* Where each clip sits on the timeline, with the overlap of the transitions that need one. */
	function timeline( p ) {
		var items = [];
		var t = 0;
		( p.clips || [] ).forEach( function ( c, i ) {
			var dur = clipDur( c );
			var prev = items[ i - 1 ];
			var overlap = 0;
			if ( prev && prev.clip.trans && overlaps( prev.clip.trans.type ) ) {
				overlap = Math.min( prev.clip.trans.dur || 0.6, prev.dur * 0.45, dur * 0.45 );
			}
			var start = Math.max( 0, t - overlap );
			items.push( { clip: c, index: i, start: start, end: start + dur, dur: dur, overlapIn: overlap } );
			t = start + dur;
		} );
		var duration = t;
		if ( ! items.length ) {
			( p.layers || [] ).forEach( function ( l ) {
				duration = Math.max( duration, l.end );
			} );
			duration = Math.max( duration, 3 );
		}
		return { items: items, duration: Math.max( duration, 0.1 ) };
	}

	/* The moment of the source file a clip shows at timeline time t. */
	function sourceTime( item, t ) {
		var c = item.clip;
		var local = clamp( t - item.start, 0, item.dur );
		if ( 'image' === c.kind ) {
			return local;
		}
		var st = c.reverse ? c.out - local * c.speed : c.in + local * c.speed;
		return clamp( st, c.in, Math.max( c.in, c.out - 0.001 ) );
	}

	function visible( tl, t ) {
		var list = [];
		for ( var i = 0; i < tl.items.length; i++ ) {
			var it = tl.items[ i ];
			if ( t >= it.start && t < it.end ) {
				list.push( it );
			}
		}
		if ( ! list.length && tl.items.length && t >= tl.duration - 1e-6 ) {
			list.push( tl.items[ tl.items.length - 1 ] );
		}
		return list.slice( -2 );
	}

	/* ─────────────────────────── pictures and fonts ─────────────────────────── */

	var images = {};
	function image( url ) {
		if ( ! url ) {
			return null;
		}
		var img = images[ url ];
		if ( ! img ) {
			img = new Image();
			img.decoding = 'async';
			img.src = url;
			images[ url ] = img;
		}
		return img.complete && img.naturalWidth ? img : null;
	}
	function loadImage( url ) {
		return new Promise( function ( resolve ) {
			if ( ! url ) {
				resolve( null );
				return;
			}
			var img = images[ url ] || new Image();
			images[ url ] = img;
			if ( img.complete && img.naturalWidth ) {
				resolve( img );
				return;
			}
			img.onload = function () {
				resolve( img );
			};
			img.onerror = function () {
				resolve( null );
			};
			if ( ! img.src ) {
				img.src = url;
			}
		} );
	}
	function fontString( l, px ) {
		return ( l.italic ? 'italic ' : '' ) + ( l.weight || 400 ) + ' ' + Math.max( 1, px ).toFixed( 1 ) + 'px "' + ( l.font || 'Montserrat' ) + '", sans-serif';
	}
	/* Every font and picture the project uses, ready before frames that need them are encoded. */
	function prepare( p ) {
		var waits = [];
		( p.layers || [] ).forEach( function ( l ) {
			if ( 'text' === l.type && document.fonts && document.fonts.load ) {
				waits.push( document.fonts.load( fontString( l, 64 ), l.text || 'A' ).catch( function () {} ) );
			}
			if ( 'sticker' === l.type && l.src ) {
				waits.push( loadImage( l.src ) );
			}
		} );
		( p.clips || [] ).forEach( function ( c ) {
			if ( 'image' === c.kind ) {
				waits.push( loadImage( c.url ) );
			}
		} );
		return Promise.all( waits );
	}

	/* ─────────────────────────── movement ─────────────────────────── */

	/* How a text or sticker stands at `local` seconds into its `dur`: in, out and its loop together. */
	function motion( a, local, dur ) {
		var s = { alpha: 1, dx: 0, dy: 0, scale: 1, sx: 1, sy: 1, rot: 0, blur: 0, reveal: 1, wipe: 1, rise: -1, glow: 0 };
		a = a || {};
		var d = Math.max( 0.05, Math.min( a.dur || 0.5, dur / 2 ) );
		if ( local < d && a.in && 'none' !== a.in ) {
			inMove( a.in, clamp( local / d, 0, 1 ), s );
		}
		if ( dur - local < d && a.out && 'none' !== a.out ) {
			outMove( a.out, clamp( ( dur - local ) / d, 0, 1 ), s );
		}
		if ( a.loop && 'none' !== a.loop ) {
			loopMove( a.loop, local, s );
		}
		return s;
	}
	function flick( p ) {
		var steps = [ 0, 1, 0.2, 1, 0, 0.6, 1, 0.3, 1, 1 ];
		return p >= 1 ? 1 : steps[ Math.floor( p * steps.length ) ];
	}
	function inMove( k, p, s ) {
		var e = ease.out( p );
		switch ( k ) {
			case 'fade': s.alpha *= p; break;
			case 'rise': s.rise = p; break;
			case 'typewriter': s.reveal = p; break;
			case 'pop': s.scale *= Math.max( 0.01, ease.back( p ) ); s.alpha *= Math.min( 1, p * 3 ); break;
			case 'zoom': s.scale *= 1 + ( 1 - e ) * 0.8; s.alpha *= p; break;
			case 'bounce': s.dy -= ( 1 - ease.bounce( p ) ) * 0.35; s.alpha *= Math.min( 1, p * 4 ); break;
			case 'slideUp': s.dy += ( 1 - e ) * 0.08; s.alpha *= p; break;
			case 'slideDown': s.dy -= ( 1 - e ) * 0.08; s.alpha *= p; break;
			case 'slideLeft': s.dx += ( 1 - e ) * 0.3; s.alpha *= p; break;
			case 'slideRight': s.dx -= ( 1 - e ) * 0.3; s.alpha *= p; break;
			case 'blur': s.blur = ( 1 - e ) * 22; s.alpha *= p; break;
			case 'wipe': s.wipe = e; break;
			case 'spin': s.rot -= ( 1 - ease.back( p ) ) * 200; s.scale *= 0.3 + 0.7 * Math.max( 0, ease.back( p ) ); s.alpha *= Math.min( 1, p * 2 ); break;
			case 'flicker': s.alpha *= flick( p ); break;
		}
	}
	function outMove( k, p, s ) {
		var e = ease.out( p );
		switch ( k ) {
			case 'fade': s.alpha *= p; break;
			case 'pop': s.scale *= Math.max( 0.01, e ); s.alpha *= Math.min( 1, p * 3 ); break;
			case 'zoom': s.scale *= 1 + ( 1 - e ) * 0.6; s.alpha *= p; break;
			case 'slideUp': s.dy -= ( 1 - e ) * 0.08; s.alpha *= p; break;
			case 'slideDown': s.dy += ( 1 - e ) * 0.08; s.alpha *= p; break;
			case 'slideLeft': s.dx -= ( 1 - e ) * 0.3; s.alpha *= p; break;
			case 'slideRight': s.dx += ( 1 - e ) * 0.3; s.alpha *= p; break;
			case 'blur': s.blur = Math.max( s.blur, ( 1 - e ) * 22 ); s.alpha *= p; break;
			case 'wipe': s.wipe = Math.min( s.wipe, e ); break;
			case 'flicker': s.alpha *= flick( p ); break;
		}
	}
	function bump( x, at ) {
		var d = ( x - at ) / 0.08;
		return Math.exp( -d * d );
	}
	function loopMove( k, t, s ) {
		var TAU = Math.PI * 2;
		switch ( k ) {
			case 'pulse': s.scale *= 1 + 0.06 * Math.sin( TAU * t * 1.2 ); break;
			case 'heartbeat': var b = t % 1.2; s.scale *= 1 + 0.1 * ( bump( b, 0.1 ) + bump( b, 0.32 ) ); break;
			case 'float': s.dy += 0.012 * Math.sin( TAU * t * 0.5 ); break;
			case 'wobble': s.rot += 5 * Math.sin( TAU * t * 1.1 ); break;
			case 'swing': s.rot += 10 * Math.sin( TAU * t * 0.8 ); break;
			case 'shake': s.dx += 0.004 * Math.sin( t * 53 ); s.dy += 0.004 * Math.sin( t * 61 ); s.rot += 1.5 * Math.sin( t * 47 ); break;
			case 'jelly': var j = Math.sin( TAU * t * 1.6 ); s.sx *= 1 + 0.08 * j; s.sy *= 1 - 0.08 * j; break;
			case 'hop': s.dy -= 0.03 * Math.abs( Math.sin( Math.PI * t * 1.5 ) ); break;
			case 'spin': s.rot += t * 120; break;
			case 'glow': s.glow = 0.5 + 0.5 * Math.sin( TAU * t * 1.2 ); break;
		}
	}

	/* ─────────────────────────── the renderer ─────────────────────────── */

	var noiseTiles = null;
	function noise() {
		if ( noiseTiles ) {
			return noiseTiles;
		}
		noiseTiles = [];
		for ( var n = 0; n < 4; n++ ) {
			var c = document.createElement( 'canvas' );
			c.width = c.height = 256;
			var g = c.getContext( '2d' );
			var d = g.createImageData( 256, 256 );
			var r = rng( 99 + n );
			for ( var i = 0; i < d.data.length; i += 4 ) {
				var v = ( r() * 255 ) | 0;
				d.data[ i ] = d.data[ i + 1 ] = d.data[ i + 2 ] = v;
				d.data[ i + 3 ] = 255;
			}
			g.putImageData( d, 0, 0 );
			noiseTiles.push( c );
		}
		return noiseTiles;
	}

	function Renderer( canvas ) {
		this.canvas = canvas;
		this.ctx = canvas.getContext( '2d' );
		this.boxes = {};
	}

	/*
	 * One frame of the project at time t onto the canvas, whatever its size: every position and size is a
	 * share of the frame, so the small preview and the full-size export draw the same picture.
	 *
	 * source( clip, time ) gives what to draw for a clip — a <video>, an <img> or a decoded canvas — or null.
	 */
	Renderer.prototype.frame = function ( p, tl, t, source ) {
		var ctx = this.ctx;
		var W = this.canvas.width;
		var H = this.canvas.height;
		ctx.setTransform( 1, 0, 0, 1, 0, 0 );
		ctx.globalAlpha = 1;
		ctx.globalCompositeOperation = 'source-over';
		ctx.filter = 'none';
		ctx.fillStyle = p.bg || '#000';
		ctx.fillRect( 0, 0, W, H );

		var cam = this.camera( p, t, W, H );
		var vis = visible( tl, t );
		var cover = 0;
		var coverColour = '#000';
		for ( var i = 0; i < vis.length; i++ ) {
			var it = vis[ i ];
			var look = { alpha: 1, dx: 0, dy: 0, scale: 1, wipe: -1 };
			var next = vis[ i + 1 ];
			var prev = vis[ i - 1 ];
			// Two clips at once: the second is coming in over the first.
			if ( next && next.overlapIn > 0 ) {
				var pa = clamp( ( t - next.start ) / next.overlapIn, 0, 1 );
				var ta = it.clip.trans.type;
				if ( 'slideleft' === ta ) {
					look.dx = -pa * W;
				} else if ( 'slideup' === ta ) {
					look.dy = -pa * H;
				} else if ( 'zoom' === ta ) {
					look.scale = 1 + pa * 0.5;
					look.alpha = 1 - pa;
				}
			}
			if ( prev && it.overlapIn > 0 ) {
				var pb = clamp( ( t - it.start ) / it.overlapIn, 0, 1 );
				var tb = prev.clip.trans.type;
				if ( 'crossfade' === tb ) {
					look.alpha = pb;
				} else if ( 'slideleft' === tb ) {
					look.dx = ( 1 - pb ) * W;
				} else if ( 'slideup' === tb ) {
					look.dy = ( 1 - pb ) * H;
				} else if ( 'zoom' === tb ) {
					look.scale = 0.85 + 0.15 * pb;
					look.alpha = pb;
				} else if ( 'wipe' === tb ) {
					look.wipe = ease.inOut( pb );
				}
			}
			var src = source( it.clip, sourceTime( it, t ) );
			if ( src ) {
				this.clip( it, src, t, look, cam, W, H );
			}
			// Fades through a colour, and the clip's own fade in and out of black.
			var c = it.clip;
			var local = t - it.start;
			var after = tl.items[ it.index + 1 ];
			var before = tl.items[ it.index - 1 ];
			if ( after && ( 'fadeblack' === c.trans.type || 'fadewhite' === c.trans.type ) ) {
				var half = Math.min( c.trans.dur, it.dur ) / 2;
				if ( local > it.dur - half ) {
					cover = Math.max( cover, ( local - ( it.dur - half ) ) / half );
					coverColour = 'fadewhite' === c.trans.type ? '#fff' : '#000';
				}
			}
			if ( before && ( 'fadeblack' === before.clip.trans.type || 'fadewhite' === before.clip.trans.type ) ) {
				var half2 = Math.min( before.clip.trans.dur, it.dur ) / 2;
				if ( local < half2 ) {
					cover = Math.max( cover, 1 - local / half2 );
					coverColour = 'fadewhite' === before.clip.trans.type ? '#fff' : '#000';
				}
			}
			if ( c.fadeIn > 0 && local < c.fadeIn ) {
				cover = Math.max( cover, 1 - local / c.fadeIn );
				coverColour = '#000';
			}
			if ( c.fadeOut > 0 && local > it.dur - c.fadeOut ) {
				cover = Math.max( cover, ( local - ( it.dur - c.fadeOut ) ) / c.fadeOut );
				coverColour = '#000';
			}
		}
		if ( cam.glitch ) {
			this.glitch( t, W, H, cam.glitch );
		}
		if ( cover > 0 ) {
			ctx.globalAlpha = clamp( cover, 0, 1 );
			ctx.fillStyle = coverColour;
			ctx.fillRect( 0, 0, W, H );
			ctx.globalAlpha = 1;
		}

		this.boxes = {};
		var layers = p.layers || [];
		for ( var k = 0; k < layers.length; k++ ) {
			var l = layers[ k ];
			if ( l.hidden || t < l.start || t >= l.end ) {
				continue;
			}
			ctx.save();
			if ( 'text' === l.type ) {
				this.text( l, t, W, H );
			} else if ( 'sticker' === l.type ) {
				this.sticker( l, t, W, H );
			} else if ( 'effect' === l.type ) {
				this.effect( l, t, W, H );
			}
			ctx.restore();
		}
	};

	/* What the "camera" effects do to the picture at t: zoom, shake, blur, colour, glitch. */
	Renderer.prototype.camera = function ( p, t, W, H ) {
		var cam = { scale: 1, dx: 0, dy: 0, filter: '', glitch: 0 };
		( p.layers || [] ).forEach( function ( l ) {
			if ( 'effect' !== l.type || l.hidden || t < l.start || t >= l.end ) {
				return;
			}
			var local = t - l.start;
			var len = l.end - l.start;
			var k = l.intensity;
			switch ( l.effect ) {
				case 'zoompunch':
					var x = local % 1;
					cam.scale *= 1 + 0.12 * k * Math.exp( -x * 7 ) * Math.min( 1, x * 30 );
					break;
				case 'kenburns':
					cam.scale *= 1 + 0.18 * k * clamp( local / len, 0, 1 );
					break;
				case 'shake':
					cam.dx += Math.sin( t * 47 ) * 0.008 * W * k + Math.sin( t * 13 ) * 0.004 * W * k;
					cam.dy += Math.sin( t * 59 ) * 0.008 * H * k * 0.6;
					cam.scale *= 1 + 0.03 * k;
					break;
				case 'blurin':
					var b = clamp( 1 - local / Math.min( 1.2, len ), 0, 1 );
					if ( b > 0 ) {
						cam.filter += ' blur(' + ( b * 24 * k * W / 1080 ).toFixed( 1 ) + 'px)';
					}
					break;
				case 'rgbpulse':
					cam.filter += ' saturate(' + ( 1 + 0.6 * k * ( 0.5 + 0.5 * Math.sin( local * 6 ) ) ).toFixed( 2 ) + ')';
					break;
				case 'glitch':
					var slot = Math.floor( t * 12 );
					if ( rng( l.seed + slot )() < 0.35 * k + 0.1 ) {
						cam.glitch = Math.max( cam.glitch, k );
					}
					break;
			}
		} );
		return cam;
	};

	function filterCss( c, W ) {
		var f = find( P.filters, c.filter );
		var a = c.adjust || {};
		var css = f && f.css ? f.css : '';
		if ( a.brightness ) {
			css += ' brightness(' + ( 1 + a.brightness / 200 ).toFixed( 3 ) + ')';
		}
		if ( a.contrast ) {
			css += ' contrast(' + ( 1 + a.contrast / 150 ).toFixed( 3 ) + ')';
		}
		if ( a.saturation ) {
			css += ' saturate(' + Math.max( 0, 1 + a.saturation / 100 ).toFixed( 3 ) + ')';
		}
		return css.trim();
	}

	/* One clip's frame, fitted to the canvas, with its zoom, drift, filter and the transition's look. */
	Renderer.prototype.clip = function ( it, src, t, look, cam, W, H ) {
		var ctx = this.ctx;
		var c = it.clip;
		var sw = src.videoWidth || src.naturalWidth || src.width;
		var sh = src.videoHeight || src.naturalHeight || src.height;
		if ( ! sw || ! sh ) {
			return;
		}
		var k = clamp( ( t - it.start ) / Math.max( 0.01, it.dur ), 0, 1 );
		var zoom = Math.max( 0.2, c.zoom || 1 );
		var px = c.px, py = c.py;
		switch ( c.motion ) {
			case 'in': zoom *= 1 + 0.15 * k; break;
			case 'out': zoom *= 1.15 - 0.15 * k; break;
			case 'left': zoom *= 1.15; px = clamp( 0.7 - 0.4 * k, 0, 1 ); break;
			case 'right': zoom *= 1.15; px = clamp( 0.3 + 0.4 * k, 0, 1 ); break;
		}
		var css = filterCss( c, W );
		var cover = Math.max( W / sw, H / sh );
		var contain = Math.min( W / sw, H / sh );

		ctx.save();
		ctx.globalAlpha = clamp( look.alpha, 0, 1 );
		if ( look.wipe >= 0 ) {
			ctx.beginPath();
			ctx.rect( 0, 0, W * look.wipe, H );
			ctx.clip();
		}
		ctx.translate( W / 2 + look.dx + cam.dx, H / 2 + look.dy + cam.dy );
		ctx.scale( look.scale * cam.scale, look.scale * cam.scale );
		ctx.translate( -W / 2, -H / 2 );

		// Shown whole on a blurred copy of itself, for a landscape clip in a vertical frame and the like.
		if ( 'blur' === c.fit ) {
			var bw = sw * cover * 1.08, bh = sh * cover * 1.08;
			ctx.filter = 'blur(' + Math.round( 28 * W / 1080 ) + 'px) brightness(0.72) ' + css;
			ctx.drawImage( src, ( W - bw ) / 2, ( H - bh ) / 2, bw, bh );
		}
		var scale = ( 'cover' === c.fit ? cover : contain ) * zoom;
		var dw = sw * scale, dh = sh * scale;
		var dx = ( W - dw ) * px, dy = ( H - dh ) * py;
		ctx.filter = ( css + ' ' + cam.filter ).trim() || 'none';
		ctx.drawImage( src, dx, dy, dw, dh );
		ctx.filter = 'none';

		var f = find( P.filters, c.filter );
		var tints = [];
		if ( f && f.tint ) {
			tints.push( f.tint );
		}
		var warm = ( c.adjust && c.adjust.warmth ) || 0;
		if ( warm ) {
			tints.push( warm > 0 ? 'rgba(255,140,40,' + ( warm / 100 * 0.35 ).toFixed( 3 ) + ')' : 'rgba(60,130,255,' + ( -warm / 100 * 0.35 ).toFixed( 3 ) + ')' );
		}
		if ( tints.length ) {
			ctx.globalCompositeOperation = 'soft-light';
			tints.forEach( function ( tint ) {
				ctx.fillStyle = tint;
				ctx.fillRect( Math.max( 0, dx ), Math.max( 0, dy ), Math.min( W, dw ), Math.min( H, dh ) );
			} );
		}
		ctx.restore();
	};

	/* A glitch: bands of the picture slid sideways and its colours split, on random frames. */
	Renderer.prototype.glitch = function ( t, W, H, k ) {
		var ctx = this.ctx;
		var r = rng( Math.floor( t * 24 ) * 7919 );
		var bands = 3 + Math.floor( r() * 5 );
		for ( var i = 0; i < bands; i++ ) {
			var y = r() * H, h = ( 0.01 + r() * 0.06 ) * H, shift = ( r() - 0.5 ) * 0.12 * W * k;
			ctx.drawImage( this.canvas, 0, y, W, h, shift, y, W, h );
		}
		ctx.save();
		ctx.globalAlpha = 0.22 * k;
		ctx.globalCompositeOperation = 'lighter';
		ctx.drawImage( this.canvas, 0.012 * W * k, 0 );
		ctx.restore();
	};

	function measure( ctx, line, spacing ) {
		return ctx.measureText( line ).width + Math.max( 0, line.length - 1 ) * spacing;
	}
	function setSpacing( ctx, px ) {
		if ( 'letterSpacing' in ctx ) {
			ctx.letterSpacing = px + 'px';
			return 0;
		}
		return px;
	}
	function rounded( ctx, x, y, w, h, r ) {
		r = Math.min( r, w / 2, h / 2 );
		ctx.beginPath();
		ctx.moveTo( x + r, y );
		ctx.arcTo( x + w, y, x + w, y + h, r );
		ctx.arcTo( x + w, y + h, x, y + h, r );
		ctx.arcTo( x, y + h, x, y, r );
		ctx.arcTo( x, y, x + w, y, r );
		ctx.closePath();
	}

	/* A text layer: its style, its movement, and where it sits, for clicking on it in the preview. */
	Renderer.prototype.text = function ( l, t, W, H ) {
		var ctx = this.ctx;
		var local = t - l.start;
		var dur = l.end - l.start;
		var m = motion( l.anim, local, dur );
		var px = l.size * H;
		var raw = String( l.text == null ? '' : l.text ).replace( '{price}', D.currency || '$' );
		var lines = ( l.upper ? raw.toUpperCase() : raw ).split( '\n' );
		ctx.font = fontString( l, px );
		ctx.textBaseline = 'middle';
		ctx.textAlign = 'left';
		var unit = H / 1080;
		var manual = setSpacing( ctx, ( l.spacing || 0 ) * unit );
		var widths = lines.map( function ( line ) {
			return measure( ctx, line, manual );
		} );
		var bw = Math.max.apply( null, widths.concat( [ 1 ] ) );
		var lh = px * ( l.lh || 1.15 );
		var bh = lh * lines.length;
		var style = l.style || 'plain';
		var padX = ( 'pill' === style ? 0.6 : ( 'box' === style || 'glass' === style ? 0.4 : 0.1 ) ) * px;
		var padY = ( 'pill' === style || 'box' === style || 'glass' === style ? 0.22 : 0.05 ) * px;

		var cx = l.x * W, cy = l.y * H;
		this.boxes[ l.id ] = { cx: cx, cy: cy, w: ( bw + padX * 2 ) * l.scale, h: ( bh + padY * 2 ) * l.scale, rot: l.rot || 0 };

		ctx.globalAlpha = clamp( m.alpha * ( l.opacity == null ? 1 : l.opacity ), 0, 1 );
		ctx.translate( cx + m.dx * W, cy + m.dy * H );
		ctx.rotate( ( ( l.rot || 0 ) + m.rot ) * Math.PI / 180 );
		ctx.scale( l.scale * m.scale * m.sx, l.scale * m.scale * m.sy );
		if ( m.blur > 0.3 ) {
			ctx.filter = 'blur(' + ( m.blur * unit ).toFixed( 1 ) + 'px)';
		}
		if ( m.wipe < 1 ) {
			ctx.beginPath();
			ctx.rect( -bw / 2 - padX, -bh / 2 - padY - px, ( bw + padX * 2 ) * Math.max( 0, m.wipe ), bh + padY * 2 + px * 2 );
			ctx.clip();
		}

		var c2 = l.color2 || '#000';
		// What sits behind the letters.
		if ( 'box' === style || 'pill' === style || 'glass' === style ) {
			ctx.save();
			if ( 'glass' === style ) {
				ctx.shadowColor = 'rgba(0,0,0,0.25)';
				ctx.shadowBlur = px * 0.5;
			}
			ctx.fillStyle = c2;
			rounded( ctx, -bw / 2 - padX, -bh / 2 - padY, bw + padX * 2, bh + padY * 2, 'pill' === style ? 9999 : px * 0.16 );
			ctx.fill();
			if ( 'glass' === style ) {
				ctx.shadowBlur = 0;
				ctx.strokeStyle = 'rgba(255,255,255,0.55)';
				ctx.lineWidth = Math.max( 1, px * 0.03 );
				ctx.stroke();
			}
			ctx.restore();
		}
		var self = this;
		var total = 0;
		lines.forEach( function ( line ) {
			total += line.length;
		} );
		var shown = m.reveal < 1 ? Math.floor( total * m.reveal + 0.0001 ) : total;
		var used = 0;
		lines.forEach( function ( line, i ) {
			var w = widths[ i ];
			var y = -bh / 2 + lh * ( i + 0.5 );
			var x = 'left' === l.align ? -bw / 2 : ( 'right' === l.align ? bw / 2 - w : -w / 2 );
			if ( 'highlight' === style ) {
				ctx.save();
				ctx.fillStyle = c2;
				ctx.globalAlpha *= 0.9;
				ctx.fillRect( x - px * 0.12, y - px * 0.1, w + px * 0.24, px * 0.55 );
				ctx.restore();
			}
			var take = Math.max( 0, Math.min( line.length, shown - used ) );
			used += line.length;
			if ( m.rise >= 0 ) {
				self.risingLine( line, x, y, px, l, style, c2, m, manual );
			} else {
				self.paint( take < line.length ? line.slice( 0, take ) : line, x, y, px, l, style, c2, m, bh );
			}
		} );
		ctx.filter = 'none';
		if ( 'letterSpacing' in ctx ) {
			ctx.letterSpacing = '0px';
		}
	};

	/* The letters of one line, in the layer's style. */
	Renderer.prototype.paint = function ( text, x, y, px, l, style, c2, m, bh ) {
		var ctx = this.ctx;
		if ( ! text ) {
			return;
		}
		ctx.lineJoin = 'round';
		switch ( style ) {
			case 'shadow':
				ctx.shadowColor = 'rgba(0,0,0,0.45)';
				ctx.shadowBlur = px * 0.28;
				ctx.shadowOffsetY = px * 0.05;
				ctx.fillStyle = l.color;
				ctx.fillText( text, x, y );
				ctx.shadowColor = 'transparent';
				break;
			case 'outline':
				ctx.lineWidth = px * 0.14;
				ctx.strokeStyle = c2;
				ctx.strokeText( text, x, y );
				ctx.fillStyle = l.color;
				ctx.fillText( text, x, y );
				break;
			case 'stroke':
				ctx.lineWidth = Math.max( 1, px * 0.05 );
				ctx.strokeStyle = l.color;
				ctx.strokeText( text, x, y );
				break;
			case 'neon':
				var g = 0.6 + 0.4 * ( m.glow || 1 );
				ctx.shadowColor = l.color;
				ctx.fillStyle = l.color;
				ctx.shadowBlur = px * 0.7 * g;
				ctx.fillText( text, x, y );
				ctx.shadowBlur = px * 0.3 * g;
				ctx.fillText( text, x, y );
				ctx.shadowBlur = 0;
				ctx.fillStyle = 'rgba(255,255,255,0.85)';
				ctx.fillText( text, x, y );
				break;
			case 'gradient':
				var grad = ctx.createLinearGradient( 0, -bh / 2, 0, bh / 2 );
				grad.addColorStop( 0, l.color );
				grad.addColorStop( 1, c2 );
				ctx.fillStyle = grad;
				ctx.shadowColor = 'rgba(0,0,0,0.25)';
				ctx.shadowBlur = px * 0.15;
				ctx.fillText( text, x, y );
				ctx.shadowColor = 'transparent';
				break;
			case 'retro':
				ctx.fillStyle = c2;
				ctx.fillText( text, x + px * 0.07, y + px * 0.07 );
				ctx.fillStyle = l.color;
				ctx.fillText( text, x, y );
				break;
			default:
				if ( m.glow ) {
					ctx.shadowColor = l.color;
					ctx.shadowBlur = px * 0.5 * m.glow;
				}
				ctx.fillStyle = l.color;
				ctx.fillText( text, x, y );
				ctx.shadowColor = 'transparent';
		}
	};

	/* "Letters rise": each letter comes up on its own, one after another. */
	Renderer.prototype.risingLine = function ( line, x, y, px, l, style, c2, m, manual ) {
		var ctx = this.ctx;
		var n = Math.max( 1, line.length );
		var base = ctx.globalAlpha;
		for ( var i = 0; i < line.length; i++ ) {
			var delay = ( i / n ) * 0.6;
			var cp = clamp( ( m.rise - delay ) / 0.4, 0, 1 );
			if ( cp <= 0 ) {
				continue;
			}
			var cx = x + ctx.measureText( line.slice( 0, i ) ).width + i * manual;
			ctx.globalAlpha = base * cp;
			this.paint( line[ i ], cx, y + ( 1 - ease.out( cp ) ) * px * 0.5, px, l, style, c2, m, px );
		}
		ctx.globalAlpha = base;
	};

	/* A sticker: a picture or an emoji, moving the way its animation says. */
	Renderer.prototype.sticker = function ( l, t, W, H ) {
		var ctx = this.ctx;
		var local = t - l.start;
		var m = motion( l.anim, local, l.end - l.start );
		var size = l.size * Math.min( W, H );
		var cx = l.x * W, cy = l.y * H;
		var w = size, h = size;
		var img = l.src ? image( l.src ) : null;
		if ( img ) {
			var ar = img.naturalWidth / img.naturalHeight;
			if ( ar > 1 ) {
				h = size / ar;
			} else {
				w = size * ar;
			}
		}
		this.boxes[ l.id ] = { cx: cx, cy: cy, w: w * l.scale, h: h * l.scale, rot: l.rot || 0 };
		ctx.globalAlpha = clamp( m.alpha * ( l.opacity == null ? 1 : l.opacity ), 0, 1 );
		ctx.translate( cx + m.dx * W, cy + m.dy * H );
		ctx.rotate( ( ( l.rot || 0 ) + m.rot ) * Math.PI / 180 );
		ctx.scale( l.scale * m.scale * m.sx * ( l.flip ? -1 : 1 ), l.scale * m.scale * m.sy );
		if ( m.blur > 0.3 ) {
			ctx.filter = 'blur(' + ( m.blur * H / 1080 ).toFixed( 1 ) + 'px)';
		}
		if ( m.glow ) {
			ctx.shadowColor = 'rgba(255,255,255,0.9)';
			ctx.shadowBlur = size * 0.2 * m.glow;
		}
		if ( img ) {
			ctx.drawImage( img, -w / 2, -h / 2, w, h );
		} else if ( l.emoji ) {
			ctx.font = Math.round( size * 0.82 ) + 'px "Segoe UI Emoji","Apple Color Emoji","Noto Color Emoji",sans-serif';
			ctx.textAlign = 'center';
			ctx.textBaseline = 'middle';
			ctx.fillText( l.emoji, 0, size * 0.04 );
		}
	};

	/* The particles of an effect: n of them, each with its own seeded path, at local time lt. */
	function particles( l, n, fn ) {
		var r = rng( l.seed || 7 );
		for ( var i = 0; i < n; i++ ) {
			fn( r, i );
		}
	}
	function heart( ctx, x, y, s ) {
		ctx.beginPath();
		ctx.moveTo( x, y + s * 0.3 );
		ctx.bezierCurveTo( x, y, x - s * 0.5, y, x - s * 0.5, y + s * 0.3 );
		ctx.bezierCurveTo( x - s * 0.5, y + s * 0.6, x, y + s * 0.75, x, y + s );
		ctx.bezierCurveTo( x, y + s * 0.75, x + s * 0.5, y + s * 0.6, x + s * 0.5, y + s * 0.3 );
		ctx.bezierCurveTo( x + s * 0.5, y, x, y, x, y + s * 0.3 );
		ctx.fill();
	}
	function star( ctx, x, y, r ) {
		ctx.beginPath();
		for ( var i = 0; i < 8; i++ ) {
			var a = i * Math.PI / 4;
			var rr = i % 2 ? r * 0.28 : r;
			ctx.lineTo( x + Math.cos( a ) * rr, y + Math.sin( a ) * rr );
		}
		ctx.closePath();
		ctx.fill();
	}

	var CONFETTI = [ '#f94144', '#f9c74f', '#90be6d', '#43aa8b', '#577590', '#f8961e', '#ff6fa3', '#b98b7e', '#ffffff' ];

	/* An effect layer drawn over the picture. */
	Renderer.prototype.effect = function ( l, t, W, H ) {
		var ctx = this.ctx;
		var lt = t - l.start;
		var len = l.end - l.start;
		var k = clamp( l.intensity, 0, 1 );
		var def = find( P.effects, l.effect ) || {};
		var col = l.color || def.color || '#ffffff';
		var S = Math.min( W, H );
		var fadeIn = clamp( lt / 0.4, 0, 1 ) * clamp( ( len - lt ) / 0.4, 0, 1 );
		ctx.globalAlpha = fadeIn;
		switch ( l.effect ) {
			case 'confetti':
				particles( l, Math.round( 30 + 90 * k ), function ( r ) {
					var x0 = r() * W, sp = ( 0.18 + 0.3 * r() ) * H, sway = ( 0.01 + 0.03 * r() ) * W, ph = r() * 6.28;
					var y = mod( sp * lt - r() * H * 1.4, H * 1.4 ) - H * 0.2;
					var x = x0 + Math.sin( lt * 2 + ph ) * sway;
					var s = ( 0.008 + 0.012 * r() ) * S;
					ctx.save();
					ctx.translate( x, y );
					ctx.rotate( lt * ( 2 + 4 * r() ) + ph );
					ctx.fillStyle = l.color || CONFETTI[ Math.floor( r() * CONFETTI.length ) ];
					ctx.fillRect( -s / 2, -s * 0.3, s, s * 0.6 );
					ctx.restore();
				} );
				break;
			case 'snow':
				ctx.fillStyle = col;
				particles( l, Math.round( 40 + 110 * k ), function ( r ) {
					var x0 = r() * W, sp = ( 0.05 + 0.12 * r() ) * H, s = ( 0.002 + 0.006 * r() ) * S, ph = r() * 6.28;
					var y = mod( sp * lt - r() * H * 1.2, H * 1.2 ) - H * 0.1;
					ctx.globalAlpha = fadeIn * ( 0.55 + 0.45 * r() );
					ctx.beginPath();
					ctx.arc( x0 + Math.sin( lt * 0.8 + ph ) * 0.02 * W, y, s, 0, Math.PI * 2 );
					ctx.fill();
				} );
				break;
			case 'hearts':
				particles( l, Math.round( 8 + 26 * k ), function ( r ) {
					var x0 = r() * W, sp = ( 0.08 + 0.14 * r() ) * H, s = ( 0.025 + 0.04 * r() ) * S, ph = r() * 6.28;
					var y = H * 1.1 - mod( sp * lt + r() * H * 1.3, H * 1.3 );
					ctx.globalAlpha = fadeIn * clamp( y / ( H * 0.4 ), 0, 1 ) * ( 0.6 + 0.4 * r() );
					ctx.fillStyle = r() < 0.7 ? col : '#ffffff';
					heart( ctx, x0 + Math.sin( lt * 1.5 + ph ) * 0.03 * W, y, s );
				} );
				break;
			case 'sparkles':
				ctx.fillStyle = col;
				particles( l, Math.round( 14 + 40 * k ), function ( r ) {
					var x = r() * W, y = r() * H, f = 0.6 + r() * 1.4, ph = r(), s = ( 0.008 + 0.022 * r() ) * S;
					var v = Math.max( 0, Math.sin( ( lt * f + ph ) * Math.PI * 2 ) );
					if ( v > 0.05 ) {
						ctx.globalAlpha = fadeIn * v;
						ctx.shadowColor = col;
						ctx.shadowBlur = s * 1.5;
						star( ctx, x, y, s * ( 0.4 + 0.6 * v ) );
					}
				} );
				ctx.shadowBlur = 0;
				break;
			case 'petals':
				particles( l, Math.round( 12 + 36 * k ), function ( r ) {
					var x0 = r() * W, sp = ( 0.07 + 0.12 * r() ) * H, s = ( 0.012 + 0.016 * r() ) * S, ph = r() * 6.28;
					var y = mod( sp * lt - r() * H * 1.3, H * 1.3 ) - H * 0.15;
					ctx.save();
					ctx.translate( x0 + Math.sin( lt * 1.2 + ph ) * 0.05 * W, y );
					ctx.rotate( lt * ( 1 + r() ) + ph );
					ctx.scale( 1, 0.55 + 0.35 * Math.sin( lt * 3 + ph ) );
					ctx.globalAlpha = fadeIn * 0.9;
					ctx.fillStyle = r() < 0.6 ? col : '#fbd3df';
					ctx.beginPath();
					ctx.ellipse( 0, 0, s, s * 0.6, 0, 0, Math.PI * 2 );
					ctx.fill();
					ctx.restore();
				} );
				break;
			case 'bubbles':
				particles( l, Math.round( 10 + 30 * k ), function ( r ) {
					var x0 = r() * W, sp = ( 0.06 + 0.1 * r() ) * H, s = ( 0.01 + 0.03 * r() ) * S, ph = r() * 6.28;
					var y = H * 1.1 - mod( sp * lt + r() * H * 1.3, H * 1.3 );
					var x = x0 + Math.sin( lt * 1.3 + ph ) * 0.02 * W;
					ctx.globalAlpha = fadeIn * 0.7;
					ctx.strokeStyle = col;
					ctx.lineWidth = Math.max( 1, s * 0.08 );
					ctx.fillStyle = 'rgba(255,255,255,0.12)';
					ctx.beginPath();
					ctx.arc( x, y, s, 0, Math.PI * 2 );
					ctx.fill();
					ctx.stroke();
					ctx.fillStyle = 'rgba(255,255,255,0.6)';
					ctx.beginPath();
					ctx.arc( x - s * 0.35, y - s * 0.35, s * 0.18, 0, Math.PI * 2 );
					ctx.fill();
				} );
				break;
			case 'glitter':
				particles( l, Math.round( 40 + 120 * k ), function ( r ) {
					var x0 = r() * W, sp = ( 0.2 + 0.35 * r() ) * H, s = ( 0.002 + 0.004 * r() ) * S, ph = r() * 6.28;
					var y = mod( sp * lt - r() * H * 1.2, H * 1.2 ) - H * 0.1;
					ctx.globalAlpha = fadeIn * ( 0.4 + 0.6 * Math.abs( Math.sin( lt * 9 + ph ) ) );
					ctx.fillStyle = r() < 0.75 ? col : '#ffffff';
					ctx.fillRect( x0, y, s * 2, s * 2 );
				} );
				break;
			case 'bokeh':
				ctx.globalCompositeOperation = 'screen';
				particles( l, Math.round( 6 + 14 * k ), function ( r ) {
					var x = mod( r() * W + lt * ( r() - 0.5 ) * 0.05 * W, W ), y = mod( r() * H - lt * ( 0.01 + 0.03 * r() ) * H, H ), s = ( 0.05 + 0.1 * r() ) * S;
					var g = ctx.createRadialGradient( x, y, 0, x, y, s );
					g.addColorStop( 0, col );
					g.addColorStop( 1, 'rgba(0,0,0,0)' );
					ctx.globalAlpha = fadeIn * ( 0.25 + 0.35 * r() ) * ( 0.7 + 0.3 * Math.sin( lt * ( 1 + r() ) ) );
					ctx.fillStyle = g;
					ctx.fillRect( x - s, y - s, s * 2, s * 2 );
				} );
				break;
			case 'lightleak':
				ctx.globalCompositeOperation = 'screen';
				[ 0, 1, 2 ].forEach( function ( i ) {
					var ph = i * 2.1 + ( l.seed % 7 );
					var x = W * ( 0.5 + 0.6 * Math.sin( lt * 0.35 + ph ) );
					var y = H * ( 0.5 + 0.5 * Math.cos( lt * 0.27 + ph * 1.3 ) );
					var s = Math.max( W, H ) * ( 0.45 + 0.15 * i );
					var g = ctx.createRadialGradient( x, y, 0, x, y, s );
					g.addColorStop( 0, i === 1 ? '#ffd27a' : col );
					g.addColorStop( 1, 'rgba(0,0,0,0)' );
					ctx.globalAlpha = fadeIn * 0.45 * k;
					ctx.fillStyle = g;
					ctx.fillRect( 0, 0, W, H );
				} );
				break;
			case 'glow':
				ctx.globalCompositeOperation = 'screen';
				ctx.globalAlpha = fadeIn * 0.45 * k;
				ctx.filter = 'blur(' + Math.round( 18 * W / 1080 ) + 'px)';
				ctx.drawImage( this.canvas, 0, 0 );
				ctx.filter = 'none';
				break;
			case 'vignette':
				var vg = ctx.createRadialGradient( W / 2, H / 2, Math.min( W, H ) * 0.35, W / 2, H / 2, Math.max( W, H ) * 0.75 );
				vg.addColorStop( 0, 'rgba(0,0,0,0)' );
				vg.addColorStop( 1, 'rgba(0,0,0,' + ( 0.75 * k ).toFixed( 3 ) + ')' );
				ctx.fillStyle = vg;
				ctx.fillRect( 0, 0, W, H );
				break;
			case 'grain':
				var tiles = noise();
				var tile = tiles[ Math.floor( lt * 24 ) % tiles.length ];
				ctx.globalCompositeOperation = 'overlay';
				ctx.globalAlpha = fadeIn * 0.35 * k;
				var pat = ctx.createPattern( tile, 'repeat' );
				ctx.fillStyle = pat;
				var o = rng( Math.floor( lt * 24 ) )();
				ctx.translate( -o * 256, -o * 256 );
				ctx.fillRect( 0, 0, W + 256, H + 256 );
				break;
			case 'letterbox':
				var bar = H * 0.11 * k * ease.out( clamp( lt / 0.6, 0, 1 ) ) * ease.out( clamp( ( len - lt ) / 0.6, 0, 1 ) );
				ctx.globalAlpha = 1;
				ctx.fillStyle = col;
				ctx.fillRect( 0, 0, W, bar );
				ctx.fillRect( 0, H - bar, W, bar );
				break;
			case 'flash':
				var fx = lt % Math.max( 0.5, len );
				ctx.globalAlpha = clamp( 1 - fx / 0.45, 0, 1 ) * k;
				ctx.fillStyle = col;
				ctx.fillRect( 0, 0, W, H );
				break;
		}
		ctx.globalAlpha = 1;
		ctx.globalCompositeOperation = 'source-over';
	};

	/* ─────────────────────────── the preview player ─────────────────────────── */

	/*
	 * Plays the project in the page from real <video> and <audio> elements, drawing each frame with the
	 * renderer. It is a preview: when a clip runs backwards it is stepped by seeking, which is choppier than
	 * the export, which decodes every frame exactly.
	 */
	function Player( opts ) {
		this.canvas = opts.canvas;
		this.renderer = new Renderer( this.canvas );
		this.project = opts.project;
		this.onTime = opts.onTime || function () {};
		this.onState = opts.onState || function () {};
		this.t = 0;
		this.playing = false;
		this.loop = false;
		this.muted = false;
		this.media = {};
		this.audios = {};
		this.raf = 0;
		this.refresh();
	}

	Player.prototype.setProject = function ( p ) {
		this.project = p;
		this.refresh();
	};

	/* Make the page's media match the project: an element per clip and per sound. */
	Player.prototype.refresh = function () {
		var self = this;
		var p = this.project;
		this.tl = timeline( p );
		var keep = {};
		( p.clips || [] ).forEach( function ( c ) {
			keep[ c.id ] = 1;
			var el = self.media[ c.id ];
			if ( el && el.dataset.src !== c.url ) {
				self.drop( el );
				el = null;
			}
			if ( ! el ) {
				if ( 'image' === c.kind ) {
					el = new Image();
					el.decoding = 'async';
					el.onload = function () {
						self.draw();
					};
					el.src = c.url;
				} else {
					el = document.createElement( 'video' );
					el.preload = 'auto';
					el.playsInline = true;
					el.muted = false;
					el.addEventListener( 'loadeddata', function () {
						self.draw();
					} );
					el.addEventListener( 'seeked', function () {
						if ( ! self.playing ) {
							self.draw();
						}
					} );
					el.src = c.url;
				}
				el.dataset.src = c.url;
				self.media[ c.id ] = el;
			}
		} );
		Object.keys( this.media ).forEach( function ( id ) {
			if ( ! keep[ id ] ) {
				self.drop( self.media[ id ] );
				delete self.media[ id ];
			}
		} );
		var keepA = {};
		( p.audio || [] ).forEach( function ( a ) {
			keepA[ a.id ] = 1;
			var el = self.audios[ a.id ];
			if ( el && el.dataset.src !== a.src ) {
				self.drop( el );
				el = null;
			}
			if ( ! el ) {
				el = new Audio();
				el.preload = 'auto';
				el.src = a.src;
				el.dataset.src = a.src;
				el.addEventListener( 'loadedmetadata', function () {
					if ( ! a.fileDur && isFinite( el.duration ) ) {
						a.fileDur = el.duration;
					}
				} );
				self.audios[ a.id ] = el;
			}
		} );
		Object.keys( this.audios ).forEach( function ( id ) {
			if ( ! keepA[ id ] ) {
				self.drop( self.audios[ id ] );
				delete self.audios[ id ];
			}
		} );
		this.t = clamp( this.t, 0, this.tl.duration );
		if ( ! this.playing ) {
			this.seek( this.t );
		}
	};

	Player.prototype.drop = function ( el ) {
		try {
			if ( el.pause ) {
				el.pause();
			}
			el.removeAttribute( 'src' );
			if ( el.load ) {
				el.load();
			}
		} catch ( e ) {}
	};

	Player.prototype.duration = function () {
		return this.tl.duration;
	};

	Player.prototype.play = function () {
		if ( this.playing ) {
			return;
		}
		if ( this.t >= this.tl.duration - 0.02 ) {
			this.t = 0;
		}
		this.playing = true;
		this.t0 = this.t;
		this.wall = performance.now();
		this.onState( true );
		var self = this;
		cancelAnimationFrame( this.raf );
		( function tick() {
			if ( ! self.playing ) {
				return;
			}
			var t = self.t0 + ( performance.now() - self.wall ) / 1000;
			if ( t >= self.tl.duration ) {
				if ( self.loop ) {
					self.t0 = 0;
					self.wall = performance.now();
					t = 0;
					self.syncAll( 0, false );
				} else {
					self.t = self.tl.duration;
					self.pause();
					return;
				}
			}
			self.t = t;
			self.syncAll( t, true );
			self.draw();
			self.onTime( t );
			self.raf = requestAnimationFrame( tick );
		}() );
	};

	Player.prototype.pause = function () {
		this.playing = false;
		cancelAnimationFrame( this.raf );
		var self = this;
		Object.keys( this.media ).forEach( function ( id ) {
			var el = self.media[ id ];
			if ( el.pause ) {
				el.pause();
			}
		} );
		Object.keys( this.audios ).forEach( function ( id ) {
			self.audios[ id ].pause();
		} );
		this.onState( false );
		this.syncAll( this.t, false );
		this.onTime( this.t );
	};

	Player.prototype.toggle = function () {
		if ( this.playing ) {
			this.pause();
		} else {
			this.play();
		}
	};

	/* Every element where it should be at t: playing or paused, at the right moment, at the right volume. */
	Player.prototype.syncAll = function ( t, playing ) {
		var self = this;
		var vis = visible( this.tl, t );
		var on = {};
		vis.forEach( function ( it ) {
			on[ it.clip.id ] = it;
		} );
		this.tl.items.forEach( function ( it ) {
			var c = it.clip;
			var el = self.media[ c.id ];
			if ( ! el || 'image' === c.kind ) {
				return;
			}
			var here = on[ c.id ];
			var want = sourceTime( it, here ? t : clamp( t, it.start, it.end ) );
			if ( here ) {
				var vol = c.muted || self.muted ? 0 : clamp( c.volume, 0, 1 );
				var local = t - it.start;
				if ( c.fadeIn && local < c.fadeIn ) {
					vol *= local / c.fadeIn;
				}
				if ( c.fadeOut && local > it.dur - c.fadeOut ) {
					vol *= ( it.dur - local ) / c.fadeOut;
				}
				el.volume = clamp( vol, 0, 1 );
				if ( playing && ! c.reverse ) {
					el.playbackRate = clamp( c.speed, 0.0625, 16 );
					if ( Math.abs( el.currentTime - want ) > 0.3 ) {
						el.currentTime = want;
					}
					if ( el.paused ) {
						el.play().catch( function () {} );
					}
				} else {
					if ( ! el.paused ) {
						el.pause();
					}
					if ( Math.abs( el.currentTime - want ) > ( playing ? 0.04 : 0.001 ) && ! el.seeking ) {
						el.currentTime = want;
					}
				}
			} else {
				if ( ! el.paused ) {
					el.pause();
				}
				// Ready at its first frame, so it starts cleanly when its turn comes.
				if ( t < it.start && t > it.start - 1.5 && Math.abs( el.currentTime - want ) > 0.05 && ! el.seeking ) {
					el.currentTime = want;
				}
			}
		} );
		( this.project.audio || [] ).forEach( function ( a ) {
			var el = self.audios[ a.id ];
			if ( ! el ) {
				return;
			}
			var local = t - a.start;
			var len = a.fileDur || el.duration || 0;
			if ( local < 0 || local >= a.dur || ( ! a.loop && len && a.offset + local >= len ) ) {
				if ( ! el.paused ) {
					el.pause();
				}
				return;
			}
			var want = a.offset + local;
			if ( a.loop && len ) {
				want = a.offset + local < len ? a.offset + local : mod( local - ( len - a.offset ), len );
			}
			var vol = self.muted ? 0 : clamp( a.volume, 0, 1 );
			if ( a.fadeIn && local < a.fadeIn ) {
				vol *= local / a.fadeIn;
			}
			if ( a.fadeOut && local > a.dur - a.fadeOut ) {
				vol *= ( a.dur - local ) / a.fadeOut;
			}
			el.volume = clamp( vol, 0, 1 );
			if ( playing ) {
				if ( Math.abs( el.currentTime - want ) > 0.3 ) {
					el.currentTime = want;
				}
				if ( el.paused ) {
					el.play().catch( function () {} );
				}
			} else if ( ! el.paused ) {
				el.pause();
			}
		} );
	};

	Player.prototype.seek = function ( t ) {
		this.t = clamp( t, 0, this.tl.duration );
		if ( this.playing ) {
			this.t0 = this.t;
			this.wall = performance.now();
		}
		this.syncAll( this.t, this.playing );
		this.draw();
		this.onTime( this.t );
	};

	Player.prototype.draw = function () {
		var self = this;
		this.renderer.frame( this.project, this.tl, this.t, function ( c ) {
			var el = self.media[ c.id ];
			if ( ! el ) {
				return null;
			}
			if ( 'image' === c.kind ) {
				return el.complete && el.naturalWidth ? el : null;
			}
			return el.readyState >= 2 ? el : null;
		} );
	};

	Player.prototype.destroy = function () {
		var self = this;
		this.pause();
		Object.keys( this.media ).forEach( function ( id ) {
			self.drop( self.media[ id ] );
		} );
		Object.keys( this.audios ).forEach( function ( id ) {
			self.drop( self.audios[ id ] );
		} );
		this.media = {};
		this.audios = {};
	};

	/* ─────────────────────────── the export ─────────────────────────── */

	/* The size the export is made at: the project's, or scaled so its long side is `long`. */
	function exportSize( p, long ) {
		var w = p.w, h = p.h;
		if ( long && Math.max( w, h ) > long ) {
			var s = long / Math.max( w, h );
			w = Math.round( w * s / 2 ) * 2;
			h = Math.round( h * s / 2 ) * 2;
		}
		return { w: w, h: h };
	}

	/* Frames of each video clip, decoded exactly, in the order the export asks for them. */
	function frameSources( MB, p, tl, fps, frames, W, H ) {
		var decoders = {};
		var stills = {};
		var opened = [];
		var setup = tl.items.map( function ( it ) {
			var c = it.clip;
			if ( 'image' === c.kind ) {
				return loadImage( c.url ).then( function ( img ) {
					stills[ c.id ] = img;
				} );
			}
			var input = new MB.Input( { source: new MB.UrlSource( c.url ), formats: MB.ALL_FORMATS } );
			opened.push( input );
			return input.getPrimaryVideoTrack().then( function ( track ) {
				if ( ! track ) {
					throw new Error( ( D.i18n && D.i18n.loadFailed ) || 'A clip could not be read' );
				}
				return Promise.all( [ track.getDisplayWidth(), track.getDisplayHeight() ] ).then( function ( dims ) {
					// No bigger than the frame needs (with room for a zoom), so a 4K clip does not decode at 4K.
					var need = Math.max( W, H ) * Math.max( 1, c.zoom || 1 ) * 1.2;
					var long = Math.max( dims[ 0 ], dims[ 1 ] );
					var opts = { poolSize: 3 };
					if ( long > need ) {
						if ( dims[ 0 ] >= dims[ 1 ] ) {
							opts.width = Math.round( need / 2 ) * 2;
						} else {
							opts.height = Math.round( need / 2 ) * 2;
						}
					}
					var sink = new MB.CanvasSink( track, opts );
					// The moments this clip is needed at, in frame order.
					var times = [];
					for ( var f = 0; f < frames; f++ ) {
						var t = f / fps;
						if ( t >= it.start && t < it.end ) {
							times.push( sourceTime( it, t ) );
						}
					}
					var sorted = times.every( function ( v, i ) {
						return ! i || v >= times[ i - 1 ];
					} );
					decoders[ c.id ] = { sink: sink, iter: sorted ? sink.canvasesAtTimestamps( times ) : null, last: null };
				} );
			} );
		} );
		return Promise.all( setup ).then( function () {
			return {
				// The next frame of a clip: it is asked for once per output frame it appears in, in order.
				next: function ( c, time ) {
					if ( 'image' === c.kind ) {
						return Promise.resolve( stills[ c.id ] || null );
					}
					var d = decoders[ c.id ];
					if ( ! d ) {
						return Promise.resolve( null );
					}
					var got = d.iter ? d.iter.next().then( function ( r ) {
						return r.done ? null : r.value;
					} ) : d.sink.getCanvas( time );
					return got.then( function ( w ) {
						if ( w && w.canvas ) {
							d.last = w.canvas;
						}
						return d.last;
					} );
				},
				close: function () {
					opened.forEach( function ( i ) {
						try {
							i.dispose();
						} catch ( e ) {}
					} );
				}
			};
		} );
	}

	/* Each clip's own sound, decoded: an AudioBuffer of the whole track, or null when it has none. */
	function clipAudio( MB, c, ctx ) {
		var input = new MB.Input( { source: new MB.UrlSource( c.url ), formats: MB.ALL_FORMATS } );
		return input.getPrimaryAudioTrack().then( function ( track ) {
			if ( ! track ) {
				input.dispose();
				return null;
			}
			var sink = new MB.AudioBufferSink( track );
			var parts = [];
			var length = 0;
			var rate = 48000;
			var channels = 2;
			var it = sink.buffers();
			return ( function pull() {
				return it.next().then( function ( r ) {
					if ( r.done ) {
						return null;
					}
					var b = r.value.buffer;
					rate = b.sampleRate;
					channels = Math.max( 1, Math.min( 2, b.numberOfChannels ) );
					parts.push( { buffer: b, at: Math.round( r.value.timestamp * b.sampleRate ) } );
					length = Math.max( length, Math.round( r.value.timestamp * b.sampleRate ) + b.length );
					return pull();
				} );
			}() ).then( function () {
				input.dispose();
				if ( ! length ) {
					return null;
				}
				var whole = ctx.createBuffer( channels, length, rate );
				parts.forEach( function ( part ) {
					for ( var ch = 0; ch < channels; ch++ ) {
						var data = part.buffer.getChannelData( Math.min( ch, part.buffer.numberOfChannels - 1 ) );
						var at = Math.max( 0, part.at );
						whole.getChannelData( ch ).set( data.subarray( 0, Math.min( data.length, length - at ) ), at );
					}
				} );
				return whole;
			} );
		} ).catch( function () {
			try {
				input.dispose();
			} catch ( e ) {}
			return null;
		} );
	}

	function reversed( ctx, buf, from, to ) {
		var a = Math.floor( from * buf.sampleRate ), b = Math.min( buf.length, Math.ceil( to * buf.sampleRate ) );
		var out = ctx.createBuffer( buf.numberOfChannels, Math.max( 1, b - a ), buf.sampleRate );
		for ( var ch = 0; ch < buf.numberOfChannels; ch++ ) {
			var src = buf.getChannelData( ch ).subarray( a, b );
			var dst = out.getChannelData( ch );
			for ( var i = 0, n = src.length; i < n; i++ ) {
				dst[ i ] = src[ n - 1 - i ];
			}
		}
		return out;
	}

	/* An envelope: silence to `vol` over fadeIn, held, down to silence over fadeOut. */
	function envelope( gain, at, len, vol, fadeIn, fadeOut ) {
		var g = gain.gain;
		if ( fadeIn > 0 ) {
			g.setValueAtTime( 0, at );
			g.linearRampToValueAtTime( vol, at + Math.min( fadeIn, len ) );
		} else {
			g.setValueAtTime( vol, at );
		}
		if ( fadeOut > 0 ) {
			g.setValueAtTime( vol, Math.max( at, at + len - fadeOut ) );
			g.linearRampToValueAtTime( 0, at + len );
		}
	}

	/* The whole sound of the project, mixed offline into one stereo AudioBuffer, or null when it is silent. */
	function mixAudio( MB, p, tl, rate ) {
		var duration = tl.duration;
		var withSound = tl.items.filter( function ( it ) {
			return 'video' === it.clip.kind && ! it.clip.muted && it.clip.volume > 0;
		} );
		var tracks = ( p.audio || [] ).filter( function ( a ) {
			return a.src && a.volume > 0 && a.dur > 0 && a.start < duration;
		} );
		if ( ! withSound.length && ! tracks.length ) {
			return Promise.resolve( null );
		}
		var ctx = new OfflineAudioContext( 2, Math.max( 1, Math.ceil( duration * rate ) ), rate );
		var heard = 0;
		var cache = {};
		var clips = withSound.map( function ( it ) {
			var c = it.clip;
			cache[ c.url ] = cache[ c.url ] || clipAudio( MB, c, ctx );
			return cache[ c.url ].then( function ( buf ) {
				if ( ! buf ) {
					return;
				}
				heard++;
				var src = ctx.createBufferSource();
				var offset = c.in;
				var len = c.out - c.in;
				if ( c.reverse ) {
					src.buffer = reversed( ctx, buf, c.in, c.out );
					offset = 0;
				} else {
					src.buffer = buf;
				}
				src.playbackRate.value = clamp( c.speed, 0.0625, 16 );
				var gain = ctx.createGain();
				var prev = tl.items[ it.index - 1 ];
				var next = tl.items[ it.index + 1 ];
				var fadeIn = Math.max( c.fadeIn || 0, it.overlapIn || 0 );
				var fadeOut = Math.max( c.fadeOut || 0, next ? next.overlapIn : 0 );
				envelope( gain, it.start, it.dur, clamp( c.volume, 0, 2 ), fadeIn, fadeOut );
				src.connect( gain ).connect( ctx.destination );
				src.start( it.start, offset, len );
				void prev;
			} );
		} );
		var music = tracks.map( function ( a ) {
			cache[ a.src ] = cache[ a.src ] || fetch( a.src, { credentials: 'same-origin' } ).then( function ( r ) {
				if ( ! r.ok ) {
					throw new Error( 'HTTP ' + r.status );
				}
				return r.arrayBuffer();
			} ).then( function ( data ) {
				return ctx.decodeAudioData( data );
			} ).catch( function () {
				return null;
			} );
			return cache[ a.src ].then( function ( buf ) {
				if ( ! buf ) {
					return;
				}
				heard++;
				var end = Math.min( duration, a.start + a.dur );
				var gain = ctx.createGain();
				envelope( gain, a.start, end - a.start, clamp( a.volume, 0, 2 ), a.fadeIn || 0, a.fadeOut || 0 );
				gain.connect( ctx.destination );
				var at = a.start;
				var pos = clamp( a.offset || 0, 0, Math.max( 0, buf.duration - 0.05 ) );
				var guard = 0;
				while ( at < end - 0.01 && guard++ < 200 ) {
					var seg = Math.min( buf.duration - pos, end - at );
					if ( seg <= 0.01 ) {
						break;
					}
					var src = ctx.createBufferSource();
					src.buffer = buf;
					src.connect( gain );
					src.start( at, pos, seg );
					at += seg;
					pos = 0;
					if ( ! a.loop ) {
						break;
					}
				}
			} );
		} );
		return Promise.all( clips.concat( music ) ).then( function () {
			return heard ? ctx.startRendering() : null;
		} );
	}

	/* Cut an AudioBuffer into one-second pieces, so sound and picture go into the file side by side. */
	function slices( buf, seconds ) {
		var out = [];
		var step = Math.round( buf.sampleRate * seconds );
		for ( var at = 0; at < buf.length; at += step ) {
			var n = Math.min( step, buf.length - at );
			var piece = new AudioBuffer( { length: n, numberOfChannels: buf.numberOfChannels, sampleRate: buf.sampleRate } );
			for ( var ch = 0; ch < buf.numberOfChannels; ch++ ) {
				piece.copyToChannel( buf.getChannelData( ch ).subarray( at, at + n ), ch );
			}
			out.push( { at: at / buf.sampleRate, buffer: piece } );
		}
		return out;
	}

	function posterOf( canvas, long ) {
		var s = Math.min( 1, ( long || 960 ) / Math.max( canvas.width, canvas.height ) );
		var c = document.createElement( 'canvas' );
		c.width = Math.round( canvas.width * s );
		c.height = Math.round( canvas.height * s );
		c.getContext( '2d' ).drawImage( canvas, 0, 0, c.width, c.height );
		return c.toDataURL( 'image/jpeg', 0.86 );
	}

	/*
	 * The project as a video file. opts.long caps the long side (e.g. 1280 for a smaller file),
	 * opts.onProgress( 0…1, words ), opts.signal { cancelled } stops it between frames.
	 */
	function exportVideo( project, opts ) {
		opts = opts || {};
		var T = D.i18n || {};
		if ( ! canEncode() ) {
			return Promise.reject( new Error( window.isSecureContext === false ? ( T.needSecure || 'Saving a video needs the admin on https://.' ) : ( T.needBrowser || 'This browser cannot save video.' ) ) );
		}
		var p = normalize( JSON.parse( JSON.stringify( project ) ) );
		var tl = timeline( p );
		var size = exportSize( p, opts.long );
		var W = size.w, H = size.h;
		var fps = p.fps;
		var frames = Math.max( 1, Math.round( tl.duration * fps ) );
		var progress = opts.onProgress || function () {};
		var signal = opts.signal || {};
		var canvas = document.createElement( 'canvas' );
		canvas.width = W;
		canvas.height = H;
		var renderer = new Renderer( canvas );
		var MB, output, videoSource, audioSource, sources, poster = '', mime = 'video/mp4', ext = 'mp4';

		return loadMediabunny().then( function ( mod ) {
			MB = mod;
			return Promise.all( [
				MB.getFirstEncodableVideoCodec( [ 'avc', 'vp9', 'av1', 'vp8' ], { width: W, height: H } ),
				prepare( p )
			] );
		} ).then( function ( r ) {
			var vcodec = r[ 0 ];
			if ( ! vcodec ) {
				throw new Error( T.noCodec || 'This browser cannot encode video at this size.' );
			}
			var mp4 = 'avc' === vcodec;
			var format = mp4 ? new MB.Mp4OutputFormat( { fastStart: 'in-memory' } ) : new MB.WebMOutputFormat();
			mime = mp4 ? 'video/mp4' : 'video/webm';
			ext = mp4 ? 'mp4' : 'webm';
			progress( 0, T.encodingAudio );
			return Promise.all( [
				mixAudio( MB, p, tl, 48000 ),
				MB.getFirstEncodableAudioCodec( mp4 ? [ 'aac', 'opus' ] : [ 'opus', 'vorbis' ], { numberOfChannels: 2, sampleRate: 48000 } ),
				frameSources( MB, p, tl, fps, frames, W, H )
			] ).then( function ( rr ) {
				var mixed = rr[ 0 ];
				var acodec = rr[ 1 ];
				sources = rr[ 2 ];
				output = new MB.Output( { format: format, target: new MB.BufferTarget() } );
				var bitrate = Math.round( clamp( W * H * fps * 0.11, 1500000, 16000000 ) );
				videoSource = new MB.CanvasSource( canvas, { codec: vcodec, quality: new MB.Quality( { bitrate: bitrate } ), keyFrameInterval: 2 } );
				output.addVideoTrack( videoSource, { frameRate: fps } );
				var pieces = [];
				if ( mixed && acodec ) {
					audioSource = new MB.AudioBufferSource( { codec: acodec, quality: new MB.Quality( { bitrate: 192000 } ) } );
					output.addAudioTrack( audioSource );
					pieces = slices( mixed, 1 );
				}
				return output.start().then( function () {
					var f = 0;
					var a = 0;
					return ( function step() {
						if ( signal.cancelled ) {
							throw new Error( 'cancelled' );
						}
						if ( f >= frames ) {
							return null;
						}
						var t = f / fps;
						// Sound a second ahead of the picture, so the muxer always has both to interleave.
						var feed = Promise.resolve();
						while ( a < pieces.length && pieces[ a ].at <= t + 1 ) {
							feed = feed.then( ( function ( piece ) {
								return function () {
									return audioSource.add( piece.buffer );
								};
							}( pieces[ a ] ) ) );
							a++;
						}
						var vis = visible( tl, t );
						return feed.then( function () {
							return Promise.all( vis.map( function ( it ) {
								return sources.next( it.clip, sourceTime( it, t ) ).then( function ( src ) {
									return [ it.clip.id, src ];
								} );
							} ) );
						} ).then( function ( got ) {
							var map = {};
							got.forEach( function ( g ) {
								map[ g[ 0 ] ] = g[ 1 ];
							} );
							renderer.frame( p, tl, t, function ( c ) {
								return map[ c.id ] || null;
							} );
							if ( ! poster && t >= Math.min( 1, tl.duration / 3 ) ) {
								poster = posterOf( canvas );
							}
							return videoSource.add( t, 1 / fps );
						} ).then( function () {
							f++;
							if ( f % 3 === 0 ) {
								progress( f / frames, T.rendering );
							}
							return step();
						} );
					}() ).then( function () {
						var rest = Promise.resolve();
						while ( a < pieces.length ) {
							rest = rest.then( ( function ( piece ) {
								return function () {
									return audioSource.add( piece.buffer );
								};
							}( pieces[ a ] ) ) );
							a++;
						}
						return rest;
					} );
				} );
			} );
		} ).then( function () {
			return output.finalize();
		} ).then( function () {
			sources.close();
			if ( ! poster ) {
				poster = posterOf( canvas );
			}
			progress( 1, T.rendering );
			return { blob: new Blob( [ output.target.buffer ], { type: mime } ), mime: mime, ext: ext, poster: poster, width: W, height: H, duration: tl.duration };
		} ).catch( function ( e ) {
			try {
				if ( sources ) {
					sources.close();
				}
				if ( output && 'finalized' !== output.state ) {
					output.cancel();
				}
			} catch ( err ) {}
			throw e;
		} );
	}

	/* The project as a looping GIF: opts.width (the long side) and opts.fps. No sound — a GIF has none. */
	function exportGif( project, opts ) {
		opts = opts || {};
		var T = D.i18n || {};
		if ( typeof window.VideoDecoder !== 'function' ) {
			return Promise.reject( new Error( window.isSecureContext === false ? ( T.needSecure || 'Saving a video needs the admin on https://.' ) : ( T.needBrowser || 'This browser cannot read video frames.' ) ) );
		}
		var p = normalize( JSON.parse( JSON.stringify( project ) ) );
		var tl = timeline( p );
		var fps = clamp( parseInt( opts.fps, 10 ) || 12, 4, 30 );
		var long = clamp( parseInt( opts.width, 10 ) || 480, 120, 1080 );
		var size = exportSize( p, long );
		var W = size.w, H = size.h;
		var frames = Math.max( 1, Math.round( tl.duration * fps ) );
		var progress = opts.onProgress || function () {};
		var signal = opts.signal || {};
		var canvas = document.createElement( 'canvas' );
		canvas.width = W;
		canvas.height = H;
		var ctx = canvas.getContext( '2d', { willReadFrequently: true } );
		var renderer = new Renderer( canvas );
		var MB, G, sources, poster = '';
		return Promise.all( [ loadMediabunny(), loadGifenc(), prepare( p ) ] ).then( function ( r ) {
			MB = r[ 0 ];
			G = r[ 1 ];
			return frameSources( MB, p, tl, fps, frames, W, H );
		} ).then( function ( s ) {
			sources = s;
			var gif = G.GIFEncoder();
			var delay = Math.round( 1000 / fps );
			var f = 0;
			return ( function step() {
				if ( signal.cancelled ) {
					throw new Error( 'cancelled' );
				}
				if ( f >= frames ) {
					return gif;
				}
				var t = f / fps;
				var vis = visible( tl, t );
				return Promise.all( vis.map( function ( it ) {
					return sources.next( it.clip, sourceTime( it, t ) ).then( function ( src ) {
						return [ it.clip.id, src ];
					} );
				} ) ).then( function ( got ) {
					var map = {};
					got.forEach( function ( g ) {
						map[ g[ 0 ] ] = g[ 1 ];
					} );
					renderer.frame( p, tl, t, function ( c ) {
						return map[ c.id ] || null;
					} );
					if ( ! poster && t >= Math.min( 1, tl.duration / 3 ) ) {
						poster = posterOf( canvas );
					}
					var data = ctx.getImageData( 0, 0, W, H ).data;
					var palette = G.quantize( data, 256 );
					var index = G.applyPalette( data, palette );
					gif.writeFrame( index, W, H, { palette: palette, delay: delay, repeat: 0 } );
					f++;
					if ( f % 2 === 0 ) {
						progress( f / frames, T.renderingGif );
					}
					// Let the page breathe between frames: quantising is the heavy part.
					return new Promise( function ( resolve ) {
						setTimeout( resolve, 0 );
					} ).then( step );
				} );
			}() );
		} ).then( function ( gif ) {
			gif.finish();
			sources.close();
			progress( 1, T.renderingGif );
			return { blob: new Blob( [ gif.bytes() ], { type: 'image/gif' } ), mime: 'image/gif', ext: 'gif', poster: poster || posterOf( canvas ), width: W, height: H, duration: tl.duration };
		} ).catch( function ( e ) {
			if ( sources ) {
				sources.close();
			}
			throw e;
		} );
	}

	/* ─────────────────────────── the media library, in pieces ─────────────────────────── */

	/* A route of the editor's, with a query that works whether the site's REST root is /wp-json/ or ?rest_route=. */
	function routeUrl( route, query ) {
		var u = D.rest.root + route;
		if ( query ) {
			u += ( u.indexOf( '?' ) === -1 ? '?' : '&' ) + Object.keys( query ).map( function ( k ) {
				return encodeURIComponent( k ) + '=' + encodeURIComponent( query[ k ] );
			} ).join( '&' );
		}
		return u;
	}

	function call( route, body, raw, query ) {
		var opts = {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': D.rest.nonce }
		};
		if ( raw ) {
			opts.headers[ 'Content-Type' ] = 'application/octet-stream';
			opts.body = raw;
		} else {
			opts.headers[ 'Content-Type' ] = 'application/json';
			opts.body = JSON.stringify( body || {} );
		}
		return fetch( routeUrl( route, query ), opts ).then( function ( r ) {
			return r.text().then( function ( text ) {
				var json = null;
				try {
					json = JSON.parse( text );
				} catch ( e ) {
					throw new Error( ( ( D.i18n && D.i18n.error ) || 'Something went wrong' ) + ' (HTTP ' + r.status + ')' );
				}
				if ( ! r.ok ) {
					throw new Error( ( json && json.message ) || ( D.i18n && D.i18n.error ) || 'Something went wrong' );
				}
				return json;
			} );
		} );
	}

	/*
	 * A file to the media library: started, sent in pieces, finished. meta: title, kind (edit|gif|boomerang|
	 * source), design (the project, for editing again), source (the first clip's attachment), poster (a JPEG
	 * data URL), made (it belongs to the Video Builder's list too).
	 */
	function upload( blob, meta, onProgress ) {
		meta = meta || {};
		onProgress = onProgress || function () {};
		var type = blob.type || meta.type || 'video/mp4';
		return call( 'upload/start', { type: type, size: blob.size } ).then( function ( s ) {
			var size = Math.min( s.chunk || D.chunk || 3145728, 6291456 );
			var count = Math.ceil( blob.size / size );
			var index = 0;
			return ( function next() {
				if ( index >= count ) {
					return null;
				}
				var piece = blob.slice( index * size, Math.min( blob.size, ( index + 1 ) * size ) );
				var tries = 0;
				return ( function send() {
					return call( 'upload/chunk', null, piece, { token: s.token, index: index } ).catch( function ( e ) {
						if ( ++tries < 4 ) {
							return new Promise( function ( resolve ) {
								setTimeout( resolve, 1200 * tries );
							} ).then( send );
						}
						throw e;
					} );
				}() ).then( function () {
					index++;
					onProgress( index / count );
					return next();
				} );
			}() ).then( function () {
				return call( 'upload/finish', {
					token: s.token,
					title: meta.title || '',
					kind: meta.kind || 'edit',
					design: meta.design ? JSON.stringify( meta.design ) : '',
					source: meta.source || 0,
					poster: meta.poster || '',
					made: meta.made ? 1 : 0
				} );
			} );
		} );
	}

	/* ─────────────────────────── for the Video Builder ─────────────────────────── */

	/* A project of these library clips one after another, at the given size. */
	function fromClips( clips, size ) {
		var p = newProject( size || {} );
		p.clips = clips.map( function ( c ) {
			return clipDefaults( { att: c.id, url: c.url, mime: c.mime, title: c.title, poster: c.poster || '', seconds: c.seconds || 5, out: c.seconds || 5 } );
		} );
		if ( clips[ 0 ] && clips[ 0 ].title ) {
			p.name = clips[ 0 ].title;
		}
		return normalize( p );
	}

	/* How long a video really is, read from the file — the library's figure is missing for a new upload. */
	function probe( url ) {
		return new Promise( function ( resolve ) {
			var v = document.createElement( 'video' );
			v.preload = 'metadata';
			v.onloadedmetadata = function () {
				resolve( { seconds: v.duration, width: v.videoWidth, height: v.videoHeight } );
				v.removeAttribute( 'src' );
				v.load();
			};
			v.onerror = function () {
				resolve( null );
			};
			v.src = url;
		} );
	}

	/* A boomerang of a clip: forward and backward, `repeat` times, at `speed`, without its sound. */
	function boomerang( clip, opts ) {
		opts = opts || {};
		var len = Math.min( clip.seconds || 3, opts.max || 4 );
		var speed = clamp( parseFloat( opts.speed ) || 1.5, 0.5, 3 );
		var repeat = clamp( parseInt( opts.repeat, 10 ) || 3, 1, 6 );
		var p = newProject( opts.size || { w: clip.width || 1080, h: clip.height || 1920 } );
		var base = { att: clip.id, url: clip.url, mime: clip.mime || 'video/mp4', seconds: clip.seconds, in: 0, out: len, speed: speed, muted: true, volume: 0 };
		for ( var i = 0; i < repeat; i++ ) {
			p.clips.push( clipDefaults( Object.assign( {}, base ) ) );
			p.clips.push( clipDefaults( Object.assign( {}, base, { reverse: true } ) ) );
		}
		p.name = ( clip.title || 'Video' ) + ' — boomerang';
		return normalize( p );
	}

	window.WHDVideo = {
		clamp: clamp,
		uid: uid,
		find: find,
		hash: hash,
		newProject: newProject,
		normalize: normalize,
		clipDefaults: clipDefaults,
		clipDur: clipDur,
		timeline: timeline,
		sourceTime: sourceTime,
		visible: visible,
		motion: motion,
		Renderer: Renderer,
		Player: Player,
		prepare: prepare,
		loadImage: loadImage,
		exportVideo: exportVideo,
		exportGif: exportGif,
		mixAudio: mixAudio,
		canEncode: canEncode,
		loadMediabunny: loadMediabunny,
		upload: upload,
		call: call,
		fromClips: fromClips,
		boomerang: boomerang,
		probe: probe,
		posterOf: posterOf
	};
}() );
