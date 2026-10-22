<?php
/**
 * Page Editor: the words and pictures of the home and about pages, edited in one admin screen.
 *
 * Both pages are templates rather than block content: their sections are code, and their copy and
 * photographs were data in this theme (omc_images(), omc_home_banners(), …) or strings in the
 * templates. This module puts an editing layer over them without moving any of it:
 *
 *   - omc_pe_schema() lists everything that can be edited, section by section in page order, with
 *     its default — the words and pictures the page has today;
 *   - what the owner changes is kept in one option, `omc_page_content`, and only where it differs
 *     from the default, so a default improved in code still reaches every field nobody touched;
 *   - the templates ask for their copy through omc_pe_copy() / omc_pe_link(), and the data
 *     functions take the saved values through their own filters, at priority 20.
 *
 * Facebook Live's fields are the Customizer's theme mods (omc_live_*), read and written here so the
 * two screens never disagree. The About page's body starts as the page's own content; edited here,
 * it is kept in the option as well, because .ftp-sync/publish-site.php rewrites that page's content
 * every time it runs and would otherwise put the old words back.
 *
 * Text fields marked "em" take *asterisks* around a word for the italic accent the headings use.
 *
 * @package moderno-child
 */

defined( 'ABSPATH' ) || exit;

const OMC_PE_OPTION = 'omc_page_content';
const OMC_PE_PAGE   = 'omc-page-editor';

/* ─────────────────────────── reading ─────────────────────────── */

/**
 * While defaults are being worked out the override filters stand aside, so a data function
 * answers with what the code says rather than with what was saved over it.
 */
function omc_pe_raw( $set = null ) {
	static $depth = 0;
	if ( true === $set ) {
		$depth++;
	} elseif ( false === $set ) {
		$depth = max( 0, $depth - 1 );
	}
	return $depth > 0;
}

/** Everything saved for one page: [ key => value ], only the fields that differ from their default. */
function omc_pe_saved( $page, $reset = false ) {
	static $all = null;
	if ( null === $all || $reset ) {
		$all = (array) get_option( OMC_PE_OPTION, [] );
	}
	return isset( $all[ $page ] ) && is_array( $all[ $page ] ) ? $all[ $page ] : [];
}

/** One saved value, or null when the field is at its default. */
function omc_pe_value( $page, $key ) {
	$saved = omc_pe_saved( $page );
	return array_key_exists( $key, $saved ) ? $saved[ $key ] : null;
}

/** A saved value, or the default given. */
function omc_pe( $page, $key, $default ) {
	$value = omc_pe_value( $page, $key );
	return null === $value ? $default : $value;
}

/** A field's default, worked out once (closures are called with the override filters off). */
function omc_pe_default( $page, $key ) {
	static $cache = [];
	$id = $page . '|' . $key;
	if ( ! array_key_exists( $id, $cache ) ) {
		$field   = omc_pe_field( $page, $key );
		$default = $field['default'] ?? '';
		if ( $default instanceof Closure ) {
			omc_pe_raw( true );
			$default = $default();
			omc_pe_raw( false );
		}
		$cache[ $id ] = $default;
	}
	return $cache[ $id ];
}

/** Template copy: what was saved, or the schema's default. */
function omc_pe_copy( $page, $key ) {
	return (string) omc_pe( $page, $key, omc_pe_default( $page, $key ) );
}

/** A button: [ text, url ]. */
function omc_pe_link( $page, $key ) {
	$link = omc_pe( $page, $key, omc_pe_default( $page, $key ) );
	$link = is_array( $link ) ? $link : [];
	return [ 'text' => (string) ( $link['text'] ?? '' ), 'url' => (string) ( $link['url'] ?? '' ) ];
}

/** A home-page section's head — small heading, heading, link — from its saved or default words. */
function omc_pe_head( $section, $link_url, $id ) {
	omc_section_head(
		omc_pe_copy( 'home', $section . '.eyebrow' ),
		omc_pe_em( omc_pe_copy( 'home', $section . '.title' ) ),
		$link_url,
		omc_pe_copy( 'home', $section . '.link' ),
		$id
	);
}

/** Escaped text with *word* set in italics, the accent every section heading uses. */
function omc_pe_em( $text ) {
	return preg_replace( '/\*([^*\n]+)\*/u', '<em>$1</em>', esc_html( (string) $text ) );
}

/* ─────────────────────────── what can be edited ─────────────────────────── */

/** The About page, whichever page is using its template. */
function omc_pe_about_id() {
	static $id = null;
	if ( null === $id ) {
		$found = get_posts( [
			'post_type'   => 'page',
			'post_status' => 'publish',
			'numberposts' => 1,
			'fields'      => 'ids',
			'meta_key'    => '_wp_page_template', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one page, admin and that page only
			'meta_value'  => 'templates/page-about.php', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		] );
		$id = $found ? (int) $found[0] : 0;
	}
	return $id;
}

/** The page each tab edits, for "View page" and the keyphrase reminder. */
function omc_pe_page_id( $page ) {
	return 'about' === $page ? omc_pe_about_id() : (int) get_option( 'page_on_front' );
}

/**
 * Every editable thing, page → section → field, in the order the page shows them.
 *
 * Field types: text, em (text with *italics*), textarea, lines (one per line), link (text + url),
 * select, toggle, datetime, image, images, rich (the About body), group (sub-fields) and list
 * (repeating sub-fields). `default` is a value or a closure; `mod` stores the field as that theme mod.
 */
