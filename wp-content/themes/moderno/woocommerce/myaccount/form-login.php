<?php
/**
 * Login Form
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/myaccount/form-login.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see       https://docs.woocommerce.com/document/template-structure/
 * @package   WooCommerce\Templates
 * @version   11.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}
$is_custom_register_page = ideapark_mod( 'register_page' ) && ideapark_mod( 'register_page' ) != get_option( 'woocommerce_myaccount_page_id' );
$is_register_form        = isset( $_POST['register'] );
?>

<?php do_action( 'woocommerce_before_customer_login_form' ); ?>

<div class="c-login" id="customer_login">

	<div class="c-login__form js-login-form<?php if ( ! $is_register_form ) { ?> c-login__form--active<?php } ?>">
		<div class="c-login__header"><?php esc_html_e( 'Login', 'woocommerce' ); ?></div>
		<form class="c-form woocommerce-form woocommerce-form-login login" method="post">

			<?php do_action( 'woocommerce_login_form_start' ); ?>

			<div class="c-form__row woocommerce-form-row">
				<label for="username"><?php esc_html_e( 'Username or email address', 'woocommerce' ); ?>&nbsp;<span
						class="required">*</span></label>
				<input type="text"
					   class="c-form__input c-form__input--full c-form__input--fill woocommerce-Input woocommerce-Input--text input-text"
					   name="username" id="username" autocomplete="username"
					   value="<?php echo ( ! empty( $_POST['username'] ) ) ? esc_attr( wp_unslash( $_POST['username'] ) ) : ''; ?>"/>
			</div>
			<div class="c-form__row woocommerce-form-row">
				<label for="password"><?php esc_html_e( 'Password', 'woocommerce' ); ?>&nbsp;<span
						class="required">*</span></label>
				<input
					class="c-form__input c-form__input--full c-form__input--fill woocommerce-Input woocommerce-Input--text input-text"
					type="password" name="password" id="password" autocomplete="current-password"/>
			</div>

			<?php do_action( 'woocommerce_login_form' ); ?>

			<div class="c-form__row c-form__row--inline c-login__remember woocommerce-form-row form-row">
				<?php wp_nonce_field( 'woocommerce-login', 'woocommerce-login-nonce' ); ?>
				<label class="c-login__label woocommerce-form__label woocommerce-form__label-for-checkbox woocommerce-form-login__rememberme"">
				<input class="c-form__checkbox woocommerce-form__input woocommerce-form__input-checkbox" name="rememberme" type="checkbox" id="rememberme" value="forever"/>
				&nbsp;<?php esc_html_e( 'Remember me', 'woocommerce' ); ?>
				</label>
				<div class="c-login__lost-password woocommerce-LostPassword lost_password">
					<a class="c-login__lost-password-link"
					   href="<?php echo esc_url( wp_lostpassword_url() ); ?>"><?php esc_html_e( 'Lost your password?', 'woocommerce' ); ?></a>
				</div>
			</div>

			<div class="c-form__row c-login__login-row woocommerce-form-row">
				<button type="submit" class="c-button c-button--accent c-button--large c-button--full woocommerce-button woocommerce-form-login__submit" name="login"
						value="<?php esc_attr_e( 'Log in', 'woocommerce' ); ?>"><?php esc_html_e( 'Log in', 'woocommerce' ); ?></button>
			</div>

			<?php if ( get_option( 'woocommerce_enable_myaccount_registration' ) === 'yes' ) { ?>
				<div class="c-login__bottom">
					<?php esc_html_e( 'Not a Member?', 'moderno' ); ?>
					<?php if ( $is_custom_register_page ) { ?>
					<a href="<?php echo esc_url( get_permalink( apply_filters( 'wpml_object_id', ideapark_mod( 'register_page' ), 'any' ) ) ); ?>"
					   class="c-login__register">
						<?php } else { ?>
						<a href="#" onclick="return false;"
						   class="c-login__register js-login-form-toggle">
							<?php } ?>
							<?php esc_html_e( 'Register', 'woocommerce' ); ?></a>
				</div>
			<?php } ?>

			<?php do_action( 'woocommerce_login_form_end' ); ?>

		</form>
	</div>

	<?php if ( get_option( 'woocommerce_enable_myaccount_registration' ) === 'yes' && ! $is_custom_register_page ) { ?>

		<div class="c-login__form js-register-form <?php if ( $is_register_form ) { ?> c-login__form--active<?php } ?>">

			<div class="c-login__header"><?php esc_html_e( 'Register', 'woocommerce' ); ?></div>
			<form method="post" class="c-form woocommerce-form woocommerce-form-register register" <?php do_action( 'woocommerce_register_form_tag' ); ?>>

				<?php do_action( 'woocommerce_register_form_start' ); ?>

				<?php if ( 'no' === get_option( 'woocommerce_registration_generate_username' ) ) : ?>

					<div class="c-form__row woocommerce-form-row">
						<label class="c-form__label"
							   for="reg_username"><?php esc_html_e( 'Username', 'woocommerce' ); ?>
							<span class="required">*</span></label>
						<input type="text"
							   class="c-form__input c-form__input--full c-form__input--fill woocommerce-Input woocommerce-Input--text input-text"
							   name="username" id="reg_username" autocomplete="username"
							   value="<?php echo ( ! empty( $_POST['username'] ) ) ? esc_attr( wp_unslash( $_POST['username'] ) ) : ''; ?>"/>
					</div>

				<?php endif; ?>

				<div class="c-form__row woocommerce-form-row">
					<label class="c-form__label"
						   for="reg_email"><?php esc_html_e( 'Email address', 'woocommerce' ); ?>
						<span class="required">*</span></label>
					<input type="email"
						   class="c-form__input c-form__input--full c-form__input--fill woocommerce-Input woocommerce-Input--text input-text"
						   name="email" id="reg_email" autocomplete="email"
						   value="<?php echo ( ! empty( $_POST['email'] ) ) ? esc_attr( wp_unslash( $_POST['email'] ) ) : ''; ?>"/>
				</div>

				<?php if ( 'no' === get_option( 'woocommerce_registration_generate_password' ) ) : ?>

					<div class="c-form__row woocommerce-form-row">
						<label class="c-form__label"
							   for="reg_password"><?php esc_html_e( 'Password', 'woocommerce' ); ?>
							<span class="required">*</span></label>
						<input type="password"
							   class="c-form__input c-form__input--full c-form__input--fill woocommerce-Input woocommerce-Input--text input-text"
							   name="password" id="reg_password" autocomplete="new-password"/>
					</div>

				<?php else : ?>

					<div
						class="c-form__row c-login__sent woocommerce-form-row"><?php esc_html_e( 'A link to set a new password will be sent to your email address.', 'woocommerce' ); ?></div>

				<?php endif; ?>

				<?php do_action( 'woocommerce_register_form' ); ?>

				<div class="c-form__row c-login__register-row woocommerce-form-row">
					<?php wp_nonce_field( 'woocommerce-register', 'woocommerce-register-nonce' ); ?>
					<button type="submit" class="c-button c-button--accent c-button--large c-button--full woocommerce-Button woocommerce-button"
							name="register"
							value="<?php esc_attr_e( 'Register', 'woocommerce' ); ?>"><?php esc_html_e( 'Register', 'woocommerce' ); ?></button>
				</div>

				<div class="c-login__bottom">
					<?php esc_html_e( 'You a Member?', 'moderno' ); ?>
					<a href="#" onclick="return false;"
					   class="c-login__register js-login-form-toggle"><?php esc_html_e( 'Login', 'woocommerce' ); ?></a>
				</div>

				<?php do_action( 'woocommerce_register_form_end' ); ?>

			</form>
		</div>

	<?php } ?>

	<?php do_action( 'woocommerce_after_customer_login_form' ); ?>
</div>
