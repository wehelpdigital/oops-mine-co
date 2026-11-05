<?php
/**
 * The live studio: one screen that fills the window. See admin/live-studio.js.
 *
 * @package whd
 */

defined( 'ABSPATH' ) || exit;

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- opened by WHD_Live_Admin::studio(), which checked it
$whd_id     = absint( $_GET['id'] ?? 0 );
$whd_stream = WHD_Live::get( $whd_id );

$whd_icons = [
	'pieces'  => '<path d="M6 8h12l1.2 12H4.8L6 8Z"/><path d="M9 8V6.500a3 3 0 0 1 6 0V8"/>',
	'cameras' => '<rect x="3" y="6.500" width="13" height="11" rx="2.500"/><path d="m16 11 5-3v8l-5-3"/>',
	'sound'   => '<path d="M9 18V6l10-2v12"/><circle cx="6.500" cy="18" r="2.500"/><circle cx="16.500" cy="16" r="2.500"/>',
	'effects' => '<path d="M12 3.500 13.800 9l5.700 1.800-5.700 1.800L12 18l-1.800-5.400-5.700-1.800L10.200 9 12 3.500Z"/><path d="M19 16.500v4M17 18.500h4"/>',
	'images'  => '<rect x="3.500" y="5" width="17" height="14" rx="2"/><circle cx="9" cy="10" r="1.600"/><path d="m4 17 5-4.500 3.500 3 3-2.500 4.500 4"/>',
	'text'    => '<path d="M5 6.500V5h14v1.500M12 5v14M9.500 19h5"/>',
	'looks'   => '<circle cx="12" cy="12" r="8.500"/><path d="M12 3.500v17M12 7l5.800-2M12 11.500h8M12 16l6 2.800"/>',
	'layers'  => '<path d="m12 4 8.500 4.500L12 13 3.500 8.500 12 4Z"/><path d="m3.500 12.500 8.500 4.500 8.500-4.500M3.500 16l8.500 4.500 8.500-4.500"/>',
];
$whd_tabs = [
	'pieces'  => __( 'Pieces', 'whd' ),
	'cameras' => __( 'Cameras', 'whd' ),
	'sound'   => __( 'Sound', 'whd' ),
	'effects' => __( 'Effects', 'whd' ),
	'images'  => __( 'Pictures', 'whd' ),
	'text'    => __( 'Text', 'whd' ),
	'looks'   => __( 'Looks', 'whd' ),
	'layers'  => __( 'On stage', 'whd' ),
];
?>
<div id="whdls" class="whdls whdls--<?php echo esc_attr( $whd_stream['format'] ); ?>" data-stage="<?php echo esc_attr( $whd_stream['stage'] ); ?>">

	<header class="whdls-top">
		<a class="whdls-back" href="<?php echo esc_url( WHD_Live_Admin::url() ); ?>" aria-label="<?php esc_attr_e( 'All streams', 'whd' ); ?>">
			<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.800" aria-hidden="true"><path d="M14.500 5.500 8 12l6.500 6.500"/></svg>
		</a>
		<div class="whdls-name">
			<strong><?php echo esc_html( get_the_title( $whd_id ) ); ?></strong>
			<span class="whdls-state" id="whdls-state"></span>
		</div>
		<div class="whdls-dests" id="whdls-dests"></div>
		<div class="whdls-gauges">
			<span class="whdls-out" id="whdls-out"></span>
			<span class="whdls-gauge" title="<?php esc_attr_e( 'Time on air', 'whd' ); ?>"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.800" aria-hidden="true"><circle cx="12" cy="12" r="8.500"/><path d="M12 7.500V12l3 2"/></svg><b id="whdls-timer">00:00</b></span>
			<span class="whdls-gauge" title="<?php esc_attr_e( 'Watching on this site', 'whd' ); ?>"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.800" aria-hidden="true"><path d="M2.500 12S6 5.500 12 5.500 21.500 12 21.500 12 18 18.500 12 18.500 2.500 12 2.500 12Z"/><circle cx="12" cy="12" r="2.800"/></svg><b id="whdls-viewers">0</b></span>
		</div>
		<?php if ( WHD_Live::has_site( $whd_stream ) ) : ?>
			<div class="whdls-link" title="<?php esc_attr_e( 'This live’s own link. It works now, showing a waiting page until you go live.', 'whd' ); ?>">
				<a href="<?php echo esc_url( WHD_Live::page_url( $whd_id ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( WHD_Live::pretty_url( WHD_Live::page_url( $whd_id ) ) ); ?></a>
				<button type="button" class="whdls-mini" data-copy="<?php echo esc_url( WHD_Live::page_url( $whd_id ) ); ?>"><?php esc_html_e( 'Copy', 'whd' ); ?></button>
			</div>
		<?php endif; ?>
		<button type="button" class="whdls-golive" id="whdls-golive"><?php esc_html_e( 'Go live', 'whd' ); ?></button>
	</header>

	<div class="whdls-banners" id="whdls-banners" aria-live="polite"></div>

	<div class="whdls-body">
		<nav class="whdls-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Tools', 'whd' ); ?>">
			<?php foreach ( $whd_tabs as $whd_key => $whd_label ) : ?>
				<button type="button" role="tab" data-tab="<?php echo esc_attr( $whd_key ); ?>" class="<?php echo 'pieces' === $whd_key ? 'is-on' : ''; ?>">
					<svg viewBox="0 0 24 24" width="21" height="21" fill="none" stroke="currentColor" stroke-width="1.500" stroke-linejoin="round" stroke-linecap="round" aria-hidden="true"><?php echo $whd_icons[ $whd_key ]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup above ?></svg>
					<span><?php echo esc_html( $whd_label ); ?></span>
				</button>
			<?php endforeach; ?>
		</nav>

		<section class="whdls-panel" id="whdls-panel">

			<div class="whdls-pane is-on" data-pane="pieces">
				<h2><?php esc_html_e( 'Pieces', 'whd' ); ?></h2>
				<div class="whdls-search">
					<input type="search" id="whdls-search" placeholder="<?php esc_attr_e( 'Search your pieces…', 'whd' ); ?>" autocomplete="off">
					<div class="whdls-results" id="whdls-results" hidden></div>
				</div>
				<ol class="whdls-pieces" id="whdls-pieces"></ol>
				<p class="whdls-hint"><?php esc_html_e( 'Press Show and the card goes into the video. Drag it where you want it. Viewers who comment its code get the link.', 'whd' ); ?></p>
			</div>

			<div class="whdls-pane" data-pane="cameras">
				<h2><?php esc_html_e( 'Cameras', 'whd' ); ?></h2>
				<div class="whdls-sources" id="whdls-sources"></div>
				<h3><?php esc_html_e( 'Add a camera', 'whd' ); ?></h3>
				<div class="whdls-inline">
					<select id="whdls-add-cam" aria-label="<?php esc_attr_e( 'Camera to add', 'whd' ); ?>"></select>
					<button type="button" class="whdls-btn whdls-btn--small" id="whdls-add-cam-btn"><?php esc_html_e( 'Add', 'whd' ); ?></button>
				</div>
				<p class="whdls-hint"><?php esc_html_e( 'Anything your computer sees as a camera: a webcam, a capture card, or a phone with a webcam app.', 'whd' ); ?></p>
				<button type="button" class="whdls-btn whdls-btn--quiet" id="whdls-share"><?php esc_html_e( 'Share your screen or a window', 'whd' ); ?></button>
				<h3><?php esc_html_e( 'When you switch', 'whd' ); ?></h3>
				<div class="whdls-seg" role="group" id="whdls-transition">
					<button type="button" data-ms="0"><?php esc_html_e( 'Cut', 'whd' ); ?></button>
					<button type="button" data-ms="400" class="is-on"><?php esc_html_e( 'Fade', 'whd' ); ?></button>
					<button type="button" data-ms="1000"><?php esc_html_e( 'Slow fade', 'whd' ); ?></button>
				</div>
			</div>

			<div class="whdls-pane" data-pane="sound">
				<h2><?php esc_html_e( 'Sound', 'whd' ); ?></h2>
				<div class="whdls-mixer">
					<div class="whdls-fader"><span><?php esc_html_e( 'Microphone', 'whd' ); ?></span><input type="range" id="whdls-vol-mic" min="0" max="1.5" step="0.01" value="1"><i class="whdls-vu"><b id="whdls-vu-mic"></b></i></div>
					<div class="whdls-fader"><span><?php esc_html_e( 'Music', 'whd' ); ?></span><input type="range" id="whdls-vol-music" min="0" max="1" step="0.01" value="0.35"><i class="whdls-vu"><b id="whdls-vu-music"></b></i></div>
					<div class="whdls-fader"><span><?php esc_html_e( 'Sound pads', 'whd' ); ?></span><input type="range" id="whdls-vol-pads" min="0" max="1" step="0.01" value="0.8"><i class="whdls-vu"><b id="whdls-vu-pads"></b></i></div>
				</div>

				<h3><?php esc_html_e( 'Background music', 'whd' ); ?></h3>
				<div class="whdls-transport">
					<button type="button" class="whdls-roundbtn" id="whdls-music-play" aria-label="<?php esc_attr_e( 'Play or pause', 'whd' ); ?>"><svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M8 5.500v13l11-6.500L8 5.500Z"/></svg></button>
					<button type="button" class="whdls-roundbtn whdls-roundbtn--small" id="whdls-music-next" aria-label="<?php esc_attr_e( 'Next track', 'whd' ); ?>"><svg viewBox="0 0 24 24" width="15" height="15" fill="currentColor" aria-hidden="true"><path d="M6 5.500v13l9-6.500-9-6.500ZM16.500 5.500h2v13h-2z"/></svg></button>
					<span class="whdls-nowplaying" id="whdls-music-now"></span>
				</div>
				<ol class="whdls-tracks" id="whdls-music-list"></ol>
				<button type="button" class="whdls-btn whdls-btn--quiet" id="whdls-music-add"><?php esc_html_e( 'Add music from the media library', 'whd' ); ?></button>
				<label class="whdls-switch whdls-switch--block"><input type="checkbox" id="whdls-music-duck" checked><span></span><?php esc_html_e( 'Lower the music when I talk', 'whd' ); ?></label>
				<label class="whdls-switch whdls-switch--block"><input type="checkbox" id="whdls-music-loop" checked><span></span><?php esc_html_e( 'Start over at the end of the list', 'whd' ); ?></label>
				<label class="whdls-switch whdls-switch--block"><input type="checkbox" id="whdls-monitor"><span></span><?php esc_html_e( 'Let me hear music and pads (wear headphones)', 'whd' ); ?></label>
				<p class="whdls-note"><?php esc_html_e( 'Use music you have the rights to. Facebook and YouTube can mute or stop a live for copyrighted music.', 'whd' ); ?></p>

				<h3><?php esc_html_e( 'Sound pads', 'whd' ); ?></h3>
				<div class="whdls-tiles" id="whdls-pads"></div>
				<button type="button" class="whdls-btn whdls-btn--quiet" id="whdls-pad-add"><?php esc_html_e( 'Add your own sound', 'whd' ); ?></button>
			</div>

			<div class="whdls-pane" data-pane="effects">
				<h2><?php esc_html_e( 'Effects', 'whd' ); ?></h2>
				<div class="whdls-seg" role="group" id="whdls-effect-mode">
					<button type="button" class="is-on" data-mode="burst"><?php esc_html_e( 'One burst', 'whd' ); ?></button>
					<button type="button" data-mode="rain"><?php esc_html_e( 'Keep going', 'whd' ); ?></button>
				</div>
				<div class="whdls-tiles" id="whdls-effects"></div>
				<h3><?php esc_html_e( 'Your own picture', 'whd' ); ?></h3>
				<p class="whdls-hint"><?php esc_html_e( 'A photo or sticker that floats up over the video.', 'whd' ); ?></p>
				<button type="button" class="whdls-btn" id="whdls-float-pick"><?php esc_html_e( 'Choose a floating picture', 'whd' ); ?></button>
				<div class="whdls-thumbs" id="whdls-float-list"></div>
			</div>

			<div class="whdls-pane" data-pane="images">
				<h2><?php esc_html_e( 'Pictures', 'whd' ); ?></h2>
				<p class="whdls-hint"><?php esc_html_e( 'Put a picture on top of the video. Drag it, pull a corner to size it, and give it a movement in the bar under the stage.', 'whd' ); ?></p>
				<button type="button" class="whdls-btn" id="whdls-image-pick"><?php esc_html_e( 'Choose a picture', 'whd' ); ?></button>
				<button type="button" class="whdls-btn whdls-btn--quiet" id="whdls-logo-add" hidden><?php esc_html_e( 'Add your logo', 'whd' ); ?></button>
				<h3><?php esc_html_e( 'Used in this stream', 'whd' ); ?></h3>
				<div class="whdls-thumbs" id="whdls-image-list"></div>
			</div>

			<div class="whdls-pane" data-pane="text">
				<h2><?php esc_html_e( 'Text', 'whd' ); ?></h2>
				<div class="whdls-presets" id="whdls-text-presets"></div>

				<h3><?php esc_html_e( 'Your words', 'whd' ); ?></h3>
				<textarea id="whdls-text-input" rows="2" placeholder="<?php esc_attr_e( 'Type anything', 'whd' ); ?>"></textarea>
				<div class="whdls-styles" id="whdls-text-styles"></div>
				<button type="button" class="whdls-btn" id="whdls-text-add"><?php esc_html_e( 'Put it on stage', 'whd' ); ?></button>

				<h3><?php esc_html_e( 'Name strip', 'whd' ); ?></h3>
				<input type="text" id="whdls-lower-title" placeholder="<?php esc_attr_e( 'Your name or the piece', 'whd' ); ?>">
				<input type="text" id="whdls-lower-sub" placeholder="<?php esc_attr_e( 'A second line', 'whd' ); ?>">
				<button type="button" class="whdls-btn whdls-btn--quiet" id="whdls-lower-add"><?php esc_html_e( 'Add the strip', 'whd' ); ?></button>

				<h3><?php esc_html_e( 'Scrolling line', 'whd' ); ?></h3>
				<input type="text" id="whdls-ticker-text" placeholder="<?php esc_attr_e( 'New pieces tonight. Comment the code to get the link.', 'whd' ); ?>">
				<button type="button" class="whdls-btn whdls-btn--quiet" id="whdls-ticker-add"><?php esc_html_e( 'Add the line', 'whd' ); ?></button>

				<h3><?php esc_html_e( 'Countdown', 'whd' ); ?></h3>
				<div class="whdls-inline">
					<input type="text" id="whdls-count-label" value="<?php esc_attr_e( 'Ends in', 'whd' ); ?>" aria-label="<?php esc_attr_e( 'Label', 'whd' ); ?>">
					<label class="whdls-min"><input type="number" id="whdls-count-min" value="5" min="1" max="180"><span><?php esc_html_e( 'min', 'whd' ); ?></span></label>
				</div>
				<button type="button" class="whdls-btn whdls-btn--quiet" id="whdls-count-add"><?php esc_html_e( 'Start the countdown', 'whd' ); ?></button>
			</div>

			<div class="whdls-pane" data-pane="looks">
				<h2><?php esc_html_e( 'Looks', 'whd' ); ?></h2>
				<h3><?php esc_html_e( 'Colour', 'whd' ); ?></h3>
				<div class="whdls-tiles whdls-tiles--text" id="whdls-looks"></div>
				<label class="whdls-range"><span><?php esc_html_e( 'Brightness', 'whd' ); ?></span><input type="range" id="whdls-brightness" min="0.6" max="1.5" step="0.01" value="1"></label>
				<h3><?php esc_html_e( 'Frame', 'whd' ); ?></h3>
				<div class="whdls-tiles whdls-tiles--text" id="whdls-frames"></div>
			</div>

			<div class="whdls-pane" data-pane="layers">
				<h2><?php esc_html_e( 'On stage', 'whd' ); ?></h2>
				<ol class="whdls-layers" id="whdls-layers"></ol>
				<button type="button" class="whdls-btn whdls-btn--quiet" id="whdls-clear"><?php esc_html_e( 'Clear the stage', 'whd' ); ?></button>
			</div>
		</section>

		<main class="whdls-centre">
			<div class="whdls-cuts">
				<div class="whdls-cuts__group" id="whdls-cut-sources"></div>
				<div class="whdls-cuts__group whdls-cuts__group--scenes">
					<span class="whdls-cuts__label"><?php esc_html_e( 'Scenes', 'whd' ); ?></span>
					<div class="whdls-cuts__chips" id="whdls-scenes"></div>
					<button type="button" class="whdls-chip whdls-chip--add" id="whdls-scene-save" title="<?php esc_attr_e( 'Save everything on stage now as a scene', 'whd' ); ?>"><?php esc_html_e( '+ Save', 'whd' ); ?></button>
				</div>
			</div>

			<div class="whdls-stagebox" id="whdls-stagebox">
				<div class="whdls-frame" id="whdls-frame">
					<canvas id="whdls-canvas"></canvas>
					<div class="whdls-handles" id="whdls-handles"></div>
					<span class="whdls-onair" id="whdls-onair" hidden><i></i><?php esc_html_e( 'On air', 'whd' ); ?></span>
					<div class="whdls-peek" id="whdls-peek" hidden>
						<video id="whdls-peek-video" muted playsinline autoplay></video>
						<span><?php esc_html_e( 'Only you see this', 'whd' ); ?></span>
					</div>
				</div>
			</div>

			<div class="whdls-selbar" id="whdls-selbar" hidden>
				<strong id="whdls-sel-name"></strong>
				<input type="text" class="whdls-sel-text" id="whdls-sel-text" hidden aria-label="<?php esc_attr_e( 'Words', 'whd' ); ?>">
				<label class="whdls-range whdls-range--bar"><span><?php esc_html_e( 'Size', 'whd' ); ?></span><input type="range" id="whdls-sel-size" min="0.04" max="1" step="0.005"></label>
				<label class="whdls-range whdls-range--bar"><span><?php esc_html_e( 'See-through', 'whd' ); ?></span><input type="range" id="whdls-sel-opacity" min="0.1" max="1" step="0.01"></label>
				<label class="whdls-range whdls-range--bar"><span><?php esc_html_e( 'Turn', 'whd' ); ?></span><input type="range" id="whdls-sel-rot" min="-180" max="180" step="1"></label>
				<label class="whdls-field"><span><?php esc_html_e( 'Move', 'whd' ); ?></span><select id="whdls-sel-anim"></select></label>
				<label class="whdls-field" id="whdls-sel-shape-box" hidden><span><?php esc_html_e( 'Shape', 'whd' ); ?></span><select id="whdls-sel-shape"><option value="rounded"><?php esc_html_e( 'Rounded', 'whd' ); ?></option><option value="circle"><?php esc_html_e( 'Circle', 'whd' ); ?></option></select></label>
				<button type="button" class="whdls-iconbtn" id="whdls-sel-raise" title="<?php esc_attr_e( 'Bring to the front', 'whd' ); ?>"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.800" aria-hidden="true"><path d="M12 19V6M6 11.500 12 5.500l6 6"/></svg></button>
				<button type="button" class="whdls-iconbtn whdls-iconbtn--danger" id="whdls-sel-remove" title="<?php esc_attr_e( 'Take it off', 'whd' ); ?>"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.800" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg></button>
			</div>

			<div class="whdls-bar">
				<button type="button" class="whdls-mute" id="whdls-mute" aria-pressed="false" title="<?php esc_attr_e( 'Mute your microphone. Shortcut: M', 'whd' ); ?>">
					<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.700" stroke-linecap="round" aria-hidden="true"><rect x="9" y="3.500" width="6" height="11" rx="3"/><path d="M5.500 11.500a6.500 6.500 0 0 0 13 0M12 18v3"/><path class="whdls-slash" d="M4 4l16 16"/></svg>
					<span id="whdls-mute-label"><?php esc_html_e( 'Mute', 'whd' ); ?></span>
					<span class="whdls-meter"><i id="whdls-meter"></i></span>
				</button>
				<select id="whdls-mic-select" aria-label="<?php esc_attr_e( 'Microphone', 'whd' ); ?>"></select>

				<button type="button" class="whdls-cover" id="whdls-cover" aria-pressed="false" title="<?php esc_attr_e( 'Cover the video with your logo. Shortcut: C', 'whd' ); ?>">
					<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.700" stroke-linecap="round" aria-hidden="true"><path d="M3 3l18 18"/><path d="M9.500 6.500h4A2.500 2.500 0 0 1 16 9v2l5-3v8l-1.500-.9"/><path d="M14 17.500H5.500A2.500 2.500 0 0 1 3 15V9a2.500 2.500 0 0 1 1.800-2.400"/></svg>
					<span id="whdls-cover-label"><?php esc_html_e( 'Cover screen', 'whd' ); ?></span>
				</button>
				<select id="whdls-cover-line" aria-label="<?php esc_attr_e( 'Words on the cover', 'whd' ); ?>">
					<option><?php esc_html_e( 'We’ll be right back', 'whd' ); ?></option>
					<option><?php esc_html_e( 'Starting soon', 'whd' ); ?></option>
					<option><?php esc_html_e( 'Just a moment', 'whd' ); ?></option>
					<option value=""><?php esc_html_e( 'Logo only', 'whd' ); ?></option>
				</select>
				<label class="whdls-switch"><input type="checkbox" id="whdls-cover-mute" checked><span></span><?php esc_html_e( 'Mute mic too', 'whd' ); ?></label>

				<span class="whdls-bar__gap"></span>
				<label class="whdls-switch"><input type="checkbox" id="whdls-hide"><span></span><?php esc_html_e( 'Hide graphics', 'whd' ); ?></label>
				<button type="button" class="whdls-record" id="whdls-record" aria-pressed="false" title="<?php esc_attr_e( 'Record the stream into your media library', 'whd' ); ?>"><i></i><span id="whdls-record-label"><?php esc_html_e( 'Record', 'whd' ); ?></span></button>
				<button type="button" class="whdls-record whdls-record--pause" id="whdls-record-pause" hidden><span id="whdls-record-pause-label"><?php esc_html_e( 'Pause', 'whd' ); ?></span></button>
			</div>
		</main>

		<aside class="whdls-side">
			<div class="whdls-stats" id="whdls-stats">
				<div><b id="whdls-s-comments">0</b><span><?php esc_html_e( 'comments', 'whd' ); ?></span></div>
				<div><b id="whdls-s-leads">0</b><span><?php esc_html_e( 'leads', 'whd' ); ?></span></div>
				<div><b id="whdls-s-links">0</b><span><?php esc_html_e( 'links sent', 'whd' ); ?></span></div>
				<div><b id="whdls-s-clicks">0</b><span><?php esc_html_e( 'clicks', 'whd' ); ?></span></div>
			</div>
			<div class="whdls-sidetabs" role="tablist">
				<button type="button" class="is-on" data-side="comments"><?php esc_html_e( 'Comments', 'whd' ); ?></button>
				<button type="button" data-side="leads"><?php esc_html_e( 'Leads', 'whd' ); ?></button>
			</div>
			<div class="whdls-sideview is-on" data-sideview="comments">
				<div class="whdls-filter" id="whdls-filter"></div>
				<ol class="whdls-feed" id="whdls-feed"></ol>
			</div>
			<div class="whdls-sideview" data-sideview="leads">
				<ol class="whdls-leads" id="whdls-leads"></ol>
				<a class="whdls-btn whdls-btn--quiet" id="whdls-leads-csv" href="#"><?php esc_html_e( 'Export CSV', 'whd' ); ?></a>
			</div>
		</aside>
	</div>

	<!-- Every camera before it is drawn. In the page so they keep running, but never seen. -->
	<div class="whdls-hidden-videos" id="whdls-videos" aria-hidden="true"></div>

	<dialog class="whdls-dialog" id="whdls-preflight">
		<h2><?php esc_html_e( 'Ready to go live?', 'whd' ); ?></h2>
		<ul class="whdls-checklist" id="whdls-checklist"></ul>
		<label class="whdls-keybox" id="whdls-tiktok-box" hidden>
			<span><?php esc_html_e( 'This session’s TikTok stream key', 'whd' ); ?></span>
			<input type="password" id="whdls-tiktok-key" autocomplete="off">
		</label>
		<label class="whdls-switch whdls-switch--block"><input type="checkbox" id="whdls-start-covered"><span></span><?php esc_html_e( 'Start behind the cover, and lift it when you’re ready', 'whd' ); ?></label>
		<p class="whdls-hint"><?php esc_html_e( 'Keep this tab open while you are live. Your picture comes from here.', 'whd' ); ?></p>
		<div class="whdls-dialog__actions">
			<button type="button" class="whdls-btn whdls-btn--quiet" data-close><?php esc_html_e( 'Not yet', 'whd' ); ?></button>
			<button type="button" class="whdls-golive" id="whdls-confirm"><?php esc_html_e( 'Go live now', 'whd' ); ?></button>
		</div>
	</dialog>

	<dialog class="whdls-dialog" id="whdls-end">
		<h2><?php esc_html_e( 'End the live?', 'whd' ); ?></h2>
		<p><?php esc_html_e( 'This stops the video on every platform and closes comments. It can’t be restarted.', 'whd' ); ?></p>
		<div class="whdls-dialog__actions">
			<button type="button" class="whdls-btn whdls-btn--quiet" data-close><?php esc_html_e( 'Keep going', 'whd' ); ?></button>
			<button type="button" class="whdls-golive whdls-golive--stop" id="whdls-end-confirm"><?php esc_html_e( 'End live', 'whd' ); ?></button>
		</div>
	</dialog>

	<dialog class="whdls-dialog whdls-dialog--wrap" id="whdls-wrap">
		<p class="whdls-kicker"><?php esc_html_e( 'That’s a wrap', 'whd' ); ?></p>
		<h2 id="whdls-wrap-title"></h2>
		<dl class="whdls-wrapstats" id="whdls-wrapstats"></dl>
		<p class="whdls-hint" id="whdls-wrap-rec" hidden></p>
		<div class="whdls-dialog__actions">
			<a class="whdls-btn whdls-btn--quiet" id="whdls-wrap-csv" href="#"><?php esc_html_e( 'Leads CSV', 'whd' ); ?></a>
			<a class="whdls-golive" href="<?php echo esc_url( WHD_Live_Admin::url() ); ?>"><?php esc_html_e( 'All streams', 'whd' ); ?></a>
		</div>
	</dialog>
</div>
