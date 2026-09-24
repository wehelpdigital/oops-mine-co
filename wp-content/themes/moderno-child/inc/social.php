<?php
/**
 * Oops, Mine Co. — social proof.
 *
 * Three pieces, all opt-in and all driven by the Customizer / WooCommerce data:
 *
 * 1. "From our feed" — the home page video + UGC band (Customizer → "Social feed").
 *    Each saved URL is embedded with wp_oembed_get(); when the provider has no
 *    embed for us (Instagram and Facebook were dropped from WordPress core in
 *    5.5.2 and now need an app token) the item falls back to an on-brand link
 *    card instead of an empty box. Embeds are injected by assets/js/omc-social.js
 *    once the card scrolls close to the viewport, so the page stays light.
 * 2. Product Open Graph / Pinterest rich-pin meta (only when no SEO plugin runs).
 * 3. A "Save to Pinterest" link next to the theme's wishlist / share buttons.
 *
 * Wiring (functions.php — not owned by this file): add this file to the module
 * loader near the top,
 *     foreach ( [ 'video', 'social' ] as $omc_module ) {
 * and add TikTok to omc_social_links() so the header icons can show it. The
 * stylesheet and script enqueue themselves below; nothing else is needed.
 * templates/page-home.php calls omc_feed_section() between the reviews band and
 * the newsletter.
 *
 * @package moderno-child
 */

defined( 'ABSPATH' ) || exit;

/* ───────────────────────── Customizer: Social feed ───────────────────────── */

/**
 * Customizer → "Social feed": the home page "From our feed" band.
 */
function omc_feed_customize_register( WP_Customize_Manager $wp_customize ) {
	$wp_customize->add_section( 'omc_feed', [
		'title'       => __( 'Social feed', 'moderno-child' ),
		'priority'    => 36,
		'description' => __( 'The "From our feed" band on the home page: short videos plus the hashtag call-out. Paste one video URL per line (Instagram Reel, TikTok or Facebook video). Leave everything empty to hide the band.', 'moderno-child' ),
	] );

	$fields = [
		'omc_feed_enabled' => [
			'label'    => __( 'Show the social feed band', 'moderno-child' ),
			'type'     => 'checkbox',
			'default'  => true,
			'sanitize' => 'rest_sanitize_boolean',
		],
		'omc_feed_heading' => [
			'label'       => __( 'Heading', 'moderno-child' ),
			'type'        => 'text',
			'default'     => '',
			'sanitize'    => 'omc_feed_sanitize_heading',
			'description' => __( 'Wrap one word in &lt;em&gt; for the italic accent. Empty = "From our feed".', 'moderno-child' ),
		],
		'omc_feed_urls'    => [
			'label'       => __( 'Video URLs, one per line', 'moderno-child' ),
			'type'        => 'textarea',
			'default'     => '',
			'sanitize'    => 'omc_feed_sanitize_urls',
			'description' => __( 'Up to six are shown. TikTok links embed the video; Instagram and Facebook links show a card that opens the post.', 'moderno-child' ),
		],
		'omc_feed_hashtag' => [
			'label'       => __( 'Hashtag', 'moderno-child' ),
			'type'        => 'text',
			'default'     => '#oopsmine',
			'sanitize'    => 'omc_feed_sanitize_hashtag',
			'description' => __( 'Shown in the call-out under the videos. Empty = no call-out.', 'moderno-child' ),
		],
	];

	foreach ( $fields as $id => $f ) {
		$wp_customize->add_setting( $id, [
			'default'           => $f['default'],
			'sanitize_callback' => $f['sanitize'],
			'transport'         => 'refresh',
		] );
		$wp_customize->add_control( $id, [
			'section'     => 'omc_feed',
			'label'       => $f['label'],
			'type'        => $f['type'],
			'description' => $f['description'] ?? '',
		] );
	}
}
add_action( 'customize_register', 'omc_feed_customize_register' );

