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
	 * The keywords that suit a particular page, for the Write with AI panel to start from.
	 *
	 * The same shortlist the writers choose from (shortlist()), read off the page's title and
	 * terms. This used to match fragments in either direction, so "over" inside "overshirt" put
	 * "petite fashion over 50" on a jacket. Phrases no other page aims at come first, then the
	 * ones about the piece rather than the shop — the panel puts the first three in by default.
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

		$limit = max( 1, (int) $limit );
		$fit   = self::shortlist( implode( ' ', $context ), (int) $post_id, $limit * 2, true );
		// usort is stable, so the shortlist's own order (best match first) holds within each group.
		usort( $fit, static fn( $a, $b ) => [ $a['taken'], $a['general'] ] <=> [ $b['taken'], $b['general'] ] );

		return array_slice( wp_list_pluck( $fit, 'keyword' ), 0, $limit );
	}

	/* ─────────────────────────── a shortlist for the writers ─────────────────────────── */

	/**
	 * The keywords from the list that fit a piece of writing, best first, for the model to choose
	 * its focus keyphrase and its supporting keywords from.
	 *
	 * The writers used to treat the list as optional vocabulary and take a product's focus
	 * keyphrase from its own name, so the researched phrases mostly went unused. The shop wants
	 * them used. A phrase only makes this list when it fits: whole words, not fragments (suggest()
	 * matches "over" inside "overshirt" and so offers "petite fashion over 50"), and every word of
	 * it has to be about the piece, about the kind of thing it is, or about the shop — a word about
	 * something else ("petite", "wedding", "men") costs more than a match earns, so "petite dresses
	 * for wedding guest" never reaches a jacket. The kind of thing is matched by family, so a
	 * shacket finds the jacket and coat phrases.
	 *
	 * Phrases another page already aims at are kept and marked: fine to work into the copy, never
	 * to be a second page's focus keyphrase, because two pages aimed at one phrase beat each other.
	 *
	 * @param string $about   Words saying what is being written about: garment, category, colour,
	 *                        details, the shop's notes — or a journal post's idea.
	 * @param int    $post_id The page being written, left out of the "already aimed at" check.
	 * @param int    $limit   How many to return.
	 * @param bool   $any     Offer the phrases about the shop even when the writing names no kind
	 *                        of clothes — a journal post on packing for Seoul is still fashion.
	 * @return array[] { keyword, volume, taken, score }
	 */
	public static function shortlist( string $about, int $post_id = 0, int $limit = 20, bool $any = false ): array {
		$item = array_flip( self::tokens( $about ) );
		if ( ! $item ) {
			return [];
		}

		// Which family each kind-word is in, and the families this piece belongs to: a shacket is
		// also a jacket and a coat.
		$kind_of = [];
		foreach ( self::kinds() as $n => $words ) {
			foreach ( $words as $word ) {
				$kind_of[ self::stem( $word ) ] = $n;
			}
		}
		$mine = [];
		foreach ( array_keys( $item ) as $word ) {
			if ( isset( $kind_of[ $word ] ) ) {
				$mine[ $kind_of[ $word ] ] = true;
			}
		}
		$shop    = array_flip( array_map( [ __CLASS__, 'stem' ], self::shop_words() ) );
		$foreign = array_flip( array_map( [ __CLASS__, 'stem' ], self::foreign_words() ) );
		$taken   = self::aimed_at( $post_id );

		$out     = [];
		$general = [];
		foreach ( self::all( [ 'limit' => 2000, 'orderby' => 'volume' ] ) as $row ) {
			$score  = 0.0;
			$strong = false;
			$names  = false;
			$stray  = 0;
			foreach ( self::tokens( (string) $row->keyword ) as $word ) {
				$kind = $kind_of[ $word ] ?? null;
				if ( null !== $kind && ! isset( $mine[ $kind ] ) ) {
					// It names a different kind of thing — a dress phrase for a cardigan however
					// many other words they share ("long sleeve") — so it is no use at all.
					$score = -1;
					break;
				}
				if ( null !== $kind ) {
					$names = true;
				}
				if ( isset( $item[ $word ] ) ) {
					$score += 3;
					$strong = true;
				} elseif ( null !== $kind ) {
					$score += 2;
					$strong = true;
				} elseif ( isset( $shop[ $word ] ) ) {
					$score += 0.5;
				} else {
					// A word about something this piece is not; a men's or children's phrase is
					// all but ruled out for a womenswear piece that never mentions either.
					$score -= isset( $foreign[ $word ] ) ? 6 : 1.25;
					$stray++;
				}
			}
			$entry = [
				'keyword' => (string) $row->keyword,
				'volume'  => (int) $row->volume,
				'taken'   => isset( $taken[ mb_strtolower( trim( (string) $row->keyword ) ) ] ),
				'score'   => $score,
				'names'   => $names,
				'general' => false,
				'used'    => (int) $row->used,
			];
			if ( $strong && $score > 0 ) {
				// A phrase that names the kind of thing outranks one that only shares a season with it.
				$entry['score'] += $names ? 2 : 0;
				$out[]           = $entry;
			} elseif ( ! $strong && ! $stray && $score > 0 ) {
				$general[] = array_merge( $entry, [ 'general' => true ] ); // all shop words: "korean fashion wear"
			}
		}

		usort( $out, static fn( $a, $b ) => [ $b['score'], $b['volume'] ] <=> [ $a['score'], $a['volume'] ] );
		$out = array_slice( $out, 0, max( 1, $limit ) );

		/*
		 * Phrases about the shop rather than the piece, for when the list has nothing that names
		 * it — so a garment's copy still carries the list's words. Supporting keywords at most,
		 * least used first, so the same head term is not stamped on every product. Clothes only:
		 * "korean fashion wear" has no business in a sunscreen's description.
		 */
		$clothes = array_diff_key( $mine, array_flip( [ 6, 7 ] ) ); // not skincare, not bags
		if ( ( $clothes || ( $any && ! $mine ) ) && count( $out ) < 3 && $general ) {
			usort( $general, static fn( $a, $b ) => [ $a['taken'], $a['used'], -$a['volume'] ] <=> [ $b['taken'], $b['used'], -$b['volume'] ] );
			$out = array_merge( $out, array_slice( $general, 0, 3 ) );
		}

		return $out;
	}

	/** Words that put a phrase in somebody else's wardrobe. */
	private static function foreign_words(): array {
		return [ 'men', 'mens', 'man', 'male', 'boy', 'boys', 'kid', 'kids', 'child', 'children', 'baby', 'toddler', 'pet', 'dog' ];
	}

	/** Lower-case words, small words and joining words dropped, plurals folded: "dresses" is "dress". */
	private static function tokens( string $text ): array {
		$stop  = [
			'the', 'and', 'for', 'with', 'a', 'an', 'of', 'in', 'to', 'on', 'at', 'by', 'or', 'from', 'your', 'you', 'our', 'is', 'it', 'its',
			// The way an idea is asked, not what it is about: "what to pack for Seoul" is not "what does petite mean".
			'what', 'how', 'why', 'when', 'where', 'which', 'who', 'does', 'do', 'can', 'should', 'are', 'that', 'this', 'these', 'those',
			'best', 'guide', 'tip', 'tips', 'way', 'ways', 'mean', 'need', 'get', 'make', 'work', 'about', 'into', 'more', 'most', 'than',
		];
		$words = preg_split( '/[^a-z0-9]+/', strtolower( remove_accents( html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) ) ), -1, PREG_SPLIT_NO_EMPTY );
		$out   = [];
		foreach ( $words as $word ) {
			// Numbers neither match nor count against: "SPF 50" is not "over 50".
			if ( strlen( $word ) < 2 || ctype_digit( $word ) || in_array( $word, $stop, true ) ) {
				continue;
			}
			$out[] = self::stem( $word );
		}
		return array_values( array_unique( $out ) );
	}

	private static function stem( string $word ): string {
		$word = strtolower( $word );
		if ( strlen( $word ) > 4 && 'ies' === substr( $word, -3 ) ) {
			return substr( $word, 0, -3 ) . 'y';
		}
		if ( strlen( $word ) > 4 && 'sses' === substr( $word, -4 ) ) {
			return substr( $word, 0, -2 );
		}
		if ( strlen( $word ) > 3 && 's' === substr( $word, -1 ) && 'ss' !== substr( $word, -2 ) ) {
			return substr( $word, 0, -1 );
		}
		return $word;
	}

	/**
	 * Families of words for one kind of thing, so a phrase can name the kind without naming the piece.
	 *
	 * Only words that cannot mean anything else: "shorts" folds to "short", which is also a length;
	 * "set" is in "set-in sleeves"; "cream" is a colour. Those still match when the piece itself is
	 * described with them — they just do not pull a whole family in behind them.
	 */
	private static function kinds(): array {
		return [
			[ 'jacket', 'coat', 'shacket', 'overshirt', 'blazer', 'outerwear', 'trench', 'parka', 'puffer', 'gilet', 'windbreaker' ],
			[ 'cardigan', 'sweater', 'jumper', 'knit', 'knitwear', 'pullover', 'turtleneck' ],
			[ 'dress', 'gown', 'frock', 'sundress' ],
			[ 'skirt', 'miniskirt' ],
			[ 'pants', 'trousers', 'jeans', 'denim', 'slacks', 'leggings', 'culottes' ],
			[ 'blouse', 'shirt', 'tee', 'tshirt', 'cami', 'camisole' ],
			[ 'skincare', 'serum', 'toner', 'moisturizer', 'moisturiser', 'sunscreen', 'spf', 'cleanser', 'ampoule' ],
			[ 'bag', 'tote', 'handbag', 'purse', 'clutch' ],
			[ 'jumpsuit', 'romper', 'playsuit' ],
		];
	}

	/** Words about the shop rather than the piece: welcome in a phrase, never enough on their own. */
	private static function shop_words(): array {
		return [ 'korean', 'korea', 'thai', 'thailand', 'asian', 'asia', 'fashion', 'clothes', 'clothing', 'style', 'outfit', 'women', 'womens', 'woman', 'ladies', 'wear', 'boutique', 'online', 'chic', 'cute', 'kpop', 'trendy', 'look' ];
	}

	/** Phrases already somebody's focus keyphrase, other than this page's own. */
	private static function aimed_at( int $post_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one indexed read per shortlist
		$rows = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT LOWER( TRIM( pm.meta_value ) ) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = '_whd_focus_keyphrase' AND pm.post_id <> %d AND p.post_status IN ('publish','draft','pending','private','future')",
			$post_id
		) );
		return array_flip( array_filter( (array) $rows ) );
	}
}
