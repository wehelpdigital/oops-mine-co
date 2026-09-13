<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ideapark_Moderno_Post_Type {

	/**
	 * The name for the custom post type.
	 * @var    string
	 * @access   public
	 * @since    1.0.0
	 */
	public $post_type;

	/**
	 * The plural name for the custom post type posts.
	 * @var    string
	 * @access   public
	 * @since    1.0.0
	 */
	public $plural;

	/**
	 * The menu name for the custom post type posts.
	 * @var    string
	 * @access   public
	 * @since    1.0.0
	 */
	public $menu_name;

	/**
	 * The singular name for the custom post type posts.
	 * @var    string
	 * @access   public
	 * @since    1.0.0
	 */
	public $single;

	/**
	 * The description of the custom post type.
	 * @var    string
	 * @access   public
	 * @since    1.0.0
	 */
	public $description;

	/**
	 * The options of the custom post type.
	 * @var    array
	 * @access   public
	 * @since    1.0.0
	 */
	public $options;

	public function __construct( $post_type = '', $plural = '', $single = '', $description = '', $options = [] ) {

		if ( ! $post_type || ! $plural || ! $single ) {
			return;
		}

		// Post type name and labels
		$this->post_type = $post_type;
		if ( is_array( $plural ) && sizeof( $plural ) == 2 ) {
			$this->plural = $plural[1];
			$this->menu_name = $plural[0];
		} else {
			$this->plural = $plural;
		}
		$this->single      = $single;
		$this->description = $description;
		$this->options     = $options;

		// Regsiter post type
		add_action( 'init', [ $this, 'register_post_type' ] );

		// Display custom update messages for posts edits
		add_filter( 'post_updated_messages', [ $this, 'updated_messages' ] );
		add_filter( 'bulk_post_updated_messages', [ $this, 'bulk_updated_messages' ], 10, 2 );
	}

	/**
	 * Register new post type
	 * @return void
	 */
	public function register_post_type() {

		$labels = [
			'name'               => $this->plural,
			'singular_name'      => $this->single,
			'name_admin_bar'     => $this->single,
			'add_new'            => esc_html__( 'Add New', 'ideapark-moderno' ),
			'add_new_item'       => sprintf( esc_html__( 'Add New %s', 'ideapark-moderno' ), $this->single ),
			'edit_item'          => sprintf( esc_html__( 'Edit %s', 'ideapark-moderno' ), $this->single ),
			'new_item'           => sprintf( esc_html__( 'New %s', 'ideapark-moderno' ), $this->single ),
			'all_items'          => sprintf( esc_html__( 'All %s', 'ideapark-moderno' ), $this->plural ),
			'view_item'          => sprintf( esc_html__( 'View %s', 'ideapark-moderno' ), $this->single ),
			'search_items'       => sprintf( esc_html__( 'Search %s', 'ideapark-moderno' ), $this->plural ),
			'not_found'          => sprintf( esc_html__( 'No %s Found', 'ideapark-moderno' ), $this->plural ),
			'not_found_in_trash' => sprintf( esc_html__( 'No %s Found In Trash', 'ideapark-moderno' ), $this->plural ),
			'parent_item_colon'  => sprintf( esc_html__( 'Parent %s', 'ideapark-moderno' ), $this->single ),
			'menu_name'          => $this->menu_name ? $this->menu_name : $this->plural,
		];

		$args = [
			'labels'                => apply_filters( $this->post_type . '_labels', $labels ),
			'description'           => $this->description,
			'public'                => true,
			'publicly_queryable'    => true,
			'exclude_from_search'   => false,
			'show_ui'               => true,
			'show_in_menu'          => true,
			'show_in_nav_menus'     => true,
			'query_var'             => true,
			'can_export'            => true,
			'rewrite'               => true,
			'capability_type'       => 'post',
			'has_archive'           => true,
			'hierarchical'          => true,
			'show_in_rest'          => true,
			'rest_base'             => $this->post_type,
			'rest_controller_class' => 'WP_REST_Posts_Controller',
			'supports'              => [ 'title', 'editor', 'excerpt', 'comments', 'thumbnail' ],
			'menu_position'         => 5,
			'menu_icon'             => 'dashicons-admin-post',
		];

		$args = array_merge( $args, $this->options );

		register_post_type( $this->post_type, apply_filters( $this->post_type . '_register_args', $args, $this->post_type ) );
	}

	/**
	 * Set up admin messages for post type
	 *
	 * @param array $messages Default message
	 *
	 * @return array           Modified messages
	 */
	public function updated_messages( $messages = [] ) {
		global $post, $post_ID;

		$messages[ $this->post_type ] = [
			0  => '',
			1  => sprintf( __( '%1$s updated. %2$sView %3$s%4$s.', 'ideapark-moderno' ), $this->single, '<a href="' . esc_url( get_permalink( $post_ID ) ) . '">', $this->single, '</a>' ),
			2  => __( 'Custom field updated.', 'ideapark-moderno' ),
			3  => __( 'Custom field deleted.', 'ideapark-moderno' ),
			4  => sprintf( __( '%1$s updated.', 'ideapark-moderno' ), $this->single ),
			5  => isset( $_GET['revision'] ) ? sprintf( __( '%1$s restored to revision from %2$s.', 'ideapark-moderno' ), $this->single, wp_post_revision_title( (int) $_GET['revision'], false ) ) : false,
			6  => sprintf( __( '%1$s published. %2$sView %3$s%4s.', 'ideapark-moderno' ), $this->single, '<a href="' . esc_url( get_permalink( $post_ID ) ) . '">', $this->single, '</a>' ),
			7  => sprintf( __( '%1$s saved.', 'ideapark-moderno' ), $this->single ),
			8  => sprintf( __( '%1$s submitted. %2$sPreview post%3$s%4$s.', 'ideapark-moderno' ), $this->single, '<a target="_blank" href="' . esc_url( add_query_arg( 'preview', 'true', get_permalink( $post_ID ) ) ) . '">', $this->single, '</a>' ),
			9  => sprintf( __( '%1$s scheduled for: %2$s. %3$sPreview %4$s%5$s.', 'ideapark-moderno' ), $this->single, '<strong>' . date_i18n( __( 'M j, Y @ G:i', 'ideapark-moderno' ), strtotime( $post->post_date ) ) . '</strong>', '<a target="_blank" href="' . esc_url( get_permalink( $post_ID ) ) . '">', $this->single, '</a>' ),
			10 => sprintf( __( '%1$s draft updated. %2$sPreview %3$s%4$s.', 'ideapark-moderno' ), $this->single, '<a target="_blank" href="' . esc_url( add_query_arg( 'preview', 'true', get_permalink( $post_ID ) ) ) . '">', $this->single, '</a>' ),
		];

		return $messages;
	}

	/**
	 * Set up bulk admin messages for post type
	 *
	 * @param array $bulk_messages Default bulk messages
	 * @param array $bulk_counts   Counts of selected posts in each status
	 *
	 * @return array                Modified messages
	 */
	public function bulk_updated_messages( $bulk_messages = [], $bulk_counts = [] ) {

		$bulk_messages[ $this->post_type ] = [
			'updated'   => sprintf( _n( '%1$s %2$s updated.', '%1$s %3$s updated.', $bulk_counts['updated'], 'ideapark-moderno' ), $bulk_counts['updated'], $this->single, $this->plural ),
			'locked'    => sprintf( _n( '%1$s %2$s not updated, somebody is editing it.', '%1$s %3$s not updated, somebody is editing them.', $bulk_counts['locked'], 'ideapark-moderno' ), $bulk_counts['locked'], $this->single, $this->plural ),
			'deleted'   => sprintf( _n( '%1$s %2$s permanently deleted.', '%1$s %3$s permanently deleted.', $bulk_counts['deleted'], 'ideapark-moderno' ), $bulk_counts['deleted'], $this->single, $this->plural ),
			'trashed'   => sprintf( _n( '%1$s %2$s moved to the Trash.', '%1$s %3$s moved to the Trash.', $bulk_counts['trashed'], 'ideapark-moderno' ), $bulk_counts['trashed'], $this->single, $this->plural ),
			'untrashed' => sprintf( _n( '%1$s %2$s restored from the Trash.', '%1$s %3$s restored from the Trash.', $bulk_counts['untrashed'], 'ideapark-moderno' ), $bulk_counts['untrashed'], $this->single, $this->plural ),
		];

		return $bulk_messages;
	}

}
