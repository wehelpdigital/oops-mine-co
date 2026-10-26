/**
 * Image Editor presets: everything the editor offers as a click rather than a blank field.
 *
 * Kept apart from the editor so the lists can grow without touching how the editor works. Sizes
 * are fractions of the design's width (`s`), so a preset looks the same on a story and a square.
 * Colours start from the shop's own — rose-gold, ink, sand — and every one can be changed.
 */
( function () {
	'use strict';

	var ROSE = '#b98b7e';
	var ROSE_DEEP = '#9c6f63';
	var INK = '#3a2b26';
	var SAND = '#f7f2ed';
	var WHITE = '#ffffff';
	var GOLD = '#f2c14e';
	var PINK = '#f4a6b8';
	var RED = '#e8484d';
	var SAGE = '#7e9a6e';

	/* A four-point sparkle centred on cx, cy, reaching r: the curve the "Sparkle" mark uses. */
	function spark( cx, cy, r ) {
		var k = 0.08 * r;
		var j = 0.24 * r;
		return 'M' + cx + ' ' + ( cy - r ) +
			' C ' + ( cx + k ) + ' ' + ( cy - j ) + ', ' + ( cx + j ) + ' ' + ( cy - k ) + ', ' + ( cx + r ) + ' ' + cy +
			' C ' + ( cx + j ) + ' ' + ( cy + k ) + ', ' + ( cx + k ) + ' ' + ( cy + j ) + ', ' + cx + ' ' + ( cy + r ) +
			' C ' + ( cx - k ) + ' ' + ( cy + j ) + ', ' + ( cx - j ) + ' ' + ( cy + k ) + ', ' + ( cx - r ) + ' ' + cy +
			' C ' + ( cx - j ) + ' ' + ( cy - k ) + ', ' + ( cx - k ) + ' ' + ( cy - j ) + ', ' + cx + ' ' + ( cy - r ) + ' Z';
	}

	/* A star as a path, so it can be drawn as an outline as well as filled. */
	function starPath( cx, cy, outer, inner, n ) {
		var d = '';
		for ( var i = 0; i < n * 2; i++ ) {
			var r = i % 2 ? inner : outer;
			var a = Math.PI * i / n - Math.PI / 2;
			d += ( i ? ' L ' : 'M' ) + ( cx + Math.cos( a ) * r ).toFixed( 1 ) + ' ' + ( cy + Math.sin( a ) * r ).toFixed( 1 );
		}
		return d + ' Z';
	}

	/* A circle as a path: several of them make dots, a cherry, a bubble's trail. */
	function dot( cx, cy, r ) {
		return 'M' + ( cx - r ) + ' ' + cy + ' a' + r + ' ' + r + ' 0 1 0 ' + ( 2 * r ) + ' 0 a' + r + ' ' + r + ' 0 1 0 ' + ( -2 * r ) + ' 0 Z';
	}

	var HEART = 'M50 88 C 20 65, 5 45, 18 25 C 30 8, 48 14, 50 30 C 52 14, 70 8, 82 25 C 95 45, 80 65, 50 88 Z';

	/* ─────────────────────────── sizes ───────────────────────────
	   The AI screens draw pictures at these same sizes (WHD_AI_Images::frames()): change one list
	   and change the other. */

	var sizes = [
		{ name: 'Original', w: 0, h: 0, note: 'the photo’s own size' },
		{ name: 'Instagram post', w: 1080, h: 1080, note: '1080 × 1080' },
		{ name: 'Instagram portrait', w: 1080, h: 1350, note: '1080 × 1350' },
		{ name: 'Story / Reel', w: 1080, h: 1920, note: '1080 × 1920' },
		{ name: 'Facebook post', w: 1200, h: 630, note: '1200 × 630' },
		{ name: 'Pinterest pin', w: 1000, h: 1500, note: '1000 × 1500' },
		{ name: 'Product, square', w: 2000, h: 2000, note: '2000 × 2000' },
		{ name: 'Product, 3:4', w: 1500, h: 2000, note: '1500 × 2000' },
		{ name: 'Web banner', w: 1920, h: 800, note: '1920 × 800' },
		{ name: 'Email header', w: 1200, h: 600, note: '1200 × 600' },
		{ name: 'Facebook cover', w: 1640, h: 624, note: '1640 × 624' },
		{ name: 'Facebook story', w: 1080, h: 1920, note: '1080 × 1920' },
		{ name: 'TikTok cover', w: 1080, h: 1920, note: '1080 × 1920' },
		{ name: 'YouTube thumbnail', w: 1280, h: 720, note: '1280 × 720' },
		{ name: 'X / Twitter post', w: 1600, h: 900, note: '1600 × 900' },
		{ name: 'Pinterest square', w: 1000, h: 1000, note: '1000 × 1000' },
		{ name: 'Landscape 3:2', w: 1800, h: 1200, note: '1800 × 1200' },
		{ name: 'Portrait 2:3', w: 1200, h: 1800, note: '1200 × 1800' },
		{ name: 'Lookbook page A4', w: 1240, h: 1754, note: '1240 × 1754' },
		{ name: 'Gift card', w: 1050, h: 600, note: '1050 × 600' }
	];

	/* ─────────────────────────── text ─────────────────────────── */

	/*
	 * A text style: font, weight, style, size (s × width), colours, spacing (Fabric's charSpacing is
	 * thousandths of an em), outline, shadow, highlight. `caps` writes the words in capitals.
	 */
	var text = {
		heading: { text: 'Your heading', font: 'Playfair Display', weight: 700, s: 0.085, fill: INK, align: 'center' },
		sub: { text: 'A subheading', font: 'Montserrat', weight: 500, s: 0.04, fill: INK, align: 'center', spacing: 120 },
		body: { text: 'A little body text, to say a bit more about it.', font: 'Manrope', weight: 400, s: 0.028, fill: INK, align: 'center', lineHeight: 1.35, width: 0.6 }
	};

	var combos = [
		{ name: 'Autumn edit', text: 'The Autumn Edit', font: 'Cormorant Garamond', style: 'italic', weight: 600, s: 0.1, fill: WHITE, shadow: true },
		{ name: 'BIG SALE', text: 'BIG SALE', font: 'Bebas Neue', weight: 400, s: 0.2, fill: WHITE, spacing: 40, shadow: true },
		{ name: 'NEW IN', text: 'NEW IN', font: 'Montserrat', weight: 900, s: 0.12, fill: INK, spacing: 200 },
		{ name: 'Love it', text: 'love it', font: 'Great Vibes', weight: 400, s: 0.13, fill: ROSE },
		{ name: 'The Journal', text: 'The Journal', font: 'Playfair Display', weight: 900, s: 0.1, fill: INK },
		{ name: 'so cute!', text: 'so cute!', font: 'Pacifico', weight: 400, s: 0.09, fill: '#e86f8f' },
		{ name: 'DROP 02', text: 'DROP 02', font: 'Archivo Black', weight: 400, s: 0.13, fill: 'rgba(0,0,0,0)', stroke: WHITE, strokeWidth: 0.004 },
		{ name: 'Shop now', text: 'Shop now', font: 'Dancing Script', weight: 700, s: 0.09, fill: ROSE_DEEP },
		{ name: 'LIMITED', text: 'LIMITED STOCK', font: 'Oswald', weight: 600, s: 0.06, fill: WHITE, spacing: 300, highlight: INK },
		{ name: 'Elegant', text: 'Elegant & easy', font: 'DM Serif Display', weight: 400, s: 0.09, fill: INK },
		{ name: 'BOLD', text: 'BOLD MOVES', font: 'Abril Fatface', weight: 400, s: 0.1, fill: ROSE },
		{ name: 'Sweet', text: 'Sweet!', font: 'Shrikhand', weight: 400, s: 0.12, fill: '#ff9a8b', stroke: INK, strokeWidth: 0.003 },
		{ name: 'note to self', text: 'note to self: buy it', font: 'Caveat', weight: 700, s: 0.07, fill: INK },
		{ name: 'MINIMAL', text: 'minimal', font: 'Josefin Sans', weight: 300, s: 0.08, fill: INK, spacing: 400 },
		{ name: 'Retro', text: 'Retro Vibes', font: 'Righteous', weight: 400, s: 0.09, fill: '#f2b33d', shadow: true },
		{ name: 'Classic', text: 'CLASSIC', font: 'Cinzel', weight: 700, s: 0.09, fill: INK, spacing: 250 },
		{ name: 'Marker', text: 'just dropped', font: 'Permanent Marker', weight: 400, s: 0.08, fill: '#e8484d' },
		{ name: 'Price', text: '₱1,890', font: 'League Spartan', weight: 800, s: 0.1, fill: INK },
		{ name: 'Script', text: 'with love', font: 'Sacramento', weight: 400, s: 0.12, fill: INK },
		{ name: 'Typewriter', text: 'NO. 04 — AUTUMN', font: 'Courier Prime', weight: 700, s: 0.04, fill: INK, spacing: 150 },
		{ name: 'Lobster', text: 'Weekend Mood', font: 'Lobster', weight: 400, s: 0.09, fill: WHITE, shadow: true },
		{ name: 'Hand-drawn', text: 'HELLO SUNSHINE', font: 'Amatic SC', weight: 700, s: 0.11, fill: INK, spacing: 80 },
		{ name: 'Parisienne', text: 'Bonjour', font: 'Parisienne', weight: 400, s: 0.12, fill: ROSE_DEEP },
		{ name: 'Grotesk', text: 'Now online', font: 'Space Grotesk', weight: 700, s: 0.08, fill: INK },
		{ name: 'Neon', text: 'open late', font: 'Pacifico', weight: 400, s: 0.1, fill: PINK, fx: 'neon' },
		{ name: 'Neon caps', text: 'LIVE NOW', font: 'Bebas Neue', weight: 400, s: 0.14, fill: '#7fd8ff', spacing: 80, fx: 'neon' },
		{ name: 'Retro', text: 'Sunday Best', font: 'Shrikhand', weight: 400, s: 0.1, fill: GOLD, fx: 'retro' },
		{ name: 'Echo', text: 'NEW SEASON', font: 'Anton', weight: 400, s: 0.12, fill: INK, spacing: 40, fx: 'echo' },
		{ name: 'Gradient', text: 'Golden hour', font: 'DM Serif Display', weight: 400, s: 0.1, fill: '#f2a65a', fx: 'gradient' },
		{ name: 'Rose gradient', text: 'blush & bloom', font: 'Great Vibes', weight: 400, s: 0.13, fill: '#e86f8f', fx: 'gradient' },
		{ name: 'Editorial', text: 'The Edit', font: 'Bodoni Moda', style: 'italic', weight: 400, s: 0.13, fill: INK },
		{ name: 'Magazine', text: 'OOPS', font: 'Playfair Display', weight: 900, s: 0.24, fill: WHITE, spacing: -20, shadow: true },
		{ name: 'Cover line', text: '12 pieces we can’t stop wearing', font: 'Montserrat', weight: 700, s: 0.035, fill: WHITE, caps: true, spacing: 60, width: 0.5, align: 'left', shadow: true },
		{ name: 'OOTD', text: 'OOTD', font: 'Archivo Black', weight: 400, s: 0.16, fill: ROSE, spacing: 60 },
		{ name: 'Get the look', text: 'Get the look', font: 'Allura', weight: 400, s: 0.12, fill: INK },
		{ name: 'Link in bio', text: 'LINK IN BIO ↓', font: 'Montserrat', weight: 800, s: 0.045, fill: INK, spacing: 250, highlight: WHITE },
		{ name: 'Tap to shop', text: 'tap to shop', font: 'Caveat', weight: 700, s: 0.08, fill: WHITE, shadow: true },
		{ name: 'Restocked', text: 'RESTOCKED', font: 'Oswald', weight: 600, s: 0.09, fill: WHITE, spacing: 200, highlight: ROSE_DEEP },
		{ name: 'Final sale', text: 'FINAL SALE', font: 'Anton', weight: 400, s: 0.16, fill: RED, spacing: 30 },
		{ name: 'Half off', text: '50% OFF', font: 'Archivo Black', weight: 400, s: 0.16, fill: WHITE, stroke: INK, strokeWidth: 0.004 },
		{ name: 'Coming soon', text: 'coming soon', font: 'Josefin Sans', weight: 300, s: 0.07, fill: WHITE, spacing: 500, caps: true, shadow: true },
		{ name: 'Sold out', text: 'SOLD OUT', font: 'Archivo Black', weight: 400, s: 0.13, fill: 'rgba(0,0,0,0)', stroke: RED, strokeWidth: 0.004 },
		{ name: 'Be mine', text: 'be mine', font: 'Great Vibes', weight: 400, s: 0.15, fill: '#d6455d' },
		{ name: 'Merry', text: 'Merry & Bright', font: 'Satisfy', weight: 400, s: 0.1, fill: '#b23a3a' },
		{ name: 'Autumn', text: 'hello, autumn', font: 'Dancing Script', weight: 700, s: 0.1, fill: '#b5652b' },
		{ name: 'Summer', text: 'SUMMER EDIT', font: 'Righteous', weight: 400, s: 0.1, fill: '#ff8a5b', fx: 'retro' },
		{ name: 'Seoul', text: 'Seoul Style', font: 'Libre Baskerville', style: 'italic', weight: 400, s: 0.08, fill: INK },
		{ name: 'Bangkok', text: 'from Bangkok, with love', font: 'Sacramento', weight: 400, s: 0.09, fill: ROSE_DEEP },
		{ name: 'Thank you', text: 'thank you', font: 'Parisienne', weight: 400, s: 0.13, fill: ROSE_DEEP },
		{ name: 'Staff pick', text: 'STAFF PICK', font: 'League Spartan', weight: 800, s: 0.07, fill: INK, spacing: 180 },
		{ name: 'Weekend', text: 'weekend edit', font: 'Lora', style: 'italic', weight: 600, s: 0.09, fill: WHITE, shadow: true },
		{ name: 'Petite', text: 'PETITE FIT', font: 'Poppins', weight: 700, s: 0.06, fill: WHITE, spacing: 300, highlight: INK },
		{ name: 'Quote', text: '“Oops — mine.”', font: 'Cormorant Garamond', style: 'italic', weight: 600, s: 0.11, fill: INK },
		{ name: 'Marker note', text: 'obsessed!!', font: 'Permanent Marker', weight: 400, s: 0.1, fill: '#e86f8f' },
		{ name: 'Inter clean', text: 'Simple. Soft. Yours.', font: 'Inter', weight: 600, s: 0.05, fill: INK },
		{ name: 'Raleway', text: 'NEW ARRIVALS', font: 'Raleway', weight: 300, s: 0.06, fill: INK, spacing: 600 },
		{ name: 'Lobster', text: 'Treat yourself', font: 'Lobster', weight: 400, s: 0.09, fill: ROSE }
	];

	/* ─────────────────────────── stickers ─────────────────────────── */

	/* Badges: a shape with words on it; the words and both colours can be changed afterwards. */
	var badges = [
		{ shape: 'circle', text: 'NEW', fill: ROSE, color: WHITE, font: 'Montserrat', weight: 800 },
		{ shape: 'burst', text: 'SALE', fill: '#e8484d', color: WHITE, font: 'Anton', weight: 400 },
		{ shape: 'burst', text: '-20%', fill: '#f2b33d', color: INK, font: 'Archivo Black', weight: 400 },
		{ shape: 'pill', text: 'JUST IN', fill: INK, color: WHITE, font: 'Montserrat', weight: 700 },
		{ shape: 'pill', text: 'BESTSELLER', fill: SAND, color: INK, font: 'Montserrat', weight: 700 },
		{ shape: 'ribbon', text: 'LIMITED', fill: ROSE_DEEP, color: WHITE, font: 'Oswald', weight: 600 },
		{ shape: 'tag', text: '₱1,890', fill: WHITE, color: INK, font: 'League Spartan', weight: 800 },
		{ shape: 'stamp', text: 'HANDPICKED', fill: 'rgba(0,0,0,0)', color: ROSE_DEEP, font: 'Cinzel', weight: 700 },
		{ shape: 'star', text: 'HOT', fill: '#ff7a45', color: WHITE, font: 'Anton', weight: 400 },
		{ shape: 'circle', text: 'BACK IN STOCK', fill: INK, color: SAND, font: 'Montserrat', weight: 700 },
		{ shape: 'pill', text: 'ONLINE ONLY', fill: WHITE, color: ROSE_DEEP, font: 'Montserrat', weight: 700 },
		{ shape: 'burst', text: 'LAST PIECES', fill: ROSE, color: WHITE, font: 'Oswald', weight: 600 },
		{ shape: 'ribbon', text: 'TRENDING', fill: INK, color: WHITE, font: 'Oswald', weight: 600 },
		{ shape: 'circle', text: 'ONE SIZE', fill: SAND, color: INK, font: 'Josefin Sans', weight: 600 },
		{ shape: 'stamp', text: 'SOLD OUT', fill: 'rgba(0,0,0,0)', color: '#e8484d', font: 'Archivo Black', weight: 400 },
		{ shape: 'pill', text: 'SWIPE UP ↑', fill: WHITE, color: INK, font: 'Montserrat', weight: 800 },
		{ shape: 'seal', text: 'STAFF PICK', fill: GOLD, color: INK, font: 'Montserrat', weight: 800 },
		{ shape: 'seal', text: 'MUST HAVE', fill: ROSE_DEEP, color: WHITE, font: 'Oswald', weight: 600 },
		{ shape: 'hex', text: 'NEW SEASON', fill: INK, color: SAND, font: 'Montserrat', weight: 700 },
		{ shape: 'hex', text: 'PETITE FIT', fill: SAND, color: INK, font: 'Josefin Sans', weight: 700 },
		{ shape: 'square', text: 'PRE-ORDER', fill: WHITE, color: INK, font: 'League Spartan', weight: 800 },
		{ shape: 'square', text: 'GIFT IDEA', fill: PINK, color: INK, font: 'Montserrat', weight: 800 },
		{ shape: 'bubble', text: 'OBSESSED', fill: WHITE, color: ROSE_DEEP, font: 'Archivo Black', weight: 400 },
		{ shape: 'bubble', text: 'NEED IT', fill: INK, color: WHITE, font: 'Anton', weight: 400 },
		{ shape: 'oval', text: 'RESTOCKED', fill: SAGE, color: WHITE, font: 'Oswald', weight: 600 },
		{ shape: 'oval', text: 'COMING SOON', fill: SAND, color: ROSE_DEEP, font: 'Montserrat', weight: 800 },
		{ shape: 'pill', text: 'LIVE NOW ●', fill: RED, color: WHITE, font: 'Montserrat', weight: 800 },
		{ shape: 'pill', text: 'LINK IN BIO', fill: INK, color: WHITE, font: 'Montserrat', weight: 800 },
		{ shape: 'pill', text: 'TAP TO SHOP', fill: WHITE, color: ROSE_DEEP, font: 'Montserrat', weight: 800 },
		{ shape: 'ribbon', text: 'EXCLUSIVE', fill: GOLD, color: INK, font: 'Oswald', weight: 600 },
		{ shape: 'ribbon', text: 'FINAL SALE', fill: RED, color: WHITE, font: 'Oswald', weight: 600 },
		{ shape: 'circle', text: 'LOW STOCK', fill: '#ff7a45', color: WHITE, font: 'Montserrat', weight: 800 },
		{ shape: 'circle', text: 'OOTD', fill: PINK, color: INK, font: 'Archivo Black', weight: 400 },
		{ shape: 'burst', text: '2 FOR 1', fill: GOLD, color: INK, font: 'Anton', weight: 400 },
		{ shape: 'burst', text: '-50%', fill: RED, color: WHITE, font: 'Archivo Black', weight: 400 },
		{ shape: 'star', text: 'WOW', fill: PINK, color: WHITE, font: 'Anton', weight: 400 },
		{ shape: 'stamp', text: 'SEOUL', fill: 'rgba(0,0,0,0)', color: INK, font: 'Cinzel', weight: 700 },
		{ shape: 'stamp', text: 'BANGKOK', fill: 'rgba(0,0,0,0)', color: ROSE_DEEP, font: 'Cinzel', weight: 700 },
		{ shape: 'tag', text: 'NOW $29', fill: SAND, color: INK, font: 'League Spartan', weight: 800 }
	];

	/* Decorations: line art and simple marks, as SVG paths on a 100 × 100 box. */
	var decor = [
		{ name: 'Heart', path: 'M50 88 C 20 65, 5 45, 18 25 C 30 8, 48 14, 50 30 C 52 14, 70 8, 82 25 C 95 45, 80 65, 50 88 Z', fill: '#e86f8f' },
		{ name: 'Sparkle', path: 'M50 0 C 54 38, 62 46, 100 50 C 62 54, 54 62, 50 100 C 46 62, 38 54, 0 50 C 38 46, 46 38, 50 0 Z', fill: '#f2c14e' },
		{ name: 'Star', star: 5, fill: '#f2c14e' },
		{ name: 'Burst', star: 12, inner: 0.7, fill: ROSE },
		{ name: 'Crown', path: 'M10 78 L 15 30 L 35 55 L 50 18 L 65 55 L 85 30 L 90 78 Z', fill: '#e2b04a' },
		{ name: 'Lightning', path: 'M58 4 L 18 56 L 44 56 L 34 96 L 82 40 L 56 40 L 66 4 Z', fill: '#f2c14e' },
		{ name: 'Cloud', path: 'M25 72 C 8 72 6 50 22 46 C 20 28 42 22 52 34 C 58 18 82 22 82 42 C 97 44 97 72 78 72 Z', fill: WHITE },
		{ name: 'Speech', path: 'M12 14 H88 Q96 14 96 22 V60 Q96 68 88 68 H46 L26 88 L32 68 H12 Q4 68 4 60 V22 Q4 14 12 14 Z', fill: WHITE },
		{ name: 'Leaf', path: 'M12 88 C 12 40 40 12 88 12 C 88 60 60 88 12 88 Z', fill: '#7e9a6e' },
		{ name: 'Brush stroke', path: 'M4 42 Q 22 30 52 35 T 96 32 L 97 60 Q 70 68 45 63 T 3 66 Z', fill: ROSE, wide: true },
		{ name: 'Arrow', path: 'M10 78 Q 46 14 86 38 M70 24 L 88 38 L 74 54', stroke: INK, width: 5 },
		{ name: 'Underline', path: 'M4 60 Q 28 44 54 54 T 96 50', stroke: ROSE, width: 6, wide: true },
		{ name: 'Circle it', path: 'M50 12 C 86 10 96 40 89 62 C 80 89 30 96 14 70 C 2 50 16 16 58 15', stroke: '#e8484d', width: 4 },
		{ name: 'Squiggle', path: 'M4 50 Q 14 30 24 50 T 44 50 T 64 50 T 84 50 T 100 46', stroke: INK, width: 4, wide: true },
		{ name: 'Sun', sun: true, fill: '#f2b33d' },
		{ name: 'Flower', flower: true, fill: '#f4a6b8', centre: '#f2c14e' },
		{ name: 'Heart outline', path: HEART, stroke: '#e86f8f', width: 5 },
		{ name: 'Two hearts', path: 'M32 76 C 12 60 4 46 12 34 C 20 22 32 26 32 38 C 32 26 44 22 52 34 C 60 46 52 60 32 76 Z M72 56 C 58 46 52 36 58 28 C 64 20 72 24 72 32 C 72 24 80 20 86 28 C 92 36 86 46 72 56 Z', fill: '#e86f8f' },
		{ name: 'Sparkles', path: spark( 40, 50, 34 ) + ' ' + spark( 80, 22, 14 ) + ' ' + spark( 78, 78, 10 ), fill: GOLD },
		{ name: 'Star outline', path: starPath( 50, 52, 46, 19, 5 ), stroke: GOLD, width: 4 },
		{ name: 'Pop', star: 8, inner: 0.55, fill: '#ff7a45' },
		{ name: 'Seal', star: 24, inner: 0.86, fill: ROSE_DEEP },
		{ name: 'Moon', path: 'M60 6 A44 44 0 1 0 94 70 A36 36 0 1 1 60 6 Z', fill: GOLD },
		{ name: 'Rainbow', layers: [
			{ path: 'M8 80 A42 42 0 0 1 92 80', stroke: '#f4a6b8', width: 8 },
			{ path: 'M20 80 A30 30 0 0 1 80 80', stroke: GOLD, width: 8 },
			{ path: 'M32 80 A18 18 0 0 1 68 80', stroke: '#9cc6e0', width: 8 }
		] },
		{ name: 'Butterfly', path: 'M50 50 C 30 8 2 18 10 42 C 14 56 34 58 50 50 Z M50 50 C 70 8 98 18 90 42 C 86 56 66 58 50 50 Z M50 52 C 32 58 16 74 28 86 C 38 94 48 74 50 56 Z M50 52 C 68 58 84 74 72 86 C 62 94 52 74 50 56 Z', fill: PINK },
		{ name: 'Bow', layers: [
			{ path: 'M50 46 C 36 28 8 22 8 40 C 8 58 36 54 50 50 Z M50 46 C 64 28 92 22 92 40 C 92 58 64 54 50 50 Z M45 52 L 32 88 L 44 82 L 50 58 Z M55 52 L 68 88 L 56 82 L 50 58 Z', fill: '#e86f8f' },
			{ path: dot( 50, 49, 7 ), fill: '#d6455d' }
		] },
		{ name: 'Lips', path: 'M6 50 C 22 28 38 30 50 40 C 62 30 78 28 94 50 C 78 78 22 78 6 50 Z', fill: '#c94b5b' },
		{ name: 'Sunglasses', layers: [
			{ path: 'M6 36 H94', stroke: INK, width: 5 },
			{ path: 'M10 38 H44 V52 Q44 70 27 70 Q10 70 10 52 Z M56 38 H90 V52 Q90 70 73 70 Q56 70 56 52 Z', fill: INK }
		] },
		{ name: 'Hanger', path: 'M50 32 C 50 24 58 22 58 15 C 58 8 51 6 47 9 M50 32 L 8 66 Q 4 72 12 72 H 88 Q 96 72 92 66 Z', stroke: INK, width: 4 },
		{ name: 'Shopping bag', path: 'M18 34 H82 L76 92 H24 Z M36 34 V26 A14 14 0 0 1 64 26 V34', stroke: INK, width: 4 },
		{ name: 'Dress', path: 'M40 6 L 60 6 L 58 22 Q 66 28 64 40 L 84 92 H 16 L 36 40 Q 34 28 42 22 Z', fill: ROSE },
		{ name: 'Price tag', path: 'M50 8 H90 V48 L50 88 L12 50 Z ' + dot( 74, 24, 6 ), stroke: INK, width: 4 },
		{ name: 'Check', path: 'M12 52 L 38 78 L 90 22', stroke: '#6f9b6a', width: 9 },
		{ name: 'Cross', path: 'M18 18 L 82 82 M82 18 L 18 82', stroke: RED, width: 9 },
		{ name: 'Quote marks', path: 'M12 64 C 12 42 22 28 40 22 L 43 30 C 33 35 29 42 29 49 H 41 V 74 H 12 Z M56 64 C 56 42 66 28 84 22 L 87 30 C 77 35 73 42 73 49 H 85 V 74 H 56 Z', fill: INK },
		{ name: 'Flourish', path: 'M4 50 C 20 50 26 38 36 38 C 46 38 44 56 50 56 C 56 56 54 38 64 38 C 74 38 80 50 96 50', stroke: ROSE, width: 3, wide: true },
		{ name: 'Three dots', path: dot( 18, 50, 9 ) + ' ' + dot( 50, 50, 9 ) + ' ' + dot( 82, 50, 9 ), fill: ROSE, wide: true },
		{ name: 'Arrow down', path: 'M50 8 Q 28 42 50 86 M34 72 L 50 90 L 64 72', stroke: INK, width: 5 },
		{ name: 'Arrow across', path: 'M6 50 H 88 M70 32 L 92 50 L 70 68', stroke: INK, width: 5, wide: true },
		{ name: 'Highlighter', path: 'M2 30 Q 50 24 98 32 L 96 70 Q 50 76 4 68 Z', fill: 'rgba(242,193,78,0.55)', wide: true },
		{ name: 'Scribble', path: 'M6 62 C 16 20 28 82 40 40 S 60 74 68 36 S 86 68 94 40', stroke: ROSE_DEEP, width: 4, wide: true },
		{ name: 'Double underline', path: 'M4 44 Q 50 34 96 44 M8 60 Q 50 50 92 58', stroke: ROSE, width: 5, wide: true },
		{ name: 'Snowflake', path: 'M50 6 V94 M12 28 L88 72 M88 28 L12 72 M40 12 L50 22 L60 12 M40 88 L50 78 L60 88', stroke: '#8fbcd8', width: 4 },
		{ name: 'Sprig', layers: [
			{ path: 'M50 96 C 50 70 48 40 54 6', stroke: SAGE, width: 4 },
			{ path: 'M50 70 C 30 66 22 52 26 44 C 40 46 48 58 50 70 Z M52 46 C 72 42 80 28 76 20 C 62 22 54 34 52 46 Z M50 30 C 36 26 32 16 34 10 C 44 12 50 20 50 30 Z', fill: SAGE }
		] },
		{ name: 'Cherries', layers: [
			{ path: 'M34 64 C 38 40 48 22 66 10 M70 60 C 68 38 66 22 66 10', stroke: SAGE, width: 4 },
			{ path: dot( 32, 72, 16 ) + ' ' + dot( 70, 68, 16 ), fill: '#d6455d' }
		] },
		{ name: 'Envelope', path: 'M8 22 H92 V78 H8 Z M8 22 L50 54 L92 22', stroke: INK, width: 4 },
		{ name: 'Thought bubble', layers: [
			{ path: 'M30 60 C 12 60 10 40 24 36 C 22 20 42 14 52 24 C 60 10 86 16 84 34 C 98 38 96 60 78 60 Z', fill: WHITE, stroke: '#d9c9c0', width: 2 },
			{ path: dot( 30, 74, 6 ) + ' ' + dot( 20, 88, 4 ), fill: WHITE, stroke: '#d9c9c0', width: 2 }
		] },
		{ name: 'Coffee', path: 'M18 36 H70 V64 Q70 84 44 84 Q18 84 18 64 Z M70 42 H78 Q88 42 88 52 Q88 62 78 62 H70 M34 12 Q28 20 34 28 M48 10 Q42 20 48 28', stroke: INK, width: 4 },
		{ name: 'Camera', path: 'M8 32 H30 L36 22 H64 L70 32 H92 V80 H8 Z ' + dot( 50, 56, 16 ), stroke: INK, width: 4 }
	];

	var emoji = [ '❤️', '✨', '🔥', '💕', '🌸', '🛍️', '👗', '👚', '👖', '👜', '👠', '👟', '💄', '💅', '🌟', '⭐', '💯', '🎉', '🎁', '☀️', '🍂', '❄️', '🌿', '💐', '🌷', '😍', '🥰', '😎', '👀', '🙌', '👇', '👉', '🆕', '💸', '🏷️', '📦', '💌', '🤍', '🖤', '🤎',
		'🧥', '🧣', '🧤', '👒', '🎀', '💍', '💎', '🕶️', '👛', '👝', '🩱', '👙', '🩴', '🥿', '👢', '👘', '🥻', '🧦',
		'🎄', '🎃', '💝', '🌹', '🌺', '🌼', '🌻', '🍁', '🌙', '⛄', '🌈', '🌊', '🍓', '🍒', '🍑', '🧁', '☕', '🧋',
		'🤩', '😘', '😉', '🫶', '💖', '💗', '💓', '💞', '✌️', '👌', '🤭', '🫢', '🥹', '😌', '💋', '👑',
		'📸', '🧸', '🕯️', '🎈', '🪩', '💫', '⚡', '🛒', '💳', '🔖', '📣', '⏰', '⌛', '📍', '✈️', '🇰🇷', '🇹🇭', '✅', '❗', '➡️', '⬇️', '👆' ];

	/* ─────────────────────────── shapes ─────────────────────────── */

	/*
	 * `group` is the heading a shape sits under. The first twelve are drawn by the editor's own cases;
	 * the rest are described here — `sides` (a regular polygon), `path` (on the 100 × 100 box), `ring`,
	 * `outline`, `line`, or an overlay with `full` and `place` — and drawn from that description.
	 * `under` puts a shape just above the photo, below any words.
	 */
	var shapes = [
		{ group: 'basic', kind: 'rect', name: 'Square' },
		{ group: 'basic', kind: 'rounded', name: 'Rounded' },
		{ group: 'basic', kind: 'circle', name: 'Circle' },
		{ group: 'basic', kind: 'triangle', name: 'Triangle' },
		{ group: 'lines', kind: 'line', name: 'Line' },
		{ group: 'basic', kind: 'star', name: 'Star' },
		{ group: 'frames', kind: 'frame', name: 'Frame' },
		{ group: 'overlays', kind: 'band', name: 'Band, bottom' },
		{ group: 'overlays', kind: 'shade-bottom', name: 'Shade, bottom' },
		{ group: 'overlays', kind: 'shade-top', name: 'Shade, top' },
		{ group: 'overlays', kind: 'tint', name: 'Darken' },
		{ group: 'overlays', kind: 'glow', name: 'Lighten' },
		{ group: 'basic', kind: 'hexagon', name: 'Hexagon', sides: 6, fill: ROSE },
		{ group: 'basic', kind: 'pentagon', name: 'Pentagon', sides: 5, fill: SAND },
		{ group: 'basic', kind: 'octagon', name: 'Octagon', sides: 8, fill: INK },
		{ group: 'basic', kind: 'diamond', name: 'Diamond', path: 'M50 2 L98 50 L50 98 L2 50 Z', fill: ROSE },
		{ group: 'basic', kind: 'slant', name: 'Slant', path: 'M26 18 H98 L74 82 H2 Z', fill: INK, wide: true },
		{ group: 'basic', kind: 'arch', name: 'Arch', path: 'M10 98 V46 A40 40 0 0 1 90 46 V98 Z', fill: SAND },
		{ group: 'basic', kind: 'half', name: 'Half circle', path: 'M2 74 A48 48 0 0 1 98 74 Z', fill: ROSE },
		{ group: 'basic', kind: 'heart', name: 'Heart', path: HEART, fill: '#e86f8f' },
		{ group: 'basic', kind: 'plus', name: 'Plus', path: 'M38 4 H62 V38 H96 V62 H62 V96 H38 V62 H4 V38 H38 Z', fill: INK },
		{ group: 'basic', kind: 'arrow', name: 'Arrow', path: 'M4 38 H60 V16 L96 50 L60 84 V62 H4 Z', fill: ROSE, wide: true },
		{ group: 'basic', kind: 'chevron', name: 'Chevron', path: 'M4 12 H64 L96 50 L64 88 H4 L36 50 Z', fill: INK, wide: true },
		{ group: 'basic', kind: 'bubble', name: 'Speech', path: 'M10 8 H90 Q98 8 98 16 V62 Q98 70 90 70 H42 L22 92 L26 70 H10 Q2 70 2 62 V16 Q2 8 10 8 Z', fill: WHITE },
		{ group: 'basic', kind: 'blob', name: 'Blob', path: 'M52 6 C 74 4 96 20 94 46 C 92 70 80 94 52 94 C 26 94 6 80 6 54 C 6 26 28 8 52 6 Z', fill: PINK },
		{ group: 'basic', kind: 'wave', name: 'Wave', path: 'M0 40 Q 25 20 50 40 T 100 40 V100 H0 Z', fill: SAND, wide: true },
		{ group: 'outlines', kind: 'ring', name: 'Ring', ring: true },
		{ group: 'outlines', kind: 'rect-outline', name: 'Box outline', outline: 'rect' },
		{ group: 'outlines', kind: 'round-outline', name: 'Rounded outline', outline: 'rounded' },
		{ group: 'outlines', kind: 'circle-outline', name: 'Circle outline', outline: 'circle' },
		{ group: 'outlines', kind: 'heart-outline', name: 'Heart outline', path: HEART, stroke: ROSE_DEEP },
		{ group: 'outlines', kind: 'star-outline', name: 'Star outline', path: starPath( 50, 52, 48, 20, 5 ), stroke: INK },
		{ group: 'lines', kind: 'line-dashed', name: 'Dashed line', line: 'dash' },
		{ group: 'lines', kind: 'line-dotted', name: 'Dotted line', line: 'dot' },
		{ group: 'lines', kind: 'line-double', name: 'Double line', line: 'double' },
		{ group: 'lines', kind: 'line-thick', name: 'Thick line', line: 'thick' },
		{ group: 'frames', kind: 'frame-double', name: 'Double frame', full: true },
		{ group: 'frames', kind: 'corners', name: 'Corners', full: true },
		{ group: 'frames', kind: 'frame-thin', name: 'Hairline frame', full: true },
		{ group: 'frames', kind: 'frame-arch', name: 'Arch window', full: true, under: true },
		{ group: 'frames', kind: 'frame-circle', name: 'Circle window', full: true, under: true },
		{ group: 'overlays', kind: 'band-top', name: 'Band, top', full: true, place: 'top', under: true },
		{ group: 'overlays', kind: 'panel-left', name: 'Side panel', full: true, place: 'left', under: true },
		{ group: 'overlays', kind: 'wash-rose', name: 'Rose wash', full: true, under: true },
		{ group: 'overlays', kind: 'wash-cream', name: 'Cream wash', full: true, under: true },
		{ group: 'overlays', kind: 'shade-left', name: 'Shade, left', full: true, place: 'left', under: true }
	];

	var shapeGroups = [
		{ key: 'basic', name: 'Shapes' },
		{ key: 'outlines', name: 'Outlines' },
		{ key: 'lines', name: 'Lines' },
		{ key: 'frames', name: 'Frames' },
		{ key: 'overlays', name: 'Overlays' }
	];

	/* ─────────────────────────── effects ─────────────────────────── */

	/* Filter presets: Fabric filters to build, and a CSS filter that previews the look on a thumbnail. */
	var filters = [
		{ key: 'none', name: 'Original', css: 'none', build: function () { return []; } },
		{ key: 'warm', name: 'Warm', css: 'sepia(.25) saturate(1.15)', build: function ( F ) { return [ new F.BlendColor( { color: '#ff9a3c', mode: 'tint', alpha: 0.14 } ), new F.Saturation( { saturation: 0.1 } ) ]; } },
		{ key: 'cool', name: 'Cool', css: 'hue-rotate(-12deg) saturate(.9)', build: function ( F ) { return [ new F.BlendColor( { color: '#3c8dff', mode: 'tint', alpha: 0.12 } ) ]; } },
		{ key: 'vivid', name: 'Vivid', css: 'saturate(1.5) contrast(1.08)', build: function ( F ) { return [ new F.Saturation( { saturation: 0.35 } ), new F.Contrast( { contrast: 0.08 } ) ]; } },
		{ key: 'soft', name: 'Soft', css: 'brightness(1.06) contrast(.88) saturate(.9)', build: function ( F ) { return [ new F.Brightness( { brightness: 0.05 } ), new F.Contrast( { contrast: -0.12 } ), new F.Saturation( { saturation: -0.1 } ) ]; } },
		{ key: 'fade', name: 'Fade', css: 'brightness(1.08) contrast(.8) saturate(.7)', build: function ( F ) { return [ new F.Contrast( { contrast: -0.2 } ), new F.Brightness( { brightness: 0.07 } ), new F.Saturation( { saturation: -0.3 } ) ]; } },
		{ key: 'bw', name: 'B&W', css: 'grayscale(1)', build: function ( F ) { return [ new F.Grayscale() ]; } },
		{ key: 'noir', name: 'Noir', css: 'grayscale(1) contrast(1.35)', build: function ( F ) { return [ new F.Grayscale(), new F.Contrast( { contrast: 0.3 } ) ]; } },
		{ key: 'vintage', name: 'Vintage', css: 'sepia(.5) contrast(1.05) saturate(.85)', build: function ( F ) { return [ new F.Vintage() ]; } },
		{ key: 'sepia', name: 'Sepia', css: 'sepia(1)', build: function ( F ) { return [ new F.Sepia() ]; } },
		{ key: 'kodachrome', name: 'Kodachrome', css: 'saturate(1.3) contrast(1.15) sepia(.1)', build: function ( F ) { return [ new F.Kodachrome() ]; } },
		{ key: 'polaroid', name: 'Polaroid', css: 'contrast(1.1) saturate(1.2) hue-rotate(-6deg)', build: function ( F ) { return [ new F.Polaroid() ]; } },
		{ key: 'technicolor', name: 'Technicolor', css: 'saturate(1.6) contrast(1.1)', build: function ( F ) { return [ new F.Technicolor() ]; } },
		{ key: 'brownie', name: 'Brownie', css: 'sepia(.6) saturate(1.2) contrast(1.1)', build: function ( F ) { return [ new F.Brownie() ]; } },
		{ key: 'golden', name: 'Golden hour', css: 'sepia(.3) saturate(1.3) brightness(1.05)', build: function ( F ) { return [ new F.BlendColor( { color: '#ffb347', mode: 'tint', alpha: 0.18 } ), new F.Brightness( { brightness: 0.04 } ), new F.Saturation( { saturation: 0.15 } ) ]; } },
		{ key: 'rose', name: 'Rose', css: 'sepia(.2) hue-rotate(-14deg) saturate(1.1)', build: function ( F ) { return [ new F.BlendColor( { color: ROSE, mode: 'tint', alpha: 0.16 } ), new F.Brightness( { brightness: 0.03 } ) ]; } },
		{ key: 'blush', name: 'Blush', css: 'brightness(1.08) saturate(1.1) hue-rotate(-8deg)', build: function ( F ) { return [ new F.BlendColor( { color: '#ffc0cb', mode: 'tint', alpha: 0.14 } ), new F.Brightness( { brightness: 0.06 } ), new F.Contrast( { contrast: -0.06 } ) ]; } },
		{ key: 'film', name: 'Film', css: 'contrast(.95) saturate(.85) sepia(.15)', build: function ( F ) { return [ new F.Contrast( { contrast: -0.05 } ), new F.Saturation( { saturation: -0.15 } ), new F.BlendColor( { color: '#e6b980', mode: 'tint', alpha: 0.1 } ), new F.Noise( { noise: 30 } ) ]; } },
		{ key: 'matte', name: 'Matte', css: 'contrast(.82) brightness(1.06) saturate(.9)', build: function ( F ) { return [ new F.ColorMatrix( { matrix: [ 0.86, 0, 0, 0, 0.07, 0, 0.86, 0, 0, 0.07, 0, 0, 0.86, 0, 0.08, 0, 0, 0, 1, 0 ] } ) ]; } },
		{ key: 'moody', name: 'Moody', css: 'brightness(.88) contrast(1.15) saturate(.75)', build: function ( F ) { return [ new F.Brightness( { brightness: -0.08 } ), new F.Contrast( { contrast: 0.15 } ), new F.Saturation( { saturation: -0.25 } ) ]; } },
		{ key: 'pastel', name: 'Pastel', css: 'brightness(1.1) contrast(.85) saturate(.8)', build: function ( F ) { return [ new F.Brightness( { brightness: 0.08 } ), new F.Contrast( { contrast: -0.12 } ), new F.Saturation( { saturation: -0.2 } ) ]; } },
		{ key: 'nordic', name: 'Nordic', css: 'brightness(1.05) saturate(.8) hue-rotate(8deg)', build: function ( F ) { return [ new F.BlendColor( { color: '#a8c8e8', mode: 'tint', alpha: 0.12 } ), new F.Brightness( { brightness: 0.05 } ), new F.Saturation( { saturation: -0.15 } ) ]; } },
		{ key: 'cinema', name: 'Cinematic', css: 'contrast(1.15) saturate(1.1) hue-rotate(-6deg)', build: function ( F ) { return [ new F.ColorMatrix( { matrix: [ 1.1, 0, 0, 0, -0.02, 0, 1.0, 0.05, 0, -0.01, 0, 0.05, 1.1, 0, 0.02, 0, 0, 0, 1, 0 ] } ), new F.Contrast( { contrast: 0.12 } ) ]; } },
		{ key: 'crisp', name: 'Crisp', css: 'contrast(1.12) saturate(1.15)', build: function ( F ) { return [ new F.Convolute( { matrix: [ 0, -0.5, 0, -0.5, 3, -0.5, 0, -0.5, 0 ] } ), new F.Saturation( { saturation: 0.1 } ) ]; } },
		{ key: 'chrome', name: 'Chrome', css: 'contrast(1.2) saturate(1.25)', build: function ( F ) { return [ new F.Contrast( { contrast: 0.14 } ), new F.Saturation( { saturation: 0.22 } ) ]; } },
		{ key: 'bleach', name: 'Bleach', css: 'saturate(.5) contrast(1.15)', build: function ( F ) { return [ new F.Saturation( { saturation: -0.5 } ), new F.Contrast( { contrast: 0.15 } ) ]; } },
		{ key: 'dream', name: 'Dreamy', css: 'brightness(1.08) contrast(.9) blur(.4px)', build: function ( F ) { return [ new F.Blur( { blur: 0.03 } ), new F.Brightness( { brightness: 0.06 } ), new F.Contrast( { contrast: -0.08 } ) ]; } },
		{ key: 'lomo', name: 'Lomo', css: 'contrast(1.3) saturate(1.35)', build: function ( F ) { return [ new F.Contrast( { contrast: 0.25 } ), new F.Saturation( { saturation: 0.3 } ) ]; } },
		{ key: 'mono-soft', name: 'Soft B&W', css: 'grayscale(1) contrast(.85) brightness(1.08)', build: function ( F ) { return [ new F.Grayscale(), new F.Contrast( { contrast: -0.12 } ), new F.Brightness( { brightness: 0.06 } ) ]; } },
		{ key: 'mono-rose', name: 'Rose mono', css: 'grayscale(1) sepia(.35) hue-rotate(-20deg)', build: function ( F ) { return [ new F.Grayscale(), new F.BlendColor( { color: ROSE, mode: 'tint', alpha: 0.25 } ) ]; } },
		{ key: 'pop', name: 'Pop', css: 'saturate(1.8) contrast(1.1)', build: function ( F ) { return [ new F.Saturation( { saturation: 0.6 } ), new F.Vibrance( { vibrance: 0.3 } ), new F.Contrast( { contrast: 0.06 } ) ]; } }
	];

	/* Adjustments: slider ranges, and the filter each one becomes. */
	var adjust = [
		{ key: 'brightness', min: -0.5, max: 0.5, step: 0.01 },
		{ key: 'contrast', min: -0.5, max: 0.5, step: 0.01 },
		{ key: 'saturation', min: -1, max: 1, step: 0.01 },
		{ key: 'vibrance', min: -1, max: 1, step: 0.01 },
		{ key: 'warmth', min: -1, max: 1, step: 0.01 },
		{ key: 'hue', min: -1, max: 1, step: 0.01 },
		{ key: 'blur', min: 0, max: 0.5, step: 0.01 },
		{ key: 'grain', min: 0, max: 200, step: 1 },
		{ key: 'vignette', min: 0, max: 0.9, step: 0.01 },
		{ key: 'sharpen', min: 0, max: 1, step: 0.05 },
		{ key: 'fade', min: 0, max: 0.4, step: 0.01 },
		{ key: 'tint', min: 0, max: 0.5, step: 0.01 },
		{ key: 'pixelate', min: 0, max: 30, step: 1 }
	];

	/* ─────────────────────────── templates ───────────────────────────
	   A template is a list of pieces laid over the photo, in fractions of the width and height. The
	   editor builds each piece with its own makers, so a template's text is ordinary text afterwards. */

	var templates = [
		/* The order-received card (WHD → Settings → "Order share card"): pick the Story / Reel size
		   first. The photo is swapped for the piece that was bought; {product} and {first_name}
		   are filled in on the shopper's screen. */
		{ name: 'Just got mine (order card)', pieces: [
			{ kind: 'shade', side: 'bottom', strength: 0.65 },
			{ kind: 'text', text: 'Just got mine.', font: 'Cormorant Garamond', style: 'italic', weight: 600, s: 0.13, fill: WHITE, x: 0.5, y: 0.78, shadow: true },
			{ kind: 'text', text: '{product}', font: 'Montserrat', weight: 500, s: 0.036, fill: WHITE, x: 0.5, y: 0.855, width: 0.82 },
			{ kind: 'line', x: 0.5, y: 0.9, w: 0.1, color: WHITE },
			{ kind: 'text', text: 'OOPS, MINE CO.', font: 'Montserrat', weight: 700, s: 0.028, fill: WHITE, spacing: 400, x: 0.5, y: 0.935, brand: true }
		] },
		{ name: 'New arrival', pieces: [
			{ kind: 'shade', side: 'bottom', strength: 0.6 },
			{ kind: 'text', text: 'New Arrival', font: 'Cormorant Garamond', style: 'italic', weight: 600, s: 0.11, fill: WHITE, x: 0.07, y: 0.8, align: 'left', origin: 'left' },
			{ kind: 'text', text: 'SHOP THE COLLECTION', font: 'Montserrat', weight: 600, s: 0.03, fill: WHITE, spacing: 300, x: 0.075, y: 0.9, align: 'left', origin: 'left' }
		] },
		{ name: 'Sale', pieces: [
			{ kind: 'tint', strength: 0.25 },
			{ kind: 'text', text: 'SALE', font: 'Anton', weight: 400, s: 0.3, fill: WHITE, x: 0.5, y: 0.46, shadow: true },
			{ kind: 'badge', shape: 'pill', text: 'UP TO 50% OFF', fill: WHITE, color: INK, font: 'Montserrat', weight: 800, s: 0.3, x: 0.5, y: 0.64 }
		] },
		{ name: 'Price tag', pieces: [
			{ kind: 'text', text: 'Your product', title: true, font: 'Playfair Display', weight: 700, s: 0.06, fill: WHITE, x: 0.5, y: 0.82, shadow: true },
			{ kind: 'badge', shape: 'tag', text: '₱1,890', fill: WHITE, color: INK, font: 'League Spartan', weight: 800, s: 0.24, x: 0.5, y: 0.91 }
		] },
		{ name: 'Quote', pieces: [
			{ kind: 'box', fill: 'rgba(255,255,255,0.82)', x: 0.5, y: 0.5, w: 0.78, h: 0.32, radius: 0.02 },
			{ kind: 'text', text: '“Wear what makes you feel like you.”', font: 'Cormorant Garamond', style: 'italic', weight: 600, s: 0.065, fill: INK, x: 0.5, y: 0.47, width: 0.68, lineHeight: 1.1 },
			{ kind: 'text', text: '— OOPS, MINE CO.', font: 'Montserrat', weight: 600, s: 0.024, fill: ROSE_DEEP, spacing: 300, x: 0.5, y: 0.6, brand: true }
		] },
		{ name: 'Story: shop now', pieces: [
			{ kind: 'shade', side: 'top', strength: 0.45 },
			{ kind: 'text', text: 'THIS WEEK’S EDIT', font: 'Montserrat', weight: 800, s: 0.06, fill: WHITE, spacing: 200, x: 0.5, y: 0.1 },
			{ kind: 'badge', shape: 'pill', text: 'SHOP NOW ↑', fill: WHITE, color: INK, font: 'Montserrat', weight: 800, s: 0.32, x: 0.5, y: 0.9 }
		] },
		{ name: 'Minimal caption', pieces: [
			{ kind: 'line', x: 0.5, y: 0.9, w: 0.12, color: WHITE },
			{ kind: 'text', text: 'Oops, Mine Co.', font: 'Cormorant Garamond', weight: 600, s: 0.045, fill: WHITE, x: 0.5, y: 0.94, brand: true, shadow: true }
		] },
		{ name: 'Limited', pieces: [
			{ kind: 'badge', shape: 'ribbon', text: 'LIMITED', fill: ROSE_DEEP, color: WHITE, font: 'Oswald', weight: 600, s: 0.42, x: 0.22, y: 0.1, angle: -12 },
			{ kind: 'badge', shape: 'circle', text: 'ONLY 5 LEFT', fill: INK, color: SAND, font: 'Montserrat', weight: 800, s: 0.22, x: 0.84, y: 0.86 }
		] },
		{ name: 'Lookbook', pieces: [
			{ kind: 'text', text: 'LOOKBOOK', font: 'Playfair Display', weight: 900, s: 0.14, fill: WHITE, spacing: 80, x: 0.5, y: 0.1, shadow: true },
			{ kind: 'text', text: 'Autumn · No. 04', font: 'Cormorant Garamond', style: 'italic', weight: 600, s: 0.045, fill: WHITE, x: 0.5, y: 0.18, shadow: true }
		] },
		{ name: 'Framed', pieces: [
			{ kind: 'frame', inset: 0.035, width: 0.012, color: WHITE },
			{ kind: 'text', text: 'OOPS, MINE CO.', font: 'Montserrat', weight: 700, s: 0.026, fill: WHITE, spacing: 400, x: 0.5, y: 0.93, brand: true, shadow: true }
		] },
		{ name: 'Polaroid', pieces: [
			{ kind: 'polaroid', caption: 'weekend uniform', font: 'Caveat', weight: 700, s: 0.07, fill: INK }
		] },
		{ name: 'Caption band', pieces: [
			{ kind: 'box', fill: SAND, x: 0.5, y: 0.915, w: 1, h: 0.17, radius: 0 },
			{ kind: 'text', text: 'The Wrap Vest', font: 'Playfair Display', weight: 700, s: 0.06, fill: INK, x: 0.5, y: 0.895 },
			{ kind: 'text', text: 'LAYER IT OVER EVERYTHING', font: 'Montserrat', weight: 600, s: 0.024, fill: ROSE_DEEP, spacing: 300, x: 0.5, y: 0.95 }
		] },
		{ name: 'Corner badge', pieces: [
			{ kind: 'badge', shape: 'burst', text: 'NEW', fill: ROSE, color: WHITE, font: 'Anton', weight: 400, s: 0.22, x: 0.83, y: 0.15, angle: 12 }
		] },
		{ name: 'Duo type', pieces: [
			{ kind: 'text', text: 'soft', font: 'Great Vibes', weight: 400, s: 0.16, fill: WHITE, x: 0.42, y: 0.42, shadow: true },
			{ kind: 'text', text: 'STRUCTURE', font: 'Bebas Neue', weight: 400, s: 0.16, fill: WHITE, spacing: 60, x: 0.5, y: 0.53, shadow: true }
		] },
		{ name: 'Testimonial', pieces: [
			{ kind: 'shade', side: 'bottom', strength: 0.55 },
			{ kind: 'text', text: '★★★★★', font: 'Montserrat', weight: 700, s: 0.05, fill: '#f2c14e', x: 0.5, y: 0.74, spacing: 100 },
			{ kind: 'text', text: '“Fits like it was made for me.”', font: 'DM Serif Display', style: 'italic', weight: 400, s: 0.06, fill: WHITE, x: 0.5, y: 0.82, width: 0.8 },
			{ kind: 'text', text: 'A HAPPY CUSTOMER', font: 'Montserrat', weight: 600, s: 0.022, fill: WHITE, spacing: 300, x: 0.5, y: 0.91 }
		] },
		{ name: 'OOTD', pieces: [
			{ kind: 'shade', side: 'top', strength: 0.4 },
			{ kind: 'text', text: 'OOTD', font: 'Archivo Black', weight: 400, s: 0.2, fill: WHITE, spacing: 60, x: 0.5, y: 0.12, shadow: true },
			{ kind: 'text', text: 'outfit of the day', font: 'Caveat', weight: 700, s: 0.06, fill: WHITE, x: 0.5, y: 0.22, shadow: true }
		] },
		{ name: 'Get the look', pieces: [
			{ kind: 'box', fill: 'rgba(255,255,255,0.9)', x: 0.5, y: 0.86, w: 0.84, h: 0.16, radius: 0.015 },
			{ kind: 'text', text: 'Get the look', font: 'Allura', weight: 400, s: 0.09, fill: INK, x: 0.5, y: 0.835 },
			{ kind: 'text', text: 'TAP TO SHOP EVERY PIECE', font: 'Montserrat', weight: 700, s: 0.022, fill: ROSE_DEEP, spacing: 300, x: 0.5, y: 0.9 }
		] },
		{ name: 'Back in stock', pieces: [
			{ kind: 'tint', strength: 0.15 },
			{ kind: 'badge', shape: 'burst', text: 'BACK IN STOCK', fill: ROSE, color: WHITE, font: 'Oswald', weight: 600, s: 0.3, x: 0.78, y: 0.18, angle: 10 },
			{ kind: 'text', text: 'It came back.', font: 'Cormorant Garamond', style: 'italic', weight: 600, s: 0.1, fill: WHITE, x: 0.5, y: 0.86, shadow: true }
		] },
		{ name: 'Coming soon', pieces: [
			{ kind: 'tint', strength: 0.45 },
			{ kind: 'text', text: 'COMING SOON', font: 'Josefin Sans', weight: 300, s: 0.08, fill: WHITE, spacing: 500, x: 0.5, y: 0.46 },
			{ kind: 'line', x: 0.5, y: 0.54, w: 0.12, color: WHITE },
			{ kind: 'text', text: 'Be first — link in bio', font: 'Montserrat', weight: 500, s: 0.03, fill: WHITE, x: 0.5, y: 0.6 }
		] },
		{ name: 'Sold out', pieces: [
			{ kind: 'tint', strength: 0.35 },
			{ kind: 'badge', shape: 'stamp', text: 'SOLD OUT', fill: 'rgba(0,0,0,0)', color: WHITE, font: 'Archivo Black', weight: 400, s: 0.42, x: 0.5, y: 0.5, angle: -14 }
		] },
		{ name: 'Gift guide', pieces: [
			{ kind: 'frame', inset: 0.04, width: 0.008, color: WHITE },
			{ kind: 'text', text: 'The Gift Guide', font: 'Great Vibes', weight: 400, s: 0.13, fill: WHITE, x: 0.5, y: 0.13, shadow: true },
			{ kind: 'text', text: 'FOR HER · UNDER $50', font: 'Montserrat', weight: 700, s: 0.026, fill: WHITE, spacing: 300, x: 0.5, y: 0.22, shadow: true }
		] },
		{ name: 'Live tonight', pieces: [
			{ kind: 'shade', side: 'bottom', strength: 0.6 },
			{ kind: 'badge', shape: 'pill', text: 'LIVE TONIGHT · 8PM', fill: RED, color: WHITE, font: 'Montserrat', weight: 800, s: 0.34, x: 0.5, y: 0.1 },
			{ kind: 'text', text: 'Join us on Facebook Live', font: 'DM Serif Display', weight: 400, s: 0.07, fill: WHITE, x: 0.5, y: 0.84, width: 0.84 },
			{ kind: 'text', text: 'NEW PIECES, FIRST LOOK', font: 'Montserrat', weight: 600, s: 0.024, fill: WHITE, spacing: 300, x: 0.5, y: 0.92 }
		] },
		{ name: 'Size note', pieces: [
			{ kind: 'box', fill: 'rgba(58,43,38,0.78)', x: 0.5, y: 0.94, w: 1, h: 0.12, radius: 0 },
			{ kind: 'text', text: 'Model is 5′5″ and wears S', font: 'Montserrat', weight: 600, s: 0.03, fill: WHITE, x: 0.5, y: 0.94, spacing: 60 }
		] },
		{ name: 'Thank you card', pieces: [
			{ kind: 'box', fill: SAND, x: 0.5, y: 0.5, w: 0.74, h: 0.36, radius: 0.02 },
			{ kind: 'text', text: 'thank you', font: 'Parisienne', weight: 400, s: 0.13, fill: ROSE_DEEP, x: 0.5, y: 0.45 },
			{ kind: 'text', text: 'FOR SHOPPING SMALL', font: 'Montserrat', weight: 700, s: 0.026, fill: INK, spacing: 300, x: 0.5, y: 0.56 },
			{ kind: 'text', text: 'OOPS, MINE CO.', font: 'Cinzel', weight: 700, s: 0.024, fill: ROSE_DEEP, spacing: 300, x: 0.5, y: 0.62, brand: true }
		] },
		{ name: 'Magazine cover', pieces: [
			{ kind: 'shade', side: 'top', strength: 0.35 },
			{ kind: 'text', text: 'OOPS', font: 'Playfair Display', weight: 900, s: 0.26, fill: WHITE, spacing: -20, x: 0.5, y: 0.12, shadow: true },
			{ kind: 'text', text: 'THE AUTUMN ISSUE', font: 'Montserrat', weight: 700, s: 0.024, fill: WHITE, spacing: 400, x: 0.5, y: 0.23 },
			{ kind: 'text', text: '12 pieces we can’t stop wearing', font: 'Bodoni Moda', style: 'italic', weight: 400, s: 0.045, fill: WHITE, x: 0.07, y: 0.82, width: 0.45, align: 'left', origin: 'left', shadow: true }
		] },
		{ name: 'Before / after', pieces: [
			{ kind: 'box', fill: WHITE, x: 0.5, y: 0.5, w: 0.006, h: 1, radius: 0 },
			{ kind: 'badge', shape: 'pill', text: 'BEFORE', fill: WHITE, color: INK, font: 'Montserrat', weight: 800, s: 0.2, x: 0.25, y: 0.07 },
			{ kind: 'badge', shape: 'pill', text: 'AFTER', fill: INK, color: WHITE, font: 'Montserrat', weight: 800, s: 0.2, x: 0.75, y: 0.07 }
		] },
		{ name: 'Weekend edit', pieces: [
			{ kind: 'shade', side: 'bottom', strength: 0.5 },
			{ kind: 'text', text: 'weekend edit', font: 'Lora', style: 'italic', weight: 600, s: 0.1, fill: WHITE, x: 0.5, y: 0.84, shadow: true },
			{ kind: 'text', text: 'SAT · SUN', font: 'Montserrat', weight: 700, s: 0.024, fill: WHITE, spacing: 400, x: 0.5, y: 0.92 }
		] }
	];

	window.WHDImagePresets = {
		colors: { rose: ROSE, roseDeep: ROSE_DEEP, ink: INK, sand: SAND, white: WHITE },
		sizes: sizes,
		text: text,
		combos: combos,
		badges: badges,
		decor: decor,
		emoji: emoji,
		shapes: shapes,
		shapeGroups: shapeGroups,
		filters: filters,
		adjust: adjust,
		templates: templates
	};
}() );
