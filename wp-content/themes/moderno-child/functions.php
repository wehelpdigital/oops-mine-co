<?php
/**
 * Moderno Child — Oops, Mine Co.
 *
 * Everything site-specific lives in the child theme so the parent (Moderno) can
 * be updated safely: brand fonts + styles, the OMC page templates (Home, About),
 * lightweight SEO output (meta description / Open Graph / JSON-LD — only when no
 * SEO plugin is active) and the helpers those templates share.
 *
 * @package moderno-child
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

define( 'OMC_VERSION', '1.1.0' );
define( 'OMC_DIR', get_stylesheet_directory() );
define( 'OMC_URI', get_stylesheet_directory_uri() );

/* Feature modules (each file is self-contained and hooks itself). */
foreach ( [ 'video', 'landing', 'pdp', 'cart', 'social', 'perf' ] as $omc_module ) {
	$omc_module_file = OMC_DIR . '/inc/' . $omc_module . '.php';
	if ( file_exists( $omc_module_file ) ) {
		require_once $omc_module_file;
	}
}
unset( $omc_module, $omc_module_file );

/* ─────────────────────────── Assets ─────────────────────────── */

/**
 * Enqueue the child stylesheet AFTER the parent's CSS, plus the brand fonts,
 * the OMC stylesheet and a small front-end script.
 *
 * The parent enqueues its (combined) stylesheet as `ideapark-core` on
 * wp_enqueue_scripts at priority 999 (see ideapark_scripts_load), so we hook
 * at 1000 and declare it as a dependency to guarantee cascade order.
 */
function moderno_child_enqueue_styles() {
	$ver = static function ( $rel ) {
		return file_exists( OMC_DIR . $rel ) ? (string) filemtime( OMC_DIR . $rel ) : OMC_VERSION;
	};

	wp_enqueue_style( 'moderno-child-style', OMC_URI . '/style.css', [ 'ideapark-core' ], $ver( '/style.css' ) );
	wp_enqueue_style(
		'omc-fonts',
		'https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400;1,500&family=Manrope:wght@300;400;500;600;700&display=swap',
		[],
		null
	);
	wp_enqueue_style( 'omc', OMC_URI . '/assets/css/omc.css', [ 'moderno-child-style', 'omc-fonts' ], $ver( '/assets/css/omc.css' ) );
	wp_enqueue_style( 'omc-pages', OMC_URI . '/assets/css/omc-pages.css', [ 'omc' ], $ver( '/assets/css/omc-pages.css' ) ); // the theme's own screens, restyled to the home page
	wp_enqueue_script( 'omc', OMC_URI . '/assets/js/omc.js', [], $ver( '/assets/js/omc.js' ), true );

	// Style videos: the two video templates, plus the home page, which shows the same cards.
	if ( is_page_template( 'templates/page-video.php' ) || is_page_template( 'templates/page-videos.php' ) || is_page_template( 'templates/page-home.php' ) ) {
		wp_enqueue_style( 'omc-video', OMC_URI . '/assets/css/omc-video.css', [ 'omc' ], $ver( '/assets/css/omc-video.css' ) );
		if ( is_page_template( 'templates/page-video.php' ) ) {
			wp_enqueue_script( 'omc-video', OMC_URI . '/assets/js/omc-video.js', [], $ver( '/assets/js/omc-video.js' ), true );
		}
	}

	// Cart persuasion layer: the drawer lives in the header, so it loads on every page.
	if ( class_exists( 'WooCommerce' ) ) {
		wp_enqueue_style( 'omc-cart', OMC_URI . '/assets/css/omc-cart.css', [ 'omc-pages' ], $ver( '/assets/css/omc-cart.css' ) );
		wp_enqueue_script( 'omc-cart', OMC_URI . '/assets/js/omc-cart.js', [], $ver( '/assets/js/omc-cart.js' ), true );
	}

	// Product page: size guide, scarcity line, trust badges, review photos. The CSS also covers
	// the quick view and the wishlist stock column, so it loads across the store screens.
	if ( function_exists( 'is_woocommerce' ) && ( is_woocommerce() || ( function_exists( 'ideapark_is_wishlist_page' ) && ideapark_is_wishlist_page() ) ) ) {
		wp_enqueue_style( 'omc-pdp', OMC_URI . '/assets/css/omc-pdp.css', [ 'omc' ], $ver( '/assets/css/omc-pdp.css' ) );
		if ( is_product() ) {
			wp_enqueue_script( 'omc-pdp', OMC_URI . '/assets/js/omc-pdp.js', [ 'jquery' ], $ver( '/assets/js/omc-pdp.js' ), true );
		}
	}
	wp_localize_script( 'omc', 'OMC', [
		'ajax'  => admin_url( 'admin-ajax.php' ),
		'nonce' => wp_create_nonce( 'omc_newsletter' ),
	] );
}
add_action( 'wp_enqueue_scripts', 'moderno_child_enqueue_styles', 1000 );

add_filter( 'wp_resource_hints', function ( $urls, $relation ) {
	if ( 'preconnect' === $relation ) {
		$urls[] = [ 'href' => 'https://fonts.gstatic.com', 'crossorigin' ];
	}
	return $urls;
}, 10, 2 );

/**
 * Carry the parent's Customizer settings over the first time the child is
 * activated (mirrors ideapark_migrate_mods_child() in the parent's installer).
 * Only runs when the child has no mods of its own yet — never overwrites.
 */
function moderno_child_migrate_parent_mods() {
	$child_key  = 'theme_mods_' . get_stylesheet();
	$parent_key = 'theme_mods_' . get_template();

	if ( false !== get_option( $child_key ) ) {
		return;
	}
	$parent_mods = get_option( $parent_key );
	if ( is_array( $parent_mods ) && ! empty( $parent_mods ) ) {
		update_option( $child_key, $parent_mods );
	}
}
add_action( 'after_switch_theme', 'moderno_child_migrate_parent_mods' );

/* ─────────────────────── Template helpers ─────────────────────── */

function omc_is_home_template() {
	return is_page_template( 'templates/page-home.php' );
}

function omc_is_about_template() {
	return is_page_template( 'templates/page-about.php' );
}

add_filter( 'body_class', function ( $classes ) {
	if ( omc_is_home_template() ) {
		$classes[] = 'omc-home-page';
	}
	if ( omc_is_about_template() ) {
		$classes[] = 'omc-about-page';
	}
	return $classes;
} );

/**
 * Photography used by the templates, as upload-relative paths so the same
 * files resolve on any environment. Override with the `omc_images` filter or
 * swap them for real brand imagery later.
 */
function omc_images() {
	return apply_filters( 'omc_images', [
		'hero'       => '2023/04/moderno-2840461420.jpg', // two women in knit dresses, blush tones
		'edit_a'     => '2023/04/moderno-2355149011.jpg', // cream knit close-up
		'edit_b'     => '2023/04/ricky-2347791350.jpg',   // black linen shirt portrait
		'story'      => '2023/04/moderno-2726173801.jpg', // beige suit close-up — blurred, darkened backdrop of the story band (the sofa shot is the wide banner below)
		'newsletter' => '2023/04/ricky-2127148895.jpg',   // knit dresses on the stairs — blurred, sand-washed backdrop of the newsletter band
		'live'       => '2023/04/moderno-2310427039.jpg', // white tee, wavy hair, face fully in frame — photo side of the Facebook Live card
		'quotes'     => '2023/04/moderno-2338813525.jpg', // blonde on a pink backdrop — blurred, darkened backdrop of the testimonials band
		'about_hero' => '2023/07/moderno-1349368030.jpg', // monochrome editorial
	] );
}

/** Resolve an upload-relative path (or a numeric ID) to an attachment ID. */
function omc_attachment_id( $file_or_id ) {
	static $cache = [];
	if ( is_numeric( $file_or_id ) ) {
		return (int) $file_or_id;
	}
	if ( isset( $cache[ $file_or_id ] ) ) {
		return $cache[ $file_or_id ];
	}
	global $wpdb;
	$id = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1",
		$file_or_id
	) );
	return $cache[ $file_or_id ] = $id;
}

/** `<img>` for an upload-relative path / ID, with proper srcset + attributes. */
function omc_image( $file_or_id, $size = 'full', $attrs = [] ) {
	$id = omc_attachment_id( $file_or_id );
	return $id ? wp_get_attachment_image( $id, $size, false, $attrs ) : '';
}

/**
 * The category every shop grid is scoped to.
 *
 * Resolved, not named. The demo tree's root was "women"; catalog.json replaced it with "clothing",
 * and a [products category="…"] pointing at a slug with no term behind it returns an empty grid
 * with no warning of any kind — which is how two whole rows of the home page went blank without
 * anything in a log to say so. Anything that scopes a grid should ask this rather than guess.
 *
 * @return string A product_cat slug that exists and has products, or '' meaning the whole shop.
 */
function omc_shop_root_category() {
	$found = '';
	foreach ( [ 'clothing', 'women' ] as $slug ) {
		$term = get_term_by( 'slug', $slug, 'product_cat' );
		if ( $term && ! is_wp_error( $term ) && $term->count > 0 ) {
			$found = $slug;
			break;
		}
	}

	/**
	 * Filter the category the shop grids are scoped to.
	 *
	 * @param string $found Slug, or '' for every product.
	 */
	return (string) apply_filters( 'omc_shop_root_category', $found );
}

