<?php

class Ideapark_Mega_Menu {

	public $assets_url;
	public $assets_dir;
	public $script_suffix;
	public $_version;
	public $_token;
	private $fields = [
		'columns',
		'content',
		'icon',
		'html_block',
		'product_category',
		'product_attr',
		'expand',
		'badge_text',
		'badge_color'
	];
	private $columns = 1;
	private $expand = 0;
	private $counter = 0;

	/*--------------------------------------------*
	 * Constructor
	 *--------------------------------------------*/

	function __construct() {
		$this->_version = IDEAPARK_VERSION;
		$this->_token   = 'ideapark_mega_menu';

		$this->assets_url    = IDEAPARK_URI . '/includes/megamenu/assets/';
		$this->assets_dir    = IDEAPARK_DIR . '/includes/megamenu/assets/';
		$this->script_suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		if ( ! is_admin() ) {
			add_filter( 'wp_get_nav_menu_items', [ $this, 'wp_get_nav_menu_items' ], 10, 3 );
			add_filter( 'walker_nav_menu_start_el', [ $this, 'walker_nav_menu_start_el' ], 10, 4 );
			add_filter( 'nav_menu_css_class', [ $this, 'menu_item_class' ], 100, 4 );
			add_filter( 'nav_menu_submenu_css_class', [ $this, 'submenu_class' ], 100, 3 );
			add_filter( 'nav_menu_item_id', [ $this, 'menu_item_id' ], 100, 4 );
		}

		add_filter( 'wp_setup_nav_menu_item', [ $this, 'add_custom_nav_fields' ] );
		add_action( 'wp_update_nav_menu_item', [ $this, 'update_custom_nav_fields' ], 10, 3 );
		add_action( 'wp_nav_menu_item_custom_fields', [ $this, 'out_custom_nav_fields' ], 10, 5 );
		add_action( 'admin_enqueue_scripts', [ $this, 'scripts' ] );
		add_action( 'wp_ajax_ideapark_load_mega_menu', [ $this, 'select_icon' ] );
		add_action( 'ideapark_import_nav_meta', [ $this, 'import_nav_meta' ], 10, 4 );

	} // end constructor

	public function import_nav_meta( $post_id, $meta, $processed_posts, $processed_terms ) {
		foreach ( $this->fields as $field_name ) {
			$meta_name = '_menu_item_' . $field_name;
			if ( ! empty( $meta[ $meta_name ] ) ) {
				$value = $meta[ $meta_name ];
				if ( $meta_name == 'html_block' ) {
					$value = isset( $processed_posts[ intval( $value ) ] ) ? $processed_posts[ intval( $value ) ] : 0;
				}
				update_post_meta( $post_id, $meta_name, $value );
			}
		}
	}

