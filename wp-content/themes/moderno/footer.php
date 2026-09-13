<?php
$ideapark_footer              = ideapark_mod('_footer');
$ideapark_footer_is_elementor = ideapark_mod('_footer_is_elementor');
?>
</main><!-- /.l-inner -->
<footer
	class="l-section c-footer c-footer--mobile-buttons-<?php echo ideapark_mod( 'bottom_buttons_mobile_locations' ); ?> <?php ideapark_class( ! $ideapark_footer && ideapark_mod( 'footer_copyright' ), 'c-footer--simple' ); ?><?php if ( ideapark_mod( 'sticky_add_to_cart' ) ) { ?> c-footer--sticky-add-to-cart<?php } ?>">
	<?php if ( $ideapark_footer ) {
		echo ideapark_wrap( $ideapark_footer, '<div class="l-section">', '</div>' );
	} else { ?>
		<div class="l-section__container">
			<?php if ( ideapark_mod( 'footer_copyright' ) ) { ?>
				<?php get_template_part( 'templates/footer-copyright' ); ?>
			<?php } ?>
		</div>
	<?php } ?>
	<?php if ( ideapark_is_elementor_preview_mode() && ideapark_mod( 'footer_page' ) ) { ?>
		<a onclick="window.open('<?php echo esc_url( esc_url( admin_url( 'post.php?post=' . ideapark_mod( 'footer_page' ) . '&action=' . ( ! empty( $ideapark_footer_is_elementor ) ? 'elementor' : 'edit' ) ) ) ); ?>', '_blank').focus();"
		   href="#"
		   class="h-footer-edit">
			<i>
				<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 862 1000">
					<path
						d="M725 324L529 127l67-67c55-53 109-83 155-38l79 80c53 52 15 101-38 155zM469 187l196 196-459 459H13V646zM35 1000a35 35 0 0 0 0-70h792a35 35 0 0 0 0 70z"
						fill="white"/>
				</svg>
			</i>
		</a>
	<?php } ?>
</footer>
<?php wp_footer(); ?>
</body>
</html>
