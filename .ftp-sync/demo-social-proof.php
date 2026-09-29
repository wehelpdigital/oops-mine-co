<?php
/**
 * Seed the demo catalogue with product reviews and story comments, so the review stars and the
 * community notes can be seen with something in them.
 *
 *   php .ftp-sync/demo-social-proof.php [--dry-run] [--remove]
 *   OMC_DB=live php .ftp-sync/demo-social-proof.php            (production write)
 *   OMC_DB=live php .ftp-sync/demo-social-proof.php --remove   (take it all back out)
 *
 * THIS CONTENT IS FABRICATED. It is scaffolding for judging the design, not customer feedback.
 * Every row it writes carries the meta key `_whd_demo`, so --remove finds all of it and nothing
 * else, and so a real review can never be mistaken for one of these. Publishing invented reviews
 * to shoppers is illegal in the United States under the FTC's rule on consumer reviews and
 * testimonials, so take them out before real traffic arrives.
 *
 * @package oopsmine-tools
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( "CLI only.\n" );
}

$argv    = $_SERVER['argv'];
$dry_run = in_array( '--dry-run', $argv, true );
$remove  = in_array( '--remove', $argv, true );

$_SERVER['HTTP_HOST'] = getenv( 'OMC_DB' ) === 'live' ? 'oopsmineco.com' : 'oopsmine.test';
require dirname( __DIR__ ) . '/wp-load.php';

const OMC_DEMO_FLAG = '_whd_demo';

$admin = get_users( [ 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID' ] );
if ( $admin ) {
	wp_set_current_user( $admin[0]->ID );
}

/* ── Remove ─────────────────────────────────────────────────────────────── */

if ( $remove ) {
	global $wpdb;
	$ids = $wpdb->get_col( $wpdb->prepare(
		"SELECT comment_id FROM {$wpdb->commentmeta} WHERE meta_key = %s",
		OMC_DEMO_FLAG
	) );
	echo count( $ids ), " seeded rows found\n";
	if ( ! $dry_run ) {
		$touched = [];
		foreach ( $ids as $id ) {
			$c = get_comment( $id );
			if ( $c ) {
				$touched[ (int) $c->comment_post_ID ] = true;
			}
			wp_delete_comment( (int) $id, true );
		}
		foreach ( array_keys( $touched ) as $pid ) {
			WC_Comments::clear_transients( $pid );
		}
	}
	echo json_encode( [ 'removed' => $dry_run ? 0 : count( $ids ), 'dry_run' => $dry_run ], JSON_PRETTY_PRINT ), "\n";
	exit;
}

/* ── Seed ───────────────────────────────────────────────────────────────── */

$people = [
	[ 'Marisol R.', 'marisol.r@example.com' ],
	[ 'Danielle K.', 'danielle.k@example.com' ],
	[ 'Priya N.', 'priya.n@example.com' ],
	[ 'Bea T.', 'bea.t@example.com' ],
	[ 'Court A.', 'court.a@example.com' ],
	[ 'Halima S.', 'halima.s@example.com' ],
	[ 'Jo M.', 'jo.m@example.com' ],
	[ 'Renée P.', 'renee.p@example.com' ],
];

/* Reviews: a rating and a line that sounds like a person, not a template. */
$reviews = [
	[ 5, 'Took my usual size and it fits the way the measurements said it would. That almost never happens.' ],
	[ 5, 'Wore it twice in the first week. The second time someone asked where it was from before I had my coat off.' ],
	[ 4, 'Lovely piece. Runs a touch small through the shoulder, so size up if you are between.' ],
	[ 5, 'Photographs do not do the colour justice. Softer in person, which I prefer.' ],
	[ 4, 'Exactly as described. I would have liked pockets, but that is me and not the piece.' ],
	[ 5, 'Arrived well packed and the finish is better than I expected at the price.' ],
	[ 5, 'I hesitated for a week and then it nearly sold out. Do not do what I did.' ],
	[ 4, 'Good weight, holds its shape after a wash. The hem sits lower on me at 5 foot 2.' ],
];

/* Story notes: styling talk rather than verdicts, which is what that section is for.
   The score is the note's own rating, kept under WHD_Story_Comments::RATING and never counted
   into the product's star average. A couple are left at 0 — a note without a score is normal. */