/** Product categories shown as cards on the home page (slugs, in order; 4 per row). */
function omc_home_categories() {
	/*
	 * Follows the shop tree rather than naming slugs, so retiring or adding a category in
	 * catalog.json does not quietly leave this section half empty — which is exactly what happened
	 * when the demo categories went and only two of the eight hard-coded slugs still existed.
	 *
	 * Categories with no products are skipped: a tile takes its photo from the first product in the
	 * category, so an empty one renders as a grey box.
	 */
	$slugs  = [];
	$parent = get_term_by( 'slug', 'clothing', 'product_cat' );
	if ( $parent && ! is_wp_error( $parent ) ) {
		$children = get_terms( [
			'taxonomy'   => 'product_cat',
			'parent'     => $parent->term_id,
			'hide_empty' => true,
			'orderby'    => 'term_id',   // the order catalog.json created them in
			'order'      => 'ASC',
		] );
		if ( ! is_wp_error( $children ) ) {
			$slugs = wp_list_pluck( $children, 'slug' );
		}
	}
	if ( ! $slugs ) {
		$slugs = [ 'dresses', 'skirts', 'tops', 'sweaters-knits' ];
	}

	// Eight fills two rows of four; a ninth would leave an orphan.
	return apply_filters( 'omc_home_categories', array_slice( $slugs, 0, 8 ) );
}

/** Trust strip under the hero. Edit via the `omc_usp_items` filter. */
function omc_usp_items() {
	return apply_filters( 'omc_usp_items', [
		[ 'icon' => 'sparkle', 'text' => __( 'New pieces every week', 'moderno-child' ) ],
		[ 'icon' => 'hand',    'text' => __( 'Curated in small batches', 'moderno-child' ) ],
		[ 'icon' => 'return',  'text' => __( 'Easy returns', 'moderno-child' ) ],
		[ 'icon' => 'lock',    'text' => __( 'Secure checkout', 'moderno-child' ) ],
	] );
}

/**
 * Hero slideshow photos (upload-relative paths or attachment IDs). The first one is the LCP image and is
 * printed eagerly; the rest load lazily and blur-dissolve in every few seconds (no pagination, no controls).
 * Edit via the `omc_hero_images` filter; a single entry turns the slideshow off.
 */
function omc_hero_images() {
	$images = omc_images();
	return array_values( array_filter( apply_filters( 'omc_hero_images', [
		$images['hero'],                    // two women in knit dresses, blush tones
		'2023/04/moderno-2338813525.jpg',   // blonde on a pink backdrop
		'2023/04/ricky-2131673471.jpg',     // camel knit on a cream sofa
	] ) ) );
}

/**
 * Image mosaic under the Facebook Live card: a fixed, balanced pattern (2×2, 1×1, 1×2, 1×1 / 1×1, 2×1, 1×1)
 * on four columns, two columns on phones. Each tile: image (upload path or attachment ID), label, url, size.
 * Demo photos for now — swap via the `omc_home_mosaic` filter or by editing this list. Keep seven tiles for the
 * pattern to stay balanced (extra tiles flow in dense order).
 */
function omc_home_mosaic() {
	return apply_filters( 'omc_home_mosaic', [
		[ 'image' => '2023/04/moderno-2338441644.jpg', 'label' => __( 'New in', 'moderno-child' ),      'url' => omc_new_arrivals_url(),                       'size' => '2x2' ],
		[ 'image' => '2023/04/ricky-2152516324.jpg',   'label' => __( 'Accessories', 'moderno-child' ), 'url' => omc_category_url( 'shoes_and_accessories' ), 'size' => '1x1' ],
		[ 'image' => '2023/04/moderno-2327197569.jpg', 'label' => __( 'Dresses', 'moderno-child' ),     'url' => omc_category_url( 'dresses' ),               'size' => '1x2' ],
		[ 'image' => '2023/04/moderno-2426118020.jpg', 'label' => __( 'Knitwear', 'moderno-child' ),    'url' => omc_category_url( 'tops', omc_shop_url() ),  'size' => '1x1' ],
		[ 'image' => '2023/04/moderno-2726284777.jpg', 'label' => __( 'Handbags', 'moderno-child' ),    'url' => omc_category_url( 'handbags' ),              'size' => '1x1' ],
		[ 'image' => '2023/04/moderno-2338177441.jpg', 'label' => __( 'Tailoring', 'moderno-child' ),   'url' => omc_shop_url(),                              'size' => '2x1' ],
		[ 'image' => '2023/04/moderno-2606742971.jpg', 'label' => __( 'Occasion', 'moderno-child' ),    'url' => omc_category_url( 'dresses' ),               'size' => '1x1', 'focus' => '82% 30%' ], // subject sits at the right of this shot
	] );
}

/**
 * Testimonials for the home page. Approved WooCommerce product reviews come first (4–5 stars, with some
 * substance, newest first); curated quotes pad the row so it never looks thin. Each item: text, author, rating,
 * product (name, url — reviews only), date, source (review|curated).
 * Filters: `omc_testimonials_fallback` (return [] to show real reviews only), `omc_testimonials` (final list).
 */
function omc_testimonials( $limit = 8 ) {
	$items = [];
	if ( function_exists( 'wc_get_product' ) ) {
		$reviews = get_comments( [ 'type' => 'review', 'status' => 'approve', 'post_type' => 'product', 'number' => 40, 'orderby' => 'comment_date_gmt', 'order' => 'DESC' ] );
		foreach ( $reviews as $r ) {
			$rating = (int) get_comment_meta( $r->comment_ID, 'rating', true );
			$text   = trim( wp_strip_all_tags( $r->comment_content ) );
			if ( $rating < 4 || mb_strlen( $text ) < 60 ) {
				continue;
			}
			$items[] = [
				'text'    => $text,
				'author'  => $r->comment_author,
				'rating'  => $rating,
				'product' => [ 'name' => get_the_title( $r->comment_post_ID ), 'url' => get_permalink( $r->comment_post_ID ) ],
				'image'   => (int) get_post_thumbnail_id( $r->comment_post_ID ), // the reviewed piece, shown beside the quote
				'date'    => $r->comment_date_gmt,
				'source'  => 'review',
			];
			if ( count( $items ) >= $limit ) {
				break;
			}
		}
	}
	if ( count( $items ) < $limit ) {
		$fallback = apply_filters( 'omc_testimonials_fallback', [ // curated quotes borrow a product photo from the category they mention
			[ 'text' => __( 'I ordered one dress “just to see” and now half my weekend wardrobe is from here. The fabric is better than pieces I’ve paid double for.', 'moderno-child' ), 'author' => 'Mika', 'image' => omc_category_image( 'dresses' ) ],
			[ 'text' => __( 'Lovely packaging, quick shipping, and the knit fits exactly like the photos. Oops — mine, indeed.', 'moderno-child' ), 'author' => 'Trisha', 'image' => omc_category_image( 'skirts' ) ],
			[ 'text' => __( 'Finally a shop that curates instead of throwing a thousand options at you. Everything I’ve bought goes together.', 'moderno-child' ), 'author' => 'Joanne', 'image' => omc_category_image( 'handbags' ) ],
			[ 'text' => __( 'The Bangkok edit pieces are perfect for our weather — light, easy, and they still look put-together in the evening.', 'moderno-child' ), 'author' => 'Celine', 'image' => omc_category_image( 'shoes_and_accessories' ) ],
		] );
		foreach ( (array) $fallback as $f ) {
			$items[] = wp_parse_args( $f, [ 'text' => '', 'author' => '', 'rating' => 5, 'product' => null, 'image' => 0, 'date' => '', 'source' => 'curated' ] );
			if ( count( $items ) >= $limit ) {
				break;
			}
		}
	}
	return apply_filters( 'omc_testimonials', $items );
}

/**
 * Second line of the hero headline: the first phrase is printed (and is what search engines and
 * screen readers get); assets/js/omc.js then rotates through the rest. Edit via `omc_hero_phrases`.
 */
function omc_hero_phrases() {
	return apply_filters( 'omc_hero_phrases', [
		__( 'Claimed on instinct.', 'moderno-child' ),
		__( 'Loved at first sight.', 'moderno-child' ),
		__( 'Worn on repeat.', 'moderno-child' ),
		__( 'Yours in a heartbeat.', 'moderno-child' ),
	] );
}

/**
 * Next Facebook Live — the band under the trust strip on the home page.
 * Everything is editable in Customizer → "Facebook Live (home banner)" (theme mods omc_live_*);
 * the values below are the fallbacks. Until a date is set, the default is next Saturday 8 pm site time.
 */
function omc_fb_live() {
	$tz      = wp_timezone();
	$default = ( new DateTimeImmutable( 'next saturday 20:00', $tz ) )->format( 'Y-m-d\TH:i' );
	$raw     = (string) get_theme_mod( 'omc_live_datetime', '' ) ?: $default;
	$when    = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', $raw, $tz ) ?: DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', $default, $tz );

	return apply_filters( 'omc_fb_live', [
		'enabled' => (bool) get_theme_mod( 'omc_live_enabled', true ),
		'when'    => $when,
		'title'   => (string) get_theme_mod( 'omc_live_title', '' ) ?: __( 'Be first in the room', 'moderno-child' ),
		'text'    => (string) get_theme_mod( 'omc_live_text', '' ) ?: __( 'Leave your email and we’ll send you the link before we go live — plus live-only discounts for everyone who joins from it.', 'moderno-child' ),
		'url'     => (string) get_theme_mod( 'omc_live_url', '' ) ?: 'https://www.facebook.com/',
		'button'  => (string) get_theme_mod( 'omc_live_button', '' ) ?: __( 'Send me the link', 'moderno-child' ),
	] );
}

function omc_sanitize_datetime_local( $value ) {
	$value = trim( (string) $value );
	return $value && DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', $value ) ? $value : '';
}

/**
 * The line of brand copy that cycles under the header logo.
 *
 * Positioning, not a promise — what kind of shop this is, said four words at a time. Edited in
 * Customizer → "Header tagline"; one phrase per line there, and the list below is what shows until
 * someone writes their own.
 *
 * @return array Phrases, already trimmed and de-duplicated.
 */
