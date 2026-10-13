<?php
/**
 * Size charts.
 *
 * A size chart is a small post type: a title, a description and one image. The owner builds each
 * chart once under its own "Size Chart" menu, then picks one from a dropdown on any product. The
 * product page shows a "Size chart" link that opens the image full size.
 *
 * It lives in the WHD plugin but deliberately keeps its own top-level menu rather than sitting
 * under WHD — it is a catalogue tool the owner reaches for while adding products, not a marketing
 * setting she visits once a month.
 *
 * @package WHD
 */

defined( 'ABSPATH' ) || exit;

class WHD_Size_Charts {

	const CPT  = 'whd_size_chart';
	const META = '_whd_size_chart';          // on a product: the chart chosen for it
	const CATS = '_whd_chart_categories';    // on a chart: the categories it covers
	const DEF  = '_whd_chart_is_default';    // on a chart: use it when nothing else matches
	const GRID = '_whd_chart_grid';          // on a chart: { columns[], rows[][] }
	const INTRO = '_whd_chart_intro';        // on a chart: the line above the table
	const NOTE  = '_whd_chart_note';         // on a chart: the line below it
	const UNIT  = '_whd_chart_unit';         // on a chart: in|cm|'' — written after every number

	public static function init() {
		add_action( 'init', [ __CLASS__, 'register' ] );

		// Admin: the chart screen and the picker on the product screen.
		add_action( 'add_meta_boxes', [ __CLASS__, 'meta_boxes' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'admin_assets' ] );
		add_action( 'save_post_product', [ __CLASS__, 'save_product' ], 10, 2 );
		add_action( 'save_post_' . self::CPT, [ __CLASS__, 'save_chart' ], 10, 2 );
		add_filter( 'whd_size_chart_id', [ __CLASS__, 'resolve' ], 10, 2 );
		add_filter( 'manage_' . self::CPT . '_posts_columns', [ __CLASS__, 'columns' ] );
		add_action( 'manage_' . self::CPT . '_posts_custom_column', [ __CLASS__, 'column' ], 10, 2 );
		add_filter( 'enter_title_here', [ __CLASS__, 'title_placeholder' ], 10, 2 );

		// Front end.
		add_action( 'woocommerce_single_product_summary', [ __CLASS__, 'render_link' ], 25 );
		add_action( 'wp_footer', [ __CLASS__, 'render_dialog' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'assets' ] );
	}

	/* ─────────────────────────── The post type ─────────────────────────── */

	/**
	 * Write a first chart, once, so the screen is not empty next to a product page that already
	 * shows a size guide.
	 *
	 * Uses the client's own US sizing: XS (0–2) to XL (16–18). Marked as the default so it reaches
	 * every product without anyone assigning it. Guarded by an option rather than by "are there any
	 * charts", so deleting it is a decision that sticks.
	 */
	public static function maybe_seed() {
		if ( get_option( 'whd_size_chart_seeded' ) ) {
			return;
		}
		update_option( 'whd_size_chart_seeded', 1, false );

		$existing = self::all();
		$table    = self::default_table();

		/*
		 * A chart that is already here gets finished rather than replaced: its title and any
		 * preamble were somebody's work. It needs a table if it has none, and it needs to reach a
		 * product, which nothing was doing.
		 */
		if ( $existing ) {
			$chart = $existing[0];
			if ( false === stripos( (string) $chart->post_content, '<table' ) ) {
				wp_update_post( [
					'ID'           => $chart->ID,
					'post_content' => trim( (string) $chart->post_content ) . "\n\n" . $table,
				] );
			}
			/*
			 * A featured image here is meant to be a picture of the chart. One from the theme demo's
			 * photo library is a model in a dress, which would be a strange thing to open a size
			 * guide onto. Detached, not deleted.
			 */
			$thumb = (int) get_post_thumbnail_id( $chart->ID );
			if ( $thumb ) {
				$file = (string) get_post_meta( $thumb, '_wp_attached_file', true );
				if ( preg_match( '#^20\d\d/\d\d/#', $file ) && false === stripos( $file, 'size' ) ) {
					delete_post_thumbnail( $chart->ID );
				}
			}
			// Nothing was marked as the default, so no product could ever find it.
			$has_default = false;
			foreach ( $existing as $c ) {
				if ( get_post_meta( $c->ID, self::DEF, true ) ) {
					$has_default = true;
					break;
				}
			}
			if ( ! $has_default ) {
				update_post_meta( $chart->ID, self::DEF, 1 );
			}
			return;
		}

		$html = '<p>Sizes below are <strong>body</strong> measurements, in inches. Pieces sourced from Korea and Thailand often run a size smaller than US sizing, so if you are between two sizes take the larger one.</p>' . $table;

		$id = wp_insert_post( [
			'post_type'    => self::CPT,
			'post_status'  => 'publish',
			'post_title'   => __( 'Womenswear (US sizing)', 'whd' ),
			'post_content' => $html,
		] );

		if ( $id && ! is_wp_error( $id ) ) {
			update_post_meta( $id, self::DEF, 1 );
		}
	}