function omc_pe_schema() {
	static $schema = null;
	if ( null !== $schema ) {
		return $schema;
	}

	$text  = static fn( $label, $default, $more = [] ) => [ 'type' => 'text', 'label' => $label, 'default' => $default ] + $more;
	$em    = static fn( $label, $default, $more = [] ) => [ 'type' => 'em', 'label' => $label, 'default' => $default ] + $more;
	$area  = static fn( $label, $default, $more = [] ) => [ 'type' => 'textarea', 'label' => $label, 'default' => $default ] + $more;
	$head  = static function ( $eyebrow, $title, $link = null ) use ( $text, $em ) {
		$out = [
			'eyebrow' => $text( __( 'Small heading', 'moderno-child' ), $eyebrow ),
			'title'   => $em( __( 'Heading', 'moderno-child' ), $title ),
		];
		if ( null !== $link ) {
			$out['link'] = $text( __( 'Link text', 'moderno-child' ), $link, [ 'help' => __( 'The link at the right of the heading.', 'moderno-child' ) ] );
		}
		return $out;
	};
	// Prefix a section's head fields with its key: "mosaic.eyebrow" and so on.
	$prefix = static function ( $section, array $fields ) {
		$out = [];
		foreach ( $fields as $k => $f ) {
			$out[ $section . '.' . $k ] = $f;
		}
		return $out;
	};
	$images = static fn() => omc_images();
	$banner = static function ( $key, $label ) {
		return [
			'type'    => 'group',
			'label'   => $label,
			'fields'  => [
				'eyebrow' => [ 'type' => 'text', 'label' => __( 'Small heading', 'moderno-child' ) ],
				'title'   => [ 'type' => 'text', 'label' => __( 'Heading', 'moderno-child' ) ],
				'text'    => [ 'type' => 'textarea', 'label' => __( 'Text', 'moderno-child' ) ],
				'cta'     => [ 'type' => 'text', 'label' => __( 'Button text', 'moderno-child' ) ],
				'url'     => [ 'type' => 'url', 'label' => __( 'Button link', 'moderno-child' ) ],
			],
			'default' => static fn() => omc_home_banners()[ $key ] ?? [],
		];
	};
	$split = static function ( $key, $label ) use ( $banner ) {
		$b                     = $banner( $key, $label );
		$b['fields']           = [ 'images' => [ 'type' => 'images', 'label' => __( 'Photos', 'moderno-child' ), 'help' => __( 'Two or more cross-fade, one after the other.', 'moderno-child' ) ] ] + $b['fields'];
		return $b;
	};
	$wide = static function ( $key, $label ) use ( $banner ) {
		$b           = $banner( $key, $label );
		$b['fields'] = [ 'image' => [ 'type' => 'image', 'label' => __( 'Photo', 'moderno-child' ) ] ] + $b['fields'] + [
			'pos' => [
				'type'    => 'select',
				'label'   => __( 'Where the words sit', 'moderno-child' ),
				'options' => [
					'bl' => __( 'Bottom left', 'moderno-child' ),
					'br' => __( 'Bottom right', 'moderno-child' ),
					'c'  => __( 'Centre', 'moderno-child' ),
				],
			],
		];
		return $b;
	};

	$about      = omc_pe_about_id();
	$spots_help = __( 'Click the photo where a piece is, then choose it from the shop. Shoppers tap the point to see the piece and add it to the bag; two or more points add a “Shop the look” button for all of them. Drag a point to move it.', 'moderno-child' );
	$icons      = [
		'sparkle' => __( 'Sparkle', 'moderno-child' ),
		'hand'    => __( 'Hand', 'moderno-child' ),
		'return'  => __( 'Return arrow', 'moderno-child' ),
		'lock'    => __( 'Lock', 'moderno-child' ),
		'shield'  => __( 'Shield', 'moderno-child' ),
		'truck'   => __( 'Truck', 'moderno-child' ),
		'card'    => __( 'Card', 'moderno-child' ),
		'globe'   => __( 'Globe', 'moderno-child' ),
		'star'    => __( 'Star', 'moderno-child' ),
	];

	$schema = [
		'home'  => [
			'label'    => __( 'Home page', 'moderno-child' ),
			'sections' => [
				'hero'       => [
					'label'  => __( 'Hero', 'moderno-child' ),
					'note'   => __( 'The full-width photo at the very top.', 'moderno-child' ),
					'fields' => [
						'hero.images'  => [ 'type' => 'images', 'label' => __( 'Slideshow photos', 'moderno-child' ), 'help' => __( 'The first shows first and loads fastest. One photo turns the slideshow off.', 'moderno-child' ), 'default' => static fn() => omc_hero_images() ],
						'hero.spots'   => [ 'type' => 'spotmap', 'images' => 'hero.images', 'label' => __( 'Pieces in the photos', 'moderno-child' ), 'help' => $spots_help, 'default' => [] ],
						'hero.title'   => $text( __( 'Headline', 'moderno-child' ), __( 'Found with intention.', 'moderno-child' ) ),
						'hero.phrases' => [ 'type' => 'lines', 'label' => __( 'Second line, in turns', 'moderno-child' ), 'help' => __( 'One per line. The first is what search engines read; the rest take turns after it.', 'moderno-child' ), 'default' => static fn() => omc_hero_phrases() ],
						'hero.lede'    => $area( __( 'Intro', 'moderno-child' ), __( 'Curated Korean and Thai fashion for the pieces you weren’t looking for — and can’t leave without.', 'moderno-child' ) ),
						'hero.cta1'    => [ 'type' => 'link', 'label' => __( 'First button', 'moderno-child' ), 'default' => static fn() => [ 'text' => __( 'Shop distinctive finds', 'moderno-child' ), 'url' => omc_new_arrivals_url() ] ],
						'hero.cta2'    => [ 'type' => 'link', 'label' => __( 'Second button', 'moderno-child' ), 'default' => static fn() => [ 'text' => __( 'Our story', 'moderno-child' ), 'url' => omc_page_url( 'about-us' ) ] ],
					],
				],
				'usp'        => [
					'label'  => __( 'Trust strip', 'moderno-child' ),
					'note'   => __( 'The row of short promises under the hero. Only promise what the shop can keep.', 'moderno-child' ),
					'fields' => [
						'usp.items' => [
							'type'    => 'list',
							'label'   => __( 'Promises', 'moderno-child' ),
							'item'    => __( 'Promise', 'moderno-child' ),
							'max'     => 6,
							'fields'  => [
								'icon' => [ 'type' => 'select', 'label' => __( 'Icon', 'moderno-child' ), 'options' => $icons ],
								'text' => [ 'type' => 'text', 'label' => __( 'Text', 'moderno-child' ) ],
							],
							'default' => static fn() => omc_usp_items(),
						],
					],
				],
				'stylist'    => [
					'label'  => __( 'The stylist', 'moderno-child' ),
					'note'   => __( 'The band that opens the digital stylist. It shows only while the stylist is switched on in WHD.', 'moderno-child' ),
					'fields' => [
						'stylist.eyebrow' => $text( __( 'Small heading', 'moderno-child' ), __( 'The stylist', 'moderno-child' ) ),
						'stylist.titles'  => [ 'type' => 'lines', 'em' => true, 'label' => __( 'Questions, in turns', 'moderno-child' ), 'help' => __( 'One per line; they cross-fade. *Asterisks* set a word in italics.', 'moderno-child' ), 'default' => static fn() => array_map( 'omc_pe_unem', (array) ( omc_stylist_band()['titles'] ?? [] ) ) ],
						'stylist.text'    => $area( __( 'Text', 'moderno-child' ), __( 'Try our digital stylist, free, and we’ll recommend what’s best for you.', 'moderno-child' ) ),
						'stylist.button'  => $text( __( 'Button text', 'moderno-child' ), __( 'Start the stylist', 'moderno-child' ) ),
					],
				],
				'swipe'      => [
					'label'  => __( 'This or that', 'moderno-child' ),
					'note'   => __( 'A deck of the newest pieces to swipe through: right to love — it goes in the wishlist, and the stylist learns from it — left to skip.', 'moderno-child' ),
					'fields' => [
						'swipe.enabled' => [ 'type' => 'toggle', 'label' => __( 'Show the deck', 'moderno-child' ), 'default' => true ],
						'swipe.eyebrow' => $text( __( 'Small heading', 'moderno-child' ), __( 'This or that', 'moderno-child' ) ),
						'swipe.title'   => $em( __( 'Heading', 'moderno-child' ), __( 'Swipe what you *love*', 'moderno-child' ) ),
						'swipe.text'    => $area( __( 'Text', 'moderno-child' ), __( 'Right for love, left for not this one. Everything you love is saved to your wishlist, and our digital stylist takes it into account the next time it picks for you.', 'moderno-child' ) ),
						'swipe.done'    => $text( __( 'When the deck runs out', 'moderno-child' ), __( 'You’ve seen everything new.', 'moderno-child' ) ),
					],
				],
				'live'       => [
					'label'  => __( 'Facebook Live card', 'moderno-child' ),
					'note'   => __( 'The countdown card with the email form. The same settings as Customizer → Facebook Live.', 'moderno-child' ),
					'fields' => [
						'live.enabled' => [ 'type' => 'toggle', 'label' => __( 'Show the card', 'moderno-child' ), 'mod' => 'omc_live_enabled', 'default' => true ],
						'live.image'   => [ 'type' => 'image', 'label' => __( 'Photo', 'moderno-child' ), 'default' => static fn() => omc_images()['live'] ?? '' ],
						'live.when'    => [ 'type' => 'datetime', 'label' => __( 'Date and time of the live', 'moderno-child' ), 'mod' => 'omc_live_datetime', 'default' => '', 'help' => __( 'Left empty, it counts down to next Saturday at 8 pm.', 'moderno-child' ) ],
						'live.title'   => $text( __( 'Heading', 'moderno-child' ), __( 'Be first in the room', 'moderno-child' ), [ 'mod' => 'omc_live_title' ] ),
						'live.text'    => $area( __( 'Text', 'moderno-child' ), __( 'Leave your email and we’ll send you the link before we go live — plus live-only discounts for everyone who joins from it.', 'moderno-child' ), [ 'mod' => 'omc_live_text' ] ),
						'live.button'  => $text( __( 'Button text', 'moderno-child' ), __( 'Send me the link', 'moderno-child' ), [ 'mod' => 'omc_live_button' ] ),
						'live.url'     => [ 'type' => 'url', 'label' => __( 'Facebook page or live link', 'moderno-child' ), 'mod' => 'omc_live_url', 'default' => 'https://www.facebook.com/' ],
					],
				],
				'drop'       => [
					'label'  => __( 'Next drop', 'moderno-child' ),
					'note'   => __( 'A countdown to new arrivals with “Remind me”. Everyone who leaves an email gets the “Drop reminder — it is open” email (WHD → Emails) when it opens; then the card links to the drop for a week and goes away on its own.', 'moderno-child' ),
					'fields' => [
						'drop.enabled'    => [ 'type' => 'toggle', 'label' => __( 'Show the card', 'moderno-child' ), 'default' => false ],
						'drop.when'       => [ 'type' => 'datetime', 'label' => __( 'When it opens', 'moderno-child' ), 'default' => '', 'help' => __( 'Shop time. The card stays hidden until this is set.', 'moderno-child' ) ],
						'drop.image'      => [ 'type' => 'image', 'label' => __( 'Photo', 'moderno-child' ), 'default' => static fn() => omc_images()['edit_b'] ?? '' ],
						'drop.eyebrow'    => $text( __( 'Small heading', 'moderno-child' ), __( 'Next drop', 'moderno-child' ) ),
						'drop.title'      => $text( __( 'Name of the drop', 'moderno-child' ), __( 'The new drop', 'moderno-child' ), [ 'help' => __( 'The card’s heading, and what the reminder email calls it: “It’s open: The new drop”.', 'moderno-child' ) ] ),
						'drop.text'       => $area( __( 'Text', 'moderno-child' ), __( 'A small run of new pieces. Leave your name and email and we’ll write to you the moment it opens.', 'moderno-child' ) ),
						'drop.button'     => $text( __( 'Button text', 'moderno-child' ), __( 'Remind me', 'moderno-child' ) ),
						'drop.open_title' => $text( __( 'Heading once it is open', 'moderno-child' ), __( 'It’s open', 'moderno-child' ) ),
						'drop.open_text'  => $area( __( 'Text once it is open', 'moderno-child' ), __( 'The new pieces are live. Runs are small, so sizes go in the order people arrive.', 'moderno-child' ) ),
						'drop.link'       => [ 'type' => 'link', 'label' => __( 'Where the drop is', 'moderno-child' ), 'default' => static fn() => [ 'text' => __( 'Shop the drop', 'moderno-child' ), 'url' => omc_new_arrivals_url() ] ],
					],
				],
				'mosaic'     => [
					'label'  => __( 'Shop the look', 'moderno-child' ),
					'note'   => __( 'The photo grid of seven tiles.', 'moderno-child' ),
					'fields' => $prefix( 'mosaic', $head( __( 'Shop the look', 'moderno-child' ), __( 'Pieces we can’t stop *styling*', 'moderno-child' ), __( 'Shop all', 'moderno-child' ) ) ) + [
						'mosaic.tiles' => [
							'type'    => 'list',
							'label'   => __( 'Tiles', 'moderno-child' ),
							'item'    => __( 'Tile', 'moderno-child' ),
							'max'     => 12,
							'help'    => __( 'Seven tiles in this order keep the grid balanced: large, small, tall, small, small, wide, small.', 'moderno-child' ) . ' ' . $spots_help,
							'fields'  => [
								'image' => [ 'type' => 'image', 'label' => __( 'Photo', 'moderno-child' ) ],
								'label' => [ 'type' => 'text', 'label' => __( 'Label', 'moderno-child' ) ],
								'url'   => [ 'type' => 'url', 'label' => __( 'Link', 'moderno-child' ) ],
								'size'  => [
									'type'    => 'select',
									'label'   => __( 'Size', 'moderno-child' ),
									'options' => [
										'2x2' => __( 'Large (2 × 2)', 'moderno-child' ),
										'1x1' => __( 'Small (1 × 1)', 'moderno-child' ),
										'1x2' => __( 'Tall (1 × 2)', 'moderno-child' ),
										'2x1' => __( 'Wide (2 × 1)', 'moderno-child' ),
									],
								],
								'spots' => [ 'type' => 'spots', 'image' => 'image', 'label' => __( 'Pieces in this photo', 'moderno-child' ) ],
							],
							'default' => static fn() => array_map( static fn( $t ) => array_intersect_key( wp_parse_args( $t, [ 'image' => '', 'label' => '', 'url' => '', 'size' => '1x1' ] ), array_flip( [ 'image', 'label', 'url', 'size' ] ) ), omc_home_mosaic() ),
						],
					],
				],
				'campaign'   => [
					'label'  => __( 'Seoul edit banner', 'moderno-child' ),
					'note'   => __( 'Photos on the left, words on the right.', 'moderno-child' ),
					'fields' => [ 'campaign' => $split( 'campaign', __( 'Banner', 'moderno-child' ) ) ],
				],
				'carousel'   => [
					'label'  => __( 'Edit carousel', 'moderno-child' ),
					'note'   => __( 'The row of square slides that scrolls by itself.', 'moderno-child' ),
					'fields' => [
						'carousel' => [
							'type'    => 'list',
							'label'   => __( 'Slides', 'moderno-child' ),
							'item'    => __( 'Slide', 'moderno-child' ),
							'max'     => 16,
							'fields'  => [
								'image' => [ 'type' => 'image', 'label' => __( 'Photo', 'moderno-child' ) ],
								'title' => [ 'type' => 'text', 'label' => __( 'Label', 'moderno-child' ) ],
								'url'   => [ 'type' => 'url', 'label' => __( 'Link', 'moderno-child' ) ],
							],
							'default' => static fn() => omc_home_banners()['carousel'] ?? [],
						],
					],
				],
				'campaign2'  => [
					'label'  => __( 'Bangkok edit banner', 'moderno-child' ),
					'note'   => __( 'Words on the left, photos on the right.', 'moderno-child' ),
					'fields' => [ 'campaign2' => $split( 'campaign2', __( 'Banner', 'moderno-child' ) ) ],
				],
				'cats'       => [
					'label'  => __( 'Shop by category', 'moderno-child' ),
					'note'   => __( 'The category pictures are each category’s own thumbnail — change them in Products → Categories.', 'moderno-child' ),
					'fields' => [
						'cats.eyebrow' => $text( __( 'Small heading', 'moderno-child' ), __( 'Shop by category', 'moderno-child' ) ),
						'cats.title'   => $text( __( 'Heading', 'moderno-child' ), __( 'Find your next', 'moderno-child' ), [ 'help' => __( 'The words before the one that types itself out.', 'moderno-child' ) ] ),
						'cats.first'   => $text( __( 'First typed word', 'moderno-child' ), __( 'favourite', 'moderno-child' ) ),
						'cats.words'   => [ 'type' => 'lines', 'label' => __( 'Words it types next', 'moderno-child' ), 'help' => __( 'One per line.', 'moderno-child' ), 'default' => static fn() => omc_home_type_words() ],
						'cats.link'    => $text( __( 'Link text', 'moderno-child' ), __( 'View all', 'moderno-child' ) ),
					],
				],
				'styles'     => [
					'label'  => __( 'Shop by style', 'moderno-child' ),
					'note'   => __( 'The cards link to the style pages and carry their search phrases, so they are not edited here. Their photos are pieces from the shop that fit each style, picked again whenever a product changes.', 'moderno-child' ),
					'fields' => $prefix( 'styles', $head( __( 'Shop by style', 'moderno-child' ), __( 'Start with the look, *not the label*', 'moderno-child' ), __( 'Style notes', 'moderno-child' ) ) ) + [
						'styles.lede' => $area( __( 'Intro', 'moderno-child' ), __( 'Oops, Mine Co. is a chic boutique for Korean and Thai fashion, chosen in small batches and added every week. Pick the style you came for, then look twice at the rest.', 'moderno-child' ) ),
					],
				],
				'story'      => [
					'label'  => __( 'Our story band', 'moderno-child' ),
					'fields' => [
						'story.image'   => [ 'type' => 'image', 'label' => __( 'Background photo', 'moderno-child' ), 'help' => __( 'Shown blurred and darkened behind the words.', 'moderno-child' ), 'default' => static fn() => omc_images()['story'] ?? '' ],
						'story.eyebrow' => $text( __( 'Small heading', 'moderno-child' ), __( 'Our story', 'moderno-child' ) ),
						'story.title'   => $text( __( 'Heading', 'moderno-child' ), __( 'Oops. Mine.', 'moderno-child' ) ),
						'story.text'    => $area( __( 'Text', 'moderno-child' ), __( 'The name came from that wonderfully spontaneous moment when you discover something you weren’t planning to buy, but suddenly — oops, mine. That instinctive little claim is at the heart of everything we do.', 'moderno-child' ) ),
						'story.button'  => $text( __( 'Button text', 'moderno-child' ), __( 'Read our story', 'moderno-child' ) ),
					],
				],
				'new'        => [
					'label'  => __( 'New arrivals', 'moderno-child' ),
					'note'   => __( 'The products are the newest in the shop.', 'moderno-child' ),
					'fields' => $prefix( 'new', $head( __( 'Just landed', 'moderno-child' ), __( 'New *arrivals*', 'moderno-child' ), __( 'Shop all new', 'moderno-child' ) ) ),
				],
				'wide'       => [
					'label'  => __( 'Wide banners', 'moderno-child' ),
					'note'   => __( 'Three full-width photos, one under the other.', 'moderno-child' ),
					'fields' => [
						'wide'  => $wide( 'wide', __( 'First banner', 'moderno-child' ) ),
						'wide2' => $wide( 'wide2', __( 'Second banner', 'moderno-child' ) ),
						'wide3' => $wide( 'wide3', __( 'Third banner', 'moderno-child' ) ),
					],
				],
				'loved'      => [
					'label'  => __( 'Most loved', 'moderno-child' ),
					'fields' => $prefix( 'loved', $head( __( 'Most loved', 'moderno-child' ), __( 'The pieces everyone keeps *reaching for*', 'moderno-child' ), __( 'Shop best sellers', 'moderno-child' ) ) ),
				],
				'videos'     => [
					'label'  => __( 'Style videos', 'moderno-child' ),
					'note'   => __( 'Shows the three newest style videos.', 'moderno-child' ),
					'fields' => $prefix( 'videos', $head( __( 'Watch', 'moderno-child' ), __( 'See how it *moves*', 'moderno-child' ), __( 'All videos', 'moderno-child' ) ) ) + [
						'videos.lede' => $area( __( 'Intro', 'moderno-child' ), __( 'Photographs give you the colour. Video gives you the movement — how a korean fashion skirt swings when you walk, where a waistband actually sits, how a sheer panel reads in daylight. We film every rack before it goes online: the ruffles and organza, the chic boho clothes, the one statement fashion piece that gets asked about twice.', 'moderno-child' ), [ 'help' => __( 'Carries search phrases (korean fashion, chic boho clothes, statement fashion) — keep them if you rewrite it.', 'moderno-child' ) ] ),
					],
				],
				'journal'    => [
					'label'  => __( 'Journal', 'moderno-child' ),
					'note'   => __( 'Shows the three newest journal posts.', 'moderno-child' ),
					'fields' => $prefix( 'journal', $head( __( 'Styling notes', 'moderno-child' ), __( 'From the *journal*', 'moderno-child' ), __( 'All stories', 'moderno-child' ) ) ) + [
						'journal.spots' => [ 'type' => 'spotmap', 'choices' => static fn() => omc_pe_journal_photos(), 'label' => __( 'Pieces in the journal photos', 'moderno-child' ), 'help' => __( 'The photos of the three newest posts, as the home page shows them. A point stays with its photo, so it shows again wherever that photo is used.', 'moderno-child' ), 'default' => [] ],
					],
				],
				'quotes'     => [
					'label'  => __( 'Reviews', 'moderno-child' ),
					'note'   => __( 'Real 4- and 5-star reviews from the shop come first; the quotes below fill the band until there are enough.', 'moderno-child' ),
					'fields' => [
						'quotes.image'   => [ 'type' => 'image', 'label' => __( 'Background photo', 'moderno-child' ), 'default' => static fn() => omc_images()['quotes'] ?? '' ],
						'quotes.eyebrow' => $text( __( 'Small heading', 'moderno-child' ), __( 'Kind words', 'moderno-child' ) ),
						'quotes.title'   => $em( __( 'Heading', 'moderno-child' ), __( 'What they’re *saying*', 'moderno-child' ) ),
						'quotes.list'    => [
							'type'    => 'list',
							'label'   => __( 'Quotes', 'moderno-child' ),
							'item'    => __( 'Quote', 'moderno-child' ),
							'max'     => 8,
							'fields'  => [
								'text'   => [ 'type' => 'textarea', 'label' => __( 'What they said', 'moderno-child' ) ],
								'author' => [ 'type' => 'text', 'label' => __( 'First name', 'moderno-child' ) ],
							],
							'default' => static function () {
								$out = [];
								foreach ( (array) apply_filters( 'omc_testimonials_fallback', omc_curated_quotes() ) as $q ) {
									$out[] = [ 'text' => (string) ( $q['text'] ?? '' ), 'author' => (string) ( $q['author'] ?? '' ) ];
								}
								return $out;
							},
						],
					],
				],
				'newsletter' => [
					'label'  => __( 'Newsletter', 'moderno-child' ),
					'note'   => __( 'The sign-up band at the bottom of the page.', 'moderno-child' ),
					'fields' => [
						'newsletter.image'   => [ 'type' => 'image', 'label' => __( 'Background photo', 'moderno-child' ), 'default' => static fn() => omc_images()['newsletter'] ?? '' ],
						'newsletter.eyebrow' => $text( __( 'Small heading', 'moderno-child' ), __( 'Stay close', 'moderno-child' ) ),
						'newsletter.title'   => $em( __( 'Heading', 'moderno-child' ), __( 'Your next *“Oops, Mine”* moment', 'moderno-child' ) ),
						'newsletter.text'    => $area( __( 'Text', 'moderno-child' ), __( 'New arrivals, quiet restocks and the occasional note from us. No noise.', 'moderno-child' ) ),
					],
				],
			],
		],
		'about' => [
			'label'    => __( 'About page', 'moderno-child' ),
			'sections' => [
				'hero' => [
					'label'  => __( 'Hero', 'moderno-child' ),
					'note'   => __( 'The full-width photo at the top, with the page’s heading over it.', 'moderno-child' ),
					'fields' => [
						'hero.image'   => [
							'type'    => 'image',
							'label'   => __( 'Photo', 'moderno-child' ),
							'default' => static function () use ( $about ) {
								$thumb = $about ? (int) get_post_thumbnail_id( $about ) : 0;
								return $thumb ?: ( omc_images()['about_hero'] ?? '' );
							},
						],
						'hero.eyebrow' => $text( __( 'Small heading', 'moderno-child' ), __( 'About Oops, Mine Co.', 'moderno-child' ) ),
						'hero.heading' => $text( __( 'Heading', 'moderno-child' ), static fn() => $about ? html_entity_decode( get_the_title( $about ), ENT_QUOTES, 'UTF-8' ) : '', [ 'help' => __( 'The page’s own title (in menus and search results) stays as it is.', 'moderno-child' ) ] ),
						'hero.tagline' => $text( __( 'Line under the heading', 'moderno-child' ), static fn() => $about ? (string) get_post_meta( $about, 'omc_tagline', true ) : '' ),
					],
				],
				'body' => [
					'label'  => __( 'The story', 'moderno-child' ),
					'note'   => __( 'Everything between the hero and the closing band. Headings, lists and photos all work — use Add Media for a picture.', 'moderno-child' ),
					'fields' => [
						'body' => [ 'type' => 'rich', 'label' => __( 'Text', 'moderno-child' ), 'default' => static fn() => $about ? (string) get_post_field( 'post_content', $about ) : '' ],
					],
				],
				'cta'  => [
					'label'  => __( 'Closing band', 'moderno-child' ),
					'fields' => [
						'cta.eyebrow' => $text( __( 'Small heading', 'moderno-child' ), __( 'When you know, you know', 'moderno-child' ) ),
						'cta.title'   => $em( __( 'Heading', 'moderno-child' ), __( 'Find something *special*', 'moderno-child' ) ),
						'cta.button1' => [ 'type' => 'link', 'label' => __( 'First button', 'moderno-child' ), 'default' => static fn() => [ 'text' => __( 'Shop new arrivals', 'moderno-child' ), 'url' => omc_new_arrivals_url() ] ],
						'cta.button2' => [ 'type' => 'link', 'label' => __( 'Second button', 'moderno-child' ), 'default' => static fn() => [ 'text' => __( 'Get in touch', 'moderno-child' ), 'url' => omc_page_url( 'contacts', '/contacts/' ) ] ],
					],
				],
			],
		],
	];

	return $schema;
}