	public function out_custom_nav_fields( $item_id, $item, $depth, $args, $id ) {
		/* New fields insertion starts here */
		?>
		<div class="wp-clearfix"></div>

		<p class="ip-field-custom ip-field-custom--depth-0 ip-field-custom--content">
			<label for="edit-menu-item-content-<?php echo esc_attr( $item_id ); ?>">
				<?php esc_html_e( 'Submenu Content', 'moderno' ); ?><br>
				<select id="edit-menu-item-content-<?php echo esc_attr( $item_id ); ?>"
				        class="edit-menu-item-content ip-field-custom__content"
				        name="menu-item-content[<?php echo esc_attr( $item_id ); ?>]">
					<option
						value="default" <?php selected( "default", $item->content ); ?>><?php esc_html_e( 'Default', 'moderno' ); ?></option>
					<option class="ip-field-custom__option-html-block"
					        value="html_block" <?php selected( "html_block", $item->content ); ?>><?php esc_html_e( 'HTML block', 'moderno' ); ?></option>
					<option
						value="product_category" <?php selected( "product_category", $item->content ); ?>><?php esc_html_e( 'Product subcategories', 'moderno' ); ?></option>
					<option
						value="product_attr" <?php selected( "product_attr", $item->content ); ?>><?php esc_html_e( 'Product attribute terms', 'moderno' ); ?></option>
				</select>
			</label>
		</p>

		<p class="ip-field-custom ip-field-custom--primary-0 ip-field-custom--columns">
			<label for="edit-menu-item-columns-<?php echo esc_attr( $item_id ); ?>">
				<?php esc_html_e( 'Submenu Columns', 'moderno' ); ?><br>
				<select id="edit-menu-item-columns-<?php echo esc_attr( $item_id ); ?>" class="edit-menu-item-columns"
				        name="menu-item-columns[<?php echo esc_attr( $item_id ); ?>]">
					<option
						value="1" <?php selected( "1", $item->columns ); ?>><?php esc_html_e( '1 column', 'moderno' ); ?></option>
					<option
						value="2" <?php selected( "2", $item->columns ); ?>><?php esc_html_e( '2 columns', 'moderno' ); ?></option>
					<option
						value="3" <?php selected( "3", $item->columns ); ?>><?php esc_html_e( '3 columns', 'moderno' ); ?></option>
					<option
						value="4" <?php selected( "4", $item->columns ); ?>><?php esc_html_e( '4 columns', 'moderno' ); ?></option>
					<option
						value="5" <?php selected( "5", $item->columns ); ?>><?php esc_html_e( 'Fullwidth', 'moderno' ); ?></option>
				</select>
			</label>
		</p>

		<p class="ip-field-custom ip-field-custom--primary-0 ip-field-custom--expand">
			<label for="edit-menu-item-expand-<?php echo esc_attr( $item_id ); ?>">
				<input type="hidden" name="menu-item-expand[<?php echo esc_attr( $item_id ); ?>]" value="0"/>
				<input type="checkbox" <?php checked( "1", $item->expand ); ?>
				       name="menu-item-expand[<?php echo esc_attr( $item_id ); ?>]" value="1"
				       id="edit-menu-item-expand-<?php echo esc_attr( $item_id ); ?>"/>
				<?php esc_html_e( 'Expand third level', 'moderno' ); ?><br>
			</label>
		</p>

		<?php foreach (
			[
				'html_block'       => esc_html__( 'HTML Block', 'moderno' ),
				'product_category' => esc_html__( 'Parent Category', 'moderno' ),
				'product_attr'     => esc_html__( 'Attribute', 'moderno' )
			] as $field => $filed_title
		) { ?>
			<p class="ip-field-custom ip-field-custom--depth-0 ip-field-custom--<?php echo esc_attr( $field ); ?>"
			   data-type="<?php echo esc_attr( $field ); ?>"
			   data-item-id="<?php echo esc_attr( $item_id ); ?>">
				<label>
					<?php echo ideapark_wrap( $filed_title ); ?><br>
					<span class="ip-field-custom__wrap">
			<a class="ip-field-custom__loader ip-field-custom__loader--box" href="#"
			   onclick="return false;">
						<?php if ( ! empty( $item->$field ) ) {

							switch ( $field ) {
								case 'html_block':
									echo esc_html( get_the_title( $item->$field ) );
									break;
								case 'product_category':
									if ( $item->$field == '-shop-' ) {
										echo esc_html__( 'Shop', 'moderno' );
									} elseif ( $term = get_term_by( 'slug', $item->$field, 'product_cat' ) ) {
										echo esc_html( $term->name );
									}
									break;
								case 'product_attr':

									if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {

										$attribute_taxonomies = wc_get_attribute_taxonomies();

										if ( ! empty( $attribute_taxonomies ) ) {
											foreach ( $attribute_taxonomies as $tax ) {
												if ( $item->$field == wc_attribute_taxonomy_name( $tax->attribute_name ) ) {
													echo esc_html( $tax->attribute_name );
												}
											}
										}
									}

									break;
							}
						} else {
							echo '&mdash; ';
							switch ( $field ) {
								case 'html_block':
									esc_html_e( 'Select HTML block', 'moderno' );
									break;
								case 'product_category':
									esc_html_e( 'Select category', 'moderno' );
									break;
								case 'product_attr':
									esc_html_e( 'Select attribute', 'moderno' );
									break;
							}
							echo ' &mdash;';
						} ?>
						<input type="hidden" class="ip-field-custom__val"
						       name="menu-item-<?php echo esc_attr( $field ); ?>[<?php echo esc_attr( $item_id ); ?>]"
						       value="<?php echo ! empty( $item->$field ) ? esc_attr( $item->$field ) : ''; ?>"
						       id="edit-menu-item-<?php echo esc_attr( $field ); ?>-<?php echo esc_attr( $item_id ); ?>"/>
					</a>
			<span class="ip-field-custom__container"><span class="spinner"></span></span>
			<?php if ( $field == 'html_block' ) { ?>
				<span class="ip-field-custom__customizer"><a
						target="_blank"
						href="<?php echo esc_url( admin_url( 'edit.php?post_type=html_block' ) ); ?>"><?php esc_html_e( 'Manage html blocks', 'moderno' ) ?></a>
				</span>
			<?php } ?>
			</span>
				</label>
			</p>
		<?php } ?>

		<p class="ip-field-custom ip-field-custom--primary-0 ip-field-custom--icon" data-type="icon"
		   data-item-id="<?php echo esc_attr( $item_id ); ?>">
			<label>
				<?php esc_html_e( 'Icon', 'moderno' ); ?><br>
				<input type="hidden" class="ip-field-custom__val"
				       name="menu-item-icon[<?php echo esc_attr( $item_id ); ?>]"
				       value="<?php echo ! empty( $item->icon ) ? esc_attr( $item->icon ) : ''; ?>"
				       id="edit-menu-item-icon-<?php echo esc_attr( $item_id ); ?>"/>
			</label>
			<span class="ip-field-custom__wrap">
				<a class="ip-field-custom__loader ip-field-custom__loader--box" href="#"
				   onclick="return false;"><?php if ( ! empty( $item->icon ) ) { ?><span
						class="ip-field-custom__icon <?php echo esc_attr( $item->icon ); ?>"></span><?php } ?></a>
				<span class="ip-field-custom__container"><span class="spinner"></span></span>
			</span>

		</p>

		<?php if ( ! $item->badge_text ) { ?>
			<p class="ip-field-custom ip-field-custom--primary-not-0 ip-field-custom--badge-add">
				<a onclick="return false"
				   class="ip-field-custom__badge_add button-link"><?php esc_html_e( 'Add badge', 'moderno' ); ?></a>
			</p>
		<?php } ?>

		<p class="ip-field-custom ip-field-custom--left ip-field-custom--primary-not-0 ip-field-custom--badge_text <?php if ( ! $item->badge_text ) { ?>ip-field-custom--hidden<?php } ?>">
			<label for="edit-menu-item-badge_text-<?php echo esc_attr( $item_id ); ?>">
				<?php esc_html_e( 'Badge text', 'moderno' ); ?><br>
				<input type="text" id="edit-menu-item-badge_text-<?php echo esc_attr( $item_id ); ?>"
				       class="edit-menu-item-badge_text ip-field-custom__badge_text"
				       name="menu-item-badge_text[<?php echo esc_attr( $item_id ); ?>]"
				       value="<?php echo esc_attr( $item->badge_text ); ?>"/>
			</label>
		</p>

		<p class="ip-field-custom ip-field-custom--right ip-field-custom--primary-not-0 ip-field-custom--badge_color <?php if ( ! $item->badge_text ) { ?>ip-field-custom--hidden<?php } ?>">
			<label for="edit-menu-item-badge_color-<?php echo esc_attr( $item_id ); ?>">
				<?php esc_html_e( 'Badge color', 'moderno' ); ?><br>
				<span>
					<input type="text" id="edit-menu-item-badge_color-<?php echo esc_attr( $item_id ); ?>"
					       placeholder="<?php echo esc_attr( ideapark_mod( 'accent_color' ) ); ?>"
					       class="edit-menu-item-badge_color ip-field-custom__badge_color"
					       name="menu-item-badge_color[<?php echo esc_attr( $item_id ); ?>]"
					       value="<?php echo esc_attr( $item->badge_color ); ?>"/>
				</span>
			</label>
		</p>

		<?php
		/* New fields insertion ends here */
	}

