(function ($) {
	"use strict";
	$(window).on('elementor/frontend/init', function () {
		window.elementorFrontend.hooks.addAction('frontend/element_ready/ideapark-slider.default', function ($scope) {
			ideapark_init_slider_carousel();
		});
		window.elementorFrontend.hooks.addAction('frontend/element_ready/ideapark-product-tabs.default', function ($scope) {
			var layout = ($scope.closest('.elementor-section-boxed').length || $scope.closest('.e-con-boxed').length) ? 'boxed' : 'full_width';
			ideapark_elementor_layout_changed($scope, layout);
			ideapark_init_product_grid_carousel();
			ideapark_init_tabs();
		});
		window.elementorFrontend.hooks.addAction('frontend/element_ready/ideapark-news-carousel.default', function ($scope) {
			ideapark_init_news_widget_carousel();
		});
		window.elementorFrontend.hooks.addAction('frontend/element_ready/ideapark-reviews.default', function ($scope) {
			ideapark_init_reviews_widget_carousel();
		});
		window.elementorFrontend.hooks.addAction('frontend/element_ready/ideapark-tabs.default', function ($scope) {
			ideapark_init_tabs();
		});
		window.elementorFrontend.hooks.addAction('frontend/element_ready/ideapark-image-list-2.default', function ($scope) {
			ideapark_init_image_list_2_carousel();
		});
		window.elementorFrontend.hooks.addAction('frontend/element_ready/ideapark-image-list-3.default', function ($scope) {
			ideapark_init_image_list_3_carousel();
		});
		window.elementorFrontend.hooks.addAction('frontend/element_ready/ideapark-countdown.default', function ($scope) {
			ideapark_init_countdown();
		});
		window.elementorFrontend.hooks.addAction('frontend/element_ready/ideapark-accordion.default', function ($scope) {
			ideapark_init_accordion();
		});
		window.elementorFrontend.hooks.addAction('frontend/element_ready/ideapark-banners.default', function ($scope) {
			ideapark_init_banners();
		});
		window.elementorFrontend.hooks.addAction('frontend/element_ready/ideapark-categories.default', function ($scope) {
			ideapark_init_subcat_carousel();
		});
		
		window.elementorFrontend.hooks.addAction('frontend/element_ready/ideapark-hotspot-carousel.default', function ($scope) {
			ideapark_init_hotspot_widget_carousel();
		});
		
		window.elementorFrontend.hooks.addAction('frontend/element_ready/ideapark-running-line.default', function ($scope) {
			ideapark_init_running_line();
		});
		if (window.elementorFrontend.isEditMode()) {
			var debounce_function = null;
			elementor.channels.editor.on('change', function (view) {
				var changed = view.container.settings.changed;
				var widget = view.container.settings.attributes.widgetType;
				var id = view.container.id;
				var $container = $('[data-id="' + id + '"]');
				var layout = '';
				
				if (view.container.settings.attributes.elType && view.container.settings.attributes.elType === 'section' && changed.layout) {
					layout = changed.layout;
				} else if (view.container.settings.attributes.elType && view.container.settings.attributes.elType === 'container' && changed.content_width) {
					layout = changed.content_width;
				} else if (view.container.settings.attributes.elType && view.container.settings.attributes.elType === 'container' && changed.boxed_width) {
					layout = changed.boxed_width;
				}
				
				if (layout) {
					ideapark_elementor_layout_changed($container, layout);
				}
				
				if (widget == 'ideapark-hotspot-carousel' && changed.items_per_row && $container.length) {
					debounce_function = debounce_function ? debounce_function : function () {
						ideapark_init_hotspot_points($('.c-ip-hotspot__image-wrap', $container));
					};
					ideapark_debounce_call();
				}
				
				if (widget == 'ideapark-image-list-2' && changed.space && $container.length) {
					debounce_function = debounce_function ? debounce_function : function () {
						var $list = $('.c-ip-image-list-2__list', $container);
						if ($list.hasClass('owl-carousel')) {
							$list.trigger('destroy.owl.carousel').removeClass('owl-carousel');
						}
						ideapark_init_image_list_2_carousel();
					};
					ideapark_debounce_call();
				}
				
				if (widget == 'ideapark-image-list-3' && (changed.items_per_row || changed.items_per_row_tablet) && $container.length) {
					debounce_function = debounce_function ? debounce_function : function () {
						var $list = $('.c-ip-image-list-3__list', $container);
						if (changed.items_per_row) {
							$list.attr('data-items-desktop', changed.items_per_row);
							$list.data('items-desktop', changed.items_per_row);
						}
						if (changed.items_per_row_tablet) {
							$list.attr( 'data-items-tablet', changed.items_per_row_tablet);
							$list.data( 'items-tablet', changed.items_per_row_tablet);
						}
						if ($list.hasClass('owl-carousel')) {
							$list.trigger('destroy.owl.carousel').removeClass('owl-carousel init');
							ideapark_init_image_list_3_carousel();
						}
					};
					ideapark_debounce_call();
				}
				
			});
			var ideapark_debounce_call = ideapark_debounce(function () {
				if (debounce_function) {
					debounce_function();
					debounce_function = null;
				}
			}, 500);
		}
	});
	function ideapark_elementor_layout_changed($container, layout) {
		$container.find('.c-product-grid__wrap').each(function () {
			var $widget = $(this);
			
			$widget.removeClass('c-product-grid__wrap--boxed c-product-grid__wrap--fullwidth');
			if (layout === 'boxed') {
				$widget.addClass('c-product-grid__wrap--boxed');
			} else {
				$widget.addClass('c-product-grid__wrap--fullwidth');
			}
			
			$widget = $(this).find('.c-product-grid__list').first();
			$widget.removeClass('c-product-grid__list--boxed c-product-grid__list--fullwidth');
			if (layout === 'boxed') {
				$widget.addClass('c-product-grid__list--boxed');
			} else {
				$widget.addClass('c-product-grid__list--fullwidth');
			}
			
			var $carousel = $widget.find('.owl-carousel');
			if ($carousel.length) {
				$carousel.trigger('destroy.owl.carousel').removeClass('owl-carousel init');
				ideapark_init_product_pairs_height();
				ideapark_init_woocommerce_widget_carousel();
				ideapark_init_carousel_combined();
			}
		});
	}
})(jQuery);
