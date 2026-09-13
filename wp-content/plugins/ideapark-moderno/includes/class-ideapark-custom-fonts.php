<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ideapark_Custom_Fonts {
	public function __construct() {

		add_filter( 'upload_mimes', function ( $mimes ) {
			if ( current_user_can( 'administrator' ) ) {
				$mimes['ttf']   = 'application/x-font-ttf';
				$mimes['eot']   = 'application/vnd.ms-fontobject';
				$mimes['woff']  = 'application/font-woff';
				$mimes['woff2'] = 'application/font-woff2';
				$mimes['otf']   = 'application/vnd.oasis.opendocument.formula-template';
			}

			return $mimes;
		} );

		add_filter( 'rwmb_meta_boxes', function ( $meta_boxes ) {
			$meta_boxes[] = [
				'id'     => 'ideapark_section_fonts',
				'title'  => __( 'Custom Fonts', 'ideapark-moderno' ),
				'panel'  => '',
				'fields' => [
					[
						'id'         => 'custom_fonts',
						'type'       => 'group',
						'clone'      => true,
						'sort_clone' => false,
						'fields'     => [
							[
								'name' => __( 'Name', 'ideapark-moderno' ),
								'id'   => 'name',
								'type' => 'text',
							],
							[
								'name' => __( 'Font .woff2', 'ideapark-moderno' ),
								'id'   => 'woff2',
								'type' => 'file_input',
							],
							[
								'name' => __( 'Font .woff', 'ideapark-moderno' ),
								'id'   => 'woff',
								'type' => 'file_input',
							],
							[
								'name' => __( 'Font .ttf', 'ideapark-moderno' ),
								'id'   => 'ttf',
								'type' => 'file_input',
							],
							[
								'name' => __( 'Font .svg', 'ideapark-moderno' ),
								'id'   => 'svg',
								'type' => 'file_input',
							],
							[
								'name' => __( 'Font .otf', 'ideapark-moderno' ),
								'id'   => 'otf',
								'type' => 'file_input',
							],
							[
								'name'    => __( 'Font Display', 'ideapark-moderno' ),
								'id'      => 'font_display',
								'type'    => 'select',
								'std'     => '',
								'options' => [
									''         => '',
									'auto'     => __( 'Auto', 'ideapark-moderno' ),
									'block'    => __( 'Block', 'ideapark-moderno' ),
									'swap'     => __( 'Swap', 'ideapark-moderno' ),
									'fallback' => __( 'Fallback', 'ideapark-moderno' ),
									'optional' => __( 'Optional', 'ideapark-moderno' ),
								],
							],
						],
					],
				],
			];

			return $meta_boxes;
		} );
	}
}

new Ideapark_Custom_Fonts();