	public function select_icon() {

		$item_id = isset( $_POST['item_id'] ) ? (int) ( $_POST['item_id'] ) : '';
		$type    = isset( $_POST['type'] ) ? trim( $_POST['type'] ) : '';
		$value   = isset( $_POST['value'] ) ? trim( $_POST['value'] ) : '';

		if ( $item_id && $type ) {
			switch ( $type ) {
				case 'icon':
					$icons = ideapark_font_icon_options();
					if ( $icons ) { ?>
						<select
							class="ip-field-custom__icon-select"
							name="menu-item-<?php echo esc_attr( $type ); ?>-id[<?php echo esc_attr( $item_id ); ?>]">
							<option></option>
							<?php foreach ( $icons as $icon_val => $icon_name ) { ?>
								<option
									value="<?php echo esc_attr( $icon_val ); ?>"
									<?php selected( $value, $icon_val ); ?>><?php echo esc_html( $icon_name ); ?></option>
							<?php } ?>

						</select>
					<?php }
					break;

				case 'html_block':
					wp_dropdown_pages(
						[
							'name'              => 'menu-item-' . esc_attr( $type ) . '[' . esc_attr( $item_id ) . ']',
							'class'             => 'ip-field-custom__content-select',
							'echo'              => 1,
							'show_option_none'  => '',
							'option_none_value' => '0',
							'selected'          => $value,
							'post_type'         => 'html_block',
							'post_status'       => [ 'publish', 'draft' ],
						]
					);
					break;

				case 'product_category' :
					$list = [
						''       => '',
						'-shop-' => esc_html__( 'Shop', 'moderno' ),
					];

					$args = [
						'taxonomy'     => 'product_cat',
						'orderby'      => 'meta_value_num',
						'meta_key'     => 'order',
						'show_count'   => 0,
						'pad_counts'   => 0,
						'hierarchical' => 1,
						'title_li'     => '',
						'hide_empty'   => 0,
						'exclude'      => ideapark_hidden_category_ids() ?: null,
					];
					if ( $all_categories = get_categories( $args ) ) {

						$category_name   = [];
						$category_slug   = [];
						$category_parent = [];
						foreach ( $all_categories as $cat ) {
							$category_name[ $cat->term_id ]    = esc_html( $cat->name );
							$category_slug[ $cat->term_id ]    = $cat->slug;
							$category_parent[ $cat->parent ][] = $cat->term_id;
						}

						$get_category = function ( $parent = 0, $prefix = ' - ' ) use ( &$list, &$category_parent, &$category_name, &$category_slug, &$get_category ) {
							if ( array_key_exists( $parent, $category_parent ) ) {
								$categories = $category_parent[ $parent ];
								foreach ( $categories as $category_id ) {
									$list[ $category_slug[ $category_id ] ] = $prefix . $category_name[ $category_id ];
									$get_category( $category_id, $prefix . ' - ' );
								}
							}
						};

						$get_category();
					}

					if ( $list ) { ?>
						<select
							class="ip-field-custom__content-select"
							name="menu-item-<?php echo esc_attr( $type ); ?>[<?php echo esc_attr( $item_id ); ?>]">
							<?php foreach ( $list as $val => $name ) { ?>
								<option
									value="<?php echo esc_attr( $val ); ?>"
									<?php selected( $value, $val ); ?>><?php echo esc_html( $name ); ?></option>
							<?php } ?>
						</select>
					<?php }
					break;

				case 'product_attr' :
					$list = [
						'' => '',
					];

					if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {

						$attribute_taxonomies = wc_get_attribute_taxonomies();

						if ( ! empty( $attribute_taxonomies ) ) {
							foreach ( $attribute_taxonomies as $tax ) {
								if ( taxonomy_exists( $taxonomy = wc_attribute_taxonomy_name( $tax->attribute_name ) ) ) {
									$list[ $taxonomy ] = $tax->attribute_name;
								}
							}
						}
					}

					if ( $list ) { ?>
						<select
							class="ip-field-custom__content-select"
							name="menu-item-<?php echo esc_attr( $type ); ?>[<?php echo esc_attr( $item_id ); ?>]">
							<?php foreach ( $list as $val => $name ) { ?>
								<option
									value="<?php echo esc_attr( $val ); ?>"
									<?php selected( $value, $val ); ?>><?php echo esc_html( $name ); ?></option>
							<?php } ?>
						</select>
					<?php }
					break;
			}
		}
		wp_die();
	}

