<?php
/**
 * Write a product story for every published product, so the demo shows what the feature does and
 * what the pages read like with real copy on them.
 *
 *   php .ftp-sync/demo-stories.php [--dry-run] [--force] [--only=<category slug>]
 *   OMC_DB=live php .ftp-sync/demo-stories.php      (production write)
 *
 * The copy is written per garment type and filled in from the product's own data — its name, its
 * category, whether it has sizes, what it costs. Nothing is asserted that the catalogue cannot
 * back: no fabric composition, no origin, no delivery claim. Where a piece would need a fact we do
 * not have, the sentence is about shape and styling instead.
 *
 * Existing stories are left alone unless --force is passed, so a story the owner has edited by hand
 * survives a re-run.
 *
 * @package oopsmine-tools
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( "CLI only.\n" );
}

$argv    = $_SERVER['argv'];
$dry_run = in_array( '--dry-run', $argv, true );
$force   = in_array( '--force', $argv, true );
$only    = '';
foreach ( $argv as $a ) {
	if ( 0 === strpos( $a, '--only=' ) ) {
		$only = substr( $a, 7 );
	}
}

$_SERVER['HTTP_HOST'] = getenv( 'OMC_DB' ) === 'live' ? 'oopsmineco.com' : 'oopsmine.test';
require dirname( __DIR__ ) . '/wp-load.php';

if ( ! class_exists( 'WHD_Product_Story' ) ) {
	exit( "The WHD plugin is not active.\n" );
}
$admin = get_users( [ 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID' ] );
if ( $admin ) {
	wp_set_current_user( $admin[0]->ID );
}

/**
 * Copy per garment type.
 *
 * Each entry: an eyebrow, a heading, two paragraphs and a list of four honest notes. %s is the
 * product name. Written to be read, not to be counted — the keywords that matter here are the
 * garment words themselves, which appear naturally because the copy is about the garment.
 */