$notes = [
	[ 5, 'Wore this with flat sandals for a lunch and then swapped to a heel for dinner. Same outfit, completely different evening.' ],
	[ 4, 'Sizing note for anyone shorter: I took the smaller size and had the hem taken up an inch. Worth the twelve dollars.' ],
	[ 5, 'Goes with the cream knit from here almost too well. I have worn the pair three times this month.' ],
	[ 0, 'I asked about the measurements before ordering and got a reply the same morning with the actual numbers. That is why I bought it.' ],
	[ 4, 'Packs completely flat, which is why it came on holiday with me and half my closet did not.' ],
	[ 5, 'Second one of these I have bought. The first is still going after a year of proper wear.' ],
];

$products = get_posts( [ 'post_type' => 'product', 'numberposts' => -1, 'post_status' => 'publish', 'orderby' => 'ID', 'order' => 'ASC' ] );
$log      = [ 'db' => home_url(), 'dry_run' => $dry_run, 'products' => 0, 'reviews' => 0, 'notes' => 0, 'skipped' => 0 ];

foreach ( $products as $i => $post ) {
	// Already seeded? Leave it; re-running should not pile more on.
	$existing = get_comments( [
		'post_id'    => $post->ID,
		'meta_key'   => OMC_DEMO_FLAG,
		'count'      => true,
		'status'     => 'all',
		'type'       => 'all',
	] );
	if ( $existing ) {
		$log['skipped']++;
		continue;
	}

	// Two or three of each, varied by position so neighbouring products do not read identically.
	$n_reviews = 2 + ( $i % 2 );
	$n_notes   = 1 + ( $i % 2 );
	$when      = time() - ( 3 + ( $i % 20 ) ) * DAY_IN_SECONDS;

	for ( $r = 0; $r < $n_reviews; $r++ ) {
		$who    = $people[ ( $i + $r ) % count( $people ) ];
		$review = $reviews[ ( $i * 3 + $r ) % count( $reviews ) ];
		$when  -= ( 1 + $r ) * DAY_IN_SECONDS;

		printf( "%-44s review %d\u{2605} %s\n", mb_substr( $post->post_title, 0, 42 ), $review[0], $who[0] );
		if ( $dry_run ) {
			$log['reviews']++;
			continue;
		}
		$id = wp_insert_comment( [
			'comment_post_ID'      => $post->ID,
			'comment_author'       => $who[0],
			'comment_author_email' => $who[1],
			'comment_content'      => $review[1],
			'comment_type'         => 'review',
			'comment_approved'     => 1,
			'comment_date'         => wp_date( 'Y-m-d H:i:s', $when ),
			'comment_date_gmt'     => gmdate( 'Y-m-d H:i:s', $when ),
		] );
		if ( $id ) {
			add_comment_meta( $id, 'rating', $review[0] );
			add_comment_meta( $id, 'verified', 1 );
			add_comment_meta( $id, OMC_DEMO_FLAG, 1 );
			$log['reviews']++;
		}
	}

	for ( $c = 0; $c < $n_notes; $c++ ) {
		$who  = $people[ ( $i + $c + 3 ) % count( $people ) ];
		$note = $notes[ ( $i * 2 + $c ) % count( $notes ) ];
		$when -= DAY_IN_SECONDS;

		if ( $dry_run ) {
			$log['notes']++;
			continue;
		}
		$id = wp_insert_comment( [
			'comment_post_ID'      => $post->ID,
			'comment_author'       => $who[0],
			'comment_author_email' => $who[1],
			'comment_content'      => $note[1],
			'comment_type'         => WHD_Story_Comments::TYPE,
			'comment_approved'     => 1,
			'comment_date'         => wp_date( 'Y-m-d H:i:s', $when ),
			'comment_date_gmt'     => gmdate( 'Y-m-d H:i:s', $when ),
		] );
		if ( $id ) {
			add_comment_meta( $id, OMC_DEMO_FLAG, 1 );
			if ( $note[0] ) {
				add_comment_meta( $id, WHD_Story_Comments::RATING, $note[0], true );
			}
			$log['notes']++;
		}
	}

	if ( ! $dry_run ) {
		WC_Comments::clear_transients( $post->ID );
	}
	$log['products']++;
}

echo json_encode( $log, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), "\n";
echo "\nFABRICATED CONTENT. Remove before real shoppers arrive:\n  php .ftp-sync/demo-social-proof.php --remove\n";
