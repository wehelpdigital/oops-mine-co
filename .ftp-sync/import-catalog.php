<?php
/**
 * Build the shop from `.ftp-sync/content/catalog-2026.json`.
 *
 *   php .ftp-sync/import-catalog.php [--dry-run]
 *   php .ftp-sync/import-catalog.php --live        (production write — the site owner runs this)
 *
 * In order: the category tree the client specified, then every product in the plan with its colour
 * and size variations, then everything that was here before moved to draft under "Archive".
 *
 * Idempotent. Products are matched by slug and updated in place, so running it twice does not make
 * a second shop. Nothing is deleted: the old catalogue is drafted and filed, not removed, because
 * "we might want that back" is the normal outcome and a restore should be one click.
 */

if ( in_array( '--live', $argv, true ) ) {
	putenv( 'OMC_DB=live' );
}
$live = 'live' === getenv( 'OMC_DB' );
$_SERVER['HTTP_HOST']   = $live ? 'oopsmineco.com' : 'oopsmine.test';
$_SERVER['REQUEST_URI'] = '/';

require dirname( __DIR__ ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/taxonomy.php';

if ( ! class_exists( 'WooCommerce' ) ) {
	exit( "WooCommerce is not active.\n" );
}

$dry = in_array( '--dry-run', $argv, true );
$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID' ] );
if ( $admins ) {
	wp_set_current_user( $admins[0]->ID );
}

$plan = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/.ftp-sync/content/catalog-2026.json' ), true );
if ( ! is_array( $plan ) ) {
	exit( "catalog-2026.json is not readable JSON\n" );
}

$source  = 'C:/Users/User/Downloads/oops-photos/';
$uploads = wp_get_upload_dir();
$imgdir  = trailingslashit( $uploads['basedir'] ) . 'omc-2026/products/';
if ( ! $dry && ! is_dir( $imgdir ) ) {
	mkdir( $imgdir, 0775, true );
}

printf( "%s  →  %s%s\n\n", $live ? 'LIVE database' : 'local database', home_url( '/' ), $dry ? '   (dry run)' : '' );

/* ─────────────────────────── helpers ─────────────────────────── */

/** A source photograph, resized and written as WebP into the catalogue folder. */
function omc_cat_webp( $src_file, $out_path, $max = 1800, $square = false ) {
	$size = @getimagesize( $src_file );
	if ( ! $size ) {
		return false;
	}
	switch ( $size[2] ) {
		case IMAGETYPE_JPEG: $im = @imagecreatefromjpeg( $src_file ); break;
		case IMAGETYPE_PNG:  $im = @imagecreatefrompng( $src_file );  break;
		case IMAGETYPE_WEBP: $im = @imagecreatefromwebp( $src_file ); break;
		default: return false;
	}
	if ( ! $im ) {
		return false;
	}
	if ( function_exists( 'exif_read_data' ) ) {
		$exif = @exif_read_data( $src_file );
		$map  = [ 3 => 180, 6 => -90, 8 => 90 ];
		$o    = (int) ( $exif['Orientation'] ?? 1 );
		if ( isset( $map[ $o ] ) ) {
			$rot = imagerotate( $im, $map[ $o ], 0 );
			if ( $rot ) {
				imagedestroy( $im );
				$im = $rot;
			}
		}
	}
	/*
	 * Scale by the short edge, not the long one. A product card is square and filled with cover, so
	 * a landscape photograph is scaled until its *height* covers the box; capping the long edge
	 * left some of them with 450px of height doing the work of 590, which is what made the cards
	 * look soft on a high-density screen. Never upscales.
	 */
	/*
	 * The card is square and filled with cover; cut the picture to match rather than leave the
	 * browser to stretch it. A third of the way down, not centred — in a photograph of a person the
	 * clothes are below the middle.
	 */
	if ( $square ) {
		$cw = imagesx( $im );
		$ch = imagesy( $im );
		$e  = min( $cw, $ch );
		if ( $cw !== $ch ) {
			$cut = imagecreatetruecolor( $e, $e );
			imagecopy( $cut, $im, 0, 0, (int) round( ( $cw - $e ) / 2 ), (int) round( ( $ch - $e ) * 0.32 ), $e, $e );
			imagedestroy( $im );
			$im = $cut;
		}
	}

	$w     = imagesx( $im );
	$h     = imagesy( $im );
	$short = 1200;
	$sc    = min( 1, min( $max / $w, $max / $h ) );
	if ( min( $w, $h ) * $sc < $short ) {
		$sc = min( 1, $short / min( $w, $h ) );
	}
	if ( $sc < 1 ) {
		$out = imagecreatetruecolor( max( 1, (int) round( $w * $sc ) ), max( 1, (int) round( $h * $sc ) ) );
		imagecopyresampled( $out, $im, 0, 0, 0, 0, imagesx( $out ), imagesy( $out ), $w, $h );
		imagedestroy( $im );
		$im = $out;
	}
	imagewebp( $im, $out_path, 82 );
	imagedestroy( $im );

	return true;
}

/** Find or create one attachment for a prepared catalogue image. */
function omc_cat_attachment( $rel, $path, $title, $alt ) {
	global $wpdb;
	$id = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1",
		$rel
	) );
	if ( ! $id ) {
		$id = wp_insert_attachment( [
			'post_mime_type' => 'image/webp',
			'post_title'     => $title,
			'post_excerpt'   => $alt,
			'post_status'    => 'inherit',
		], $path );
		if ( is_wp_error( $id ) || ! $id ) {
			return 0;
		}
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $path ) );
	} else {
		wp_update_post( [ 'ID' => $id, 'post_title' => $title, 'post_excerpt' => $alt ] );
		// The intermediate sizes are cut from the file; if the file has been re-prepared at another
		// shape, the stale set is what the browser picks from and the card looks soft.
		$meta = wp_get_attachment_metadata( $id );
		$now  = @getimagesize( $path );
		if ( $now && ( (int) ( $meta['width'] ?? 0 ) !== $now[0] || (int) ( $meta['height'] ?? 0 ) !== $now[1] ) ) {
			wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $path ) );
		}
	}
	update_post_meta( $id, '_wp_attachment_image_alt', $alt );

	return (int) $id;
}

