<?php
/**
 * Admin: the "Variation tiers" product-data tab (tier builder + combination grid), its save handler,
 * the after-save notice, and the settings page.
 */

defined( 'ABSPATH' ) || exit;

final class WHDV_Admin {

	public static function init() {
		add_filter( 'woocommerce_product_data_tabs', [ __CLASS__, 'tab' ] );
		add_action( 'woocommerce_product_data_panels', [ __CLASS__, 'panel' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ] );
		add_action( 'woocommerce_process_product_meta', [ __CLASS__, 'save' ], 20, 2 );
		add_action( 'admin_notices', [ __CLASS__, 'notices' ] );
		add_action( 'admin_menu', [ __CLASS__, 'menu' ], 60 );
		add_filter( 'plugin_action_links_' . plugin_basename( WHDV_FILE ), [ __CLASS__, 'action_links' ] );
	}

	/* ───────────── product screen ───────────── */

	/** The product being edited, from wherever the screen has put it. */
	public static function current_product_id() {
		if ( ! empty( $GLOBALS['post'] ) && $GLOBALS['post'] instanceof WP_Post ) {
			return (int) $GLOBALS['post']->ID;
		}

		return isset( $_GET['post'] ) ? (int) $_GET['post'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading which product is open, not acting on it
	}

	/**
	 * Can the grid show this product whole?
	 *
	 * Only global attributes become tiers, and only WHDV_MAX_LEVELS of them. A product that
	 * breaks either rule has variations the grid will not draw, and WooCommerce's tab is then
	 * the only place to reach them — so it stays.
	 *
	 * Scoping this to products the grid had already built was the obvious guess and the useless
	 * one: every product carrying that meta is a draft, so on the live shop the rule never fired
	 * once and both tabs kept showing on all 105 published products.
	 */
	public static function grid_can_show( $post_id ) {
		$product = $post_id ? wc_get_product( $post_id ) : null;
		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			return false; // nothing else shows WooCommerce's Variations tab anyway
		}

		$levels = 0;
		foreach ( $product->get_attributes() as $attr ) {
			if ( ! $attr->get_variation() ) {
				continue;
			}
			if ( ! $attr->is_taxonomy() ) {
				return false; // a custom, per-product attribute: the grid ignores it
			}
			$levels++;
		}

		return $levels <= WHDV_MAX_LEVELS;
	}

	/** Is WooCommerce's own Variations tab being hidden on this product? */
	public static function replaces_wc_tab( $post_id ) {
		return ! empty( WHDV_Plugin::settings()['hide_wc_variations'] ) && self::grid_can_show( $post_id );
	}

	public static function tab( $tabs ) {
		$tabs['whdv'] = [
			'label'    => __( 'Variation tiers', 'whd-variations' ),
			'target'   => 'whdv_product_data',
			'class'    => [ 'show_if_simple', 'show_if_variable' ],
			'priority' => 65,
		];

		/* Two tabs called almost the same thing, side by side, both editing the same variations:
		   "Variations" at priority 60 and this one at 65. They are two views of one set of rows,
		   not two systems, but nothing on the screen said so. Where the grid can show the product
		   whole, it is the only one shown. */
		if ( self::replaces_wc_tab( self::current_product_id() ) ) {
			unset( $tabs['variations'] );
		}

		return $tabs;
	}

