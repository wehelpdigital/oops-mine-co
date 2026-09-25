<?php
/**
 * Publish the Oops, Mine Co. site setup: content pages, product categories, the mega menu,
 * theme/WooCommerce settings, the WHD plugins and the coupons.
 *
 * Idempotent: a second run reports no changes. CLI only.
 *
 *   local DB :  php .ftp-sync/publish-site.php [--dry-run]
 *   LIVE DB  :  OMC_DB=live php .ftp-sync/publish-site.php   (PowerShell: $env:OMC_DB="live"; php .ftp-sync/publish-site.php)
 *
 * Flags:
 *   --dry-run   report every change it would make and write nothing.
 *   --force-menu  rebuild the Main Menu even when its signature is unchanged.
 *
 * Sections:
 *   0  site identity
 *   1  palette + theme mods
 *   2  announcement bar (theme "HTML block")
 *   3  home + about pages
 *   4  content import — .ftp-sync/content/pages/*.json → product categories, landing / journal /
 *      faq / contact / policy pages; occasion categories from content/plan.json; demo pages drafted
 *   5  Main Menu, rebuilt as the theme's mega menu
 *   6  store, cart, product page, contact and WooCommerce settings
 *   7  WHD + WHD Variation Tiers plugins
 *   8  coupons (OOPS10, STAY15)
 *
 * Requires the code (child theme + whd plugins) to already be on the target server.
 */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 403 ); exit( 'CLI only' ); }

$omc_argv                   = isset( $_SERVER['argv'] ) ? (array) $_SERVER['argv'] : [];
$GLOBALS['omc_pub_dry']     = in_array( '--dry-run', $omc_argv, true ) || in_array( '-n', $omc_argv, true );
$GLOBALS['omc_pub_changes'] = [];
$omc_force_menu             = in_array( '--force-menu', $omc_argv, true );

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
$log = [
	'target' => $is_live ? 'LIVE database (' . home_url( '/' ) . ')' : 'local database (' . home_url( '/' ) . ')',
	'mode'   => omc_pub_dry()
		? 'dry-run (nothing written; new ids read as 0 and the menu preview only counts pages/categories that already exist)'
		: 'write',
];

/* ────────────────────────────── helpers ────────────────────────────── */

/** True when --dry-run was passed: every helper below then only reports. */
function omc_pub_dry() {
	return ! empty( $GLOBALS['omc_pub_dry'] );
}

/** Record one line for the change log (the log is empty on a second run). */
function omc_pub_note( $line ) {
	$GLOBALS['omc_pub_changes'][] = $line;
	if ( omc_pub_dry() ) {
		fwrite( STDERR, "would change: {$line}\n" );
	}
	return true;
}

/** Short, printable form of a stored value, for the change log. */
function omc_pub_show( $value ) {
	if ( is_bool( $value ) || null === $value ) {
		return var_export( $value, true );
	}
	if ( is_array( $value ) ) {
		return wp_json_encode( $value );
	}
	$value = (string) $value;
	$value = preg_replace( '/\s+/', ' ', $value );
	return "'" . ( strlen( $value ) > 60 ? substr( $value, 0, 57 ) . '…' : $value ) . "'";
}

/**
 * Loose equality for settings: '' / false / null are the same "unset", 1 and true are the same
 * "on", and hex colours compare case-insensitively. Keeps re-runs from rewriting equal values.
 */
function omc_pub_same( $a, $b ) {
	if ( is_array( $a ) || is_array( $b ) ) {
		return wp_json_encode( $a ) === wp_json_encode( $b );
	}
	$norm = static function ( $v ) {
		if ( true === $v ) { return '1'; }
		if ( false === $v || null === $v ) { return ''; }
		$v = (string) $v;
		return preg_match( '/^#[0-9a-f]{3,8}$/i', $v ) ? strtolower( $v ) : $v;
	};
	return $norm( $a ) === $norm( $b );
}

/** set_theme_mod() that skips (and logs) when the value already matches. */
function omc_pub_mod( $key, $value ) {
	if ( omc_pub_same( get_theme_mod( $key ), $value ) ) {
		return false;
	}
	omc_pub_note( "theme_mod {$key}: " . omc_pub_show( get_theme_mod( $key ) ) . ' → ' . omc_pub_show( $value ) );
	if ( ! omc_pub_dry() ) {
		set_theme_mod( $key, $value );
	}
	return true;
}

/** update_option() that skips (and logs) when the value already matches. */
function omc_pub_option( $name, $value ) {
	$current = get_option( $name, null );
	// WordPress sanitises some options on the way in (blogdescription escapes the apostrophe in
	// the tagline), so compare the stored form too or every run rewrites the same value.
	$stored = is_scalar( $value ) ? sanitize_option( $name, $value ) : $value;
	if ( omc_pub_same( $current, $value ) || omc_pub_same( $current, $stored ) ) {
		return false;
	}
	omc_pub_note( "option {$name}: " . omc_pub_show( $current ) . ' → ' . omc_pub_show( $value ) );
	if ( ! omc_pub_dry() ) {
		update_option( $name, $value );
	}
	return true;
}

/** update_post_meta() that skips (and logs) when the value already matches. */
function omc_pub_meta( $post_id, $key, $value ) {
	$post_id = (int) $post_id;
	if ( ! $post_id ) {
		return false;
	}
	$current = get_post_meta( $post_id, $key, true );
	if ( omc_pub_same( $current, $value ) ) {
		return false;
	}
	omc_pub_note( "meta #{$post_id} {$key}: " . omc_pub_show( $current ) . ' → ' . omc_pub_show( $value ) );
	if ( ! omc_pub_dry() ) {
		update_post_meta( $post_id, $key, is_string( $value ) ? wp_slash( $value ) : $value );
	}
	return true;
}

/** Drop every _elementor* meta so a page renders through its page template again. */
function omc_pub_strip_elementor( $post_id ) {
	$post_id = (int) $post_id;
	if ( ! $post_id || ! get_post_meta( $post_id, '_elementor_edit_mode', true ) ) {
		return false;
	}
	$keys = [];
	foreach ( (array) get_post_meta( $post_id ) as $key => $unused ) {
		if ( 0 === strpos( (string) $key, '_elementor' ) ) {
			$keys[] = $key;
		}
	}
	omc_pub_note( "page #{$post_id}: removed Elementor meta (" . count( $keys ) . ' keys)' );
	if ( ! omc_pub_dry() ) {
		/*
		 * _elementor_data IS the page design. On live these may be pages the owner built by hand,
		 * and there is no undo once the rows are gone, so keep a copy under a key Elementor never
		 * reads. Restoring is a rename: _omc_elementor_backup_<key> → <key>.
		 */
		$backup = [];
		foreach ( $keys as $key ) {
			$backup[ $key ] = get_post_meta( $post_id, $key, true );
		}
		if ( ! get_post_meta( $post_id, '_omc_elementor_backup', true ) ) {
			update_post_meta( $post_id, '_omc_elementor_backup', wp_slash( wp_json_encode( $backup ) ) );
			update_post_meta( $post_id, '_omc_elementor_backup_at', current_time( 'mysql' ) );
			omc_pub_note( "page #{$post_id}: Elementor meta backed up to _omc_elementor_backup" );
		}
		foreach ( $keys as $key ) {
			delete_post_meta( $post_id, $key );
		}
	}
	return true;
}

/* ── Journal thumbnails ──────────────────────────────────────────────────────
   The keyword posts were imported without images, so the blog grid rendered as a
   wall of text. These are the theme's own demo photographs, assigned by slug so a
   post keeps the same picture on every environment and on every re-run. Swap them
   for real photography by setting a featured image in the editor: anything already
   set is left alone. */