function omc_header_taglines() {
	$saved = trim( (string) get_theme_mod( 'omc_tagline_lines', '' ) );
	$lines = $saved
		? preg_split( '/\R/', $saved )
		: [
			__( 'Claimed on instinct', 'moderno-child' ),
			__( 'Curated by designers', 'moderno-child' ),
			__( 'Limited stock, limited edition', 'moderno-child' ),
			__( 'Selected by real people', 'moderno-child' ),
			__( 'Found, not mass produced', 'moderno-child' ),
			__( 'Chosen piece by piece', 'moderno-child' ),
			__( 'One small run at a time', 'moderno-child' ),
			__( 'Picked for the way it wears', 'moderno-child' ),
		];

	$lines = array_values( array_unique( array_filter( array_map( 'trim', (array) $lines ) ) ) );

	/**
	 * Filter the header taglines.
	 *
	 * @param array $lines Phrases in rotation order.
	 */
	return (array) apply_filters( 'omc_header_taglines', $lines );
}

/**
 * Print that line under the logo.
 *
 * Every phrase is in the markup, stacked in one grid cell so the strip is as tall as its tallest
 * line and the header never jumps mid-rotation. Without JavaScript the first one simply stays —
 * which is also what happens for anyone who has asked for reduced motion.
 *
 * Called from templates/header-logo.php and header-logo-mobile.php, inside the logo block.
 */
function omc_header_tagline() {
	if ( ! get_theme_mod( 'omc_tagline_enabled', true ) ) {
		return;
	}
	$lines = omc_header_taglines();
	if ( ! $lines ) {
		return;
	}
	$speed = max( 1200, (int) get_theme_mod( 'omc_tagline_speed', 3600 ) );
	?>
	<p class="omc-tagline js-omc-tagline" data-speed="<?php echo esc_attr( $speed ); ?>">
		<?php foreach ( $lines as $i => $line ) : ?>
			<span class="omc-tagline__item<?php echo 0 === $i ? ' is-on' : ''; ?>"<?php echo 0 === $i ? '' : ' aria-hidden="true"'; ?>><?php echo esc_html( $line ); ?></span>
		<?php endforeach; ?>
	</p>
	<?php
}

function omc_customize_register( WP_Customize_Manager $wp_customize ) {
	$wp_customize->add_section( 'omc_live', [
		'title'       => __( 'Facebook Live (home banner)', 'moderno-child' ),
		'priority'    => 35,
		'description' => __( 'The "next live" band on the home page. Leave a text field empty to use the built-in copy.', 'moderno-child' ),
	] );
	$fields = [
		'omc_live_enabled'  => [ 'label' => __( 'Show the Facebook Live band', 'moderno-child' ), 'type' => 'checkbox', 'default' => true, 'sanitize' => 'rest_sanitize_boolean' ],
		'omc_live_datetime' => [ 'label' => __( 'Date and time of the next live', 'moderno-child' ), 'type' => 'datetime-local', 'default' => '', 'sanitize' => 'omc_sanitize_datetime_local', 'description' => sprintf( __( 'Site timezone: %s (Settings → General).', 'moderno-child' ), wp_timezone_string() ) ],
		'omc_live_title'    => [ 'label' => __( 'Title', 'moderno-child' ), 'type' => 'text', 'default' => '', 'sanitize' => 'sanitize_text_field' ],
		'omc_live_text'     => [ 'label' => __( 'Short text', 'moderno-child' ), 'type' => 'textarea', 'default' => '', 'sanitize' => 'sanitize_textarea_field' ],
		'omc_live_url'      => [ 'label' => __( 'Facebook page or event URL', 'moderno-child' ), 'type' => 'url', 'default' => '', 'sanitize' => 'esc_url_raw' ],
		'omc_live_button'   => [ 'label' => __( 'Form button label', 'moderno-child' ), 'type' => 'text', 'default' => '', 'sanitize' => 'sanitize_text_field' ],
	];
	foreach ( $fields as $id => $f ) {
		$wp_customize->add_setting( $id, [ 'default' => $f['default'], 'sanitize_callback' => $f['sanitize'], 'transport' => 'refresh' ] );
		$wp_customize->add_control( $id, [ 'section' => 'omc_live', 'label' => $f['label'], 'type' => $f['type'], 'description' => $f['description'] ?? '' ] );
	}

	$wp_customize->add_section( 'omc_tagline', [
		'title'       => __( 'Header tagline', 'moderno-child' ),
		'priority'    => 34,
		'description' => __( 'The short line that cycles under the logo. Leave the phrases empty to use the built-in set.', 'moderno-child' ),
	] );
	$tagline = [
		'omc_tagline_enabled' => [ 'label' => __( 'Show the line under the logo', 'moderno-child' ), 'type' => 'checkbox', 'default' => true, 'sanitize' => 'rest_sanitize_boolean' ],
		'omc_tagline_lines'   => [ 'label' => __( 'Phrases', 'moderno-child' ), 'type' => 'textarea', 'default' => '', 'sanitize' => 'sanitize_textarea_field', 'description' => __( 'One per line, in the order they appear. Three or four words each reads best; anything much longer wraps on a phone.', 'moderno-child' ) ],
		'omc_tagline_speed'   => [ 'label' => __( 'Seconds on each phrase', 'moderno-child' ), 'type' => 'number', 'default' => 3600, 'sanitize' => 'absint', 'description' => __( 'In milliseconds. 3600 is a comfortable read; below 1200 it is ignored.', 'moderno-child' ) ],
	];
	foreach ( $tagline as $id => $f ) {
		$wp_customize->add_setting( $id, [ 'default' => $f['default'], 'sanitize_callback' => $f['sanitize'], 'transport' => 'refresh' ] );
		$wp_customize->add_control( $id, [ 'section' => 'omc_tagline', 'label' => $f['label'], 'type' => $f['type'], 'description' => $f['description'] ?? '' ] );
	}
}
add_action( 'customize_register', 'omc_customize_register' );

/**
 * Social links for the announcement bar (and, once real URLs exist, the footer + JSON-LD sameAs).
 * URLs come from the parent's Customizer: Appearance → Customize → Social Media Links (facebook, instagram,
 * pinterest) and the header email. Empty or "#" values fall back to the network's home page so the icons are
 * always present — replace them there. Override everything with the `omc_social_links` filter.
 */
function omc_social_links() {
	$mod = function ( $key ) {
		$v = function_exists( 'ideapark_mod' ) ? trim( (string) ideapark_mod( $key ) ) : '';
		return ( '' === $v || '#' === $v ) ? '' : $v;
	};
	$email = $mod( 'header_email' );
	if ( ! $email || false !== stripos( $email, 'moderno-demo' ) ) { // the demo's placeholder address
		$email = get_option( 'admin_email' );
	}
	$links = [
		'facebook'  => [ 'label' => 'Facebook',  'url' => $mod( 'facebook' ) ?: 'https://www.facebook.com/' ],
		'instagram' => [ 'label' => 'Instagram', 'url' => $mod( 'instagram' ) ?: 'https://www.instagram.com/' ],
	];

	// Customizer → Social Media Links → TikTok. No placeholder: the icon appears once a real URL is saved.
	if ( $mod( 'tiktok' ) ) {
		$links['tiktok'] = [ 'label' => 'TikTok', 'url' => $mod( 'tiktok' ) ];
	}

	$links['email']     = [ 'label' => __( 'Email us', 'moderno-child' ), 'url' => 'mailto:' . $email ];
	$links['pinterest'] = [ 'label' => 'Pinterest', 'url' => $mod( 'pinterest' ) ?: 'https://www.pinterest.com/' ];

	return apply_filters( 'omc_social_links', $links );
}

/** Real profile URLs (not the fallbacks, not mailto) feed the footer list and the Organization schema. */
add_filter( 'omc_social_profiles', function ( $profiles ) {
	if ( $profiles ) {
		return $profiles;
	}
	foreach ( omc_social_links() as $key => $l ) {
		if ( 'email' !== $key && ! preg_match( '#^https://www\.[a-z]+\.com/?$#', $l['url'] ) ) {
			$profiles[ $l['label'] ] = $l['url'];
		}
	}
	return $profiles;
} );

/**
 * Social icons on the left of the announcement bar. The parent builds the bar's HTML at wp_head priority 1
 * (ideapark_advert_bar_render → temp mod `_advert_bar`) and header.php prints it; we prepend the icons at priority 2.
 */
function omc_advert_bar_social() {
	if ( ! function_exists( 'ideapark_mod' ) || ! function_exists( 'ideapark_mod_set_temp' ) ) {
		return;
	}
	$bar = (string) ideapark_mod( '_advert_bar' );
	if ( '' === $bar || false !== strpos( $bar, 'omc-announce__social' ) ) {
		return;
	}
	/*
	 * On a phone the icons and the countdown share one strip and collide. The countdown is the
	 * thing worth the space, so the icons step aside — they are in the footer too, and on desktop
	 * they live in the logo row rather than here.
	 */
	$live_bar = omc_advert_bar_live( $bar );
	$compact  = ( $live_bar !== $bar ) ? ' omc-announce__social--compact' : '';

	$html = '<div class="omc-announce__social' . $compact . '" aria-label="' . esc_attr__( 'Follow Oops, Mine Co.', 'moderno-child' ) . '">';
	foreach ( omc_social_links() as $key => $l ) {
		$external = 0 !== strpos( $l['url'], 'mailto:' );
		$html    .= '<a href="' . esc_url( $l['url'] ) . '" aria-label="' . esc_attr( $l['label'] ) . '" title="' . esc_attr( $l['label'] ) . '"' . ( $external ? ' target="_blank" rel="noopener"' : '' ) . '>' . omc_icon( $key ) . '</a>';
	}
	$html .= '</div>';
	ideapark_mod_set_temp( '_advert_bar', $html . $live_bar );
}
add_action( 'wp_head', 'omc_advert_bar_social', 2 );

/**
 * The site logo. The Customizer logo (Appearance → Customize → Logo) wins on light backgrounds when one is set;
 * otherwise the brand files bundled with the theme are used (assets/img/oops-logo-<variant>.png, 1071×729,
 * transparent). Variants: rose (theme rose-gold, light backgrounds — header, footer), cream (pale rose, dark
 * backgrounds — hero), plus the client's black and white originals. Tinted files are made by
 * .ftp-sync/tools/make-logo-variants.php. Returns [ url, width, height, custom ]. Filter `omc_logo` to change.
 */