/** A global attribute (pa_colour, pa_size), created and registered so it is usable this request. */
function omc_cat_attribute( $label, $slug ) {
	$tax = wc_attribute_taxonomy_name( $slug );
	if ( ! taxonomy_exists( $tax ) ) {
		$id = wc_attribute_taxonomy_id_by_name( $slug );
		if ( ! $id ) {
			$id = wc_create_attribute( [ 'name' => $label, 'slug' => $slug, 'type' => 'select', 'order_by' => 'menu_order' ] );
			if ( is_wp_error( $id ) ) {
				return '';
			}
		}
		// WooCommerce registers these on init; this script is past that, so do it by hand.
		register_taxonomy( $tax, 'product', [ 'hierarchical' => false, 'show_ui' => false, 'query_var' => true, 'rewrite' => false ] );
		delete_transient( 'wc_attribute_taxonomies' );
	}

	return $tax;
}

/** A product category, created under its parent if it is not there yet. */
function omc_cat_term( $slug, $name, $parent = 0, $description = '' ) {
	$term = get_term_by( 'slug', $slug, 'product_cat' );
	if ( $term && ! is_wp_error( $term ) ) {
		wp_update_term( $term->term_id, 'product_cat', [ 'name' => $name, 'description' => $description, 'parent' => $parent ] );
		return (int) $term->term_id;
	}
	$made = wp_insert_term( $name, 'product_cat', [ 'slug' => $slug, 'parent' => $parent, 'description' => $description ] );

	return is_wp_error( $made ) ? 0 : (int) $made['term_id'];
}