function omc_pub_post_thumbnails() {
	// Womenswear and still-life only: the demo library also holds men's shots, and a male model
	// on "Traditional Thai Clothes" on a womenswear boutique reads as a mistake. Checked by eye
	// against a contact sheet, not by filename.
	$pool = [
		'2023/04/ricky-2127760710.jpg',
		'2023/04/ricky-2131286131.jpg',
		'2023/04/ricky-2152926699.jpg',
		'2023/04/ricky-2152516324.jpg',
		'2023/04/ricky-2152193566.jpg',
		'2023/04/ricky-2202377559.jpg',
		'2023/04/ricky-2347110865.jpg',
		'2023/04/ricky-2347961730.jpg',
		'2023/04/ricky-2347791350.jpg',
		'2023/04/moderno-2338177441.jpg',
		'2023/04/moderno-2338441644.jpg',
		'2023/04/moderno-2338813525.jpg',
		'2023/04/moderno-2346592900.jpg',
		'2023/04/moderno-2310883787.jpg',
		'2023/04/moderno-2310427039.jpg',
		'2023/04/moderno-2327197569.jpg',
		'2023/04/moderno-2607419203.jpg',
		'2023/04/moderno-2606815318.jpg',
		'2023/04/moderno-2606742971.jpg',
		'2023/04/moderno-2840461420.jpg',
		'2023/04/moderno-2844197659.jpg',
		'2023/04/moderno-2842566551.jpg',
	];

	$ids = [];
	foreach ( $pool as $file ) {
		$id = omc_pub_attachment_by_file( $file );
		if ( $id ) {
			$ids[] = $id;
		}
	}
	if ( ! $ids ) {
		omc_pub_note( 'journal thumbnails: none of the demo photographs are in this media library, skipped' );
		return;
	}

	$posts = get_posts( [
		'post_type'   => 'post',
		'numberposts' => -1,
		'post_status' => [ 'publish', 'draft' ],
		'orderby'     => 'ID',
		'order'       => 'ASC',
	] );

	$set = 0;
	foreach ( $posts as $post ) {
		if ( get_post_thumbnail_id( $post->ID ) ) {
			continue; // a real image was chosen for this one
		}
		// crc32 of the slug: stable across environments, and neighbouring slugs do not collide.
		$id = $ids[ crc32( $post->post_name ) % count( $ids ) ];
		omc_pub_note( "post {$post->post_name} (#{$post->ID}): featured image set to #{$id}" );
		if ( ! omc_pub_dry() ) {
			set_post_thumbnail( $post->ID, $id );
		}
		$set++;
	}
	$GLOBALS['omc_pub_log_thumbs'] = $set;
}

/**
 * Every imported post landed in Style notes AND in WordPress's default Uncategorized, so each
 * journal card read "STYLE NOTES · UNCATEGORIZED". Drop the default wherever a real category
 * is set; a post with nothing else keeps it, so nothing ends up with no category at all.
 */
function omc_pub_tidy_categories() {
	$default = get_category( (int) get_option( 'default_category' ) );
	if ( ! $default || is_wp_error( $default ) ) {
		return;
	}
	$cleaned = 0;
	foreach ( get_posts( [ 'post_type' => 'post', 'numberposts' => -1, 'post_status' => [ 'publish', 'draft' ] ] ) as $post ) {
		$terms = wp_get_post_categories( $post->ID );
		if ( count( $terms ) < 2 || ! in_array( $default->term_id, $terms, true ) ) {
			continue;
		}
		$keep = array_values( array_diff( $terms, [ $default->term_id ] ) );
		omc_pub_note( "post {$post->post_name} (#{$post->ID}): removed the {$default->name} category" );
		if ( ! omc_pub_dry() ) {
			wp_set_post_categories( $post->ID, $keep );
		}
		$cleaned++;
	}
	$GLOBALS['omc_pub_log_cats'] = $cleaned;
}

/** Attachment id for an uploads-relative path, the way the child theme resolves images. */
function omc_pub_attachment_by_file( $file ) {
	global $wpdb;
	$id = $wpdb->get_var( $wpdb->prepare(
		"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1",
		$file
	) );
	return $id ? (int) $id : 0;
}

/**
 * Create or update a post/page by slug. Only writes when a field really differs, so
 * post_modified does not churn on a second run.
 *
 * @param array $args post_type, post_name, post_title, post_status, post_content, post_excerpt,
 *                    optional page_template, meta (array) and categories (term ids, posts only).
 * @return array [ id, action ] — action is created|updated|unchanged (id is 0 in a dry run).
 */
function omc_pub_upsert_post( $args ) {
	$type     = $args['post_type'] ?? 'page';
	$slug     = $args['post_name'];
	$existing = get_page_by_path( $slug, OBJECT, $type );
	$fields   = [ 'post_title', 'post_status', 'post_content', 'post_excerpt' ];

	if ( $existing ) {
		$diff = [];
		foreach ( $fields as $field ) {
			if ( isset( $args[ $field ] ) && (string) $existing->$field !== (string) $args[ $field ] ) {
				$diff[] = $field;
			}
		}
		$id = (int) $existing->ID;
		if ( $diff ) {
			omc_pub_note( "{$type} {$slug} (#{$id}): updated " . implode( ', ', $diff ) );
			if ( ! omc_pub_dry() ) {
				$update       = array_intersect_key( $args, array_flip( $fields ) );
				$update['ID'] = $id;
				wp_update_post( wp_slash( $update ) );
			}
		}
		$action = $diff ? 'updated' : 'unchanged';
	} else {
		omc_pub_note( "{$type} {$slug}: created" );
		$id = 0;
		if ( ! omc_pub_dry() ) {
			$insert = array_intersect_key( $args, array_flip( $fields ) );
			$insert += [ 'post_type' => $type, 'post_name' => $slug, 'post_status' => 'publish' ];
			$id      = (int) wp_insert_post( wp_slash( $insert ), true );
			if ( is_wp_error( $id ) || ! $id ) {
				omc_pub_note( "{$type} {$slug}: INSERT FAILED" );
				$id = 0;
			}
		}
		$action = 'created';
	}

	if ( $id ) {
		if ( ! empty( $args['page_template'] ) ) {
			// Set the meta directly: wp_insert_post() refuses a template file the theme does not have yet.
			omc_pub_meta( $id, '_wp_page_template', $args['page_template'] );
		}
		foreach ( (array) ( $args['meta'] ?? [] ) as $key => $value ) {
			omc_pub_meta( $id, $key, $value );
		}
		if ( ! empty( $args['categories'] ) ) {
			$have = wp_get_object_terms( $id, 'category', [ 'fields' => 'ids' ] );
			$want = array_map( 'intval', (array) $args['categories'] );
			if ( array_diff( $want, is_array( $have ) ? $have : [] ) ) {
				omc_pub_note( "{$type} {$slug} (#{$id}): category assigned" );
				if ( ! omc_pub_dry() ) {
					wp_set_object_terms( $id, $want, 'category', true );
				}
			}
		}
	}

	return [ 'id' => $id, 'action' => $action ];
}

/**
 * Create or update a taxonomy term by slug.
 *
 * @param array $args slug, name, taxonomy, parent (slug or id), description.
 * @return int term id (0 when a dry run would have created it).
 */
