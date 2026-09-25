<?php
/**
 * Oops, Mine Co. — content pages (landing / FAQ / contact / policy).
 *
 * The publish script writes one JSON document per page into the post meta
 * `_omc_landing` (the content contract: hero, sections, faqs, cta, category,
 * related_slugs…) and a plain-HTML copy of the same words into post_content.
 * The four templates in templates/page-{landing,faq,contact,policy}.php render
 * the JSON through the helpers below; when the meta is missing they fall back
 * to the_content(), so a page is never blank.
 *
 * Everything here is prefixed `omc_landing_` (plus the contact-page helpers)
 * and lives in the child theme. functions.php only needs one require_once.
 *
 * @package moderno-child
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'OMC_LANDING_META' ) ) {
	define( 'OMC_LANDING_META', '_omc_landing' );
}

/* ───────────────────────── Templates & assets ───────────────────────── */

/** The page templates that render a content document. */
function omc_landing_templates() {
	return [
		'templates/page-landing.php',
		'templates/page-faq.php',
		'templates/page-contact.php',
		'templates/page-policy.php',
	];
}

/**
 * True on (or for) a page that uses one of the four content templates.
 *
 * @param int|WP_Post|null $post Optional post; defaults to the queried page.
 */
function omc_is_landing_template( $post = null ) {
	if ( null === $post ) {
		if ( ! is_page() ) {
			return false;
		}
		$post = get_queried_object_id();
	}
	return in_array( (string) get_page_template_slug( $post ), omc_landing_templates(), true );
}

/** Which of the four templates is in play: landing | faq | contact | policy. */
function omc_landing_template_type( $post = null ) {
	if ( null === $post ) {
		$post = is_page() ? get_queried_object_id() : 0;
	}
	$slug = (string) get_page_template_slug( $post );
	return preg_match( '#templates/page-(landing|faq|contact|policy)\.php#', $slug, $m ) ? $m[1] : '';
}

/** Stylesheet + script, only on the content templates. */
function omc_landing_assets() {
	if ( ! omc_is_landing_template() ) {
		return;
	}
	$ver = static function ( $rel ) {
		return file_exists( OMC_DIR . $rel ) ? (string) filemtime( OMC_DIR . $rel ) : ( defined( 'OMC_VERSION' ) ? OMC_VERSION : '1.0' );
	};
	wp_enqueue_style( 'omc-landing', OMC_URI . '/assets/css/omc-landing.css', [ 'omc-pages' ], $ver( '/assets/css/omc-landing.css' ) );
	wp_enqueue_script( 'omc-landing', OMC_URI . '/assets/js/omc-landing.js', [], $ver( '/assets/js/omc-landing.js' ), true );
	wp_localize_script( 'omc-landing', 'OMC_LANDING', [
		'ajax'  => admin_url( 'admin-ajax.php' ),
		'nonce' => wp_create_nonce( 'omc_contact' ),
	] );
}
add_action( 'wp_enqueue_scripts', 'omc_landing_assets', 1001 );

/** Body classes: one for the family, one per template. */
add_filter( 'body_class', function ( $classes ) {
	if ( omc_is_landing_template() ) {
		$classes[] = 'omc-landing-page';
		$type      = omc_landing_template_type();
		if ( $type ) {
			$classes[] = 'omc-landing-page--' . $type;
		}
	}
	return $classes;
} );

/* ────────────────────────────── The data ────────────────────────────── */

/** Inline HTML a writer may use inside a paragraph, bullet or answer. */
function omc_landing_allowed_inline() {
	return [
		'a'      => [ 'href' => [], 'title' => [], 'rel' => [], 'target' => [], 'class' => [] ],
		'em'     => [],
		'i'      => [],
		'strong' => [],
		'b'      => [],
		'br'     => [],
		'small'  => [],
		'span'   => [ 'class' => [] ],
		'sup'    => [],
		'sub'    => [],
		'abbr'   => [ 'title' => [] ],
		'time'   => [ 'datetime' => [] ],
	];
}

/** Safe rich string (keeps links and emphasis). */
function omc_landing_text( $value ) {
	return trim( wp_kses( (string) $value, omc_landing_allowed_inline() ) );
}

/** Safe plain string (nav labels, JSON-LD, meta description). */
function omc_landing_plain( $value ) {
	return trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $value ) ) );
}

/** Heading string: the site's rose-gold title with one italic word in ink. */
function omc_landing_heading( $value ) {
	return trim( wp_kses( (string) $value, [ 'em' => [], 'i' => [], 'br' => [], 'span' => [ 'class' => [] ], 'strong' => [] ] ) );
}

/** A list of rich strings from a string|array value. */
function omc_landing_lines( $value ) {
	$out = [];
	foreach ( (array) $value as $line ) {
		$line = omc_landing_text( $line );
		if ( '' !== $line ) {
			$out[] = $line;
		}
	}
	return $out;
}

/** True when the page carries a content document. */
function omc_landing_has_data( $post_id = 0 ) {
	$data = omc_landing_data( $post_id );
	return ! empty( $data['_found'] );
}

/**
 * The page's content document: decoded, filled with defaults and escaped-safe.
 *
 * @param int $post_id Page ID (defaults to the current post).
 * @return array
 */