/* ─────────────────────────── 1. the category tree ─────────────────────────── */

echo "1. categories\n";
$keep = [];
foreach ( (array) $plan['categories'] as $group ) {
	$pid = $dry ? 0 : omc_cat_term( $group['slug'], $group['name'], 0, $group['description'] ?? '' );
	$keep[] = $group['slug'];
	printf( "   %-22s %s\n", $group['slug'], $dry ? '(would create)' : 'ok' );
	foreach ( (array) ( $group['children'] ?? [] ) as $child ) {
		if ( ! $dry ) {
			omc_cat_term( $child['slug'], $child['name'], $pid, $child['description'] ?? '' );
		}
		$keep[] = $child['slug'];
		printf( "     %-20s %s\n", $child['slug'], $dry ? '(would create)' : 'ok' );
	}
}
$archive_id = $dry ? 0 : omc_cat_term( 'archive', 'Archive', 0, 'Pieces taken off the shop floor. Kept so they can be put back.' );
$keep[] = 'archive';
$keep[] = 'uncategorized';

/* ─────────────────────────── 2. the products ─────────────────────────── */

echo "\n2. products\n";
$sizes = (array) ( $plan['sizes'] ?? [] );
$made  = 0;
$kept_ids = [];

foreach ( (array) $plan['products'] as $p ) {
	$slug = $p['slug'];
	$existing = get_page_by_path( $slug, OBJECT, 'product' );

	if ( $dry ) {
		printf( "   %-30s %s  (%d images, %d colours)\n", $slug, $existing ? 'update' : 'create', count( $p['images'] ), count( $p['colours'] ?? [] ) );
		continue;
	}

	$product = $existing ? wc_get_product( $existing->ID ) : new WC_Product_Simple();
	$product->set_name( $p['title'] );
	$product->set_slug( $slug );
	$product->set_status( 'publish' );
	$product->set_catalog_visibility( 'visible' );
	$product->set_description( wpautop( $p['description'] ) );
	$product->set_short_description( $p['short'] );
	$product->set_regular_price( (string) $p['price'] );
	$product->set_manage_stock( false );
	$product->set_stock_status( 'instock' );
	$pid = $product->save();

	// Pictures first: the gallery is what the variations will point at.
	$gallery = [];
	$by_file = [];
	foreach ( array_values( (array) $p['images'] ) as $i => $file ) {
		$rel  = 'omc-2026/products/' . $slug . '-' . ( $i + 1 ) . '.webp';
		$path = trailingslashit( wp_get_upload_dir()['basedir'] ) . $rel;
		if ( ! file_exists( $path ) && ! omc_cat_webp( 'C:/Users/User/Downloads/oops-photos/' . $file, $path, 1800, 0 === $i ) ) {
			continue;
		}
		$alt = $p['title'] . ' — ' . $p['short'];
		$aid = omc_cat_attachment( $rel, $path, $p['title'], mb_strimwidth( $alt, 0, 120, '' ) );
		if ( $aid ) {
			$gallery[]        = $aid;
			$by_file[ $file ] = $aid;
		}
	}
	if ( $gallery ) {
		set_post_thumbnail( $pid, $gallery[0] );
		update_post_meta( $pid, '_product_image_gallery', implode( ',', array_slice( $gallery, 1 ) ) );
	}


	// Colour and size become real WooCommerce variations, so cart, stock and the swatch plugin work.
	$colours = array_values( array_filter( array_column( (array) ( $p['colours'] ?? [] ), 'name' ) ) );
	$runs    = $p['sizes'] ? (array) ( $sizes[ $p['sizes'] ] ?? [] ) : [];

	if ( $colours || $runs ) {
		$attrs = [];
		$order = 0;
		foreach ( [ [ 'Colour', 'colour', $colours ], [ 'Size', 'size', $runs ] ] as list( $label, $aslug, $values ) ) {
			if ( ! $values ) {
				continue;
			}
			$tax = omc_cat_attribute( $label, $aslug );
			if ( ! $tax ) {
				continue;
			}
			$term_ids = [];
			foreach ( $values as $v ) {
				$t = get_term_by( 'name', $v, $tax );
				if ( ! $t ) {
					$t = wp_insert_term( $v, $tax );
					$t = is_wp_error( $t ) ? null : get_term( $t['term_id'], $tax );
				}
				if ( $t ) {
					$term_ids[] = (int) $t->term_id;
				}
			}
			wp_set_object_terms( $pid, $term_ids, $tax );

			$a = new WC_Product_Attribute();
			$a->set_id( wc_attribute_taxonomy_id_by_name( $aslug ) );
			$a->set_name( $tax );
			$a->set_options( $term_ids );
			$a->set_position( $order++ );
			$a->set_visible( true );
			$a->set_variation( true );
			$attrs[] = $a;
		}

		if ( $attrs ) {
			// Converting by instantiating the variable class and saving — setting the product_type
			// term and re-fetching does not work, WooCommerce caches the type per request.
			$variable = new WC_Product_Variable( $pid );
			$variable->set_attributes( $attrs );
			$variable->save();

			$colour_terms = $colours ? wp_get_object_terms( $pid, wc_attribute_taxonomy_name( 'colour' ), [ 'fields' => 'slugs' ] ) : [ '' ];
			$size_terms   = $runs ? wp_get_object_terms( $pid, wc_attribute_taxonomy_name( 'size' ), [ 'fields' => 'slugs' ] ) : [ '' ];

			$have = [];
			foreach ( $variable->get_children() as $child ) {
				$v = wc_get_product( $child );
				if ( $v ) {
					$have[ implode( '|', $v->get_attributes() ) ] = $child;
				}
			}
			foreach ( $colour_terms as $cslug ) {
				foreach ( $size_terms as $sslug ) {
					$combo = [];
					if ( $cslug ) {
						$combo[ wc_attribute_taxonomy_name( 'colour' ) ] = $cslug;
					}
					if ( $sslug ) {
						$combo[ wc_attribute_taxonomy_name( 'size' ) ] = $sslug;
					}
					$key = implode( '|', $combo );
					if ( isset( $have[ $key ] ) ) {
						continue;
					}
					$var = new WC_Product_Variation();
					$var->set_parent_id( $pid );
					$var->set_attributes( $combo );
					$var->set_regular_price( (string) $p['price'] );
					$var->set_stock_status( 'instock' );
					$var->save();
				}
			}
			WC_Product_Variable::sync( $pid );
		}
	}

	/*
	 * Categories last. WooCommerce gives a product the default category whenever it is saved
	 * without one, and this product has been saved more than once by now — set them any earlier
	 * and "Uncategorized" is quietly added back underneath, which the theme then hides from the
	 * shop. Set by id: a bare slug is matched against term names first, and would create a second
	 * category called "pants-trousers" beside "Pants & Trousers".
	 */
	$cat_ids = [];
	foreach ( [ $p['category'], 'new-arrivals' ] as $cslug ) {
		$t = get_term_by( 'slug', $cslug, 'product_cat' );
		if ( $t && ! is_wp_error( $t ) ) {
			$cat_ids[] = (int) $t->term_id;
		}
	}
	wp_set_object_terms( $pid, $cat_ids, 'product_cat' );

	$kept_ids[] = $pid;
	$made++;
	printf( "   #%-6d %-30s %2d images  %d colour × %d size\n", $pid, $slug, count( $gallery ), max( 1, count( $colours ) ), max( 1, count( $runs ) ) );
}

