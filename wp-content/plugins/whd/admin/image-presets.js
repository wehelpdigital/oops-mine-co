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

	/* ─────────────────────────── sizes ─────────────────────────── */

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
		{ name: 'Email header', w: 1200, h: 600, note: '1200 × 600' }
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
		{ name: 'Grotesk', text: 'Now online', font: 'Space Grotesk', weight: 700, s: 0.08, fill: INK }
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
		{ shape: 'pill', text: 'SWIPE UP ↑', fill: WHITE, color: INK, font: 'Montserrat', weight: 800 }
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
		{ name: 'Flower', flower: true, fill: '#f4a6b8', centre: '#f2c14e' }
	];

	var emoji = [ '❤️', '✨', '🔥', '💕', '🌸', '🛍️', '👗', '👚', '👖', '👜', '👠', '👟', '💄', '💅', '🌟', '⭐', '💯', '🎉', '🎁', '☀️', '🍂', '❄️', '🌿', '💐', '🌷', '😍', '🥰', '😎', '👀', '🙌', '👇', '👉', '🆕', '💸', '🏷️', '📦', '💌', '🤍', '🖤', '🤎' ];

	/* ─────────────────────────── shapes ─────────────────────────── */

	var shapes = [
		{ kind: 'rect', name: 'Square' },
		{ kind: 'rounded', name: 'Rounded' },
		{ kind: 'circle', name: 'Circle' },
		{ kind: 'triangle', name: 'Triangle' },
		{ kind: 'line', name: 'Line' },
		{ kind: 'star', name: 'Star' },
		{ kind: 'frame', name: 'Frame' },
		{ kind: 'band', name: 'Band' },
		{ kind: 'shade-bottom', name: 'Shade, bottom' },
		{ kind: 'shade-top', name: 'Shade, top' },
		{ kind: 'tint', name: 'Darken' },
		{ kind: 'glow', name: 'Lighten' }
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
		{ key: 'brownie', name: 'Brownie', css: 'sepia(.6) saturate(1.2) contrast(1.1)', build: function ( F ) { return [ new F.Brownie() ]; } }
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
		{ key: 'vignette', min: 0, max: 0.9, step: 0.01 }
	];

	/* ─────────────────────────── templates ───────────────────────────
	   A template is a list of pieces laid over the photo, in fractions of the width and height. The
	   editor builds each piece with its own makers, so a template's text is ordinary text afterwards. */

	var templates = [
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
		filters: filters,
		adjust: adjust,
		templates: templates
	};
}() );