	public static function panel() {
		?>
		<div id="whdv_product_data" class="panel woocommerce_options_panel hidden">
			<?php if ( self::replaces_wc_tab( self::current_product_id() ) ) : ?>
				<p class="whdv-owns">
					<?php
					printf(
						/* translators: %s: link to the settings screen */
						esc_html__( 'Variations are edited here, so WooCommerce\'s own Variations tab is hidden. It is still the only place to set a per-variation weight, shipping class, description or downloadable file — %s to show it again.', 'whd-variations' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=whd-variations' ) ) . '">' . esc_html__( 'turn this off in the settings', 'whd-variations' ) . '</a>'
					);
					?>
				</p>
			<?php endif; ?>
			<div id="whdv-app" class="whdv-app"><p class="whdv-loading"><?php esc_html_e( 'Loading tiers…', 'whd-variations' ); ?></p></div>
			<?php wp_nonce_field( 'whdv_save', 'whdv_nonce' ); ?>
			<input type="hidden" name="whdv_state" id="whdv-state" value="">
		</div>
		<?php
	}

	public static function assets( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen || 'product' !== $screen->post_type || ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}
		global $post;
		$product = $post ? wc_get_product( $post->ID ) : null;
		$state   = $product ? WHDV_Model::state_for_product( $product ) : [ 'levels' => [], 'combos' => [], 'groups' => [], 'notices' => [] ];

		wp_enqueue_media();
		wp_enqueue_style( 'whdv-admin', WHDV_URL . 'admin/tiers.css', [], WHDV_VERSION );
		wp_enqueue_script( 'whdv-admin', WHDV_URL . 'admin/tiers.js', [ 'jquery', 'media-editor' ], WHDV_VERSION, true );
		wp_localize_script( 'whdv-admin', 'WHDV', [
			'state'     => $state,
			'catalog'   => WHDV_Model::catalog(),
			'types'     => wc_get_attribute_types(),
			'maxLevels' => WHDV_MAX_LEVELS,
			// Suggested levels for this product's category, so a new product opens with the right
			// tiers already chosen. Only offered when the grid is still empty — never overwrites work.
			'preset'    => ( $post && empty( $state['levels'] ) ) ? WHDV_Model::preset_for_product( $post->ID ) : null,
			'currency'  => get_woocommerce_currency_symbol(),
			'i18n'      => [
				'level'         => __( 'Level %d', 'whd-variations' ),
				'chooseAttr'    => __( '— choose an attribute —', 'whd-variations' ),
				'newAttr'       => __( '+ New attribute…', 'whd-variations' ),
				'newAttrLabel'  => __( 'Attribute name (e.g. Colour)', 'whd-variations' ),
				'addOption'     => __( 'Add option', 'whd-variations' ),
				'optionPlace'   => __( 'Type an option and press Enter (existing ones autocomplete)', 'whd-variations' ),
				'addAll'        => __( 'Add all %d', 'whd-variations' ),
				'remove'        => __( 'Remove', 'whd-variations' ),
				'up'            => __( 'Move up', 'whd-variations' ),
				'down'          => __( 'Move down', 'whd-variations' ),
				'addLevel'      => __( 'Add a level', 'whd-variations' ),
				'intro'         => __( 'Level 1 is what the shopper picks first (usually Colour), level 2 comes next (usually Size). Every combination below becomes a real WooCommerce variation when you click Update.', 'whd-variations' ),
				'noLevels'      => __( 'No tiers yet. Add a level to start.', 'whd-variations' ),
				'combos'        => __( 'Combinations', 'whd-variations' ),
				'enabled'       => __( 'On', 'whd-variations' ),
				'sku'           => __( 'SKU', 'whd-variations' ),
				'regular'       => __( 'Regular price', 'whd-variations' ),
				'sale'          => __( 'Sale price', 'whd-variations' ),
				'stock'         => __( 'Stock', 'whd-variations' ),
				'stockHint'     => __( 'Leave stock empty to not track it.', 'whd-variations' ),
				'image'         => __( 'Image', 'whd-variations' ),
				'groupImage'    => __( 'Image for all %s', 'whd-variations' ),
				'pickImage'     => __( 'Choose an image', 'whd-variations' ),
				'clear'         => __( 'Clear', 'whd-variations' ),
				'allOn'         => __( 'All on', 'whd-variations' ),
				'allOff'        => __( 'All off', 'whd-variations' ),
				'applyAll'      => __( 'Apply to all', 'whd-variations' ),
				'apply'         => __( 'Apply', 'whd-variations' ),
				'bulk'          => __( 'Fill every row', 'whd-variations' ),
				'summary'       => __( '%1$d combinations · %2$d enabled', 'whd-variations' ),
				'summaryOne'    => __( '%1$d combination · %2$d enabled', 'whd-variations' ),
				'loaded'        => __( '%d existing variations loaded from this product.', 'whd-variations' ),
				'colour'        => __( 'Swatch colour', 'whd-variations' ),
				'swatchImage'   => __( 'Swatch image', 'whd-variations' ),
				'exists'        => __( 'That option is already in this level.', 'whd-variations' ),
				'levelsFull'    => __( 'Maximum number of levels reached.', 'whd-variations' ),
				'removeLevel'   => __( 'Remove this level? Its options are dropped from the grid (existing variations are deleted when you Update).', 'whd-variations' ),
				'emptyNote'     => __( 'Removing every level leaves the existing variations untouched — delete those in the Variations tab if needed.', 'whd-variations' ),
				'dirty'         => __( 'Tiers changed — click Update to create the variations.', 'whd-variations' ),
				'typeLabel'     => __( 'Shown as', 'whd-variations' ),
			],
		] );
	}