	public function scripts( $hook ) {
		if ( 'nav-menus.php' != $hook ) {
			return;
		}

		wp_deregister_script( 'select2' );
		wp_deregister_style( 'select2' );

		wp_deregister_script( 'selectWoo' );
		wp_deregister_style( 'selectWoo' );

		wp_enqueue_style( 'select2-theme', esc_url( $this->assets_url ) . 'css/select2.min.css', false, '4.1.0-rc.0', 'all' );
		wp_enqueue_style( $this->_token . '-megamenu', esc_url( $this->assets_url ) . 'css/mega-menu.css', [], ideapark_mtime( $this->assets_dir . 'css/mega-menu.css' ) );

//		wp_enqueue_media();
		wp_enqueue_script( 'select2-theme', esc_url( $this->assets_url ) . 'js/select2.full.min.js', [ 'jquery' ], '4.1.0-rc.0', true );
		wp_enqueue_script( $this->_token . '-megamenu', esc_url( $this->assets_url ) . 'js/mega-menu.js', [ 'jquery' ], ideapark_mtime( $this->assets_dir . 'js/mega-menu.js' ), true );
		wp_localize_script( $this->_token . '-megamenu', 'ideapark_wp_vars_mega_menu', [
			'themeUri'                => get_template_directory_uri(),
			'placeholderIcon'         => esc_html__( "Select icon", 'moderno' ),
			'select_html_block'       => esc_html__( 'Select HTML block', 'moderno' ),
			'select_product_category' => esc_html__( 'Select parent category', 'moderno' ),
			'select_product_attr'     => esc_html__( 'Select attribute', 'moderno' ),
		] );
	}

