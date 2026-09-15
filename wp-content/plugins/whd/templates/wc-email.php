<?php
/**
 * WooCommerce email template stand-in. wc_locate_template() is pointed here for
 * every trigger whose WHD design is enabled; the variables WooCommerce passes
 * to the original template ($order, $email, $email_heading, $user_login, …)
 * are available in this scope and handed to the renderer.
 *
 * @package whd
 */

defined( 'ABSPATH' ) || exit;

if ( isset( $email ) && $email instanceof WC_Email ) {
	echo WHD_Emails::render_wc( $email, get_defined_vars() ); // phpcs:ignore WordPress.Security.EscapeOutput -- fully built, escaped HTML document
}