function omc_logo( $variant = 'rose' ) {
	$variant = in_array( $variant, [ 'rose', 'cream', 'black', 'white' ], true ) ? $variant : 'rose';
	$custom  = function_exists( 'ideapark_mod' ) ? trim( (string) ideapark_mod( 'logo' ) ) : '';
	if ( in_array( $variant, [ 'rose', 'black' ], true ) && $custom ) {
		return [ 'url' => $custom, 'width' => (int) ideapark_mod( 'logo__width' ), 'height' => (int) ideapark_mod( 'logo__height' ), 'custom' => true ];
	}
	/*
	 * The 400px copy, not the 1071px master. The header draws the mark between 78 and 132 pixels
	 * wide, so the master was about 48 kB of PNG per page — twice on the home page — to paint
	 * something smaller than a thumbnail. 400px is still 3x for the largest of those slots.
	 * Regenerate with .ftp-sync/tools/make-logo-small.php after changing the palette.
	 */
	$file = OMC_DIR . '/assets/img/oops-logo-' . $variant . '-400.png';
	$url  = OMC_URI . '/assets/img/oops-logo-' . $variant . ( file_exists( $file ) ? '-400' : '' ) . '.png';

	return apply_filters(
		'omc_logo',
		[
			'url'    => $url,
			'width'  => 1071,   // the intrinsic ratio the browser should reserve, not the file's pixels
			'height' => 729,
			'custom' => false,
		],
		$variant
	);
}

/** <img> for omc_logo(): intrinsic width/height (no layout shift), alt = site name, any extra attributes. */
function omc_logo_img( $variant = 'rose', $class = '', $attrs = [] ) {
	$logo  = omc_logo( $variant );
	$attrs = wp_parse_args( $attrs, [ 'class' => $class, 'alt' => get_bloginfo( 'name' ), 'decoding' => 'async' ] );
	$html  = '<img src="' . esc_url( $logo['url'] ) . '"';
	if ( $logo['width'] && $logo['height'] ) {
		$html .= ' width="' . (int) $logo['width'] . '" height="' . (int) $logo['height'] . '"';
	}
	foreach ( $attrs as $k => $v ) {
		if ( '' !== $v && null !== $v && false !== $v ) {
			$html .= ' ' . esc_attr( $k ) . '="' . esc_attr( $v ) . '"';
		}
	}
	return $html . '>';
}

/** Tiny inline line-icons (no icon font needed). */
function omc_icon( $name ) {
	$icons = [
		'sparkle' => '<path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z"/><path d="M19 16l.8 2.2L22 19l-2.2.8L19 22l-.8-2.2L16 19l2.2-.8z"/>',
		'hand'    => '<path d="M8 13V6a2 2 0 1 1 4 0v6"/><path d="M12 12V5a2 2 0 1 1 4 0v8"/><path d="M16 13V8a2 2 0 1 1 4 0v6a7 7 0 0 1-7 7h-1a7 7 0 0 1-6-3.4L3.6 14a2 2 0 0 1 3.3-2.2L8 13"/>',
		'return'  => '<path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/>',
		'lock'    => '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
		'shield'  => '<path d="M12 3l7 3v5.5c0 4.3-2.9 7.8-7 9.5-4.1-1.7-7-5.2-7-9.5V6z"/><path d="M9.2 12.2l2 2 3.6-3.9"/>',
		'card'    => '<rect x="3" y="6" width="18" height="12" rx="2"/><path d="M3 10.5h18"/><path d="M6.5 14.5h3"/>',
		'truck'   => '<path d="M3 7h10v9H3z"/><path d="M13 10h4l3 3v3h-7z"/><circle cx="7" cy="18" r="1.8"/><circle cx="17" cy="18" r="1.8"/>',
		'arrow'   => '<path d="M5 12h14"/><path d="M13 6l6 6-6 6"/>',
		'facebook' => '<path fill="currentColor" stroke="none" d="M13.6 21v-7.2h2.4l.4-2.9h-2.8V9.1c0-.8.3-1.4 1.4-1.4h1.5V5.1c-.3 0-1.2-.1-2.2-.1-2.2 0-3.6 1.3-3.6 3.8v2.1H8.3v2.9h2.4V21z"/>',
		'instagram' => '<rect x="3.5" y="3.5" width="17" height="17" rx="4.5"/><circle cx="12" cy="12" r="3.8"/><circle cx="17.2" cy="6.8" r="1" fill="currentColor" stroke="none"/>',
		'tiktok'    => '<path fill="currentColor" stroke="none" d="M16.1 3h-2.6v11.4a2.3 2.3 0 1 1-2-2.3V9.4a5 5 0 1 0 4.6 5V8.6a6 6 0 0 0 3.4 1.1V7.1a3.6 3.6 0 0 1-3.4-3.6z"/>',
		'email'    => '<rect x="3" y="5.5" width="18" height="13" rx="2"/><path d="M3.5 7l8.5 6 8.5-6"/>',
		'star'     => '<path fill="currentColor" stroke="none" d="M12 2.6l2.9 6 6.6.9-4.8 4.6 1.2 6.5L12 17.5l-5.9 3.1 1.2-6.5L2.5 9.5l6.6-.9z"/>',
		'pinterest' => '<path fill="currentColor" stroke="none" d="M12 2.5a9.5 9.5 0 0 0-3.5 18.4c-.1-.8-.2-2 0-2.8l1.2-5s-.3-.6-.3-1.5c0-1.4.8-2.4 1.8-2.4.8 0 1.3.6 1.3 1.4 0 .9-.6 2.1-.8 3.3-.2 1 .5 1.8 1.5 1.8 1.8 0 3.1-1.9 3.1-4.6 0-2.4-1.7-4.1-4.2-4.1-2.9 0-4.5 2.1-4.5 4.4 0 .9.3 1.8.7 2.3.1.1.1.2.1.3l-.3 1.2c0 .2-.2.2-.4.1-1.3-.6-2-2.4-2-3.9 0-3.2 2.3-6.1 6.6-6.1 3.5 0 6.2 2.5 6.2 5.8 0 3.4-2.2 6.2-5.2 6.2-1 0-2-.5-2.3-1.1l-.6 2.4c-.2.9-.8 2-1.2 2.6A9.5 9.5 0 1 0 12 2.5z"/>',
	];
	$path = $icons[ $name ] ?? $icons['arrow'];
	return '<svg class="omc-icon omc-icon--' . esc_attr( $name ) . '" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $path . '</svg>';
}

function omc_shop_url( $args = [] ) {
	$url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/shop/' );
	return $args ? add_query_arg( $args, $url ) : $url;
}

function omc_new_arrivals_url() {
	return apply_filters( 'omc_new_arrivals_url', omc_shop_url( [ 'orderby' => 'date' ] ) );
}

function omc_page_url( $slug, $fallback = '/' ) {
	$page = get_page_by_path( $slug );
	return $page ? get_permalink( $page ) : home_url( $fallback );
}

/**
 * Footer link columns (shop / help / legal) for footer.php.
 * Shop links come from the home categories that actually exist; help/legal links only include
 * published pages, so the footer never points at a 404. Override with the `omc_footer_links` filter.
 */
function omc_footer_links() {
	$shop = [ [ 'label' => __( 'New arrivals', 'moderno-child' ), 'url' => omc_new_arrivals_url() ] ];
	foreach ( array_slice( omc_home_categories(), 0, 6 ) as $slug ) {
		$term = get_term_by( 'slug', $slug, 'product_cat' );
		$link = $term ? get_term_link( $term ) : null;
		if ( $link && ! is_wp_error( $link ) ) {
			$shop[] = [ 'label' => $term->name, 'url' => $link ];
		}
	}
	$shop[] = [ 'label' => __( 'Shop all', 'moderno-child' ), 'url' => omc_shop_url() ];

	$pages = function ( $map ) {
		$out = [];
		foreach ( $map as $slug => $label ) {
			$page = get_page_by_path( $slug );
			if ( $page && 'publish' === $page->post_status ) {
				$out[] = [ 'label' => $label, 'url' => get_permalink( $page ) ];
			}
		}
		return $out;
	};

	return apply_filters( 'omc_footer_links', [
		'shop'  => $shop,
		'help'  => $pages( [
			'about-us'       => __( 'Our story', 'moderno-child' ),
			'contacts'       => __( 'Contact us', 'moderno-child' ),
			'faq'            => __( 'FAQ', 'moderno-child' ),
			'order-tracking' => __( 'Track your order', 'moderno-child' ),
			'refund_returns' => __( 'Returns & refunds', 'moderno-child' ),
			'my-account'     => __( 'My account', 'moderno-child' ),
			'wishlist'       => __( 'Wishlist', 'moderno-child' ),
			'blog'           => __( 'Journal', 'moderno-child' ),
		] ),
		'legal' => $pages( [
			'privacy-policy'       => __( 'Privacy policy', 'moderno-child' ),
			'terms-and-conditions' => __( 'Terms & conditions', 'moderno-child' ),
			'refund_returns'       => __( 'Refund policy', 'moderno-child' ),
		] ),
	] );
}

/** A product photo that represents a category (the demo's category thumbnails are line-art icons). */
function omc_category_image( $slug ) {
	static $cache = [];
	if ( isset( $cache[ $slug ] ) ) {
		return $cache[ $slug ];
	}
	$id = 0;
	if ( function_exists( 'wc_get_products' ) ) {
		$sample = wc_get_products( [ 'category' => [ $slug ], 'limit' => 1, 'status' => 'publish', 'orderby' => 'date', 'order' => 'DESC' ] );
		if ( $sample ) {
			$id = (int) $sample[0]->get_image_id();
		}
	}
	if ( ! $id ) {
		$term = get_term_by( 'slug', $slug, 'product_cat' );
		$id   = $term ? (int) get_term_meta( $term->term_id, 'thumbnail_id', true ) : 0;
	}
	return $cache[ $slug ] = $id;
}