	function add_custom_nav_fields( $menu_item ) {

		foreach ( $this->fields as $field ) {
			$menu_item->$field = get_post_meta( $menu_item->ID, '_menu_item_' . $field, true );
		}

		return $menu_item;
	}

	function update_custom_nav_fields( $menu_id, $menu_item_db_id, $args ) {
		foreach ( $this->fields as $field_name ) {
			$post_field = 'menu-item-' . $field_name;
			$meta_name  = '_' . str_replace( '-', '_', $post_field );
			if ( isset( $_POST[ $post_field ] ) && is_array( $_POST[ $post_field ] ) && array_key_exists( $menu_item_db_id, $_POST[ $post_field ] ) ) {
				update_post_meta( $menu_item_db_id, $meta_name, $_POST[ $post_field ][ $menu_item_db_id ] );
			} else {
				delete_post_meta( $menu_item_db_id, $meta_name );
			}
		}
	}

	public function wp_get_nav_menu_items( $items, $menu, $args ) {
		$parent_clear_ids = [];
		foreach ( $items as $item ) {
			if ( ! empty( $item->content ) && $item->content !== 'default' ) {
				$parent_clear_ids[] = $item->ID;
			}
		}
		$items     = array_filter( $items, function ( $_item ) use ( $parent_clear_ids ) {
			$a = ! in_array( (int) $_item->menu_item_parent, $parent_clear_ids );

			return $a;
		} );
		$need_sort = false;
		foreach ( $items as $item ) {
			if ( ! empty( $item->content ) ) {
				switch ( $item->content ) {
					case 'product_category':
						if (
							( ! empty( $item->product_category ) ) &&
							( $taxonomy = get_taxonomy( 'product_cat' ) )
						) {

							if ( $item->product_category == '-shop-' ) {
								$term_parent_id = 0;
							} else {
								if (
									( $term_parent = get_term_by( 'slug', $item->product_category, 'product_cat' ) ) &&
									! is_wp_error( $term_parent )
								) {
									$term_parent_id = $term_parent->term_id;
								} else {
									continue 2;
								}
							}

							$args = [
								'taxonomy'     => 'product_cat',
								'orderby'      => 'meta_value_num',
								'meta_key'     => 'order',
								'show_count'   => 0,
								'pad_counts'   => 0,
								'hierarchical' => 1,
								'title_li'     => '',
								'hide_empty'   => 1,
								'exclude'      => ideapark_hidden_category_ids() ?: null,
								'child_of'     => $term_parent_id,
							];
							if ( $all_categories = get_categories( $args ) ) {
								foreach ( $all_categories as $index => $term ) {
									$id = - $term->term_id * 100 - $this->counter;

									$menu_item                   = WP_Post::get_instance( $item->ID );
									$menu_item->ID               = $id;
									$menu_item->post_type        = 'nav_menu_item';
									$menu_item->menu_order       = $item->menu_order + ( $index + 1 ) / 1000;
									$menu_item->db_id            = $id;
									$menu_item->menu_item_parent = $term->parent == $term_parent_id ? $item->ID : ( - $term->parent * 100 - $this->counter );
									$menu_item->post_parent      = $menu_item->menu_item_parent;
									$menu_item->object_id        = $term->term_id;
									$menu_item->object           = 'product_cat';
									$menu_item->type             = 'taxonomy';
									$menu_item->type_label       = $taxonomy->labels->singular_name;
									$menu_item->url              = get_term_link( (int) $menu_item->object_id, $menu_item->object );
									$menu_item->title            = $term->name;
									$menu_item->target           = '';
									$menu_item->attr_title       = '';
									$menu_item->description      = '';
									$menu_item->classes          = [ '' ];
									$menu_item->xfn              = '';

									$items[]   = $menu_item;
									$need_sort = true;
								}
								$this->counter ++;
							}
						}
						break;
					case 'product_attr':
						if (
							( ! empty( $item->product_attr ) ) &&
							( $taxonomy = get_taxonomy( $item->product_attr ) )
						) {
							$args = [
//									'orderby'      => 'meta_value_num',
//									'meta_key'     => 'order',
								'show_count'   => 0,
								'pad_counts'   => 0,
								'hierarchical' => 0,
								'title_li'     => '',
								'hide_empty'   => 1,
							];

							if ( ( $all_categories = get_terms( $item->product_attr, $args ) ) && ! is_wp_error( $all_categories ) ) {
								foreach ( $all_categories as $index => $term ) {
									$id = - $term->term_id * 100 - $this->counter;

									$menu_item                   = WP_Post::get_instance( $item->ID );
									$menu_item->ID               = $id;
									$menu_item->post_type        = 'nav_menu_item';
									$menu_item->menu_order       = $item->menu_order + ( $index + 1 ) / 1000;
									$menu_item->db_id            = $id;
									$menu_item->menu_item_parent = $item->ID;
									$menu_item->post_parent      = $menu_item->menu_item_parent;
									$menu_item->object_id        = $term->term_id;
									$menu_item->object           = $item->product_attr;
									$menu_item->type             = 'taxonomy';
									$menu_item->type_label       = $taxonomy->labels->singular_name;
									$menu_item->url              = get_term_link( (int) $menu_item->object_id, $menu_item->object );
									$menu_item->title            = $term->name;
									$menu_item->target           = '';
									$menu_item->attr_title       = '';
									$menu_item->description      = '';
									$menu_item->classes          = [ '' ];
									$menu_item->xfn              = '';

									$items[]   = $menu_item;
									$need_sort = true;
								}
								$this->counter ++;
							}
						}
						break;
				}

			}
		}

		if ( $need_sort ) {
			$items = wp_list_sort(
				$items,
				[
					'menu_order' => 'ASC',
				]
			);
			$i     = 1;
			foreach ( $items as $k => $item ) {
				$items[ $k ]->menu_order = $i ++;
			}
		}

		return $items;
	}