/** One field's definition. */
function omc_pe_field( $page, $key ) {
	foreach ( omc_pe_schema()[ $page ]['sections'] ?? [] as $section ) {
		if ( isset( $section['fields'][ $key ] ) ) {
			return $section['fields'][ $key ];
		}
	}
	return null;
}

/** "<em>x</em>" as the editor writes it: "*x*". */
function omc_pe_unem( $html ) {
	return wp_strip_all_tags( preg_replace( '#</?em>#', '*', (string) $html ) );
}

/* ─────────────────────────── values: one shape for every field ─────────────────────────── */

/**
 * A value in its stored shape: text trimmed and sanitised, pictures as attachment ids, links as
 * { text, url }, lists as lists. Defaults go through the same, so the two compare like for like.
 */
function omc_pe_clean( array $field, $value ) {
	switch ( $field['type'] ) {
		case 'text':
		case 'em':
			return sanitize_text_field( is_scalar( $value ) ? (string) $value : '' );
		case 'textarea':
			return trim( sanitize_textarea_field( is_scalar( $value ) ? (string) $value : '' ) );
		case 'url':
			return esc_url_raw( trim( is_scalar( $value ) ? (string) $value : '' ) );
		case 'rich':
			return trim( wp_kses_post( is_scalar( $value ) ? (string) $value : '' ) );
		case 'toggle':
			return (bool) $value;
		case 'datetime':
			return omc_sanitize_datetime_local( is_scalar( $value ) ? (string) $value : '' );
		case 'select':
			$value = is_scalar( $value ) ? (string) $value : '';
			return isset( $field['options'][ $value ] ) ? $value : (string) array_key_first( $field['options'] );
		case 'lines':
			$lines = is_array( $value ) ? $value : preg_split( '/\r\n|\r|\n/', (string) $value );
			return array_values( array_filter( array_map( static fn( $l ) => sanitize_text_field( is_scalar( $l ) ? (string) $l : '' ), array_slice( (array) $lines, 0, 20 ) ), 'strlen' ) );
		case 'link':
			$value = is_array( $value ) ? $value : [];
			return [
				'text' => sanitize_text_field( (string) ( $value['text'] ?? '' ) ),
				'url'  => esc_url_raw( trim( (string) ( $value['url'] ?? '' ) ) ),
			];
		case 'image':
			return omc_pe_image_id( $value );
		case 'images':
			return array_values( array_filter( array_map( 'omc_pe_image_id', array_slice( (array) $value, 0, 12 ) ) ) );
		case 'group':
			$value = is_array( $value ) ? $value : [];
			$out   = [];
			foreach ( $field['fields'] as $k => $sub ) {
				$out[ $k ] = omc_pe_clean( $sub, $value[ $k ] ?? null );
			}
			return $out;
		case 'list':
			$out = [];
			foreach ( array_slice( (array) $value, 0, (int) ( $field['max'] ?? 12 ) ) as $item ) {
				$out[] = omc_pe_clean( [ 'type' => 'group', 'fields' => $field['fields'] ], is_array( $item ) ? $item : [] );
			}
			return $out;
		case 'spots':
			return function_exists( 'omc_spots_clean' ) ? omc_spots_clean( $value ) : [];
		case 'spotmap':
			// Hotspots per photograph, keyed by its attachment id.
			$out = [];
			foreach ( is_array( $value ) ? $value : [] as $image => $spots ) {
				$image = absint( $image );
				$spots = function_exists( 'omc_spots_clean' ) ? omc_spots_clean( $spots ) : [];
				if ( $image && $spots ) {
					$out[ $image ] = $spots;
				}
			}
			return $out;
	}
	return null;
}

