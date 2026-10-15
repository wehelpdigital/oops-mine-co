<?php
/**
 * The structured data the rest of the site was missing.
 *
 * functions.php emits Organization everywhere, WebSite on the front page and AboutPage on the
 * About template. Everything else — journal posts, the journal index, ordinary pages, the shop
 * and its category archives — shipped with Organization alone, which tells a crawler who runs the
 * site and nothing whatever about the page it is looking at.
 *
 * This hooks `omc_schema_graph` and fills those in, plus the two head tags the archives were
 * missing: a canonical link (WordPress only emits one for singular pages) and a description for
 * the journal index.
 *
 * Nothing here duplicates what already exists:
 *   - products and product categories get BreadcrumbList and Product from WooCommerce;
 *   - posts written in WHD → AI Posts carry their own BlogPosting, so they are left alone;
 *   - the About template keeps its AboutPage and gets no second WebPage node.
 *
 * @package Moderno Child
 */

defined( 'ABSPATH' ) || exit;

/** The site node, referenced by every page node as isPartOf. */
function omc_schema_website_id() {
	return home_url( '/' ) . '#website';
}

/**
 * The trail to the page you are on.
 *
 * Home first, then the page's own ancestors, then the page. A single item (the home page itself)
 * is not a trail, so nothing is emitted for it.
 *
 * @return array|null
 */
function omc_schema_breadcrumb( array $trail ) {
	if ( count( $trail ) < 2 ) {
		return null;
	}
	$items = [];
	foreach ( array_values( $trail ) as $i => [ $name, $url ] ) {
		$item = [
			'@type'    => 'ListItem',
			'position' => $i + 1,
			'name'     => $name,
		];
		// The last crumb is the page itself; Google asks that it carry no item URL.
		if ( $url && $i + 1 < count( $trail ) ) {
			$item['item'] = $url;
		}
		$items[] = $item;
	}

	return [
		'@type'           => 'BreadcrumbList',
		'@id'             => omc_schema_current_url() . '#breadcrumb',
		'itemListElement' => $items,
	];
}

/** The URL of the page being rendered, archives included. */
function omc_schema_current_url() {
	if ( is_singular() ) {
		return (string) get_permalink();
	}
	if ( is_tax() || is_category() || is_tag() ) {
		$link = get_term_link( get_queried_object() );
		return is_wp_error( $link ) ? home_url( '/' ) : (string) $link;
	}
	if ( function_exists( 'is_shop' ) && is_shop() ) {
		return (string) wc_get_page_permalink( 'shop' );
	}
	if ( is_home() ) {
		$blog = (int) get_option( 'page_for_posts' );
		return $blog ? (string) get_permalink( $blog ) : home_url( '/' );
	}

	return home_url( '/' );
}

/** The first N things in the loop, as an ItemList — what the archive is a list of. */
function omc_schema_item_list( $limit = 12 ) {
	global $wp_query;
	$items = [];
	foreach ( (array) $wp_query->posts as $i => $post ) {
		if ( $i >= $limit ) {
			break;
		}
		$items[] = [
			'@type'    => 'ListItem',
			'position' => $i + 1,
			'url'      => get_permalink( $post ),
			'name'     => get_the_title( $post ),
		];
	}

	return $items;
}

/**
 * Add what the page itself is.
 *
 * @param array $graph Nodes functions.php has already built.
 * @return array
 */
