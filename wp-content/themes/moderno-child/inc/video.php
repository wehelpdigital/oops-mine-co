<?php
/**
 * Style videos: data access, structured data and shared render helpers.
 *
 * Each video page is a normal WordPress page using templates/page-video.php, with the video's JSON
 * (see .ftp-sync/content/videos/*.json) stored in post meta `_omc_video`. The hub page uses
 * templates/page-videos.php and lists every published video page, newest first.
 *
 * The media itself is a real media-library attachment (MP4 + poster JPG) imported by
 * .ftp-sync/import-videos.php, so the owner can re-use both from the admin like any other upload.
 *
 * @package moderno-child
 */

defined( 'ABSPATH' ) || exit;

const OMC_VIDEO_META = '_omc_video';

/** Decoded video data for a page, with defaults. Returns null when the page carries no video. */
function omc_video_data( $post_id = 0 ) {
	$post_id = $post_id ?: get_the_ID();
	$raw     = get_post_meta( $post_id, OMC_VIDEO_META, true );
	$data    = is_string( $raw ) ? json_decode( $raw, true ) : ( is_array( $raw ) ? $raw : null );
	if ( ! is_array( $data ) || empty( $data['slug'] ) ) {
		return null;
	}
	return wp_parse_args( $data, [
		'slug'        => '',
		'title'       => get_the_title( $post_id ),
		'seo_title'   => '',
		'meta_description' => '',
		'intro'       => '',
		'alt'         => '',
		'orientation' => 'portrait',
		'upload_date' => get_the_date( 'Y-m-d', $post_id ),
		'attachment'  => 0,
		'poster_id'   => 0,
		'src'         => '',
		'poster_url'  => '',
		'width'       => 0,
		'height'      => 0,
		'duration'    => 0,
		'chapters'    => [],
		'sections'    => [],
		'faqs'        => [],
		'shop'        => [],
		'related'     => [],
	] );
}

/** Video file URL: the imported attachment first, then whatever the JSON recorded. */
function omc_video_src( array $v ) {
	if ( ! empty( $v['attachment'] ) && wp_get_attachment_url( (int) $v['attachment'] ) ) {
		return wp_get_attachment_url( (int) $v['attachment'] );
	}
	return (string) $v['src'];
}

function omc_video_poster( array $v, $size = 'large' ) {
	if ( ! empty( $v['poster_id'] ) ) {
		$url = wp_get_attachment_image_url( (int) $v['poster_id'], $size );
		if ( $url ) {
			return $url;
		}
	}
	return (string) $v['poster_url'];
}

/** Seconds → ISO 8601 duration (PT1M23S), the format schema.org and Google expect. */
function omc_video_iso_duration( $seconds ) {
	$seconds = (int) round( (float) $seconds );
	$m       = intdiv( $seconds, 60 );
	$s       = $seconds % 60;
	return 'PT' . ( $m ? $m . 'M' : '' ) . $s . 'S';
}

/** Seconds → 0:35, for the on-page chapter list. */
function omc_video_clock( $seconds ) {
	$seconds = (int) round( (float) $seconds );
	return intdiv( $seconds, 60 ) . ':' . str_pad( (string) ( $seconds % 60 ), 2, '0', STR_PAD_LEFT );
}

/** Every published video page, newest first: [ post_id => data ]. */
function omc_video_pages() {
	$pages = get_posts( [
		'post_type'      => 'page',
		'post_status'    => 'publish',
		'numberposts'    => 50,
		'meta_key'       => OMC_VIDEO_META,
		'orderby'        => 'menu_order date',
		'order'          => 'ASC',
		'suppress_filters' => false,
	] );
	$out = [];
	foreach ( $pages as $p ) {
		$data = omc_video_data( $p->ID );
		if ( $data ) {
			$out[ $p->ID ] = $data;
		}
	}
	return $out;
}

/** The VideoObject node for one video (array, ready for the @graph). */
function omc_video_schema_node( array $v, $permalink ) {
	$src    = omc_video_src( $v );
	$poster = omc_video_poster( $v, 'full' );
	$node   = [
		'@type'        => 'VideoObject',
		'@id'          => $permalink . '#video',
		'name'         => wp_strip_all_tags( $v['title'] ),
		'description'  => wp_strip_all_tags( $v['meta_description'] ?: $v['intro'] ),
		'uploadDate'   => gmdate( 'c', strtotime( $v['upload_date'] . ' 12:00:00' ) ),
		'url'          => $permalink,
		'publisher'    => [ '@id' => home_url( '/' ) . '#organization' ],
	];
	if ( $poster ) {
		$node['thumbnailUrl'] = [ $poster ];
	}
	if ( $src ) {
		$node['contentUrl'] = $src;
	}
	if ( ! empty( $v['duration'] ) ) {
		$node['duration'] = omc_video_iso_duration( $v['duration'] );
	}
	if ( ! empty( $v['width'] ) && ! empty( $v['height'] ) ) {
		$node['width']  = (int) $v['width'];
		$node['height'] = (int) $v['height'];
	}
	// Key moments: Google shows these as jump-to links under the result.
	$clips = [];
	$total = (float) $v['duration'];
	$marks = array_values( array_filter( (array) $v['chapters'], function ( $c ) {
		return isset( $c['at'], $c['label'] );
	} ) );
	foreach ( $marks as $i => $c ) {
		$start = (int) $c['at'];
		$end   = isset( $marks[ $i + 1 ] ) ? (int) $marks[ $i + 1 ]['at'] : (int) floor( $total );
		if ( $end <= $start ) {
			continue;
		}
		$clips[] = [
			'@type'       => 'Clip',
			'name'        => wp_strip_all_tags( $c['label'] ),
			'startOffset' => $start,
			'endOffset'   => $end,
			'url'         => $permalink . '#t=' . $start,
		];
	}
	if ( $clips ) {
		$node['hasPart'] = $clips;
	}
	return $node;
}