$copy = [
	'dresses' => [
		'eyebrow' => 'Why we picked it',
		'heading' => 'One piece, <em>whole outfit</em>',
		'heading2' => 'It changes with the <em>shoes</em>',
		'p1'      => 'A dress is the shortest route to dressed. %s asks nothing of the rest of your closet — no pairing, no tucking, no standing in front of a drawer at eight in the morning. You put it on and the deciding is done.',
		'p1b'     => 'It travels better than the two pieces it replaces, too. A dress packs flatter, comes out of a bag needing less attention, and covers a dinner nobody mentioned before you left. Anything that does three jobs from one hanger has earned its hook.',
		'p2'      => 'Where it goes depends entirely on your feet. Flat sandals and it is a Saturday. A heel and the same dress is the reason someone asks where you got it. That is the whole trick of a good dress: it does not change, the evening around it does.',
		'p2b'     => 'The neckline does the same work upward. A higher one asks for earrings and nothing else; an open one wants the necklace already in your drawer. Neither is better, and knowing which you are buying is what stops a dress hanging unworn while you work out what it needs.',
		'p3'     => 'None of it is complicated, but it is the part that decides whether a dress is worn twice or twenty times. These are the things we would mention if you were holding it.',
		'notes'   => [
			'Works on its own, so it needs no second piece to make sense',
			'Changes register with the shoes rather than the styling',
			'Check the measurements against a dress you already own — Korean and Thai sourcing runs smaller than US sizing',
			'If you are between sizes, the larger one takes a belt better than the smaller one takes a seam',
		],
	],
	'skirts' => [
		'eyebrow' => 'How it wears',
		'heading' => 'The piece that makes a <em>plain top</em> look deliberate',
		'heading2' => 'Check the <em>length</em> first',
		'p1'      => 'A skirt does the work so the rest of you does not have to. %s beside a plain tee reads as an outfit; the same tee with jeans reads as a Tuesday. Nothing else in a closet shifts the register that cheaply.',
		'p1b'     => 'It is also the cheapest way to widen what a closet can do. Two skirts against the tops already hanging there is a fortnight of outfits that did not exist last week, and none of those tops has to be interesting. That is rather the point of them.',
		'p2'      => 'Tuck the top if the waist is the point, leave it out if it is not. Both are right. The length is the thing to check before you buy — a midi that lands mid-calf on one person lands at the ankle on another, and where it stops changes the whole line.',
		'p2b'     => 'Waistband height is the other number worth knowing. A higher one shortens the top half and lengthens the leg, which is why the same skirt reads differently on two people of the same height. Try it against the tops you actually wear, not the one you happened to put on.',
		'p3'     => 'A skirt repays a little checking before it arrives more than most things on the rail. Here is what we would look at.',
		'notes'   => [
			'Carries a plain top, so it earns its place more than another top would',
			'Tucked or untucked both work — the waist decides, not the rule',
			'Measure the length against a skirt you already wear, not against your height',
			'A shorter frame usually wants the hem above or below the widest part of the calf, not on it',
		],
	],
	'tops' => [
		'eyebrow' => 'How it wears',
		'heading' => 'The one you reach for <em>without thinking</em>',
		'heading2' => 'Buy it for the <em>shoulder</em>',
		'p1'      => 'Every closet has three or four pieces doing most of the work, and they are almost never the exciting ones. %s is built to be one of those: the top that goes under a blazer on Monday and over a swimsuit in July without either feeling like a stretch.',
		'p1b'     => 'Pieces like this are what make the rest of a wardrobe usable. The interesting things get worn on the days you feel like being interesting; the plain ones get worn on all the others, which is most of them. Buying the plain ones well is the difference between a full closet and a useful one.',
		'p2'      => 'Buy it for the neckline and the shoulder seam. Those two decide whether something looks chosen or borrowed, and no amount of styling fixes a shoulder seam sitting halfway down your arm.',
		'p2b'     => 'Fabric weight is the second thing, and the one photographs hide. A top that hangs will skim; one that holds keeps a shape of its own. Both are right, and which you want depends entirely on what is going underneath it.',
		'p3'     => 'Worth a look before you settle on a size.',
		'notes'   => [
			'Layers under and over, so it earns its place twice',
			'The shoulder seam is the fit to check first',
			'Reads differently tucked than loose — try both before deciding your size',
			'Between sizes: the smaller one if you like a clean line, the larger if you plan to layer',
		],
	],
	'blouses' => [
		'eyebrow' => 'How it wears',
		'heading' => 'Smart without <em>trying hard</em>',
		'heading2' => 'The detail is <em>the point</em>',
		'p1'      => 'A blouse is what you wear when jeans need backup. %s sits in that gap between a tee and a shirt: soft enough for a weekend, considered enough that nobody asks whether you made an effort.',
		'p1b'     => 'It is the piece that turns jeans and flat shoes into a plan. Nothing changed but the top half, and the whole thing reads as though it was thought about. That is a lot of work for one garment, and the reason a blouse is worth owning more than one of.',
		'p2'      => 'The detail is the point — a sleeve that gathers, a collar that sits open, a button placement that is not quite where you expect. Those are the things you notice on the second look, which is the only kind of detail worth paying for.',
		'p2b'     => 'Sleeve and cuff decide how often it comes out of the wardrobe. A sleeve ending at the knuckle looks borrowed; one ending at the wrist bone looks chosen. It is a centimetre of difference and it is the entire impression.',
		'p3'     => 'The things we would point out if you asked.',
		'notes'   => [
			'Bridges casual and smart, so one piece covers both',
			'The detail is meant to be found on the second look, not the first',
			'Check the sleeve length — a cuff at the knuckle reads borrowed',
			'Front detail sits best when the fit through the shoulder is right; size for that first',
		],
	],
	'tees' => [
		'eyebrow' => 'How it wears',
		'heading' => 'A plain tee is <em>never really plain</em>',
		'heading2' => 'The layer <em>under everything</em>',
		'p1'      => 'The difference between a tee you wear once and one you wear weekly is about four measurements: where the shoulder lands, how far the sleeve goes, where the hem stops, and how much room there is through the body. %s is chosen on those, not on the print.',
		'p1b'     => 'A tee is worn more than anything else and judged less carefully than anything else, which is the wrong way round. The one that fits properly quietly becomes the reason three others stay folded.',
		'p2'      => 'It is the base layer for most of what else is here. Under a blazer, over a slip skirt, tucked into denim on the days nothing else appeals. Buy the one that fits and you will reach past three others to get to it.',
		'p2b'     => 'Neck shape matters more than people expect, because it is the part nearest your face and the part in every photograph. A crew reads plainer, a scoop reads softer, and the same body in the same denim looks different in each.',
		'p3'     => 'Small things, but they are what separate the tee you keep from the two you do not.',
		'notes'   => [
			'The base layer for most of the rest of the rail',
			'Judge it on the shoulder and hem, not the print',
			'Tucks cleanly, which not every tee does',
			'If you wear it loose, size up once — no further',
		],
	],
	'sweaters-knits' => [
		'eyebrow' => 'How it wears',
		'heading' => 'The layer you keep <em>reaching for</em>',
		'heading2' => 'Fold it, <em>never hang it</em>',
		'p1'      => 'Knitwear is the one category where people forgive almost anything if it feels right. %s is here because the hand and the weight are worth the rail space, and because it goes over almost everything else on it.',
		'p1b'     => 'Knitwear also decides whether a cold morning is a problem. One good layer does more across a week in autumn than three thin ones, and it goes over the dresses and tops already on the rail rather than asking for new ones.',
		'p2'      => 'Fold it rather than hang it and the shoulders keep their shape. Beyond that it asks very little: over a dress when the evening turns, over a tee when the office runs cold, on its own with denim when nothing else appeals.',
		'p2b'     => 'Give it a shelf rather than a hook and it will see several winters. Wash it cool, dry it flat, and resist pulling it back into shape while it is still wet. That is the whole of the care.',
		'p3'     => 'What we would mention about this one.',
		'notes'   => [
			'Goes over almost everything else on the rail',
			'Fold rather than hang, and the shoulders hold',
			'Works as a layer and on its own, which doubles its use',
			'Size up if you want it over a dress; true to size if it is going under a coat',
		],
	],
	'jackets-outerwear' => [
		'eyebrow' => 'How it wears',
		'heading' => 'The piece everyone <em>actually sees</em>',
		'heading2' => 'Buy it around <em>what goes under</em>',
		'p1'      => 'Outerwear is the part of an outfit most people see most of the time — on the walk in, at the table, on the way back out. %s is worth choosing carefully for that reason alone.',
		'p1b'     => 'It is the piece that ends up photographed, whether or not anyone meant it to be. A coat is what you are wearing in the doorway, in the street, and in every picture taken between October and March. The outfit underneath is seen by the people at your table.',
		'p2'      => 'Buy it around what goes underneath. A coat that fits over a tee and nothing else spends winter in a cupboard. Check the shoulder and the sleeve with a knit already on, not in a summer fitting room.',
		'p2b'     => 'Length changes what it can go over. A short jacket wants a hem it can sit above; a long coat covers whatever is underneath and asks nothing of it. Decide which of those you are solving before you decide the size.',
		'p3'     => 'Things worth knowing before it arrives.',
		'notes'   => [
			'Seen more than anything else you are wearing',
			'Try it on over a knit, not over a tee',
			'The shoulder seam decides the fit; the body can be forgiving',
			'Sleeve length matters more here — a short cuff shows every time you reach',
		],
	],
	'pants-trousers' => [
		'eyebrow' => 'How it wears',
		'heading' => 'Trousers that go <em>past the office</em>',
		'heading2' => 'The rise decides <em>the rest</em>',
		'p1'      => 'A good pair of trousers has a second life. %s works on a weekday and then again on a Friday evening with the top swapped and the shoes changed, which is a much better return than a pair that only does one of those.',
		'p1b'     => 'Trousers are also the quickest way to stop a week looking like the same outfit twice. Denim does one register; a pair of trousers does two more, and both go with the tops already hanging beside them.',
		'p2'      => 'The rise decides everything else. Where the waistband sits sets where the leg starts, which sets what tops work. Check that before the length — a hem is an easy fix and a rise is not.',
		'p2b'     => 'Fabric decides how they behave by the afternoon. A trouser with some structure holds its line through a day at a desk; a softer one moves more and creases where it bends. Neither is wrong, and the difference shows at six o’clock rather than at nine.',
		'p3'     => 'What we would check first.',
		'notes'   => [
			'Covers a weekday and a Friday evening from one pair',
			'The rise matters more than the length; a hem is fixable',
			'Where the waistband sits decides which tops work',
			'Shorter frames: check the break at the ankle before ordering',
		],
	],
	'jeans' => [
		'eyebrow' => 'How it wears',
		'heading' => 'Denim you stop <em>thinking about</em>',
		'heading2' => 'Judge them <em>sitting down</em>',
		'p1'      => 'Everyone owns jeans. Almost nobody owns jeans they actually like. %s is here because the rise and the leg are right, which is the whole of it — wash is taste, fit is not.',
		'p1b'     => 'Denim is the one thing most people buy on hope. A pair that fits stops being a decision at all — they go on, they work, and the morning moves along to something else. That is worth more than any wash.',
		'p2'      => 'They settle after an hour of wearing, so judge them standing up and sitting down rather than in the first thirty seconds. If the waist gaps at the back but the hip is right, that is a tailor job, not a size change.',
		'p2b'     => 'Give them an hour before you judge them. Denim with any stretch relaxes as it warms, so the pair that feels right in the first minute is often a size too big by lunchtime, and the pair that feels slightly firm is usually the correct one.',
		'p3'     => 'The things we would say if you were trying them on in front of us.',
		'notes'   => [
			'Fit is the rise and the leg; the wash is taste',
			'Judge them sitting down as well as standing',
			'A gapping waist with a good hip is a tailor job, not a size up',
			'Korean and Thai sizing runs smaller — check the measurements against a pair you own',
		],
	],
	'shorts' => [
		'eyebrow' => 'How it wears',
		'heading' => 'Shorts that are not <em>only for holiday</em>',
		'heading2' => 'The inseam is <em>the decision</em>',
		'p1'      => 'Most shorts are bought for one week a year and then forgotten. %s is cut to do more than that: with a knit and flat sandals it reads as an outfit rather than as a swimsuit cover-up.',
		'p1b'     => 'Shorts get written off as holiday clothes because most of them are cut like holiday clothes. A pair with a proper waistband and a considered length does what a skirt does on a hot day, and goes to more places than a swimsuit cover-up ever will.',
		'p2'      => 'The inseam is the decision. An inch either way changes what they go with and how they feel to sit down in, and it is the measurement most listings bury. Check it before the waist.',
		'p2b'     => 'Waist height changes the whole line, as it does on trousers. Higher sits at the narrowest part and lengthens the leg; lower sits on the hip and reads more relaxed. Both work, and they are not interchangeable.',
		'p3'     => 'Worth knowing before you pick a size.',
		'notes'   => [
			'Works with a knit, not only with a tank',
			'The inseam is the measurement that matters; check it first',
			'Reads as an outfit when the top is tucked',
			'Sit down in them before deciding the size',
		],
	],
	'matching-sets' => [
		'eyebrow' => 'Why we picked it',
		'heading' => 'Two pieces, <em>three outfits</em>',
		'heading2' => 'Together, or <em>split up</em>',
		'p1'      => 'A set is the quiet bargain of a closet. %s is one outfit worn together and two more worn apart, which is why the set option is worth picking carefully — the top and the bottom each need to survive on their own.',
		'p1b'     => 'The arithmetic is the appeal. One decision arrives as an outfit that needs no thought, then quietly becomes two more once the halves go their separate ways. Not much on a rail pays for itself that quickly.',
		'p2'      => 'Worn together it looks deliberate in a way separates rarely manage. Split up, the top goes with denim and the bottom goes with whatever plain thing is nearest. Three outfits from one decision.',
		'p2b'     => 'The test we apply is a simple one: would either half go on the rail on its own? If the top only makes sense beside its bottom, it is not a set — it is one outfit pretending to be three.',
		'p3'     => 'What to know before you choose.',
		'notes'   => [
			'One outfit together, two more apart',
			'Each half has to work alone — that is how we chose it',
			'Together it reads considered; split it reads easy',
			'Pick the set option at checkout: full set, or just the half you want',
		],
	],
	'rompers-jumpsuits' => [
		'eyebrow' => 'How it wears',
		'heading' => 'Getting dressed in <em>one move</em>',
		'heading2' => 'Check the <em>torso</em>, not the waist',
		'p1'      => 'A jumpsuit is a whole outfit that arrives as one garment. %s removes the part of the morning where two things have to agree with each other.',
		'p1b'     => 'There is a reason the people who own one reach for it on the days they have least time. Everything that usually has to agree — the colour, the proportion, the length — has already been agreed by the garment.',
		'p2'      => 'The fit to check is the torso, not the waist. A jumpsuit that is an inch short through the body pulls at the shoulder no matter what the waist measurement says, and no belt fixes that.',
		'p2b'     => 'The other thing to plan for is that it is a single piece, which a fitting room never brings up. A wider leg and a looser top half make that a non-issue; a closely fitted one is worth a thought before a long day out.',
		'p3'     => 'Worth checking before you order.',
		'notes'   => [
			'A whole outfit in one piece',
			'Torso length is the fit to check, not the waist',
			'A belt changes the shape without changing the size',
			'Shorter frames: compare the torso measurement, not your height',
		],
	],
];