	public function walker_nav_menu_start_el( $item_output, $item, $depth, $args ) {
		if ( ( $args->menu_id == 'top-menu-desktop' || $args->menu_id == 'mobile-top-menu' ) && $depth == 0 ) {
			$top_menu_depth = ideapark_mod( 'top_menu_depth' ) === 'unlim' ? 1000 : (int) ideapark_mod( 'top_menu_depth' );
			if ( $args->menu_id == 'top-menu-desktop' && ! empty( $item->icon ) ) {
				$item_output = preg_replace( '~<a[^>]*>~', '\\0<i class="c-top-menu__icon ' . esc_attr( $item->icon ) . '"></i>', $item_output );
			}
			if ( strpos( $item_output, '<a>' ) !== false || strpos( $item_output, '<a href="#">' ) !== false ) {
				$item_output = str_replace( '<a href="#">', '<span class="a">', $item_output );
				$item_output = str_replace( '<a>', '<span class="a">', $item_output );
				$item_output = str_replace( '</a>', '</span>', $item_output );
			}
			if ( ! empty( $item->columns ) ) {
				$this->columns = (int) $item->columns;
			}
			if ( ! empty( $item->expand ) ) {
				$this->expand = 1;
			}

			if ( ! empty( $item->content ) && $item->content == 'html_block' && ! empty( $item->html_block ) ) {
				if ( $top_menu_depth > 1 ) {
					if ( $page_content = ideapark_html_block( (int) $item->html_block ) ) {
						$columns = ! empty( $item->columns ) ? (int) $item->columns : 1;
						if ( $args->menu_id == 'top-menu-desktop' ) {
							$item_output .= ideapark_wrap( $page_content, '<div class="c-top-menu__submenu c-top-menu__submenu--content c-top-menu__submenu--columns-' . esc_attr( $columns ) . '">', '</div>' );
						} elseif ( $args->menu_id == 'mobile-top-menu' ) {
							$item_output .= ideapark_wrap( $page_content, '<div class="c-mobile-menu__submenu c-mobile-menu__submenu--content">', '</div>' );
						}
					}
				}
			} elseif ( $args->menu_id == 'top-menu-desktop' && ! empty( $item->columns ) ) {
				$this->columns = (int) $item->columns;
			}
			if ( $args->menu_id == 'top-menu-desktop' && ! empty( $item->expand ) ) {
				$this->expand = 1;
			} else {
				$this->expand = 0;
			}
		} else {
			if ( ( $args->menu_id == 'top-menu-desktop' || $args->menu_id == 'mobile-top-menu' ) && $depth > 0 && ! empty( $item->badge_text ) ) {
				$badge_color = preg_match( '~^\#[0-9A-F]{3,6}$~i', $item->badge_color ) ? $item->badge_color : 'var(--accent-color)';
				$item_output = preg_replace( '~<a[^>]*>~', '\\0<span class="c-menu-badge__wrap">', $item_output );
				$item_output = preg_replace( '~</a>~', '<span class="c-menu-badge" style="--badge-color:' . esc_attr( $badge_color ) . '">' . esc_html( $item->badge_text ) . '</span></span>\\0', $item_output );
			}
			if ( strpos( $item_output, '<a>' ) !== false || strpos( $item_output, '<a href="#">' ) !== false ) {
				$item_output = str_replace( '<a href="#">', '<span class="a">', $item_output );
				$item_output = str_replace( '<a>', '<span class="a">', $item_output );
				$item_output = str_replace( '</a>', '</span>', $item_output );
			}
			$this->columns = 1;
		}

		return $item_output;
	}