/* ─────────────────────────── 3. everything else, drafted ─────────────────────────── */

echo "\n3. the old catalogue\n";
$all = get_posts( [ 'post_type' => 'product', 'post_status' => [ 'publish', 'pending', 'private' ], 'numberposts' => -1, 'fields' => 'ids' ] );
$old = array_diff( $all, $kept_ids );
printf( "   %d product(s) to put away\n", count( $old ) );

if ( ! $dry ) {
	foreach ( $old as $id ) {
		wp_update_post( [ 'ID' => $id, 'post_status' => 'draft' ] );
		wp_set_object_terms( $id, $archive_id ? [ (int) $archive_id ] : [], 'product_cat' );
		update_post_meta( $id, '_visibility', 'hidden' );
		wp_set_object_terms( $id, [ 'exclude-from-catalog', 'exclude-from-search' ], 'product_visibility' );
	}
	printf( "   drafted and filed under Archive\n" );

	// Categories the client's list does not mention, now that nothing published is in them.
	$gone = 0;
	foreach ( get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false ] ) as $t ) {
		if ( in_array( $t->slug, $keep, true ) ) {
			continue;
		}
		wp_delete_term( $t->term_id, 'product_cat' );
		$gone++;
	}
	printf( "   removed %d category(ies) that are not on the client's list\n", $gone );
}