	public static function save( $post_id, $post ) {
		if ( empty( $_POST['whdv_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['whdv_nonce'] ) ), 'whdv_save' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_product', $post_id ) || ! isset( $_POST['whdv_state'] ) ) {
			return;
		}
		$state = json_decode( wp_unslash( $_POST['whdv_state'] ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised field by field in the model
		if ( ! is_array( $state ) || empty( $state['dirty'] ) ) {
			return; // the tiers panel was not touched: leave the native attributes/variations alone
		}
		$result = WHDV_Model::apply( $post_id, $state );
		set_transient( 'whdv_result_' . get_current_user_id(), $result, 120 );
	}

	public static function notices() {
		$screen = get_current_screen();
		if ( ! $screen || 'product' !== $screen->post_type ) {
			return;
		}
		$key    = 'whdv_result_' . get_current_user_id();
		$result = get_transient( $key );
		if ( ! is_array( $result ) ) {
			return;
		}
		delete_transient( $key );
		if ( ! empty( $result['ok'] ) ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p><strong>%s</strong> %s</p></div>',
				esc_html__( 'Variation tiers saved.', 'whd-variations' ),
				esc_html( sprintf(
					/* translators: 1: created, 2: updated, 3: deleted */
					__( '%1$d variations created, %2$d updated, %3$d removed.', 'whd-variations' ),
					(int) $result['created'],
					(int) $result['updated'],
					(int) $result['deleted']
				) )
			);
		}
		foreach ( (array) ( $result['notices'] ?? [] ) as $n ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', empty( $result['ok'] ) ? 'error' : 'warning', esc_html( $n ) );
		}
	}

	/* ───────────── settings ───────────── */

	public static function menu() {
		global $submenu;
		$parent = ( is_array( $submenu ) && isset( $submenu['whd'] ) ) ? 'whd' : 'woocommerce';
		add_submenu_page( $parent, __( 'Variation tiers', 'whd-variations' ), __( 'Variation tiers', 'whd-variations' ), 'manage_woocommerce', 'whd-variations', [ __CLASS__, 'page_settings' ] );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=whd-variations' ) ) . '">' . esc_html__( 'Settings', 'whd-variations' ) . '</a>' );
		return $links;
	}

	public static function page_settings() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$fields = [
			'progressive'      => [ __( 'Reveal one level at a time', 'whd-variations' ), __( 'Level 2 appears after level 1 is chosen, and so on. Off = every level is shown at once.', 'whd-variations' ) ],
			'hide_unavailable' => [ __( 'Hide options that don’t exist for the chosen level', 'whd-variations' ), __( 'Off = they stay visible but greyed out (WooCommerce default).', 'whd-variations' ) ],
			'auto_select'      => [ __( 'Auto-pick a level with a single remaining option', 'whd-variations' ), '' ],
			'step_labels'      => [ __( 'Number the levels and show the chosen value next to the label', 'whd-variations' ), '' ],
			'hide_wc_variations' => [ __( 'Show only this grid, not WooCommerce’s Variations tab', 'whd-variations' ), __( 'One tab for the job instead of two with nearly the same name. WooCommerce’s own Variations tab is hidden on any variable product this grid can show whole; a product using a custom (per-product) attribute, or more levels than the grid has, keeps it. Turn this off if you need a per-variation weight, shipping class, description or downloadable file — only WooCommerce’s tab edits those.', 'whd-variations' ) ],
		];
		if ( isset( $_POST['whdv_settings_nonce'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_POST['whdv_settings_nonce'] ) ), 'whdv_settings' ) ) {
			$new = [];
			foreach ( array_keys( $fields ) as $k ) {
				$new[ $k ] = empty( $_POST['whdv'][ $k ] ) ? 0 : 1;
			}
			update_option( 'whdv_settings', $new );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'whd-variations' ) . '</p></div>';
		}
		$s = WHDV_Plugin::settings();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Variation tiers', 'whd-variations' ); ?></h1>
			<p><?php esc_html_e( 'Tiers are set per product: edit a product → Product data → “Variation tiers”. These options control how the levels behave on the product page.', 'whd-variations' ); ?></p>
			<form method="post">
				<?php wp_nonce_field( 'whdv_settings', 'whdv_settings_nonce' ); ?>
				<table class="form-table" role="presentation">
					<?php foreach ( $fields as $k => $f ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html( $f[0] ); ?></th>
							<td>
								<label><input type="checkbox" name="whdv[<?php echo esc_attr( $k ); ?>]" value="1" <?php checked( ! empty( $s[ $k ] ) ); ?>> <?php esc_html_e( 'Enabled', 'whd-variations' ); ?></label>
								<?php if ( $f[1] ) : ?><p class="description"><?php echo esc_html( $f[1] ); ?></p><?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