	public function menu_item_class( $classes, $item, $args, $depth ) {
		$top_menu_depth = ideapark_mod( 'top_menu_depth' ) === 'unlim' ? 1000 : (int) ideapark_mod( 'top_menu_depth' );
		$custom_classes = array_filter(
			array_slice( $classes, 0, array_search( 'menu-item', $classes ) ?: 0 ),
			fn( $c ) => $c !== ''
		);

		if ( isset( $args->menu_id ) ) {
			if ( $args->menu_id == 'top-menu-desktop' ) {
				$classes = array_map( function ( $class ) use ( $depth, $top_menu_depth ) {
					if ( preg_match( '~current~', $class ) ) {
						return $class;
					} elseif ( preg_match( '~menu-item-\d+~', $class ) ) {
						return $class;
					} else {
						switch ( $class ) {

							case 'menu-item':
								return ( $depth > 0 ? 'c-top-menu__subitem' : 'c-top-menu__item' );

							case 'menu-item-has-children':
								return $depth + 1 < $top_menu_depth ? ( $this->expand && $depth == 1 ) ? '' : ( $depth > 0 ? 'c-top-menu__subitem--has-children' : 'c-top-menu__item--has-children' ) : '';

							default:
								return '';
						}
					}
				}, $classes );

				if ( $depth == 0 && $top_menu_depth > 1 && ! empty( $item->content ) && $item->content == 'html_block' && ! empty( $item->html_block ) ) {
					$classes[] = 'c-top-menu__item--has-children';
				}

				if ( $depth == 1 && $this->expand ) {
					$classes[] = 'c-top-menu__subitem--expand';
				} elseif ( $depth >= 1 ) {
					$classes[] = 'c-top-menu__subitem--collapse';
				}
				$classes[] = 'js-menu-item';

				return array_unique( array_filter( array_merge( $classes, $custom_classes ) ) );
			} elseif ( $args->menu_id == 'mobile-top-menu' ) {

				$classes = array_map( function ( $class ) use ( $depth ) {

					if ( preg_match( '~current~', $class ) ) {
						return $class;
					} elseif ( preg_match( '~menu-item-\d+~', $class ) ) {
						return $class;
					} else {
						switch ( $class ) {

							case 'menu-item':
								return ( $depth > 0 ? 'c-mobile-menu__subitem' : 'c-mobile-menu__item' );

							case 'menu-item-has-children':
								return ( $depth > 0 ? 'c-mobile-menu__subitem--has-children' : 'c-mobile-menu__item--has-children' );

							default:
								return '';
						}
					}
				}, $classes );

				if ( $depth == 0 && ! empty( $item->content ) && $item->content == 'html_block' && ! empty( $item->html_block ) ) {
					$classes[] = 'c-mobile-menu__item--has-children';
				}

				return array_unique( array_filter( array_merge( $classes, $custom_classes ) ) );
			}
		}

		return $classes;
	}