function omc_pub_upsert_term( $args ) {
	$taxonomy = $args['taxonomy'] ?? 'product_cat';
	$slug     = sanitize_title( $args['slug'] );
	$name     = (string) ( $args['name'] ?? $slug );
	$desc     = (string) ( $args['description'] ?? '' );

	$parent_id = 0;
	if ( ! empty( $args['parent'] ) ) {
		$parent = is_numeric( $args['parent'] ) ? get_term( (int) $args['parent'], $taxonomy ) : get_term_by( 'slug', sanitize_title( $args['parent'] ), $taxonomy );
		if ( $parent && ! is_wp_error( $parent ) ) {
			$parent_id = (int) $parent->term_id;
		}
	}

	// A dry run writes nothing, so a term two files share would be reported "created" twice.
	static $pretend = [];
	if ( omc_pub_dry() && isset( $pretend[ $taxonomy . ':' . $slug ] ) ) {
		return 0;
	}

	$term = get_term_by( 'slug', $slug, $taxonomy );
	if ( $term && ! is_wp_error( $term ) ) {
		// WordPress encodes entities on the way in ("Chic & Boho" is stored "Chic &amp; Boho"),
		// so compare the stored form too or the term is rewritten on every run.
		$as_stored = static function ( $field, $value ) use ( $term, $taxonomy ) {
			return sanitize_term_field( $field, $value, (int) $term->term_id, $taxonomy, 'db' );
		};
		$diff = [];
		if ( $name !== $term->name && $as_stored( 'name', $name ) !== $term->name ) { $diff['name'] = $name; }
		if ( '' !== $desc && $desc !== $term->description && $as_stored( 'description', $desc ) !== $term->description ) { $diff['description'] = $desc; }
		if ( $parent_id && $parent_id !== (int) $term->parent ) { $diff['parent'] = $parent_id; }
		if ( $diff ) {
			omc_pub_note( "{$taxonomy} {$slug}: updated " . implode( ', ', array_keys( $diff ) ) );
			if ( ! omc_pub_dry() ) {
				wp_update_term( (int) $term->term_id, $taxonomy, $diff );
			}
		}
		return (int) $term->term_id;
	}

	omc_pub_note( "{$taxonomy} {$slug}: created ({$name})" );
	if ( omc_pub_dry() ) {
		$pretend[ $taxonomy . ':' . $slug ] = true;
		return 0;
	}
	$new = wp_insert_term( $name, $taxonomy, [ 'slug' => $slug, 'parent' => $parent_id, 'description' => $desc ] );
	if ( is_wp_error( $new ) ) {
		omc_pub_note( "{$taxonomy} {$slug}: INSERT FAILED — " . $new->get_error_message() );
		return 0;
	}
	return (int) $new['term_id'];
}

/** Append products to a term without touching the categories they already have. */
function omc_pub_assign_products( $term_id, $product_ids, $taxonomy = 'product_cat' ) {
	$term_id = (int) $term_id;
	$added   = 0;
	if ( ! $term_id ) {
		return 0;
	}
	foreach ( (array) $product_ids as $product_id ) {
		$product_id = (int) $product_id;
		if ( ! $product_id || 'product' !== get_post_type( $product_id ) ) {
			continue;
		}
		if ( is_object_in_term( $product_id, $taxonomy, $term_id ) ) {
			continue;
		}
		$added ++;
		if ( ! omc_pub_dry() ) {
			wp_set_object_terms( $product_id, [ $term_id ], $taxonomy, true );
		}
	}
	if ( $added ) {
		$term = get_term( $term_id, $taxonomy );
		omc_pub_note( "{$taxonomy} " . ( $term && ! is_wp_error( $term ) ? $term->slug : $term_id ) . ": +{$added} products" );
	}
	return $added;
}

/** Inline HTML a writer may use inside a paragraph, bullet or cell. */
function omc_pub_inline( $text ) {
	if ( is_array( $text ) ) {
		$text = implode( ' ', array_map( 'strval', $text ) );
	}
	return trim( wp_kses( (string) $text, [
		'a'      => [ 'href' => [], 'title' => [], 'rel' => [], 'target' => [] ],
		'em'     => [],
		'strong' => [],
		'b'      => [],
		'i'      => [],
		'br'     => [],
		'small'  => [],
	] ) );
}

/** A list of non-empty inline strings (a writer may pass one string or a list). */
function omc_pub_lines( $value ) {
	$out = [];
	foreach ( is_array( $value ) ? $value : [ $value ] as $line ) {
		$line = omc_pub_inline( $line );
		if ( '' !== $line ) {
			$out[] = $line;
		}
	}
	return $out;
}

/** <p><a href="…">…</a></p> for a { text, url } pair. */
function omc_pub_render_cta_link( $cta ) {
	$text = omc_pub_inline( $cta['text'] ?? ( $cta['button_text'] ?? '' ) );
	$url  = (string) ( $cta['url'] ?? ( $cta['button_url'] ?? '' ) );
	if ( '' === $text || '' === $url ) {
		return '';
	}
	return '<p><a href="' . esc_url( $url ) . '">' . $text . '</a></p>';
}

/**
 * The plain-HTML copy of a content document that lands in post_content.
 * Headings, paragraphs, lists and tables only — no template markup, so the page still reads
 * correctly if its template is missing, in a feed, or in search results.
 */
function omc_pub_render_doc( array $doc ) {
	$out = [];

	foreach ( omc_pub_lines( $doc['hero']['intro'] ?? '' ) as $line ) {
		$out[] = '<p>' . $line . '</p>';
	}
	$hero_cta = omc_pub_render_cta_link( (array) ( $doc['hero'] ?? [] ) + [ 'text' => $doc['hero']['cta_text'] ?? '', 'url' => $doc['hero']['cta_url'] ?? '' ] );
	if ( $hero_cta ) {
		$out[] = $hero_cta;
	}

	foreach ( (array) ( $doc['sections'] ?? [] ) as $section ) {
		if ( ! is_array( $section ) ) {
			continue;
		}
		$heading = omc_pub_inline( $section['heading'] ?? '' );
		if ( '' !== $heading ) {
			$out[] = '<h2>' . $heading . '</h2>';
		}
		foreach ( omc_pub_lines( $section['paragraphs'] ?? [] ) as $line ) {
			$out[] = '<p>' . $line . '</p>';
		}
		$bullets = omc_pub_lines( $section['bullets'] ?? [] );
		if ( $bullets ) {
			$out[] = "<ul>\n<li>" . implode( "</li>\n<li>", $bullets ) . "</li>\n</ul>";
		}
		$table = (array) ( $section['table'] ?? [] );
		if ( ! empty( $table['rows'] ) || ! empty( $table['head'] ) ) {
			$html = '<table>';
			if ( ! empty( $table['caption'] ) ) {
				$html .= '<caption>' . omc_pub_inline( $table['caption'] ) . '</caption>';
			}
			if ( ! empty( $table['head'] ) ) {
				$html .= "\n<thead>\n<tr><th>" . implode( '</th><th>', omc_pub_lines( $table['head'] ) ) . "</th></tr>\n</thead>";
			}
			if ( ! empty( $table['rows'] ) ) {
				$html .= "\n<tbody>";
				foreach ( (array) $table['rows'] as $row ) {
					$cells = omc_pub_lines( $row );
					if ( $cells ) {
						$html .= "\n<tr><td>" . implode( '</td><td>', $cells ) . '</td></tr>';
					}
				}
				$html .= "\n</tbody>";
			}
			$out[] = $html . "\n</table>";
		}
		foreach ( (array) ( $section['faqs'] ?? [] ) as $faq ) {
			$question = omc_pub_inline( $faq['q'] ?? ( $faq['question'] ?? '' ) );
			$answer   = omc_pub_lines( $faq['a'] ?? ( $faq['answer'] ?? [] ) );
			if ( '' === $question || ! $answer ) {
				continue;
			}
			$out[] = '<h3>' . $question . '</h3>';
			foreach ( $answer as $line ) {
				$out[] = '<p>' . $line . '</p>';
			}
		}
		$cta = omc_pub_render_cta_link( (array) ( $section['cta'] ?? [] ) );
		if ( $cta ) {
			$out[] = $cta;
		}
	}

	$faqs = (array) ( $doc['faqs'] ?? [] );
	if ( $faqs ) {
		$printed = false;
		foreach ( $faqs as $faq ) {
			$question = omc_pub_inline( $faq['q'] ?? ( $faq['question'] ?? '' ) );
			$answer   = omc_pub_lines( $faq['a'] ?? ( $faq['answer'] ?? [] ) );
			if ( '' === $question || ! $answer ) {
				continue;
			}
			if ( ! $printed ) {
				$out[]   = '<h2>Questions, answered</h2>';
				$printed = true;
			}
			$out[] = '<h3>' . $question . '</h3>';
			foreach ( $answer as $line ) {
				$out[] = '<p>' . $line . '</p>';
			}
		}
	}

	$cta = (array) ( $doc['cta'] ?? [] );
	if ( $cta ) {
		$heading = omc_pub_inline( $cta['heading'] ?? '' );
		if ( '' !== $heading ) {
			$out[] = '<h2>' . $heading . '</h2>';
		}
		foreach ( omc_pub_lines( $cta['text'] ?? '' ) as $line ) {
			$out[] = '<p>' . $line . '</p>';
		}
		$link = omc_pub_render_cta_link( $cta );
		if ( $link ) {
			$out[] = $link;
		}
	}

	return implode( "\n\n", $out );
}