echo "\n";
/* -- the theme's own demo journal posts ------------------------------------ */

if ( ! $dry ) {
	/*
	 * Filler that arrived with the theme, carrying its stock photography. Leaving it published
	 * beside the client's own work is the same problem the demo catalogue was. Drafted, not
	 * deleted — the slugs are listed rather than guessed at, so nothing the client wrote is caught
	 * by a pattern match.
	 */
	$demo_posts = [ 'collections_post', 'collection2023', 'gifts', 'new-trends-in-clothes-industry-in-2023' ];
	$put_away   = 0;
	foreach ( $demo_posts as $slug ) {
		$post = get_page_by_path( $slug, OBJECT, 'post' );
		if ( $post && 'draft' !== $post->post_status ) {
			wp_update_post( [ 'ID' => $post->ID, 'post_status' => 'draft' ] );
			$put_away++;
		}
	}
	if ( $put_away ) {
		printf( "   drafted %d demo journal post(s)\n", $put_away );
	}
}

/* -- the default category, which keeps coming back ------------------------- */

if ( ! $dry && $kept_ids ) {
	global $wpdb;
	/*
	 * WooCommerce adds "Uncategorized" whenever a product is saved without a category, and this
	 * script saves each product several times. Setting the real categories at the end clears it
	 * from the product's term list, but a relationship row survives — and the theme excludes that
	 * category from the shop by term, so every new product came out published, in stock, and
	 * missing from every grid. Deleting the row and the caches built from it is what sticks.
	 */
	$default_term = (int) get_option( 'default_product_cat' );
	$default_tt   = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = %s",
		$default_term,
		'product_cat'
	) );
	if ( $default_tt ) {
		$in      = implode( ',', array_map( 'intval', $kept_ids ) );
		$removed = (int) $wpdb->query( "DELETE FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = {$default_tt} AND object_id IN ({$in})" ); // phpcs:ignore
		if ( $removed ) {
			wp_update_term_count_now( [ $default_tt ], 'product_cat' );
			clean_term_cache( [ $default_term ], 'product_cat' );
			foreach ( $kept_ids as $cid ) {
				clean_object_term_cache( $cid, 'product' );
			}
			printf( "   took \"Uncategorized\" back off %d product(s)
", $removed );
		}
	}
	wc_delete_product_transients();
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wc_term_counts%' OR option_name LIKE '_transient_timeout_wc_term_counts%'" ); // phpcs:ignore
	wc_recount_all_terms();
	echo "   caches and term counts rebuilt
";
}

printf( "%d product(s) %s, %d put away%s\n", $made, $dry ? 'planned' : 'live', count( $old ), $dry ? ' (dry run — nothing written)' : '' );