	/* ─────────────────────────── The measurements ───────────────────────────
	   A chart is a grid: a list of column names and a list of rows. Kept as data rather than as
	   markup so the screen can be a form rather than an HTML lesson, and so the table on the
	   product page is drawn by us and looks the same on every chart anybody makes. */

	/** The columns and rows of a chart, falling back to whatever was typed before this existed. */
	public static function grid( $chart_id ): array {
		$grid = get_post_meta( (int) $chart_id, self::GRID, true );
		if ( is_array( $grid ) && ! empty( $grid['columns'] ) ) {
			return [
				'columns' => array_values( array_map( 'strval', (array) $grid['columns'] ) ),
				'rows'    => array_values( array_map( static fn( $r ) => array_values( array_map( 'strval', (array) $r ) ), (array) ( $grid['rows'] ?? [] ) ) ),
			];
		}

		// A chart written as HTML before the form existed still has to open in the form.
		return self::grid_from_html( (string) get_post_field( 'post_content', $chart_id ) );
	}

	/**
	 * Read the first table out of a lump of HTML.
	 *
	 * Only ever used to fill the form the first time an old chart is opened — what the form saves
	 * is the grid, and from then on the HTML is ignored.
	 */
	public static function grid_from_html( string $html ): array {
		$empty = [ 'columns' => [], 'rows' => [] ];
		if ( false === stripos( $html, '<table' ) || ! preg_match( '#<table[^>]*>(.*?)</table>#is', $html, $table ) ) {
			return $empty;
		}
		if ( ! preg_match_all( '#<tr[^>]*>(.*?)</tr>#is', $table[1], $trs ) ) {
			return $empty;
		}

		$rows = [];
		foreach ( $trs[1] as $tr ) {
			if ( ! preg_match_all( '#<(th|td)[^>]*>(.*?)</\1>#is', $tr, $cells ) ) {
				continue;
			}
			$rows[] = array_map(
				static fn( $c ) => trim( html_entity_decode( wp_strip_all_tags( $c ), ENT_QUOTES, 'UTF-8' ) ),
				$cells[2]
			);
		}
		if ( ! $rows ) {
			return $empty;
		}

		return [ 'columns' => array_shift( $rows ), 'rows' => array_values( $rows ) ];
	}

	/** The chart as a table, drawn the same way for every chart. */
	public static function table_html( $chart_id ): string {
		$grid = self::grid( $chart_id );
		if ( ! $grid['columns'] || ! $grid['rows'] ) {
			return '';
		}
		$unit = (string) get_post_meta( (int) $chart_id, self::UNIT, true );
		$suf  = 'in' === $unit ? '″' : ( 'cm' === $unit ? ' cm' : '' );

		$html = '<table class="whd-sc__table"><thead><tr>';
		foreach ( $grid['columns'] as $column ) {
			$html .= '<th scope="col">' . esc_html( $column ) . '</th>';
		}
		$html .= '</tr></thead><tbody>';

		foreach ( $grid['rows'] as $row ) {
			$html .= '<tr>';
			foreach ( $grid['columns'] as $i => $column ) {
				$cell = trim( (string) ( $row[ $i ] ?? '' ) );
				// The unit goes on measurements, not on "M (8–10)" — so only where it is numeric.
				if ( '' !== $cell && $suf && $i > 0 && preg_match( '/^[\d.,\s\-–—\/]+$/u', $cell ) ) {
					$cell .= $suf;
				}
				$html .= $i
					? '<td>' . esc_html( $cell ) . '</td>'
					: '<th scope="row">' . esc_html( $cell ) . '</th>';
			}
			$html .= '</tr>';
		}

		return $html . '</tbody></table>';
	}