function omc_category_url( $slug, $fallback = null ) {
	$term = get_term_by( 'slug', $slug, 'product_cat' );
	$link = $term ? get_term_link( $term ) : null;
	return $link && ! is_wp_error( $link ) ? $link : ( $fallback ?: omc_shop_url() );
}

/**
 * Banner sets for the home page, Chicwish-style: image + short overlaid copy + "Shop now".
 * Each banner: image (upload-relative path or attachment ID), eyebrow, title, text, cta, url,
 * pos (bl|br|c = where the copy sits), tone (dark|light = copy colour). Override with `omc_home_banners`.
 */
function omc_home_banners() {
	$images = omc_images();
	return apply_filters( 'omc_home_banners', [
		'campaign' => [ // split banner: photos (cross-fading) left, cream copy panel right
			'images'  => [ '2023/04/ricky-2127760710.jpg', '2023/04/moderno-2346592900.jpg', '2023/04/moderno-2310883787.jpg' ],
			'side'    => 'left',
			'eyebrow' => __( 'New season', 'moderno-child' ),
			'title'   => __( 'The Seoul edit', 'moderno-child' ),
			'text'    => __( 'Soft knits, clean lines and the quiet details you notice second. New pieces from Seoul, chosen one at a time.', 'moderno-child' ),
			'cta'     => __( 'Shop new arrivals', 'moderno-child' ),
			'url'     => omc_new_arrivals_url(),
		],
		'campaign2' => [ // mirrored split banner under the carousel: copy left, photos (cross-fading) right
			'images'  => [ '2023/04/ricky-2347110865.jpg', '2023/04/ricky-2131286131.jpg', '2023/04/moderno-2346546369.jpg' ],
			'side'    => 'right',
			'eyebrow' => __( 'Warm-weather dressing', 'moderno-child' ),
			'title'   => __( 'The Bangkok edit', 'moderno-child' ),
			'text'    => __( 'Light fabrics, easy silhouettes and the finishing touches that travel well — made for long days, warm evenings and everything in between.', 'moderno-child' ),
			'cta'     => __( 'Shop dresses & accessories', 'moderno-child' ),
			'url'     => omc_category_url( 'dresses' ),
		],
		'carousel' => [ // square slides under the campaign banner (Chicwish's second row); title + link per slide
			[ 'image' => omc_category_image( 'dresses' ), 'title' => __( 'The dress edit', 'moderno-child' ), 'url' => omc_category_url( 'dresses' ) ],
			[ 'image' => '2023/04/moderno-2310883787.jpg', 'title' => __( 'The colour edit', 'moderno-child' ), 'url' => omc_shop_url() ],
			[ 'image' => omc_category_image( 'skirts' ), 'title' => __( 'The skirt edit', 'moderno-child' ), 'url' => omc_category_url( 'skirts' ) ],
			[ 'image' => '2023/04/moderno-2355149011.jpg', 'title' => __( 'Everyday knits', 'moderno-child' ), 'url' => omc_shop_url() ],
			[ 'image' => '2023/04/ricky-2347791350.jpg', 'title' => __( 'The shirt edit', 'moderno-child' ), 'url' => omc_shop_url() ],
			[ 'image' => omc_category_image( 'handbags' ), 'title' => __( 'Handbags', 'moderno-child' ), 'url' => omc_category_url( 'handbags' ) ],
			[ 'image' => omc_category_image( 'sunglasses' ), 'title' => __( 'Sunglasses', 'moderno-child' ), 'url' => omc_category_url( 'sunglasses' ) ],
			[ 'image' => omc_category_image( 'shoes_and_accessories' ), 'title' => __( 'Shoes', 'moderno-child' ), 'url' => omc_category_url( 'shoes_and_accessories' ) ],
			[ 'image' => omc_category_image( 'hats' ), 'title' => __( 'Hats', 'moderno-child' ), 'url' => omc_category_url( 'hats' ) ],
			[ 'image' => '2023/04/ricky-2127760710.jpg', 'title' => __( 'New arrivals', 'moderno-child' ), 'url' => omc_new_arrivals_url() ],
		],
		'wide' => [ // full-width photo banner after New arrivals, copy on the left
			'image'   => '2023/04/ricky-2131286131.jpg',
			'eyebrow' => __( 'Everyday elegance', 'moderno-child' ),
			'title'   => __( 'Dressed, not done up', 'moderno-child' ),
			'text'    => __( 'Knits, tailoring and denim that work as hard on a Tuesday as they do on a night out.', 'moderno-child' ),
			'cta'     => __( 'Shop the collection', 'moderno-child' ),
			'url'     => omc_shop_url(),
			'pos'     => 'bl',
			'tone'    => 'dark',
		],
		'wide2' => [ // its pair, directly below: copy on the right
			'image'   => '2023/04/ricky-2152193566.jpg',
			'eyebrow' => __( 'Soft season', 'moderno-child' ),
			'title'   => __( 'Knits worth keeping', 'moderno-child' ),
			'text'    => __( 'Oversized, brushed and quietly patterned — the layers you’ll reach for first every morning.', 'moderno-child' ),
			'cta'     => __( 'Shop knitwear', 'moderno-child' ),
			'url'     => omc_category_url( 'tops', omc_shop_url() ),
			'pos'     => 'br',
			'tone'    => 'dark',
		],
		'wide3' => [ // third in the stack: copy on the left again
			'image'   => '2023/04/ricky-2152926699.jpg',
			'eyebrow' => __( 'After dark', 'moderno-child' ),
			'title'   => __( 'Evenings, softly', 'moderno-child' ),
			'text'    => __( 'Silk, satin and the small details that carry you from dinner to whatever comes next.', 'moderno-child' ),
			'cta'     => __( 'Shop dresses', 'moderno-child' ),
			'url'     => omc_category_url( 'dresses' ),
			'pos'     => 'bl',
			'tone'    => 'dark',
		],
		'more' => [ // 2-up tiles after Most loved
			[ 'image' => omc_category_image( 'handbags' ) ?: omc_category_image( 'accessories' ), 'eyebrow' => __( 'Finishing touches', 'moderno-child' ), 'title' => __( 'The accessories edit', 'moderno-child' ), 'cta' => __( 'Shop accessories', 'moderno-child' ), 'url' => omc_category_url( 'accessories' ), 'pos' => 'bl', 'tone' => 'dark' ],
			[ 'image' => omc_category_image( 'jeans_and_denim' ), 'eyebrow' => __( 'Worn in', 'moderno-child' ), 'title' => __( 'The denim edit', 'moderno-child' ), 'cta' => __( 'Shop denim', 'moderno-child' ), 'url' => omc_category_url( 'jeans_and_denim' ), 'pos' => 'bl', 'tone' => 'dark' ],
		],
	] );
}

/** Products shown as framed "editor's pick" banners. Override with `omc_home_feature_products` (array of product IDs). */
function omc_feature_products() {
	$ids = apply_filters( 'omc_home_feature_products', [] );
	if ( ! $ids && function_exists( 'wc_get_products' ) ) {
		// Default to dresses (the boutique's signature category) rather than the most-sold items overall.
		$cat = apply_filters( 'omc_home_feature_category', 'dresses' );
		$ids = wc_get_products( [ 'category' => $cat ? [ $cat ] : [], 'limit' => 2, 'status' => 'publish', 'orderby' => 'popularity', 'return' => 'ids', 'type' => [ 'simple', 'variable' ] ] );
	}
	return array_filter( array_map( 'wc_get_product', (array) $ids ) );
}

/**
 * Render one banner (image + copy). $size: wide | tile | split.
 * `images` (array) instead of `image` → the photos cross-fade (assets/js/omc.js);
 * `side` => 'right' puts the photo on the right of a split banner (copy on the left).
 */
function omc_banner( $b, $size = 'tile' ) {
	$b = wp_parse_args( $b, [ 'image' => '', 'images' => [], 'side' => 'left', 'eyebrow' => '', 'title' => '', 'text' => '', 'cta' => __( 'Shop now', 'moderno-child' ), 'url' => omc_shop_url(), 'pos' => 'bl', 'tone' => 'dark', 'alt' => '' ] );
	$files = $b['images'] ?: [ $b['image'] ];
	$img_size = 'split' === $size || 'wide' === $size ? 'full' : 'large';
	$alt   = $b['alt'] ?: wp_strip_all_tags( $b['title'] );
	$imgs  = [];
	foreach ( $files as $i => $file ) {
		$attrs = [ 'class' => 'omc-banner__img' . ( 0 === $i ? ' is-active' : '' ), 'loading' => 'lazy', 'alt' => $alt ];
		if ( $i > 0 ) {
			$attrs['aria-hidden'] = 'true'; // only the visible photo is announced
		}
		$html = omc_image( $file, $img_size, $attrs );
		if ( $html ) {
			$imgs[] = $html;
		}
	}
	if ( ! $imgs ) {
		return;
	}
	$classes = [ 'omc-banner', 'omc-banner--' . $size, 'omc-banner--pos-' . $b['pos'], 'omc-banner--' . $b['tone'] ];
	if ( 'right' === $b['side'] ) {
		$classes[] = 'omc-banner--media-right';
	}
	if ( count( $imgs ) > 1 ) {
		$classes[] = 'omc-banner--fade js-omc-fade';
	}
	?>
	<a class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" href="<?php echo esc_url( $b['url'] ); ?>" data-fade="4500">
		<span class="omc-banner__media"><?php echo implode( '', $imgs ); ?></span>
		<span class="omc-banner__content">
			<?php if ( $b['eyebrow'] ) : ?><span class="omc-eyebrow"><?php echo esc_html( $b['eyebrow'] ); ?></span><?php endif; ?>
			<span class="omc-banner__title"><?php echo esc_html( $b['title'] ); ?></span>
			<?php if ( $b['text'] ) : ?><span class="omc-banner__text"><?php echo esc_html( $b['text'] ); ?></span><?php endif; ?>
			<span class="omc-banner__cta"><?php echo esc_html( $b['cta'] ); ?><?php echo omc_icon( 'arrow' ); ?></span>
		</span>
	</a>
	<?php
}

