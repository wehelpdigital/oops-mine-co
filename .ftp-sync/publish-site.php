<?php
/**
 * Publish the Oops, Mine Co. site setup (pages, menu, theme settings, WHD plugin activation, coupon).
 * Idempotent. CLI only.
 *
 *   local DB :  php .ftp-sync/publish-site.php
 *   LIVE DB  :  OMC_DB=live php .ftp-sync/publish-site.php   (PowerShell: $env:OMC_DB="live"; php .ftp-sync/publish-site.php)
 *
 * Requires the code (child theme + whd plugin) to already be on the target server.
 */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 403 ); exit( 'CLI only' ); }

$_SERVER['HTTP_HOST']   = 'oopsmine.test';
$_SERVER['REQUEST_URI'] = '/';
require dirname( __DIR__ ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/nav-menu.php';

$is_live = ( 'live' === getenv( 'OMC_DB' ) );
if ( $is_live && DB_HOST !== '15.235.219.232' ) {
	fwrite( STDERR, "OMC_DB=live was requested but wp-config.php did not switch to the live database (DB_HOST=" . DB_HOST . ")\n" );
	exit( 1 );
}
if ( ! $is_live && DB_HOST !== '127.0.0.1:3307' ) {
	fwrite( STDERR, "Refusing to run: wp-config.php is not pointed at the local database (DB_HOST=" . DB_HOST . ")\n" );
	exit( 1 );
}

$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID' ] );
wp_set_current_user( $admins ? $admins[0]->ID : 1 );
$log = [ 'target' => $is_live ? 'LIVE database (' . home_url( '/' ) . ')' : 'local database (' . home_url( '/' ) . ')' ];

/* 0 ── Site identity */
update_option( 'blogname', 'Oops, Mine Co.' );
set_theme_mod( 'truncate_logo_placeholder', false );      // show the full name as the text logo
foreach ( get_theme_mods() as $mod_key => $mod_value ) {  // drop the demo's "BEST SPECIAL OFFERS!" header text
	if ( is_string( $mod_value ) && stripos( $mod_value, 'BEST SPECIAL OFFERS' ) !== false ) {
		set_theme_mod( $mod_key, '' );
		$log['cleared_demo_header_text'] = $mod_key;
	}
}

/* 1 ── Palette + logo (an empty logo mod = the brand PNGs bundled in the child theme, assets/img) */
$mods = [
	'accent_color'                   => '#B98B7E',
	'button_color'                   => '#141414',
	'button_color_hover'             => '#9C6F63',
	'button_color_dark'              => '#141414',
	'header_top_accent_color'        => '#B98B7E',
	'to_top_button_color'            => '#B98B7E',
	'product_image_background_color' => '#F4EFEA',
	'logo'                           => '',
	'logo__url'                      => '',
	'remove_frontpage_logo_link'     => false,
];
foreach ( $mods as $k => $v ) {
	set_theme_mod( $k, $v );
}
$log['theme_mods'] = array_keys( $mods );

/* 2 ── Announcement bar (theme "HTML block") */
$bar_html = '<p class="omc-announce"><span>New arrivals every week</span><span>Curated Korean &amp; Thai fashion</span><a href="' . esc_url( home_url( '/about-us/' ) ) . '">Our story</a></p>';
$bar      = get_page_by_path( 'omc-announcement', OBJECT, 'html_block' );
if ( ! $bar ) {
	$bar_id = wp_insert_post( [
		'post_type'    => 'html_block',
		'post_status'  => 'publish',
		'post_title'   => 'OMC Announcement bar',
		'post_name'    => 'omc-announcement',
		'post_content' => $bar_html,
	] );
} else {
	$bar_id = $bar->ID;
	wp_update_post( [ 'ID' => $bar_id, 'post_content' => $bar_html ] ); // keep the markup current
}
set_theme_mod( 'header_advert_bar_page', $bar_id );
$log['announcement_block'] = $bar_id;

/* 3 ── Pages */
$demo_about = get_page_by_path( 'about-us' );
if ( $demo_about && (int) $demo_about->ID === 1729 ) { // demo page: free the slug, keep as draft
	wp_update_post( [ 'ID' => 1729, 'post_name' => 'about-us-demo', 'post_status' => 'draft' ] );
	$log['demo_about'] = 'moved to /about-us-demo/ (draft)';
}

$about_content = <<<'HTML'
<p class="omc-lead">Some pieces simply catch your eye. You see them, pause for a second, and think, “Wait… I need this.” That little moment is where Oops, Mine Co. began.</p>
<p>I’ve always loved clothes, but even more than that, I’ve loved the feeling of finding something unexpected. A piece that feels different. A detail you don’t see everywhere. Something that instantly feels like you.</p>
<p>That love for finding those pieces eventually became Oops, Mine Co., a boutique built around the joy of discovering something special and making it yours.</p>

<p class="omc-eyebrow">What we believe</p>
<h2>We believe in choosing, <em>not just collecting.</em></h2>
<p>For us, it’s never about having the biggest collection or bringing in every trend that comes along. It’s about finding the right pieces.</p>
<p>We look for clothing that catches our attention for a reason — the fabric, the fit, the details, the quality, or simply that feeling you get when you know a piece is going to become a favorite.</p>
<p>Every item is chosen with intention because we believe quality will always matter more than quantity. You won’t find a closet full of pieces chosen just to fill space. You’ll find thoughtfully selected styles that we genuinely love and would be excited to wear ourselves.</p>

<ul class="omc-values">
<li><strong>Quality over quantity</strong><span>Small, considered edits — never a collection padded just to fill space.</span></li>
<li><strong>Chosen with intention</strong><span>Every piece earns its place for the fabric, the fit or a detail you don’t see everywhere.</span></li>
<li><strong>Personal, never trend-chasing</strong><span>Your closet should reflect you, not what happens to be having a moment.</span></li>
</ul>

<p class="omc-eyebrow">Your closet</p>
<h2>Your closet should feel like <em>you.</em></h2>
<p>Shopping should be more than simply adding another item to your cart. We want you to find pieces that make you feel good when you put them on — pieces that work their way into your wardrobe because they feel right, not because they happen to be having a moment.</p>
<p>Maybe it becomes the dress you reach for every time you have somewhere to go. Maybe it’s that one top you somehow keep wearing. Or maybe it’s the unexpected little find you weren’t looking for at all. Those are our favorite kinds of pieces.</p>

<p class="omc-eyebrow">The name</p>
<h2>And that’s where <em>“Oops, Mine”</em> comes in.</h2>
<p>The name came from that wonderfully spontaneous moment when you discover something you weren’t planning to buy, but suddenly…</p>
<blockquote class="omc-pull"><p>Oops. Mine.</p></blockquote>
<p>It’s that instinctive little claim. The <em>I wasn’t looking for this, but now I can’t leave without it</em> moment. And honestly, we think shopping should have a little more of that.</p>

<p class="omc-eyebrow">More than a boutique</p>
<h2>It continues because we get to share those finds <em>with you.</em></h2>
<p>Oops, Mine Co. started with a simple love for finding beautiful and unique things. Every collection, every new arrival, and every piece we bring in reflects the same idea: choose well, find something special — and when you know, you know.</p>
<p class="omc-signoff">Welcome to Oops, Mine Co.<br>Your next “Oops, Mine” moment might be right around the corner.</p>
HTML;

$about = get_page_by_path( 'about-us' );
$about_args = [
	'post_type'     => 'page',
	'post_status'   => 'publish',
	'post_title'    => 'About Oops, Mine Co.',
	'post_name'     => 'about-us',
	'post_content'  => $about_content,
	'post_excerpt'  => 'Oops, Mine Co. is a curated boutique of Korean and Thai fashion — pieces chosen with intention, for the moment you see something and think: oops, mine. Read our story.',
	'page_template' => 'templates/page-about.php',
	'meta_input'    => [ 'omc_tagline' => 'Found with intention. Claimed on instinct.', '_header_height' => 'hide' ],
];
if ( $about ) {
	$about_args['ID'] = $about->ID;
	$about_id = wp_update_post( $about_args );
} else {
	$about_id = wp_insert_post( $about_args );
}
update_post_meta( $about_id, '_wp_page_template', 'templates/page-about.php' );
$log['about_page'] = [ 'id' => $about_id, 'url' => get_permalink( $about_id ) ];

$home = get_page_by_path( 'home' );
$home_args = [
	'post_type'     => 'page',
	'post_status'   => 'publish',
	'post_title'    => 'Home',
	'post_name'     => 'home',
	'post_content'  => '',
	'post_excerpt'  => 'Curated Korean and Thai fashion — dresses, skirts, denim and accessories chosen with intention. Found with intention. Claimed on instinct.',
	'page_template' => 'templates/page-home.php',
];
if ( $home ) {
	$home_args['ID'] = $home->ID;
	$home_id = wp_update_post( $home_args );
} else {
	$home_id = wp_insert_post( $home_args );
}
update_post_meta( $home_id, '_wp_page_template', 'templates/page-home.php' );
update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', $home_id );
$log['home_page'] = [ 'id' => $home_id, 'url' => home_url( '/' ) ];

/* 4 ── Menu */
$menu    = wp_get_nav_menu_object( 'Main Menu' );
$menu_id = $menu ? $menu->term_id : wp_create_nav_menu( 'Main Menu' );
foreach ( (array) wp_get_nav_menu_items( $menu_id ) as $item ) {
	wp_delete_post( $item->ID, true );
}
$add = function ( $args ) use ( $menu_id ) {
	return wp_update_nav_menu_item( $menu_id, 0, $args + [ 'menu-item-status' => 'publish' ] );
};
$add( [ 'menu-item-title' => 'Home', 'menu-item-url' => home_url( '/' ), 'menu-item-type' => 'custom' ] );
$add( [ 'menu-item-title' => 'New Arrivals', 'menu-item-url' => omc_new_arrivals_url(), 'menu-item-type' => 'custom' ] );
foreach ( [ [ 'Dresses', 'dresses' ], [ 'Skirts', 'skirts' ], [ 'Denim', 'jeans_and_denim' ], [ 'Accessories', 'accessories' ] ] as [ $label, $slug ] ) {
	$t = get_term_by( 'slug', $slug, 'product_cat' );
	if ( $t ) {
		$add( [ 'menu-item-title' => $label, 'menu-item-type' => 'taxonomy', 'menu-item-object' => 'product_cat', 'menu-item-object-id' => $t->term_id ] );
	}
}
$add( [ 'menu-item-title' => 'Journal', 'menu-item-type' => 'post_type', 'menu-item-object' => 'page', 'menu-item-object-id' => 39 ] );
$add( [ 'menu-item-title' => 'About Us', 'menu-item-type' => 'post_type', 'menu-item-object' => 'page', 'menu-item-object-id' => $about_id ] );
$add( [ 'menu-item-title' => 'Contact', 'menu-item-type' => 'post_type', 'menu-item-object' => 'page', 'menu-item-object-id' => 51 ] );
$locations            = get_theme_mod( 'nav_menu_locations', [] );
$locations['primary'] = $menu_id;
$locations['mobile']  = $menu_id;
set_theme_mod( 'nav_menu_locations', $locations );
$log['menu'] = [ 'id' => $menu_id, 'items' => count( wp_get_nav_menu_items( $menu_id ) ) ];

/* 5 ── WHD plugin: activate + demo configuration */
$r = activate_plugin( 'whd/whd.php' );
$log['plugin'] = is_wp_error( $r ) ? 'ERROR: ' . $r->get_error_message() : 'active';
if ( ! is_wp_error( $r ) ) {
	update_option( 'whd_settings', [ 'exclude_admins' => 0, 'cart_delay_hours' => 1, 'cart_coupon' => 'OOPS10', 'from_name' => 'Oops, Mine Co.', 'from_email' => get_option( 'admin_email' ) ] );
	foreach ( [ 'exit_intent', 'welcome' ] as $pid ) {
		$d = WHD_Popups::get( $pid );
		$d['settings']['enabled'] = 1;
		foreach ( $d['blocks'] as &$b ) {
			if ( 'coupon' === $b['type'] ) {
				$b['props']['code'] = '{coupon_code}';
			}
		}
		unset( $b );
		WHD_Popups::save( $pid, $d );
	}
	foreach ( [ 'customer_processing_order', 'customer_completed_order', 'customer_new_account', 'abandoned_cart', 'welcome_subscriber' ] as $eid ) {
		$d = WHD_Emails::get( $eid );
		$d['settings']['enabled'] = 1;
		WHD_Emails::save( $eid, $d );
	}
	$log['whd'] = [ 'popups_enabled' => [ 'exit_intent', 'welcome' ], 'emails_enabled' => 5, 'coupon_tag' => 'OOPS10' ];
}

/* 6 ── The OOPS10 coupon the popups promise (10% off, once per customer) */
if ( class_exists( 'WooCommerce' ) && ! wc_get_coupon_id_by_code( 'OOPS10' ) ) {
	$coupon = new WC_Coupon();
	$coupon->set_code( 'OOPS10' );
	$coupon->set_discount_type( 'percent' );
	$coupon->set_amount( 10 );
	$coupon->set_usage_limit_per_user( 1 );
	$coupon->set_individual_use( true );
	$coupon->set_description( 'Welcome / exit-intent popup offer (WHD).' );
	$coupon->save();
	$log['coupon'] = 'OOPS10 created';
}

flush_rewrite_rules();
echo json_encode( $log, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), "\n";
