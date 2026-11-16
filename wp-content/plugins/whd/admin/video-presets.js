/**
 * What the Video Editor offers, as data: sizes, ready-made text, text styles, how things come in, go out
 * and move while they are on screen, emoji, animated effects, filters, transitions and slow zooms.
 *
 * The renderer (video-engine.js) draws every one of these by key, the same way in the preview and in the
 * export, so adding one here and a case there is all a new one needs. Labels are English, like the Image
 * Editor's presets; the screen's own words come translated from PHP.
 */
( function () {
	'use strict';

	var sizes = [
		{ key: 'reel', name: 'Reel · TikTok · Story — 9:16', w: 1080, h: 1920 },
		{ key: 'feed', name: 'Feed post — 4:5', w: 1080, h: 1350 },
		{ key: 'square', name: 'Square — 1:1', w: 1080, h: 1080 },
		{ key: 'landscape', name: 'Landscape — 16:9', w: 1920, h: 1080 },
		{ key: 'banner', name: 'Website banner — 21:9', w: 2520, h: 1080 },
		{ key: 'pin', name: 'Pinterest — 2:3', w: 1000, h: 1500 },
		{ key: 'reel720', name: 'Reel, smaller file — 720 × 1280', w: 720, h: 1280 },
		{ key: 'hd720', name: 'Landscape, smaller file — 1280 × 720', w: 1280, h: 720 }
	];

	/* The look of a text: how its letters and whatever is behind them are painted. */
	var textStyles = [
		{ key: 'plain', name: 'Plain' },
		{ key: 'shadow', name: 'Soft shadow' },
		{ key: 'outline', name: 'Outline' },
		{ key: 'stroke', name: 'Outline only' },
		{ key: 'box', name: 'Box behind' },
		{ key: 'pill', name: 'Pill behind' },
		{ key: 'highlight', name: 'Highlighter' },
		{ key: 'neon', name: 'Neon glow' },
		{ key: 'gradient', name: 'Gradient' },
		{ key: 'retro', name: 'Retro offset' },
		{ key: 'glass', name: 'Frosted card' }
	];

	var animsIn = [
		{ key: 'none', name: 'Just appears' },
		{ key: 'fade', name: 'Fade' },
		{ key: 'rise', name: 'Letters rise' },
		{ key: 'typewriter', name: 'Typewriter' },
		{ key: 'pop', name: 'Pop' },
		{ key: 'zoom', name: 'Zoom in' },
		{ key: 'bounce', name: 'Bounce down' },
		{ key: 'slideUp', name: 'Slide up' },
		{ key: 'slideDown', name: 'Slide down' },
		{ key: 'slideLeft', name: 'Slide in from the right' },
		{ key: 'slideRight', name: 'Slide in from the left' },
		{ key: 'blur', name: 'Blur in' },
		{ key: 'wipe', name: 'Wipe' },
		{ key: 'spin', name: 'Spin in' },
		{ key: 'flicker', name: 'Flicker on' }
	];

	var animsOut = [
		{ key: 'none', name: 'Just goes' },
		{ key: 'fade', name: 'Fade' },
		{ key: 'pop', name: 'Shrink away' },
		{ key: 'zoom', name: 'Zoom out' },
		{ key: 'slideUp', name: 'Slide up' },
		{ key: 'slideDown', name: 'Slide down' },
		{ key: 'slideLeft', name: 'Slide out to the left' },
		{ key: 'slideRight', name: 'Slide out to the right' },
		{ key: 'blur', name: 'Blur out' },
		{ key: 'wipe', name: 'Wipe' },
		{ key: 'flicker', name: 'Flicker off' }
	];

	var loops = [
		{ key: 'none', name: 'Stays still' },
		{ key: 'pulse', name: 'Pulse' },
		{ key: 'heartbeat', name: 'Heartbeat' },
		{ key: 'float', name: 'Float' },
		{ key: 'wobble', name: 'Wobble' },
		{ key: 'swing', name: 'Swing' },
		{ key: 'shake', name: 'Shake' },
		{ key: 'jelly', name: 'Jelly' },
		{ key: 'hop', name: 'Hop' },
		{ key: 'spin', name: 'Spin' },
		{ key: 'glow', name: 'Glow' }
	];

	/*
	 * Ready-made text. size is a share of the frame's height; y where its middle sits (0 top, 1 bottom).
	 * {price} is filled with the shop's currency in the editor.
	 */
	var texts = [
		{ name: 'New in', text: 'NEW IN', font: 'Bebas Neue', weight: 400, size: 0.085, color: '#ffffff', color2: '#b98b7e', style: 'box', spacing: 6, y: 0.18, anim: { in: 'pop', out: 'fade', loop: 'none' } },
		{ name: 'Headline', text: 'Your new favourite', font: 'Playfair Display', weight: 700, italic: true, size: 0.06, color: '#ffffff', style: 'shadow', y: 0.2, anim: { in: 'rise', out: 'fade', loop: 'none' } },
		{ name: 'Just landed', text: 'Just landed', font: 'Great Vibes', weight: 400, size: 0.085, color: '#ffffff', style: 'shadow', y: 0.25, anim: { in: 'fade', out: 'fade', loop: 'float' } },
		{ name: 'Sale', text: 'SALE −30%', font: 'Anton', weight: 400, size: 0.08, color: '#ffffff', color2: '#e0245e', style: 'box', y: 0.8, anim: { in: 'pop', out: 'pop', loop: 'pulse' } },
		{ name: 'Price tag', text: '{price}49', font: 'Montserrat', weight: 800, size: 0.055, color: '#1d1d1f', color2: '#ffffff', style: 'pill', y: 0.72, x: 0.72, anim: { in: 'slideLeft', out: 'fade', loop: 'none' } },
		{ name: 'Shop now', text: 'Shop now →', font: 'Manrope', weight: 800, size: 0.05, color: '#1d1d1f', color2: '#ffffff', style: 'pill', y: 0.86, anim: { in: 'bounce', out: 'fade', loop: 'heartbeat' } },
		{ name: 'Link in bio', text: 'Link in bio', font: 'Manrope', weight: 700, size: 0.042, color: '#ffffff', color2: '#b98b7e', style: 'pill', y: 0.9, anim: { in: 'slideUp', out: 'fade', loop: 'hop' } },
		{ name: 'Caption', text: 'Soft knit, made for slow mornings', font: 'Inter', weight: 600, size: 0.034, color: '#ffffff', color2: 'rgba(0,0,0,0.55)', style: 'box', y: 0.82, anim: { in: 'fade', out: 'fade', loop: 'none' } },
		{ name: 'Neon', text: 'obsessed', font: 'Righteous', weight: 400, size: 0.075, color: '#ff4f8b', style: 'neon', y: 0.3, anim: { in: 'flicker', out: 'flicker', loop: 'glow' } },
		{ name: 'Retro', text: 'Weekend mood', font: 'Shrikhand', weight: 400, size: 0.065, color: '#ffffff', color2: '#e0245e', style: 'retro', y: 0.22, anim: { in: 'zoom', out: 'fade', loop: 'wobble' } },
		{ name: 'Gradient', text: 'Glow up', font: 'Poppins', weight: 900, size: 0.085, color: '#f6dd9a', color2: '#b98b7e', style: 'gradient', y: 0.25, anim: { in: 'zoom', out: 'zoom', loop: 'none' } },
		{ name: 'Highlighter', text: 'only a few left', font: 'Permanent Marker', weight: 400, size: 0.05, color: '#1d1d1f', color2: '#ffe066', style: 'highlight', y: 0.78, anim: { in: 'wipe', out: 'fade', loop: 'none' } },
		{ name: 'Typewriter', text: 'Size S — XL', font: 'Courier Prime', weight: 700, size: 0.045, color: '#ffffff', color2: 'rgba(0,0,0,0.5)', style: 'box', y: 0.85, anim: { in: 'typewriter', out: 'fade', loop: 'none' } },
		{ name: 'Big and bold', text: 'MUST HAVE', font: 'Archivo Black', weight: 400, size: 0.09, color: '#ffffff', style: 'outline', color2: '#1d1d1f', y: 0.5, anim: { in: 'slideUp', out: 'slideUp', loop: 'none' } },
		{ name: 'Minimal', text: 'THE ESSENTIALS', font: 'Josefin Sans', weight: 300, size: 0.04, color: '#ffffff', style: 'plain', spacing: 14, y: 0.12, anim: { in: 'fade', out: 'fade', loop: 'none' } },
		{ name: 'Limited', text: 'LIMITED STOCK', font: 'League Spartan', weight: 800, size: 0.05, color: '#ffffff', color2: '#1d1d1f', style: 'box', y: 0.15, anim: { in: 'slideRight', out: 'fade', loop: 'shake' } },
		{ name: 'Elegant', text: 'Effortless', font: 'Cormorant Garamond', weight: 600, italic: true, size: 0.08, color: '#ffffff', style: 'shadow', y: 0.5, anim: { in: 'blur', out: 'blur', loop: 'none' } },
		{ name: 'Frosted', text: 'Back in stock', font: 'Raleway', weight: 800, size: 0.045, color: '#ffffff', color2: 'rgba(255,255,255,0.22)', style: 'glass', y: 0.2, anim: { in: 'pop', out: 'fade', loop: 'none' } },
		{ name: 'Spin', text: 'GIVEAWAY', font: 'Oswald', weight: 600, size: 0.07, color: '#ffffff', color2: '#8a1cff', style: 'box', y: 0.3, anim: { in: 'spin', out: 'pop', loop: 'swing' } },
		{ name: 'Handwritten', text: 'gift idea ♡', font: 'Caveat', weight: 700, size: 0.07, color: '#ffffff', style: 'shadow', y: 0.7, anim: { in: 'rise', out: 'fade', loop: 'jelly' } }
	];

	var emoji = [
		'✨', '💖', '❤️', '🔥', '⭐', '🌟', '💫', '🎉', '🎁', '🛍️', '👗', '👚', '👠', '👜', '💄', '💅',
		'🌸', '🌷', '🌹', '🌼', '🍂', '❄️', '☀️', '🌈', '💎', '👑', '💐', '🎀', '🧸', '🥂', '☕', '🍓',
		'😍', '🥰', '😎', '🤩', '😘', '🙌', '👏', '👀', '💯', '🆕', '💸', '🏷️', '📦', '🚚', '⏰', '📣',
		'➡️', '⬇️', '👉', '👆', '✅', '❗', '💬', '📸', '🎶', '🪩', '🫶', '💋', '🍒', '🦋', '🌙', '⚡'
	];

	/* Effects drawn over (or into) the video. camera ones move the picture itself. */
	var effects = [
		{ key: 'confetti', name: 'Confetti', icon: '🎉', color: '' },
		{ key: 'sparkles', name: 'Sparkles', icon: '✨', color: '#fff6d6' },
		{ key: 'hearts', name: 'Floating hearts', icon: '💗', color: '#ff6fa3' },
		{ key: 'snow', name: 'Snow', icon: '❄️', color: '#ffffff' },
		{ key: 'petals', name: 'Petals', icon: '🌸', color: '#f7b2c4' },
		{ key: 'bubbles', name: 'Bubbles', icon: '🫧', color: '#ffffff' },
		{ key: 'glitter', name: 'Falling glitter', icon: '⭐', color: '#e8c872' },
		{ key: 'bokeh', name: 'Bokeh lights', icon: '🟡', color: '#ffd9a0' },
		{ key: 'lightleak', name: 'Light leak', icon: '🌅', color: '#ff9a4d' },
		{ key: 'glow', name: 'Soft glow', icon: '🌕', color: '#fff2e0' },
		{ key: 'vignette', name: 'Vignette', icon: '⚫', color: '#000000' },
		{ key: 'grain', name: 'Film grain', icon: '🎞️', color: '' },
		{ key: 'letterbox', name: 'Cinema bars', icon: '🎬', color: '#000000' },
		{ key: 'flash', name: 'Flash', icon: '📸', color: '#ffffff' },
		{ key: 'zoompunch', name: 'Zoom punch', icon: '💥', color: '', camera: true },
		{ key: 'kenburns', name: 'Slow push in', icon: '🔍', color: '', camera: true },
		{ key: 'shake', name: 'Camera shake', icon: '📳', color: '', camera: true },
		{ key: 'glitch', name: 'Glitch', icon: '📺', color: '', camera: true },
		{ key: 'blurin', name: 'Blur to sharp', icon: '🌫️', color: '', camera: true },
		{ key: 'rgbpulse', name: 'Colour pop', icon: '🎨', color: '', camera: true }
	];

	/* Filters: a CSS filter for the canvas, and a tint laid over in soft light. */
	var filters = [
		{ key: 'none', name: 'Original', css: '' },
		{ key: 'vivid', name: 'Vivid', css: 'saturate(1.35) contrast(1.08)' },
		{ key: 'crisp', name: 'Crisp', css: 'contrast(1.15) saturate(1.06) brightness(1.02)' },
		{ key: 'warm', name: 'Warm', css: 'saturate(1.12) sepia(0.12)', tint: 'rgba(255,150,60,0.18)' },
		{ key: 'golden', name: 'Golden hour', css: 'saturate(1.25) sepia(0.22) brightness(1.04)', tint: 'rgba(255,170,70,0.22)' },
		{ key: 'cool', name: 'Cool', css: 'saturate(1.05) brightness(1.02)', tint: 'rgba(70,140,255,0.18)' },
		{ key: 'soft', name: 'Soft', css: 'contrast(0.92) brightness(1.06) saturate(0.95)' },
		{ key: 'rose', name: 'Rose', css: 'saturate(1.08) brightness(1.04)', tint: 'rgba(214,120,140,0.2)' },
		{ key: 'pastel', name: 'Pastel', css: 'saturate(0.78) brightness(1.1) contrast(0.9)', tint: 'rgba(255,190,210,0.14)' },
		{ key: 'fade', name: 'Faded', css: 'contrast(0.84) brightness(1.08) saturate(0.82)' },
		{ key: 'vintage', name: 'Vintage', css: 'sepia(0.42) contrast(0.95) saturate(0.9) brightness(1.02)' },
		{ key: 'dramatic', name: 'Dramatic', css: 'contrast(1.32) saturate(1.1) brightness(0.94)' },
		{ key: 'mono', name: 'Black & white', css: 'grayscale(1) contrast(1.05)' },
		{ key: 'noir', name: 'Noir', css: 'grayscale(1) contrast(1.35) brightness(0.92)' },
		{ key: 'dreamy', name: 'Dreamy', css: 'brightness(1.08) saturate(1.12) contrast(0.92)', tint: 'rgba(255,220,240,0.16)' }
	];

	/* From one clip into the next. overlap: the two run at the same time for the transition's length. */
	var transitions = [
		{ key: 'none', name: 'Straight cut', overlap: false },
		{ key: 'crossfade', name: 'Crossfade', overlap: true },
		{ key: 'fadeblack', name: 'Fade through black', overlap: false },
		{ key: 'fadewhite', name: 'Fade through white', overlap: false },
		{ key: 'slideleft', name: 'Slide left', overlap: true },
		{ key: 'slideup', name: 'Slide up', overlap: true },
		{ key: 'zoom', name: 'Zoom through', overlap: true },
		{ key: 'wipe', name: 'Wipe', overlap: true }
	];

	/* A slow zoom on a clip, mostly for photos. */
	var motions = [
		{ key: 'none', name: 'None' },
		{ key: 'in', name: 'Zoom in' },
		{ key: 'out', name: 'Zoom out' },
		{ key: 'left', name: 'Drift left' },
		{ key: 'right', name: 'Drift right' }
	];

	window.WHDVideoPresets = {
		sizes: sizes,
		textStyles: textStyles,
		animsIn: animsIn,
		animsOut: animsOut,
		loops: loops,
		texts: texts,
		emoji: emoji,
		effects: effects,
		filters: filters,
		transitions: transitions,
		motions: motions,
		weights: [ 300, 400, 600, 700, 800, 900 ],
		fps: [ 24, 30, 60 ]
	};
}() );
