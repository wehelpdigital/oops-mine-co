<?php

class Ideapark_Latest_Posts_Widget extends WP_Widget {

	function __construct() {
		$widget_options = [
			'classname'   => 'custom-lps-widget',
			'description' => esc_html__( 'The recent posts with thumbnails', 'ideapark-moderno' )
		];

		parent::__construct(
			'ideapark_latest_posts_widget', esc_html__( 'Moderno Latest Posts', 'ideapark-moderno' ), $widget_options
		);
	}

	function widget( $args, $instance ) {
		/**
		 * @var string $before_widget
		 * @var string $before_title
		 * @var string $after_title
		 * @var string $after_widget
		 */

		global $post;
		extract( $args );
		

		$title = apply_filters( 'widget_title', $instance['title'] );

		$posts = get_posts( [
			'posts_per_page'      => $instance['post_per_page'],
			'category'            => $instance['categories'],
			'ignore_sticky_posts' => 1,
			'suppress_filters' => false
		] );

		if ( $posts ) {

			ob_start();
			echo ideapark_wrap( esc_html( $title ), $before_title, $after_title );
			?>
			<ul class="c-lp-widget">
				<?php foreach ( $posts AS $post ) : ?>
				<?php $has_thumbnail = ( function_exists( 'has_post_thumbnail' ) ) && ( has_post_thumbnail() ); ?>
					<li class="c-lp-widget__item <?php if ($has_thumbnail) { ?>c-lp-widget__item--thumb<?php } else { ?>c-lp-widget__item--no-thumb<?php } ?>">
						<?php if ( $has_thumbnail ) : ?>
							<div class="c-lp-widget__thumb">
								<a href="<?php echo get_permalink() ?>" role="presentation">
									<?php the_post_thumbnail( 'thumbnail', [ 'class' => 'c-lp-widget__thumb-img', 'alt'=> $title ] ); ?>
								</a>
							</div>
						<?php endif; ?>
						<div class="c-lp-widget__content">
							<a href="<?php echo get_permalink() ?>" rel="bookmark">
								<div class="c-lp-widget__title"><?php the_title(); ?></div>
							</a>
							<div class="c-lp-widget__date"><?php the_time( get_option( 'date_format' ) ); ?></div>
						</div>
					</li>
				<?php endforeach; ?>
				<?php wp_reset_postdata(); ?>
			</ul>
			<?php
			$inner_widget = ob_get_contents();
			ob_end_clean();

			echo ideapark_wrap( $inner_widget, $before_widget,  $after_widget);
		}
	}

	function update( $new_instance, $old_instance ) {
		$instance                  = $old_instance;
		$instance['title']         = strip_tags( $new_instance['title'] );
		$instance['categories']    = (int) $new_instance['categories'];
		$instance['post_per_page'] = strip_tags( $new_instance['post_per_page'] );

		return $instance;
	}


	function form( $instance ) {
		$defaults = [
			'title'         => esc_html__( 'Latest Posts', 'ideapark-moderno' ),
			'post_per_page' => 5,
			'categories'    => ''
		];

		$instance = wp_parse_args( (array) $instance, $defaults ); ?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title:', 'ideapark-moderno' ); ?></label>
			<input type="text" class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" value="<?php echo esc_attr($instance['title']); ?>" />
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'categories' ) ); ?>"><?php esc_html_e( 'Filter by Category:', 'ideapark-moderno' ); ?></label>
			<select id="<?php echo esc_attr( $this->get_field_id( 'categories' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'categories' ) ); ?>" class="widefat categories" style="width:100%;">
				<option value="all" <?php if ( $instance['categories'] == 'all' ) { ?> selected<?php } ?>><?php esc_html_e( 'All categories', 'ideapark-moderno' ); ?>
				</option>
				<?php $categories = get_categories( 'hide_empty=0&depth=1&type=post' ); ?>
				<?php foreach ( $categories as $category ) { ?>
					<option value="<?php echo esc_attr( $category->term_id ); ?>" <?php if ( $category->term_id == $instance['categories'] ) { ?> selected<?php } ?>><?php echo esc_html( $category->cat_name ); ?></option>
				<?php } ?>
			</select>
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'post_per_page' ) ); ?>"><?php esc_html_e( 'Number of posts to show:', 'ideapark-moderno' ); ?></label>
			<input type="text" class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'post_per_page' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'post_per_page' ) ); ?>" value="<?php echo esc_attr( $instance['post_per_page'] ); ?>" size="3" />
		</p>
	<?php
	}
}

register_widget( 'Ideapark_Latest_Posts_Widget' );