/** A picture as an attachment id: an id, an upload-relative path the library knows, or 0. */
function omc_pe_image_id( $value ) {
	if ( is_array( $value ) ) {
		$value = $value['id'] ?? 0;
	}
	$id = is_numeric( $value ) ? (int) $value : ( is_string( $value ) && '' !== $value ? omc_attachment_id( $value ) : 0 );
	return $id && wp_attachment_is_image( $id ) ? $id : 0;
}

/** What a field shows now: its theme mod, its saved value, or its default — in the stored shape. */
function omc_pe_current( $page, $key ) {
	$field = omc_pe_field( $page, $key );
	if ( ! $field ) {
		return null;
	}
	if ( ! empty( $field['mod'] ) ) {
		$mod = get_theme_mod( $field['mod'], null );
		if ( null !== $mod && '' !== $mod ) {
			return omc_pe_clean( $field, $mod );
		}
	}
	$saved = omc_pe_value( $page, $key );
	return omc_pe_clean( $field, null === $saved ? omc_pe_default( $page, $key ) : $saved );
}

/**
 * Save one page's fields. Each value is cleaned, then kept only if it differs from the default;
 * a field put back to its default is removed, so it follows the code again.
 *
 * @return array The page's current values, after saving.
 */
function omc_pe_save( $page, array $given ) {
	$schema = omc_pe_schema();
	if ( ! isset( $schema[ $page ] ) ) {
		return [];
	}
	$all   = (array) get_option( OMC_PE_OPTION, [] );
	$saved = isset( $all[ $page ] ) && is_array( $all[ $page ] ) ? $all[ $page ] : [];

	foreach ( $schema[ $page ]['sections'] as $section ) {
		foreach ( $section['fields'] as $key => $field ) {
			if ( ! array_key_exists( $key, $given ) ) {
				continue;
			}
			$value   = omc_pe_clean( $field, $given[ $key ] );
			$default = omc_pe_clean( $field, omc_pe_default( $page, $key ) );
			$same    = wp_json_encode( $value ) === wp_json_encode( $default );

			if ( ! empty( $field['mod'] ) ) {
				// The Customizer's own setting: an empty text field means "use the default" there too.
				if ( $same || '' === $value ) {
					remove_theme_mod( $field['mod'] );
				} else {
					set_theme_mod( $field['mod'], $value );
				}
				continue;
			}
			// Emptied means "as it was", never "nothing": every heading, photo and list needs something to show.
			$blank = in_array( $value, [ '', 0, [] ], true ) || ( 'link' === $field['type'] && '' === $value['text'] );
			if ( $same || $blank ) {
				unset( $saved[ $key ] );
			} else {
				$saved[ $key ] = $value;
			}
		}
	}

	$all[ $page ] = $saved;
	update_option( OMC_PE_OPTION, $all, true );
	omc_pe_saved( $page, true );

	$out = [];
	foreach ( $schema[ $page ]['sections'] as $section ) {
		foreach ( array_keys( $section['fields'] ) as $key ) {
			$out[ $key ] = omc_pe_current( $page, $key );
		}
	}
	return $out;
}