function omc_landing_data( $post_id = 0 ) {
	static $cache = [];

	$post_id = (int) ( $post_id ?: get_the_ID() );
	if ( isset( $cache[ $post_id ] ) ) {
		return $cache[ $post_id ];
	}

	$raw = $post_id ? get_post_meta( $post_id, OMC_LANDING_META, true ) : '';
	if ( is_string( $raw ) && '' !== trim( $raw ) ) {
		$decoded = json_decode( $raw, true );
		$raw     = is_array( $decoded ) ? $decoded : [];
	}
	if ( ! is_array( $raw ) ) {
		$raw = [];
	}
	$found = ! empty( $raw );

	$get = static function ( $arr, $key, $default = '' ) {
		return isset( $arr[ $key ] ) ? $arr[ $key ] : $default;
	};

	$hero_raw = (array) $get( $raw, 'hero', [] );
	$cat_raw  = (array) $get( $raw, 'category', [] );
	$cta_raw  = (array) $get( $raw, 'cta', [] );

	$data = [
		'_found'           => $found,
		'slug'             => sanitize_title( (string) $get( $raw, 'slug', $post_id ? get_post_field( 'post_name', $post_id ) : '' ) ),
		'type'             => sanitize_key( (string) $get( $raw, 'type', 'landing' ) ),
		'title'            => omc_landing_heading( $get( $raw, 'title', '' ) ),
		'seo_title'        => omc_landing_plain( $get( $raw, 'seo_title', '' ) ),
		'meta_description' => omc_landing_plain( $get( $raw, 'meta_description', '' ) ),
		'primary_keyword'  => omc_landing_plain( $get( $raw, 'primary_keyword', '' ) ),
		'updated'          => omc_landing_plain( $get( $raw, 'updated', $get( $raw, 'last_updated', '' ) ) ),
		'hero'             => [
			'eyebrow'  => omc_landing_plain( $get( $hero_raw, 'eyebrow', '' ) ),
			'intro'    => omc_landing_text( $get( $hero_raw, 'intro', '' ) ),
			'cta_text' => omc_landing_plain( $get( $hero_raw, 'cta_text', '' ) ),
			'cta_url'  => (string) $get( $hero_raw, 'cta_url', '' ),
			'image'    => (string) $get( $hero_raw, 'image', '' ),
		],
		'category'         => [
			'slug'        => sanitize_title( (string) $get( $cat_raw, 'slug', '' ) ),
			'name'        => omc_landing_plain( $get( $cat_raw, 'name', '' ) ),
			'description' => omc_landing_text( $get( $cat_raw, 'description', '' ) ),
		],
		'sections'         => omc_landing_sanitize_sections( $get( $raw, 'sections', [] ) ),
		'faqs'             => omc_landing_sanitize_faqs( $get( $raw, 'faqs', [] ) ),
		'cta'              => [
			'heading'       => omc_landing_heading( $get( $cta_raw, 'heading', '' ) ),
			'text'          => omc_landing_text( $get( $cta_raw, 'text', '' ) ),
			'eyebrow'       => omc_landing_plain( $get( $cta_raw, 'eyebrow', '' ) ),
			'button_text'   => omc_landing_plain( $get( $cta_raw, 'button_text', '' ) ),
			'button_url'    => (string) $get( $cta_raw, 'button_url', '' ),
			'capture_email' => ! isset( $cta_raw['capture_email'] ) || ! empty( $cta_raw['capture_email'] ),
			'enabled'       => ! isset( $cta_raw['enabled'] ) || ! empty( $cta_raw['enabled'] ),
		],
		'related_slugs'    => array_values( array_filter( array_map( 'sanitize_title', (array) $get( $raw, 'related_slugs', [] ) ) ) ),
	];

	/**
	 * Filter a page's content document before it is rendered.
	 *
	 * @param array $data    Sanitised document.
	 * @param int   $post_id Page ID.
	 */
	$data = apply_filters( 'omc_landing_data', $data, $post_id );

	$cache[ $post_id ] = $data;

	return $data;
}

/** Sanitise the sections array (heading, paragraphs, bullets, table, cta, nested faqs). */
function omc_landing_sanitize_sections( $sections ) {
	$out  = [];
	$seen = [];

	foreach ( (array) $sections as $index => $section ) {
		if ( ! is_array( $section ) ) {
			continue;
		}
		$heading = omc_landing_heading( $section['heading'] ?? '' );
		$id      = sanitize_title( $section['id'] ?? omc_landing_plain( $heading ) );
		if ( '' === $id ) {
			$id = 'section-' . ( (int) $index + 1 );
		}
		if ( isset( $seen[ $id ] ) ) {
			$seen[ $id ]++;
			$id .= '-' . $seen[ $id ];
		} else {
			$seen[ $id ] = 1;
		}

		$cta = (array) ( $section['cta'] ?? [] );

		$out[] = [
			'id'         => $id,
			'heading'    => $heading,
			'paragraphs' => omc_landing_lines( $section['paragraphs'] ?? [] ),
			'bullets'    => omc_landing_lines( $section['bullets'] ?? [] ),
			'table'      => omc_landing_sanitize_table( $section['table'] ?? [] ),
			'faqs'       => omc_landing_sanitize_faqs( $section['faqs'] ?? [] ),
			'cta'        => [
				'text' => omc_landing_plain( $cta['text'] ?? '' ),
				'url'  => (string) ( $cta['url'] ?? '' ),
			],
		];
	}

	return $out;
}

/** Sanitise a section table: { head: [], rows: [[]], caption: "" }. */
function omc_landing_sanitize_table( $table ) {
	if ( ! is_array( $table ) || ( empty( $table['rows'] ) && empty( $table['head'] ) ) ) {
		return [];
	}
	$rows = [];
	foreach ( (array) ( $table['rows'] ?? [] ) as $row ) {
		$cells = [];
		foreach ( (array) $row as $cell ) {
			$cells[] = omc_landing_text( $cell );
		}
		if ( $cells ) {
			$rows[] = $cells;
		}
	}
	$head = [];
	foreach ( (array) ( $table['head'] ?? [] ) as $cell ) {
		$head[] = omc_landing_text( $cell );
	}
	if ( ! $rows && ! $head ) {
		return [];
	}
	return [
		'head'    => $head,
		'rows'    => $rows,
		'caption' => omc_landing_plain( $table['caption'] ?? '' ),
	];
}