/** Section header used by every home section. */
function omc_section_head( $eyebrow, $title, $link_url = '', $link_text = '', $id = '' ) {
	echo '<header class="omc-section__head">';
	echo '<div>';
	if ( $eyebrow ) {
		echo '<p class="omc-eyebrow">' . esc_html( $eyebrow ) . '</p>';
	}
	// The id the surrounding <section> names in aria-labelledby. Without it that reference points
	// at nothing and the section reaches a screen reader with no name at all.
	echo '<h2 class="omc-section__title"' . ( $id ? ' id="' . esc_attr( $id ) . '"' : '' ) . '>' . wp_kses( $title, [ 'em' => [], 'br' => [], 'span' => [ 'class' => [], 'data-words' => [] ] ] ) . '</h2>';
	echo '</div>';
	if ( $link_url ) {
		echo '<a class="omc-link" href="' . esc_url( $link_url ) . '">' . esc_html( $link_text ) . omc_icon( 'arrow' ) . '</a>';
	}
	echo '</header>';
}

/**
 * The "As seen on" strip on the home page.
 *
 * PLACEHOLDERS. These are names to swap, not coverage we have. Replace each entry with a real
 * mention (and ideally a link to it) before the store opens, or drop the section with
 * `add_filter( 'omc_press_logos', '__return_empty_array' );` — a press strip naming outlets that
 * never covered the brand is the kind of claim a shopper can check.
 *
 * @return array List of [ name, url ]. An empty list hides the section.
 */
function omc_press_logos() {
	/*
	 * `logo` is a path under assets/img/, and the entry falls back to its name when there is none —
	 * so a set-name placeholder and a real mark can sit in the same row while the others are
	 * replaced one at a time. `url` links the entry to the coverage itself where there is a page
	 * to link to, which is the thing that turns a logo into a citation.
	 *
	 * `scale` multiplies the row's logo height for that one mark. A single-line wordmark is 1; a
	 * stacked mark like the peacock spends most of its height on the symbol, so at 1 its letters
	 * come out half the size of the wordmarks beside it. Prepare a file and get a suggested number
	 * with .ftp-sync/tools/make-press-logo.php, which also trims each mark to its own ink so the
	 * heights mean the same thing.
	 */
	return apply_filters( 'omc_press_logos', [
		[ 'name' => 'FOX', 'logo' => 'press/fox.png', 'scale' => 1, 'url' => '' ],
		[ 'name' => 'NBC', 'logo' => 'press/nbc.png', 'scale' => 1.6, 'url' => '' ],
		[ 'name' => 'CBS', 'logo' => 'press/cbs.png', 'scale' => 0.95, 'url' => '' ],
		[ 'name' => 'ABC', 'logo' => 'press/abc.png', 'scale' => 1.5, 'url' => '' ],
	] );
}

/**
 * The line under the press logos: how many outlets, and who counted them.
 *
 * Separate from the logos because it is a different kind of statement. A logo says "this outlet";
 * a number says "this many", and a number wants a source next to it — which is what `verifier` is
 * for. Point `url` at the report and the attribution becomes something a visitor can check.
 *
 * Empty either string to drop that line; empty both and the whole block goes.
 *
 * @return array [ count, verifier, url ].
 */
function omc_press_note() {
	return (array) apply_filters( 'omc_press_note', [
		'count'    => __( '+ Over 250 News Outlets', 'moderno-child' ),
		'verifier' => __( 'Verified By The Buzz Blast', 'moderno-child' ),
		'url'      => '',
	] );
}

/**
 * One press entry: its logo if it has one, otherwise its name set as type.
 *
 * The image carries the outlet name as alt text, so the row reads the same to a screen reader
 * whether an entry is a picture or a word.
 *
 * @param array $entry [ name, logo, url ].
 * @return string
 */
function omc_press_mark( array $entry ) {
	$name = (string) ( $entry['name'] ?? '' );
	$logo = (string) ( $entry['logo'] ?? '' );
	if ( ! $logo || ! file_exists( OMC_DIR . '/assets/img/' . $logo ) ) {
		return '<span class="omc-press__name">' . esc_html( $name ) . '</span>';
	}
	$size  = @getimagesize( OMC_DIR . '/assets/img/' . $logo );
	$scale = (float) ( $entry['scale'] ?? 1 );
	$scale = $scale > 0 ? min( 2.5, max( 0.5, $scale ) ) : 1;

	return sprintf(
		'<img class="omc-press__logo" src="%s" alt="%s" %s style="--omc-press-scale:%s" loading="lazy" decoding="async">',
		esc_url( OMC_URI . '/assets/img/' . $logo ),
		esc_attr( $name ),
		$size ? sprintf( 'width="%d" height="%d"', (int) $size[0], (int) $size[1] ) : '',
		esc_attr( rtrim( rtrim( number_format( $scale, 2, '.', '' ), '0' ), '.' ) )
	);
}

/**
 * The reassurance row in the footer: what is true about paying here, said plainly.
 *
 * No card-brand marks on purpose — printing a Visa or PayPal logo asserts a gateway that is not
 * connected yet. These say what the site itself can back up.
 *
 * @return array List of [ icon, title, text ].
 */
function omc_footer_badges() {
	return apply_filters( 'omc_footer_badges', [
		[
			'icon'  => 'lock',
			'title' => __( 'Secure checkout', 'moderno-child' ),
			'text'  => __( 'Encrypted end to end', 'moderno-child' ),
		],
		[
			'icon'  => 'shield',
			'title' => __( 'Your details stay yours', 'moderno-child' ),
			'text'  => __( 'Never sold, never shared', 'moderno-child' ),
		],
		[
			'icon'  => 'card',
			'title' => __( 'Card numbers never touch us', 'moderno-child' ),
			'text'  => __( 'Handled by the payment processor', 'moderno-child' ),
		],
		[
			'icon'  => 'return',
			'title' => __( '7-day store-credit returns', 'moderno-child' ),
			'text'  => __( 'Terms on the return policy', 'moderno-child' ),
		],
	] );
}

/**
 * The photograph behind a page's header band.
 *
 * Every page gets one so the headers read as a set rather than three different treatments. The
 * picture is chosen by what the page is about; a page with a featured image uses that instead,
 * which is how a journal post ends up behind its own headline.
 *
 * @return string Uploads-relative path or an attachment id. Empty hides the photo and the band
 *                falls back to the flat brand colour.
 */
function omc_page_header_bg() {
	$images = omc_images();
	$bg     = $images['hero'];

	if ( is_singular() && has_post_thumbnail() ) {
		$bg = (int) get_post_thumbnail_id();
	} elseif ( function_exists( 'is_product_category' ) && is_product_category() ) {
		$term  = get_queried_object();
		$thumb = $term ? (int) get_term_meta( $term->term_id, 'thumbnail_id', true ) : 0;
		$bg    = $thumb ?: $images['edit_a'];
	} elseif ( function_exists( 'is_shop' ) && is_shop() ) {
		$bg = $images['edit_a'];
	} elseif ( is_home() || is_archive() ) {
		$bg = $images['quotes'];
	} elseif ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() ) ) {
		$bg = $images['newsletter'];
	} elseif ( function_exists( 'is_account_page' ) && is_account_page() ) {
		$bg = $images['live'];
	}

	/**
	 * Filter the header photograph for the current view.
	 *
	 * @param string|int $bg Uploads-relative path or attachment id.
	 */
	return apply_filters( 'omc_page_header_bg', $bg );
}

/**
 * Hand the header photograph to CSS as a custom property.
 *
 * A custom property rather than an inline background so the band's gradient, blur and text colour
 * all stay in the stylesheet where they can be read together.
 */
function omc_page_header_bg_css() {
	if ( is_front_page() ) {
		return; // the home page has its own full-bleed hero
	}
	$id = omc_attachment_id( omc_page_header_bg() );
	if ( ! $id ) {
		return;
	}
	$url = wp_get_attachment_image_url( $id, 'full' );
	if ( ! $url ) {
		return;
	}
	printf(
		'<style id="omc-header-bg">:root{--omc-head-bg:url(%s);}</style>' . "\n",
		esc_url( $url )
	);
}
add_action( 'wp_head', 'omc_page_header_bg_css', 7 );

/**
 * "New Arrivals" is a real destination in the menu, but it points at the shop sorted by date, so
 * the shop's own title greeted people with "Shop". Name the page after what they clicked.
 *
 * @param string $title The title WooCommerce built.
 */