/** VideoObject (+ FAQPage) for a single video page; ItemList for the hub. */
function omc_video_schema() {
	if ( omc_has_seo_plugin() ) {
		return;
	}
	$graph = [];

	if ( is_page_template( 'templates/page-video.php' ) ) {
		$v = omc_video_data();
		if ( ! $v ) {
			return;
		}
		$graph[] = omc_video_schema_node( $v, get_permalink() );
		$faqs    = [];
		foreach ( (array) $v['faqs'] as $f ) {
			if ( empty( $f['q'] ) || empty( $f['a'] ) ) {
				continue;
			}
			$faqs[] = [
				'@type'          => 'Question',
				'name'           => wp_strip_all_tags( $f['q'] ),
				'acceptedAnswer' => [ '@type' => 'Answer', 'text' => wp_strip_all_tags( $f['a'] ) ],
			];
		}
		if ( $faqs ) {
			$graph[] = [ '@type' => 'FAQPage', '@id' => get_permalink() . '#faq', 'mainEntity' => $faqs ];
		}
	} elseif ( is_page_template( 'templates/page-videos.php' ) ) {
		$items = [];
		$i     = 0;
		foreach ( omc_video_pages() as $pid => $v ) {
			$i++;
			$items[] = [
				'@type'    => 'ListItem',
				'position' => $i,
				'item'     => omc_video_schema_node( $v, get_permalink( $pid ) ),
			];
		}
		if ( ! $items ) {
			return;
		}
		$graph[] = [
			'@type'           => 'ItemList',
			'@id'             => get_permalink() . '#videolist',
			'name'            => wp_strip_all_tags( get_the_title() ),
			'numberOfItems'   => count( $items ),
			'itemListElement' => $items,
		];
	} else {
		return;
	}

	echo '<script type="application/ld+json">' . wp_json_encode( [ '@context' => 'https://schema.org', '@graph' => $graph ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
}
add_action( 'wp_head', 'omc_video_schema', 6 );

/** The page's own meta description / title, when no SEO plugin owns the head. */
function omc_video_meta_description( $desc ) {
	if ( is_page_template( 'templates/page-video.php' ) || is_page_template( 'templates/page-videos.php' ) ) {
		$v = omc_video_data();
		if ( $v && ! empty( $v['meta_description'] ) ) {
			return $v['meta_description'];
		}
		$stored = get_post_meta( get_the_ID(), '_omc_meta_description', true );
		if ( $stored ) {
			return $stored;
		}
	}
	return $desc;
}
add_filter( 'omc_meta_description', 'omc_video_meta_description' );

function omc_video_document_title( $parts ) {
	if ( is_page_template( 'templates/page-video.php' ) || is_page_template( 'templates/page-videos.php' ) ) {
		$v   = omc_video_data();
		$seo = $v && ! empty( $v['seo_title'] ) ? $v['seo_title'] : get_post_meta( get_the_ID(), '_omc_seo_title', true );
		if ( $seo ) {
			$parts['title'] = $seo;
		}
	}
	return $parts;
}
add_filter( 'document_title_parts', 'omc_video_document_title' );

function omc_video_body_class( $classes ) {
	if ( is_page_template( 'templates/page-video.php' ) ) {
		$classes[] = 'omc-video-page';
	}
	if ( is_page_template( 'templates/page-videos.php' ) ) {
		$classes[] = 'omc-videos-page';
	}
	return $classes;
}
add_filter( 'body_class', 'omc_video_body_class' );

/* ───────────────────────────── render helpers ───────────────────────────── */

/** The player: poster, native controls, preload=none so the page stays light. */
function omc_video_player( array $v, $autoplay_muted = false ) {
	$src = omc_video_src( $v );
	if ( ! $src ) {
		return;
	}
	$poster = omc_video_poster( $v, 'large' );
	$ratio  = ( ! empty( $v['width'] ) && ! empty( $v['height'] ) ) ? ( (int) $v['width'] . ' / ' . (int) $v['height'] ) : ( 'square' === $v['orientation'] ? '1 / 1' : '9 / 16' );
	printf(
		'<div class="omc-video__frame omc-video__frame--%1$s" style="--omc-video-ratio:%2$s">
			<video class="omc-video__player js-omc-video" %3$s%4$s playsinline preload="none" controls%5$s aria-label="%6$s">
				<source src="%7$s" type="video/mp4">
			</video>
		</div>',
		esc_attr( $v['orientation'] ),
		esc_attr( $ratio ),
		$poster ? 'poster="' . esc_url( $poster ) . '" ' : '',
		( ! empty( $v['width'] ) ? 'width="' . (int) $v['width'] . '" height="' . (int) $v['height'] . '"' : '' ),
		$autoplay_muted ? ' muted loop' : '',
		esc_attr( $v['alt'] ?: $v['title'] ),
		esc_url( $src )
	);
}

function omc_video_chapters( array $v ) {
	$marks = array_filter( (array) $v['chapters'], function ( $c ) {
		return isset( $c['at'], $c['label'] );
	} );
	if ( ! $marks ) {
		return;
	}
	echo '<div class="omc-video__chapters"><p class="omc-eyebrow">' . esc_html__( 'In this video', 'moderno-child' ) . '</p><ol class="omc-video__chapter-list">';
	foreach ( $marks as $c ) {
		printf(
			'<li><button type="button" class="omc-video__chapter js-omc-video-seek" data-at="%1$d"><span class="omc-video__time">%2$s</span><span class="omc-video__label">%3$s</span></button></li>',
			(int) $c['at'],
			esc_html( omc_video_clock( $c['at'] ) ),
			esc_html( $c['label'] )
		);
	}
	echo '</ol></div>';
}

/**
 * The written version of a video: heading, paragraphs, bullets.
 *
 * Deliberately self-contained rather than borrowing the landing-page renderer — a video page
 * should keep working whatever happens to that file, and the styling lives in omc-video.css.
 */
function omc_video_sections( array $sections ) {
	foreach ( $sections as $s ) {
		$s = (array) $s;
		if ( ! empty( $s['heading'] ) ) {
			echo '<h2 class="omc-video__heading">' . esc_html( $s['heading'] ) . '</h2>';
		}
		foreach ( (array) ( $s['paragraphs'] ?? [] ) as $p ) {
			echo '<p>' . wp_kses_post( $p ) . '</p>';
		}
		if ( ! empty( $s['bullets'] ) ) {
			echo '<ul class="omc-video__bullets">';
			foreach ( (array) $s['bullets'] as $b ) {
				echo '<li>' . wp_kses_post( $b ) . '</li>';
			}
			echo '</ul>';
		}
	}
}

function omc_video_faqs( array $faqs ) {
	$faqs = array_filter( (array) $faqs, function ( $f ) {
		return ! empty( $f['q'] ) && ! empty( $f['a'] );
	} );
	if ( ! $faqs ) {
		return;
	}
	echo '<div class="omc-video__faq"><h2 class="omc-video__heading">' . esc_html__( 'Questions we get', 'moderno-child' ) . '</h2>';
	foreach ( $faqs as $f ) {
		echo '<details class="omc-faq"><summary>' . esc_html( $f['q'] ) . '</summary><div class="omc-faq__a"><p>' . wp_kses_post( $f['a'] ) . '</p></div></details>';
	}
	echo '</div>';
}

function omc_video_shop_links( array $links ) {
	$links = array_filter( (array) $links, function ( $l ) {
		return ! empty( $l['text'] ) && ! empty( $l['url'] );
	} );
	if ( ! $links ) {
		return;
	}
	echo '<div class="omc-video__shop">';
	$first = true;
	foreach ( $links as $l ) {
		printf(
			'<a class="omc-btn %1$s" href="%2$s">%3$s</a>',
			$first ? 'omc-btn--solid' : 'omc-btn--outline',
			esc_url( home_url( $l['url'] ) ),
			esc_html( $l['text'] )
		);
		$first = false;
	}
	echo '</div>';
}

/** Cards for the hub and for "More to watch". */
function omc_video_card( $post_id, array $v ) {
	$poster = omc_video_poster( $v, 'large' );
	printf(
		'<a class="omc-video-card omc-video-card--%1$s" href="%2$s">
			<span class="omc-video-card__media">%3$s<span class="omc-video-card__play" aria-hidden="true"></span>%4$s</span>
			<span class="omc-video-card__body">
				<span class="omc-video-card__title">%5$s</span>
				<span class="omc-video-card__text">%6$s</span>
			</span>
		</a>',
		esc_attr( $v['orientation'] ),
		esc_url( get_permalink( $post_id ) ),
		$poster ? '<img src="' . esc_url( $poster ) . '" alt="' . esc_attr( $v['alt'] ?: $v['title'] ) . '" loading="lazy" decoding="async">' : '',
		! empty( $v['duration'] ) ? '<span class="omc-video-card__time">' . esc_html( omc_video_clock( $v['duration'] ) ) . '</span>' : '',
		esc_html( $v['title'] ),
		esc_html( wp_trim_words( $v['meta_description'] ?: $v['intro'], 20, '…' ) )
	);
}
