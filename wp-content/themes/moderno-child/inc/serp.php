<?php
/**
 * How the site looks in a search result.
 *
 * A result is more than the title and the snippet: Google draws the site's name and icon above it
 * (WebSite and Organization), the breadcrumb trail as its address (BreadcrumbList), and under a
 * product its price, whether it is in stock and the shop's returns (Product → Offer →
 * MerchantReturnPolicy). This file fills in what the rest of the site leaves short:
 *
 *   - the return policy, in Google's terms, from the policy page's own facts: store credit, seven
 *     days, by post with an RMA number, a $15 restocking fee, sale pieces final sale — on every
 *     product offer and on the Organization;
 *   - a product's gallery as its images and "new" as its condition;
 *   - names with HTML entities in them, which a result shows as they are ("Jackets &amp;amp;
 *     Outerwear", "&#039;oops, mine&#039;");
 *   - category titles that say what the shop is, and category snippets long enough to use the room;
 *   - the site's other names, so Google settles on "Oops, Mine Co." as the site name.
 *
 * The product snippets themselves are tidied by WHD (WHD_SEO::snippet()), next to the editor's
 * preview of the same result.
 *
 * @package moderno-child
 */

defined( 'ABSPATH' ) || exit;

/**
 * The shop's return policy, as schema.org has it. Everything in it is on the refund and returns
 * page; filter `omc_return_policy` when that page changes.
 *
 * @param bool $final_sale A piece on sale: final sale, no returns.
 * @return array MerchantReturnPolicy node.
 */
function omc_return_policy( $final_sale = false ) {
	$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD';
	$policy   = [
		'@type'              => 'MerchantReturnPolicy',
		// Where the shop ships: the footer's own statement (filter `omc_ships_to`), as country codes.
		'applicableCountry'  => (array) apply_filters( 'omc_return_countries', [ 'US', 'CA' ] ),
		'merchantReturnLink' => omc_page_url( 'refund_returns', '/refund_returns/' ),
	];
	if ( $final_sale ) {
		$policy['returnPolicyCategory'] = 'https://schema.org/MerchantReturnNotPermitted';
	} else {
		$policy += [
			'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
			'merchantReturnDays'   => 7,
			'returnMethod'         => 'https://schema.org/ReturnByMail',
			'refundType'           => 'https://schema.org/StoreCreditRefund',
			'restockingFee'        => [ '@type' => 'MonetaryAmount', 'value' => 15, 'currency' => $currency ],
		];
	}
	return (array) apply_filters( 'omc_return_policy', $policy, $final_sale );
}

/** Text as a person reads it: no tags, no entities — however many times it was escaped. */
function omc_serp_plain( $text ) {
	$text = wp_strip_all_tags( (string) $text );
	for ( $i = 0; $i < 3; $i++ ) {
		$next = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( $next === $text ) {
			break;
		}
		$text = $next;
	}
	return trim( $text );
}

/* The site's own nodes: plain names, the returns, a way to write, the site's other names. */
add_filter( 'omc_schema_graph', static function ( $graph ) {
	foreach ( $graph as $i => $node ) {
		$type = $node['@type'] ?? '';
		foreach ( [ 'name', 'description' ] as $key ) {
			if ( isset( $node[ $key ] ) && is_string( $node[ $key ] ) ) {
				$graph[ $i ][ $key ] = omc_serp_plain( $node[ $key ] );
			}
		}
		if ( 'BreadcrumbList' === $type ) {
			foreach ( (array) ( $node['itemListElement'] ?? [] ) as $j => $crumb ) {
				if ( isset( $crumb['name'] ) ) {
					$graph[ $i ]['itemListElement'][ $j ]['name'] = omc_serp_plain( $crumb['name'] );
				}
			}
		}
		if ( 'Organization' === $type ) {
			$graph[ $i ]['hasMerchantReturnPolicy'] = omc_return_policy();
			$email = sanitize_email( (string) get_option( 'woocommerce_email_from_address', '' ) );
			if ( $email ) {
				$graph[ $i ]['email']        = $email;
				$graph[ $i ]['contactPoint'] = [ '@type' => 'ContactPoint', 'contactType' => 'customer service', 'email' => $email ];
			}
		}
		if ( 'WebSite' === $type ) {
			// The name Google should show above every result, and the ways people write it.
			$graph[ $i ]['alternateName'] = (array) apply_filters( 'omc_site_alternate_names', [ 'Oops Mine Co', 'Oops, Mine', 'oopsmineco.com' ] );
		}
	}
	return $graph;
}, 50 );