function omc_shop_sorted_title( $title, $id = 0 ) {
	if ( ! function_exists( 'is_shop' ) || ! is_shop() || is_admin() ) {
		return $title;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a sort link, not a form submission
	$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : '';
	if ( 'date' !== $orderby ) {
		return $title;
	}
	// The theme titles the shop with get_the_title( shop page id ), so that is what to intercept.
	return ( (int) $id === (int) wc_get_page_id( 'shop' ) ) ? __( 'New Arrivals', 'moderno-child' ) : $title;
}
add_filter( 'the_title', 'omc_shop_sorted_title', 10, 2 );

/**
 * The header band for a screen the parent theme leaves untitled.
 *
 * The parent prints nothing at all on an empty cart or a logged-out account, so there is no title
 * to filter — the band has to be rendered here. Same classes as the parent's, so it picks up the
 * photo treatment with it.
 *
 * @param string $title Title to show.
 */
function omc_bare_header_band( $title ) {
	?>
	<header class="l-section c-page-header c-page-header--header-type-1 c-page-header--low omc-head-bare">
		<div class="c-page-header__row-2 c-page-header__row-2--1-columns l-section__container">
			<div class="c-page-header__row-2-col c-page-header__row-2-col--title-breadcrumbs">
				<h1 class="c-page-header__title c-page-header__title--center"><?php echo esc_html( $title ); ?></h1>
			</div>
		</div>
		<div class="c-page-header__line"></div>
	</header>
	<?php
}

/**
 * The announcement bar counts down to the next Facebook Live.
 *
 * The bar used to carry three fixed lines of copy. A countdown earns its place better: it is the
 * one thing on the page that changes, it gives a reason to come back, and the reminder button next
 * to it captures an email at the moment someone is interested rather than on the way out.
 *
 * Date, copy and the Facebook link all come from Customizer → "Facebook Live (home banner)", the
 * same settings the home page card reads, so the two never disagree. Once the live has been and
 * gone the bar falls back to whatever the announcement block says.
 *
 * @param string $bar The bar HTML the parent theme built.
 * @return string
 */
function omc_advert_bar_live( $bar ) {
	$live = omc_fb_live();
	if ( empty( $live['enabled'] ) ) {
		return $bar;
	}

	// An hour of "live now", then the bar goes back to the ordinary announcement.
	$window = (int) apply_filters( 'omc_live_window', 2 * HOUR_IN_SECONDS );
	if ( $live['when']->getTimestamp() + $window < time() ) {
		return $bar;
	}

	// Single letters, set in the bar's own face at the bar's own size. The three-letter labels
	// introduced a second typographic system into a strip 46 pixels tall.
	$units = [
		'd' => _x( 'd', 'countdown unit, short', 'moderno-child' ),
		'h' => _x( 'h', 'countdown unit, short', 'moderno-child' ),
		'm' => _x( 'm', 'countdown unit, short', 'moderno-child' ),
		's' => _x( 's', 'countdown unit, short', 'moderno-child' ),
	];

	$html  = '<div class="omc-livebar js-omc-countdown" data-until="' . esc_attr( $live['when']->format( DATE_ATOM ) ) . '" data-live-window="' . esc_attr( (string) $window ) . '">';
	$html .= '<span class="omc-livebar__dot" aria-hidden="true"></span>';
	$html .= '<span class="omc-livebar__lead">' . esc_html__( 'Next live', 'moderno-child' ) . '</span>';

	$html .= '<span class="omc-livebar__clock">';
	foreach ( $units as $key => $label ) {
		$html .= '<span class="omc-livebar__unit"><b class="omc-live__num" data-unit="' . esc_attr( $key ) . '">--</b><i>' . esc_html( $label ) . '</i></span>';
	}
	$html .= '</span>';

	// Swapped in by CSS once the countdown hits zero.
	$html .= '<span class="omc-livebar__now">' . esc_html__( 'We are live now', 'moderno-child' ) . '</span>';
	$html .= '<span class="omc-livebar__tag">' . esc_html__( 'Be the first to get it', 'moderno-child' ) . '</span>';
	$html .= '<button type="button" class="omc-livebar__cta" data-omc-live-open>' . esc_html__( 'Remind me', 'moderno-child' ) . '</button>';
	$html .= '<a class="omc-livebar__watch" href="' . esc_url( $live['url'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'Watch now', 'moderno-child' ) . '</a>';
	$html .= '</div>';

	return $html;
}

/**
 * The reminder dialog the bar's button opens.
 *
 * The form is the site's own newsletter form with source `fb_live`, so a sign-up here lands in the
 * WHD subscriber table and goes out to whichever provider is connected in WHD → Integrations,
 * exactly as the home page live card does. No second list, no second integration to configure.
 */
function omc_live_reminder_modal() {
	$live = omc_fb_live();
	if ( empty( $live['enabled'] ) ) {
		return;
	}
	$window = (int) apply_filters( 'omc_live_window', 2 * HOUR_IN_SECONDS );
	if ( $live['when']->getTimestamp() + $window < time() ) {
		return;
	}
	?>
	<div class="omc-livemodal" id="omc-live-reminder" role="dialog" aria-modal="true" aria-labelledby="omc-live-reminder-title" hidden>
		<div class="omc-livemodal__backdrop" data-omc-live-close></div>
		<div class="omc-livemodal__panel" role="document">
			<button type="button" class="omc-livemodal__close" data-omc-live-close aria-label="<?php esc_attr_e( 'Close', 'moderno-child' ); ?>">&times;</button>
			<p class="omc-eyebrow"><?php esc_html_e( 'Next live', 'moderno-child' ); ?></p>
			<h2 class="omc-livemodal__title" id="omc-live-reminder-title"><?php echo esc_html( $live['title'] ); ?></h2>
			<p class="omc-livemodal__when"><?php echo esc_html( wp_date( 'l j F, g:i a', $live['when']->getTimestamp() ) ); ?></p>
			<p class="omc-livemodal__text"><?php echo esc_html( $live['text'] ); ?></p>
			<?php
			omc_newsletter_form( [
				'source' => 'fb_live',
				'button' => $live['button'],
				'after'  => '#omc-live-reminder .omc-livemodal__link',
			] );
			?>
			<p class="omc-livemodal__link" hidden>
				<a class="omc-btn omc-btn--outline" href="<?php echo esc_url( $live['url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open the Facebook page', 'moderno-child' ); ?></a>
			</p>
		</div>
	</div>
	<?php
}
// Priority 5: footer scripts print at 20, and omc.js binds the newsletter form by querying the
// DOM at load. A dialog printed after that would submit natively and reload the page.
add_action( 'wp_footer', 'omc_live_reminder_modal', 5 );

/**
 * The welcome discount a newsletter sign-up earns.
 *
 * Read from WHD → Settings so the code the site promises is the code the plugin actually created.
 * Returning an empty string turns the offer into a plain sign-up with no code.
 */
function omc_welcome_coupon() {
	$code = '';
	if ( class_exists( 'WHD_Plugin' ) && method_exists( 'WHD_Plugin', 'settings' ) ) {
		$settings = WHD_Plugin::settings();
		$code     = (string) ( $settings['exit_coupon'] ?? '' );
	}
	if ( $code && function_exists( 'wc_get_coupon_id_by_code' ) && ! wc_get_coupon_id_by_code( $code ) ) {
		$code = ''; // the code was renamed or deleted; do not promise one that will not work
	}
	return (string) apply_filters( 'omc_welcome_coupon', $code );
}

/** How much that coupon is worth, as a string like "15%". Empty when there is no coupon. */
function omc_welcome_coupon_amount() {
	$code = omc_welcome_coupon();
	if ( ! $code || ! function_exists( 'wc_get_coupon_id_by_code' ) ) {
		return '';
	}
	$id = wc_get_coupon_id_by_code( $code );
	if ( ! $id ) {
		return '';
	}
	$coupon = new WC_Coupon( $id );
	return 'percent' === $coupon->get_discount_type() ? rtrim( rtrim( (string) $coupon->get_amount(), '0' ), '.' ) . '%' : '';
}

/**
 * The floating tab down the side of every page, and the dialog it opens.
 *
 * One job: trade a discount for an email address. The code shown afterwards is a real WooCommerce
 * coupon, so the promise on the button is one checkout can actually keep — if the coupon is ever
 * deleted the offer quietly becomes a plain sign-up rather than a broken code.
 *
 * The form is the site's own newsletter form, source `discount`, so it lands in the WHD subscriber
 * table and syncs to whatever provider is connected in WHD → Integrations.
 */
function omc_discount_tab() {
	if ( is_admin() || ! apply_filters( 'omc_discount_tab', true ) ) {
		return;
	}
	$code   = omc_welcome_coupon();
	$amount = omc_welcome_coupon_amount();
	$label  = $amount
		/* translators: %s: discount amount, e.g. 15% */
		? sprintf( __( 'Get %s off', 'moderno-child' ), $amount )
		: __( 'Get discount', 'moderno-child' );
	?>
	<button type="button" class="omc-disctab" data-omc-modal="omc-discount" aria-haspopup="dialog" aria-controls="omc-discount">
		<span class="omc-disctab__label"><?php echo esc_html( $label ); ?></span>
	</button>

	<div class="omc-modal omc-modal--discount" id="omc-discount" role="dialog" aria-modal="true" aria-labelledby="omc-discount-title" hidden>
		<div class="omc-modal__backdrop" data-omc-modal-close></div>
		<div class="omc-modal__panel" role="document">
			<button type="button" class="omc-modal__close" data-omc-modal-close aria-label="<?php esc_attr_e( 'Close', 'moderno-child' ); ?>">&times;</button>
			<p class="omc-eyebrow"><?php esc_html_e( 'For the list', 'moderno-child' ); ?></p>
			<h2 class="omc-modal__title" id="omc-discount-title">
				<?php
				echo $amount
					/* translators: %s: discount amount, e.g. 15% */
					? esc_html( sprintf( __( 'Take %s off your first order', 'moderno-child' ), $amount ) )
					: esc_html__( 'Join the list', 'moderno-child' );
				?>
			</h2>
			<p class="omc-modal__text">
				<?php esc_html_e( 'One email when a new rack goes up, one before we go live, and the code below to use whenever you are ready. Nothing else.', 'moderno-child' ); ?>
			</p>
			<?php
			omc_newsletter_form( [
				'source' => 'discount',
				'button' => $code ? __( 'Send me the code', 'moderno-child' ) : __( 'Join the list', 'moderno-child' ),
				'after'  => '#omc-discount .omc-modal__reveal',
			] );
			?>
			<?php if ( $code ) : ?>
				<div class="omc-modal__reveal" hidden>
					<p class="omc-modal__code-label"><?php esc_html_e( 'Your code', 'moderno-child' ); ?></p>
					<p class="omc-modal__code"><?php echo esc_html( $code ); ?></p>
					<p class="omc-modal__code-note"><?php esc_html_e( 'Enter it at checkout. It is in your inbox too.', 'moderno-child' ); ?></p>
					<a class="omc-btn omc-btn--solid" href="<?php echo esc_url( omc_shop_url() ); ?>"><?php esc_html_e( 'Start shopping', 'moderno-child' ); ?></a>
				</div>
			<?php endif; ?>
			<p class="omc-modal__small"><?php esc_html_e( 'Unsubscribe from any email, any time.', 'moderno-child' ); ?></p>
		</div>
	</div>
	<?php
}
// Priority 5: footer scripts print at 20, and omc.js binds the newsletter form by querying the DOM.
add_action( 'wp_footer', 'omc_discount_tab', 5 );

/** Newsletter form (progressively enhanced by assets/js/omc.js). */
function omc_newsletter_form( $args = [] ) {
	static $count = 0;
	$count++;
	$a = wp_parse_args( $args, [
		'class'       => '',
		'placeholder' => __( 'Your email address', 'moderno-child' ),
		'button'      => __( 'Join the list', 'moderno-child' ),
		'source'      => 'newsletter', // passed to `omc_newsletter_subscribed` and picks the success message (fb_live = the live card)
		'after'       => '',           // CSS selector of an element to reveal once the sign-up succeeded
	] );
	$field_id = 'omc-newsletter-email' . ( $count > 1 ? '-' . $count : '' ); // several forms can share a page (footer, live card, band)
	?>
	<form class="omc-newsletter__form js-omc-newsletter <?php echo esc_attr( $a['class'] ); ?>" method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" novalidate<?php echo $a['after'] ? ' data-after="' . esc_attr( $a['after'] ) . '"' : ''; ?>>
		<input type="hidden" name="action" value="omc_subscribe">
		<input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( 'omc_newsletter' ) ); ?>">
		<input type="hidden" name="source" value="<?php echo esc_attr( $a['source'] ); ?>">
		<label class="screen-reader-text" for="<?php echo esc_attr( $field_id ); ?>"><?php esc_html_e( 'Email address', 'moderno-child' ); ?></label>
		<input class="omc-newsletter__input" id="<?php echo esc_attr( $field_id ); ?>" type="email" name="email" placeholder="<?php echo esc_attr( $a['placeholder'] ); ?>" required autocomplete="email">
		<button class="omc-btn omc-btn--solid" type="submit"><?php echo esc_html( $a['button'] ); ?></button>
		<p class="omc-newsletter__note" role="status" aria-live="polite"></p>
	</form>
	<?php
}

/**
 * Newsletter sign-up endpoint. Stores addresses in an option and fires
 * `omc_newsletter_subscribed` so a marketing plugin (e.g. WHD) can take over.
 */
function omc_subscribe_ajax() {
	check_ajax_referer( 'omc_newsletter', 'nonce' );
	$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
	if ( ! is_email( $email ) ) {
		wp_send_json_error( [ 'message' => __( 'Please enter a valid email address.', 'moderno-child' ) ], 400 );
	}
	// Keep the "landing:<slug>" / "video:<slug>" shape: sanitize_key() would eat the colon.
	$source = isset( $_POST['source'] )
		? preg_replace( '/[^a-z0-9:_-]/', '', strtolower( sanitize_text_field( wp_unslash( $_POST['source'] ) ) ) )
		: 'newsletter';
	$source = $source ?: 'newsletter';
	$list   = get_option( 'omc_newsletter_subscribers', [] );
	if ( ! isset( $list[ $email ] ) ) {
		$list[ $email ] = current_time( 'mysql' );
		update_option( 'omc_newsletter_subscribers', $list, false );
	}
	do_action( 'omc_newsletter_subscribed', $email, $source );
	$message = 'fb_live' === $source
		? __( 'You’re in. We’ll send you the link before we go live — see you there.', 'moderno-child' )
		: __( 'You’re on the list. Your next “Oops, Mine” moment is coming.', 'moderno-child' );
	wp_send_json_success( [ 'message' => $message, 'source' => $source ] );
}
add_action( 'wp_ajax_omc_subscribe', 'omc_subscribe_ajax' );
add_action( 'wp_ajax_nopriv_omc_subscribe', 'omc_subscribe_ajax' );

/* ────────────────────────────── SEO ────────────────────────────── */

/** True when a dedicated SEO plugin owns the <head>; then we stay out of the way. */
function omc_has_seo_plugin() {
	return defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'AIOSEO_VERSION' )
		|| defined( 'THE_SEO_FRAMEWORK_VERSION' ) || defined( 'SEOPRESS_VERSION' );
}