	/** The client's own US size run, as a grid anyone can edit afterwards. */
	public static function default_grid(): array {
		return [
			'columns' => [ 'Size', 'Bust', 'Waist', 'Hip' ],
			'rows'    => [
				[ 'XS (0–2)', '31–32', '24–25', '34–35' ],
				[ 'S (4–6)', '33–34', '26–27', '36–37' ],
				[ 'M (8–10)', '35–36', '28–29', '38–39' ],
				[ 'L (12–14)', '37–39', '30–32', '40–42' ],
				[ 'XL (16–18)', '40–42', '33–35', '43–45' ],
			],
		];
	}

	/** The same thing as HTML, for the post body an old chart still keeps. */
	public static function default_table() {
		$grid = self::default_grid();
		$html = '<table><thead><tr><th>' . implode( '</th><th>', $grid['columns'] ) . '</th></tr></thead><tbody>';
		foreach ( $grid['rows'] as $r ) {
			$html .= '<tr><th>' . $r[0] . '</th><td>' . $r[1] . '&quot;</td><td>' . $r[2] . '&quot;</td><td>' . $r[3] . '&quot;</td></tr>';
		}

		return $html . '</tbody></table>';
	}

	public static function register() {
		register_post_type( self::CPT, [
			'labels'          => [
				'name'               => __( 'Size Charts', 'whd' ),
				'singular_name'      => __( 'Size Chart', 'whd' ),
				'menu_name'          => __( 'Size Chart', 'whd' ),
				'add_new'            => __( 'Add Size Chart', 'whd' ),
				'add_new_item'       => __( 'Add Size Chart', 'whd' ),
				'edit_item'          => __( 'Edit Size Chart', 'whd' ),
				'new_item'           => __( 'New Size Chart', 'whd' ),
				'view_item'          => __( 'View Size Chart', 'whd' ),
				'search_items'       => __( 'Search size charts', 'whd' ),
				'not_found'          => __( 'No size charts yet. Add one and it becomes pickable on every product.', 'whd' ),
				'not_found_in_trash' => __( 'No size charts in the trash.', 'whd' ),
				// "Featured image" means nothing here: the picture IS the chart.
				'featured_image'        => __( 'Picture of the chart', 'whd' ),
				'set_featured_image'    => __( 'Upload a picture of your chart', 'whd' ),
				'remove_featured_image' => __( 'Remove the picture', 'whd' ),
				'use_featured_image'    => __( 'Use as the chart', 'whd' ),
			],
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => true,   // its own top-level menu, not a WHD submenu
			'menu_position'   => 26,     // just under Products
			'menu_icon'       => 'dashicons-editor-table',
			// No editor: the chart is a form now, and a second place to type one was the thing
			// that made the old screen confusing. Anything already written there still opens in
			// the form, because the grid reads it when no grid has been saved yet.
			'supports'        => [ 'title', 'thumbnail' ],
			'capability_type' => 'page',
			'map_meta_cap'    => true,
			'has_archive'     => false,
			'rewrite'         => false,
			'query_var'       => false,
		] );
	}

	public static function title_placeholder( $text, $post ) {
		return ( $post && self::CPT === $post->post_type )
			? __( 'Chart name — e.g. Tops & Dresses (US)', 'whd' )
			: $text;
	}