/* 0 ── Site identity */
omc_pub_option( 'blogname', 'Oops, Mine Co.' );
omc_pub_option( 'blogdescription', "One unexpected find. One 'oops, mine' moment at a time." );
omc_pub_option( 'timezone_string', 'America/Chicago' );
omc_pub_mod( 'truncate_logo_placeholder', false );      // show the full name as the text logo
omc_pub_mod( 'product_grid_width', 'boxed' );           // shop grid inside the 1170px container (see omc-pages.css)
omc_pub_mod( 'product_page_layout', 'layout-4' );       // product page: gallery + summary inside the container
foreach ( get_theme_mods() as $mod_key => $mod_value ) {  // drop the demo's "BEST SPECIAL OFFERS!" header text
	if ( is_string( $mod_value ) && stripos( $mod_value, 'BEST SPECIAL OFFERS' ) !== false ) {
		omc_pub_mod( $mod_key, '' );
		$log['cleared_demo_header_text'] = $mod_key;
	}
}

/* 1 ── Palette + logo (an empty logo mod = the brand PNGs bundled in the child theme, assets/img) */
$mods = [
	'accent_color'                   => '#B98B7E',
	'header_top_accent_color'        => '#B98B7E',
	'button_color'                   => '#3a2b26',   // brand ink (deep brown) — no black anywhere
	'button_color_dark'              => '#3a2b26',
	'button_color_hover'             => '#9c6f63',
	'text_color'                     => '#3a2b26',
	'header_top_color'               => '#3a2b26',
	'mobile_header_color'            => '#3a2b26',
	'top_menu_submenu_color'         => '#3a2b26',
	'sale_badge_color'               => '#9c6f63',   // rose-dark stands in for the usual red
	'new_badge_color'                => '#b98b7e',
	'featured_badge_color'           => '#b98b7e',
	'outofstock_badge_color'         => '#8a7d76',
	'star_rating_color'              => '#b98b7e',
	'to_top_button_color'            => '#b98b7e',
	'product_image_background_color' => '#f7f2ed',
	'store_notice_background_color'  => '#9c6f63',   // the theme default is a red bar
	'top_menu_submenu_accent_color'  => '#B98B7E',   // empty by default, falls back to the demo orange
	'top_menu_depth'                 => 'unlim',     // the mega menu needs three levels
	'logo'                           => '',
	'logo__url'                      => '',
	'remove_frontpage_logo_link'     => false,
];
foreach ( $mods as $k => $v ) {
	omc_pub_mod( $k, $v );
}
$log['theme_mods'] = array_keys( $mods );

/* 2 ── Announcement bar (theme "HTML block") */
$bar_html = '<p class="omc-announce"><span>New arrivals every week</span><span>Curated Korean &amp; Thai fashion</span><a href="' . esc_url( home_url( '/about-us/' ) ) . '">Our story</a></p>';
$bar      = omc_pub_upsert_post( [
	'post_type'    => 'html_block',
	'post_status'  => 'publish',
	'post_title'   => 'OMC Announcement bar',
	'post_name'    => 'omc-announcement',
	'post_content' => $bar_html,
] );
$bar_id = $bar['id'];
if ( $bar_id ) {
	omc_pub_mod( 'header_advert_bar_page', $bar_id );
}
$log['announcement_block'] = $bar_id;