function omc_default_description() {
	return apply_filters(
		'omc_front_description',
		get_bloginfo( 'description' ) ?: __( 'Oops, Mine Co. is a curated boutique of Korean and Thai fashion — dresses, skirts, denim and accessories chosen with intention, for the moment you see something and think: oops, mine.', 'moderno-child' )
	);
}

/**
 * A per-post search snippet, stored as meta.
 *
 * The landing and video templates already carry their own descriptions inside their JSON documents.
 * This is for everything else — an ordinary page, a product, a journal post — so a description
 * written on the edit screen (by hand, or by the WHD AI panel) reaches the head. The landing filter
 * runs later and still wins on the pages it owns.
 *
 * Both values are optional and absent on almost every post; get_post_meta on a single query is
 * already in the object cache by the time the head is built.
 */
add_filter( 'omc_meta_description', function ( $desc ) {
	if ( ! is_singular() ) {
		return $desc;
	}
	$own = trim( (string) get_post_meta( get_queried_object_id(), '_omc_meta_description', true ) );
	return $own ?: $desc;
}, 5 );

add_filter( 'document_title_parts', function ( $parts ) {
	if ( ! is_singular() ) {
		return $parts;
	}
	$title = trim( (string) get_post_meta( get_queried_object_id(), '_omc_seo_title', true ) );
	if ( ! $title ) {
		return $parts;
	}
	$parts['title'] = $title;

	// A title that already names the shop does not need the suffix saying it again.
	$flat = static function ( $value ) {
		return preg_replace( '/[^a-z0-9]+/', '', strtolower( (string) $value ) );
	};
	$site = $flat( get_bloginfo( 'name' ) );
	if ( $site && false !== strpos( $flat( $title ), $site ) ) {
		unset( $parts['site'], $parts['tagline'] );
	}
	return $parts;
}, 5 );

/** Meta description + Open Graph for the main templates when no SEO plugin is active. */
function omc_seo_head() {
	if ( omc_has_seo_plugin() ) {
		return;
	}
	$desc  = '';
	$image = '';
	$type  = 'website';

	if ( is_front_page() ) {
		$desc  = omc_default_description();
		$image = wp_get_attachment_image_url( omc_attachment_id( omc_images()['hero'] ), 'large' );
	} elseif ( is_singular() ) {
		$post  = get_queried_object();
		$desc  = has_excerpt( $post ) ? get_the_excerpt( $post ) : wp_trim_words( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), 28, '…' );
		$image = get_the_post_thumbnail_url( $post, 'large' );
		$type  = is_singular( 'post' ) ? 'article' : ( is_singular( 'product' ) ? 'product' : 'website' );
		if ( ! $image && omc_is_about_template() ) {
			$image = wp_get_attachment_image_url( omc_attachment_id( omc_images()['about_hero'] ), 'large' );
		}
	} elseif ( is_tax() || is_category() || is_tag() ) {
		$term = get_queried_object();
		$desc = $term && $term->description ? wp_strip_all_tags( $term->description ) : '';
	} elseif ( function_exists( 'is_shop' ) && is_shop() ) {
		$desc = __( 'Shop new arrivals from Oops, Mine Co. — curated Korean and Thai fashion, chosen with intention.', 'moderno-child' );
	}

	/**
	 * Let a page supply its own meta description (landing pages, video pages, …).
	 *
	 * @param string $desc Description derived from the page above.
	 */
	$desc = apply_filters( 'omc_meta_description', $desc );
	$desc = trim( preg_replace( '/\s+/', ' ', (string) $desc ) );
	if ( $desc ) {
		echo '<meta name="description" content="' . esc_attr( wp_html_excerpt( $desc, 158, '…' ) ) . '">' . "\n";
	}
	echo '<meta property="og:site_name" content="' . esc_attr( get_bloginfo( 'name' ) ) . '">' . "\n";
	echo '<meta property="og:type" content="' . esc_attr( $type ) . '">' . "\n";
	echo '<meta property="og:title" content="' . esc_attr( wp_get_document_title() ) . '">' . "\n";
	if ( $desc ) {
		echo '<meta property="og:description" content="' . esc_attr( wp_html_excerpt( $desc, 200, '…' ) ) . '">' . "\n";
	}
	if ( $image ) {
		echo '<meta property="og:image" content="' . esc_url( $image ) . '">' . "\n";
		echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
	}
}
add_action( 'wp_head', 'omc_seo_head', 1 );

/** Organization / WebSite (+SearchAction) / AboutPage structured data. */
function omc_schema() {
	if ( omc_has_seo_plugin() ) {
		return;
	}
	$home  = home_url( '/' );
	$graph = [];

	$org = [
		'@type'       => 'Organization',
		'@id'         => $home . '#organization',
		'name'        => get_bloginfo( 'name' ),
		'url'         => $home,
		'description' => omc_default_description(),
	];
	$org['logo'] = omc_logo( 'rose' )['url'];
	$same_as = apply_filters( 'omc_social_profiles', [] );
	if ( $same_as ) {
		$org['sameAs'] = array_values( $same_as );
	}
	$graph[] = $org;

	if ( is_front_page() ) {
		$graph[] = [
			'@type'           => 'WebSite',
			'@id'             => $home . '#website',
			'url'             => $home,
			'name'            => get_bloginfo( 'name' ),
			'publisher'       => [ '@id' => $home . '#organization' ],
			'potentialAction' => [
				'@type'       => 'SearchAction',
				'target'      => [ '@type' => 'EntryPoint', 'urlTemplate' => $home . '?s={search_term_string}&post_type=product' ],
				'query-input' => 'required name=search_term_string',
			],
		];
	}

	if ( omc_is_about_template() ) {
		$graph[] = [
			'@type'    => 'AboutPage',
			'@id'      => get_permalink() . '#webpage',
			'url'      => get_permalink(),
			'name'     => get_the_title(),
			'about'    => [ '@id' => $home . '#organization' ],
			'isPartOf' => [ '@id' => $home . '#website' ],
		];
	}

	echo '<script type="application/ld+json">' . wp_json_encode( [ '@context' => 'https://schema.org', '@graph' => $graph ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
}
add_action( 'wp_head', 'omc_schema', 5 );