/* A product: its gallery, its condition and the returns, on every offer. */
add_filter( 'woocommerce_structured_data_product', static function ( $markup, $product ) {
	if ( ! $product instanceof WC_Product ) {
		return $markup;
	}
	$ids    = array_unique( array_filter( array_merge( [ (int) $product->get_image_id() ], array_map( 'intval', $product->get_gallery_image_ids() ) ) ) );
	$images = [];
	foreach ( array_slice( $ids, 0, 6 ) as $id ) {
		$url = wp_get_attachment_image_url( $id, 'full' );
		if ( $url ) {
			$images[] = $url;
		}
	}
	if ( $images ) {
		$markup['image'] = $images;
	}
	if ( isset( $markup['name'] ) ) {
		$markup['name'] = omc_serp_plain( $markup['name'] );
	}
	if ( isset( $markup['description'] ) ) {
		$markup['description'] = omc_serp_plain( $markup['description'] );
	}
	if ( ! empty( $markup['offers'] ) && is_array( $markup['offers'] ) ) {
		// The policy page: "sale items are final sale".
		$policy = omc_return_policy( $product->is_on_sale() );
		foreach ( $markup['offers'] as $k => $offer ) {
			$markup['offers'][ $k ]['itemCondition']           = 'https://schema.org/NewCondition';
			$markup['offers'][ $k ]['hasMerchantReturnPolicy'] = $policy;
		}
	}
	return $markup;
}, 20, 2 );

/* WooCommerce's breadcrumb trail escapes a category name twice: "Jackets &amp;amp; Outerwear". */
add_filter( 'woocommerce_structured_data_breadcrumblist', static function ( $markup ) {
	foreach ( (array) ( $markup['itemListElement'] ?? [] ) as $i => $crumb ) {
		if ( isset( $crumb['item']['name'] ) ) {
			$markup['itemListElement'][ $i ]['item']['name'] = omc_serp_plain( $crumb['item']['name'] );
		}
	}
	return $markup;
} );

/** Which part of the shop a category belongs to: clothing, skincare, or neither. */
function omc_serp_department( $term ) {
	$slugs = [ $term->slug ];
	foreach ( get_ancestors( $term->term_id, 'product_cat', 'taxonomy' ) as $id ) {
		$parent = get_term( $id, 'product_cat' );
		if ( $parent && ! is_wp_error( $parent ) ) {
			$slugs[] = $parent->slug;
		}
	}
	if ( in_array( 'skincare', $slugs, true ) ) {
		return 'skincare';
	}
	return in_array( 'clothing', $slugs, true ) ? 'clothing' : '';
}

/* A category's title says what the shop is: "Dresses: Korean & Thai Fashion | Oops, Mine Co." */
add_filter( 'document_title_parts', static function ( $parts ) {
	if ( ! function_exists( 'is_product_category' ) || ! is_product_category() ) {
		return $parts;
	}
	$term = get_queried_object();
	$dept = $term ? omc_serp_department( $term ) : '';
	$name = $term ? omc_serp_plain( $term->name ) : '';
	if ( 'clothing' === $dept ) {
		/* translators: %s: a clothing category, e.g. Dresses */
		$parts['title'] = 'clothing' === $term->slug ? __( 'Women’s Clothing: Korean & Thai Fashion', 'moderno-child' ) : sprintf( __( '%s: Korean & Thai Fashion', 'moderno-child' ), $name );
	} elseif ( 'skincare' === $dept ) {
		/* translators: %s: a skincare category, e.g. Toners */
		$parts['title'] = 'skincare' === $term->slug ? __( 'Korean Skincare', 'moderno-child' ) : sprintf( __( '%s: Korean Skincare', 'moderno-child' ), $name );
	}
	return $parts;
}, 20 );

/* A category's snippet: its own words, or — where it has none — a line from what is in it; and the
   room Google gives a snippet used. */
add_filter( 'omc_meta_description', static function ( $desc ) {
	if ( ! function_exists( 'is_product_category' ) || ! is_product_category() ) {
		return $desc;
	}
	$term  = get_queried_object();
	$site  = rtrim( omc_serp_plain( get_bloginfo( 'name' ) ), '.' ); // "Co." ends the sentence once
	$desc  = omc_serp_plain( $desc );
	$empty = [
		'clothing' => __( 'Women’s clothing from Korea and Thailand: dresses, skirts, knits, tops and denim, chosen one piece at a time and bought in small batches.', 'moderno-child' ),
		'skincare' => __( 'Korean skincare: cleansers, toners, serums, masks and daily sun care from Korean skincare houses, bought a few at a time.', 'moderno-child' ),
		'new-now'  => __( 'The newest pieces in the shop, Korean and Thai fashion added in small batches, and the VIP collection held back for the people who ask first.', 'moderno-child' ),
	];
	if ( '' === $desc && $term && isset( $empty[ $term->slug ] ) ) {
		$desc = $empty[ $term->slug ];
	}
	if ( '' !== $desc && $term && mb_strlen( $desc ) < 115 ) {
		/* translators: 1: category name, 2: shop name */
		$more = sprintf( __( 'Shop %1$s at %2$s.', 'moderno-child' ), mb_strtolower( omc_serp_plain( $term->name ) ), $site );
		if ( mb_strlen( $desc . ' ' . $more ) <= 158 ) {
			$desc .= ' ' . $more;
		}
	}
	return $desc;
}, 50 );