/* ─────────────────────────── the pages take the saved values ─────────────────────────── */

add_filter( 'omc_images', static function ( $images ) {
	if ( omc_pe_raw() ) {
		return $images;
	}
	foreach ( [ 'live' => 'live.image', 'story' => 'story.image', 'quotes' => 'quotes.image', 'newsletter' => 'newsletter.image' ] as $slot => $key ) {
		$id = (int) omc_pe_value( 'home', $key );
		if ( $id ) {
			$images[ $slot ] = $id;
		}
	}
	return $images;
}, 20 );

add_filter( 'omc_hero_images', static function ( $images ) {
	$saved = omc_pe_raw() ? null : omc_pe_value( 'home', 'hero.images' );
	return $saved ? (array) $saved : $images;
}, 20 );

add_filter( 'omc_hero_phrases', static function ( $phrases ) {
	$saved = omc_pe_raw() ? null : omc_pe_value( 'home', 'hero.phrases' );
	return $saved ? (array) $saved : $phrases;
}, 20 );

add_filter( 'omc_home_type_words', static function ( $words ) {
	$saved = omc_pe_raw() ? null : omc_pe_value( 'home', 'cats.words' );
	return $saved ? (array) $saved : $words;
}, 20 );

add_filter( 'omc_usp_items', static function ( $items ) {
	$saved = omc_pe_raw() ? null : omc_pe_value( 'home', 'usp.items' );
	return $saved ? (array) $saved : $items;
}, 20 );