/* 3 ── Home + About pages */
$demo_about = get_page_by_path( 'about-us' );
if ( $demo_about && (int) $demo_about->ID === 1729 ) { // demo page: free the slug, keep as draft
	omc_pub_note( 'page about-us (#1729): demo page moved to /about-us-demo/ (draft)' );
	if ( ! omc_pub_dry() ) {
		wp_update_post( [ 'ID' => 1729, 'post_name' => 'about-us-demo', 'post_status' => 'draft' ] );
	}
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

$about = omc_pub_upsert_post( [
	'post_type'     => 'page',
	'post_status'   => 'publish',
	'post_title'    => 'About Oops, Mine Co.',
	'post_name'     => 'about-us',
	'post_content'  => $about_content,
	'post_excerpt'  => 'Oops, Mine Co. is a curated boutique of Korean and Thai fashion — pieces chosen with intention, for the moment you see something and think: oops, mine. Read our story.',
	'page_template' => 'templates/page-about.php',
	'meta'          => [ 'omc_tagline' => 'Found with intention. Claimed on instinct.', '_header_height' => 'hide' ],
] );
$about_id    = $about['id'];
$log['about_page'] = [ 'id' => $about_id, 'url' => $about_id ? get_permalink( $about_id ) : null ];

$home = omc_pub_upsert_post( [
	'post_type'     => 'page',
	'post_status'   => 'publish',
	'post_title'    => 'Home',
	'post_name'     => 'home',
	'post_content'  => '',
	'post_excerpt'  => 'Curated Korean and Thai fashion — dresses, skirts, denim and accessories chosen with intention. Found with intention. Claimed on instinct.',
	'page_template' => 'templates/page-home.php',
] );
$home_id = $home['id'];
if ( $home_id ) {
	omc_pub_option( 'show_on_front', 'page' );
	omc_pub_option( 'page_on_front', $home_id );
}
$log['home_page'] = [ 'id' => $home_id, 'url' => home_url( '/' ) ];

/* 4 ── Content import: .ftp-sync/content/pages/*.json */
$content_dir = __DIR__ . '/content';
$plan        = [];
if ( is_readable( $content_dir . '/plan.json' ) ) {
	$plan = json_decode( (string) file_get_contents( $content_dir . '/plan.json' ), true );
	$plan = is_array( $plan ) ? $plan : [];
}

$content_log = [
	'files'      => 0,
	'pages'      => [ 'created' => 0, 'updated' => 0, 'unchanged' => 0 ],
	'journal'    => 0,
	'categories' => 0,
	'products'   => 0,
	'skipped'    => [],
];
$content_index = [];   // slug => [ id, type, post_type, title, category ]

/** Pages the store needs untouched, whatever a JSON file says. */
$protected_ids = array_filter( array_map( 'intval', [
	get_option( 'woocommerce_shop_page_id' ),
	get_option( 'woocommerce_cart_page_id' ),
	get_option( 'woocommerce_checkout_page_id' ),
	get_option( 'woocommerce_myaccount_page_id' ),
	get_option( 'page_on_front' ),
	get_option( 'page_for_posts' ),
] ) );
$protected_slugs = [ 'shop', 'cart', 'checkout', 'my-account', 'home', 'blog' ];

$templates = [
	'landing'   => 'templates/page-landing.php',
	'faq'       => 'templates/page-faq.php',
	'contact'   => 'templates/page-contact.php',
	'policy'    => 'templates/page-policy.php',
	'sizeguide' => 'templates/page-policy.php',
];

$style_notes_id = 0;
$files          = glob( $content_dir . '/pages/*.json' ) ?: [];
sort( $files );
foreach ( $files as $file ) {
	$content_log['files'] ++;
	$doc = json_decode( (string) file_get_contents( $file ), true );
	if ( ! is_array( $doc ) ) {
		$content_log['skipped'][ basename( $file ) ] = 'not valid JSON (' . json_last_error_msg() . ')';
		continue;
	}
	$slug  = sanitize_title( (string) ( $doc['slug'] ?? pathinfo( $file, PATHINFO_FILENAME ) ) );
	$title = trim( (string) ( $doc['title'] ?? '' ) );
	$type  = sanitize_key( (string) ( $doc['type'] ?? 'landing' ) );
	if ( '' === $slug || '' === $title ) {
		$content_log['skipped'][ basename( $file ) ] = 'missing slug or title';
		continue;
	}
	if ( ! isset( $templates[ $type ] ) && 'journal' !== $type ) {
		$content_log['skipped'][ basename( $file ) ] = "unknown type '{$type}'";
		continue;
	}
	if ( in_array( $slug, $protected_slugs, true ) ) {
		$content_log['skipped'][ basename( $file ) ] = "slug '{$slug}' belongs to a store page";
		continue;
	}
	$status = in_array( ( $doc['status'] ?? 'publish' ), [ 'publish', 'draft', 'pending', 'private' ], true ) ? $doc['status'] : 'publish';

	/* 4a ── the product category a landing page fronts */
	$category = ( isset( $doc['category'] ) && is_array( $doc['category'] ) && ! empty( $doc['category']['slug'] ) ) ? $doc['category'] : null;
	$term_id  = 0;
	if ( $category ) {
		$term_id = omc_pub_upsert_term( [
			'taxonomy'    => 'product_cat',
			'slug'        => $category['slug'],
			'name'        => $category['name'] ?? $title,
			'parent'      => $category['parent'] ?? 'women',
			'description' => (string) ( $category['description'] ?? ( $category['description_hint'] ?? '' ) ),
		] );
		if ( $term_id ) {
			$content_log['categories'] ++;
			$content_log['products'] += omc_pub_assign_products( $term_id, $category['assign_products'] ?? [] );
		}
	}

	/* 4b ── the page (or journal post) itself */
	$post_type = ( 'journal' === $type ) ? 'post' : 'page';
	$existing  = get_page_by_path( $slug, OBJECT, $post_type );
	if ( $existing && in_array( (int) $existing->ID, $protected_ids, true ) ) {
		$content_log['skipped'][ basename( $file ) ] = "page #{$existing->ID} is a store/front page";
		continue;
	}

	$categories = [];
	if ( 'journal' === $type ) {
		if ( ! $style_notes_id ) {
			$style_notes_id = omc_pub_upsert_term( [
				'taxonomy'    => 'category',
				'slug'        => 'style-notes',
				'name'        => 'Style notes',
				'description' => 'Styling guides and stories from the Oops, Mine Co. journal.',
			] );
		}
		if ( $style_notes_id ) {
			$categories[] = $style_notes_id;
		}
		$content_log['journal'] ++;
	}

	$excerpt = trim( (string) ( $doc['excerpt'] ?? ( $doc['meta_description'] ?? '' ) ) );
	$result  = omc_pub_upsert_post( [
		'post_type'     => $post_type,
		'post_name'     => $slug,
		'post_title'    => $title,
		'post_status'   => $status,
		'post_content'  => omc_pub_render_doc( $doc ),
		'post_excerpt'  => $excerpt,
		'page_template' => 'page' === $post_type ? $templates[ $type ] : '',
		'categories'    => $categories,
		'meta'          => [
			'_omc_landing'          => wp_json_encode( $doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			'_omc_meta_description' => trim( (string) ( $doc['meta_description'] ?? '' ) ),
			'_omc_seo_title'        => trim( (string) ( $doc['seo_title'] ?? '' ) ),
		],
	] );
	$content_log['pages'][ $result['action'] ] ++;
	if ( $result['id'] ) {
		omc_pub_strip_elementor( $result['id'] );   // Elementor pages ignore the page template
	}
	$content_index[ $slug ] = [
		'id'        => $result['id'],
		'type'      => $type,
		'post_type' => $post_type,
		'title'     => $title,
		'status'    => $status,
		'category'  => $category ? sanitize_title( $category['slug'] ) : '',
		'term_id'   => $term_id,
	];
}

/* 4c ── occasion categories for the mega menu (from plan.json; otherwise matched by product title) */
$occasions = [];
foreach ( (array) ( $plan['occasion_categories'] ?? [] ) as $occasion ) {
	if ( empty( $occasion['slug'] ) ) {
		continue;
	}
	$occasions[ sanitize_title( $occasion['slug'] ) ] = [
		'name'     => $occasion['name'] ?? ucwords( str_replace( '-', ' ', $occasion['slug'] ) ),
		'products' => (array) ( $occasion['assign_products'] ?? [] ),
		'desc'     => (string) ( $occasion['description'] ?? '' ),
	];
}
if ( ! $occasions ) {
	// Fallback when plan.json carries no occasion list: match the demo product titles.
	$patterns = [
		'casual'       => [ 'name' => 'Casual', 'match' => '/jeans|shorts|tee|t-shirt|sneaker|hoodie|pullover|leggings/i' ],
		'workwear'     => [ 'name' => 'Workwear', 'match' => '/tunic|pleated|midi skirt|blazer|suit|pump|shirt/i' ],
		'evening'      => [ 'name' => 'Evening', 'match' => '/gown|sequin|lamé|lame|satin|embellished|metallic/i' ],
		'wedding-guest' => [ 'name' => 'Wedding Guest', 'match' => '/gown|halter|maxi|satin|embellished|sandal|pump/i' ],
	];
	$catalog  = get_posts( [ 'post_type' => 'product', 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids' ] );
	foreach ( $patterns as $slug => $spec ) {
		$ids = [];
		foreach ( $catalog as $product_id ) {
			if ( preg_match( $spec['match'], (string) get_the_title( $product_id ) ) ) {
				$ids[] = $product_id;
			}
			if ( count( $ids ) >= 10 ) {
				break;
			}
		}
		$occasions[ $slug ] = [ 'name' => $spec['name'], 'products' => $ids, 'desc' => '' ];
	}
}
$occasion_terms = [];
foreach ( $occasions as $slug => $occasion ) {
	$term_id = omc_pub_upsert_term( [
		'taxonomy'    => 'product_cat',
		'slug'        => $slug,
		'name'        => $occasion['name'],
		'parent'      => 'women',
		'description' => $occasion['desc'],
	] );
	if ( $term_id ) {
		$occasion_terms[ $slug ] = $term_id;
		$content_log['products'] += omc_pub_assign_products( $term_id, $occasion['products'] );
	}
}
$content_log['occasions'] = array_keys( $occasion_terms );

/* 4d ── the demo's Elementor showcase pages: out of the way, never deleted */
$drafted = [];
foreach ( [ 'home-1', 'home-2', 'home-3', 'home-4', 'brands-1', 'brands-2', 'featured', 'newest', 'on-sale', 'popular' ] as $demo_slug ) {
	$demo = get_page_by_path( $demo_slug );
	if ( ! $demo || 'draft' === $demo->post_status || in_array( (int) $demo->ID, $protected_ids, true ) ) {
		continue;
	}
	$drafted[] = $demo_slug;
	omc_pub_note( "page {$demo_slug} (#{$demo->ID}): demo page → draft" );
	if ( ! omc_pub_dry() ) {
		wp_update_post( [ 'ID' => $demo->ID, 'post_status' => 'draft' ] );
	}
}
$content_log['demo_pages_drafted'] = $drafted;
// slug → "#id type" so the integrator can see what each JSON file became.
$content_log['imported'] = [];
foreach ( $content_index as $index_slug => $entry ) {
	$content_log['imported'][ $index_slug ] = '#' . $entry['id'] . ' ' . $entry['type'] . ( $entry['category'] ? ' → product_cat ' . $entry['category'] : '' );
}
$log['content'] = $content_log;

/* 5 ── Main Menu: the theme's mega menu
 *
 * Mega-menu meta the parent theme reads (wp-content/themes/moderno/includes/megamenu/mega_menu.php):
 *   _menu_item_columns    1–4 = submenu columns, 5 = fullwidth  → class c-top-menu__submenu--columns-N
 *   _menu_item_expand     1   = show the third level inside the column instead of on hover
 *   _menu_item_content    default|html_block|product_category|product_attr (anything but "default"
 *                         REPLACES the hand-made children, so this build keeps it unset)
 *   _menu_item_html_block / _menu_item_product_category / _menu_item_product_attr  the source for those
 *   _menu_item_badge_text / _menu_item_badge_color   small badge, second level and deeper
 * The theme deletes those metas on every wp_update_nav_menu_item() call, so they are written after
 * the item exists. A "#" URL is turned into a <span> by the theme: that is the column heading.
 */
$menu    = wp_get_nav_menu_object( 'Main Menu' );
$menu_id = $menu ? (int) $menu->term_id : 0;
if ( ! $menu_id ) {
	omc_pub_note( 'menu Main Menu: created' );
	if ( ! omc_pub_dry() ) {
		$menu_id = (int) wp_create_nav_menu( 'Main Menu' );
	}
}

/** A product category item, or null when the term does not exist. */
$menu_term = static function ( $slug, $label = '' ) {
	$term = get_term_by( 'slug', sanitize_title( $slug ), 'product_cat' );
	if ( ! $term || is_wp_error( $term ) ) {
		return null;
	}
	return [ 'type' => 'taxonomy', 'object' => 'product_cat', 'object_id' => (int) $term->term_id, 'title' => $label ?: $term->name ];
};
/** A page/post item, or null when the post is missing or not published. */
$menu_post = static function ( $slug, $label = '', $post_type = 'page' ) {
	$post = get_page_by_path( $slug, OBJECT, $post_type );
	if ( ! $post || 'publish' !== $post->post_status ) {
		return null;
	}
	return [ 'type' => 'post_type', 'object' => $post_type, 'object_id' => (int) $post->ID, 'title' => $label ?: get_the_title( $post ) ];
};
/** A plain link. */
$menu_link = static function ( $label, $url ) {
	return [ 'type' => 'custom', 'url' => $url, 'title' => $label ];
};
/** The landing page for a style when it is published, otherwise its product category. */
$menu_style = static function ( $page_slugs, $term_slugs, $label ) use ( $menu_post, $menu_term ) {
	foreach ( (array) $page_slugs as $slug ) {
		$item = $menu_post( $slug, $label );
		if ( $item ) {
			return $item;
		}
	}
	foreach ( (array) $term_slugs as $slug ) {
		$item = $menu_term( $slug, $label );
		if ( $item ) {
			return $item;
		}
	}
	return null;
};

$shop_url         = function_exists( 'omc_shop_url' ) ? omc_shop_url() : ( function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/shop/' ) );
$new_arrivals_url = function_exists( 'omc_new_arrivals_url' ) ? omc_new_arrivals_url() : add_query_arg( 'orderby', 'date', $shop_url );
$blog_page        = (int) get_option( 'page_for_posts' );
$journal_item     = $blog_page ? [ 'type' => 'post_type', 'object' => 'page', 'object_id' => $blog_page, 'title' => 'Journal' ] : $menu_link( 'Journal', home_url( '/blog/' ) );

/* Column 1 — the catalogue itself */
$column_category = array_values( array_filter( [
	$menu_term( 'dresses' ),
	$menu_term( 'skirts' ),
	$menu_term( 'jeans_and_denim', 'Jeans & Denim' ),
	$menu_term( 'lingerie' ),
	$menu_term( 'shoes_and_accessories', 'Shoes & Accessories' ),
	$menu_link( 'New arrivals', $new_arrivals_url ) + [ 'meta' => [ '_menu_item_badge_text' => 'New', '_menu_item_badge_color' => '#b98b7e' ] ],
	$menu_link( 'Shop all', $shop_url ),
] ) );

/* Column 2 — the style landing pages written from the keyword plan (only the ones that exist) */
$style_order = [
	[ [ 'korean-fashion' ], [ 'korean-fashion' ], 'Korean Fashion' ],
	[ [ 'thai-fashion' ], [ 'thai-fashion' ], 'Thai Fashion' ],
	[ [ 'asian-fashion' ], [ 'asian-fashion' ], 'Asian Fashion' ],
	[ [ 'kpop-fashion' ], [ 'kpop-fashion' ], 'K-Pop Inspired' ],
	[ [ 'petite-clothes-for-women', 'petite-dresses' ], [ 'petite-clothing', 'petite-dresses' ], 'Petite' ],
	[ [ 'chic-boho-clothes', 'chic-style' ], [ 'chic-boho', 'chic-style' ], 'Chic & Boho' ],
	[ [ 'cute-fashion' ], [ 'cute-fashion' ], 'Cute Everyday' ],
	[ [ 'statement-pieces' ], [ 'statement-pieces' ], 'Statement Pieces' ],
	[ [ 'affordable-luxury-clothes' ], [ 'affordable-luxury' ], 'Affordable Luxury' ],
	[ [ 'korean-winter-fashion' ], [ 'korean-winter-fashion' ], 'Winter Korean' ],
];
$column_style = [];
foreach ( $style_order as [ $pages, $terms, $label ] ) {
	$item = $menu_style( $pages, $terms, $label );
	if ( $item && count( $column_style ) < 8 ) {
		$column_style[] = $item;
	}
}

/* Column 3 — occasions (product categories created above) */
$column_occasion = array_values( array_filter( [
	$menu_term( 'casual' ),
	$menu_term( 'workwear' ),
	$menu_term( 'evening' ),
	$menu_term( 'wedding-guest', 'Wedding guest' ),
] ) );

/* Column 4 — the journal landing plus the first style guides that exist */
// array_merge, not +: the blog item already has a title ("Journal") and the column head uses that word.
$column_journal = [ array_merge( $journal_item, [ 'title' => 'All style notes' ] ) ];
foreach ( (array) ( $plan['pages'] ?? [] ) as $planned ) {
	if ( count( $column_journal ) >= 4 ) {
		break;
	}
	if ( ( $planned['type'] ?? '' ) !== 'journal' || empty( $planned['slug'] ) ) {
		continue;
	}
	$item = $menu_post( sanitize_title( $planned['slug'] ), '', 'post' );
	if ( $item ) {
		$column_journal[] = $item;
	}
}

$tree = [];
$shop_children = array_values( array_filter( [
	$column_category ? $menu_link( 'Shop by category', '#' ) + [ 'children' => $column_category ] : null,
	$column_style ? $menu_link( 'Shop by style', '#' ) + [ 'children' => $column_style ] : null,
	$column_occasion ? $menu_link( 'Shop by occasion', '#' ) + [ 'children' => $column_occasion ] : null,
	count( $column_journal ) > 1 ? $menu_link( 'Journal', '#' ) + [ 'children' => $column_journal ] : null,
] ) );
$tree[] = $menu_link( 'Shop', $shop_url ) + [
	'meta'     => [ '_menu_item_columns' => (string) max( 1, min( 4, count( $shop_children ) ) ), '_menu_item_expand' => '1' ],
	'children' => $shop_children,
];
$tree[] = $menu_link( 'New Arrivals', $new_arrivals_url );
foreach ( [
	[ [ 'korean-fashion' ], [ 'korean-fashion' ], 'Korean Fashion' ],
	[ [ 'thai-fashion' ], [ 'thai-fashion' ], 'Thai Fashion' ],
	[ [ 'petite-clothes-for-women', 'petite-dresses' ], [ 'petite-clothing', 'petite-dresses' ], 'Petite' ],
] as [ $pages, $terms, $label ] ) {
	$item = $menu_style( $pages, $terms, $label );
	if ( $item ) {
		$tree[] = $item;
	}
}
// Style videos, when import-videos.php has created the hub.
$videos_item = $menu_post( 'videos', 'Videos' );
if ( $videos_item ) {
	$tree[] = $videos_item;
}
$tree[] = $journal_item;
if ( $about_id ) {
	$tree[] = [ 'type' => 'post_type', 'object' => 'page', 'object_id' => $about_id, 'title' => 'About' ];
}
$contact = $menu_post( 'contacts', 'Contact' );
if ( $contact ) {
	$tree[] = $contact;
}

$count_tree = static function ( array $nodes ) use ( &$count_tree ) {
	$n = 0;
	foreach ( $nodes as $node ) {
		$n += 1 + $count_tree( $node['children'] ?? [] );
	}
	return $n;
};
$expected  = $count_tree( $tree );
$signature = md5( wp_json_encode( $tree ) );
$current   = $menu_id ? (array) wp_get_nav_menu_items( $menu_id, [ 'post_status' => 'publish' ] ) : [];
$rebuild   = $omc_force_menu || ! $menu_id || get_option( 'omc_menu_signature' ) !== $signature || count( $current ) !== $expected;

if ( $rebuild ) {
	omc_pub_note( "menu Main Menu: rebuilt (" . count( $current ) . " → {$expected} items)" );
	if ( ! omc_pub_dry() && $menu_id ) {
		foreach ( $current as $item ) {
			wp_delete_post( $item->ID, true );
		}
		$build = static function ( array $nodes, $parent ) use ( &$build, $menu_id ) {
			foreach ( $nodes as $node ) {
				$args = [
					'menu-item-title'     => $node['title'],
					'menu-item-status'    => 'publish',
					'menu-item-parent-id' => $parent,
					'menu-item-type'      => $node['type'],
				];
				if ( 'custom' === $node['type'] ) {
					$args['menu-item-url'] = $node['url'];
				} else {
					$args['menu-item-object']    = $node['object'];
					$args['menu-item-object-id'] = $node['object_id'];
				}
				$item_id = wp_update_nav_menu_item( $menu_id, 0, $args );
				if ( is_wp_error( $item_id ) || ! $item_id ) {
					continue;
				}
				// After the item exists: the theme's own hook deletes these on every save.
				foreach ( (array) ( $node['meta'] ?? [] ) as $key => $value ) {
					update_post_meta( $item_id, $key, $value );
				}
				$build( $node['children'] ?? [], (int) $item_id );
			}
		};
		$build( $tree, 0 );
		update_option( 'omc_menu_signature', $signature, false );
	}
}
if ( $menu_id ) {
	$locations = get_theme_mod( 'nav_menu_locations', [] );
	if ( (int) ( $locations['primary'] ?? 0 ) !== $menu_id || (int) ( $locations['mobile'] ?? 0 ) !== $menu_id ) {
		$locations['primary'] = $menu_id;
		$locations['mobile']  = $menu_id;
		omc_pub_mod( 'nav_menu_locations', $locations );
	}
}
$log['menu'] = [
	'id'      => $menu_id,
	'items'   => $expected,
	'top'     => array_map( static fn( $n ) => $n['title'], $tree ),
	'columns' => array_map( static fn( $n ) => $n['title'] . ' (' . count( $n['children'] ?? [] ) . ')', $shop_children ),
	'action'  => $rebuild ? 'rebuilt' : 'unchanged',
];

/* 6 ── Store, cart, product page and contact settings */
$store_mods = [
	// Slide-out cart with a free-shipping progress bar (see the WHD cart assets).
	'popup_cart_layout'            => 'sidebar',
	'popup_cart_modal'             => 1,
	'popup_cart_auto_open_desktop' => 1,
	'popup_cart_auto_open_mobile'  => 1,
	// Product page
	'related_product_header'       => 'You might also like',
	'recently_enabled'             => 1,
	'recently_product_show'        => 1,
	'recently_product_header'      => 'Recently viewed',
	'product_page_custom_html'     => '',   // the child theme prints its own trust badges
	// Contact details (the demo's belong to the theme author)
	'header_email'                 => 'hello@oopsmineco.com',
	'header_phone'                 => '346-847-6606',
	'header_hours'                 => 'Mon–Fri 8:30am–5pm · Sat 10am–5pm CT',
	'header_address'               => 'Online boutique · Houston, Texas',
	'facebook'                     => 'https://www.facebook.com/p/Oops-Mine-Co-100090677450840/',
];
foreach ( $store_mods as $k => $v ) {
	omc_pub_mod( $k, $v );
}
// Placeholder social links: clear "#" rather than invent a handle. Real URLs are left alone.
foreach ( [ 'instagram', 'tiktok', 'pinterest', 'twitter', 'youtube' ] as $social ) {
	if ( '#' === get_theme_mod( $social ) ) {
		omc_pub_mod( $social, '' );
	}
}
$log['store_mods'] = array_keys( $store_mods );

$wc_options = [
	'woocommerce_default_country'    => 'US:TX',
	'woocommerce_store_address'      => '12712 W Lake Houston Pkwy, Ste B-4014',
	'woocommerce_store_city'         => 'Houston',
	'woocommerce_store_postcode'     => '77044',
	'woocommerce_email_from_name'    => 'Oops, Mine Co.',
	'woocommerce_email_from_address' => 'hello@oopsmineco.com',
];
foreach ( $wc_options as $k => $v ) {
	omc_pub_option( $k, $v );
}
$log['woocommerce'] = array_keys( $wc_options );

/* Point the legal-page settings at the pages we actually publish. WooCommerce shipped a dangling
   refund page id and used the returns policy as the checkout terms page, so the "I agree to the
   terms" link went to the wrong document. */
$omc_legal = [
	'woocommerce_terms_page_id'         => 'terms-and-conditions',
	'woocommerce_refund_returns_page_id' => 'refund_returns',
	'wp_page_for_privacy_policy'        => 'privacy-policy',
];
foreach ( $omc_legal as $omc_opt => $omc_slug ) {
	$omc_legal_page = get_page_by_path( $omc_slug );
	if ( $omc_legal_page ) {
		omc_pub_option( $omc_opt, (string) $omc_legal_page->ID );
	} else {
		omc_pub_note( "option $omc_opt: skipped, no published page /$omc_slug/" );
	}
}

/* 7 ── WHD plugin: activate + configure */
if ( ! is_plugin_active( 'whd/whd.php' ) ) {
	omc_pub_note( 'plugin whd: activated' );
	$r = omc_pub_dry() ? null : activate_plugin( 'whd/whd.php' );
	$log['plugin'] = is_wp_error( $r ) ? 'ERROR: ' . $r->get_error_message() : 'activated';
} else {
	$log['plugin'] = 'active';
}

/* 7b ── WHD Variation Tiers: activate (display settings keep their defaults; tiers are per product) */
if ( ! is_plugin_active( 'whd-variations/whd-variations.php' ) ) {
	omc_pub_note( 'plugin whd-variations: activated' );
	$r = omc_pub_dry() ? null : activate_plugin( 'whd-variations/whd-variations.php' );
	$log['plugin_variations'] = is_wp_error( $r ) ? 'ERROR: ' . $r->get_error_message() : 'activated';
} else {
	$log['plugin_variations'] = 'active';
}

if ( class_exists( 'WHD_Popups' ) ) {
	// Merge, never clobber: WHD → Settings holds keys this script knows nothing about.
	$whd = (array) get_option( 'whd_settings', [] );
	omc_pub_option( 'whd_settings', array_merge( $whd, [
		// The plugin's own default is 1 (keep popups and tracking off the owner's own sessions).
		// Writing 0 here would silently flip that off on a database that has never saved these settings.
		'exclude_admins'          => $whd['exclude_admins'] ?? 1,
		'cart_delay_hours'        => $whd['cart_delay_hours'] ?? 1,
		'cart_coupon'             => 'OOPS10',   // {coupon_code}
		'exit_coupon'             => 'STAY15',   // {exit_coupon}, the exit-intent save
		// 0 = the cart progress bar stays hidden. The store has no free-shipping method configured,
		// so a threshold here would promise shoppers something checkout cannot honour. The owner sets
		// a real number in WHD → Settings once a free-shipping zone exists in WooCommerce → Shipping.
		'free_shipping_threshold' => $whd['free_shipping_threshold'] ?? 0,
		'from_name'               => 'Oops, Mine Co.',
		'from_email'              => $whd['from_email'] ?? get_option( 'admin_email' ),
	] ) );

	// One-off: replace the first-round demo designs with the shipped brand defaults.
	if ( method_exists( 'WHD_Popups', 'reset_to_defaults' ) && ! get_option( 'omc_popups_v2' ) ) {
		omc_pub_note( 'whd popups: reset to the v2 brand defaults (once)' );
		if ( ! omc_pub_dry() ) {
			WHD_Popups::reset_to_defaults();
			update_option( 'omc_popups_v2', 1, false );
		}
		$log['whd_popups_v2'] = 'reset';
	}

	foreach ( [ 'exit_intent', 'welcome' ] as $pid ) {
		$d = WHD_Popups::get( $pid );
		if ( ! is_array( $d ) || ! empty( $d['settings']['enabled'] ) ) {
			continue;
		}
		omc_pub_note( "whd popup {$pid}: enabled" );
		if ( ! omc_pub_dry() ) {
			$d['settings']['enabled'] = 1;
			WHD_Popups::save( $pid, $d );
		}
	}
	foreach ( [ 'customer_processing_order', 'customer_completed_order', 'customer_new_account', 'abandoned_cart', 'abandoned_cart_2', 'abandoned_cart_3', 'welcome_subscriber' ] as $eid ) {
		$d = WHD_Emails::get( $eid );
		if ( ! is_array( $d ) || ! empty( $d['settings']['enabled'] ) ) {
			continue;
		}
		omc_pub_note( "whd email {$eid}: enabled" );
		if ( ! omc_pub_dry() ) {
			$d['settings']['enabled'] = 1;
			WHD_Emails::save( $eid, $d );
		}
	}
	$log['whd'] = [ 'popups' => [ 'exit_intent', 'welcome' ], 'emails' => 5, 'cart_coupon' => 'OOPS10', 'exit_coupon' => 'STAY15', 'free_shipping' => 75 ];
}

/* 8 ── The coupons the popups promise */
$coupons = [
	'OOPS10' => [ 'amount' => 10, 'description' => 'Welcome / newsletter popup offer (WHD).' ],
	'STAY15' => [ 'amount' => 15, 'description' => 'Exit-intent offer (WHD)' ],
];
$log['coupons'] = [];
if ( class_exists( 'WooCommerce' ) ) {
	foreach ( $coupons as $code => $spec ) {
		if ( wc_get_coupon_id_by_code( $code ) ) {
			$log['coupons'][ $code ] = 'exists';
			continue;
		}
		omc_pub_note( "coupon {$code}: created ({$spec['amount']}% off)" );
		$log['coupons'][ $code ] = 'created';
		if ( omc_pub_dry() ) {
			continue;
		}
		$coupon = new WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_discount_type( 'percent' );
		$coupon->set_amount( $spec['amount'] );
		$coupon->set_usage_limit_per_user( 1 );
		$coupon->set_individual_use( true );
		$coupon->set_description( $spec['description'] );
		$coupon->save();
	}
}

/* Moderno concatenates the Customizer palette into wp-content/uploads/moderno/min.css and caches it
   behind `ideapark_styles_hash`. That hash is built from file mtimes and the theme version only — no
   theme-mod input — so writing mods from the CLI leaves the stale sheet in place and live keeps
   serving the demo palette. The theme busts it in ideapark_after_customizer_save(); do the same here,
   and the next front-end request rebuilds the sheet. */
if ( ! omc_pub_dry() ) {
	$omc_hash_opts = [ 'ideapark_styles_hash', 'ideapark_editor_styles_hash', 'ideapark_google_font_uri' ];
	if ( function_exists( 'ideapark_active_languages' ) ) {
		foreach ( (array) ideapark_active_languages() as $omc_lang_code => $omc_lang ) {
			$omc_hash_opts[] = 'ideapark_styles_hash_' . $omc_lang_code;
			$omc_hash_opts[] = 'ideapark_google_font_uri_' . $omc_lang_code;
		}
	}
	$omc_busted = 0;
	foreach ( $omc_hash_opts as $omc_hash_opt ) {
		if ( false !== get_option( $omc_hash_opt, false ) ) {
			delete_option( $omc_hash_opt );
			$omc_busted++;
		}
	}
	omc_pub_note( "theme css cache: $omc_busted hash option(s) cleared; min.css rebuilds on the next request" );
	$log['css_cache_busted'] = $omc_busted;
}

omc_pub_post_thumbnails();
$log['journal_thumbnails'] = $GLOBALS['omc_pub_log_thumbs'] ?? 0;
omc_pub_tidy_categories();
$log['uncategorized_removed'] = $GLOBALS['omc_pub_log_cats'] ?? 0;

if ( ! omc_pub_dry() ) {
	flush_rewrite_rules();
}
$log['changed'] = count( $GLOBALS['omc_pub_changes'] );
$log['changes'] = $GLOBALS['omc_pub_changes'];
echo json_encode( $log, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), "\n";
