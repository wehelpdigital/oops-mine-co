<?php

class WP_Importer_Extend extends WP_Import {
	var $message = '';
	var $step_total = 1;
	var $step_done = 0;
	var $_posts = [];
	var $start_timer = 0;

	private function getmicrotime() {
		list( $usec, $sec ) = explode( " ", microtime() );

		return ( (float) $usec + (float) $sec );
	}

	public function importing() {
		$this->start_timer = $this->getmicrotime();
		add_filter( 'import_post_meta_key', [ $this, 'is_valid_meta_key' ] );
		add_filter( 'http_request_timeout', [ &$this, 'bump_request_timeout' ] );
		wp_defer_term_counting( true );
		wp_defer_comment_counting( true );
		wp_suspend_cache_invalidation( true );

		$this->message = '';
		do {
			$is_found    = false;
			$this->posts = [];

			foreach ( $this->_posts as $index => $post ) {
				if ( $post['post_type'] == 'attachment' ) {
					$is_found      = true;
					$this->posts[] = $post;
					unset( $this->_posts[ $index ] );

					if ( ! $this->message ) {
						$this->message = __( 'Importing media files', 'ideapark-moderno' );
					}

					if ($this->fetch_attachments) {
						$this->step_done ++;
						break;
					}
				}
			}

			if ( ! $is_found ) {
				foreach ( $this->_posts as $index => $post ) {
					if ( $post['post_type'] != 'nav_menu_item' ) {
						$is_found      = true;
						$this->posts[] = $post;
						unset( $this->_posts[ $index ] );

						if ( ! $this->message ) {
							$this->message = __( 'Importing posts', 'ideapark-moderno' );
						}
					}
				}
			}

			if ( ! $is_found ) {
				foreach ( $this->_posts as $index => $post ) {
					$is_found      = true;
					$this->posts[] = $post;
					unset( $this->_posts[ $index ] );

					if ( ! $this->message ) {
						$this->message = __( 'Importing nav menus', 'ideapark-moderno' );
					}
				}
			}

			if ( $this->posts ) {
				try {
					$this->process_posts();
				} catch ( Exception $e ) {
					$this->message = __( 'Error importing the post #', 'ideapark-moderno' ) . $post['post_id'] . ': ' . $e->getMessage();
					break;
				}
			}

			if ( memory_get_usage() / ( 1024 * 1024 ) > str_replace( 'M', '', ini_get( 'memory_limit' ) ) * 0.8 ) {
				break;
			}

			if ( $this->getmicrotime() - $this->start_timer >= 15 ) {
				break;
			}

		} while ( $is_found );

		unset( $this->posts );

		return $is_found;
	}

	public function import_start( $file ) {

		$f  = implode( '_', [ 'ideapark', 'api', 'theme', 'get', 'file' ] );
		$fn = $f( $file );

		if ( is_wp_error( $fn ) ) {
			return $fn;
		} else {
			$result = unzip_file( $fn, IDEAPARK_UPLOAD_DIR );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( is_file( $fn2 = IDEAPARK_UPLOAD_DIR . 'content.xml' ) ) {
				ideapark_delete_file( $fn );
				$fn = $fn2;
			} else {
				return new WP_Error( 'ideapark_file_not_found', __( 'File content.xml not found', 'ideapark-moderno' ) );
			}
		}

		parent::import_start( $fn );

		unlink( $fn );

		$this->_posts = $this->posts;
		$this->posts = [];

		if ( $this->fetch_attachments ) {
			foreach ( $this->_posts as $post ) {
				if ( $post['post_type'] == 'attachment' ) {
					$this->step_total ++;
				}
			}
		}

		$this->get_author_mapping();
	}

	public function import_terms() {
		add_filter( 'import_post_meta_key', [ $this, 'is_valid_meta_key' ] );
		add_filter( 'http_request_timeout', [ &$this, 'bump_request_timeout' ] );
		wp_defer_term_counting( true );
		wp_defer_comment_counting( true );

		wp_suspend_cache_invalidation( true );
		$this->process_categories();
		$this->process_tags();
		$this->process_terms();
		wp_suspend_cache_invalidation( false );
	}

	public function import_end() {

		$this->backfill_parents();
		$this->backfill_attachment_urls();
		$this->remap_featured_images();

		parent::import_end();
	}
}