function omc_schema_page_nodes( array $graph ) {
	$home    = home_url( '/' );
	$site_id = omc_schema_website_id();
	$org     = [ '@id' => $home . '#organization' ];

	/* WebSite was emitted on the front page only, which left every other page's isPartOf
	   pointing at a node that was not there. It belongs on all of them. */
	$has_site = false;
	foreach ( $graph as $node ) {
		if ( 'WebSite' === ( $node['@type'] ?? '' ) ) {
			$has_site = true;
			break;
		}
	}
	if ( ! $has_site ) {
		$graph[] = [
			'@type'     => 'WebSite',
			'@id'       => $site_id,
			'url'       => $home,
			'name'      => get_bloginfo( 'name' ),
			'publisher' => $org,
		];
	}

	/* ── a journal post ── */
	if ( is_singular( 'post' ) ) {
		$post = get_queried_object();

		// Posts written in WHD → AI Posts bring their own BlogPosting; two would be one too many.
		if ( get_post_meta( $post->ID, '_whd_ai_origin', true ) ) {
			return $graph;
		}

		$url     = (string) get_permalink( $post );
		$article = [
			'@type'            => 'BlogPosting',
			'@id'              => $url . '#article',
			'mainEntityOfPage' => $url,
			'headline'         => wp_html_excerpt( get_the_title( $post ), 110, '…' ),
			'url'              => $url,
			'datePublished'    => get_the_date( DATE_W3C, $post ),
			'dateModified'     => get_the_modified_date( DATE_W3C, $post ),
			'author'           => $org,
			'publisher'        => $org,
			'isPartOf'         => [ '@id' => $site_id ],
		];

		$description = (string) get_post_meta( $post->ID, '_omc_meta_description', true );
		if ( ! $description ) {
			$description = wp_trim_words( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), 32, '…' );
		}
		if ( $description ) {
			$article['description'] = $description;
		}

		$image = get_the_post_thumbnail_url( $post, 'large' );
		if ( ! $image ) {
			// The first picture in the copy, when the post has no featured image set.
			if ( preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/i', (string) $post->post_content, $m ) ) {
				$image = $m[1];
			}
		}
		if ( $image ) {
			$article['image'] = $image;
		}

		$words = str_word_count( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ) );
		if ( $words ) {
			$article['wordCount'] = $words;
		}

		$terms = get_the_terms( $post, 'category' );
		if ( $terms && ! is_wp_error( $terms ) ) {
			$names = wp_list_pluck( $terms, 'name' );
			$article['articleSection'] = count( $names ) > 1 ? array_values( $names ) : (string) reset( $names );
		}

		$keywords = get_post_meta( $post->ID, '_whd_ai_keywords', true );
		if ( ! $keywords ) {
			$landing  = get_post_meta( $post->ID, '_omc_landing', true );
			$decoded  = is_string( $landing ) ? json_decode( $landing, true ) : $landing;
			$keywords = is_array( $decoded ) ? ( $decoded['keywords'] ?? [] ) : [];
		}
		if ( $keywords ) {
			$article['keywords'] = implode( ', ', array_slice( (array) $keywords, 0, 10 ) );
		}

		$graph[] = $article;

		$crumb = omc_schema_breadcrumb( array_filter( [
			[ __( 'Home', 'moderno-child' ), $home ],
			( (int) get_option( 'page_for_posts' ) )
				? [ get_the_title( (int) get_option( 'page_for_posts' ) ), get_permalink( (int) get_option( 'page_for_posts' ) ) ]
				: null,
			[ get_the_title( $post ), $url ],
		] ) );
		if ( $crumb ) {
			$graph[] = $crumb;
		}

		return $graph;
	}

	/* ── the journal index ── */
	if ( is_home() ) {
		$url   = omc_schema_current_url();
		$blog  = (int) get_option( 'page_for_posts' );
		$graph[] = [
			'@type'    => 'Blog',
			'@id'      => $url . '#blog',
			'url'      => $url,
			'name'     => $blog ? get_the_title( $blog ) : __( 'Journal', 'moderno-child' ),
			'isPartOf' => [ '@id' => $site_id ],
			'publisher' => $org,
			'blogPost' => omc_schema_item_list(),
		];
		$crumb = omc_schema_breadcrumb( [
			[ __( 'Home', 'moderno-child' ), $home ],
			[ $blog ? get_the_title( $blog ) : __( 'Journal', 'moderno-child' ), $url ],
		] );
		if ( $crumb ) {
			$graph[] = $crumb;
		}

		return $graph;
	}

	/* ── the shop, and the category archives under it ── */
	$is_shop = function_exists( 'is_shop' ) && is_shop();
	$is_cat  = function_exists( 'is_product_category' ) && ( is_product_category() || is_product_tag() );
	if ( $is_shop || $is_cat ) {
		$url  = omc_schema_current_url();
		$term = $is_cat ? get_queried_object() : null;
		$page = [
			'@type'    => 'CollectionPage',
			'@id'      => $url . '#webpage',
			'url'      => $url,
			'name'     => $term ? $term->name : __( 'Shop', 'moderno-child' ),
			'isPartOf' => [ '@id' => $site_id ],
		];
		$description = $term && $term->description ? wp_strip_all_tags( $term->description ) : '';
		if ( $description ) {
			$page['description'] = $description;
		}
		$items = omc_schema_item_list();
		if ( $items ) {
			$page['mainEntity'] = [
				'@type'           => 'ItemList',
				'itemListElement' => $items,
			];
		}
		$graph[] = $page;

		// WooCommerce already prints a BreadcrumbList on category archives; the shop root has none.
		if ( $is_shop ) {
			$crumb = omc_schema_breadcrumb( [
				[ __( 'Home', 'moderno-child' ), $home ],
				[ __( 'Shop', 'moderno-child' ), $url ],
			] );
			if ( $crumb ) {
				$graph[] = $crumb;
			}
		}

		return $graph;
	}

	/* ── an ordinary page ──
	   Templates that describe themselves already (About, the video pages, the landing pages with
	   their FAQPage) keep what they have; this is for everything that had nothing. */
	if ( is_page() && ! is_front_page() ) {
		$post = get_queried_object();
		$url  = (string) get_permalink( $post );

		/* The breadcrumb is worth having either way — nothing else on the site emits one for a
		   page — so it is built before the question of whether this page needs a node at all. */
		$trail = [ [ __( 'Home', 'moderno-child' ), $home ] ];
		foreach ( array_reverse( (array) get_post_ancestors( $post ) ) as $ancestor ) {
			$trail[] = [ get_the_title( $ancestor ), get_permalink( $ancestor ) ];
		}
		$trail[] = [ get_the_title( $post ), $url ];
		$crumb   = omc_schema_breadcrumb( $trail );

		/* Pages that already describe themselves keep what they have. A second node typing the
		   same URL as a plain WebPage beside its FAQPage says nothing the first did not. */
		if ( omc_schema_has_own_node( $graph, $post ) ) {
			if ( $crumb ) {
				$graph[] = $crumb;
			}

			return $graph;
		}

		$page = [
			'@type'            => omc_schema_page_type( $post ),
			'@id'              => $url . '#webpage',
			'url'              => $url,
			'name'             => get_the_title( $post ),
			'isPartOf'         => [ '@id' => $site_id ],
			'datePublished'    => get_the_date( DATE_W3C, $post ),
			'dateModified'     => get_the_modified_date( DATE_W3C, $post ),
			'mainEntityOfPage' => $url,
		];
		$description = (string) get_post_meta( $post->ID, '_omc_meta_description', true );
		if ( $description ) {
			$page['description'] = $description;
		}
		$image = get_the_post_thumbnail_url( $post, 'large' );
		if ( $image ) {
			$page['primaryImageOfPage'] = $image;
		}
		$graph[] = $page;

		if ( $crumb ) {
			$graph[] = $crumb;
		}
	}

	return $graph;
}
add_filter( 'omc_schema_graph', 'omc_schema_page_nodes' );