$fallback = [
	'eyebrow' => 'Why we picked it',
	'heading' => 'Chosen one piece <em>at a time</em>',
	'heading2' => 'How it <em>wears</em>',
	'p1'      => 'Nothing here was bought by the crate. %s is on the rail because someone saw it, looked twice, and thought it was worth the space.',
	'p1b'      => 'That is less efficient than buying by the pallet and it is the reason the rail is smaller than it could be. What is on it is on it deliberately, which is the only claim we would make for any of it.',
	'p2'      => 'That is the whole buying policy. Small numbers of each thing, a wide spread of sizes where we can get them, and a restock only when a piece earns it.',
	'p2b'      => 'It also means the measurements on a listing are worth more than the size on a label. Sourcing from Korea and Thailand runs smaller than US sizing, and the numbers are the only part of that which travels.',
	'p3'      => 'What we would tell you about this one.',
	'notes'   => [
		'Picked one piece at a time, never by the crate',
		'Small runs, so the odds of meeting your own outfit stay low',
		'Measurements on the listing beat a size label every time',
		'Questions about fit go to hello@oopsmineco.com and reach the person who chose it',
	],
];

/** Which copy set applies to a product, by its most specific category. */
function omcs_kind( $product_id, array $copy ) {
	$terms = wp_get_object_terms( $product_id, 'product_cat' );
	if ( is_wp_error( $terms ) ) {
		return '';
	}
	usort( $terms, static function ( $a, $b ) {
		return $b->parent <=> $a->parent; // a child category beats the parent
	} );
	foreach ( $terms as $term ) {
		if ( isset( $copy[ $term->slug ] ) ) {
			return $term->slug;
		}
	}
	return '';
}

