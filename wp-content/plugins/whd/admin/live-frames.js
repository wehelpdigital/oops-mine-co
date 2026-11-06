/**
 * What dresses the live stage: frames, light over the picture, and themes that set them all in one tap.
 *
 * All of it is drawn here in code, so there is no picture to license and it comes out sharp at any size. Nothing is named
 * after a brand, a magazine or a shop other than this one.
 *
 * A frame:  draw( x, W, H, u, o ) runs once and the result is kept, because shadows at 30 fps on a laptop that is also
 *           encoding video are where frames go. live( x, W, H, u, t, c, o ) is optional and runs every frame for what
 *           moves, keeping its own sprites in c. o = { t: colours, words, brand }. u is the short side.
 * A light:  draw( x, W, H, u, t, c, level ) runs every frame, under the frame and the graphics, usually a few sprites made
 *           once and moved. It must leave the context as it found it apart from what the caller saves and restores.
 *
 * Room is left for what else is on the stage: the logo top left and the piece's card bottom left, and in a phone's picture
 * the bottom third is TikTok's own. Every frame stays in the outer edge, so the person in the middle is never covered.
 *
 * @package whd
 */
( function ( root ) {
	'use strict';

	var TAU = Math.PI * 2;
	var SERIF = '"Cormorant Garamond", Georgia, serif';
	var SANS = 'Manrope, "Segoe UI", Arial, sans-serif';
	var MONO = 'Consolas, "Courier New", monospace';

	/* The colours a frame can be worn in. main: the frame. light, deep: its highlight and shade. ink: words on it. */
	var TINTS = {
		rose: { label: 'Rose gold', main: '#b98b7e', light: '#f2d5cb', deep: '#8f6155', ink: '#fff8f4' },
		blush: { label: 'Blush', main: '#f0b4c2', light: '#fde6ec', deep: '#cf7f96', ink: '#6b2f40' },
		cream: { label: 'Cream', main: '#f7f2ed', light: '#ffffff', deep: '#c9b6a6', ink: '#5b4238' },
		gold: { label: 'Gold', main: '#d3ad66', light: '#f8e7bb', deep: '#9a7433', ink: '#33260f' },
		sage: { label: 'Sage', main: '#a5bd9f', light: '#e3eede', deep: '#6f8e6a', ink: '#26382a' },
		sky: { label: 'Sky', main: '#a8cbe4', light: '#e4f1f9', deep: '#6d9cbe', ink: '#1f3b50' },
		lilac: { label: 'Lilac', main: '#c6b2e0', light: '#eee6f8', deep: '#9379b8', ink: '#352750' },
		ink: { label: 'Black', main: '#2b1f1b', light: '#5d4840', deep: '#100b09', ink: '#f7f2ed' }
	};

	function makeCanvas( w, h ) {
		var c = document.createElement( 'canvas' );
		c.width = Math.max( 1, Math.ceil( w ) );
		c.height = Math.max( 1, Math.ceil( h ) );
		return c;
	}

	function roundRect( x, l, t, w, h, r ) {
		r = Math.min( r, w / 2, h / 2 );
		x.beginPath();
		x.moveTo( l + r, t );
		x.arcTo( l + w, t, l + w, t + h, r );
		x.arcTo( l + w, t + h, l, t + h, r );
		x.arcTo( l, t + h, l, t, r );
		x.arcTo( l, t, l + w, t, r );
		x.closePath();
	}

	/* The same numbers every time, so a frame's tile and the frame itself match. */
	function seeded( n ) {
		var s = n;
		return function () {
			s = ( s * 16807 ) % 2147483647;
			return s / 2147483647;
		};
	}

	/* Even steps round a rectangle set in from the edge, each with the way it faces. */
	function around( W, H, inset, gap ) {
		var w = W - inset * 2;
		var h = H - inset * 2;
		var nx = Math.max( 2, Math.round( w / gap ) );
		var ny = Math.max( 2, Math.round( h / gap ) );
		var out = [];
		var i;
		for ( i = 0; i < nx; i++ ) {
			out.push( { x: inset + i * w / nx, y: inset, a: 0 } );
		}
		for ( i = 0; i < ny; i++ ) {
			out.push( { x: W - inset, y: inset + i * h / ny, a: Math.PI / 2 } );
		}
		for ( i = 0; i < nx; i++ ) {
			out.push( { x: W - inset - i * w / nx, y: H - inset, a: Math.PI } );
		}
		for ( i = 0; i < ny; i++ ) {
			out.push( { x: inset, y: H - inset - i * h / ny, a: -Math.PI / 2 } );
		}
		return out;
	}

	/* A rectangle with its corners scooped inwards. */
	function scooped( x, l, t, r, b, n ) {
		x.beginPath();
		x.moveTo( l + n, t );
		x.lineTo( r - n, t );
		x.arc( r, t, n, Math.PI, Math.PI / 2, true );
		x.lineTo( r, b - n );
		x.arc( r, b, n, -Math.PI / 2, Math.PI, true );
		x.lineTo( l + n, b );
		x.arc( l, b, n, 0, -Math.PI / 2, true );
		x.lineTo( l, t + n );
		x.arc( l, t, n, Math.PI / 2, 0, true );
		x.closePath();
	}

	function shadow( x, colour, blur, dy ) {
		x.shadowColor = colour;
		x.shadowBlur = blur;
		x.shadowOffsetY = dy || 0;
	}

	function noShadow( x ) {
		x.shadowColor = 'transparent';
		x.shadowBlur = 0;
		x.shadowOffsetY = 0;
	}

	function foil( x, W, H, t ) {
		var g = x.createLinearGradient( 0, 0, W, H );
		[ 0, 0.22, 0.42, 0.58, 0.78, 1 ].forEach( function ( at, i ) {
			g.addColorStop( at, i % 2 ? t.light : ( 2 === i ? t.main : t.deep ) );
		} );
		return g;
	}

	function letters( x, spacing ) {
		if ( 'letterSpacing' in x ) {
			x.letterSpacing = spacing + 'px';
		}
	}

	/* Words at the largest size up to `size` that fits `room`. */
	function fitFont( x, text, style, family, size, room ) {
		x.font = style + ' ' + size + 'px ' + family;
		var wide = x.measureText( text ).width;
		if ( wide > room ) {
			size = Math.max( 8, size * room / wide );
			x.font = style + ' ' + size + 'px ' + family;
		}
		return size;
	}

	function heart( x, cx, cy, s ) {
		x.beginPath();
		x.moveTo( cx, cy + s * 0.9 );
		x.bezierCurveTo( cx - s * 1.5, cy + s * 0.05, cx - s * 0.75, cy - s * 0.95, cx, cy - s * 0.25 );
		x.bezierCurveTo( cx + s * 0.75, cy - s * 0.95, cx + s * 1.5, cy + s * 0.05, cx, cy + s * 0.9 );
		x.closePath();
	}

	/* A four-pointed glint. */
	function glint( x, cx, cy, r, waist ) {
		x.beginPath();
		for ( var i = 0; i < 8; i++ ) {
			var reach = i % 2 ? r * ( waist || 0.14 ) : r;
			var a = i * Math.PI / 4 - Math.PI / 2;
			x[ i ? 'lineTo' : 'moveTo' ]( cx + Math.cos( a ) * reach, cy + Math.sin( a ) * reach );
		}
		x.closePath();
	}

	function pearl( x, px, py, r, t ) {
		var g = x.createRadialGradient( px - r * 0.35, py - r * 0.42, r * 0.06, px, py, r );
		g.addColorStop( 0, '#ffffff' );
		g.addColorStop( 0.42, t.light );
		g.addColorStop( 0.84, t.main );
		g.addColorStop( 1, t.deep );
		x.fillStyle = g;
		x.beginPath();
		x.arc( px, py, r, 0, TAU );
		x.fill();
	}

	function bow( x, cx, cy, s, rot, t ) {
		x.save();
		x.translate( cx, cy );
		x.rotate( rot );
		shadow( x, 'rgba(40,20,20,.34)', s * 0.14, s * 0.05 );
		[ -1, 1 ].forEach( function ( side ) {
			var tail = x.createLinearGradient( 0, 0, side * s * 0.4, s );
			tail.addColorStop( 0, t.main );
			tail.addColorStop( 1, t.deep );
			x.fillStyle = tail;
			x.beginPath();
			x.moveTo( side * s * 0.02, s * 0.04 );
			x.bezierCurveTo( side * s * 0.18, s * 0.34, side * s * 0.36, s * 0.55, side * s * 0.44, s * 0.98 );
			x.lineTo( side * s * 0.27, s * 0.86 );
			x.lineTo( side * s * 0.12, s * 1.02 );
			x.bezierCurveTo( side * s * 0.12, s * 0.62, side * s * 0.03, s * 0.32, 0, s * 0.12 );
			x.closePath();
			x.fill();
		} );
		[ -1, 1 ].forEach( function ( side ) {
			var loop = x.createLinearGradient( 0, -s * 0.5, 0, s * 0.4 );
			loop.addColorStop( 0, t.light );
			loop.addColorStop( 0.7, t.main );
			loop.addColorStop( 1, t.deep );
			x.fillStyle = loop;
			x.beginPath();
			x.moveTo( 0, 0 );
			x.bezierCurveTo( side * s * 0.34, -s * 0.56, side * s * 1.02, -s * 0.62, side * s * 1, -s * 0.03 );
			x.bezierCurveTo( side * s * 0.98, s * 0.5, side * s * 0.34, s * 0.38, 0, 0 );
			x.closePath();
			x.fill();
			noShadow( x );
			x.strokeStyle = t.deep;
			x.globalAlpha = 0.55;
			x.lineWidth = Math.max( 0.6, s * 0.018 );
			x.lineCap = 'round';
			x.beginPath();
			x.moveTo( side * s * 0.14, -s * 0.02 );
			x.quadraticCurveTo( side * s * 0.46, -s * 0.24, side * s * 0.74, -s * 0.2 );
			x.moveTo( side * s * 0.14, s * 0.03 );
			x.quadraticCurveTo( side * s * 0.42, s * 0.16, side * s * 0.66, s * 0.15 );
			x.stroke();
			x.globalAlpha = 1;
			shadow( x, 'rgba(40,20,20,.34)', s * 0.14, s * 0.05 );
		} );
		noShadow( x );
		var knot = x.createLinearGradient( 0, -s * 0.18, 0, s * 0.18 );
		knot.addColorStop( 0, t.light );
		knot.addColorStop( 1, t.deep );
		x.fillStyle = knot;
		roundRect( x, -s * 0.14, -s * 0.17, s * 0.28, s * 0.34, s * 0.09 );
		x.fill();
		x.restore();
	}

	function daisy( x, cx, cy, r, rot, petal, middle ) {
		x.save();
		x.translate( cx, cy );
		x.rotate( rot );
		shadow( x, 'rgba(30,30,10,.3)', r * 0.3, r * 0.08 );
		x.fillStyle = petal;
		for ( var i = 0; i < 11; i++ ) {
			x.rotate( TAU / 11 );
			x.beginPath();
			x.ellipse( 0, -r * 0.62, r * 0.2, r * 0.42, 0, 0, TAU );
			x.fill();
		}
		noShadow( x );
		var g = x.createRadialGradient( -r * 0.08, -r * 0.08, 0, 0, 0, r * 0.3 );
		g.addColorStop( 0, '#ffe58a' );
		g.addColorStop( 1, middle );
		x.fillStyle = g;
		x.beginPath();
		x.arc( 0, 0, r * 0.28, 0, TAU );
		x.fill();
		x.restore();
	}

	function leaf( x, px, py, a, len ) {
		x.save();
		x.translate( px, py );
		x.rotate( a );
		x.beginPath();
		x.moveTo( 0, 0 );
		x.quadraticCurveTo( len * 0.5, -len * 0.3, len, 0 );
		x.quadraticCurveTo( len * 0.5, len * 0.3, 0, 0 );
		x.closePath();
		x.fill();
		x.stroke();
		x.beginPath();
		x.moveTo( 0, 0 );
		x.lineTo( len * 0.84, 0 );
		x.stroke();
		x.restore();
	}

	/* A curved stem from a to b, bending towards c, with leaves down both sides. */
	function sprig( x, ax, ay, cx, cy, bx, by, n, len ) {
		x.beginPath();
		x.moveTo( ax, ay );
		x.quadraticCurveTo( cx, cy, bx, by );
		x.stroke();
		for ( var i = 1; i <= n; i++ ) {
			var t = i / ( n + 0.6 );
			var m = 1 - t;
			var px = m * m * ax + 2 * m * t * cx + t * t * bx;
			var py = m * m * ay + 2 * m * t * cy + t * t * by;
			var a = Math.atan2( 2 * m * ( cy - ay ) + 2 * t * ( by - cy ), 2 * m * ( cx - ax ) + 2 * t * ( bx - cx ) );
			leaf( x, px, py, a + ( i % 2 ? 0.75 : -0.75 ), len * ( 1 - t * 0.5 ) );
		}
		leaf( x, bx, by, Math.atan2( by - cy, bx - cx ), len * 0.5 );
	}

	/* A palm frond: leaflets down both sides of a drooping rib, longest in the middle. */
	function frond( x, ax, ay, a, len ) {
		x.save();
		x.translate( ax, ay );
		x.rotate( a );
		var n = 20;
		for ( var i = 0; i <= n; i++ ) {
			var t = i / n;
			var px = len * t;
			var py = len * 0.2 * t * t;
			var size = len * 0.36 * Math.pow( Math.sin( Math.PI * ( 0.1 + 0.88 * t ) ), 0.8 );
			var w = size * 0.075;
			[ -1, 1 ].forEach( function ( side ) {
				var la = side * ( 1.1 - 0.62 * t ) + t * 0.32;
				var ex = px + Math.cos( la ) * size;
				var ey = py + Math.sin( la ) * size;
				var mx = px + Math.cos( la ) * size * 0.5;
				var my = py + Math.sin( la ) * size * 0.5;
				x.beginPath();
				x.moveTo( px, py );
				x.quadraticCurveTo( mx - Math.sin( la ) * w, my + Math.cos( la ) * w, ex, ey );
				x.quadraticCurveTo( mx + Math.sin( la ) * w, my - Math.cos( la ) * w, px, py );
				x.fill();
			} );
		}
		x.lineWidth = Math.max( 1, len * 0.012 );
		x.strokeStyle = x.fillStyle;
		x.beginPath();
		x.moveTo( 0, 0 );
		x.quadraticCurveTo( len * 0.5, 0, len, len * 0.2 );
		x.stroke();
		x.restore();
	}

	/* A soft round of light, used for glows and bulbs. */
	function glowSprite( size, colour ) {
		var c = makeCanvas( size, size );
		var x = c.getContext( '2d' );
		var g = x.createRadialGradient( size / 2, size / 2, 0, size / 2, size / 2, size / 2 );
		g.addColorStop( 0, colour );
		g.addColorStop( 0.3, colour.replace( /[\d.]+\)$/, function ( a ) {
			return ( parseFloat( a ) * 0.45 ) + ')';
		} ) );
		g.addColorStop( 1, colour.replace( /[\d.]+\)$/, '0)' ) );
		x.fillStyle = g;
		x.fillRect( 0, 0, size, size );
		return c;
	}

	/* ─────────────────────────── frames ─────────────────────────── */

	var FRAME_GROUPS = {
		clean: 'Clean',
		editorial: 'Magazine and film',
		sweet: 'Sweet',
		garden: 'Garden',
		glam: 'Glam and party'
	};

	/* Bulbs and their places, shared by the frames that twinkle. */
	function twinkleBulbs( x, list, sprite, size, t, speed, floor ) {
		x.globalCompositeOperation = 'lighter';
		for ( var i = 0; i < list.length; i++ ) {
			var p = list[ i ];
			x.globalAlpha = floor + ( 1 - floor ) * ( 0.5 + 0.5 * Math.sin( t * speed + i * 0.9 ) );
			x.drawImage( sprite, p.x - size / 2, p.y - size / 2, size, size );
		}
		x.globalAlpha = 1;
		x.globalCompositeOperation = 'source-over';
	}

	function fairyBulbs( W, H, u ) {
		var list = [];
		var swags = H > W ? 3 : 5;
		var drop = u * 0.075;
		var each = W / swags;
		for ( var s = 0; s < swags; s++ ) {
			for ( var i = 0; i < 6; i++ ) {
				var t = ( i + 0.5 ) / 6;
				list.push( { x: ( s + t ) * each, y: u * 0.012 + 4 * drop * t * ( 1 - t ) + u * 0.016 } );
			}
		}
		return list;
	}

	var FRAMES = {
		none: { label: 'None', group: 'clean' },

		rose: {
			label: 'Rose line',
			group: 'clean',
			draw: function ( x, W, H, u ) {
				x.strokeStyle = '#b98b7e';
				x.lineWidth = u * 0.008;
				x.strokeRect( u * 0.025, u * 0.025, W - u * 0.05, H - u * 0.05 );
				x.strokeStyle = 'rgba(255,255,255,.5)';
				x.lineWidth = 1.5;
				x.strokeRect( u * 0.04, u * 0.04, W - u * 0.08, H - u * 0.08 );
			}
		},

		cream: {
			label: 'Cream edge',
			group: 'clean',
			draw: function ( x, W, H, u ) {
				x.strokeStyle = '#f7f2ed';
				x.lineWidth = u * 0.05;
				x.strokeRect( 0, 0, W, H );
			}
		},

		vignette: {
			label: 'Vignette',
			group: 'clean',
			draw: function ( x, W, H, u ) {
				var g = x.createRadialGradient( W / 2, H / 2, u * 0.4, W / 2, H / 2, Math.max( W, H ) * 0.72 );
				g.addColorStop( 0, 'rgba(20,12,10,0)' );
				g.addColorStop( 1, 'rgba(20,12,10,.62)' );
				x.fillStyle = g;
				x.fillRect( 0, 0, W, H );
			}
		},

		soft: {
			label: 'Soft window',
			group: 'clean',
			tint: 'cream',
			draw: function ( x, W, H, u, o ) {
				var b = u * 0.036;
				var r = u * 0.07;
				x.fillStyle = o.t.main;
				x.fillRect( 0, 0, W, H );
				x.globalCompositeOperation = 'destination-out';
				roundRect( x, b, b, W - b * 2, H - b * 2, r );
				x.fill();
				x.globalCompositeOperation = 'source-over';
				x.save();
				roundRect( x, b, b, W - b * 2, H - b * 2, r );
				x.clip();
				shadow( x, 'rgba(30,18,14,.42)', u * 0.03, 0 );
				x.strokeStyle = 'rgba(30,18,14,.4)';
				x.lineWidth = 2;
				roundRect( x, b, b, W - b * 2, H - b * 2, r );
				x.stroke();
				x.restore();
			}
		},

		arch: {
			label: 'Arch',
			group: 'clean',
			tint: 'cream',
			draw: function ( x, W, H, u, o ) {
				var side = u * 0.05;
				var r = Math.min( ( W - side * 2 ) / 2, H * 0.62 );
				var arch = function ( inset ) {
					var l = side + inset;
					var w = W - ( side + inset ) * 2;
					var top = side + inset;
					var bottom = H - side - inset;
					var rr = r - inset;
					x.beginPath();
					x.moveTo( l, bottom );
					x.lineTo( l, top + rr );
					x.arcTo( l, top, l + rr, top, rr );
					x.lineTo( l + w - rr, top );
					x.arcTo( l + w, top, l + w, top + rr, rr );
					x.lineTo( l + w, bottom );
					x.closePath();
				};
				x.fillStyle = o.t.main;
				x.fillRect( 0, 0, W, H );
				x.globalCompositeOperation = 'destination-out';
				arch( 0 );
				x.fill();
				x.globalCompositeOperation = 'source-over';
				x.strokeStyle = o.t.deep;
				x.lineWidth = Math.max( 1.2, u * 0.0032 );
				arch( -u * 0.012 );
				x.stroke();
			}
		},

		gilded: {
			label: 'Gilded',
			group: 'clean',
			tint: 'gold',
			draw: function ( x, W, H, u, o ) {
				var m = u * 0.028;
				shadow( x, 'rgba(0,0,0,.4)', u * 0.008, 0 );
				x.strokeStyle = foil( x, W, H, o.t );
				x.lineWidth = u * 0.0075;
				scooped( x, m, m, W - m, H - m, u * 0.045 );
				x.stroke();
				var i = m + u * 0.014;
				x.lineWidth = Math.max( 1, u * 0.002 );
				scooped( x, i, i, W - i, H - i, u * 0.045 );
				x.stroke();
				x.fillStyle = foil( x, W, H, o.t );
				[ [ W / 2, m ], [ W / 2, H - m ], [ m, H / 2 ], [ W - m, H / 2 ] ].forEach( function ( p ) {
					glint( x, p[ 0 ], p[ 1 ], u * 0.024, 0.32 );
					x.fill();
				} );
				[ [ m + u * 0.018, m + u * 0.018 ], [ W - m - u * 0.018, m + u * 0.018 ], [ m + u * 0.018, H - m - u * 0.018 ], [ W - m - u * 0.018, H - m - u * 0.018 ] ].forEach( function ( p ) {
					x.beginPath();
					x.arc( p[ 0 ], p[ 1 ], u * 0.0055, 0, TAU );
					x.fill();
				} );
			}
		},

		atelier: {
			label: 'Atelier',
			group: 'clean',
			tint: 'cream',
			words: true,
			draw: function ( x, W, H, u, o ) {
				var m = u * 0.03;
				shadow( x, 'rgba(0,0,0,.45)', u * 0.008, 0 );
				x.strokeStyle = o.t.main;
				x.lineWidth = Math.max( 1.4, u * 0.0028 );
				x.strokeRect( m, m, W - m * 2, H - m * 2 );
				var i = m + u * 0.012;
				x.lineWidth = Math.max( 1, u * 0.0016 );
				scooped( x, i, i, W - i, H - i, u * 0.03 );
				x.stroke();
				noShadow( x );
				if ( o.words ) {
					var size = u * 0.024;
					letters( x, size * 0.32 );
					var label = String( o.words ).toUpperCase();
					fitFont( x, label, '600', SANS, size, W * 0.5 );
					var wide = x.measureText( label ).width + size * 2.4;
					x.clearRect( ( W - wide ) / 2, H - i - u * 0.012, wide, u * 0.04 );
					x.clearRect( ( W - wide ) / 2, H - m - u * 0.012, wide, u * 0.02 );
					shadow( x, 'rgba(0,0,0,.6)', u * 0.008, 0 );
					x.fillStyle = o.t.main;
					x.textAlign = 'center';
					x.textBaseline = 'middle';
					x.fillText( label, W / 2, H - m - u * 0.006 );
					letters( x, 0 );
				}
			}
		},

		cover: {
			label: 'Magazine cover',
			group: 'editorial',
			tint: 'cream',
			words: true,
			draw: function ( x, W, H, u, o ) {
				var words = String( o.words || o.brand || '' );
				var m = u * 0.04;
				var size = fitFont( x, words, '500', SERIF, H > W ? W * 0.17 : H * 0.2, W - m * 2 );
				var top = u * 0.012;
				var shade = x.createLinearGradient( 0, 0, 0, size * 1.5 );
				shade.addColorStop( 0, 'rgba(30,20,15,.5)' );
				shade.addColorStop( 1, 'rgba(30,20,15,0)' );
				x.fillStyle = shade;
				x.fillRect( 0, 0, W, size * 1.5 );
				shadow( x, 'rgba(0,0,0,.38)', u * 0.016, u * 0.004 );
				x.fillStyle = o.t.main;
				x.textAlign = 'center';
				x.textBaseline = 'top';
				x.fillText( words, W / 2, top );
				var line = top + size * 1.04;
				x.fillRect( m, line, W - m * 2, Math.max( 1.2, u * 0.0028 ) );
				var small = u * 0.021;
				letters( x, small * 0.26 );
				x.font = '700 ' + small + 'px ' + SANS;
				x.textBaseline = 'top';
				x.textAlign = 'left';
				x.fillText( 'THE LIVE EDIT', m, line + u * 0.012 );
				x.textAlign = 'right';
				x.fillText( new Date().toLocaleDateString( 'en', { month: 'long', year: 'numeric' } ).toUpperCase(), W - m, line + u * 0.012 );
				letters( x, 0 );
			}
		},

		polaroid: {
			label: 'Instant photo',
			group: 'editorial',
			tint: 'cream',
			words: true,
			draw: function ( x, W, H, u, o ) {
				var side = u * 0.034;
				var foot = u * 0.13;
				x.fillStyle = o.t.main;
				x.beginPath();
				x.rect( 0, 0, W, H );
				x.rect( side, side, W - side * 2, H - side - foot );
				x.fill( 'evenodd' );
				x.save();
				x.beginPath();
				x.rect( side, side, W - side * 2, H - side - foot );
				x.clip();
				shadow( x, 'rgba(0,0,0,.55)', u * 0.014, 0 );
				x.strokeStyle = 'rgba(0,0,0,.45)';
				x.lineWidth = 1.5;
				x.strokeRect( side, side, W - side * 2, H - side - foot );
				x.restore();
				noShadow( x );
				if ( o.words ) {
					fitFont( x, o.words, 'italic 500', SERIF, u * 0.062, W * 0.7 );
					x.fillStyle = o.t.ink;
					x.textAlign = 'center';
					x.textBaseline = 'middle';
					x.fillText( o.words, W / 2, H - foot / 2 );
				}
			}
		},

		film: {
			label: 'Film strip',
			group: 'editorial',
			draw: function ( x, W, H, u ) {
				var tall = H > W;
				var band = u * 0.075;
				x.fillStyle = '#14100e';
				if ( tall ) {
					x.fillRect( 0, 0, band, H );
					x.fillRect( W - band, 0, band, H );
				} else {
					x.fillRect( 0, 0, W, band );
					x.fillRect( 0, H - band, W, band );
				}
				var hole = u * 0.03;
				var gap = u * 0.062;
				var run = tall ? H : W;
				var count = Math.floor( run / gap );
				var start = ( run - ( count - 1 ) * gap ) / 2;
				x.fillStyle = '#efe6da';
				for ( var i = 0; i < count; i++ ) {
					var along = start + i * gap - hole / 2;
					[ band * 0.2, null ].forEach( function ( at ) {
						var near = null === at ? ( tall ? W : H ) - band * 0.2 - hole * 0.82 : at;
						if ( tall ) {
							roundRect( x, near, along, hole * 0.82, hole, hole * 0.2 );
						} else {
							roundRect( x, along, near, hole, hole * 0.82, hole * 0.2 );
						}
						x.fill();
					} );
				}
				var small = u * 0.014;
				x.font = '700 ' + small + 'px ' + MONO;
				x.fillStyle = '#f0a33a';
				x.textBaseline = 'middle';
				x.textAlign = 'left';
				if ( ! tall ) {
					[ 0.08, 0.34, 0.6, 0.86 ].forEach( function ( at, i ) {
						x.fillText( 'OOPS 400   ▸ ' + ( 12 + i * 2 ) + 'A', W * at - u * 0.06, band * 0.88 );
						x.fillText( ( 12 + i * 2 ), W * at, H - band * 0.88 );
					} );
				} else {
					[ 0.1, 0.36, 0.62, 0.88 ].forEach( function ( at, i ) {
						x.save();
						x.translate( band * 0.86, H * at );
						x.rotate( Math.PI / 2 );
						x.fillText( 'OOPS 400 ▸ ' + ( 12 + i * 2 ) + 'A', 0, 0 );
						x.restore();
					} );
				}
			}
		},

		cinema: {
			label: 'Cinema',
			group: 'editorial',
			draw: function ( x, W, H ) {
				x.fillStyle = '#000';
				var bar = H > W ? H * 0.08 : H * 0.1;
				x.fillRect( 0, 0, W, bar );
				x.fillRect( 0, H - bar, W, bar );
			}
		},

		viewfinder: {
			label: 'Camcorder',
			group: 'editorial',
			draw: function ( x, W, H, u ) {
				var m = u * 0.05;
				var arm = u * 0.09;
				shadow( x, 'rgba(0,0,0,.5)', u * 0.006, 0 );
				x.strokeStyle = '#ffffff';
				x.lineWidth = Math.max( 2, u * 0.004 );
				x.lineCap = 'square';
				x.beginPath();
				[ [ m, m, 1, 1 ], [ W - m, m, -1, 1 ], [ m, H - m, 1, -1 ], [ W - m, H - m, -1, -1 ] ].forEach( function ( p ) {
					x.moveTo( p[ 0 ] + arm * p[ 2 ], p[ 1 ] );
					x.lineTo( p[ 0 ], p[ 1 ] );
					x.lineTo( p[ 0 ], p[ 1 ] + arm * p[ 3 ] );
				} );
				var c = u * 0.018;
				x.moveTo( W / 2 - c, H / 2 );
				x.lineTo( W / 2 + c, H / 2 );
				x.moveTo( W / 2, H / 2 - c );
				x.lineTo( W / 2, H / 2 + c );
				x.stroke();
				// The battery, bottom right, above the date.
				var bw = u * 0.05;
				var bh = u * 0.024;
				var bx = W - m - u * 0.03 - bw;
				var by = m + u * 0.075;
				x.lineWidth = Math.max( 1.2, u * 0.0026 );
				x.strokeRect( bx, by, bw, bh );
				x.fillStyle = '#fff';
				x.fillRect( bx + bw, by + bh * 0.28, bh * 0.2, bh * 0.44 );
				x.fillRect( bx + bh * 0.2, by + bh * 0.2, bw * 0.58, bh * 0.6 );
				x.font = '700 ' + ( u * 0.03 ) + 'px ' + MONO;
				x.textAlign = 'right';
				x.textBaseline = 'alphabetic';
				x.fillStyle = '#ffb347';
				x.fillText( new Date().toLocaleDateString( 'en', { month: 'short', day: '2-digit', year: 'numeric' } ).toUpperCase(), W - m - u * 0.03, H - m - u * 0.03 );
			},
			live: function ( x, W, H, u, t ) {
				var m = u * 0.05;
				var size = u * 0.032;
				x.font = '700 ' + size + 'px ' + MONO;
				x.textBaseline = 'middle';
				x.textAlign = 'right';
				var y = m + u * 0.045;
				var secs = Math.floor( t );
				var pad = function ( n ) {
					return ( n < 10 ? '0' : '' ) + n;
				};
				var time = pad( Math.floor( secs / 3600 ) ) + ':' + pad( Math.floor( secs / 60 ) % 60 ) + ':' + pad( secs % 60 );
				var right = W - m - u * 0.03;
				x.fillStyle = 'rgba(0,0,0,.5)';
				x.fillText( 'REC  ' + time, right + 1.5, y + 1.5 );
				x.fillStyle = '#fff';
				x.fillText( 'REC  ' + time, right, y );
				if ( 0 === Math.floor( t * 1.4 ) % 2 ) {
					x.fillStyle = '#ff2d2d';
					x.beginPath();
					x.arc( right - x.measureText( 'REC  ' + time ).width - size * 0.6, y, size * 0.3, 0, TAU );
					x.fill();
				}
			}
		},

		tape: {
			label: 'Live tape',
			group: 'editorial',
			tint: 'ink',
			words: true,
			draw: function ( x, W, H, u, o ) {
				var h = u * 0.062;
				var reach = u * 0.17;
				var size = h * 0.42;
				var phrase = 'LIVE NOW  •  ' + ( o.words ? String( o.words ).toUpperCase() + '  •  ' : '' );
				[ [ W - reach, reach, Math.PI / 4 ], [ reach, H - reach, Math.PI / 4 ] ].forEach( function ( p ) {
					x.save();
					x.translate( p[ 0 ], p[ 1 ] );
					x.rotate( p[ 2 ] );
					var half = u * 0.42;
					shadow( x, 'rgba(0,0,0,.42)', u * 0.016, u * 0.004 );
					x.fillStyle = o.t.main;
					x.fillRect( -half, -h / 2, half * 2, h );
					noShadow( x );
					x.fillStyle = o.t.ink;
					x.font = '800 ' + size + 'px ' + SANS;
					letters( x, size * 0.12 );
					x.textAlign = 'left';
					x.textBaseline = 'middle';
					var wide = Math.max( 10, x.measureText( phrase ).width );
					for ( var tx = -half - wide * 0.3; tx < half; tx += wide ) {
						x.fillText( phrase, tx, 0 );
					}
					letters( x, 0 );
					x.restore();
				} );
			}
		},

		pearls: {
			label: 'Pearls',
			group: 'sweet',
			tint: 'cream',
			draw: function ( x, W, H, u, o ) {
				var r = u * 0.0135;
				around( W, H, u * 0.03, r * 2.25 ).forEach( function ( p, i ) {
					var big = 0 === i % 6;
					shadow( x, 'rgba(20,10,5,.36)', r * 0.6, r * 0.2 );
					pearl( x, p.x, p.y, big ? r * 1.32 : r, o.t );
				} );
				noShadow( x );
			}
		},

		bows: {
			label: 'Bows',
			group: 'sweet',
			tint: 'blush',
			draw: function ( x, W, H, u, o ) {
				var m = u * 0.03;
				shadow( x, 'rgba(40,20,20,.28)', u * 0.008, 0 );
				x.strokeStyle = o.t.main;
				x.lineWidth = u * 0.011;
				x.strokeRect( m, m, W - m * 2, H - m * 2 );
				x.strokeStyle = o.t.light;
				x.lineWidth = Math.max( 1, u * 0.0024 );
				x.strokeRect( m, m, W - m * 2, H - m * 2 );
				noShadow( x );
				var s = u * 0.066;
				var at = u * 0.066;
				bow( x, at, at, s, -0.32, o.t );
				bow( x, W - at, at, s, 0.32, o.t );
				bow( x, at, H - at * 1.5, s * 0.82, -0.2, o.t );
				bow( x, W - at, H - at * 1.5, s * 0.82, 0.2, o.t );
			}
		},

		scallop: {
			label: 'Scallop',
			group: 'sweet',
			tint: 'blush',
			draw: function ( x, W, H, u, o ) {
				var band = u * 0.016;
				var r = u * 0.024;
				var list = around( W, H, band, r * 2 );
				shadow( x, 'rgba(40,20,20,.3)', u * 0.012, u * 0.002 );
				x.fillStyle = o.t.main;
				x.beginPath();
				x.rect( 0, 0, W, band );
				x.rect( 0, H - band, W, band );
				x.rect( 0, band, band, H - band * 2 );
				x.rect( W - band, band, band, H - band * 2 );
				list.forEach( function ( p ) {
					x.moveTo( p.x + r, p.y );
					x.arc( p.x, p.y, r, 0, TAU );
				} );
				x.fill();
				noShadow( x );
				x.fillStyle = o.t.light;
				list.forEach( function ( p ) {
					var dx = Math.sin( p.a ) * -r * 0.42;
					var dy = Math.cos( p.a ) * r * 0.42;
					x.beginPath();
					x.arc( p.x + dx, p.y + dy, r * 0.17, 0, TAU );
					x.fill();
				} );
			}
		},

		lace: {
			label: 'Lace',
			group: 'sweet',
			tint: 'cream',
			draw: function ( x, W, H, u, o ) {
				var band = u * 0.014;
				var r = u * 0.03;
				var list = around( W, H, band, r * 1.86 );
				shadow( x, 'rgba(20,10,5,.3)', u * 0.008, 0 );
				x.fillStyle = o.t.main;
				x.beginPath();
				x.rect( 0, 0, W, band );
				x.rect( 0, H - band, W, band );
				x.rect( 0, band, band, H - band * 2 );
				x.rect( W - band, band, band, H - band * 2 );
				list.forEach( function ( p ) {
					x.moveTo( p.x + r, p.y );
					x.arc( p.x, p.y, r, 0, TAU );
				} );
				x.fill();
				// The tiny picot round each curve.
				list.forEach( function ( p ) {
					for ( var k = -2; k <= 2; k++ ) {
						var a = p.a + Math.PI / 2 + k * 0.5;
						x.beginPath();
						x.arc( p.x + Math.cos( a ) * r * 1.04, p.y + Math.sin( a ) * r * 1.04, r * 0.11, 0, TAU );
						x.fill();
					}
				} );
				noShadow( x );
				// The holes that make it lace.
				x.globalCompositeOperation = 'destination-out';
				list.forEach( function ( p ) {
					var dir = p.a + Math.PI / 2;
					for ( var k = -2; k <= 2; k++ ) {
						var a = dir + k * 0.52;
						x.beginPath();
						x.arc( p.x + Math.cos( a ) * r * 0.66, p.y + Math.sin( a ) * r * 0.66, r * 0.12, 0, TAU );
						x.fill();
					}
					x.beginPath();
					x.arc( p.x + Math.cos( dir ) * r * 0.16, p.y + Math.sin( dir ) * r * 0.16, r * 0.18, 0, TAU );
					x.fill();
				} );
				x.globalCompositeOperation = 'source-over';
			}
		},

		gingham: {
			label: 'Gingham',
			group: 'sweet',
			tint: 'blush',
			draw: function ( x, W, H, u, o ) {
				var b = u * 0.05;
				var s = b / 4;
				x.save();
				x.beginPath();
				x.rect( 0, 0, W, H );
				x.rect( b, b, W - b * 2, H - b * 2 );
				x.clip( 'evenodd' );
				x.fillStyle = '#ffffff';
				x.fillRect( 0, 0, W, H );
				x.fillStyle = o.t.main;
				x.globalAlpha = 0.5;
				var n;
				for ( n = 0; n * s * 2 < W; n++ ) {
					x.fillRect( n * s * 2, 0, s, H );
				}
				for ( n = 0; n * s * 2 < H; n++ ) {
					x.fillRect( 0, n * s * 2, W, s );
				}
				x.restore();
				x.save();
				x.strokeStyle = o.t.deep;
				x.lineWidth = Math.max( 1.4, u * 0.003 );
				x.setLineDash( [ u * 0.012, u * 0.009 ] );
				x.strokeRect( b * 0.5, b * 0.5, W - b, H - b );
				x.restore();
				shadow( x, 'rgba(40,20,20,.34)', u * 0.012, 0 );
				x.strokeStyle = o.t.deep;
				x.lineWidth = 1.5;
				x.strokeRect( b, b, W - b * 2, H - b * 2 );
			}
		},

		hearts: {
			label: 'Hearts',
			group: 'sweet',
			tint: 'blush',
			draw: function ( x, W, H, u, o ) {
				var s = u * 0.016;
				var rnd = seeded( 31 );
				around( W, H, u * 0.032, u * 0.066 ).forEach( function ( p, i ) {
					shadow( x, 'rgba(60,10,30,.32)', s * 0.7, s * 0.14 );
					x.fillStyle = i % 2 ? o.t.light : o.t.main;
					x.save();
					x.translate( p.x, p.y );
					x.rotate( ( rnd() - 0.5 ) * 0.6 );
					heart( x, 0, 0, i % 2 ? s * 0.78 : s );
					x.fill();
					x.restore();
				} );
			}
		},

		washi: {
			label: 'Scrapbook',
			group: 'sweet',
			draw: function ( x, W, H, u ) {
				var strips = [
					[ u * 0.11, u * 0.11, -Math.PI / 4, '#f0b4c2', 'stripes' ],
					[ W - u * 0.11, u * 0.11, Math.PI / 4, '#a5bd9f', 'dots' ],
					[ u * 0.11, H - u * 0.11, Math.PI / 4, '#e7cf94', 'dots' ],
					[ W - u * 0.11, H - u * 0.11, -Math.PI / 4, '#c6b2e0', 'stripes' ]
				];
				var len = u * 0.34;
				var h = u * 0.07;
				strips.forEach( function ( s ) {
					x.save();
					x.translate( s[ 0 ], s[ 1 ] );
					x.rotate( s[ 2 ] );
					x.beginPath();
					var teeth = 6;
					x.moveTo( -len / 2, -h / 2 );
					x.lineTo( len / 2, -h / 2 );
					for ( var i = 1; i <= teeth; i++ ) {
						x.lineTo( len / 2 + ( i % 2 ? -h * 0.05 : 0 ), -h / 2 + h * i / teeth );
					}
					x.lineTo( -len / 2, h / 2 );
					for ( var k = 1; k <= teeth; k++ ) {
						x.lineTo( -len / 2 + ( k % 2 ? h * 0.05 : 0 ), h / 2 - h * k / teeth );
					}
					x.closePath();
					shadow( x, 'rgba(30,20,10,.28)', u * 0.01, u * 0.003 );
					x.globalAlpha = 0.9;
					x.fillStyle = s[ 3 ];
					x.fill();
					noShadow( x );
					x.clip();
					x.fillStyle = 'rgba(255,255,255,.55)';
					var n;
					if ( 'stripes' === s[ 4 ] ) {
						for ( n = -len; n < len; n += h * 0.42 ) {
							x.beginPath();
							x.moveTo( n, -h / 2 );
							x.lineTo( n + h * 0.2, -h / 2 );
							x.lineTo( n + h * 0.2 + h * 0.5, h / 2 );
							x.lineTo( n + h * 0.5, h / 2 );
							x.closePath();
							x.fill();
						}
					} else {
						for ( n = -len / 2; n < len / 2; n += h * 0.42 ) {
							[ -0.22, 0.22 ].forEach( function ( row, r ) {
								x.beginPath();
								x.arc( n + ( r ? h * 0.21 : 0 ), row * h, h * 0.07, 0, TAU );
								x.fill();
							} );
						}
					}
					x.restore();
				} );
			}
		},

		daisies: {
			label: 'Daisy chain',
			group: 'garden',
			draw: function ( x, W, H, u ) {
				var rnd = seeded( 7 );
				var greens = [ '#7f9d6d', '#94b27f' ];
				x.lineWidth = 1;
				around( W, H, u * 0.034, u * 0.082 ).forEach( function ( p, i ) {
					x.fillStyle = greens[ i % 2 ];
					x.strokeStyle = 'rgba(40,70,30,.5)';
					leaf( x, p.x, p.y, p.a + 0.5 + rnd(), u * 0.04 );
					leaf( x, p.x, p.y, p.a + 3.4 + rnd(), u * 0.034 );
				} );
				around( W, H, u * 0.034, u * 0.082 ).forEach( function ( p, i ) {
					var small = 2 === i % 3;
					daisy( x, p.x, p.y, u * ( small ? 0.02 : 0.031 + rnd() * 0.007 ), rnd() * TAU, small ? '#fbd5de' : '#ffffff', '#eeb030' );
				} );
			}
		},

		botanical: {
			label: 'Line leaves',
			group: 'garden',
			tint: 'sage',
			draw: function ( x, W, H, u, o ) {
				x.fillStyle = o.t.light;
				x.strokeStyle = o.t.deep;
				x.lineWidth = Math.max( 1.2, u * 0.0028 );
				x.lineCap = 'round';
				shadow( x, 'rgba(0,0,0,.28)', u * 0.008, u * 0.002 );
				var a = u * 0.34;
				var len = u * 0.09;
				sprig( x, W - u * 0.012, u * 0.012, W - a * 0.24, a * 0.08, W - a * 1.1, u * 0.05, 7, len );
				sprig( x, W - u * 0.012, u * 0.012, W - a * 0.08, a * 0.24, W - u * 0.05, a * 1.05, 7, len );
				sprig( x, u * 0.012, H - u * 0.012, a * 0.24, H - a * 0.08, a * 1.1, H - u * 0.05, 7, len );
				sprig( x, u * 0.012, H - u * 0.012, a * 0.08, H - a * 0.24, u * 0.05, H - a * 1.05, 7, len );
			}
		},

		palms: {
			label: 'Palm leaves',
			group: 'garden',
			draw: function ( x, W, H, u ) {
				var reach = u * 0.46;
				var set = function ( cx, cy, angles, colours ) {
					angles.forEach( function ( a, i ) {
						shadow( x, 'rgba(0,20,10,.4)', u * 0.018, u * 0.006 );
						x.fillStyle = colours[ i % colours.length ];
						frond( x, cx, cy, a, reach * ( 1 - i * 0.07 ) );
					} );
					noShadow( x );
				};
				var greens = [ '#1f4d33', '#2e6a44', '#17402a', '#3a7a4f' ];
				set( W + u * 0.02, -u * 0.02, [ 2.75, 2.25, 1.78, 2.5, 2.0 ], greens );
				set( -u * 0.02, H + u * 0.02, [ -0.45, -0.95, -1.42, -0.7, -1.2 ], greens );
			}
		},

		neon: {
			label: 'Neon',
			group: 'glam',
			draw: function ( x, W, H, u ) {
				var m = u * 0.034;
				var r = u * 0.05;
				shadow( x, '#ff3d9a', u * 0.02, 0 );
				x.strokeStyle = '#ffd0e6';
				x.lineWidth = u * 0.005;
				roundRect( x, m, m, W - m * 2, H - m * 2, r );
				x.stroke();
				x.stroke();
			},
			live: function ( x, W, H, u, t, c ) {
				if ( ! c.glow ) {
					c.glow = makeCanvas( W, H );
					var g = c.glow.getContext( '2d' );
					var m = u * 0.034;
					shadow( g, '#ff2a90', u * 0.026, 0 );
					g.strokeStyle = 'rgba(255,70,160,.8)';
					g.lineWidth = u * 0.006;
					roundRect( g, m, m, W - m * 2, H - m * 2, u * 0.05 );
					g.stroke();
					g.stroke();
				}
				// A slow breath, with the little flicker a real tube has.
				var flicker = Math.sin( t * 17 ) * Math.sin( t * 5.3 ) > 0.86 ? 0.45 : 1;
				x.globalAlpha = ( 0.5 + 0.3 * Math.sin( t * 1.5 ) ) * flicker;
				x.drawImage( c.glow, 0, 0 );
				x.globalAlpha = 1;
				x.globalCompositeOperation = 'source-over';
			}
		},

		bulbs: {
			label: 'Dressing room',
			group: 'glam',
			draw: function ( x, W, H, u ) {
				var r = u * 0.0145;
				var inset = u * 0.034;
				x.fillStyle = '#c9a15c';
				x.beginPath();
				x.rect( 0, 0, W, u * 0.012 );
				x.rect( 0, H - u * 0.012, W, u * 0.012 );
				x.rect( 0, 0, u * 0.012, H );
				x.rect( W - u * 0.012, 0, u * 0.012, H );
				x.fill();
				around( W, H, inset, u * 0.1 ).forEach( function ( p ) {
					shadow( x, 'rgba(255,190,110,.9)', r * 1.6, 0 );
					var g = x.createRadialGradient( p.x, p.y, 0, p.x, p.y, r );
					g.addColorStop( 0, '#fffdf4' );
					g.addColorStop( 0.7, '#ffeec6' );
					g.addColorStop( 1, '#f4cf8e' );
					x.fillStyle = g;
					x.beginPath();
					x.arc( p.x, p.y, r, 0, TAU );
					x.fill();
				} );
			},
			live: function ( x, W, H, u, t, c ) {
				if ( ! c.list ) {
					c.list = around( W, H, u * 0.034, u * 0.1 );
					c.size = u * 0.075;
					c.sprite = glowSprite( 96, 'rgba(255,205,135,.55)' );
				}
				twinkleBulbs( x, c.list, c.sprite, c.size, t, 1.3, 0.4 );
			}
		},

		fairy: {
			label: 'Fairy lights',
			group: 'glam',
			draw: function ( x, W, H, u ) {
				var swags = H > W ? 3 : 5;
				var each = W / swags;
				var drop = u * 0.075;
				x.strokeStyle = '#3c4a34';
				x.lineWidth = Math.max( 1.2, u * 0.0028 );
				shadow( x, 'rgba(0,0,0,.4)', u * 0.006, u * 0.002 );
				x.beginPath();
				for ( var s = 0; s < swags; s++ ) {
					x.moveTo( s * each, u * 0.012 );
					x.quadraticCurveTo( ( s + 0.5 ) * each, u * 0.012 + drop * 2, ( s + 1 ) * each, u * 0.012 );
				}
				x.stroke();
				noShadow( x );
				fairyBulbs( W, H, u ).forEach( function ( p, i ) {
					x.fillStyle = '#fff1c9';
					x.beginPath();
					x.ellipse( p.x, p.y, u * 0.0065, u * 0.0095, 0, 0, TAU );
					x.fill();
					x.fillStyle = 0 === i % 3 ? '#ffc56b' : '#fff8e3';
					x.beginPath();
					x.arc( p.x, p.y, u * 0.0036, 0, TAU );
					x.fill();
				} );
			},
			live: function ( x, W, H, u, t, c ) {
				if ( ! c.list ) {
					c.list = fairyBulbs( W, H, u );
					c.size = u * 0.075;
					c.sprite = glowSprite( 80, 'rgba(255,205,120,.9)' );
				}
				twinkleBulbs( x, c.list, c.sprite, c.size, t, 2.1, 0.3 );
			}
		},

		deco: {
			label: 'Art deco',
			group: 'glam',
			tint: 'gold',
			draw: function ( x, W, H, u, o ) {
				var gold = foil( x, W, H, o.t );
				var m = u * 0.024;
				shadow( x, 'rgba(0,0,0,.45)', u * 0.007, 0 );
				x.strokeStyle = gold;
				x.lineWidth = u * 0.006;
				x.strokeRect( m, m, W - m * 2, H - m * 2 );
				var i = m + u * 0.012;
				x.lineWidth = Math.max( 1, u * 0.002 );
				x.strokeRect( i, i, W - i * 2, H - i * 2 );
				var r = u * 0.14;
				[ [ i, i, 0 ], [ W - i, i, Math.PI / 2 ], [ W - i, H - i, Math.PI ], [ i, H - i, -Math.PI / 2 ] ].forEach( function ( c ) {
					x.save();
					x.translate( c[ 0 ], c[ 1 ] );
					x.rotate( c[ 2 ] );
					x.lineWidth = Math.max( 1, u * 0.0022 );
					x.beginPath();
					for ( var n = 0; n <= 6; n++ ) {
						var a = n / 6 * Math.PI / 2;
						x.moveTo( Math.cos( a ) * r * 0.26, Math.sin( a ) * r * 0.26 );
						x.lineTo( Math.cos( a ) * r * ( n % 2 ? 0.8 : 1 ), Math.sin( a ) * r * ( n % 2 ? 0.8 : 1 ) );
					}
					x.stroke();
					x.beginPath();
					x.arc( 0, 0, r * 1.04, 0, Math.PI / 2 );
					x.moveTo( r * 0.26, 0 );
					x.arc( 0, 0, r * 0.26, 0, Math.PI / 2 );
					x.stroke();
					x.restore();
				} );
				// A stepped mark in the middle of the top and bottom.
				[ [ u * 0.024, 1 ], [ H - u * 0.024, -1 ] ].forEach( function ( p ) {
					x.lineWidth = Math.max( 1, u * 0.0028 );
					x.beginPath();
					for ( var n = 0; n < 3; n++ ) {
						var w = u * ( 0.11 - n * 0.032 );
						var y = p[ 0 ] + p[ 1 ] * u * ( 0.012 + n * 0.01 );
						x.moveTo( W / 2 - w, y );
						x.lineTo( W / 2 + w, y );
					}
					x.stroke();
				} );
			}
		},

		y2k: {
			label: 'Y2K chrome',
			group: 'glam',
			draw: function ( x, W, H, u ) {
				var m = u * 0.024;
				var g = x.createLinearGradient( 0, 0, W, H );
				[ '#dfe7f2', '#ffffff', '#a9b6c9', '#f5ecff', '#c7d4e6', '#ffffff', '#b7c1d4' ].forEach( function ( colour, i ) {
					g.addColorStop( i / 6, colour );
				} );
				shadow( x, 'rgba(70,50,130,.5)', u * 0.018, 0 );
				x.strokeStyle = g;
				x.lineWidth = u * 0.014;
				roundRect( x, m, m, W - m * 2, H - m * 2, u * 0.06 );
				x.stroke();
				noShadow( x );
				x.strokeStyle = 'rgba(255,255,255,.85)';
				x.lineWidth = Math.max( 1, u * 0.002 );
				roundRect( x, m + u * 0.012, m + u * 0.012, W - ( m + u * 0.012 ) * 2, H - ( m + u * 0.012 ) * 2, u * 0.05 );
				x.stroke();
			},
			live: function ( x, W, H, u, t ) {
				var spots = [ [ W - u * 0.075, u * 0.07, 0.05 ], [ W - u * 0.15, u * 0.11, 0.024 ], [ u * 0.07, H - u * 0.075, 0.05 ], [ u * 0.15, H - u * 0.12, 0.024 ], [ W - u * 0.06, H - u * 0.09, 0.034 ], [ u * 0.06, u * 0.09, 0.034 ] ];
				x.fillStyle = '#ffffff';
				spots.forEach( function ( s, i ) {
					var beat = 0.55 + 0.45 * Math.sin( t * 2 + i * 1.7 );
					x.globalAlpha = 0.5 + 0.5 * beat;
					glint( x, s[ 0 ], s[ 1 ], u * s[ 2 ] * ( 0.7 + 0.3 * beat ), 0.1 );
					x.fill();
				} );
				x.globalAlpha = 1;
			}
		},

		checker: {
			label: 'Checkerboard',
			group: 'glam',
			tint: 'ink',
			draw: function ( x, W, H, u, o ) {
				var s = u * 0.024;
				var b = s * 2;
				x.save();
				x.beginPath();
				x.rect( 0, 0, W, H );
				x.rect( b, b, W - b * 2, H - b * 2 );
				x.clip( 'evenodd' );
				x.fillStyle = '#fbf6f0';
				x.fillRect( 0, 0, W, H );
				x.fillStyle = o.t.main;
				for ( var c = 0; c * s < W; c++ ) {
					for ( var r = 0; r * s < H; r++ ) {
						if ( ( c + r ) % 2 && ( r * s < b || r * s >= H - b - s || c * s < b || c * s >= W - b - s ) ) {
							x.fillRect( c * s, r * s, s, s );
						}
					}
				}
				x.restore();
			}
		}
	};

	/* ─────────────────────────── light over the picture ─────────────────────────── */

	/* A soft shape drawn small and shown large: the enlarging is the blur, so it needs no filter and costs nothing per frame. */
	function softSheet( W, H, scale, paint ) {
		var w = Math.ceil( W * scale );
		var h = Math.ceil( H * scale );
		var c = makeCanvas( w, h );
		paint( c.getContext( '2d' ), w, h, Math.min( w, h ) );
		return c;
	}

	function drift( list, n, make ) {
		if ( ! list ) {
			list = [];
			for ( var i = 0; i < n; i++ ) {
				list.push( make( i ) );
			}
		}
		return list;
	}

	/* Round lights out of focus: brighter at the rim, as a lens draws them. */
	function discSprite( colour ) {
		var c = makeCanvas( 128, 128 );
		var x = c.getContext( '2d' );
		var g = x.createRadialGradient( 64, 64, 0, 64, 64, 62 );
		g.addColorStop( 0, 'rgba(' + colour + ',.34)' );
		g.addColorStop( 0.82, 'rgba(' + colour + ',.42)' );
		g.addColorStop( 0.95, 'rgba(' + colour + ',.72)' );
		g.addColorStop( 1, 'rgba(' + colour + ',0)' );
		x.fillStyle = g;
		x.fillRect( 0, 0, 128, 128 );
		return c;
	}

	function heartSprite( colour ) {
		var c = makeCanvas( 128, 128 );
		var x = c.getContext( '2d' );
		shadow( x, 'rgba(' + colour + ',.8)', 10, 0 );
		x.fillStyle = 'rgba(' + colour + ',.62)';
		heart( x, 64, 62, 38 );
		x.fill();
		return c;
	}

	function floaters( x, W, H, u, t, c, level, sprites ) {
		c.list = drift( c.list, 22, function ( i ) {
			var r = seeded( 101 + i * 37 );
			r();
			return { x: r(), y: r(), size: 0.07 + r() * 0.15, rise: 0.006 + r() * 0.012, sway: 0.5 + r(), phase: r() * TAU, sprite: i % sprites.length };
		} );
		x.globalCompositeOperation = 'screen';
		for ( var i = 0; i < c.list.length; i++ ) {
			var p = c.list[ i ];
			var d = u * p.size;
			var py = ( ( p.y - t * p.rise ) % 1 + 1 ) % 1;
			var px = p.x + Math.sin( t * 0.2 * p.sway + p.phase ) * 0.04;
			x.globalAlpha = level * ( 0.4 + 0.5 * ( 0.5 + 0.5 * Math.sin( t * 0.6 * p.sway + p.phase ) ) );
			x.drawImage( sprites[ p.sprite ], px * W - d / 2, py * ( H + d ) - d / 2, d, d );
		}
	}

	/* A sheet that drifts a little, larger than the picture so its edge never shows. */
	function driftSheet( x, sheet, W, H, u, t, mode, alpha, moveX, moveY, turn ) {
		var over = 1.3;
		x.globalCompositeOperation = mode;
		x.globalAlpha = alpha;
		x.translate( W / 2 + Math.sin( t * 0.16 ) * u * moveX, H / 2 + Math.cos( t * 0.13 ) * u * moveY );
		if ( turn ) {
			x.rotate( Math.sin( t * 0.22 ) * turn );
		}
		x.imageSmoothingQuality = 'high';
		x.drawImage( sheet, -W * over / 2, -H * over / 2, W * over, H * over );
	}

	var LIGHTS = {
		none: { label: 'None' },

		glow: {
			label: 'Dreamy glow',
			// The picture itself, shrunk and laid back over it: bright things bloom softly, as through a diffusion filter.
			draw: function ( x, W, H, u, t, c, level ) {
				if ( ! c.small ) {
					c.small = makeCanvas( Math.ceil( W / 10 ), Math.ceil( H / 10 ) );
				}
				var s = c.small.getContext( '2d' );
				s.imageSmoothingQuality = 'high';
				s.drawImage( x.canvas, 0, 0, c.small.width, c.small.height );
				x.globalCompositeOperation = 'lighten';
				x.globalAlpha = 0.72 * level;
				x.imageSmoothingQuality = 'high';
				x.drawImage( c.small, 0, 0, W, H );
			}
		},

		bokeh: {
			label: 'Bokeh',
			draw: function ( x, W, H, u, t, c, level ) {
				if ( ! c.sprites ) {
					c.sprites = [ discSprite( '255,206,170' ), discSprite( '255,170,170' ), discSprite( '255,226,180' ), discSprite( '240,190,255' ) ];
				}
				floaters( x, W, H, u, t, c, level, c.sprites );
			}
		},

		hearts: {
			label: 'Soft hearts',
			draw: function ( x, W, H, u, t, c, level ) {
				if ( ! c.sprites ) {
					c.sprites = [ heartSprite( '255,160,190' ), heartSprite( '255,205,215' ), heartSprite( '255,120,160' ) ];
				}
				floaters( x, W, H, u, t, c, level, c.sprites );
			}
		},

		dust: {
			label: 'Gold dust',
			draw: function ( x, W, H, u, t, c, level ) {
				c.list = drift( c.list, 110, function ( i ) {
					var r = seeded( 11 + i * 53 );
					r();
					return { x: r(), y: r(), size: 0.003 + r() * r() * 0.009, rise: 0.012 + r() * 0.03, rate: 0.6 + r() * 2.4, phase: r() * TAU };
				} );
				if ( ! c.sprite ) {
					c.sprite = glowSprite( 32, 'rgba(255,214,140,1)' );
				}
				x.globalCompositeOperation = 'lighter';
				for ( var i = 0; i < c.list.length; i++ ) {
					var p = c.list[ i ];
					var d = u * p.size * 4;
					x.globalAlpha = level * ( 0.2 + 0.8 * Math.pow( 0.5 + 0.5 * Math.sin( t * p.rate + p.phase ), 2 ) );
					x.drawImage( c.sprite, ( p.x + Math.sin( t * 0.3 + p.phase ) * 0.015 ) * W - d / 2, ( ( p.y - t * p.rise ) % 1 + 1 ) % 1 * H - d / 2, d, d );
				}
			}
		},

		sparkle: {
			label: 'Sparkles',
			draw: function ( x, W, H, u, t, c, level ) {
				c.list = drift( c.list, 18, function ( i ) {
					var r = seeded( 200 + i * 71 );
					r();
					return { x: r(), y: r(), size: 0.018 + r() * 0.03, rate: 0.5 + r() * 0.8, phase: r() * TAU };
				} );
				x.globalCompositeOperation = 'lighter';
				x.fillStyle = '#fff6df';
				for ( var i = 0; i < c.list.length; i++ ) {
					var p = c.list[ i ];
					var beat = Math.pow( Math.max( 0, Math.sin( t * p.rate + p.phase ) ), 4 );
					if ( beat > 0.02 ) {
						x.globalAlpha = level * beat;
						glint( x, p.x * W, p.y * H, u * p.size * beat, 0.08 );
						x.fill();
					}
				}
			}
		},

		leak: {
			label: 'Light leak',
			draw: function ( x, W, H, u, t, c, level ) {
				if ( ! c.sprites ) {
					c.sprites = [ glowSprite( 256, 'rgba(255,110,40,.85)' ), glowSprite( 256, 'rgba(255,60,90,.7)' ), glowSprite( 256, 'rgba(255,210,120,.75)' ) ];
				}
				var big = Math.max( W, H );
				var spots = [
					[ 0.02 + Math.sin( t * 0.17 ) * 0.03, 0.2 + Math.sin( t * 0.11 ) * 0.2, 1.05, 0 ],
					[ 0.98 + Math.sin( t * 0.13 + 2 ) * 0.03, 0.72 + Math.sin( t * 0.09 + 1 ) * 0.2, 0.95, 1 ],
					[ 0.5 + Math.sin( t * 0.1 + 4 ) * 0.42, -0.04, 0.72, 2 ]
				];
				x.globalCompositeOperation = 'screen';
				x.globalAlpha = level * ( 0.68 + 0.18 * Math.sin( t * 0.5 ) );
				spots.forEach( function ( s ) {
					var d = big * s[ 2 ];
					x.drawImage( c.sprites[ s[ 3 ] ], s[ 0 ] * W - d / 2, s[ 1 ] * H - d / 2, d, d );
				} );
			}
		},

		golden: {
			label: 'Golden hour',
			draw: function ( x, W, H, u, t, c, level ) {
				if ( ! c.sun ) {
					c.sun = glowSprite( 256, 'rgba(255,190,110,.95)' );
				}
				x.globalCompositeOperation = 'soft-light';
				x.globalAlpha = 0.5 * level;
				x.fillStyle = '#ffae5c';
				x.fillRect( 0, 0, W, H );
				var d = Math.max( W, H ) * ( 1.1 + 0.05 * Math.sin( t * 0.4 ) );
				x.globalCompositeOperation = 'screen';
				x.globalAlpha = 0.72 * level;
				x.drawImage( c.sun, W * 0.92 - d / 2, -H * 0.04 - d / 2, d, d );
			}
		},

		window: {
			label: 'Window light',
			draw: function ( x, W, H, u, t, c, level ) {
				if ( ! c.sheet ) {
					c.sheet = softSheet( W, H, 0.14, function ( s, w, h ) {
						s.fillStyle = '#a4917e';
						s.fillRect( 0, 0, w, h );
						s.fillStyle = '#ffffff';
						s.save();
						s.translate( w * 0.5, h * 0.5 );
						s.transform( 1, 0.05, -0.34, 1, 0, 0 );
						var cols = 3;
						var rows = 2;
						var pane = Math.min( w * 0.24, h * 0.42 );
						var gap = pane * 0.12;
						for ( var a = 0; a < cols; a++ ) {
							for ( var b = 0; b < rows; b++ ) {
								s.fillRect( ( a - cols / 2 ) * ( pane + gap ), ( b - rows / 2 ) * ( pane * 1.25 + gap ), pane, pane * 1.25 );
							}
						}
						s.restore();
					} );
				}
				driftSheet( x, c.sheet, W, H, u, t, 'multiply', level, 0.02, 0.012 );
			}
		},

		blinds: {
			label: 'Blinds',
			draw: function ( x, W, H, u, t, c, level ) {
				if ( ! c.sheet ) {
					c.sheet = softSheet( W, H, 0.2, function ( s, w, h ) {
						s.fillStyle = '#ffffff';
						s.fillRect( 0, 0, w, h );
						s.save();
						s.translate( w / 2, h / 2 );
						s.rotate( -0.16 );
						s.fillStyle = '#bbab9b';
						var step = Math.min( w, h ) * 0.1;
						for ( var y = -h; y < h; y += step ) {
							s.fillRect( -w, y, w * 2, step * 0.46 );
						}
						s.restore();
					} );
				}
				driftSheet( x, c.sheet, W, H, u, t, 'multiply', level, 0.012, 0.018 );
			}
		},

		leaves: {
			label: 'Leaf shadows',
			draw: function ( x, W, H, u, t, c, level ) {
				if ( ! c.sheet ) {
					c.sheet = softSheet( W, H, 0.16, function ( s, w, h, m ) {
						s.fillStyle = '#ffffff';
						s.fillRect( 0, 0, w, h );
						s.fillStyle = '#7c8772';
						[ [ w * 1.02, -h * 0.02, 2.55, 0.92 ], [ w * 1.02, h * 0.2, 2.95, 0.8 ], [ w * 0.72, -h * 0.05, 2.0, 0.76 ], [ -w * 0.02, h * 1.02, -0.5, 0.86 ], [ -w * 0.02, h * 0.74, -0.1, 0.62 ] ].forEach( function ( f ) {
							frond( s, f[ 0 ], f[ 1 ], f[ 2 ], m * f[ 3 ] );
						} );
					} );
				}
				driftSheet( x, c.sheet, W, H, u, t, 'multiply', level, 0.012, 0.01, 0.018 );
			}
		},

		spot: {
			label: 'Runway spotlight',
			draw: function ( x, W, H, u, t, c, level ) {
				if ( ! c.dark ) {
					c.dark = makeCanvas( 64, 64 );
					var d = c.dark.getContext( '2d' );
					var dark = d.createRadialGradient( 32, 28, 6, 32, 32, 44 );
					dark.addColorStop( 0, '#ffffff' );
					dark.addColorStop( 1, '#4a3f3a' );
					d.fillStyle = dark;
					d.fillRect( 0, 0, 64, 64 );
					c.beam = softSheet( 200, 400, 0.5, function ( s, w, h ) {
						var g = s.createLinearGradient( 0, 0, 0, h );
						g.addColorStop( 0, 'rgba(255,240,215,.75)' );
						g.addColorStop( 1, 'rgba(255,240,215,0)' );
						s.fillStyle = g;
						s.beginPath();
						s.moveTo( w * 0.42, 0 );
						s.lineTo( w * 0.58, 0 );
						s.lineTo( w * 0.86, h );
						s.lineTo( w * 0.14, h );
						s.closePath();
						s.fill();
					} );
				}
				x.globalCompositeOperation = 'multiply';
				x.globalAlpha = 0.7 * level;
				x.imageSmoothingQuality = 'high';
				x.drawImage( c.dark, 0, 0, W, H );
				x.globalCompositeOperation = 'screen';
				x.globalAlpha = 0.42 * level;
				var bw = u * 1.15;
				var bh = Math.hypot( W, H ) * 1.05;
				[ [ 0.26, 0.32, 0.5 ], [ 0.74, -0.34, 0.41 ] ].forEach( function ( b, i ) {
					x.save();
					x.translate( b[ 0 ] * W, -u * 0.04 );
					x.rotate( b[ 1 ] * Math.sin( t * b[ 2 ] + i * 2 ) * ( i ? 1 : -1 ) + ( i ? 0.24 : -0.24 ) );
					x.drawImage( c.beam, -bw / 2, 0, bw, bh );
					x.restore();
				} );
			}
		},

		disco: {
			label: 'Party lights',
			draw: function ( x, W, H, u, t, c, level ) {
				if ( ! c.sprites ) {
					c.sprites = [ glowSprite( 128, 'rgba(255,70,170,.95)' ), glowSprite( 128, 'rgba(90,160,255,.95)' ), glowSprite( 128, 'rgba(255,190,70,.95)' ), glowSprite( 128, 'rgba(150,90,255,.95)' ) ];
				}
				c.list = drift( c.list, 16, function ( i ) {
					var r = seeded( 400 + i * 23 );
					r();
					return { ax: r(), ay: r(), rx: 0.2 + r() * 0.34, ry: 0.15 + r() * 0.3, sx: 0.3 + r() * 0.5, sy: 0.25 + r() * 0.5, size: 0.3 + r() * 0.4, phase: r() * TAU };
				} );
				x.globalCompositeOperation = 'screen';
				for ( var i = 0; i < c.list.length; i++ ) {
					var p = c.list[ i ];
					var d = u * p.size;
					x.globalAlpha = level * ( 0.34 + 0.34 * Math.sin( t * 1.4 + p.phase ) );
					x.drawImage( c.sprites[ i % 4 ], ( p.ax + Math.sin( t * p.sx + p.phase ) * p.rx ) * W - d / 2, ( p.ay + Math.cos( t * p.sy + p.phase ) * p.ry ) * H - d / 2, d, d );
				}
			}
		},

		prism: {
			label: 'Prism',
			draw: function ( x, W, H, u, t, c, level ) {
				if ( ! c.sheet ) {
					c.sheet = softSheet( 320, 240, 0.5, function ( s, w, h ) {
						var g = s.createLinearGradient( 0, 0, w, 0 );
						var colours = [ '0,0,0', '255,60,60', '255,190,60', '120,240,110', '70,170,255', '190,90,255', '0,0,0' ];
						colours.forEach( function ( colour, i ) {
							g.addColorStop( i / 6, 'rgba(' + colour + ',' + ( i && i < 6 ? 0.8 : 0 ) + ')' );
						} );
						s.fillStyle = g;
						s.fillRect( 0, 0, w, h );
						var fade = s.createLinearGradient( 0, 0, 0, h );
						fade.addColorStop( 0, 'rgba(0,0,0,0)' );
						fade.addColorStop( 0.5, 'rgba(0,0,0,1)' );
						fade.addColorStop( 1, 'rgba(0,0,0,0)' );
						s.globalCompositeOperation = 'destination-in';
						s.fillStyle = fade;
						s.fillRect( 0, 0, w, h );
					} );
				}
				x.globalCompositeOperation = 'screen';
				x.globalAlpha = 0.7 * level;
				x.imageSmoothingQuality = 'high';
				var band = u * 0.62;
				var long = Math.hypot( W, H ) * 1.2;
				[ [ 0.04, 0.32, 0 ], [ 0.98, 0.2, 2.2 ] ].forEach( function ( b ) {
					x.save();
					x.translate( ( b[ 0 ] + Math.sin( t * 0.2 + b[ 2 ] ) * 0.035 ) * W, H / 2 );
					x.rotate( b[ 1 ] );
					x.drawImage( c.sheet, -band / 2, -long / 2, band, long );
					x.restore();
				} );
			}
		},

		sheen: {
			label: 'Passing shine',
			draw: function ( x, W, H, u, t, c, level ) {
				if ( ! c.sprite ) {
					c.sprite = makeCanvas( 256, 8 );
					var s = c.sprite.getContext( '2d' );
					var g = s.createLinearGradient( 0, 0, 256, 0 );
					g.addColorStop( 0, 'rgba(255,255,255,0)' );
					g.addColorStop( 0.5, 'rgba(255,248,235,.85)' );
					g.addColorStop( 1, 'rgba(255,255,255,0)' );
					s.fillStyle = g;
					s.fillRect( 0, 0, 256, 8 );
				}
				var every = 7;
				var phase = ( t % every ) / 1.5;
				if ( phase >= 1 ) {
					return;
				}
				var long = Math.hypot( W, H ) * 1.1;
				var wide = u * 0.5;
				x.globalCompositeOperation = 'screen';
				x.globalAlpha = 0.8 * level;
				x.translate( -W * 0.3 + phase * W * 1.6, H / 2 );
				x.rotate( 0.42 );
				x.drawImage( c.sprite, -wide / 2, -long / 2, wide, long );
			}
		},

		grain: {
			label: 'Film grain',
			draw: function ( x, W, H, u, t, c, level ) {
				if ( ! c.tile ) {
					c.tile = makeCanvas( 256, 256 );
					var s = c.tile.getContext( '2d' );
					var img = s.createImageData( 256, 256 );
					var r = seeded( 977 );
					for ( var i = 0; i < img.data.length; i += 4 ) {
						var v = 128 + ( r() - 0.5 ) * 110;
						img.data[ i ] = v;
						img.data[ i + 1 ] = v;
						img.data[ i + 2 ] = v;
						img.data[ i + 3 ] = 255;
					}
					s.putImageData( img, 0, 0 );
					c.pattern = x.createPattern( c.tile, 'repeat' );
				}
				// New grain 10 times a second, not 30: it reads the same and leaves the encoder bits for the picture.
				var step = Math.floor( t * 10 );
				if ( step !== c.step ) {
					c.step = step;
					c.ox = ( step * 73 ) % 256;
					c.oy = ( step * 131 ) % 256;
				}
				x.globalCompositeOperation = 'overlay';
				x.globalAlpha = 0.5 * level;
				x.translate( -c.ox, -c.oy );
				x.fillStyle = c.pattern;
				x.fillRect( 0, 0, W + 256, H + 256 );
			}
		},

		vhs: {
			label: 'Old video',
			draw: function ( x, W, H, u, t, c, level ) {
				if ( ! c.lines ) {
					c.lines = makeCanvas( 4, 6 );
					var s = c.lines.getContext( '2d' );
					s.fillStyle = 'rgba(0,0,0,.15)';
					s.fillRect( 0, 0, 4, 2 );
					c.pattern = x.createPattern( c.lines, 'repeat' );
					c.band = makeCanvas( 8, 128 );
					var b = c.band.getContext( '2d' );
					var g = b.createLinearGradient( 0, 0, 0, 128 );
					g.addColorStop( 0, 'rgba(255,255,255,0)' );
					g.addColorStop( 0.5, 'rgba(255,255,255,.4)' );
					g.addColorStop( 1, 'rgba(255,255,255,0)' );
					b.fillStyle = g;
					b.fillRect( 0, 0, 8, 128 );
				}
				x.globalCompositeOperation = 'soft-light';
				x.globalAlpha = 0.16 * level;
				x.fillStyle = '#7e3cff';
				x.fillRect( 0, 0, W, H );
				x.globalCompositeOperation = 'source-over';
				x.globalAlpha = level;
				x.fillStyle = c.pattern;
				x.fillRect( 0, 0, W, H );
				x.globalCompositeOperation = 'screen';
				x.globalAlpha = 0.34 * level;
				var bh = H * 0.16;
				x.drawImage( c.band, 0, ( ( t * 0.09 ) % 1.2 - 0.1 ) * H - bh / 2, W, bh );
			}
		}
	};

	/* ─────────────────────────── themes ─────────────────────────── */

	/*
	 * A whole look in one tap. Each must differ from the others in look, frame or light, because that is how the studio knows
	 * one is still on. The Adjust sliders are left alone: they correct the room, and the room has not changed.
	 * mood: the start of a mood in sounds.json. rain: an effect in the stage's EFFECTS. level: how strong its light starts.
	 */
	var THEMES = {
		clean: { label: 'Clean studio', look: 'natural', frame: 'rose', light: 'none', rain: '', mood: '' },
		seoul: { label: 'Seoul café', look: 'seoul', frame: 'soft', light: 'window', level: 0.55, rain: '', mood: 'Caf' },
		coquette: { label: 'Coquette', look: 'blush', frame: 'bows', tint: 'blush', light: 'hearts', rain: 'bows', mood: 'Dreamy' },
		editorial: { label: 'Editorial', look: 'ivory', frame: 'cover', light: 'none', rain: '', mood: 'Soft jazz' },
		golden: { label: 'Golden hour', look: 'golden', frame: 'gilded', light: 'golden', rain: '', mood: 'Bossa' },
		neon: { label: 'Neon night', look: 'night', frame: 'neon', light: 'bokeh', level: 0.6, rain: '', mood: 'Feel-good' },
		filmroll: { label: 'Film roll', look: 'film', frame: 'film', light: 'grain', rain: '', mood: 'Soft jazz' },
		camcorder: { label: 'Camcorder', look: 'vivid', frame: 'viewfinder', light: 'vhs', rain: '', mood: 'Feel-good' },
		y2k: { label: 'Y2K', look: 'peach', frame: 'y2k', light: 'sparkle', rain: 'butterflies', mood: 'Feel-good' },
		garden: { label: 'Garden party', look: 'fresh', frame: 'daisies', light: 'leaves', level: 0.5, rain: 'petals', mood: 'Bossa' },
		resort: { label: 'Resort', look: 'warm', frame: 'palms', light: 'leaves', level: 0.6, rain: '', mood: 'Bossa' },
		picnic: { label: 'Picnic', look: 'peach', frame: 'gingham', tint: 'sage', light: 'golden', rain: '', mood: 'Caf' },
		dressing: { label: 'Dressing room', look: 'rosy', frame: 'bulbs', light: 'glow', rain: '', mood: 'Soft jazz' },
		runway: { label: 'Runway', look: 'vintage', frame: 'cinema', light: 'spot', rain: '', mood: 'Feel-good' },
		gala: { label: 'Gala', look: 'soft', frame: 'deco', light: 'dust', rain: 'foil', mood: 'Soft jazz' }
	};

	root.WHDFrames = {
		TINTS: TINTS,
		FRAMES: FRAMES,
		FRAME_GROUPS: FRAME_GROUPS,
		LIGHTS: LIGHTS,
		THEMES: THEMES,
		painters: {
			pearl: function ( x, size ) {
				pearl( x, size / 2, size / 2, size * 0.36, TINTS.cream );
			},
			bubble: function ( x, size ) {
				var r = size * 0.38;
				var g = x.createRadialGradient( size / 2, size / 2, r * 0.6, size / 2, size / 2, r );
				g.addColorStop( 0, 'rgba(255,255,255,.04)' );
				g.addColorStop( 0.85, 'rgba(220,235,255,.22)' );
				g.addColorStop( 1, 'rgba(255,255,255,.75)' );
				x.fillStyle = g;
				x.beginPath();
				x.arc( size / 2, size / 2, r, 0, TAU );
				x.fill();
				x.fillStyle = 'rgba(255,255,255,.85)';
				x.beginPath();
				x.ellipse( size / 2 - r * 0.42, size / 2 - r * 0.46, r * 0.17, r * 0.1, -0.7, 0, TAU );
				x.fill();
			}
		}
	};
}( window ) );