/**
 * Does this page already say what it is, somewhere other than here?
 *
 * The landing module prints its FAQPage in a script tag of its own, and the video module does the
 * same — neither passes through `omc_schema_graph`, so the graph handed to this filter does not
 * show them. The conditions are therefore checked directly rather than looked for in the array.
 *
 * @param array   $graph Nodes built so far.
 * @param WP_Post $post  The page being rendered.
 */
function omc_schema_has_own_node( array $graph, $post ) {
	foreach ( $graph as $node ) {
		foreach ( (array) ( $node['@type'] ?? [] ) as $type ) {
			if ( 'WebSite' !== $type && 'Page' === substr( (string) $type, -4 ) ) {
				return true; // AboutPage, from functions.php.
			}
		}
	}

	// A content page with questions on it becomes an FAQPage, which is a WebPage already.
	if ( function_exists( 'omc_is_landing_template' ) && function_exists( 'omc_landing_all_faqs' )
		&& omc_is_landing_template() && omc_landing_all_faqs( omc_landing_data( $post->ID ) ) ) {
		return true;
	}

	// So does a video page that carries them.
	$video = get_post_meta( $post->ID, '_omc_video', true );
	$video = is_string( $video ) ? json_decode( $video, true ) : $video;
	if ( is_array( $video ) && ! empty( $video['faqs'] ) ) {
		return true;
	}

	return false;
}

