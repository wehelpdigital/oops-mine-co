/**
 * The Adjust sliders of the live studio: contrast, warmth, soft skin and the rest, worked out on the graphics card.
 *
 * Only the camera goes through here. It runs before the frame, the product card and the text are drawn, so those keep
 * their true colours, and it is burned into the video like everything else on the stage. With every slider at zero it
 * does not run at all: the camera is drawn straight onto the stage, the way it always was.
 *
 * One pass over one texture, on purpose. A laptop that is also encoding video has little to spare, and the only thing
 * this needs beyond the pixel itself is a blurred copy for soft skin, which the texture's mipmaps give for free.
 *
 * A browser or computer without a real graphics card (failIfMajorPerformanceCaveat) gets no grader, because doing this
 * in software would cost the stream its frames. There the first four sliders fall back to canvas filters and the others
 * are switched off; see cssFilter() and the `css` flag.
 *
 * @package whd
 */
( function ( root ) {
	'use strict';

	/* The sliders, in the order they are shown. css: can still be done without the graphics card. */
	var ADJUST = [
		{ key: 'bright', label: 'Brightness', group: 'light', min: -100, max: 100, css: true },
		{ key: 'contrast', label: 'Contrast', group: 'light', min: -100, max: 100, css: true },
		{ key: 'high', label: 'Highlights', group: 'light', min: -100, max: 100 },
		{ key: 'low', label: 'Shadows', group: 'light', min: -100, max: 100 },
		{ key: 'sat', label: 'Saturation', group: 'colour', min: -100, max: 100, css: true },
		{ key: 'warm', label: 'Warmth', group: 'colour', min: -100, max: 100, css: true },
		{ key: 'tint', label: 'Tint', group: 'colour', min: -100, max: 100 },
		{ key: 'sharp', label: 'Sharpness', group: 'finish', min: 0, max: 100 },
		{ key: 'soft', label: 'Soft skin', group: 'finish', min: 0, max: 100 },
		{ key: 'fade', label: 'Fade', group: 'finish', min: 0, max: 100 },
		{ key: 'vig', label: 'Vignette', group: 'finish', min: 0, max: 100 }
	];

	var VERTEX = [
		'#version 300 es',
		'uniform vec4 uCrop;', // the part of the texture to show: x, y, width, height, each 0 to 1
		'out vec2 vUv;',
		'void main() {',
		'	vec2 p = vec2( float( gl_VertexID & 1 ), float( ( gl_VertexID >> 1 ) & 1 ) );',
		'	vUv = uCrop.xy + p * uCrop.zw;',
		'	gl_Position = vec4( p.x * 2.0 - 1.0, 1.0 - p.y * 2.0, 0.0, 1.0 );',
		'}'
	].join( '\n' );

	var FRAGMENT = [
		'#version 300 es',
		'precision highp float;',
		'uniform sampler2D uTex;',
		'uniform vec2 uStep;', // one pixel of the stage, in the texture
		'uniform vec2 uSize;',
		'uniform float uLod;',
		'uniform float uGain, uContrast, uHigh, uLow, uSat, uWarm, uTint, uSharp, uSoft, uFade, uVig;',
		'in vec2 vUv;',
		'out vec4 outColor;',
		'const vec3 LUMA = vec3( 0.2126, 0.7152, 0.0722 );',
		'',
		// Skin, of any tone, sits in a small patch of colour whatever its lightness. Clothes in that patch get smoothed too, which is why the edge test below matters.
		'float skin( vec3 c ) {',
		'	float y = dot( c, LUMA );',
		'	vec2 chroma = vec2( ( c.b - y ) * 0.5389, ( c.r - y ) * 0.635 );',
		'	float d = length( ( chroma - vec2( -0.075, 0.095 ) ) / vec2( 0.085, 0.08 ) );',
		'	return ( 1.0 - smoothstep( 0.6, 1.2, d ) ) * smoothstep( 0.1, 0.26, y );',
		'}',
		'',
		'void main() {',
		'	vec3 c = texture( uTex, vUv ).rgb;',
		'	if ( uSoft > 0.0 ) {',
		'		vec2 s = uStep * 2.5;',
		'		vec3 b = ( textureLod( uTex, vUv + s * vec2( -1.0, -1.0 ), uLod ).rgb + textureLod( uTex, vUv + s * vec2( 1.0, -1.0 ), uLod ).rgb',
		'			+ textureLod( uTex, vUv + s * vec2( -1.0, 1.0 ), uLod ).rgb + textureLod( uTex, vUv + s * vec2( 1.0, 1.0 ), uLod ).rgb ) * 0.25;',
		// Far from its blurred self means an eye, a lip, a hem: those stay sharp. Measured a step blurred, or every noisy pixel of a webcam counts as an edge and is left behind as a speck.
		'		float edge = smoothstep( 0.025, 0.11, length( textureLod( uTex, vUv, max( uLod - 1.0, 0.0 ) ).rgb - b ) );',
		'		c = mix( c, b, uSoft * skin( b ) * ( 1.0 - edge ) );',
		'	}',
		'	if ( uSharp > 0.0 ) {',
		'		vec3 n = ( texture( uTex, vUv + vec2( uStep.x, 0.0 ) ).rgb + texture( uTex, vUv - vec2( uStep.x, 0.0 ) ).rgb',
		'			+ texture( uTex, vUv + vec2( 0.0, uStep.y ) ).rgb + texture( uTex, vUv - vec2( 0.0, uStep.y ) ).rgb ) * 0.25;',
		'		c += ( c - n ) * uSharp * 1.6;',
		'	}',
		'	c *= vec3( 1.0 + 0.12 * uWarm, 1.0 - 0.12 * uTint, 1.0 - 0.16 * uWarm );',
		'	c *= uGain;',
		'	float l = dot( c, LUMA );',
		'	c += uLow * 0.22 * ( 1.0 - smoothstep( 0.0, 0.55, l ) );',
		'	c += uHigh * 0.22 * smoothstep( 0.45, 1.0, l );',
		'	c = ( c - 0.5 ) * uContrast + 0.5;',
		'	l = dot( c, LUMA );',
		'	c = mix( vec3( l ), c, 1.0 + uSat );',
		'	if ( uFade > 0.0 ) {',
		'		c = c * ( 1.0 - uFade * 0.2 ) + uFade * 0.14;',
		'	}',
		'	if ( uVig > 0.0 ) {',
		'		float r = distance( gl_FragCoord.xy / uSize, vec2( 0.5 ) );',
		'		float v = smoothstep( 0.32, 0.82, r );',
		'		c *= 1.0 - uVig * 0.8 * v * v;',
		'	}',
		'	outColor = vec4( clamp( c, 0.0, 1.0 ), 1.0 );',
		'}'
	].join( '\n' );

	function clamp( n, a, b ) {
		return n < a ? a : n > b ? b : n;
	}

	function gain( v ) {
		return Math.pow( 2, ( +v.bright || 0 ) / 100 * 0.7 );
	}

	function contrastFactor( v ) {
		var c = ( +v.contrast || 0 ) / 100;
		return 1 + c * ( c > 0 ? 0.7 : 0.5 );
	}

	function Grader() {
		var self = this;
		this.canvas = document.createElement( 'canvas' );
		this.ok = false;
		this.runs = 0;
		this.canvas.addEventListener( 'webglcontextlost', function ( e ) {
			e.preventDefault(); // or the browser never gives it back
			self.ok = false;
		} );
		this.canvas.addEventListener( 'webglcontextrestored', function () {
			self._init();
		} );
		this._init();
	}

	Grader.prototype._init = function () {
		var gl = this.gl || this.canvas.getContext( 'webgl2', {
			alpha: false,
			antialias: false,
			depth: false,
			stencil: false,
			failIfMajorPerformanceCaveat: true
		} );
		this.gl = gl;
		if ( ! gl ) {
			return;
		}
		var make = function ( kind, source ) {
			var shader = gl.createShader( kind );
			gl.shaderSource( shader, source );
			gl.compileShader( shader );
			return shader;
		};
		var program = gl.createProgram();
		gl.attachShader( program, make( gl.VERTEX_SHADER, VERTEX ) );
		gl.attachShader( program, make( gl.FRAGMENT_SHADER, FRAGMENT ) );
		gl.linkProgram( program );
		if ( ! gl.getProgramParameter( program, gl.LINK_STATUS ) ) {
			return;
		}
		gl.useProgram( program );
		this.u = {};
		[ 'uCrop', 'uStep', 'uSize', 'uLod', 'uGain', 'uContrast', 'uHigh', 'uLow', 'uSat', 'uWarm', 'uTint', 'uSharp', 'uSoft', 'uFade', 'uVig' ].forEach( function ( name ) {
			this.u[ name ] = gl.getUniformLocation( program, name );
		}, this );
		var texture = gl.createTexture();
		gl.bindTexture( gl.TEXTURE_2D, texture );
		gl.texParameteri( gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE );
		gl.texParameteri( gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE );
		gl.texParameteri( gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR );
		gl.texParameteri( gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR );
		gl.bindVertexArray( gl.createVertexArray() );
		this._mips = false;
		this.ok = true;
	};

	/**
	 * Draw the part of the camera that shows, adjusted, onto this.canvas at the same place it has on the stage.
	 * Read it straight away, in the same tick: WebGL clears its picture once the browser has shown it.
	 *
	 * @param {HTMLVideoElement} video The camera.
	 * @param {Object}           box   sx, sy, sw, sh: the part of the video. dx, dy, dw, dh: where it goes.
	 * @param {number}           W     The stage's width.
	 * @param {number}           H     The stage's height.
	 * @param {Object}           v     The sliders.
	 * @return {HTMLCanvasElement|null} Null if it cannot, and the camera should be drawn as it is.
	 */
	Grader.prototype.run = function ( video, box, W, H, v ) {
		if ( ! this.ok ) {
			return null;
		}
		var gl = this.gl;
		var u = this.u;
		var vw = video.videoWidth;
		var vh = video.videoHeight;
		if ( this.canvas.width !== W || this.canvas.height !== H ) {
			this.canvas.width = W;
			this.canvas.height = H;
		}
		try {
			gl.texImage2D( gl.TEXTURE_2D, 0, gl.RGBA, gl.RGBA, gl.UNSIGNED_BYTE, video );
		} catch ( e ) {
			this.ok = false; // a picture that may not be read; never expected from a camera
			return null;
		}
		var soft = ( +v.soft || 0 ) / 100;
		if ( soft > 0 ) {
			gl.generateMipmap( gl.TEXTURE_2D );
			if ( ! this._mips ) {
				gl.texParameteri( gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR_MIPMAP_LINEAR );
				this._mips = true;
			}
		} else if ( this._mips ) {
			// Asking for mipmaps that were not made for this frame would draw black.
			gl.texParameteri( gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR );
			this._mips = false;
		}
		var per = box.sw / box.dw; // camera pixels per stage pixel
		gl.viewport( Math.round( box.dx ), H - Math.round( box.dy + box.dh ), Math.round( box.dw ), Math.round( box.dh ) );
		gl.uniform4f( u.uCrop, box.sx / vw, box.sy / vh, box.sw / vw, box.sh / vh );
		gl.uniform2f( u.uStep, per / vw, ( box.sh / box.dh ) / vh );
		gl.uniform2f( u.uSize, W, H );
		gl.uniform1f( u.uLod, Math.max( 0, 2 + Math.log2( per ) ) );
		gl.uniform1f( u.uGain, gain( v ) );
		gl.uniform1f( u.uContrast, contrastFactor( v ) );
		gl.uniform1f( u.uHigh, ( +v.high || 0 ) / 100 );
		gl.uniform1f( u.uLow, ( +v.low || 0 ) / 100 );
		gl.uniform1f( u.uSat, ( +v.sat || 0 ) / 100 );
		gl.uniform1f( u.uWarm, ( +v.warm || 0 ) / 100 );
		gl.uniform1f( u.uTint, ( +v.tint || 0 ) / 100 );
		gl.uniform1f( u.uSharp, ( +v.sharp || 0 ) / 100 );
		gl.uniform1f( u.uSoft, soft );
		gl.uniform1f( u.uFade, ( +v.fade || 0 ) / 100 );
		gl.uniform1f( u.uVig, ( +v.vig || 0 ) / 100 );
		gl.drawArrays( gl.TRIANGLE_STRIP, 0, 4 );
		this.runs++;
		return this.canvas;
	};

	/* What the sliders can still do with canvas filters, on a computer the grader could not start on. */
	function cssFilter( v ) {
		var parts = [];
		var warm = ( +v.warm || 0 ) / 100;
		if ( warm > 0 ) {
			parts.push( 'sepia(' + ( warm * 0.4 ).toFixed( 3 ) + ') saturate(' + ( 1 + warm * 0.3 ).toFixed( 3 ) + ')' );
		} else if ( warm < 0 ) {
			parts.push( 'hue-rotate(' + ( -warm * 12 ).toFixed( 1 ) + 'deg)' );
		}
		if ( v.bright ) {
			parts.push( 'brightness(' + gain( v ).toFixed( 3 ) + ')' );
		}
		if ( v.contrast ) {
			parts.push( 'contrast(' + contrastFactor( v ).toFixed( 3 ) + ')' );
		}
		if ( v.sat ) {
			parts.push( 'saturate(' + ( 1 + v.sat / 100 ).toFixed( 3 ) + ')' );
		}
		return parts.join( ' ' );
	}

	/**
	 * One look at the camera and a sensible correction: exposure, spread and white balance, each only part of the way.
	 * It only touches the sliders it names, and only once: it does not follow the room's light around while on air.
	 *
	 * It only corrects what is clearly off. White balance assumes the picture averages out to a slightly warm grey, which a
	 * bright dress or a beige wall can upset, so anything from a touch cool to quite warm is left as it is, and past that it
	 * goes part of the way to a low limit: better a little off than the dress turning another colour.
	 *
	 * @param {Uint8ClampedArray} d RGBA.
	 * @return {Object} bright, contrast, high, low, warm, tint.
	 */
	function auto( d ) {
		var hist = new Uint32Array( 256 );
		var r = 0;
		var g = 0;
		var b = 0;
		var n = 0;
		var total = d.length / 4;
		for ( var i = 0; i < d.length; i += 4 ) {
			var y = 0.2126 * d[ i ] + 0.7152 * d[ i + 1 ] + 0.0722 * d[ i + 2 ];
			hist[ y | 0 ]++;
			if ( y > 16 && y < 240 ) { // burnt-out windows and black corners say nothing about the light
				r += d[ i ];
				g += d[ i + 1 ];
				b += d[ i + 2 ];
				n++;
			}
		}
		var at = function ( part ) {
			var want = total * part;
			var seen = 0;
			for ( var k = 0; k < 256; k++ ) {
				seen += hist[ k ];
				if ( seen >= want ) {
					return k / 255;
				}
			}
			return 1;
		};
		var low = at( 0.02 );
		var mid = at( 0.5 );
		var high = at( 0.98 );
		var out = { bright: 0, contrast: 0, high: 0, low: 0, warm: 0, tint: 0 };

		var aim = mid < 0.36 ? 0.36 : mid > 0.62 ? 0.62 : mid;
		out.bright = clamp( Math.round( Math.log2( aim / Math.max( 0.04, mid ) ) * 0.8 / 0.7 * 100 ), -40, 60 );
		var spread = Math.max( 0.05, high - low );
		if ( spread < 0.8 ) {
			out.contrast = clamp( Math.round( ( 0.85 / spread - 1 ) / 0.7 * 100 * 0.6 ), 0, 30 );
		}
		if ( high > 0.97 ) {
			out.high = -18;
		}
		if ( low < 0.03 ) {
			out.low = 12;
		}
		if ( n > total * 0.05 && r > 0 && b > 0 ) {
			var warmth = r / b;
			var green = g / ( ( r + b ) / 2 );
			var warmAim = warmth < 0.96 ? 0.96 : warmth > 1.4 ? 1.4 : warmth;
			var greenAim = green < 0.96 ? 0.96 : green > 1.04 ? 1.04 : green;
			out.warm = clamp( Math.round( Math.log( warmAim / warmth ) / Math.log( 1.12 / 0.84 ) * 100 * 0.6 ), -22, 22 );
			out.tint = clamp( Math.round( Math.log( green / greenAim ) / -Math.log( 0.88 ) * 100 * 0.6 ), -18, 18 );
		}
		return out;
	}

	root.WHDGrade = {
		ADJUST: ADJUST,
		Grader: Grader,
		cssFilter: cssFilter,
		auto: auto
	};
}( window ) );