add_filter( 'omc_home_mosaic', static function ( $tiles ) {
	$saved = omc_pe_raw() ? null : omc_pe_value( 'home', 'mosaic.tiles' );
	return $saved ? (array) $saved : $tiles;
}, 20 );

add_filter( 'omc_home_banners', static function ( $banners ) {
	if ( omc_pe_raw() ) {
		return $banners;
	}
	foreach ( [ 'campaign', 'campaign2', 'wide', 'wide2', 'wide3' ] as $key ) {
		$saved = omc_pe_value( 'home', $key );
		if ( is_array( $saved ) && isset( $banners[ $key ] ) ) {
			$banners[ $key ] = array_merge( $banners[ $key ], $saved );
		}
	}
	$slides = omc_pe_value( 'home', 'carousel' );
	if ( $slides ) {
		$banners['carousel'] = (array) $slides;
	}
	return $banners;
}, 20 );

add_filter( 'omc_stylist_band', static function ( $band ) {
	if ( omc_pe_raw() || ! is_array( $band ) ) {
		return $band;
	}
	foreach ( [ 'eyebrow', 'text', 'button' ] as $k ) {
		$saved = omc_pe_value( 'home', 'stylist.' . $k );
		if ( null !== $saved ) {
			$band[ $k ] = $saved;
		}
	}
	$titles = omc_pe_value( 'home', 'stylist.titles' );
	if ( $titles ) {
		// The template lets <em> through; the editor writes *asterisks*.
		$band['titles'] = array_map( 'omc_pe_em', (array) $titles );
		$band['title']  = $band['titles'][0];
	}
	return $band;
}, 20 );