	public static function columns( $columns ) {
		$out = [];
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'title' === $key ) {
				$out['whd_image'] = __( 'Chart', 'whd' );
				$out['whd_used']  = __( 'Used on', 'whd' );
			}
		}
		return $out;
	}

	public static function column( $column, $post_id ) {
		if ( 'whd_image' === $column ) {
			$thumb = get_the_post_thumbnail( $post_id, [ 90, 90 ], [ 'style' => 'height:auto;border:1px solid #dcdcde' ] );
			if ( $thumb ) {
				echo wp_kses_post( $thumb );
			} elseif ( false !== stripos( (string) get_post_field( 'post_content', $post_id ), '<table' ) ) {
				echo '<span style="color:#646970">' . esc_html__( 'Typed table', 'whd' ) . '</span>';
			} else {
				echo '<span style="color:#b32d2e">' . esc_html__( 'Nothing in it yet', 'whd' ) . '</span>';
			}
		}
		if ( 'whd_used' === $column ) {
			echo wp_kses_post( self::reach_summary( $post_id ) );
		}
	}

	/**
	 * What this chart reaches, in the words the owner ticked.
	 *
	 * Counting products that store the chart's id misses the default and the categories entirely,
	 * which is how the chart the whole shop uses came to be listed as reaching nothing.
	 */
	public static function reach_summary( $chart_id ) {
		$out = [];
		if ( get_post_meta( $chart_id, self::DEF, true ) ) {
			$out[] = '<strong>' . esc_html__( 'Every product', 'whd' ) . '</strong>';
		}

		$names = [];
		foreach ( (array) get_post_meta( $chart_id, self::CATS, true ) as $term_id ) {
			$term = get_term( (int) $term_id, 'product_cat' );
			if ( $term && ! is_wp_error( $term ) ) {
				$names[] = $term->name;
			}
		}
		if ( $names ) {
			sort( $names );
			$out[] = esc_html( implode( ', ', $names ) );
		}

		$picked = self::product_count( $chart_id );
		if ( $picked ) {
			$out[] = esc_html( sprintf(
				/* translators: %d: number of products that name this chart themselves */
				_n( '%d product picks it', '%d products pick it', $picked, 'whd' ),
				$picked
			) );
		}

		return $out ? implode( '<br>', $out ) : '<span style="color:#646970">' . esc_html__( 'Not used yet', 'whd' ) . '</span>';
	}

	/** How many products point at this chart. */
	public static function product_count( $chart_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'product' AND p.post_status != 'trash'
			 WHERE pm.meta_key = %s AND pm.meta_value = %d",
			self::META,
			(int) $chart_id
		) );
	}

	/* ─────────────────────────── Picking one on a product ─────────────────────────── */

	public static function meta_boxes() {
		add_meta_box(
			'whd-size-chart',
			__( 'Size chart', 'whd' ),
			[ __CLASS__, 'product_box' ],
			'product',
			'side',
			'default'
		);
		add_meta_box(
			'whd-chart-reach',
			__( 'Where this chart is used', 'whd' ),
			[ __CLASS__, 'reach_box' ],
			self::CPT,
			'side',
			'high'
		);
		add_meta_box(
			// Not 'whd-chart-grid': that id belongs to the form inside, and add_meta_box puts its
			// own id on the postbox wrapper.
			'whd-chart-grid-box',
			__( 'The measurements', 'whd' ),
			[ __CLASS__, 'grid_box' ],
			self::CPT,
			'normal',
			'high'
		);
		add_meta_box(
			'whd-chart-help',
			__( 'How to build it', 'whd' ),
			[ __CLASS__, 'help_box' ],
			self::CPT,
			'side',
			'low'
		);
	}

	/** The builder's own script and styles, on the chart screen and nowhere else. */
	public static function admin_assets( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen || self::CPT !== $screen->post_type || ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}
		wp_enqueue_style( 'whd-size-chart-admin', WHD_URL . 'admin/size-chart.css', [], WHD_VERSION );
		wp_enqueue_script( 'whd-size-chart-admin', WHD_URL . 'admin/size-chart.js', [], WHD_VERSION, true );
	}

	/**
	 * The chart as a form: name the columns, fill the rows.
	 *
	 * Everything is a plain input in a real table, so it submits with the page whether or not the
	 * JavaScript loads — the buttons only add and remove rows. Nobody has to know what a <td> is.
	 */
	public static function grid_box( $post ) {
		wp_nonce_field( 'whd_chart_grid', 'whd_chart_grid_nonce' );
		$grid = self::grid( $post->ID );
		if ( ! $grid['columns'] ) {
			$grid = self::default_grid();
		}
		/*
		 * Inches for a new chart, nothing for one carried over: an old chart's cells already have
		 * their own quote marks typed into them, and adding a second one is worse than none.
		 */
		$unit = metadata_exists( 'post', $post->ID, self::UNIT )
			? (string) get_post_meta( $post->ID, self::UNIT, true )
			: ( trim( (string) $post->post_content ) ? '' : 'in' );

		$intro = (string) get_post_meta( $post->ID, self::INTRO, true );
		$note  = (string) get_post_meta( $post->ID, self::NOTE, true );
		$cols  = count( $grid['columns'] );
		?>
		<div class="whd-grid" id="whd-chart-grid">

			<div class="whd-grid__field">
				<label for="whd-chart-intro"><?php esc_html_e( 'A line above the table', 'whd' ); ?></label>
				<textarea id="whd-chart-intro" name="whd_chart_intro" rows="2" class="large-text" placeholder="<?php esc_attr_e( 'e.g. Measure against something that already fits you, not against yourself.', 'whd' ); ?>"><?php echo esc_textarea( $intro ); ?></textarea>
			</div>

			<div class="whd-grid__bar">
				<span class="whd-grid__label"><?php esc_html_e( 'Measurements are in', 'whd' ); ?></span>
				<select name="whd_chart_unit" id="whd-chart-unit">
					<option value="in" <?php selected( $unit, 'in' ); ?>><?php esc_html_e( 'inches', 'whd' ); ?></option>
					<option value="cm" <?php selected( $unit, 'cm' ); ?>><?php esc_html_e( 'centimetres', 'whd' ); ?></option>
					<option value="" <?php selected( $unit, '' ); ?>><?php esc_html_e( 'nothing — I will type the units myself', 'whd' ); ?></option>
				</select>
				<span class="description"><?php esc_html_e( 'Written after every number except the first column.', 'whd' ); ?></span>
			</div>

			<div class="whd-grid__scroll">
				<table class="whd-grid__table">
					<thead>
						<tr>
							<th class="whd-grid__handle" aria-hidden="true"></th>
							<?php foreach ( $grid['columns'] as $i => $column ) : ?>
								<th>
									<input type="text" name="whd_chart_cols[]" value="<?php echo esc_attr( $column ); ?>"
										placeholder="<?php echo esc_attr( $i ? __( 'Bust', 'whd' ) : __( 'Size', 'whd' ) ); ?>">
									<button type="button" class="whd-grid__drop" data-whd-drop-col title="<?php esc_attr_e( 'Remove this column', 'whd' ); ?>">&times;</button>
								</th>
							<?php endforeach; ?>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $grid['rows'] as $row ) : ?>
							<tr>
								<th class="whd-grid__handle">
									<button type="button" class="whd-grid__drop" data-whd-drop-row title="<?php esc_attr_e( 'Remove this row', 'whd' ); ?>">&times;</button>
								</th>
								<?php for ( $i = 0; $i < $cols; $i++ ) : ?>
									<td><input type="text" name="whd_chart_cells[]" value="<?php echo esc_attr( $row[ $i ] ?? '' ); ?>"></td>
								<?php endfor; ?>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<p class="whd-grid__actions">
				<button type="button" class="button" data-whd-add-row><?php esc_html_e( '+ Add a size', 'whd' ); ?></button>
				<button type="button" class="button" data-whd-add-col><?php esc_html_e( '+ Add a measurement', 'whd' ); ?></button>
				<span class="description"><?php esc_html_e( 'The first column is the size name; the rest are measurements.', 'whd' ); ?></span>
			</p>

			<div class="whd-grid__field">
				<label for="whd-chart-note"><?php esc_html_e( 'A line below the table', 'whd' ); ?></label>
				<textarea id="whd-chart-note" name="whd_chart_note" rows="2" class="large-text" placeholder="<?php esc_attr_e( 'e.g. Each piece is also measured flat on its own page. Where the two disagree, trust the garment.', 'whd' ); ?>"><?php echo esc_textarea( $note ); ?></textarea>
			</div>

			<div class="whd-grid__preview">
				<p class="whd-grid__label"><?php esc_html_e( 'This is what a shopper sees', 'whd' ); ?></p>
				<div class="whd-sc__text" data-whd-preview></div>
			</div>
		</div>
		<?php
	}

	/**
	 * Which products this chart covers.
	 *
	 * Most shops want one chart on everything, so "the default" is one tick rather than a visit to
	 * every product. Categories are there for the shop that measures knitwear differently from
	 * denim. Either way the dropdown on an individual product still wins.
	 */
	public static function reach_box( $post ) {
		wp_nonce_field( 'whd_chart_reach', 'whd_chart_reach_nonce' );
		$is_default = (bool) get_post_meta( $post->ID, self::DEF, true );
		$chosen     = (array) get_post_meta( $post->ID, self::CATS, true );

		printf(
			'<p><label><input type="checkbox" name="whd_chart_default" value="1"%s> <strong>%s</strong></label></p><p class="description">%s</p>',
			checked( $is_default, true, false ),
			esc_html__( 'Use this chart on every product', 'whd' ),
			esc_html__( 'Unless a product or one of the categories below says otherwise.', 'whd' )
		);

		$terms = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false ] );
		if ( is_wp_error( $terms ) || ! $terms ) {
			return;
		}
		printf( '<p><strong>%s</strong></p>', esc_html__( 'Or only these categories', 'whd' ) );
		echo '<div style="max-height:220px;overflow:auto;border:1px solid #dcdcde;padding:8px;background:#fff">';
		foreach ( $terms as $t ) {
			if ( 'archive' === $t->slug || 'uncategorized' === $t->slug ) {
				continue;
			}
			printf(
				'<label style="display:block;margin:2px 0"><input type="checkbox" name="whd_chart_cats[]" value="%d"%s> %s</label>',
				(int) $t->term_id,
				checked( in_array( (int) $t->term_id, array_map( 'intval', $chosen ), true ), true, false ),
				esc_html( $t->name )
			);
		}
		echo '</div>';
	}

	/** Said once on the editing screen, because what to fill in first is not obvious. */
	public static function help_box() {
		echo '<p>' . esc_html__( 'Fill in the table under “The measurements”. Name the columns, add a row per size, and type the numbers. Every chart is drawn the same way on the product page, so they all match.', 'whd' ) . '</p>';
		echo '<p>' . wp_kses_post( __( 'You can also <strong>upload a picture</strong> of a chart under <em>Picture of the chart</em> — a photograph or a screenshot. It is shown above the table.', 'whd' ) ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Leave the table empty and only the picture is shown.', 'whd' ) . '</p>';
	}

	/**
	 * The grid, straight off the form.
	 *
	 * The cells arrive as one flat list because that is what a table of inputs posts; they are cut
	 * back into rows by the number of columns. A row with nothing in it anywhere is dropped, so
	 * adding a spare row and not using it costs nothing.
	 */
	public static function save_grid( $post_id ) {
		if ( ! isset( $_POST['whd_chart_grid_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['whd_chart_grid_nonce'] ) ), 'whd_chart_grid' ) ) {
			return;
		}

		$columns = array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['whd_chart_cols'] ?? [] ) );
		$columns = array_values( array_filter( $columns, static fn( $c ) => '' !== trim( $c ) ) );
		$cells   = array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['whd_chart_cells'] ?? [] ) );

		$rows = [];
		if ( $columns ) {
			foreach ( array_chunk( $cells, count( $columns ) ) as $row ) {
				$row = array_pad( array_slice( $row, 0, count( $columns ) ), count( $columns ), '' );
				if ( '' !== trim( implode( '', $row ) ) ) {
					$rows[] = array_map( 'trim', $row );
				}
			}
		}

		update_post_meta( $post_id, self::GRID, [ 'columns' => $columns, 'rows' => $rows ] );

		$unit = sanitize_key( (string) wp_unslash( $_POST['whd_chart_unit'] ?? '' ) );
		update_post_meta( $post_id, self::UNIT, in_array( $unit, [ 'in', 'cm' ], true ) ? $unit : '' );
		update_post_meta( $post_id, self::INTRO, sanitize_textarea_field( (string) wp_unslash( $_POST['whd_chart_intro'] ?? '' ) ) );
		update_post_meta( $post_id, self::NOTE, sanitize_textarea_field( (string) wp_unslash( $_POST['whd_chart_note'] ?? '' ) ) );
	}

	public static function save_chart( $post_id, $post ) {
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		// Its own nonce, because the measurements and the reach are two separate boxes and a screen
		// can post one without the other.
		self::save_grid( $post_id );

		if ( ! isset( $_POST['whd_chart_reach_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['whd_chart_reach_nonce'] ) ), 'whd_chart_reach' ) ) {
			return;
		}
		$cats = isset( $_POST['whd_chart_cats'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['whd_chart_cats'] ) ) : [];
		update_post_meta( $post_id, self::CATS, $cats );

		if ( empty( $_POST['whd_chart_default'] ) ) {
			delete_post_meta( $post_id, self::DEF );
			return;
		}
		// Only one chart can be the default; the newest claim wins.
		foreach ( self::all() as $other ) {
			if ( (int) $other->ID !== (int) $post_id ) {
				delete_post_meta( $other->ID, self::DEF );
			}
		}
		update_post_meta( $post_id, self::DEF, 1 );
	}

	/**
	 * Work out which chart a product should show.
	 *
	 * Product first, because an override on one piece is a deliberate act. Then a chart that names
	 * one of the product's categories, walking up the tree so a chart on Clothing covers Skirts.
	 * Then whichever chart is marked as the default.
	 *
	 * @param int $chart_id   What the product's own meta said, if anything.
	 * @param int $product_id Product being looked at.
	 * @return int
	 */
	public static function resolve( $chart_id, $product_id ) {
		if ( $chart_id ) {
			return (int) $chart_id;
		}
		$charts = self::all();
		if ( ! $charts ) {
			return 0;
		}

		$terms = wp_get_object_terms( (int) $product_id, 'product_cat', [ 'fields' => 'ids' ] );
		$terms = is_wp_error( $terms ) ? [] : array_map( 'intval', $terms );
		// A chart on a parent category covers everything beneath it.
		foreach ( $terms as $tid ) {
			foreach ( (array) get_ancestors( $tid, 'product_cat', 'taxonomy' ) as $ancestor ) {
				$terms[] = (int) $ancestor;
			}
		}
		$terms = array_unique( $terms );

		$fallback = 0;
		foreach ( $charts as $chart ) {
			if ( ! $fallback && get_post_meta( $chart->ID, self::DEF, true ) ) {
				$fallback = (int) $chart->ID;
			}
			$covers = array_map( 'intval', (array) get_post_meta( $chart->ID, self::CATS, true ) );
			if ( $covers && array_intersect( $covers, $terms ) ) {
				return (int) $chart->ID;
			}
		}

		return $fallback;
	}

	public static function product_box( $post ) {
		$charts   = self::all();
		$selected = (int) get_post_meta( $post->ID, self::META, true );
		wp_nonce_field( 'whd_size_chart', 'whd_size_chart_nonce' );

		if ( ! $charts ) {
			printf(
				'<p>%s</p><p><a href="%s" class="button">%s</a></p>',
				esc_html__( 'No size charts yet.', 'whd' ),
				esc_url( admin_url( 'post-new.php?post_type=' . self::CPT ) ),
				esc_html__( 'Add your first one', 'whd' )
			);
			return;
		}
		echo '<select name="whd_size_chart" style="width:100%">';
		echo '<option value="0">' . esc_html__( '— none —', 'whd' ) . '</option>';
		foreach ( $charts as $chart ) {
			printf(
				'<option value="%d"%s>%s</option>',
				(int) $chart->ID,
				selected( $selected, $chart->ID, false ),
				esc_html( $chart->post_title )
			);
		}
		echo '</select>';
		printf(
			'<p class="description">%s</p><p><a href="%s">%s</a></p>',
			esc_html__( 'Shown as a “Size chart” link on the product page.', 'whd' ),
			esc_url( admin_url( 'edit.php?post_type=' . self::CPT ) ),
			esc_html__( 'Manage size charts', 'whd' )
		);
	}

	public static function save_product( $post_id, $post ) {
		if ( ! isset( $_POST['whd_size_chart_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['whd_size_chart_nonce'] ) ), 'whd_size_chart' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$chart = isset( $_POST['whd_size_chart'] ) ? absint( wp_unslash( $_POST['whd_size_chart'] ) ) : 0;
		if ( $chart ) {
			update_post_meta( $post_id, self::META, $chart );
		} else {
			delete_post_meta( $post_id, self::META );
		}
	}

	/** Every published chart, newest first. */
	public static function all() {
		return get_posts( [
			'post_type'   => self::CPT,
			'numberposts' => -1,
			'post_status' => 'publish',
			'orderby'     => 'title',
			'order'       => 'ASC',
		] );
	}

	/**
	 * The chart attached to a product, as [ id, title, description, image, alt ], or null.
	 *
	 * Filterable so a theme can attach a chart by category rather than per product.
	 *
	 * @param int $product_id Product to look up. Defaults to the current post.
	 */
	public static function for_product( $product_id = 0 ) {
		$product_id = $product_id ? (int) $product_id : (int) get_the_ID();
		$chart_id   = (int) apply_filters( 'whd_size_chart_id', (int) get_post_meta( $product_id, self::META, true ), $product_id );
		if ( ! $chart_id || 'publish' !== get_post_status( $chart_id ) ) {
			return null;
		}
		$image_id = (int) get_post_thumbnail_id( $chart_id );
		$table    = self::table_html( $chart_id );
		return [
			'id'          => $chart_id,
			// Decoded: the title filters turn "&" into an entity, and every place this is used
			// escapes it again, so a chart called "Tops & Dresses" arrives as "Tops &#038; Dresses".
			'title'       => html_entity_decode( get_the_title( $chart_id ), ENT_QUOTES, 'UTF-8' ),
			'intro'       => (string) get_post_meta( $chart_id, self::INTRO, true ),
			'table'       => $table,
			'note'        => (string) get_post_meta( $chart_id, self::NOTE, true ),
			// Only where there is no table: an old chart's body is the chart.
			'description' => $table ? '' : apply_filters( 'the_content', get_post_field( 'post_content', $chart_id ) ),
			'image'       => $image_id ? wp_get_attachment_image_url( $image_id, 'full' ) : '',
			'image_tag'   => $image_id ? wp_get_attachment_image( $image_id, 'large', false, [ 'class' => 'whd-sc__img', 'loading' => 'lazy' ] ) : '',
		];
	}

	/* ─────────────────────────── Product page ─────────────────────────── */

	/** True when this product page should print the chart. */
	protected static function active() {
		return function_exists( 'is_product' ) && is_product() && self::for_product();
	}

	/** Only on a product page that actually has a chart attached. */
	public static function assets() {
		if ( ! self::active() || ! apply_filters( 'whd_size_chart_render', true ) ) {
			return;
		}
		wp_enqueue_style( 'whd-size-chart', WHD_URL . 'assets/size-chart.css', [], WHD_VERSION );
		wp_enqueue_script( 'whd-size-chart', WHD_URL . 'assets/size-chart.js', [], WHD_VERSION, true );
	}

	public static function render_link() {
		if ( ! self::active() || ! apply_filters( 'whd_size_chart_render', true ) ) {
			return;
		}
		?>
		<button type="button" class="whd-sc__open" data-whd-size-chart aria-haspopup="dialog" aria-controls="whd-size-chart">
			<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><rect x="3" y="7" width="18" height="10" rx="1.5"/><path d="M7 7v3M11 7v5M15 7v3M19 7v5"/></svg>
			<span><?php esc_html_e( 'Size chart', 'whd' ); ?></span>
		</button>
		<?php
	}

	public static function render_dialog() {
		if ( ! self::active() || ! apply_filters( 'whd_size_chart_render', true ) ) {
			return;
		}
		$chart = self::for_product();
		?>
		<div class="whd-sc" id="whd-size-chart" role="dialog" aria-modal="true" aria-labelledby="whd-sc-title" hidden>
			<div class="whd-sc__backdrop" data-whd-size-chart-close></div>
			<div class="whd-sc__panel" role="document">
				<button type="button" class="whd-sc__close" data-whd-size-chart-close aria-label="<?php esc_attr_e( 'Close', 'whd' ); ?>">&times;</button>
				<h2 class="whd-sc__title" id="whd-sc-title"><?php echo esc_html( $chart['title'] ); ?></h2>
				<?php if ( $chart['intro'] ) : ?>
					<p class="whd-sc__lede"><?php echo esc_html( $chart['intro'] ); ?></p>
				<?php endif; ?>
				<?php if ( $chart['image_tag'] ) : ?>
					<figure class="whd-sc__figure">
						<?php echo wp_kses_post( $chart['image_tag'] ); ?>
						<figcaption><a href="<?php echo esc_url( $chart['image'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open the full-size chart', 'whd' ); ?></a></figcaption>
					</figure>
				<?php endif; ?>
				<?php if ( $chart['table'] ) : ?>
					<div class="whd-sc__tablewrap"><?php echo wp_kses_post( $chart['table'] ); ?></div>
				<?php endif; ?>
				<?php if ( $chart['note'] ) : ?>
					<p class="whd-sc__note"><?php echo esc_html( $chart['note'] ); ?></p>
				<?php endif; ?>
				<?php if ( $chart['description'] ) : ?>
					<div class="whd-sc__text"><?php echo wp_kses_post( $chart['description'] ); ?></div>
				<?php endif; ?>
				<?php if ( ! $chart['table'] && ! $chart['image_tag'] && ! $chart['description'] ) : ?>
					<p class="whd-sc__text"><?php esc_html_e( 'This chart has nothing in it yet.', 'whd' ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