/** Sanitise FAQs: [ { q, a, group } ]; `a` may be a string or a list of paragraphs. */
function omc_landing_sanitize_faqs( $faqs ) {
	$out = [];
	foreach ( (array) $faqs as $faq ) {
		if ( ! is_array( $faq ) ) {
			continue;
		}
		$question = omc_landing_plain( $faq['q'] ?? ( $faq['question'] ?? '' ) );
		$answer   = omc_landing_lines( $faq['a'] ?? ( $faq['answer'] ?? [] ) );
		if ( '' === $question || ! $answer ) {
			continue;
		}
		$out[] = [
			'q'     => $question,
			'a'     => $answer,
			'group' => omc_landing_plain( $faq['group'] ?? '' ),
			'id'    => 'q-' . substr( sanitize_title( $question ), 0, 48 ),
		];
	}
	return $out;
}

/** Every FAQ on the page: the top-level list plus any attached to a section. */
function omc_landing_all_faqs( array $data ) {
	$faqs = $data['faqs'] ?? [];
	foreach ( $data['sections'] ?? [] as $section ) {
		if ( ! empty( $section['faqs'] ) ) {
			$faqs = array_merge( $faqs, $section['faqs'] );
		}
	}
	return $faqs;
}

/**
 * FAQs split into named groups, so a template can print one accordion per group.
 * Works with a flat list (one unnamed group) and with per-FAQ "group" keys.
 *
 * @return array [ [ 'heading' => string, 'id' => string, 'faqs' => [] ], … ]
 */
function omc_landing_faq_groups( $faqs, $default_heading = '' ) {
	$groups = [];
	foreach ( (array) $faqs as $faq ) {
		$key = $faq['group'] ?? '';
		if ( ! isset( $groups[ $key ] ) ) {
			$groups[ $key ] = [
				'heading' => $key ?: $default_heading,
				'id'      => sanitize_title( $key ?: ( $default_heading ?: 'faq' ) ),
				'faqs'    => [],
			];
		}
		$groups[ $key ]['faqs'][] = $faq;
	}
	return array_values( $groups );
}

/**
 * The "Last updated …" line for a policy page.
 * Reads the document's `updated` key, or lifts it out of the sections (so the
 * same line never prints twice). Takes the array by reference on purpose.
 */
function omc_landing_updated( array &$data ) {
	if ( ! empty( $data['updated'] ) ) {
		return $data['updated'];
	}
	foreach ( $data['sections'] as $i => $section ) {
		if ( preg_match( '/^last updated/i', omc_landing_plain( $section['heading'] ) ) ) {
			$value = $section['paragraphs'] ? omc_landing_plain( $section['paragraphs'][0] ) : '';
			unset( $data['sections'][ $i ] );
			$data['sections'] = array_values( $data['sections'] );
			return preg_replace( '/^last updated\s*[:\-–—]*\s*/i', '', $value );
		}
		foreach ( $section['paragraphs'] as $j => $paragraph ) {
			if ( preg_match( '/^last updated\s*[:\-–—]*\s*(.+)$/i', omc_landing_plain( $paragraph ), $m ) ) {
				unset( $data['sections'][ $i ]['paragraphs'][ $j ] );
				$data['sections'][ $i ]['paragraphs'] = array_values( $data['sections'][ $i ]['paragraphs'] );
				return trim( $m[1] );
			}
		}
	}
	return '';
}

/* ──────────────────────────── Rendering ──────────────────────────── */

/** Small line-icons the shared omc_icon() does not carry (phone, clock, pin, chat). */
function omc_landing_icon( $name ) {
	$icons = [
		'phone' => '<path d="M6.8 3.5h2.3l1.3 3.3-1.7 1.2a11 11 0 0 0 5.3 5.3l1.2-1.7 3.3 1.3v2.3a2 2 0 0 1-2.2 2A15.6 15.6 0 0 1 4.8 5.7a2 2 0 0 1 2-2.2z"/>',
		'clock' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.4V12l3 1.8"/>',
		'pin'   => '<path d="M12 21s6.5-6 6.5-10.4A6.5 6.5 0 0 0 5.5 10.6C5.5 15 12 21 12 21z"/><circle cx="12" cy="10.4" r="2.4"/>',
		'chat'  => '<path d="M20 12.5a7 7 0 0 1-7 7H8l-4 2.5.9-3.6A7 7 0 0 1 11 4.5h2a7 7 0 0 1 7 7z"/><path d="M9 11h6"/><path d="M9 14h4"/>',
		'note'  => '<path d="M14 3.5H7a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8.5z"/><path d="M14 3.5v5h5"/><path d="M9 13h6"/><path d="M9 16.5h4"/>',
	];
	if ( isset( $icons[ $name ] ) ) {
		return '<svg class="omc-icon omc-icon--' . esc_attr( $name ) . '" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $icons[ $name ] . '</svg>';
	}
	return function_exists( 'omc_icon' ) ? omc_icon( $name ) : '';
}

/**
 * Render the page's sections.
 *
 * @param array $sections omc_landing_data()['sections'].
 * @param array $args     layout: bands (full-width, alternating tints) | plain (inside a column);
 *                        alternate: tint every other band; level: heading level; container: wrapper class.
 */