/* A paged archive's snippet says which page it is, so page 2 and page 5 of a category are not one snippet five times. */
add_filter( 'omc_meta_description', static function ( $desc ) {
	$paged = max( (int) get_query_var( 'paged' ), (int) get_query_var( 'page' ) );
	if ( '' === trim( (string) $desc ) || $paged < 2 || is_singular() ) {
		return $desc;
	}
	/* translators: %d: page number */
	return rtrim( (string) $desc ) . ' ' . sprintf( __( 'Page %d.', 'moderno-child' ), $paged );
}, 60 );

/*
 * A title too long for a result loses the shop's name to "…" anyway, and Google prints the site's
 * name above every result on its own (WebSite schema). Past 60 characters the suffix goes, so the
 * words that describe the page are the ones that show.
 */
add_filter( 'document_title_parts', static function ( $parts ) {
	if ( empty( $parts['site'] ) || empty( $parts['title'] ) ) {
		return $parts;
	}
	$sep  = ' ' . apply_filters( 'document_title_separator', '-' ) . ' ';
	$full = omc_serp_plain( implode( $sep, array_filter( [ $parts['title'], $parts['page'] ?? '', $parts['site'] ] ) ) );
	if ( mb_strlen( $full ) > (int) apply_filters( 'omc_title_room', 60 ) ) {
		unset( $parts['site'], $parts['tagline'] );
	}
	return $parts;
}, 99 );

/*
 * One address per category. WooCommerce also answers a sub-category at its short address
 * (/product-category/dresses/ for /product-category/clothing/dresses/) with the same page, which
 * is a duplicate however good its canonical tag is — and old links in posts use the short one.
 */
add_action( 'template_redirect', static function () {
	if ( ! function_exists( 'is_product_category' ) || ! is_product_category() || is_paged() ) {
		return;
	}
	$want = get_term_link( get_queried_object() );
	$path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- only compared
	if ( is_wp_error( $want ) || '' === $path ) {
		return;
	}
	if ( untrailingslashit( $path ) !== untrailingslashit( (string) wp_parse_url( $want, PHP_URL_PATH ) ) ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the filters and sort carried over as they were
		wp_safe_redirect( $_GET ? add_query_arg( map_deep( wp_unslash( $_GET ), 'sanitize_text_field' ), $want ) : $want, 301 );
		exit;
	}
} );

/*
 * Pages with nothing for a search result: the wishlist, order tracking, a product attribute's
 * archive (/size/m-8-10/ — a list of pieces with that size, no words of its own) and the default
 * "Uncategorized" category. Followed, not indexed. WooCommerce already does the bag, the checkout
 * and the account pages.
 */
/** Pages that are published but are not for search: the theme demo's own leftovers. Filter `omc_serp_hidden_slugs`. */
function omc_serp_hidden_slugs(): array {
	return (array) apply_filters( 'omc_serp_hidden_slugs', [ 'maintenance-mode' ] );
}

function omc_serp_noindex(): bool {
	if ( is_singular( 'page' ) ) {
		$id      = get_queried_object_id();
		$content = (string) get_post_field( 'post_content', $id );
		$wish    = function_exists( 'ideapark_mod' ) ? (int) ideapark_mod( 'wishlist_page' ) : 0;
		return ( $wish && $wish === $id ) || has_shortcode( $content, 'woocommerce_order_tracking' )
			|| in_array( get_post_field( 'post_name', $id ), omc_serp_hidden_slugs(), true );
	}
	if ( is_tax() && function_exists( 'taxonomy_is_product_attribute' ) && taxonomy_is_product_attribute( (string) get_queried_object()->taxonomy ) ) {
		return true;
	}
	return is_category( (int) get_option( 'default_category' ) );
}

add_filter( 'wp_robots', static function ( $robots ) {
	if ( omc_serp_noindex() ) {
		$robots['noindex'] = true;
		if ( empty( $robots['nofollow'] ) ) {
			$robots['follow'] = true;
		}
	}
	return $robots;
}, 20 );

/* A product's sizes and colours as words, not links to those thin archive pages. */
add_filter( 'woocommerce_attribute', static function ( $text, $attribute ) {
	if ( $attribute instanceof WC_Product_Attribute && $attribute->is_taxonomy() ) {
		$text = preg_replace( '#</?a\b[^>]*>#i', '', (string) $text );
	}
	return $text;
}, 20, 2 );

