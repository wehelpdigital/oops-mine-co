<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ideapark_moderno_sort_notice = [];

class Ideapark_Moderno_Taxonomy {

	/**
	 * The name for the custom taxonomy.
	 * @var    string
	 * @access   public
	 * @since    1.0.0
	 */
	public $taxonomy;

	/**
	 * The object types for the custom taxonomy.
	 * @var    string
	 * @access   public
	 * @since    1.0.0
	 */
	public $object_type;

	/**
	 * The plural name for the custom taxonomy posts.
	 * @var    string
	 * @access   public
	 * @since    1.0.0
	 */
	public $plural;

	/**
	 * The singular name for the custom taxonomy posts.
	 * @var    string
	 * @access   public
	 * @since    1.0.0
	 */
	public $single;

	/**
	 * The description of the custom taxonomy.
	 * @var    string
	 * @access   public
	 * @since    1.0.0
	 */
	public $description;

	/**
	 * The options of the custom taxonomy.
	 * @var    array
	 * @access   public
	 * @since    1.0.0
	 */
	public $options;

	public function __construct( $taxonomy, $object_type, $plural = '', $single = '', $description = '', $options = [] ) {

		if ( ! $taxonomy || ! $plural || ! $single ) {
			return;
		}

		// Taxonomy name and labels
		$this->taxonomy    = $taxonomy;
		$this->object_type = $object_type;
		$this->plural      = $plural;
		$this->single      = $single;
		$this->description = $description;
		$this->options     = $options;

		// Regsiter taxonomy
		add_action( 'init', [ $this, 'register_taxonomy' ] );
	}

