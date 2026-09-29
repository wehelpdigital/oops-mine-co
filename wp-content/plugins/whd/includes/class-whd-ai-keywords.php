<?php
/**
 * The keyword list the AI module writes against.
 *
 * A table rather than an option: a real keyword set for a shop this size runs to several hundred
 * rows with volume and difficulty attached, and it needs to be searched, sorted and counted. Rows
 * come from two places — typed in by hand, or imported from the CSV an SEO tool exports.
 *
 * Nothing here talks to a model. Its whole job is to know which terms exist, which ones suit a
 * given page, and how often each has been used, so that the same three keywords do not end up on
 * forty products.
 *
 * @package whd
 */

defined( 'ABSPATH' ) || exit;

final class WHD_AI_Keywords {

	const DB_VERSION = '1.0';

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'whd_keywords';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$table   = self::table();

		// keyword is indexed at 191 characters: the longest a utf8mb4 unique index can hold.
		dbDelta(
			"CREATE TABLE $table (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				keyword VARCHAR(191) NOT NULL,
				volume INT UNSIGNED NOT NULL DEFAULT 0,
				difficulty INT UNSIGNED NOT NULL DEFAULT 0,
				intent VARCHAR(40) NOT NULL DEFAULT '',
				tags VARCHAR(191) NOT NULL DEFAULT '',
				used INT UNSIGNED NOT NULL DEFAULT 0,
				last_used DATETIME NULL,
				created DATETIME NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY keyword (keyword),
				KEY volume (volume),
				KEY used (used)
			) $charset;"
		);
		update_option( 'whd_keywords_db', self::DB_VERSION, false );
	}

	/** Create the table on demand — the plugin may have been active before this module existed. */
	public static function maybe_install() {
		if ( get_option( 'whd_keywords_db' ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/* ─────────────────────────── reading ─────────────────────────── */

	/**
	 * @param array $args search, tag, orderby (volume|keyword|used|created), order, limit, offset.
	 */
	public static function all( array $args = [] ) {
		global $wpdb;
		$args = wp_parse_args( $args, [
			'search'  => '',
			'tag'     => '',
			'orderby' => 'volume',
			'order'   => 'DESC',
			'limit'   => 100,
			'offset'  => 0,
		] );

		$where  = [ '1=1' ];
		$params = [];
		if ( '' !== $args['search'] ) {
			$where[]  = 'keyword LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $args['search'] ) . '%';
		}
		if ( '' !== $args['tag'] ) {
			$where[]  = 'tags LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $args['tag'] ) . '%';
		}

		$orderby = in_array( $args['orderby'], [ 'volume', 'keyword', 'used', 'created', 'difficulty' ], true ) ? $args['orderby'] : 'volume';
		$order   = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

		$sql = 'SELECT * FROM ' . self::table() . ' WHERE ' . implode( ' AND ', $where )
			. " ORDER BY $orderby $order, keyword ASC LIMIT %d OFFSET %d";
		$params[] = max( 1, (int) $args['limit'] );
		$params[] = max( 0, (int) $args['offset'] );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name and ORDER BY are whitelisted above.
		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
	}

	public static function count( $search = '', $tag = '' ) {
		global $wpdb;
		$where  = [ '1=1' ];
		$params = [];
		if ( '' !== $search ) {
			$where[]  = 'keyword LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}
		if ( '' !== $tag ) {
			$where[]  = 'tags LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $tag ) . '%';
		}
		$sql = 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE ' . implode( ' AND ', $where );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is ours; the rest is prepared.
		return (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_var( $sql ) );
	}

	/** Every distinct tag in use, for the filter dropdown. */
	public static function tags() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is ours.
		$rows = $wpdb->get_col( 'SELECT DISTINCT tags FROM ' . self::table() . " WHERE tags <> ''" );
		$out  = [];
		foreach ( $rows as $row ) {
			foreach ( explode( ',', $row ) as $tag ) {
				$tag = trim( $tag );
				if ( '' !== $tag && ! in_array( $tag, $out, true ) ) {
					$out[] = $tag;
				}
			}
		}
		sort( $out );
		return $out;
	}

	/* ─────────────────────────── writing ─────────────────────────── */

	/**
	 * Add or update one keyword.
	 *
	 * An existing row keeps its usage history; only the numbers and tags are refreshed, so
	 * re-importing a newer export never resets what has already been written.
	 */
	public static function upsert( $keyword, array $fields = [] ) {
		global $wpdb;
		$keyword = trim( wp_strip_all_tags( (string) $keyword ) );
		$keyword = preg_replace( '/\s+/', ' ', $keyword );
		if ( '' === $keyword || mb_strlen( $keyword ) > 191 ) {
			return 0;
		}

		$row = [
			'keyword'    => $keyword,
			'volume'     => max( 0, (int) ( $fields['volume'] ?? 0 ) ),
			'difficulty' => max( 0, min( 100, (int) ( $fields['difficulty'] ?? 0 ) ) ),
			'intent'     => sanitize_text_field( (string) ( $fields['intent'] ?? '' ) ),
			'tags'       => self::clean_tags( $fields['tags'] ?? '' ),
		];

		$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE keyword = %s', $keyword ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( $id ) {
			$wpdb->update( self::table(), $row, [ 'id' => $id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return $id;
		}
		$row['created'] = current_time( 'mysql' );
		$wpdb->insert( self::table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->insert_id;
	}

	/** One keyword per line, optionally "keyword, volume, difficulty". Returns how many landed. */
	public static function add_lines( $text, $tags = '' ) {
		$added = 0;
		foreach ( preg_split( '/\R/', (string) $text ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$parts   = array_map( 'trim', explode( ',', $line ) );
			$keyword = array_shift( $parts );
			if ( self::upsert( $keyword, [
				'volume'     => isset( $parts[0] ) ? (int) preg_replace( '/[^0-9]/', '', $parts[0] ) : 0,
				'difficulty' => isset( $parts[1] ) ? (int) preg_replace( '/[^0-9]/', '', $parts[1] ) : 0,
				'tags'       => $tags,
			] ) ) {
				$added++;
			}
		}
		return $added;
	}

	/**
	 * Import a CSV export.
	 *
	 * Column names vary between tools, so the header is matched loosely rather than by position —
	 * Ubersuggest writes "Keyword, Volume, SD", Ahrefs "Keyword, Volume, KD", Semrush "Keyword,
	 * Search Volume, Keyword Difficulty". A file with no recognisable header is read as one
	 * keyword per line, using the first column.
	 *
	 * @param string $path Local file to read.
	 * @param string $tags Tag applied to every row that has none of its own in the file.
	 * @return array [ added, skipped, columns ]
	 */
	public static function import_csv( $path, $tags = '' ) {
		$handle = @fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $handle ) {
			return new WP_Error( 'whd_csv', __( 'That file could not be opened.', 'whd' ) );
		}

		$map     = null;
		$added   = 0;
		$skipped = 0;
		$columns = [];

		while ( false !== ( $row = fgetcsv( $handle, 0, ',' ) ) ) {
			if ( ! $row || ( 1 === count( $row ) && '' === trim( (string) $row[0] ) ) ) {
				continue;
			}
			if ( null === $map ) {
				$map     = self::map_columns( $row );
				$columns = $row;
				if ( null !== $map['keyword'] ) {
					continue; // it really was a header row
				}
				$map = [ 'keyword' => 0, 'volume' => null, 'difficulty' => null, 'intent' => null, 'tags' => null ];
			}

			$keyword = trim( (string) ( $row[ $map['keyword'] ] ?? '' ) );
			if ( '' === $keyword ) {
				$skipped++;
				continue;
			}
			$number = static function ( $index ) use ( $row ) {
				if ( null === $index || ! isset( $row[ $index ] ) ) {
					return 0;
				}
				return (int) preg_replace( '/[^0-9]/', '', (string) $row[ $index ] );
			};

			// A tag in the file wins; the import-wide tag is the fallback for files without one.
			$row_tags = null !== $map['tags'] ? trim( (string) ( $row[ $map['tags'] ] ?? '' ) ) : '';

			if ( self::upsert( $keyword, [
				'volume'     => $number( $map['volume'] ),
				'difficulty' => $number( $map['difficulty'] ),
				'intent'     => null !== $map['intent'] ? (string) ( $row[ $map['intent'] ] ?? '' ) : '',
				'tags'       => '' !== $row_tags ? $row_tags : $tags,
			] ) ) {
				$added++;
			} else {
				$skipped++;
			}
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		return [
			'added'   => $added,
			'skipped' => $skipped,
			'columns' => $columns,
		];
	}

	/** Work out which column is which from a header row. Null means "not in this file". */
	private static function map_columns( array $header ) {
		$map  = [ 'keyword' => null, 'volume' => null, 'difficulty' => null, 'intent' => null, 'tags' => null ];
		$want = [
			'keyword'    => [ 'keyword', 'keywords', 'query', 'term', 'search term' ],
			'volume'     => [ 'volume', 'search volume', 'vol', 'searches', 'avg monthly searches', 'monthly searches' ],
			'difficulty' => [ 'sd', 'kd', 'difficulty', 'keyword difficulty', 'seo difficulty', 'competition' ],
			'intent'     => [ 'intent', 'search intent' ],
			'tags'       => [ 'tags', 'tag', 'group', 'page', 'cluster', 'topic' ],
		];
		foreach ( $header as $i => $cell ) {
			$cell = strtolower( trim( preg_replace( '/[^A-Za-z ]/', '', (string) $cell ) ) );
			foreach ( $want as $field => $names ) {
				if ( null === $map[ $field ] && in_array( $cell, $names, true ) ) {
					$map[ $field ] = $i;
				}
			}
		}
		return $map;
	}

	public static function delete( array $ids ) {
		global $wpdb;
		$ids = array_filter( array_map( 'absint', $ids ) );
		if ( ! $ids ) {
			return 0;
		}
		$in = implode( ',', $ids );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $in is a list of absints.
		return (int) $wpdb->query( 'DELETE FROM ' . self::table() . " WHERE id IN ($in)" );
	}

	public static function clear() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is ours.
		return (int) $wpdb->query( 'TRUNCATE TABLE ' . self::table() );
	}

	/** Record that these keywords went into a page, so the suggester can spread the load. */
	public static function mark_used( $keywords ) {
		global $wpdb;
		foreach ( WHD_AI::clean_keywords( $keywords ) as $keyword ) {
			$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
				'UPDATE ' . self::table() . ' SET used = used + 1, last_used = %s WHERE keyword = %s',
				current_time( 'mysql' ),
				$keyword
			) );
		}
	}

	private static function clean_tags( $tags ) {
		$parts = is_array( $tags ) ? $tags : explode( ',', (string) $tags );
		$parts = array_filter( array_map( 'sanitize_text_field', array_map( 'trim', $parts ) ) );
		return mb_substr( implode( ',', array_unique( $parts ) ), 0, 191 );
	}

	/* ─────────────────────────── suggesting ─────────────────────────── */

	/**
	 * The keywords that suit a particular page.
	 *
	 * Scored, not searched. A keyword is worth more the more of it the page actually covers: two
	 * words out of two beats one word out of four, so "korean fashion" wins over "korean fashion
	 * men's clothing" on a page about Korean dresses. Volume nudges, difficulty pushes back, and
	 * having been used already costs a lot — without that last part every product gets handed the
	 * same head term and forty pages compete with each other.
	 *
	 * @param int $post_id
	 * @param int $limit
	 * @return array Keyword strings, best first.
	 */
	public static function suggest( $post_id, $limit = 8 ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return [];
		}

		$context = [ $post->post_title ];
		foreach ( [ 'product_cat', 'product_tag', 'category', 'post_tag' ] as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}
			$terms = wp_get_post_terms( $post_id, $taxonomy, [ 'fields' => 'names' ] );
			if ( $terms && ! is_wp_error( $terms ) ) {
				$context = array_merge( $context, $terms );
			}
		}

		$stop  = [ 'the', 'and', 'for', 'with', 'a', 'an', 'of', 'in', 'to', 'on', 'women', 'womens', 'ladies' ];
		$words = preg_split( '/[^a-z0-9]+/', strtolower( implode( ' ', $context ) ) );
		$words = array_unique( array_diff( array_filter( $words, static fn( $w ) => strlen( $w ) > 2 ), $stop ) );
		if ( ! $words ) {
			return [];
		}

		$scored = [];
		foreach ( self::all( [ 'limit' => 2000, 'orderby' => 'volume' ] ) as $row ) {
			$parts = array_values( array_filter(
				preg_split( '/[^a-z0-9]+/', strtolower( $row->keyword ) ),
				static fn( $w ) => strlen( $w ) > 2 && ! in_array( $w, $stop, true )
			) );
			if ( ! $parts ) {
				continue;
			}

			$hits = 0;
			foreach ( $parts as $part ) {
				foreach ( $words as $word ) {
					// Either direction, so "sneaker" on the page finds "sneakers" in the keyword.
					if ( false !== strpos( $part, $word ) || false !== strpos( $word, $part ) ) {
						$hits++;
						break;
					}
				}
			}
			if ( ! $hits ) {
				continue;
			}

			$score  = ( $hits / count( $parts ) ) * 100;    // how much of the keyword this page is about
			$score += min( 8, (int) $row->volume / 500 );   // a nudge, never the deciding factor
			$score -= (int) $row->used * 12;                // spread the list across the catalogue
			$score -= (int) $row->difficulty / 20;
			$scored[ $row->keyword ] = $score;
		}

		arsort( $scored );
		return array_slice( array_keys( $scored ), 0, max( 1, (int) $limit ) );
	}
}