add_filter( 'omc_testimonials_fallback', static function ( $quotes ) {
	$saved = omc_pe_raw() ? null : omc_pe_value( 'home', 'quotes.list' );
	if ( ! $saved ) {
		return $quotes;
	}
	return array_map( static fn( $q ) => [ 'text' => $q['text'], 'author' => $q['author'], 'image' => 0 ], (array) $saved );
}, 20 );

/* ─────────────────────────── the admin screen ─────────────────────────── */

add_action( 'admin_menu', static function () {
	add_menu_page(
		__( 'Page Editor', 'moderno-child' ),
		__( 'Page Editor', 'moderno-child' ),
		'edit_theme_options',
		OMC_PE_PAGE,
		'omc_pe_screen',
		'dashicons-layout',
		21
	);
} );

/** The editor, on one tab. */
function omc_pe_url( $page = 'home' ) {
	return add_query_arg( [ 'page' => OMC_PE_PAGE, 'tab' => $page ], admin_url( 'admin.php' ) );
}

/* A way in from where people look for it: the Pages list, and the admin bar on either page. */
add_filter( 'page_row_actions', static function ( $actions, $post ) {
	if ( ! current_user_can( 'edit_theme_options' ) ) {
		return $actions;
	}
	foreach ( [ 'home', 'about' ] as $page ) {
		if ( (int) $post->ID === omc_pe_page_id( $page ) ) {
			$actions = [ 'omc_pe' => '<a href="' . esc_url( omc_pe_url( $page ) ) . '"><strong>' . esc_html__( 'Edit in Page Editor', 'moderno-child' ) . '</strong></a>' ] + $actions;
		}
	}
	return $actions;
}, 10, 2 );

add_action( 'admin_bar_menu', static function ( WP_Admin_Bar $bar ) {
	if ( is_admin() || ! current_user_can( 'edit_theme_options' ) ) {
		return;
	}
	$page = omc_is_home_template() ? 'home' : ( omc_is_about_template() ? 'about' : '' );
	if ( $page ) {
		$bar->add_node( [
			'id'    => 'omc-page-editor',
			'title' => '<span class="ab-icon dashicons dashicons-layout" aria-hidden="true"></span>' . esc_html__( 'Edit this page', 'moderno-child' ),
			'href'  => omc_pe_url( $page ),
		] );
	}
}, 80 );