function omc_landing_render_sections( $sections, $args = [] ) {
	$a = wp_parse_args( $args, [
		'layout'    => 'bands',
		'alternate' => true,
		'level'     => 2,
		'container' => 'l-section__container',
		'class'     => '',
		'reveal'    => true,
		'anchors'   => true,
		'faqs'      => false, // render FAQs attached to a section right after its copy
	] );

	$sections = array_values( array_filter( (array) $sections, static function ( $section ) {
		return ! empty( $section['heading'] ) || ! empty( $section['paragraphs'] ) || ! empty( $section['bullets'] ) || ! empty( $section['table'] ) || ! empty( $section['faqs'] );
	} ) );
	if ( ! $sections ) {
		return;
	}

	$plain = 'plain' === $a['layout'];
	$tag   = 'h' . max( 2, min( 4, (int) $a['level'] ) );
	$sub   = 'h' . min( 5, max( 3, (int) $a['level'] + 1 ) );

	foreach ( $sections as $i => $section ) {
		$classes = [ $plain ? 'omc-lp-block' : 'omc-lp-section' ];
		if ( ! $plain && $a['alternate'] && $i % 2 ) {
			$classes[] = 'omc-lp-section--alt';
		}
		if ( $a['reveal'] ) {
			$classes[] = 'omc-reveal';
		}
		if ( $a['class'] ) {
			$classes[] = $a['class'];
		}
		?>
		<section class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"<?php echo $a['anchors'] ? ' id="' . esc_attr( $section['id'] ) . '" data-omc-anchor' : ''; ?>>
			<?php if ( ! $plain ) : ?>
				<div class="<?php echo esc_attr( $a['container'] ); ?> omc-lp-section__inner">
			<?php endif; ?>

			<?php if ( $section['heading'] ) : ?>
				<<?php echo $tag; ?> class="omc-lp-section__title"><?php echo wp_kses( $section['heading'], [ 'em' => [], 'i' => [], 'br' => [], 'span' => [ 'class' => [] ], 'strong' => [] ] ); ?></<?php echo $tag; ?>>
			<?php endif; ?>

			<?php if ( $section['paragraphs'] || $section['bullets'] || $section['table'] || $section['cta']['text'] ) : ?>
				<div class="omc-lp-prose">
					<?php foreach ( $section['paragraphs'] as $paragraph ) : ?>
						<p><?php echo wp_kses( $paragraph, omc_landing_allowed_inline() ); ?></p>
					<?php endforeach; ?>

					<?php if ( $section['bullets'] ) : ?>
						<ul class="omc-lp-list">
							<?php foreach ( $section['bullets'] as $bullet ) : ?>
								<li><?php echo wp_kses( $bullet, omc_landing_allowed_inline() ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>

					<?php omc_landing_table( $section['table'] ); ?>

					<?php if ( $section['cta']['text'] && $section['cta']['url'] ) : ?>
						<p class="omc-lp-section__cta"><a class="omc-btn omc-btn--outline" href="<?php echo esc_url( $section['cta']['url'] ); ?>"><?php echo esc_html( $section['cta']['text'] ); ?></a></p>
					<?php elseif ( $section['cta']['text'] ) : ?>
						<p class="omc-lp-section__cta"><?php echo esc_html( $section['cta']['text'] ); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php
			if ( $a['faqs'] && ! empty( $section['faqs'] ) ) {
				omc_landing_faq( $section['faqs'], [ 'heading' => '', 'level' => $sub ] );
			}
			?>

			<?php if ( ! $plain ) : ?>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}
}

/** A section table (size guide): horizontal scroll container, header row, zebra rows. */
function omc_landing_table( $table ) {
	if ( empty( $table ) || ( empty( $table['rows'] ) && empty( $table['head'] ) ) ) {
		return;
	}
	$allowed = omc_landing_allowed_inline();
	?>
	<div class="omc-lp-table" tabindex="0" role="region" aria-label="<?php echo esc_attr( $table['caption'] ?: __( 'Measurements table', 'moderno-child' ) ); ?>">
		<table>
			<?php if ( ! empty( $table['caption'] ) ) : ?>
				<caption><?php echo esc_html( $table['caption'] ); ?></caption>
			<?php endif; ?>
			<?php if ( ! empty( $table['head'] ) ) : ?>
				<thead>
					<tr><?php foreach ( $table['head'] as $cell ) : ?><th scope="col"><?php echo wp_kses( $cell, $allowed ); ?></th><?php endforeach; ?></tr>
				</thead>
			<?php endif; ?>
			<tbody>
				<?php foreach ( $table['rows'] as $row ) : ?>
					<tr>
						<?php foreach ( $row as $index => $cell ) : ?>
							<?php if ( 0 === $index && ! empty( $table['head'] ) ) : ?>
								<th scope="row"><?php echo wp_kses( $cell, $allowed ); ?></th>
							<?php else : ?>
								<td><?php echo wp_kses( $cell, $allowed ); ?></td>
							<?php endif; ?>
						<?php endforeach; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
}

/**
 * FAQ accordion: native <details>/<summary>, so it works with the keyboard and
 * a screen reader before JavaScript runs. omc-landing.js only adds the polish
 * (close the others, open the one named in the URL hash).
 *
 * @param array $faqs omc_landing_data()['faqs'].
 * @param array $args heading, id, level, exclusive, open_first.
 */
function omc_landing_faq( $faqs, $args = [] ) {
	$faqs = array_values( (array) $faqs );
	if ( ! $faqs ) {
		return;
	}
	$a = wp_parse_args( $args, [
		'heading'    => '',
		'id'         => '',
		'level'      => 'h2',
		'exclusive'  => true,
		'open_first' => false,
		'class'      => '',
	] );
	$tag = preg_match( '/^h[2-5]$/', (string) $a['level'] ) ? $a['level'] : 'h2';
	?>
	<?php if ( $a['heading'] ) : ?>
		<<?php echo $tag; ?> class="omc-faq__group-title"<?php echo $a['id'] ? ' id="' . esc_attr( $a['id'] ) . '"' : ''; ?>><?php echo wp_kses( $a['heading'], [ 'em' => [], 'i' => [], 'br' => [] ] ); ?></<?php echo $tag; ?>>
	<?php endif; ?>
	<div class="omc-faq js-omc-faq <?php echo esc_attr( $a['class'] ); ?>" data-exclusive="<?php echo $a['exclusive'] ? '1' : '0'; ?>">
		<?php foreach ( $faqs as $index => $faq ) : ?>
			<details class="omc-faq__item" id="<?php echo esc_attr( $faq['id'] ); ?>"<?php echo ( $a['open_first'] && 0 === $index ) ? ' open' : ''; ?>>
				<summary class="omc-faq__q">
					<span class="omc-faq__q-text"><?php echo esc_html( $faq['q'] ); ?></span>
					<span class="omc-faq__mark" aria-hidden="true"></span>
				</summary>
				<div class="omc-faq__a">
					<?php foreach ( $faq['a'] as $paragraph ) : ?>
						<p><?php echo wp_kses( $paragraph, omc_landing_allowed_inline() ); ?></p>
					<?php endforeach; ?>
				</div>
			</details>
		<?php endforeach; ?>
	</div>
	<?php
}

/**
 * FAQPage structured data for any content page that carries questions.
 * Stays out of the way when an SEO plugin owns the <head> (same rule as omc_schema()).
 */
function omc_landing_faq_schema() {
	if ( function_exists( 'omc_has_seo_plugin' ) && omc_has_seo_plugin() ) {
		return;
	}
	if ( ! is_page() || ! omc_is_landing_template() ) {
		return;
	}
	$faqs = omc_landing_all_faqs( omc_landing_data( get_queried_object_id() ) );
	if ( ! $faqs ) {
		return;
	}
	$entities = [];
	foreach ( $faqs as $faq ) {
		$answer = omc_landing_plain( implode( ' ', $faq['a'] ) );
		if ( '' === $answer ) {
			continue;
		}
		$entities[] = [
			'@type'          => 'Question',
			'name'           => $faq['q'],
			'acceptedAnswer' => [ '@type' => 'Answer', 'text' => $answer ],
		];
	}
	if ( ! $entities ) {
		return;
	}
	echo '<script type="application/ld+json">' . wp_json_encode( [
		'@context'   => 'https://schema.org',
		'@type'      => 'FAQPage',
		'@id'        => get_permalink() . '#faq',
		'mainEntity' => $entities,
	], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
}
add_action( 'wp_head', 'omc_landing_faq_schema', 6 );

/**
 * The closing call-to-action band: heading, one line of copy, a button and —
 * when the document asks for it — the newsletter form (the site's second goal).
 *
 * @param array  $cta  omc_landing_data()['cta'].
 * @param string $slug Page slug, used as the sign-up source.
 */
function omc_landing_cta( $cta, $slug = '' ) {
	$cta = wp_parse_args( (array) $cta, [
		'heading'       => '',
		'text'          => '',
		'eyebrow'       => '',
		'button_text'   => '',
		'button_url'    => '',
		'capture_email' => true,
		'enabled'       => true,
	] );
	if ( empty( $cta['enabled'] ) ) {
		return;
	}

	$heading = $cta['heading'] ?: __( 'Find something <em>yours</em>', 'moderno-child' );
	$text    = $cta['text'] ?: __( 'New pieces land in small batches and the loved ones go quickly. Leave your email and you will hear it from us first.', 'moderno-child' );
	$eyebrow = $cta['eyebrow'] ?: __( 'Stay close', 'moderno-child' );
	$button  = $cta['button_text'] ?: __( 'Shop new arrivals', 'moderno-child' );
	$url     = $cta['button_url'] ?: ( function_exists( 'omc_new_arrivals_url' ) ? omc_new_arrivals_url() : home_url( '/shop/' ) );
	$slug    = sanitize_title( $slug ?: get_post_field( 'post_name', get_the_ID() ) );
	?>
	<section class="omc-lp-cta omc-reveal" aria-labelledby="omc-lp-cta-title">
		<div class="l-section__container omc-lp-cta__inner">
			<p class="omc-eyebrow"><?php echo esc_html( $eyebrow ); ?></p>
			<h2 class="omc-section__title" id="omc-lp-cta-title"><?php echo wp_kses( $heading, [ 'em' => [], 'i' => [], 'br' => [] ] ); ?></h2>
			<p class="omc-lp-cta__text"><?php echo wp_kses( $text, omc_landing_allowed_inline() ); ?></p>
			<?php if ( ! empty( $cta['capture_email'] ) && function_exists( 'omc_newsletter_form' ) ) : ?>
				<?php
				omc_newsletter_form( [
					'class'  => 'omc-lp-cta__form',
					'button' => __( 'Join the list', 'moderno-child' ),
					'source' => 'landing:' . $slug,
				] );
				?>
			<?php endif; ?>
			<?php if ( $button && $url ) : ?>
				<p class="omc-lp-cta__actions"><a class="omc-btn omc-btn--solid" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $button ); ?></a></p>
			<?php endif; ?>
		</div>
	</section>
	<?php
}

/**
 * "You might also like" — cards for the sibling pages named in related_slugs.
 * Only published pages are linked, so the row never points at a 404.
 */
function omc_landing_related( $slugs, $args = [] ) {
	$a = wp_parse_args( $args, [
		'eyebrow' => __( 'Keep looking', 'moderno-child' ),
		'title'   => __( 'You might also <em>like</em>', 'moderno-child' ),
	] );

	$items = [];
	foreach ( (array) $slugs as $slug ) {
		$slug = sanitize_title( $slug );
		if ( ! $slug || $slug === get_post_field( 'post_name', get_the_ID() ) ) {
			continue;
		}
		$page = get_page_by_path( $slug );
		if ( ! $page || 'publish' !== $page->post_status ) {
			continue;
		}
		$sibling = omc_landing_data( $page->ID );
		$text    = $sibling['meta_description'];
		if ( ! $text ) {
			$text = $sibling['hero']['intro'] ? omc_landing_plain( $sibling['hero']['intro'] ) : omc_landing_plain( get_the_excerpt( $page ) );
		}
		$items[] = [
			'title' => get_the_title( $page ),
			'url'   => get_permalink( $page ),
			'text'  => wp_html_excerpt( $text, 140, '…' ),
		];
	}
	if ( ! $items ) {
		return;
	}
	?>
	<section class="omc-section omc-lp-related omc-reveal" aria-labelledby="omc-lp-related-title">
		<div class="l-section__container">
			<header class="omc-section__head">
				<div>
					<p class="omc-eyebrow"><?php echo esc_html( $a['eyebrow'] ); ?></p>
					<h2 class="omc-section__title" id="omc-lp-related-title"><?php echo wp_kses( $a['title'], [ 'em' => [], 'i' => [] ] ); ?></h2>
				</div>
			</header>
			<div class="omc-lp-related__grid">
				<?php foreach ( $items as $item ) : ?>
					<a class="omc-lp-card" href="<?php echo esc_url( $item['url'] ); ?>">
						<span class="omc-lp-card__title"><?php echo esc_html( $item['title'] ); ?></span>
						<?php if ( $item['text'] ) : ?>
							<span class="omc-lp-card__text"><?php echo esc_html( $item['text'] ); ?></span>
						<?php endif; ?>
						<span class="omc-lp-card__more"><?php esc_html_e( 'Read more', 'moderno-child' ); ?><?php echo function_exists( 'omc_icon' ) ? omc_icon( 'arrow' ) : ''; ?></span>
					</a>
				<?php endforeach; ?>
			</div>
		</div>
	</section>
	<?php
}

/** In-page navigation used by the FAQ and policy templates (sticky on desktop). */
function omc_landing_nav( $items, $args = [] ) {
	$items = array_values( array_filter( (array) $items, static function ( $item ) {
		return ! empty( $item['id'] ) && ! empty( $item['label'] );
	} ) );
	if ( count( $items ) < 2 ) {
		return;
	}
	$a = wp_parse_args( $args, [
		'title' => __( 'On this page', 'moderno-child' ),
		'label' => __( 'Sections of this page', 'moderno-child' ),
	] );
	?>
	<nav class="omc-lp-nav js-omc-spy" aria-label="<?php echo esc_attr( $a['label'] ); ?>">
		<p class="omc-lp-nav__title"><?php echo esc_html( $a['title'] ); ?></p>
		<ul class="omc-lp-nav__list">
			<?php foreach ( $items as $item ) : ?>
				<li><a class="omc-lp-nav__link" href="#<?php echo esc_attr( $item['id'] ); ?>"><?php echo esc_html( $item['label'] ); ?></a></li>
			<?php endforeach; ?>
		</ul>
	</nav>
	<?php
}

/* ───────────────────────── Contact page ───────────────────────── */

/**
 * The boutique's contact details. Facebook comes from the Customizer (the
 * parent's "Social Media Links"), so nothing is invented here.
 * Filter: omc_contact_details.
 */
function omc_contact_details() {
	$facebook = function_exists( 'ideapark_mod' ) ? trim( (string) ideapark_mod( 'facebook' ) ) : '';
	if ( '#' === $facebook ) {
		$facebook = '';
	}

	$details = [
		'email' => [
			'icon'  => 'email',
			'label' => __( 'Email', 'moderno-child' ),
			'value' => 'hello@oopsmineco.com',
			'url'   => 'mailto:hello@oopsmineco.com',
			'note'  => __( 'Orders, sizing, anything at all.', 'moderno-child' ),
		],
		// Phone, hours and the mailing address used to sit here. The boutique answers by email,
		// so the card carries the inbox and Facebook only; the returns address lives on the
		// return policy page, which is the one place a shopper actually needs it.
	];

	if ( $facebook ) {
		$details['facebook'] = [
			'icon'  => 'facebook',
			'label' => __( 'Facebook', 'moderno-child' ),
			'value' => __( 'Catch the next live', 'moderno-child' ),
			'url'   => $facebook,
			'blank' => true,
		];
	}

	return apply_filters( 'omc_contact_details', $details );
}

/** Render the contact details card. */
function omc_contact_card( $args = [] ) {
	$a = wp_parse_args( $args, [
		'title' => __( 'Talk to us', 'moderno-child' ),
		'text'  => __( 'One person reads every note. Tell us what you are after and we will help you find it.', 'moderno-child' ),
		'class' => '',
	] );
	$details = omc_contact_details();
	if ( ! $details ) {
		return;
	}
	?>
	<aside class="omc-contact__card <?php echo esc_attr( $a['class'] ); ?>">
		<?php if ( $a['title'] ) : ?>
			<h2 class="omc-contact__card-title"><?php echo esc_html( $a['title'] ); ?></h2>
		<?php endif; ?>
		<?php if ( $a['text'] ) : ?>
			<p class="omc-contact__card-text"><?php echo esc_html( $a['text'] ); ?></p>
		<?php endif; ?>
		<ul class="omc-contact__list">
			<?php foreach ( $details as $item ) : ?>
				<?php
				$item  = wp_parse_args( (array) $item, [ 'icon' => 'arrow', 'label' => '', 'value' => '', 'lines' => [], 'url' => '', 'note' => '', 'blank' => false ] );
				$lines = $item['lines'] ? (array) $item['lines'] : array_filter( [ $item['value'] ] );
				if ( ! $lines ) {
					continue;
				}
				?>
				<li class="omc-contact__item">
					<span class="omc-contact__icon"><?php echo omc_landing_icon( $item['icon'] ); ?></span>
					<span class="omc-contact__body">
						<span class="omc-contact__label"><?php echo esc_html( $item['label'] ); ?></span>
						<?php foreach ( $lines as $i => $line ) : ?>
							<?php if ( 0 === $i && $item['url'] ) : ?>
								<a class="omc-contact__value" href="<?php echo esc_url( $item['url'], [ 'http', 'https', 'mailto', 'tel' ] ); ?>"<?php echo $item['blank'] ? ' target="_blank" rel="noopener"' : ''; ?>><?php echo esc_html( $line ); ?></a>
							<?php else : ?>
								<span class="omc-contact__value"><?php echo esc_html( $line ); ?></span>
							<?php endif; ?>
						<?php endforeach; ?>
						<?php if ( $item['note'] ) : ?>
							<span class="omc-contact__note"><?php echo esc_html( $item['note'] ); ?></span>
						<?php endif; ?>
					</span>
				</li>
			<?php endforeach; ?>
		</ul>
	</aside>
	<?php
}

/** A compact "need a hand" card for the end of a policy page. */
function omc_contact_help_card( $args = [] ) {
	$a = wp_parse_args( $args, [
		'title' => __( 'Still need a hand?', 'moderno-child' ),
		'text'  => __( 'Write to us with your order number and we will sort it out with you.', 'moderno-child' ),
	] );
	$details = omc_contact_details();
	$email   = $details['email'] ?? [];
	$phone   = $details['phone'] ?? [];
	?>
	<aside class="omc-help-card omc-reveal">
		<h2 class="omc-help-card__title"><?php echo esc_html( $a['title'] ); ?></h2>
		<p class="omc-help-card__text"><?php echo esc_html( $a['text'] ); ?></p>
		<p class="omc-help-card__links">
			<?php if ( ! empty( $email['value'] ) ) : ?>
				<a class="omc-link" href="<?php echo esc_url( $email['url'], [ 'mailto' ] ); ?>"><?php echo esc_html( $email['value'] ); ?></a>
			<?php endif; ?>
			<?php if ( ! empty( $phone['value'] ) ) : ?>
				<a class="omc-link" href="<?php echo esc_url( $phone['url'], [ 'tel' ] ); ?>"><?php echo esc_html( $phone['value'] ); ?></a>
			<?php endif; ?>
		</p>
		<?php if ( function_exists( 'omc_page_url' ) ) : ?>
			<p class="omc-help-card__more"><a class="omc-btn omc-btn--outline" href="<?php echo esc_url( omc_page_url( 'contacts', '/contacts/' ) ); ?>"><?php esc_html_e( 'Get in touch', 'moderno-child' ); ?></a></p>
		<?php endif; ?>
	</aside>
	<?php
}

/** The Contact Form 7 shortcode for the site's contact form, or '' when unavailable. */
function omc_contact_cf7_shortcode() {
	/** Filter: return '' to force the theme's own form, or a shortcode string to use another form. */
	$override = apply_filters( 'omc_contact_form_shortcode', null );
	if ( null !== $override ) {
		return (string) $override;
	}
	if ( ! defined( 'WPCF7_VERSION' ) ) {
		return '';
	}
	$title = apply_filters( 'omc_contact_cf7_title', 'Contact form 1' );
	$form  = null;

	// get_page_by_title() is deprecated; match the title with a small query instead.
	foreach ( get_posts( [ 'post_type' => 'wpcf7_contact_form', 'numberposts' => 50, 'post_status' => 'any', 'orderby' => 'ID', 'order' => 'ASC' ] ) as $candidate ) {
		if ( 0 === strcasecmp( $candidate->post_title, $title ) ) {
			$form = $candidate;
			break;
		}
		$form = $form ?: $candidate; // first form as the fallback
	}
	return $form ? '[contact-form-7 id="' . (int) $form->ID . '" title="' . esc_attr( $form->post_title ) . '"]' : '';
}

/**
 * The theme's own contact form (used when Contact Form 7 is not available).
 * Posts to admin-ajax (action omc_contact); omc-landing.js submits it in place,
 * and without JavaScript the handler redirects back with ?omc_contact=sent.
 */
function omc_contact_form( $args = [] ) {
	$a = wp_parse_args( $args, [
		'button' => __( 'Send message', 'moderno-child' ),
		'class'  => '',
	] );
	$sent  = isset( $_GET['omc_contact'] ) ? sanitize_key( wp_unslash( $_GET['omc_contact'] ) ) : '';
	$id    = 'omc-contact';
	?>
	<?php if ( 'sent' === $sent ) : ?>
		<p class="omc-form__flash" role="status"><?php esc_html_e( 'Thank you — your note is on its way. We read every one and answer as soon as we can.', 'moderno-child' ); ?></p>
	<?php elseif ( 'error' === $sent ) : ?>
		<p class="omc-form__flash omc-form__flash--error" role="alert"><?php esc_html_e( 'That did not go through. Please check your details and try again.', 'moderno-child' ); ?></p>
	<?php endif; ?>
	<form class="omc-form js-omc-contact <?php echo esc_attr( $a['class'] ); ?>" method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">
		<input type="hidden" name="action" value="omc_contact">
		<input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( 'omc_contact' ) ); ?>">
		<input type="hidden" name="omc_js" value="0">
		<input type="hidden" name="page" value="<?php echo esc_url( get_permalink() ); ?>">
		<p class="omc-form__row omc-form__row--hp" aria-hidden="true">
			<label for="<?php echo esc_attr( $id ); ?>-website"><?php esc_html_e( 'Leave this field empty', 'moderno-child' ); ?></label>
			<input id="<?php echo esc_attr( $id ); ?>-website" type="text" name="omc_website" value="" tabindex="-1" autocomplete="off">
		</p>
		<div class="omc-form__grid">
			<p class="omc-form__row">
				<label for="<?php echo esc_attr( $id ); ?>-name"><?php esc_html_e( 'Your name', 'moderno-child' ); ?></label>
				<input id="<?php echo esc_attr( $id ); ?>-name" type="text" name="name" required autocomplete="name">
			</p>
			<p class="omc-form__row">
				<label for="<?php echo esc_attr( $id ); ?>-email"><?php esc_html_e( 'Email address', 'moderno-child' ); ?></label>
				<input id="<?php echo esc_attr( $id ); ?>-email" type="email" name="email" required autocomplete="email">
			</p>
		</div>
		<p class="omc-form__row">
			<label for="<?php echo esc_attr( $id ); ?>-subject"><?php esc_html_e( 'What is it about?', 'moderno-child' ); ?></label>
			<input id="<?php echo esc_attr( $id ); ?>-subject" type="text" name="subject" placeholder="<?php esc_attr_e( 'Sizing, an order, a piece you are after…', 'moderno-child' ); ?>">
		</p>
		<p class="omc-form__row">
			<label for="<?php echo esc_attr( $id ); ?>-message"><?php esc_html_e( 'Your message', 'moderno-child' ); ?></label>
			<textarea id="<?php echo esc_attr( $id ); ?>-message" name="message" rows="6" required></textarea>
		</p>
		<p class="omc-form__actions">
			<button class="omc-btn omc-btn--solid" type="submit"><?php echo esc_html( $a['button'] ); ?></button>
			<span class="omc-form__note" role="status" aria-live="polite"></span>
		</p>
	</form>
	<?php
}

