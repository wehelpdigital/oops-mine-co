<?php
/**
 * Back-in-stock alerts.
 *
 * A boutique that buys in small runs sells out, and a sold-out size is the one moment a shopper is
 * most willing to leave an email address. This catches that: a "Notify me when it's back" form on
 * the size that is gone, an email the moment it returns, and a count in the admin of who is waiting
 * for what — which is the only demand signal a shop this size gets before it reorders.
 *
 * Requests live in {prefix}whd_stock_alerts, one row per person per variation. The email is the
 * designable `back_in_stock` trigger, so it goes out in the same builder as everything else.
 *
 * Sending is driven twice over: a one-off event a minute after stock changes, so someone watching
 * hears quickly, and a sweep every fifteen minutes as the backstop for stock that changed without
 * firing a hook — an import, a direct database edit, a refund putting one back.
 *
 * @package whd
 */

defined( 'ABSPATH' ) || exit;

final class WHD_Stock_Alerts {

	const DB_VERSION = '1.0';
	const CRON       = 'whd_stock_cron';
	const CRON_SOON  = 'whd_stock_cron_soon';
	const TRIGGER    = 'back_in_stock';

	/** How many alerts one sweep will send. Keeps a restock of fifty sizes off a single request. */
	const BATCH = 60;

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'whd_stock_alerts';
	}

	/* ─────────────────────────── install ─────────────────────────── */

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = self::table();

		/*
		 * `uniq` is md5( product|variation|email ) rather than a unique index across those three
		 * columns: a utf8mb4 email column at 191 characters plus two bigints is over the index
		 * length some MySQL configurations still enforce, and a hash is the same guarantee in 32
		 * bytes.
		 */
		dbDelta(
			"CREATE TABLE $table (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				product_id BIGINT UNSIGNED NOT NULL,
				variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				label VARCHAR(191) NOT NULL DEFAULT '',
				email VARCHAR(191) NOT NULL,
				name VARCHAR(100) NOT NULL DEFAULT '',
				status VARCHAR(20) NOT NULL DEFAULT 'waiting',
				token CHAR(32) NOT NULL DEFAULT '',
				uniq CHAR(32) NOT NULL DEFAULT '',
				created DATETIME NOT NULL,
				notified DATETIME NULL,
				PRIMARY KEY (id),
				UNIQUE KEY uniq (uniq),
				KEY status (status),
				KEY product_id (product_id)
			) " . $wpdb->get_charset_collate() . ';'
		);
		update_option( 'whd_stock_alerts_db', self::DB_VERSION, false );
	}

	/** The plugin was active before this module existed, so activation cannot be relied on. */
	public static function maybe_install() {
		if ( get_option( 'whd_stock_alerts_db' ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	public static function init() {
		add_action( 'admin_init', [ __CLASS__, 'maybe_install' ] );

		// Front end.
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'assets' ] );
		add_action( 'woocommerce_single_product_summary', [ __CLASS__, 'render' ], 31 );
		add_action( 'wp_ajax_whd_stock_alert', [ __CLASS__, 'ajax' ] );
		add_action( 'wp_ajax_nopriv_whd_stock_alert', [ __CLASS__, 'ajax' ] );
		add_action( 'template_redirect', [ __CLASS__, 'handle_cancel' ] );

		// Stock changes.
		add_action( 'woocommerce_product_set_stock_status', [ __CLASS__, 'stock_changed' ], 10, 3 );
		add_action( 'woocommerce_variation_set_stock_status', [ __CLASS__, 'stock_changed' ], 10, 3 );
		add_action( 'woocommerce_product_set_stock', [ __CLASS__, 'stock_object_changed' ] );
		add_action( 'woocommerce_variation_set_stock', [ __CLASS__, 'stock_object_changed' ] );

		add_action( self::CRON, [ __CLASS__, 'run' ] );
		add_action( self::CRON_SOON, [ __CLASS__, 'run' ] );
	}

	/* ─────────────────────────── capture ─────────────────────────── */

	/**
	 * Record a request. Idempotent: asking twice for the same size updates the row rather than
	 * queueing a second email.
	 *
	 * @return array|WP_Error [ id, already ]
	 */
	public static function add( $product_id, $variation_id, $email, $name = '' ) {
		global $wpdb;
		self::maybe_install();

		$product_id   = (int) $product_id;
		$variation_id = (int) $variation_id;
		$email        = sanitize_email( $email );

		if ( ! is_email( $email ) ) {
			return new WP_Error( 'whd_email', __( 'That does not look like an email address.', 'whd' ) );
		}
		$product = wc_get_product( $variation_id ?: $product_id );
		if ( ! $product ) {
			return new WP_Error( 'whd_product', __( 'That piece is no longer listed.', 'whd' ) );
		}
		if ( $product->is_in_stock() ) {
			return new WP_Error( 'whd_in_stock', __( 'Good news — that one is available right now.', 'whd' ) );
		}

		$uniq     = md5( $product_id . '|' . $variation_id . '|' . strtolower( $email ) );
		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT id, status FROM ' . self::table() . ' WHERE uniq = %s', $uniq ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( $existing && 'waiting' === $existing->status ) {
			return [ 'id' => (int) $existing->id, 'already' => true ];
		}

		$row = [
			'product_id'   => $product_id,
			'variation_id' => $variation_id,
			'label'        => self::label( $product_id, $variation_id ),
			'email'        => $email,
			'name'         => sanitize_text_field( $name ),
			'status'       => 'waiting',
			'token'        => wp_generate_password( 32, false, false ),
			'uniq'         => $uniq,
			'created'      => current_time( 'mysql' ),
			'notified'     => null,
		];

		if ( $existing ) {
			// They were told once and are asking again — reset rather than duplicate.
			$wpdb->update( self::table(), $row, [ 'id' => (int) $existing->id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$id = (int) $existing->id;
		} else {
			$wpdb->insert( self::table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$id = (int) $wpdb->insert_id;
		}

		/**
		 * Fires when someone asks to be told a piece is back.
		 *
		 * @param string $email
		 * @param int    $product_id
		 * @param int    $variation_id
		 */
		do_action( 'whd_stock_alert_requested', $email, $product_id, $variation_id );

		return [ 'id' => $id, 'already' => false ];
	}

	/** "Black / M", or the product name for something with no variations. */
	public static function label( $product_id, $variation_id ) {
		$product = wc_get_product( $variation_id ?: $product_id );
		if ( ! $product ) {
			return '';
		}
		if ( $variation_id && $product instanceof WC_Product_Variation ) {
			$bits = [];
			foreach ( $product->get_variation_attributes() as $name => $value ) {
				if ( '' === $value ) {
					continue;
				}
				$taxonomy = str_replace( 'attribute_', '', $name );
				$term     = taxonomy_exists( $taxonomy ) ? get_term_by( 'slug', $value, $taxonomy ) : null;
				$bits[]   = $term && ! is_wp_error( $term ) ? $term->name : ucwords( str_replace( '-', ' ', $value ) );
			}
			return mb_substr( implode( ' / ', $bits ), 0, 191 );
		}
		return mb_substr( $product->get_name(), 0, 191 );
	}

	public static function ajax() {
		check_ajax_referer( 'whd_stock_alert', 'nonce' );

		$result = self::add(
			isset( $_POST['product_id'] ) ? (int) $_POST['product_id'] : 0,
			isset( $_POST['variation_id'] ) ? (int) $_POST['variation_id'] : 0,
			isset( $_POST['email'] ) ? wp_unslash( $_POST['email'] ) : '',
			isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : ''
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		wp_send_json_success( [
			'message' => $result['already']
				? __( 'You are already on the list for this one — we will write the moment it lands.', 'whd' )
				: __( 'Done. We will email you the moment it is back, and only about this piece.', 'whd' ),
		] );
	}

	/** A one-click way out of a request, from the email. */
	public static function handle_cancel() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the token is the credential
		$token = isset( $_GET['whd_stock_cancel'] ) ? sanitize_text_field( wp_unslash( $_GET['whd_stock_cancel'] ) ) : '';
		if ( ! $token ) {
			return;
		}
		global $wpdb;
		$wpdb->update( self::table(), [ 'status' => 'cancelled' ], [ 'token' => $token ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		wp_safe_redirect( add_query_arg( 'whd_stock', 'cancelled', home_url( '/' ) ) );
		exit;
	}

	/* ─────────────────────────── the product page ─────────────────────────── */

	public static function assets() {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}
		// Nothing to ask about, nothing to load. Most product pages are fully in stock.
		if ( ! self::applies( wc_get_product( get_queried_object_id() ) ) ) {
			return;
		}
		wp_enqueue_style( 'whd-stock-alerts', WHD_URL . 'assets/stock-alerts.css', [], WHD_VERSION );
		wp_enqueue_script( 'whd-stock-alerts', WHD_URL . 'assets/stock-alerts.js', [ 'jquery' ], WHD_VERSION, true );
		wp_localize_script( 'whd-stock-alerts', 'WHD_STOCK', [
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'whd_stock_alert' ),
			'i18n'  => [
				'sending' => __( 'Sending…', 'whd' ),
				'button'  => __( 'Notify me', 'whd' ),
				'error'   => __( 'Something went wrong. Try again in a moment.', 'whd' ),
			],
		] );
	}

	/**
	 * The form, printed once under the add-to-cart area and shown by JavaScript when the piece —
	 * or the size chosen — turns out to be gone.
	 */
	public static function render() {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		if ( ! apply_filters( 'whd_stock_alert_render', true, $product->get_id() ) ) {
			return;
		}

		if ( ! self::applies( $product ) ) {
			return; // everything is available; nothing to ask about
		}
		$variable = $product->is_type( 'variable' );

		$user  = wp_get_current_user();
		$email = $user && $user->ID ? $user->user_email : '';
		?>
		<div class="whd-stock" id="whd-stock"
			data-product="<?php echo esc_attr( $product->get_id() ); ?>"
			data-variable="<?php echo $variable ? '1' : '0'; ?>"
			<?php echo $variable ? '' : 'data-open="1"'; ?>>
			<p class="whd-stock__head">
				<span class="whd-stock__dot" aria-hidden="true"></span>
				<span class="whd-stock__gone"><?php esc_html_e( 'Sold out', 'whd' ); ?></span>
				<span class="whd-stock__which"></span>
			</p>
			<p class="whd-stock__lede">
				<?php esc_html_e( 'These come in small runs, so a restock is never a promise — but if this one comes back, you will be the first to know.', 'whd' ); ?>
			</p>
			<form class="whd-stock__form" method="post">
				<input type="hidden" name="variation_id" value="0">
				<label class="screen-reader-text" for="whd-stock-email"><?php esc_html_e( 'Your email', 'whd' ); ?></label>
				<input type="email" id="whd-stock-email" name="email" required
					value="<?php echo esc_attr( $email ); ?>"
					placeholder="<?php esc_attr_e( 'you@email.com', 'whd' ); ?>">
				<button type="submit" class="whd-stock__submit"><?php esc_html_e( 'Notify me', 'whd' ); ?></button>
			</form>
			<p class="whd-stock__note" role="status"></p>
			<p class="whd-stock__small"><?php esc_html_e( 'One email about this piece only. Nothing else, and no list.', 'whd' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Is there anything on this product a shopper could ask to be told about?
	 *
	 * For a variable product that means at least one size gone. Asked of the lookup table in one
	 * query rather than by instantiating every variation: a product with three colours and five
	 * sizes has fifteen children, and this runs on every product page view.
	 */
	public static function applies( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return false;
		}
		if ( ! $product->is_type( 'variable' ) ) {
			return ! $product->is_in_stock();
		}
		return (bool) self::sold_out_variations( $product );
	}

	/** Variations that exist but have none left. */
	public static function sold_out_variations( WC_Product $product ) {
		global $wpdb;
		if ( ! $product->is_type( 'variable' ) ) {
			return [];
		}
		$children = array_map( 'intval', $product->get_children() );
		if ( ! $children ) {
			return [];
		}
		$in = implode( ',', $children );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $in is a list of integers.
		$ids = $wpdb->get_col(
			"SELECT product_id FROM {$wpdb->prefix}wc_product_meta_lookup
			 WHERE product_id IN ($in) AND stock_status <> 'instock'"
		);

		return array_map( 'intval', (array) $ids );
	}

	/* ─────────────────────────── sending ─────────────────────────── */

	public static function stock_changed( $id, $status, $product = null ) {
		if ( 'instock' === $status ) {
			self::schedule_soon();
		}
	}

	public static function stock_object_changed( $product ) {
		if ( $product instanceof WC_Product && $product->is_in_stock() ) {
			self::schedule_soon();
		}
	}

	/** A minute's grace, so a save that touches twenty variations schedules one run, not twenty. */
	private static function schedule_soon() {
		if ( ! wp_next_scheduled( self::CRON_SOON ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CRON_SOON );
		}
	}

	/**
	 * Send every waiting alert whose piece is available again.
	 *
	 * @return array [ checked, sent, failed ]
	 */
	public static function run() {
		global $wpdb;
		self::maybe_install();

		$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			'SELECT * FROM ' . self::table() . ' WHERE status = %s ORDER BY created ASC LIMIT %d',
			'waiting',
			self::BATCH
		) );

		$log = [ 'checked' => count( $rows ), 'sent' => 0, 'failed' => 0 ];
		foreach ( $rows as $row ) {
			$product = wc_get_product( (int) $row->variation_id ?: (int) $row->product_id );
			if ( ! $product ) {
				// The piece is gone for good; stop holding someone's address for it.
				$wpdb->update( self::table(), [ 'status' => 'cancelled' ], [ 'id' => (int) $row->id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				continue;
			}
			if ( ! $product->is_in_stock() ) {
				continue;
			}
			if ( self::send( $row ) ) {
				$wpdb->update( self::table(), [ 'status' => 'sent', 'notified' => current_time( 'mysql' ) ], [ 'id' => (int) $row->id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$log['sent']++;
			} else {
				$log['failed']++;
			}
		}

		return $log;
	}

	/** One alert email, through the designable trigger. */
	public static function send( $row ) {
		$parent = wc_get_product( (int) $row->product_id );
		$item   = wc_get_product( (int) $row->variation_id ?: (int) $row->product_id );
		if ( ! $parent || ! $item ) {
			return false;
		}

		$url = $parent->get_permalink();
		if ( $row->variation_id && $item instanceof WC_Product_Variation ) {
			// Straight to the size they asked about, already selected.
			$url = add_query_arg( array_filter( $item->get_variation_attributes() ), $url );
		}

		$ctx = WHD_Emails::context( [
			'data' => [
				'product_name'  => $parent->get_name(),
				'product_url'   => $url,
				'product_price' => wp_strip_all_tags( $item->get_price_html() ),
				'variation'     => (string) $row->label,
				'first_name'    => $row->name ?: __( 'there', 'whd' ),
				'email'         => $row->email,
				'cancel_url'    => add_query_arg( 'whd_stock_cancel', $row->token, home_url( '/' ) ),
			],
		] );

		// The size's own photograph where the variation has one, the product's otherwise.
		$image_id = $item->get_image_id() ?: $parent->get_image_id();
		if ( $image_id ) {
			$ctx['data']['product_image'] = (string) wp_get_attachment_image_url( (int) $image_id, 'medium' );
		}

		return (bool) WHD_Emails::send_custom( self::TRIGGER, $ctx, $row->email );
	}

	/* ─────────────────────────── reading ─────────────────────────── */

	/**
	 * Who is waiting for what, most-wanted first. This is the restock signal.
	 *
	 * @return array Rows of product_id, variation_id, label, waiting, in_stock, name, url.
	 */
	public static function demand() {
		global $wpdb;
		self::maybe_install();

		$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			'SELECT product_id, variation_id, label, COUNT(*) AS waiting, MAX(created) AS latest
			 FROM ' . self::table() . '
			 WHERE status = %s
			 GROUP BY product_id, variation_id, label
			 ORDER BY waiting DESC, latest DESC',
			'waiting'
		) );

		$out = [];
		foreach ( $rows as $r ) {
			$parent  = wc_get_product( (int) $r->product_id );
			$item    = wc_get_product( (int) $r->variation_id ?: (int) $r->product_id );
			$out[]   = [
				'product_id'   => (int) $r->product_id,
				'variation_id' => (int) $r->variation_id,
				'label'        => $r->label,
				'waiting'      => (int) $r->waiting,
				'latest'       => $r->latest,
				'name'         => $parent ? $parent->get_name() : __( '(deleted product)', 'whd' ),
				'url'          => $parent ? get_edit_post_link( (int) $r->product_id, 'raw' ) : '',
				'in_stock'     => $item ? $item->is_in_stock() : false,
			];
		}
		return $out;
	}

	public static function counts() {
		global $wpdb;
		self::maybe_install();
		$rows = $wpdb->get_results( 'SELECT status, COUNT(*) n FROM ' . self::table() . ' GROUP BY status' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$out  = [ 'waiting' => 0, 'sent' => 0, 'cancelled' => 0 ];
		foreach ( $rows as $r ) {
			$out[ $r->status ] = (int) $r->n;
		}
		return $out;
	}

	public static function recent( $limit = 50 ) {
		global $wpdb;
		self::maybe_install();
		return $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			'SELECT * FROM ' . self::table() . ' ORDER BY created DESC LIMIT %d',
			(int) $limit
		) );
	}
}