/** Heading: plain text plus a single <em> accent. */
function omc_feed_sanitize_heading( $value ) {
	return trim( wp_kses( (string) $value, [ 'em' => [] ] ) );
}

/** Hashtag: word characters only, always printed with one leading #. */
function omc_feed_sanitize_hashtag( $value ) {
	$tag = preg_replace( '/[^0-9A-Za-z_]/u', '', (string) $value );
	return $tag ? '#' . $tag : '';
}

/**
 * One line of the textarea → a usable http(s) URL, or '' when the line is not one.
 * esc_url_raw() alone would turn "my reel" into "http://my%20reel", so the host
 * has to look like a host as well.
 */
function omc_feed_clean_url( $line ) {
	$url  = esc_url_raw( trim( (string) $line ), [ 'http', 'https' ] );
	$host = $url ? (string) wp_parse_url( $url, PHP_URL_HOST ) : '';
	if ( ! $url || ! $host || false === strpos( $host, '.' ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
		return '';
	}
	return $url;
}

/** Textarea of URLs: one http(s) URL per line, de-duplicated, junk lines dropped. */
function omc_feed_sanitize_urls( $value ) {
	$out = [];
	foreach ( preg_split( '/\r\n|\r|\n/', (string) $value ) as $line ) {
		$url = omc_feed_clean_url( $line );
		if ( $url && ! in_array( $url, $out, true ) ) {
			$out[] = $url;
		}
	}
	return implode( "\n", $out );
}

/* ─────────────────────────────── Feed data ─────────────────────────────── */

/** How many videos the grid shows at most. */
function omc_feed_max() {
	return max( 1, (int) apply_filters( 'omc_feed_max', 6 ) );
}

function omc_feed_enabled() {
	return (bool) apply_filters( 'omc_feed_enabled', rest_sanitize_boolean( get_theme_mod( 'omc_feed_enabled', true ) ) );
}

/** The saved video URLs, capped at omc_feed_max(). */
function omc_feed_urls() {
	$urls = preg_split( '/\r\n|\r|\n/', (string) get_theme_mod( 'omc_feed_urls', '' ) );
	$urls = array_values( array_unique( array_filter( array_map( 'omc_feed_clean_url', $urls ) ) ) );

	return array_slice( (array) apply_filters( 'omc_feed_urls', $urls ), 0, omc_feed_max() );
}

function omc_feed_hashtag() {
	return (string) apply_filters( 'omc_feed_hashtag', omc_feed_sanitize_hashtag( get_theme_mod( 'omc_feed_hashtag', '#oopsmine' ) ) );
}

function omc_feed_heading() {
	$heading = omc_feed_sanitize_heading( get_theme_mod( 'omc_feed_heading', '' ) );
	return $heading ?: __( 'From our <em>feed</em>', 'moderno-child' );
}

/**
 * Profile links for the hashtag call-out: Instagram first, then TikTok, from the
 * parent's Customizer → Social Media Links. "#" and empty values are dropped, so
 * the button only appears once a real profile URL is saved.
 *
 * @return array<string,array{label:string,url:string}>
 */
function omc_feed_profiles() {
	$profiles = [];
	foreach ( [ 'instagram' => 'Instagram', 'tiktok' => 'TikTok' ] as $key => $label ) {
		$url = function_exists( 'ideapark_mod' ) ? trim( (string) ideapark_mod( $key ) ) : '';
		if ( '' === $url || '#' === $url ) {
			continue;
		}
		$url = esc_url_raw( $url, [ 'http', 'https' ] );
		if ( $url ) {
			$profiles[ $key ] = [ 'label' => $label, 'url' => $url ];
		}
	}
	return (array) apply_filters( 'omc_feed_profiles', $profiles );
}

/** True when the band has something to show (videos, or a profile to follow). */
function omc_feed_has_content() {
	return omc_feed_enabled() && ( omc_feed_urls() || omc_feed_profiles() );
}

/* ────────────────────────── Platform + embed helpers ────────────────────────── */

/**
 * Which network a URL belongs to.
 *
 * @return array{key:string,label:string}
 */
function omc_feed_platform( $url ) {
	$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	$map  = [
		'tiktok'    => [ 'tiktok.com' ],
		'instagram' => [ 'instagram.com', 'instagr.am' ],
		'facebook'  => [ 'facebook.com', 'fb.watch', 'fb.com' ],
		'youtube'   => [ 'youtube.com', 'youtu.be' ],
	];
	$labels = [
		'tiktok'    => 'TikTok',
		'instagram' => 'Instagram',
		'facebook'  => 'Facebook',
		'youtube'   => 'YouTube',
	];
	foreach ( $map as $key => $hosts ) {
		foreach ( $hosts as $needle ) {
			if ( $host === $needle || substr( $host, - strlen( '.' . $needle ) ) === '.' . $needle ) {
				return [ 'key' => $key, 'label' => $labels[ $key ] ];
			}
		}
	}
	return [ 'key' => 'video', 'label' => __( 'video', 'moderno-child' ) ];
}

/**
 * Line icon for a network. Falls back to the child's omc_icon() for the networks
 * it already draws; TikTok and the generic play mark live here.
 */
function omc_feed_icon( $key ) {
	$paths = [
		'tiktok' => '<path fill="currentColor" stroke="none" d="M16.1 3h-2.6v11.4a2.3 2.3 0 1 1-2-2.3V9.4a5 5 0 1 0 4.6 5V8.6a6 6 0 0 0 3.4 1.1V7.1a3.6 3.6 0 0 1-3.4-3.6z"/>',
		'video'  => '<circle cx="12" cy="12" r="9"/><path d="M10.4 8.8l4.6 3.2-4.6 3.2z"/>',
	];
	if ( isset( $paths[ $key ] ) ) {
		return '<svg class="omc-icon omc-icon--' . esc_attr( $key ) . '" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths[ $key ] . '</svg>';
	}
	return function_exists( 'omc_icon' ) ? omc_icon( $key ) : '';
}

/**
 * The provider's embed markup for a URL, cached.
 *
 * wp_oembed_get() only answers for providers WordPress still ships (TikTok and
 * YouTube among them); Instagram and Facebook return nothing without an app
 * token, and that empty answer is cached too so no visitor waits on a lookup
 * that will not succeed. Filter `omc_feed_embed_html` to plug in your own
 * markup for a URL (see the README note in the section docblock).
 *
 * A page render asks the providers twice at most (`omc_feed_lookup_budget`);
 * anything still uncold keeps its link card and is fetched on a later request,
 * so a slow provider can never hold up the home page.
 *
 * @param string $url            Video URL.
 * @param bool   $ignore_budget  True when the caller may wait (cache warming).
 * @return string Embed HTML, or '' when the URL cannot be embedded.
 */
function omc_feed_embed( $url, $ignore_budget = false ) {
	static $budget = null;

	$custom = apply_filters( 'omc_feed_embed_html', null, $url );
	if ( null !== $custom ) {
		return (string) $custom;
	}

	$key    = 'omc_feed_' . md5( $url );
	$cached = get_transient( $key );
	if ( false !== $cached ) {
		return '0' === $cached ? '' : (string) $cached;
	}

	if ( null === $budget ) {
		$budget = max( 0, (int) apply_filters( 'omc_feed_lookup_budget', 2 ) );
	}
	if ( ! $ignore_budget ) {
		if ( $budget < 1 ) {
			return '';
		}
		$budget--;
	}

	$cap = static function ( $args ) {
		$args['timeout'] = 3; // a slow provider must never hold a page render open
		return $args;
	};
	add_filter( 'oembed_remote_get_args', $cap );
	$html = wp_oembed_get( $url, [ 'width' => 480 ] );
	remove_filter( 'oembed_remote_get_args', $cap );

	$html = is_string( $html ) ? trim( $html ) : '';
	set_transient( $key, $html ?: '0', $html ? 12 * HOUR_IN_SECONDS : 6 * HOUR_IN_SECONDS );

	return $html;
}

/**
 * Warm the embed cache after the URLs are saved.
 *
 * The lookups run in the cron request WordPress spawns in the background, not in
 * the Customizer save (six providers × 3 s would make the editor sit and wait)
 * and not in a visitor's page render. With cron switched off nothing breaks: the
 * band keeps showing link cards and fills itself in over the next few visits,
 * two lookups at a time.
 */
function omc_feed_prime_embeds() {
	if ( ! wp_next_scheduled( 'omc_feed_prime' ) ) {
		wp_schedule_single_event( time() + 10, 'omc_feed_prime' );
	}
}
add_action( 'customize_save_after', 'omc_feed_prime_embeds' );

function omc_feed_prime_embeds_now() {
	foreach ( omc_feed_urls() as $url ) {
		omc_feed_embed( $url, true );
	}
}
add_action( 'omc_feed_prime', 'omc_feed_prime_embeds_now' );

/** Readable form of a URL for the link card: no scheme, no query string, trimmed. */
function omc_feed_pretty_url( $url ) {
	$short = preg_replace( '#^https?://(www\.)?#i', '', (string) $url );
	$short = untrailingslashit( strtok( $short, '?' ) );
	return strlen( $short ) > 46 ? substr( $short, 0, 45 ) . '…' : $short;
}

/* ───────────────────────────── Feed rendering ───────────────────────────── */

/**
 * One grid item: the link card, plus the provider embed in a <template> when we
 * have one (assets/js/omc-social.js swaps it in when the card comes into view).
 */
function omc_feed_card( $url ) {
	$platform = omc_feed_platform( $url );
	$embed    = omc_feed_embed( $url );
	$label    = 'video' === $platform['key']
		? __( 'Watch the video', 'moderno-child' )
		: sprintf( __( 'Watch on %s', 'moderno-child' ), $platform['label'] );
	?>
	<li class="omc-feed__item">
		<div class="omc-feed__card<?php echo $embed ? ' js-omc-embed' : ''; ?>" data-omc-title="<?php echo esc_attr( $label ); ?>">
			<?php if ( $embed ) : ?>
				<?php /* Provider markup (TikTok, YouTube…) — printed as it came back from the oEmbed endpoint, like WordPress does in post content. */ ?>
				<template class="omc-feed__source"><?php echo $embed; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></template>
			<?php endif; ?>
			<a class="omc-feed__link" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener nofollow">
				<span class="omc-feed__badge"><?php echo omc_feed_icon( $platform['key'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<span class="omc-feed__watch"><?php echo esc_html( $label ); ?></span>
				<span class="omc-feed__url"><?php echo esc_html( omc_feed_pretty_url( $url ) ); ?></span>
				<span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'moderno-child' ); ?></span>
			</a>
		</div>
	</li>
	<?php
}

/**
 * The "From our feed" band. Prints nothing when the band is switched off, or
 * when there is neither a video nor a profile to point at.
 *
 * Called from templates/page-home.php between the reviews band and the newsletter.
 */
function omc_feed_section() {
	if ( ! omc_feed_has_content() ) {
		return;
	}
	$urls     = omc_feed_urls();
	$profiles = omc_feed_profiles();
	$hashtag  = omc_feed_hashtag();
	$profile  = $profiles ? reset( $profiles ) : null;
	?>
	<section class="omc-section omc-feed omc-reveal" aria-label="<?php esc_attr_e( 'From our feed', 'moderno-child' ); ?>">
		<div class="l-section__container-wide">
			<?php
			if ( function_exists( 'omc_section_head' ) ) {
				omc_section_head( __( 'In motion', 'moderno-child' ), omc_feed_heading() );
			}
			?>
			<?php if ( $urls ) : ?>
				<ul class="omc-feed__grid">
					<?php
					foreach ( $urls as $url ) {
						omc_feed_card( $url );
					}
					?>
				</ul>
			<?php endif; ?>

			<?php if ( $hashtag || $profile ) : ?>
				<div class="omc-feed__ugc">
					<p class="omc-feed__ugc-text">
						<?php if ( $hashtag ) : ?>
							<?php
							printf(
								/* translators: %s: the brand hashtag, e.g. #oopsmine */
								esc_html__( 'Wearing something from us? Tag %s — we feature our favourites here.', 'moderno-child' ),
								'<span class="omc-feed__tag">' . esc_html( $hashtag ) . '</span>'
							);
							?>
						<?php else : ?>
							<?php esc_html_e( 'Wearing something from us? Show us — we feature our favourites here.', 'moderno-child' ); ?>
						<?php endif; ?>
					</p>
					<?php if ( $profile ) : ?>
						<a class="omc-btn omc-btn--outline omc-feed__follow" href="<?php echo esc_url( $profile['url'] ); ?>" target="_blank" rel="noopener">
							<?php echo omc_feed_icon( strtolower( $profile['label'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php
							printf(
								/* translators: %s: social network name */
								esc_html__( 'Follow on %s', 'moderno-child' ),
								esc_html( $profile['label'] )
							);
							?>
						</a>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
	</section>
	<?php
}

/* ──────────────────────────────── Assets ──────────────────────────────── */

/**
 * The feed band needs both files, the product page only the stylesheet
 * (the Pinterest link). Hooked after the child's own enqueue at 1000 so the
 * `omc` handle is registered.
 */
function omc_social_assets() {
	$dir = defined( 'OMC_DIR' ) ? OMC_DIR : get_stylesheet_directory();
	$uri = defined( 'OMC_URI' ) ? OMC_URI : get_stylesheet_directory_uri();
	$ver = static function ( $rel ) use ( $dir ) {
		return file_exists( $dir . $rel ) ? (string) filemtime( $dir . $rel ) : ( defined( 'OMC_VERSION' ) ? OMC_VERSION : '1.0.0' );
	};

	$feed    = function_exists( 'omc_is_home_template' ) && omc_is_home_template() && omc_feed_has_content();
	$product = function_exists( 'is_product' ) && is_product();
	if ( ! $feed && ! $product ) {
		return;
	}

	wp_enqueue_style( 'omc-social', $uri . '/assets/css/omc-social.css', [ 'omc' ], $ver( '/assets/css/omc-social.css' ) );
	if ( $feed && omc_feed_urls() ) { // the script only swaps embeds in; a call-out-only band needs no JS
		wp_enqueue_script( 'omc-social', $uri . '/assets/js/omc-social.js', [], $ver( '/assets/js/omc-social.js' ), true );
	}
}
add_action( 'wp_enqueue_scripts', 'omc_social_assets', 1001 );

/* ───────────── Product Open Graph / Pinterest rich pins ───────────── */

/**
 * True when this file prints the whole Open Graph block for a product.
 *
 * functions.php prints generic tags (og:title, og:description, og:image,
 * twitter:card) at wp_head priority 1 for every singular view. On a product we
 * can do better — the short description, a 1200px image, price and stock — so
 * the generic pass is dropped and everything is printed once, here.
 * Return false from `omc_social_replace_seo_head` to keep the generic pass; then
 * only the tags it does not print (og:url, product:*, og:brand) are added.
 */
function omc_social_owns_product_head() {
	static $owns = null;
	if ( null === $owns ) {
		$owns = is_singular( 'product' )
			&& function_exists( 'omc_has_seo_plugin' ) && ! omc_has_seo_plugin()
			&& function_exists( 'omc_seo_head' )
			&& (bool) apply_filters( 'omc_social_replace_seo_head', true );
	}
	return $owns;
}

function omc_social_product_head_takeover() {
	if ( omc_social_owns_product_head() ) {
		remove_action( 'wp_head', 'omc_seo_head', 1 );
	}
}
add_action( 'template_redirect', 'omc_social_product_head_takeover' );

/** Plain-text product description for meta tags. */
function omc_social_product_description( $product ) {
	$text = $product->get_short_description();
	if ( ! $text ) {
		$post = get_post( $product->get_id() );
		$text = $post ? ( $post->post_excerpt ?: $post->post_content ) : '';
	}
	$text = wp_strip_all_tags( strip_shortcodes( (string) $text ) );
	return trim( preg_replace( '/\s+/', ' ', $text ) );
}

/** Main product image as [ url, width, height ], asking for ~1200px. */
function omc_social_product_image( $product ) {
	$id = (int) $product->get_image_id();
	if ( ! $id ) {
		$gallery = $product->get_gallery_image_ids();
		$id      = $gallery ? (int) $gallery[0] : 0;
	}
	if ( ! $id ) {
		return [];
	}
	$src = wp_get_attachment_image_src( $id, [ 1200, 1200 ] );
	return $src ? [ 'url' => $src[0], 'width' => (int) $src[1], 'height' => (int) $src[2] ] : [];
}

/** Brand name from the theme's brand attribute, WooCommerce's brand taxonomy or pa_brand. */
function omc_social_product_brand( $product ) {
	$taxonomies = [];
	$mod        = function_exists( 'ideapark_mod' ) ? trim( (string) ideapark_mod( 'product_brand_attribute' ) ) : '';
	if ( $mod ) {
		$taxonomies[] = 0 === strpos( $mod, 'pa_' ) ? $mod : 'pa_' . $mod;
	}
	$taxonomies[] = 'pa_brand';
	$taxonomies[] = 'product_brand';

	foreach ( array_unique( $taxonomies ) as $taxonomy ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			continue;
		}
		$terms = get_the_terms( $product->get_id(), $taxonomy );
		if ( $terms && ! is_wp_error( $terms ) ) {
			return $terms[0]->name;
		}
	}
	return '';
}

/** The price shown to shoppers (respects the store's tax display setting). */
function omc_social_product_price( $product ) {
	$price = $product->get_price();
	if ( '' === $price || null === $price ) {
		return '';
	}
	if ( function_exists( 'wc_get_price_to_display' ) ) {
		$price = wc_get_price_to_display( $product, [ 'price' => $price ] );
	}
	return wc_format_decimal( $price, function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2 );
}

/**
 * Open Graph product tags: what Pinterest reads for a rich pin, and what
 * Facebook / Messenger show when a product link is shared.
 */
function omc_social_product_meta() {
	if ( ! is_singular( 'product' ) || ! function_exists( 'wc_get_product' ) ) {
		return;
	}
	if ( function_exists( 'omc_has_seo_plugin' ) && omc_has_seo_plugin() ) {
		return;
	}
	$product = wc_get_product( get_queried_object_id() );
	if ( ! $product ) {
		return;
	}

	// When the generic pass is still hooked it already printed og:type/title/description/image at priority 1.
	$full  = false === has_action( 'wp_head', 'omc_seo_head' );
	$desc  = omc_social_product_description( $product );
	$image = omc_social_product_image( $product );
	$out   = '';

	if ( $full ) {
		if ( $desc ) {
			$out .= '<meta name="description" content="' . esc_attr( wp_html_excerpt( $desc, 158, '…' ) ) . '">' . "\n";
		}
		$out .= '<meta property="og:site_name" content="' . esc_attr( get_bloginfo( 'name' ) ) . '">' . "\n";
		$out .= '<meta property="og:type" content="product">' . "\n";
		$out .= '<meta property="og:title" content="' . esc_attr( get_the_title( $product->get_id() ) ) . '">' . "\n";
		if ( $desc ) {
			$out .= '<meta property="og:description" content="' . esc_attr( wp_html_excerpt( $desc, 200, '…' ) ) . '">' . "\n";
		}
		if ( $image ) {
			$out .= '<meta property="og:image" content="' . esc_url( $image['url'] ) . '">' . "\n";
			if ( $image['width'] && $image['height'] ) {
				$out .= '<meta property="og:image:width" content="' . (int) $image['width'] . '">' . "\n";
				$out .= '<meta property="og:image:height" content="' . (int) $image['height'] . '">' . "\n";
			}
			$out .= '<meta property="og:image:alt" content="' . esc_attr( get_the_title( $product->get_id() ) ) . '">' . "\n";
		}
		$out .= '<meta name="twitter:card" content="summary_large_image">' . "\n";
	}

	$out .= '<meta property="og:url" content="' . esc_url( get_permalink( $product->get_id() ) ) . '">' . "\n";

	$price = omc_social_product_price( $product );
	if ( '' !== $price ) {
		$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '';
		$out     .= '<meta property="product:price:amount" content="' . esc_attr( $price ) . '">' . "\n";
		$out     .= '<meta property="og:price:amount" content="' . esc_attr( $price ) . '">' . "\n"; // Pinterest reads the og: pair
		if ( $currency ) {
			$out .= '<meta property="product:price:currency" content="' . esc_attr( $currency ) . '">' . "\n";
			$out .= '<meta property="og:price:currency" content="' . esc_attr( $currency ) . '">' . "\n";
		}
	}

	$availability = $product->is_in_stock() ? 'instock' : 'out of stock';
	$out         .= '<meta property="product:availability" content="' . esc_attr( $availability ) . '">' . "\n";
	$out         .= '<meta property="og:availability" content="' . esc_attr( $product->is_in_stock() ? 'in stock' : 'out of stock' ) . '">' . "\n";

	$brand = omc_social_product_brand( $product );
	if ( $brand ) {
		$out .= '<meta property="og:brand" content="' . esc_attr( $brand ) . '">' . "\n";
		$out .= '<meta property="product:brand" content="' . esc_attr( $brand ) . '">' . "\n";
	}

	echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — every value escaped above.
}
add_action( 'wp_head', 'omc_social_product_meta', 6 );

/* ─────────────────────── "Save to Pinterest" on the product ─────────────────────── */

/**
 * A plain Pinterest save link — no external script, no tracking. It lands inside
 * the theme's wishlist / share row (`woocommerce_share` fires there through
 * ideapark_product_wishlist_share) and falls back to the end of the product
 * summary on layouts that drop that row.
 */
function omc_social_pin_button() {
	static $printed = false;

	if ( $printed || ! is_singular( 'product' ) ) {
		return;
	}
	if ( defined( 'IDEAPARK_IS_AJAX_QUICKVIEW' ) && IDEAPARK_IS_AJAX_QUICKVIEW ) {
		return; // quick view has no room for it, and this stylesheet is not loaded there
	}
	if ( ! function_exists( 'wc_get_product' ) || ! apply_filters( 'omc_social_pin_button', true ) ) {
		return;
	}
	$product = wc_get_product( get_queried_object_id() );
	if ( ! $product ) {
		return;
	}
	$printed = true;

	$image = omc_social_product_image( $product );
	$title = get_the_title( $product->get_id() );
	$desc  = omc_social_product_description( $product );
	$desc  = $desc ? $title . ' — ' . $desc : $title;

	$href = add_query_arg(
		[
			'url'         => rawurlencode( get_permalink( $product->get_id() ) ),
			'media'       => rawurlencode( $image['url'] ?? '' ),
			'description' => rawurlencode( wp_html_excerpt( $desc, 480, '…' ) ),
		],
		'https://www.pinterest.com/pin/create/button/'
	);
	?>
	<a class="omc-pin" href="<?php echo esc_url( $href ); ?>" target="_blank" rel="noopener nofollow" data-pin-do="none" title="<?php esc_attr_e( 'Save this piece to a Pinterest board', 'moderno-child' ); ?>">
		<?php echo omc_feed_icon( 'pinterest' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<span class="omc-pin__text"><?php esc_html_e( 'Save to Pinterest', 'moderno-child' ); ?></span>
	</a>
	<?php
}
add_action( 'woocommerce_share', 'omc_social_pin_button', 20 );
add_action( 'woocommerce_single_product_summary', 'omc_social_pin_button', 55 );