/** Contact form endpoint: nonce + honeypot, sanitised, mailed to the shop address. */
function omc_contact_ajax() {
	$is_js     = ! empty( $_POST['omc_js'] ) && '1' === (string) wp_unslash( $_POST['omc_js'] );
	$back      = isset( $_POST['page'] ) ? esc_url_raw( wp_unslash( $_POST['page'] ) ) : wp_get_referer();
	$fail      = static function ( $message, $code ) use ( $is_js, $back ) {
		if ( $is_js ) {
			wp_send_json_error( [ 'message' => $message ], $code );
		}
		wp_safe_redirect( add_query_arg( 'omc_contact', 'error', $back ?: home_url( '/' ) ) );
		exit;
	};

	if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'omc_contact' ) ) {
		$fail( __( 'Your session expired. Please reload the page and send it again.', 'moderno-child' ), 403 );
	}
	if ( ! empty( $_POST['omc_website'] ) ) {                 // honeypot: only a bot fills this in
		wp_send_json_success( [ 'message' => __( 'Thank you — your note is on its way.', 'moderno-child' ) ] );
	}

	$name    = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
	$email   = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
	$subject = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '';
	$message = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';

	if ( '' === $name || ! is_email( $email ) || '' === $message ) {
		$fail( __( 'Please add your name, a valid email address and a message.', 'moderno-child' ), 400 );
	}
	if ( strlen( $message ) > 5000 ) {
		$message = substr( $message, 0, 5000 );
	}

	$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$key = 'omc_contact_' . md5( $ip . '|' . $email );
	if ( $ip && (int) get_transient( $key ) >= 5 ) {
		$fail( __( 'That is a few messages in a row — give us a moment to answer the first one.', 'moderno-child' ), 429 );
	}

	$to      = apply_filters( 'omc_contact_recipient', get_option( 'admin_email' ) );
	$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	$title   = $subject ? sprintf( '[%s] %s', $site, $subject ) : sprintf( '[%s] %s', $site, __( 'New message from the website', 'moderno-child' ) );
	$body    = implode( "\n", [
		sprintf( __( 'From: %s', 'moderno-child' ), $name ),
		sprintf( __( 'Email: %s', 'moderno-child' ), $email ),
		$subject ? sprintf( __( 'Subject: %s', 'moderno-child' ), $subject ) : '',
		isset( $_POST['page'] ) ? sprintf( __( 'Page: %s', 'moderno-child' ), esc_url_raw( wp_unslash( $_POST['page'] ) ) ) : '',
		'',
		$message,
	] );
	$headers = [ 'Reply-To: ' . $name . ' <' . $email . '>' ];
	$sent    = wp_mail( $to, $title, $body, $headers );

	do_action( 'omc_contact_message', [ 'name' => $name, 'email' => $email, 'subject' => $subject, 'message' => $message, 'sent' => $sent ] );

	if ( $ip ) {
		set_transient( $key, (int) get_transient( $key ) + 1, HOUR_IN_SECONDS );
	}

	if ( ! $sent ) {
		$fail( __( 'The message could not be sent from here. Please email hello@oopsmineco.com instead.', 'moderno-child' ), 500 );
	}
	if ( $is_js ) {
		wp_send_json_success( [ 'message' => __( 'Thank you — your note is on its way. We read every one and answer as soon as we can.', 'moderno-child' ) ] );
	}
	wp_safe_redirect( add_query_arg( 'omc_contact', 'sent', $back ?: home_url( '/' ) ) );
	exit;
}
add_action( 'wp_ajax_omc_contact', 'omc_contact_ajax' );
add_action( 'wp_ajax_nopriv_omc_contact', 'omc_contact_ajax' );