add_action( 'admin_enqueue_scripts', static function () {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks which screen this is
	if ( ! isset( $_GET['page'] ) || OMC_PE_PAGE !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
		return;
	}
	$ver = static fn( $rel ) => file_exists( OMC_DIR . $rel ) ? (string) filemtime( OMC_DIR . $rel ) : OMC_VERSION;
	wp_enqueue_media();
	wp_enqueue_editor();
	wp_enqueue_style( 'omc-pe-fonts', 'https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,500;1,500&display=swap', [], null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
	wp_enqueue_style( 'omc-page-editor', OMC_URI . '/assets/css/page-editor.css', [], $ver( '/assets/css/page-editor.css' ) );
	if ( function_exists( 'omc_piece_picker_assets' ) ) {
		omc_piece_picker_assets();
	}
	wp_enqueue_script( 'omc-page-editor', OMC_URI . '/assets/js/page-editor.js', [ 'media-editor', 'editor', 'omc-piece-picker' ], $ver( '/assets/js/page-editor.js' ), true );

	$pages  = [];
	$media  = [];
	$pieces = [];
	foreach ( omc_pe_schema() as $page => $def ) {
		$id       = omc_pe_page_id( $page );
		$sections = [];
		$values   = [];
		$defaults = [];
		foreach ( $def['sections'] as $skey => $section ) {
			$fields = [];
			foreach ( $section['fields'] as $key => $field ) {
				$values[ $key ]   = omc_pe_current( $page, $key );
				$defaults[ $key ] = omc_pe_clean( $field, omc_pe_default( $page, $key ) );
				unset( $field['default'] );
				if ( isset( $field['choices'] ) && $field['choices'] instanceof Closure ) {
					$field['choices'] = ( $field['choices'] )();
				}
				omc_pe_collect_pieces( $field, $values[ $key ], $pieces );
				$fields[] = [ 'key' => $key ] + $field;
				omc_pe_collect_media( $field, $values[ $key ], $media );
				omc_pe_collect_media( $field, $defaults[ $key ], $media );
			}
			$sections[] = [ 'key' => $skey, 'label' => $section['label'], 'note' => $section['note'] ?? '', 'fields' => $fields ];
		}
		$pages[ $page ] = [
			'label'     => $def['label'],
			'view'      => $id ? (string) get_permalink( $id ) : home_url( '/' ),
			'keyphrase' => $id ? (string) get_post_meta( $id, '_whd_focus_keyphrase', true ) : '',
			'sections'  => $sections,
			'values'    => $values,
			'defaults'  => $defaults,
		];
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks the tab
	$tab = isset( $_GET['tab'] ) && 'about' === $_GET['tab'] ? 'about' : 'home';
	wp_localize_script( 'omc-page-editor', 'OMC_PE', [
		'rest'  => esc_url_raw( rest_url( 'omc/v1/page-editor' ) ),
		'nonce' => wp_create_nonce( 'wp_rest' ),
		'tab'   => $tab,
		'pages'  => $pages,
		'media'  => (object) $media,
		'pieces' => (object) ( function_exists( 'omc_piece_brief' ) ? omc_piece_brief( $pieces ) : [] ),
		'i18n'  => [
			'save'       => __( 'Save changes', 'moderno-child' ),
			'saving'     => __( 'Saving…', 'moderno-child' ),
			'saved'      => __( 'All changes saved', 'moderno-child' ),
			'unsaved'    => __( 'Unsaved changes', 'moderno-child' ),
			'failed'     => __( 'Could not save', 'moderno-child' ),
			'view'       => __( 'View page', 'moderno-child' ),
			'reset'      => __( 'Reset to default', 'moderno-child' ),
			'changed'    => __( 'Changed', 'moderno-child' ),
			'choose'     => __( 'Choose photo', 'moderno-child' ),
			'change'     => __( 'Change', 'moderno-child' ),
			'addPhotos'  => __( 'Add photos', 'moderno-child' ),
			'use'        => __( 'Use this photo', 'moderno-child' ),
			'useMany'    => __( 'Add these photos', 'moderno-child' ),
			'remove'     => __( 'Remove', 'moderno-child' ),
			'moveUp'     => __( 'Move up', 'moderno-child' ),
			'moveDown'   => __( 'Move down', 'moderno-child' ),
			'moveLeft'   => __( 'Move left', 'moderno-child' ),
			'moveRight'  => __( 'Move right', 'moderno-child' ),
			/* translators: %s: what one item is called, e.g. Tile */
			'add'        => __( 'Add %s', 'moderno-child' ),
			'noImage'    => __( 'No photo', 'moderno-child' ),
			'linkText'   => __( 'Button text', 'moderno-child' ),
			'linkUrl'    => __( 'Link', 'moderno-child' ),
			'onePerLine' => __( 'One per line', 'moderno-child' ),
			'emHint'     => __( 'Put *asterisks* around a word to set it in italics.', 'moderno-child' ),
			/* translators: %s: the search phrase */
			'keyphrase'  => __( 'This page is aimed at the search phrase “%s” — keep it somewhere in the words.', 'moderno-child' ),
			'leave'      => __( 'You have changes that are not saved. Leave anyway?', 'moderno-child' ),
			'sections'   => __( 'Sections', 'moderno-child' ),
			'shown'      => __( 'Shown', 'moderno-child' ),
			'spotsNone'  => __( 'Choose the photo first, then put the pieces on it.', 'moderno-child' ),
			'spotsEmpty' => __( 'No pieces on this photo yet — click where one is.', 'moderno-child' ),
			'spotsMax'   => __( 'Twelve points is the most a photo takes.', 'moderno-child' ),
			'noPhotos'   => __( 'There are no photos here yet.', 'moderno-child' ),
			/* translators: %d: number of points */
			'spotsCount' => __( '%d on it', 'moderno-child' ),
			'changePc'   => __( 'Change piece', 'moderno-child' ),
			'unknown'    => __( 'A piece no longer in the shop', 'moderno-child' ),
		],
	] );
} );

/** Every picture a value or a default refers to, with a thumbnail to show it by. */
function omc_pe_collect_media( array $field, $value, array &$media ) {
	$add = static function ( $id ) use ( &$media ) {
		$id = (int) $id;
		if ( $id && ! isset( $media[ $id ] ) ) {
			$media[ $id ] = [
				'thumb' => (string) wp_get_attachment_image_url( $id, 'medium' ),
				'large' => (string) wp_get_attachment_image_url( $id, 'large' ),
				'name'  => (string) wp_basename( (string) get_attached_file( $id ) ),
			];
		}
	};
	switch ( $field['type'] ) {
		case 'image':
			$add( $value );
			break;
		case 'images':
			array_map( $add, (array) $value );
			break;
		case 'group':
			foreach ( $field['fields'] as $k => $sub ) {
				omc_pe_collect_media( $sub, $value[ $k ] ?? null, $media );
			}
			break;
		case 'list':
			foreach ( (array) $value as $item ) {
				omc_pe_collect_media( [ 'type' => 'group', 'fields' => $field['fields'] ], $item, $media );
			}
			break;
		case 'spotmap':
			array_map( $add, array_keys( (array) $value ) );
			foreach ( (array) ( $field['choices'] ?? [] ) as $choice ) {
				$add( $choice['image'] ?? 0 );
			}
			break;
	}
}

/** Every product a hotspot field points at. */
function omc_pe_collect_pieces( array $field, $value, array &$ids ) {
	switch ( $field['type'] ) {
		case 'spots':
			foreach ( (array) $value as $s ) {
				$ids[] = (int) ( $s['product'] ?? 0 );
			}
			break;
		case 'spotmap':
			foreach ( (array) $value as $spots ) {
				omc_pe_collect_pieces( [ 'type' => 'spots' ], $spots, $ids );
			}
			break;
		case 'group':
			foreach ( $field['fields'] as $k => $sub ) {
				omc_pe_collect_pieces( $sub, $value[ $k ] ?? null, $ids );
			}
			break;
		case 'list':
			foreach ( (array) $value as $item ) {
				omc_pe_collect_pieces( [ 'type' => 'group', 'fields' => $field['fields'] ], $item, $ids );
			}
			break;
	}
}

/** The journal photos the home page shows: the three newest posts' pictures. */
function omc_pe_journal_photos() {
	$out = [];
	foreach ( get_posts( [ 'numberposts' => 3, 'post_status' => 'publish', 'ignore_sticky_posts' => true ] ) as $post ) {
		$thumb = (int) get_post_thumbnail_id( $post );
		if ( $thumb ) {
			$out[] = [ 'image' => $thumb, 'label' => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ) ];
		}
	}
	return $out;
}

/** The saved hotspots of a home-page field ("hero.spots", "journal.spots"): [ attachment id => spots ]. */
function omc_pe_spotmap( $key ) {
	$value = omc_pe( 'home', $key, [] );
	return is_array( $value ) ? $value : [];
}

function omc_pe_screen() {
	?>
	<div class="wrap omc-pe" id="omc-pe">
		<header class="omc-pe__head">
			<p class="omc-pe__eyebrow"><?php esc_html_e( 'Site', 'moderno-child' ); ?></p>
			<h1 class="omc-pe__title"><?php esc_html_e( 'Page Editor', 'moderno-child' ); ?></h1>
			<p class="omc-pe__lead"><?php esc_html_e( 'The words and photos of the home page and the About page, section by section in the order they appear. Change what you like and save — anything you put back to how it was follows the theme again.', 'moderno-child' ); ?></p>
		</header>
		<hr class="wp-header-end">
		<noscript><div class="notice notice-error"><p><?php esc_html_e( 'The Page Editor needs JavaScript.', 'moderno-child' ); ?></p></div></noscript>
		<div class="omc-pe__app" id="omc-pe-app"></div>
	</div>
	<?php
}

/* ─────────────────────────── saving ─────────────────────────── */

add_action( 'rest_api_init', static function () {
	register_rest_route( 'omc/v1', '/page-editor', [
		'methods'             => 'POST',
		'permission_callback' => static fn() => current_user_can( 'edit_theme_options' ),
		'callback'            => static function ( WP_REST_Request $r ) {
			$out   = [];
			$media = [];
			foreach ( (array) $r->get_param( 'pages' ) as $page => $values ) {
				$page = sanitize_key( $page );
				if ( isset( omc_pe_schema()[ $page ] ) && is_array( $values ) ) {
					$out[ $page ] = omc_pe_save( $page, $values );
					foreach ( $out[ $page ] as $key => $value ) {
						omc_pe_collect_media( omc_pe_field( $page, $key ), $value, $media );
					}
				}
			}
			return new WP_REST_Response( [ 'values' => $out, 'media' => (object) $media ], 200 );
		},
	] );
} );