	/**
	 * Register new taxonomy
	 * @return void
	 */
	public function register_taxonomy() {

		$labels = [
			'name'              => $this->plural,
			'singular_name'     => $this->single,
			'search_items'      => sprintf( esc_html__( 'Search %s', 'ideapark-moderno' ), $this->plural ),
			'all_items'         => sprintf( esc_html__( 'All %s', 'ideapark-moderno' ), $this->plural ),
			'view_item'         => sprintf( esc_html__( 'View %s', 'ideapark-moderno' ), $this->single ),
			'parent_item'       => sprintf( esc_html__( 'Parent %s', 'ideapark-moderno' ), $this->single ),
			'parent_item_colon' => sprintf( esc_html__( 'Parent %s', 'ideapark-moderno' ), $this->single ),
			'edit_item'         => sprintf( esc_html__( 'Edit %s', 'ideapark-moderno' ), $this->single ),
			'update_item'       => sprintf( esc_html__( 'Update %s', 'ideapark-moderno' ), $this->single ),
			'add_new_item'      => sprintf( esc_html__( 'Add New %s', 'ideapark-moderno' ), $this->single ),
			'new_item_name'     => sprintf( esc_html__( 'New %s', 'ideapark-moderno' ), $this->single ),
			'menu_name'         => $this->plural,
		];

		$args = [
			'labels'             => apply_filters( $this->taxonomy . '_labels', $labels ),
			'description'        => $this->description,
			'public'             => true,
			'publicly_queryable' => true,
			'show_in_nav_menus'  => true,
			'show_ui'            => true,
			'show_in_menu'       => true,
			'show_tagcloud'      => true,
			'show_in_quick_edit' => null,
			'show_in_rest'       => true,
			'rest_base'          => $this->taxonomy,
			'hierarchical'       => true,
			'rewrite'            => true,
			'capability_type'    => [],
			'meta_box_cb'        => null,
			'show_admin_column'  => false,
			'_builtin'           => false,
		];

		$remove_column = [];
		$add_column    = [];

		$args = array_merge( $args, $this->options );

		if ( ! empty( $args['modified'] ) ) {
			add_action( "created_{$this->taxonomy}", $args['modified'], 100, 2 );
			add_action( "edited_{$this->taxonomy}", $args['modified'], 100, 2 );
			add_action( "delete_{$this->taxonomy}", $args['modified'], 100, 2 );
			add_action( "sorted_{$this->taxonomy}", $args['modified'], 100, 0 );
			unset( $args['modified'] );
		}

		if ( ! empty( $args['deep'] ) ) {
			$taxonomy = $this->taxonomy;
			$deep     = $args['deep'];
			add_filter( 'taxonomy_parent_dropdown_args', function ( $dropdown_args, $_taxonomy, $context ) use ( $taxonomy, $deep ) {

				if ( $taxonomy == $_taxonomy ) {
					$dropdown_args['depth'] = $deep;
				}

				return $dropdown_args;
			}, 10, 3 );

			unset( $args['deep'] );
		}

		if ( ! empty( $args['custom_column'] ) ) {
			$add_column = $columns = $args['custom_column'];

			add_filter( 'manage_' . $this->taxonomy . '_custom_column', function ( $content, $column_name, $term_id ) use ( $columns ) {
				if ( array_key_exists( $column_name, $columns ) ) {

					$column = $columns[ $column_name ];

					switch ( $column['type'] ) {
						case 'text':
							return get_term_meta( $term_id, $column['term_meta'], true );
							break;

						case 'yes_no':
							return get_term_meta( $term_id, $column['term_meta'], true ) ? '<span class="dashicons dashicons-yes-alt"></span>' : '';
							break;

						case 'icon_image':
							$icon = '';
							if ( $thumbnail_id = get_term_meta( $term_id, $column['term_meta']['image'], true ) ) {
								$image = wp_get_attachment_thumb_url( $thumbnail_id );
							} elseif ( $icon_class = get_term_meta( $term_id, $column['term_meta']['icon'], true ) ) {
								$icon = "<span class='ideapark_taxcol-icon-wrap'><i class='ideapark_taxcol-icon " . esc_attr( $icon_class ) . "'></i></span>";
							} else {
								$image = '';
							}

							return $icon ? $icon : ( $image ? ( '<img src="' . esc_url( str_replace( ' ', '%20', $image ) ) . '" alt="' . esc_attr( $column['title'] ) . '" class="ideapark_taxcol-image" height="55" width="55" />' ) : '' );
							break;
					}


				} else {
					return $content;
				}
			}, 10, 3 );

			unset( $args['custom_column'] );
		}

		if ( ! empty( $args['hide_description'] ) ) {
			if ( is_string( $args['hide_description'] ) ) {
				$args['hide_description'] = [ $args['hide_description'] ];
			}
			if ( $args['hide_description'] === true || in_array( 'list', $args['hide_description'] ) ) {
				$remove_column[] = 'description';
			}

			$hide_func = function ( $taxonomy ) {
				?>
				<style>
					.term-description-wrap {
						display: none;
					}
				</style><?php
			};

			if ( $args['hide_description'] === true || in_array( 'add', $args['hide_description'] ) ) {
				add_action( $this->taxonomy . "_add_form", $hide_func, 10, 2 );
			}
			if ( $args['hide_description'] === true || in_array( 'edit', $args['hide_description'] ) ) {
				add_action( $this->taxonomy . "_edit_form", $hide_func, 10, 2 );
			}

			unset( $args['hide_description'] );
		}

		if ( ! empty( $args['hide_slug'] ) ) {
			if ( is_string( $args['hide_slug'] ) ) {
				$args['hide_slug'] = [ $args['hide_slug'] ];
			}
			if ( $args['hide_slug'] === true || in_array( 'list', $args['hide_slug'] ) ) {
				$remove_column[] = 'slug';
			}

			$hide_func = function ( $taxonomy ) {
				?>
				<style>
					.term-slug-wrap {
						display: none;
					}
				</style><?php
			};

			if ( $args['hide_slug'] === true || in_array( 'add', $args['hide_slug'] ) ) {
				add_action( $this->taxonomy . "_add_form", $hide_func, 10, 2 );
			}
			if ( $args['hide_slug'] === true || in_array( 'edit', $args['hide_slug'] ) ) {
				add_action( $this->taxonomy . "_edit_form", $hide_func, 10, 2 );
			}

			unset( $args['hide_slug'] );
		}


		if ( ! empty( $args['hide_posts'] ) ) {
			$remove_column[] = 'posts';
			unset( $args['hide_posts'] );
		}

		if ( ! empty( $args['sort_notice'] ) ) {
			global $ideapark_moderno_sort_notice;
			$ideapark_moderno_sort_notice[$this->taxonomy] = $args['sort_notice'];
			unset( $args['sort_notice'] );
		}


		if ( ! empty( $remove_column ) || ! empty( $add_column ) ) {
			add_filter( 'manage_edit-' . $this->taxonomy . '_columns', function ( $columns ) use ( $remove_column, $add_column ) {
				if ( ! empty( $remove_column ) ) {
					foreach ( $remove_column AS $column ) {
						if ( isset( $columns[ $column ] ) ) {
							unset( $columns[ $column ] );
						}
					}
				}

				if ( ! empty( $add_column ) ) {
					$columns_new = [];

					$_num = 0;
					foreach ( $columns AS $_index => $_column ) {
						foreach ( $add_column AS $column_slug => $column ) {
							if ( $_num == $column['position'] ) {
								unset( $add_column[ $column_slug ] );
								$columns_new[ $column_slug ] = $column['title'];
							}
						}
						$columns_new[ $_index ] = $_column;
						$_num ++;
					}
					foreach ( $add_column AS $column_slug => $column ) {
						$columns_new[ $column_slug ] = $column['title'];
					}
					$columns = $columns_new;
				}

				return $columns;
			} );
		}

		register_taxonomy( $this->taxonomy, $this->object_type, apply_filters( $this->taxonomy . '_register_args', $args, $this->taxonomy ) );
	}
}