/* ────────────────────────────── SEO ────────────────────────────── */

/**
 * The page's own meta description (written by the content team).
 * functions.php → omc_seo_head() should prefer this over the trimmed content.
 */
function omc_landing_meta_description( $post_id = 0 ) {
	$post_id = (int) ( $post_id ?: ( is_page() ? get_queried_object_id() : get_the_ID() ) );
	if ( ! $post_id || ! omc_is_landing_template( $post_id ) ) {
		return '';
	}
	return omc_landing_data( $post_id )['meta_description'];
}

/**
 * Hand that line to the child theme's SEO output. functions.php → omc_seo_head()
 * already runs every description through the `omc_meta_description` filter (the
 * video pages use it too), so the writer's sentence wins with no edit there; a
 * direct call in omc_seo_head() would do the same job.
 */
add_filter( 'omc_meta_description', function ( $desc ) {
	$own = omc_landing_meta_description();
	return $own ?: $desc;
} );

/** The document <title>: the writer's seo_title wins when the page has one. */
add_filter( 'document_title_parts', function ( $parts ) {
	if ( ! is_page() || ! omc_is_landing_template() ) {
		return $parts;
	}
	$title = omc_landing_data( get_queried_object_id() )['seo_title'];
	if ( ! $title ) {
		return $parts;
	}
	$parts['title'] = $title;

	// The writer's title often carries the brand already — then drop the site suffix.
	$flat = static function ( $value ) {
		return preg_replace( '/[^a-z0-9]+/', '', strtolower( (string) $value ) );
	};
	$site = $flat( get_bloginfo( 'name' ) );
	if ( $site && false !== strpos( $flat( $title ), $site ) ) {
		unset( $parts['site'], $parts['tagline'] );
	}
	return $parts;
} );