/**
 * The most specific type a page can honestly claim.
 *
 * A returns policy is not a WebPage in the same sense a contact form is, and saying so is free.
 */
function omc_schema_page_type( $post ) {
	$landing = get_post_meta( $post->ID, '_omc_landing', true );
	$decoded = is_string( $landing ) ? json_decode( $landing, true ) : $landing;
	$kind    = is_array( $decoded ) ? (string) ( $decoded['type'] ?? '' ) : '';

	if ( 'contact' === $kind ) {
		return 'ContactPage';
	}
	if ( 'faq' === $kind ) {
		return 'FAQPage';
	}
	if ( in_array( $kind, [ 'policy', 'sizeguide' ], true ) ) {
		return 'WebPage';
	}

	return 'WebPage';
}

/**
 * The canonical link, on the pages WordPress does not give one.
 *
 * `rel_canonical` only runs for singular pages, so the journal index, the shop and every product
 * category shipped without one — which leaves paged and filtered variants of the same archive
 * competing with each other.
 */
function omc_schema_canonical() {
	if ( function_exists( 'omc_has_seo_plugin' ) && omc_has_seo_plugin() ) {
		return;
	}
	if ( is_singular() || is_404() || is_search() ) {
		return; // WordPress handles the first; the other two should not claim a canonical at all.
	}
	$is_archive = is_home()
		|| ( function_exists( 'is_shop' ) && is_shop() )
		|| ( function_exists( 'is_product_category' ) && ( is_product_category() || is_product_tag() ) )
		|| is_category() || is_tag() || is_tax();
	if ( ! $is_archive ) {
		return;
	}

	$url  = omc_schema_current_url();
	$page = (int) get_query_var( 'paged' );
	if ( $page > 1 ) {
		// Page two of an archive is its own page, not a copy of page one.
		$url = trailingslashit( $url ) . 'page/' . $page . '/';
	}

	echo '<link rel="canonical" href="' . esc_url( $url ) . '">' . "\n";
}
add_action( 'wp_head', 'omc_schema_canonical', 2 );

/**
 * A description for the journal index.
 *
 * omc_seo_head() covers the front page, singular pages, terms and the shop; the posts page fell
 * between them and shipped with no description at all.
 */
function omc_schema_blog_description( $desc ) {
	if ( $desc || ! is_home() ) {
		return $desc;
	}
	$blog = (int) get_option( 'page_for_posts' );
	if ( $blog ) {
		$own = (string) get_post_meta( $blog, '_omc_meta_description', true );
		if ( $own ) {
			return $own;
		}
		$excerpt = get_post_field( 'post_excerpt', $blog );
		if ( $excerpt ) {
			return wp_strip_all_tags( $excerpt );
		}
	}

	return __( 'Style notes from Oops, Mine Co. — how to wear Korean and Thai pieces, what suits a petite frame, and what we are reaching for this season.', 'moderno-child' );
}
add_filter( 'omc_meta_description', 'omc_schema_blog_description' );
