<?php
/**
 * My Account page
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/myaccount/my-account.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see     https://docs.woocommerce.com/document/template-structure/
 * @package WooCommerce/Templates
 * @version   11.0.0
 */

defined( 'ABSPATH' ) || exit;
global $current_user;
?>

<div class="c-account">
	<div class="c-account__col-menu">
		<div class="c-account__user">
			<div class="c-account__user-icon">
				<?php $current_user = wp_get_current_user(); ?>
				<?php echo get_avatar( $current_user->user_email, 70 ); ?>
			</div>
			<div class="c-account__user-text">
				<?php esc_html_e('Hello', 'moderno'); ?>,
				<div class="c-account__user-name">
					<?php echo esc_html( $current_user->display_name ); ?>
				</div>
			</div>
		</div>
		<?php
		/**
		 * My Account navigation.
		 * @since 2.6.0
		 */
		do_action( 'woocommerce_account_navigation' ); ?>
	</div>

	<div class="c-account__col-content">
		<?php
		/**
		 * My Account content.
		 * @since 2.6.0
		 */
		do_action( 'woocommerce_account_content' );
		?>
	</div>
</div>