	public function submenu_class( $classes, $args, $depth ) {

		if ( $args->menu_id == 'top-menu-desktop' ) {
			$classes = array_map( function ( $class ) {

				if ( preg_match( '~current~', $class ) ) {
					return $class;
				} else {
					switch ( $class ) {

						case 'sub-menu':
							return 'c-top-menu__submenu c-top-menu__submenu--columns-' . $this->columns . ( $this->expand ? ' c-top-menu__submenu--expand' : '' );

						default:
							return '';
					}
				}
			}, $classes );

			if ( $depth > 0 ) {
				$classes[] = 'c-top-menu__submenu--inner';
			}

			return array_unique( array_filter( $classes ) );
		} elseif ( $args->menu_id == 'mobile-top-menu' ) {
			$classes = array_map( function ( $class ) {

				switch ( $class ) {

					case 'sub-menu':
						return 'c-mobile-menu__submenu';

					default:
						return '';
				}
			}, $classes );

			if ( $depth > 0 ) {
				$classes[] = 'c-mobile-menu__submenu--inner';
			}

			return array_unique( array_filter( $classes ) );
		}

		return $classes;
	}

	public function menu_item_id( $menu_id, $item, $args, $depth ) {
		if ( preg_match( '~^top-menu~', $args->menu_id ) ) {
			return '';
		} else {
			return $menu_id;
		}
	}
}

new Ideapark_Mega_Menu();