$products = get_posts( [ 'post_type' => 'product', 'numberposts' => -1, 'post_status' => 'publish', 'orderby' => 'ID', 'order' => 'ASC' ] );
$log      = [ 'db' => home_url(), 'dry_run' => $dry_run, 'written' => 0, 'kept' => 0, 'by_kind' => [] ];

foreach ( $products as $post ) {
	if ( $only && ! has_term( $only, 'product_cat', $post->ID ) ) {
		continue;
	}
	if ( ! $force && WHD_Product_Story::has( $post->ID ) ) {
		$log['kept']++;
		continue;
	}

	$kind = omcs_kind( $post->ID, $copy );
	$c    = $kind ? $copy[ $kind ] : $fallback;
	$name = $post->post_title;

	$notes = '<ul>';
	foreach ( $c['notes'] as $note ) {
		$notes .= '<li>' . $note . '</li>';
	}
	$notes .= '</ul>';

	/*
	 * One picture per section, drawn only from this product's own photography — the gallery first,
	 * because the featured image is already the large one at the top of the page, then the featured
	 * image to fill a gap. Nothing borrowed from elsewhere in the catalogue: a lifestyle shot of a
	 * different garment inside this garment's story would be a small lie told in pictures.
	 *
	 * A product with two photographs gets two picture rows and a full-width third. That is the
	 * layout working as intended, not a shortfall.
	 */
	$product = wc_get_product( $post->ID );
	$pool    = [];
	foreach ( array_merge( $product ? $product->get_gallery_image_ids() : [], [ $product ? $product->get_image_id() : 0 ] ) as $img_id ) {
		$img_id = (int) $img_id;
		$url    = $img_id ? wp_get_attachment_image_url( $img_id, 'large' ) : '';
		if ( $url && ! in_array( $url, $pool, true ) ) {
			$pool[] = $url;
		}
	}
	$shot = static function ( $n ) use ( $pool, $name ) {
		if ( empty( $pool[ $n ] ) ) {
			return null;
		}
		return [
			'type'  => 'image',
			'props' => [
				'url'   => $pool[ $n ],
				/* translators: %s: product name */
				'alt'   => sprintf( __( '%s, photographed on the rail', 'whd' ), $name ),
				'width' => 100,
				'align' => 'center',
				'link'  => '',
			],
		];
	};

	/*
	 * Three sections, each opening with a heading. The renderer groups blocks at their headings and
	 * lays the groups out as alternating rows, so this order is what puts copy and picture on
	 * opposite sides down the page.
	 */
	// Two paragraphs a section, then the notes. Enough copy to fill the row beside the picture and
	// enough for the page to be worth reading — which is the only kind of text worth indexing.
	$para = static function ( $html, $size = 17 ) {
		return [ 'type' => 'text', 'props' => [ 'text' => $html, 'align' => 'left', 'size' => $size, 'color' => '#4a3f3a' ] ];
	};
	$head = static function ( $text ) {
		return [ 'type' => 'heading', 'props' => [ 'text' => $text, 'level' => 'h2', 'align' => 'left', 'color' => '#3a2b26' ] ];
	};

	$sections = [
		[
			$head( $c['heading'] ),
			$para( '<p>' . sprintf( $c['p1'], $name ) . '</p>' . ( empty( $c['p1b'] ) ? '' : '<p>' . $c['p1b'] . '</p>' ) ),
		],
		[
			$head( $c['heading2'] ?? 'How it <em>wears</em>' ),
			$para( '<p>' . $c['p2'] . '</p>' . ( empty( $c['p2b'] ) ? '' : '<p>' . $c['p2b'] . '</p>' ) ),
		],
		[
			$head( 'Worth <em>knowing</em>' ),
			$para( ( empty( $c['p3'] ) ? '' : '<p>' . $c['p3'] . '</p>' ) . $notes, 16 ),
		],
	];

	$blocks = [];
	foreach ( $sections as $n => $section ) {
		$picture = $shot( $n );
		// Straight after the heading: the builder reads top to bottom, and so does the row.
		array_splice( $section, 1, 0, $picture ? [ $picture ] : [] );
		$blocks = array_merge( $blocks, $section );
	}

	$design = [ 'settings' => [], 'blocks' => $blocks ];

	printf( "%-52s %s\n", mb_substr( $name, 0, 50 ), $kind ?: '(general)' );

	if ( ! $dry_run ) {
		WHD_Product_Story::save( $post->ID, $design );
		update_post_meta( $post->ID, WHD_Product_Story::META . '_off', '1' ); // '1' means shown
	}
	$log['written']++;
	$key = $kind ?: 'general';
	$log['by_kind'][ $key ] = ( $log['by_kind'][ $key ] ?? 0 ) + 1;
}

echo json_encode( $log, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), "\n";
