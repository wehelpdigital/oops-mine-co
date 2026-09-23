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

	public static function tab( $tabs ) {
		$tabs['whdv'] = [
			'label'    => __( 'Variation tiers', 'whd-variations' ),
			'target'   => 'whdv_product_data',
			'class'    => [ 'show_if_simple', 'show_if_variable' ],
			'priority' => 65,
		];
		return $tabs;
	}

	public static function panel() {
		?>
		<div id="whdv_product_data" class="panel woocommerce_options_panel hidden">
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
				'bulk'          => __( 'Fill every row', 'whd-variations' ),
				'summary'       => __( '%1$d combinations · %2$d enabled', 'whd-variations' ),
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