/* The sidebar's category list without "Uncategorized": it is where a post lands that nobody filed. */
add_filter( 'widget_categories_args', static function ( $args ) {
	$args['exclude'] = trim( ( $args['exclude'] ?? '' ) . ',' . (int) get_option( 'default_category' ), ',' );
	return $args;
} );

/* "Page 1" is the first page's own address, not /page/1/ redirecting to it. */
add_filter( 'paginate_links', static function ( $link ) {
	return preg_replace( '#/page/1/?(?=$|\?|\#)#', '/', (string) $link );
} );

/*
 * The sitemap WordPress makes once the site is open to search engines lists only what should be
 * found: no author pages, no attribute archives, no "Uncategorized", none of the pages above.
 */
add_filter( 'wp_sitemaps_add_provider', static function ( $provider, $name ) {
	return 'users' === $name ? false : $provider;
}, 10, 2 );
/* The theme's HTML blocks (header bar, footer, mega menu…) are parts of pages: their own addresses are 404s. */
add_filter( 'wp_sitemaps_post_types', static function ( $types ) {
	foreach ( array_keys( $types ) as $type ) {
		$object = get_post_type_object( $type );
		if ( 'html_block' === $type || ! $object || ! $object->publicly_queryable && 'page' !== $type ) {
			unset( $types[ $type ] );
		}
	}
	return $types;
} );
add_filter( 'wp_sitemaps_taxonomies', static function ( $taxonomies ) {
	foreach ( array_keys( $taxonomies ) as $tax ) {
		if ( function_exists( 'taxonomy_is_product_attribute' ) && taxonomy_is_product_attribute( $tax ) ) {
			unset( $taxonomies[ $tax ] );
		}
	}
	return $taxonomies;
} );
add_filter( 'wp_sitemaps_taxonomies_query_args', static function ( $args, $taxonomy ) {
	if ( 'category' === $taxonomy ) {
		$args['exclude'] = array_merge( (array) ( $args['exclude'] ?? [] ), [ (int) get_option( 'default_category' ) ] );
	}
	return $args;
}, 10, 2 );
add_filter( 'wp_sitemaps_posts_query_args', static function ( $args, $post_type ) {
	if ( 'page' !== $post_type || ! function_exists( 'wc_get_page_id' ) ) {
		return $args;
	}
	$skip = [ wc_get_page_id( 'cart' ), wc_get_page_id( 'checkout' ), wc_get_page_id( 'myaccount' ) ];
	if ( function_exists( 'ideapark_mod' ) ) {
		$skip[] = (int) ideapark_mod( 'wishlist_page' );
	}
	foreach ( array_merge( [ 'order-tracking' ], omc_serp_hidden_slugs() ) as $slug ) {
		$page = get_page_by_path( $slug );
		if ( $page ) {
			$skip[] = $page->ID;
		}
	}
	$args['post__not_in'] = array_values( array_filter( array_merge( (array) ( $args['post__not_in'] ?? [] ), $skip ), static fn( $id ) => $id > 0 ) );
	return $args;
}, 10, 2 );

/* The editor's preview of a product's result (WHD → the SEO box) names the same returns. */
add_filter( 'whd_seo_preview_returns', static function ( $text, $product ) {
	if ( ! $product instanceof WC_Product ) {
		return $text;
	}
	return $product->is_on_sale() ? __( 'Final sale', 'moderno-child' ) : __( '7-day returns', 'moderno-child' );
}, 10, 2 );

/*
 * WooCommerce prints its structured data HTML-escaped (wc_esc_json), and a script element is never
 * unescaped, so every "&" reached Google as "&amp;": "Jackets &amp; Outerwear" in a result's
 * breadcrumb. The same data printed with JSON's own escapes instead — "&" travels as \u0026, which
 * every JSON reader turns back into "&", and < > as \u003C \u003E, so nothing can close the tag.
 */
add_action( 'wp', static function () {
	if ( ! function_exists( 'WC' ) || ! WC()->structured_data ) {
		return;
	}
	$sd = WC()->structured_data;
	if ( ! remove_action( 'wp_footer', [ $sd, 'output_structured_data' ], 10 ) ) {
		return;
	}
	add_action( 'wp_footer', static function () use ( $sd ) {
		// WooCommerce's own choice of types for the page (WC_Structured_Data::get_data_type_for_page()).
		$types = array_filter( (array) apply_filters( 'woocommerce_structured_data_type_for_page', [
			is_shop() || is_product_category() || is_product() ? 'product' : '',
			is_shop() && is_front_page() ? 'website' : '',
			is_product() ? 'review' : '',
			'breadcrumblist',
			'order',
		] ) );
		$data = $sd->get_structured_data( $types );
		if ( $data ) {
			echo '<script type="application/ld+json">' . wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON with its own escapes
		}
	}, 10 );
} );
