(function ($, root, undefined) {
	"use strict";
	
	$.migrateMute = true;
	$.migrateTrace = false;
	
	$(window).on('elementor/frontend/init', function () {
		if (typeof elementorFrontend === 'undefined') {
			return;
		}
		
		if (typeof elementorFrontendConfig.experimentalFeatures !== 'undefined') {
			elementorFrontendConfig.experimentalFeatures.e_css_smooth_scroll = false;
		}
		
		elementorFrontend.on('components:init', function () {
			if (typeof elementorFrontend.utils.anchors !== 'undefined') {
				elementorFrontend.utils.anchors.setSettings('selectors.targets', '.dummy-selector');
			}
		});
	});
	
	root.ideapark_videos = [];
	root.ideapark_players = [];
	root.ideapark_env_init = false;
	root.ideapark_slick_paused = false;
	root.ideapark_is_mobile = false;
	
	root.old_windows_width = 0;
	
	var $window = $(window);
	
	var ideapark_all_is_loaded = false;
	var ideapark_mega_menu_initialized = 0;
	
	
	var ideapark_is_masonry_init = false;
	var ideapark_masonry_sidebar_object = null;
	
	var $ideapark_mobile_menu = $('.js-mobile-menu');
	var ideapark_mobile_menu_initialized = false;
	var ideapark_mobile_menu_active = false;
	var ideapark_mobile_menu_page = -1;
	var ideapark_mobile_menu_page_parent = [];
	
	var $ideapark_cart_sidebar = $('.js-cart-sidebar');
	var $ideapark_cart_sidebar_wrap = $('.js-cart-sidebar-wrap');
	var ideapark_cart_sidebar_initialized = false;
	var ideapark_cart_sidebar_active = false;
	
	var $ideapark_shop_sidebar = $('.js-shop-sidebar');
	var $ideapark_shop_sidebar_wrap = $('.js-shop-sidebar-wrap');
	var ideapark_shop_sidebar_filter_desktop = $ideapark_shop_sidebar.hasClass('c-shop-sidebar--desktop-filter');
	var ideapark_shop_sidebar_initialized = false;
	var ideapark_shop_sidebar_active = false;
	
	var ideapark_search_popup_active = false;
	var ideapark_search_popup_initialized = false;
	
	var $ideapark_store_notice_top = $('.woocommerce-store-notice--top');
	var $ideapark_advert_bar_above = $('.c-header__advert_bar--above');
	var $ideapark_header_filter = $('.js-header-filter');
	var $ideapark_page_header_filter = $('.js-page-header-filter');
	var $ideapark_desktop_sticky_row = $('.js-header-desktop');
	var $ideapark_mobile_sticky_row = $('.js-header-mobile');
	var $ideapark_header_outer_desktop = $('.c-header__outer--desktop');
	var $ideapark_header_outer_mobile = $('.c-header__outer--mobile');
	var ideapark_is_sticky_hide_desktop = $('.c-header--desktop .c-header__logo--sticky-hide').length > 0;
	var ideapark_sticky_desktop_active = false;
	var ideapark_sticky_animation = false;
	var ideapark_sticky_desktop_init = false;
	var ideapark_sticky_mobile_active = false;
	var ideapark_sticky_mobile_init = false;
	var ideapark_before_header_height = 0;
	var ideapark_header_height = 0;
	var ideapark_is_popup_active = false;
	
	var $ideapark_sticky_sidebar = $('.js-sticky-sidebar');
	var $ideapark_sticky_sidebar_nearby = $('.js-sticky-sidebar-nearby');
	var ideapark_sticky_sidebar_old_style = null;
	var ideapark_is_sticky_sidebar_inner = !!$ideapark_sticky_sidebar_nearby.find('.js-sticky-sidebar').length;
	
	var $ideapark_infinity_loader;
	var ideapark_has_loader = false;
	
	var ideapark_nav_text = ['<i class="ip-right h-carousel__prev"></i><span class="screen-reader-text">' + ideapark_wp_vars.prevText + '</span>', '<i class="ip-right h-carousel__next"></i><span class="screen-reader-text">' + ideapark_wp_vars.nextText + '</span>'];
	var ideapark_nav_text_subcat = ['<i class="ip-right-subcat h-carousel__prev"></i><span class="screen-reader-text">' + ideapark_wp_vars.prevText + '</span>', '<i class="ip-right-subcat h-carousel__next"></i><span class="screen-reader-text">' + ideapark_wp_vars.nextText + '</span>'];
	var ideapark_nav_text_def = ['<i class="ip-right-default h-carousel__prev"></i><span class="screen-reader-text">' + ideapark_wp_vars.prevText + '</span>', '<i class="ip-right-default h-carousel__next"></i><span class="screen-reader-text">' + ideapark_wp_vars.nextText + '</span>'];
	
	var ideapark_loaded_images;
	
	document.onreadystatechange = function () {
		if (document.readyState === 'complete') {
			ideapark_all_is_loaded = true;
			ideapark_init_nice_select();
			ideapark_mega_menu_init();
		}
	};
	
	$(window).on("pageshow", function (e) {
		if (e.originalEvent.persisted) {
			if (ideapark_is_mobile_layout) {
				ideapark_sidebar_popup(false);
				ideapark_cart_sidebar_popup(false);
			}
			setTimeout(function () {
				try {
					var wc_fragments = JSON.parse(sessionStorage.getItem(wc_cart_fragments_params.fragment_name));
					if (wc_fragments && wc_fragments['div.widget_shopping_cart_content']) {
						$('div.widget_shopping_cart_content').replaceWith(wc_fragments['div.widget_shopping_cart_content']);
					}
				} catch (err) {
				}
			}, 500);
			$('.js-loading').each(function () {
				$(this).ideapark_button('reset');
			});
		}
	});
	
	$(function () {
		
		$('ul.wc-tabs a').on('click', function () {
			setTimeout(ideapark_sticky_sidebar, 100);
		});
		
		$('.js-login-form-toggle').on('click', function (e) {
			var $this = $(this);
			var $active_tab = $this.closest('.c-login__form');
			var $new_tab = $('.c-login__form:not(.c-login__form--active)');
			
			e.preventDefault();
			
			$active_tab.removeClass('c-login__form--active');
			$new_tab.addClass('c-login__form--active');
		});
		
		$(".js-wishlist-share-link").on('focus', function () {
			$(this).trigger('select');
			document.execCommand('copy');
		});
		
		$(document.body)
			.on('updated_wc_div', function () {
				if ($('.woocommerce-cart-form').length === 0) {
					window.location.reload();
				}
			})
			.on('woocommerce_variation_has_changed', function(){
				ideapark_loaded_images = null;
			})
			.on('show_variation hide_variation', '.variations_form', function (e, variation) {
				var is_hide = e.type === 'hide_variation';
				var $form = $(this);
				var $product = $form.closest('.c-product__summary, .product');
				
				var $progress = $product.find('.js-progress');
				var $progress_inner, $progress_sale, $progress_available;
				if ($progress.length === 1) {
					$progress_inner = $progress.find('.js-progress-inner');
					$progress_sale = $progress.find('.js-progress-sale');
					$progress_available = $progress.find('.js-progress-available');
				}
				
				var hide_progress = true;
				var percent = '';
				var sale = '';
				var available = '';
				
				if (!is_hide) {
					if (typeof variation.stock_progress_available !== 'undefined') {
						hide_progress = false;
						percent = variation.stock_progress_percent;
						sale = variation.stock_progress_sale;
						available = variation.stock_progress_available;
					} else if (typeof variation.is_in_stock !== 'undefined' && variation.is_in_stock) {
						hide_progress = false;
						percent = $progress.data('percent');
						sale = $progress.data('sale');
						available = $progress.data('available');
					}
				}
				
				if (hide_progress) {
					if ($progress.data('hidden') === 'no' && is_hide) {
						hide_progress = false;
						percent = $progress.data('percent');
						sale = $progress.data('sale');
						available = $progress.data('available');
					}
				}
				
				if ($progress.length === 1) {
					if (hide_progress) {
						$progress.addClass('h-hidden');
					} else {
						$progress.removeClass('h-hidden');
						$progress_inner.css({width: percent + '%'});
						$progress_sale.text(sale);
						$progress_available.text(available);
					}
				}
			})
			.one('click', '.js-search-button,.js-mobile-menu-open,.js-filter-show-button,.js-cart-sidebar-open,.js-accordion-title', function (e) {
				e.preventDefault();
				if (!ideapark_defer_action_done()) {
					var $this = $(this);
					$(document).one('ideapark.defer.done', function () {
						$this.trigger('click');
					});
					ideapark_defer_action_run();
				}
			})
			.on('keydown', function (e) {
				if (e.keyCode === 27) {
					$('button.js-callback-close').trigger('click');
					$('button.js-search-close').trigger('click');
					$('button.js-filter-close-button').trigger('click');
				}
				
				if (e.keyCode === 37 || e.keyCode === 39) {
					var $carousel = $('.js-single-product-carousel.owl-loaded');
					if ($carousel.length === 1) {
						if (e.keyCode === 37) { // prev
							$carousel.trigger('prev.owl.carousel');
						} else if (e.keyCode === 39) { // next
							$carousel.trigger('next.owl.carousel');
						}
					}
					var $nav_prev = $('.c-post__nav-prev');
					if ($nav_prev.length && e.keyCode === 37) {
						document.location.href = $nav_prev.attr('href');
					}
					var $nav_next = $('.c-post__nav-next');
					if ($nav_next.length && e.keyCode === 39) {
						document.location.href = $nav_next.attr('href');
					}
				}
			})
			.on('click', '.h-link-yes', function (e) {
				e.preventDefault();
				var $scope = $(this);
				if ($scope.data('ip-url') && $scope.data('ip-link') == 'yes') {
					if ($scope.data('ip-new-window') == 'yes') {
						window.open($scope.data('ip-url'));
					} else {
						location.href = $scope.data('ip-url');
					}
				}
			})
			.on('click', ".js-mobile-modal", function (e) {
				$(this).parent().find(".js-product-modal").first().trigger('click');
			})
			.on('click', ".js-product-modal", function (e) {
				e.preventDefault();
				$('.c-product__gallery .c-inline-video').each(function () {
					$(this)[0].pause();
				});
				ideapark_grid_video_start(true);
				var $button = $(this);
				var $play_button = $('.c-play', $button);
				var $button_loading = $play_button.length ? $play_button : $('.js-loading-wrap', $button);
				if ($button_loading.hasClass('js-loading')) {
					return;
				}
				var index = 0;
				
				if (typeof PhotoSwipe === 'function' && ideapark_loaded_images) {
					if (ideapark_isset($button.data('index'))) {
						index = $button.data('index');
						ideapark_lightbox(ideapark_loaded_images, index);
					}
					return;
				}
				
				var $parent = $button_loading.parent();
				$parent.addClass('loading');
				if (ideapark_isset($button.data('index'))) {
					$button_loading.ideapark_button('loading', 25);
					index = $button.data('index');
				} else {
					$button_loading.ideapark_button('loading');
				}
				var $product = $button.closest('.product');
				var variation_id = $product.find('.c-product__summary .variation_id').val();
				
				ideapark_require([ideapark_wp_vars.themeUri + '/assets/js/photoswipe/photoswipe.min.js?v=' + ideapark_wp_vars.scriptsHash, ideapark_wp_vars.themeUri + '/assets/js/photoswipe/photoswipe-ui-default.min.js?v=' + ideapark_wp_vars.scriptsHash, ideapark_wp_vars.ajaxUrl + '?action=ideapark_product_images&index=' + index + '&product_id=' + $button.data('product-id') + (!ideapark_empty(variation_id) ? '&variation_id=' + variation_id : ''), ideapark_wp_vars.themeUri + '/assets/css/photoswipe/photoswipe.css', ideapark_wp_vars.themeUri + '/assets/css/photoswipe/default-skin/default-skin.css'], function (values) {
					var images = values[2];
					ideapark_loaded_images = images;
					$button_loading.ideapark_button('reset');
					$parent.removeClass('loading');
					ideapark_lightbox(images, index);
				});
			})
			.on('click', ".js-video", function (e) {
				e.preventDefault();
				ideapark_init_venobox($(this));
			})
			.on('click', ".js-ajax-search-all", function (e) {
				$('.js-search-form').submit();
			})
			.on('click', '.js-load-more', function (e) {
				ideapark_infinity_loader($(this), e);
			})
			.on('wc_cart_button_updated', function (e, $button) {
				var $view_cart_button = $button.parent().find('.added_to_cart');
				$view_cart_button.addClass('c-product-grid__atc');
			})
			.on('click', '.js-notice-close', function (e) {
				e.preventDefault();
				var $notice = $(this).closest('.woocommerce-notice');
				$notice.animate({
					opacity: 0,
				}, 500, function () {
					$notice.remove();
				});
			})
			.on('adding_to_cart', function (e, $button) {
				$button.ideapark_button('loading', 16);
			})
			.on('added_to_cart', function (e, fragments, cart_hash, $button) {
				if (ideapark_is_mobile_layout && ideapark_wp_vars.popupCartOpenMobile || !ideapark_is_mobile_layout && ideapark_wp_vars.popupCartOpenDesktop) {
					ideapark_cart_sidebar_popup(true);
				} else {
					if (typeof fragments.ideapark_notice !== 'undefined') {
						ideapark_show_notice(fragments.ideapark_notice);
					}
				}
			})
			.on('wc_fragments_loaded wc_fragment_refresh wc_fragments_refreshed', function (e) {
				if (ideapark_masonry_sidebar_object) {
					ideapark_masonry_sidebar_object.layout();
				}
			})
			.on('click', ".js-quantity-minus", function (e) {
				e.preventDefault();
				var $input = $(this).parent().find('input[type=number]');
				var quantity = parseInt($input.val().trim(), 10) || 0;
				var min = typeof $input.attr('min') !== 'undefined' ? parseInt($input.attr('min'), 10) : 1;
				var step = parseInt($input.attr('step'), 10) || 1;
				quantity -= step;
				quantity = Math.max(quantity, min);
				$input.val(quantity);
				$input.trigger('change');
				
			})
			.on('click', ".js-quantity-plus", function (e) {
				e.preventDefault();
				var $input = $(this).parent().find('input[type=number]');
				var quantity = parseInt($input.val().trim(), 10) || 0;
				var max = parseInt($input.attr('max'), 10);
				var step = parseInt($input.attr('step'), 10) || 1;
				quantity += step;
				if (max) {
					quantity = Math.min(quantity, max);
				}
				if (quantity > 0) {
					$input.val(quantity);
					$input.trigger('change');
				}
			})
			.on('click', '.js-cart-coupon', function (e) {
				e.preventDefault();
				var $coupon = $(".c-cart__coupon-from-wrap");
				$coupon.toggleClass('c-cart__coupon-from-wrap--opened');
				$('.c-cart__select-icon').toggleClass('c-cart__select-icon--opened');
				if ($coupon.hasClass('c-cart__coupon-from-wrap--opened')) {
					setTimeout(function () {
						$coupon.find('input[type=text]').first().trigger('focus');
					}, 500);
				}
				return false;
			})
			.on('checkout_error updated_checkout applied_coupon removed_coupon updated_wc_div', function (e) {
				ideapark_search_notice();
			})
			.on('keypress', "#coupon_code", function (e) {
				if (e.which == 13) {
					$('#ip-checkout-apply-coupon').trigger('click');
				}
			})
			.on('click', "#ip-checkout-apply-coupon", function () {
				
				var params = null;
				var is_cart = false;
				
				if (typeof wc_checkout_params != 'undefined') {
					params = wc_checkout_params;
					is_cart = false;
				}
				
				if (typeof wc_cart_params != 'undefined') {
					params = wc_cart_params;
					is_cart = true;
				}
				
				if (!params) {
					return false;
				}
				
				var $collaterals = $(this).closest('.c-cart__collaterals');
				
				if ($collaterals.is('.processing')) {
					return false;
				}
				
				$collaterals.addClass('processing').block({
					message: null, overlayCSS: {
						background: '#fff', opacity: 0.6
					}
				});
				
				var data = {
					security: params.apply_coupon_nonce, coupon_code: $collaterals.find('input[name="coupon_code"]').val()
				};
				
				$.ajax({
					type       : 'POST', url: params.wc_ajax_url.toString().replace('%%endpoint%%', 'apply_coupon'), data: data, success: function (code) {
						if (code) {
							ideapark_show_notice(code);
							if (is_cart) {
								$.ajax({
									url        : params.wc_ajax_url.toString().replace('%%endpoint%%', 'get_cart_totals'), dataType: 'html', success: function (response) {
										$collaterals.html(response);
									}, complete: function () {
										$collaterals.removeClass('processing').unblock();
									}
								});
								$('.c-cart__shop-update-button').prop('disabled', false).trigger('click');
							} else {
								$collaterals.removeClass('processing').unblock();
								$(document.body).trigger('update_checkout', {update_shipping_method: false});
							}
						}
					}, dataType: 'html'
				});
				
				return false;
			})
			.on('click', 'a.woocommerce-review-link', function (e) {
				e.preventDefault();
				var $quickview_container = $(this).closest('.c-product--quick-view');
				if ($quickview_container.length) {
					var product_url = $quickview_container.find('.woocommerce-LoopProduct-link').first().attr('href') + '#reviews';
					document.location.href = product_url;
					return false;
				} else {
					setTimeout(function () {
						ideapark_hash_menu_animate(e);
					}, 500);
				}
			})
			.on('click', '.woocommerce-store-notice__dismiss-link', function () {
				setTimeout(function () {
					$(document).trigger('ideapark.wpadminbar.scroll', ideapark_adminbar_visible_height);
				}, 100);
			})
			.on('click', '.js-grid-zoom', function () {
				var $ideapark_quickview_container = $('.js-quickview-container');
				var $ideapark_quickview_popup = $('.js-quickview-popup');
				$ideapark_quickview_popup.one('ip-close', function () {
					$ideapark_quickview_container.html('');
				});
				var $button = $(this), ajax_url, product_id = $button.data('product-id'), data = {
					product_id: product_id, lang: $button.data('lang')
				};
				if (product_id) {
					ajax_url = ideapark_wp_vars.ajaxUrl;
					data.action = 'ideapark_ajax_product';
					
					$.ajax({
						type      : 'POST', url: ajax_url, data: data, dataType: 'html', cache: false, headers: {'cache-control': 'no-cache'}, beforeSend: function () {
							$button.ideapark_button('loading', 16, true);
						}, success: function (data) {
							$ideapark_quickview_container.html(data);
							var $currentContainer = $ideapark_quickview_popup.find('#product-' + product_id);
							if ($currentContainer.hasClass('product-type-variable')) {
								var $productForm = $currentContainer.find('form.cart');
								$productForm.wc_variation_form().find('.variations select:eq(0)').trigger('change');
							}
							ideapark_init_product_carousel();
							if (typeof IP_Wishlist !== 'undefined') {
								IP_Wishlist.init_product_button();
							}
							$ideapark_quickview_popup.trigger('ip-open');
							$button.ideapark_button('reset');
							$('.c-play--disabled').removeClass('c-play--disabled');
							ideapark_init_zoom();
							ideapark_init_ajax_add_to_cart();
							ideapark_init_attribute_hint_popup();
						}
					});
				}
			});
		
		ideapark_wpadminbar_resize();
		ideapark_scroll_actions();
		ideapark_resize_actions();
		ideapark_init_notice();
		ideapark_init_masonry();
		ideapark_init_subcat_carousel();
		ideapark_init_ajax_add_to_cart();
		ideapark_init_callback_popup();
		ideapark_init_zoom();
		ideapark_init_product_carousel();
		ideapark_init_product_thumbs_carousel();
		ideapark_init_anchor_smooth_scrolling();
		
		ideapark_resize_action_layout_add(function () {
			ideapark_search_popup(false);
			ideapark_mobile_menu_popup(false);
			ideapark_init_mobile_menu();
			ideapark_init_shop_sidebar();
			ideapark_init_cart_sidebar();
			ideapark_mega_menu_init();
			ideapark_init_zoom();
		});
		
		ideapark_resize_action_500_add(ideapark_defer_action_run);
		
		ideapark_defer_action_add(function () {
			if (typeof ideapark_redirect_url !== 'undefined' && ideapark_redirect_url) {
				location.href = ideapark_redirect_url;
				return;
			}
			ideapark_init_recently();
			ideapark_init_search();
			ideapark_responsive_body_class();
			ideapark_init_top_menu();
			ideapark_init_mobile_menu();
			ideapark_header_sticky_init();
			ideapark_header_sticky();
			ideapark_init_shop_sidebar();
			ideapark_init_cart_sidebar();
			ideapark_init_post_image_carousel();
			ideapark_init_product_grid_carousel();
			ideapark_init_product_combined();
			ideapark_init_product_tabs();
			ideapark_init_attribute_hint_popup();
			ideapark_init_review_placeholder();
			ideapark_to_top_button_init();
			ideapark_grid_color_var_init();
			ideapark_init_filter_expand();
			ideapark_init_cart_auto_update();
			ideapark_sticky_sidebar();
			ideapark_grid_video_start();
			
			ideapark_scroll_action_add(function () {
				ideapark_sticky_sidebar();
				ideapark_header_sticky();
				ideapark_infinity_loading();
				ideapark_grid_video_start_debounce();
			});
			
			ideapark_resize_action_500_add(function () {
				ideapark_calc_header_element_height();
				ideapark_header_sticky_init();
				ideapark_header_sticky();
				ideapark_init_subcat_carousel();
				ideapark_responsive_body_class();
				ideapark_init_product_carousel();
				ideapark_init_nice_select();
				ideapark_sticky_sidebar();
				
				if (ideapark_is_mobile_layout) {
					$('.c-product-grid__thumb-wrap--hover').removeClass('c-product-grid__thumb-wrap--hover');
				}
			});
			
			$ideapark_infinity_loader = $('.js-load-infinity');
			ideapark_has_loader = $ideapark_infinity_loader.length || $('.js-load-more').length;
			$('.c-play--disabled').removeClass('c-play--disabled');
			$('.entry-content').fitVids();
			$ideapark_mobile_sticky_row.addClass('init');
			$(document.body)
				.trigger('ideapark.wpadminbar.scroll', ideapark_adminbar_visible_height)
				.trigger('theme_init');
		});
		
		if (!ideapark_wp_vars.jsDelay || ideapark_wp_vars.elementorPreview || ($window.width() >= 768 && $window.width() <= 1189)) {
			ideapark_defer_action_run();
		}
		
		$(document)
			.on('ideapark.wpadminbar.scroll ideapark.sticky ideapark.sticky.late', ideapark_set_notice_offset)
			.trigger('ideapark.wpadminbar.scroll', ideapark_adminbar_visible_height);
		
		$('body.h-preload').removeClass('h-preload');
	});
	
	root.ideapark_responsive_body_class = function () {
		var w = $window.width();
		var $body = $('body');
		if (w < 768 && !$body.hasClass('h-screen-mobile')) {
			$body.removeClass('h-screen-desktop h-screen-tablet h-screen-not-mobile').addClass('h-screen-mobile');
		} else if (w >= 768 && w < 1190 && !$body.hasClass('h-screen-tablet')) {
			$body.removeClass('h-screen-desktop h-screen-mobile').addClass('h-screen-tablet h-screen-not-mobile');
		} else if (w >= 1190 && !$body.hasClass('h-screen-desktop')) {
			$body.removeClass('h-screen-mobile h-screen-tablet').addClass('h-screen-desktop h-screen-not-mobile');
		}
	};
	
	root.ideapark_search_popup = function (show) {
		if (show && !ideapark_search_popup_active) {
			ideapark_mobile_menu_popup(false);
			ideapark_search_popup_active = true;
			$('.c-header-search').addClass('c-header-search--active');
		} else if (ideapark_search_popup_active) {
			ideapark_search_popup_active = false;
			$('.c-header-search').removeClass('c-header-search--active');
		}
	};
	
	root.ideapark_init_top_menu = function () {
		var $ideapark_top_menu = $('.js-top-menu');
		
		if ($ideapark_top_menu.length) {
			$ideapark_top_menu.find('.c-top-menu__subitem--has-children').each(function () {
				var $li = $(this);
				if ($li.find('ul').length) {
					$li.append('<i class="ip-menu-right c-top-menu__more-svg"></i>');
				} else {
					$li.removeClass('c-top-menu__subitem--has-children');
				}
			});
		}
	};
	
	root.ideapark_calc_header_element_height = function () {
		if (!ideapark_is_mobile_layout && ideapark_wp_vars.stickyMenuDesktop) {
			$ideapark_desktop_sticky_row.addClass('c-header--origin');
		}
		if (ideapark_is_mobile_layout && ideapark_wp_vars.stickyMenuMobile) {
			$ideapark_mobile_sticky_row.addClass('c-header--origin');
		}
		ideapark_before_header_height = ($ideapark_advert_bar_above.length ? $ideapark_advert_bar_above.outerHeight() : 0) + ($ideapark_store_notice_top.length && $ideapark_store_notice_top.css('display') !== 'none' ? $ideapark_store_notice_top.outerHeight() : 0);
		ideapark_header_height = ideapark_is_mobile_layout ? $ideapark_mobile_sticky_row.outerHeight() : $ideapark_desktop_sticky_row.outerHeight();
		if (!ideapark_is_mobile_layout && ideapark_wp_vars.stickyMenuDesktop) {
			$ideapark_desktop_sticky_row.removeClass('c-header--origin');
		}
		if (ideapark_is_mobile_layout && ideapark_wp_vars.stickyMenuMobile) {
			$ideapark_mobile_sticky_row.removeClass('c-header--origin');
		}
	};
	
	root.ideapark_header_sticky_init = function () {
		
		// Desktop
		if (!ideapark_is_mobile_layout && ideapark_wp_vars.stickyMenuDesktop) {
			ideapark_calc_header_element_height();
			if (!$ideapark_header_outer_desktop.hasClass('c-header__outer--tr')) {
				$ideapark_header_outer_desktop.css({'min-height': ideapark_header_height + 'px'});
			}
			if (!ideapark_sticky_desktop_init) {
				$ideapark_desktop_sticky_row.addClass('c-header--init');
				ideapark_sticky_desktop_active = false;
				ideapark_sticky_desktop_init = true;
			}
		}
		
		// Mobile
		if (!ideapark_sticky_mobile_init && ideapark_is_mobile_layout && ideapark_wp_vars.stickyMenuMobile) {
			ideapark_calc_header_element_height();
			if (!$ideapark_header_outer_mobile.hasClass('c-header__outer--tr')) {
				$ideapark_header_outer_mobile.css({'min-height': ideapark_header_height + 'px'});
			}
			if (!ideapark_sticky_mobile_init) {
				$ideapark_mobile_sticky_row.addClass('c-header--init');
				ideapark_sticky_mobile_active = false;
				ideapark_sticky_mobile_init = true;
			}
		}
		
		$(document).off('ideapark.wpadminbar.scroll', ideapark_header_sticky);
		$(document).on('ideapark.wpadminbar.scroll', ideapark_header_sticky);
	};
	
	root.ideapark_header_sticky = function () {
		if (ideapark_sticky_animation) {
			return;
		}
		var sticky_height = ideapark_is_mobile_layout ? $ideapark_mobile_sticky_row.outerHeight() : $ideapark_desktop_sticky_row.outerHeight(),
			before = ideapark_before_header_height + (ideapark_adminbar_position === 'fixed' ? 0 : ideapark_adminbar_height),
			is_transparent = $ideapark_desktop_sticky_row.hasClass('c-header--tr'),
			is_sticky_area = window.scrollY > before + (is_transparent ? sticky_height * 2 : (ideapark_is_sticky_hide_desktop ? sticky_height : 0));
		
		if (ideapark_sticky_desktop_init && !ideapark_is_mobile_layout) {
			if (ideapark_sticky_desktop_active) {
				if (!is_sticky_area) {
					if (is_transparent) {
						ideapark_sticky_animation = true;
						$ideapark_desktop_sticky_row.animate({
							top: '-' + (sticky_height + ideapark_adminbar_height) + 'px'
						}, 200, function () {
							$ideapark_desktop_sticky_row.css({
								top: '0'
							});
							$ideapark_desktop_sticky_row.removeClass('c-header--sticky');
							if (ideapark_wp_vars.stickyFilter) {
								$ideapark_header_filter.removeClass('c-header__filter--active');
								$ideapark_page_header_filter.removeClass('c-page-header__filter--hidden');
							}
							ideapark_sticky_animation = false;
							ideapark_header_sticky();
						});
					} else {
						$ideapark_desktop_sticky_row.css({
							top: '0'
						});
						$ideapark_desktop_sticky_row.removeClass('c-header--sticky');
						if (ideapark_wp_vars.stickyFilter) {
							$ideapark_header_filter.removeClass('c-header__filter--active');
							$ideapark_page_header_filter.removeClass('c-page-header__filter--hidden');
						}
					}
					ideapark_sticky_desktop_active = false;
					$(document).trigger('ideapark.sticky');
					setTimeout(function () {
						$(document).trigger('ideapark.sticky.late');
					}, 600);
				}
			} else {
				if (is_sticky_area) {
					if (window.scrollY - (before + sticky_height) > 0 || is_transparent) {
						$ideapark_desktop_sticky_row.css({
							top: '-' + (sticky_height + ideapark_adminbar_height) + 'px'
						});
						$ideapark_desktop_sticky_row.addClass('c-header--sticky');
						if (ideapark_wp_vars.stickyFilter) {
							$ideapark_header_filter.addClass('c-header__filter--active');
							$ideapark_page_header_filter.addClass('c-page-header__filter--hidden');
						}
						
						ideapark_sticky_animation = true;
						$ideapark_desktop_sticky_row.animate({
							top: (ideapark_adminbar_position === 'fixed' ? ideapark_adminbar_height : 0) + 'px'
						}, 500, function () {
							ideapark_sticky_animation = false;
							ideapark_header_sticky();
						});
					} else {
						$ideapark_desktop_sticky_row.addClass('c-header--sticky');
						if (ideapark_wp_vars.stickyFilter) {
							$ideapark_header_filter.addClass('c-header__filter--active');
							$ideapark_page_header_filter.addClass('c-page-header__filter--hidden');
						}
						
						$ideapark_desktop_sticky_row.css({
							top: (ideapark_adminbar_position === 'fixed' ? ideapark_adminbar_height : 0) + 'px'
						});
					}
					ideapark_sticky_desktop_active = true;
					$(document).trigger('ideapark.sticky');
					setTimeout(function () {
						$(document).trigger('ideapark.sticky.late');
					}, 600);
				}
			}
		}
		if (ideapark_sticky_mobile_init && ideapark_is_mobile_layout) {
			if (ideapark_sticky_mobile_active) {
				if (!is_sticky_area) {
					if (is_transparent) {
						ideapark_sticky_animation = true;
						$ideapark_mobile_sticky_row.animate({
							top: '-' + (sticky_height + ideapark_adminbar_height) + 'px'
						}, 200, function () {
							$ideapark_mobile_sticky_row.css({
								top: '0'
							});
							$ideapark_mobile_sticky_row.removeClass('c-header--sticky');
							if (ideapark_wp_vars.stickyFilter) {
								$ideapark_header_filter.removeClass('c-header__filter--active');
								$ideapark_page_header_filter.removeClass('c-page-header__filter--hidden');
							}
							
							ideapark_sticky_animation = false;
							ideapark_header_sticky();
						});
					} else {
						$ideapark_mobile_sticky_row.css({
							top: '0'
						});
						$ideapark_mobile_sticky_row.removeClass('c-header--sticky');
						if (ideapark_wp_vars.stickyFilter) {
							$ideapark_header_filter.removeClass('c-header__filter--active');
							$ideapark_page_header_filter.removeClass('c-page-header__filter--hidden');
						}
					}
					ideapark_sticky_mobile_active = false;
					$(document).trigger('ideapark.sticky');
					setTimeout(function () {
						$(document).trigger('ideapark.sticky.late');
					}, 600);
				}
			} else {
				if (is_sticky_area) {
					if (window.scrollY - (before + sticky_height) > 0 || is_transparent) {
						$ideapark_mobile_sticky_row.css({
							top: '-' + (sticky_height + ideapark_adminbar_height) + 'px'
						});
						$ideapark_mobile_sticky_row.addClass('c-header--sticky');
						if (ideapark_wp_vars.stickyFilter) {
							$ideapark_header_filter.addClass('c-header__filter--active');
							$ideapark_page_header_filter.addClass('c-page-header__filter--hidden');
						}
						
						ideapark_sticky_animation = true;
						$ideapark_mobile_sticky_row.animate({
							top: (ideapark_adminbar_position === 'fixed' ? ideapark_adminbar_height : 0) + 'px'
						}, 500, function () {
							ideapark_sticky_animation = false;
							ideapark_header_sticky();
						});
					} else {
						$ideapark_mobile_sticky_row.addClass('c-header--sticky');
						if (ideapark_wp_vars.stickyFilter) {
							$ideapark_header_filter.addClass('c-header__filter--active');
							$ideapark_page_header_filter.addClass('c-page-header__filter--hidden');
						}
						$ideapark_mobile_sticky_row.css({
							top: (ideapark_adminbar_position === 'fixed' ? ideapark_adminbar_height : 0) + 'px'
						});
					}
					ideapark_sticky_mobile_active = true;
					$(document).trigger('ideapark.sticky');
					setTimeout(function () {
						$(document).trigger('ideapark.sticky.late');
					}, 600);
				}
			}
		}
	};
	
	root.ideapark_init_search = function () {
		if (ideapark_search_popup_initialized) {
			return;
		}
		ideapark_search_popup_initialized = true;
		
		$('.js-ajax-search').each(function () {
			var $ideapark_search = $(this);
			var $ideapark_search_form = $('.js-search-form', $ideapark_search);
			var $ideapark_search_result = $('.js-ajax-search-result', $ideapark_search);
			var $ideapark_search_input = $('.js-ajax-search-input', $ideapark_search);
			var $ideapark_search_clear = $('.js-search-clear', $ideapark_search);
			var $ideapark_search_loader = $('<i class="h-loading c-header-search__loading"></i>');
			var ideapark_search_input_filled = false;
			var ajaxSearchFunction = $ideapark_search_input.hasClass('no-ajax') ? function () {
			} : ideapark_debounce(function () {
				var search = $ideapark_search_input.val().trim();
				if (ideapark_empty(search)) {
					$ideapark_search_result.html('');
				} else {
					$ideapark_search_loader.insertBefore($ideapark_search_input);
					$.ajax({
						url       : ideapark_wp_vars.ajaxUrl, type: 'POST', data: {
							action: 'ideapark_ajax_search', s: search, lang: ideapark_wp_vars.locale
						}, success: function (results) {
							$ideapark_search_loader.remove();
							$ideapark_search_result.html((ideapark_empty($ideapark_search_input.val().trim())) ? '' : results);
						}
					});
				}
			}, 500);
			
			$ideapark_search_input.on('keydown', function (e) {
				var $this = $(this);
				var is_not_empty = !ideapark_empty($this.val().trim());
				
				if (e.keyCode == 13) {
					e.preventDefault();
					if ($this.hasClass('no-ajax') && is_not_empty) {
						$this.closest('form').submit();
					}
				} else if (e.keyCode == 27) {
					ideapark_search_popup(false);
				}
			}).on('input', function () {
				var $this = $(this);
				var $form = $this.closest('.js-search-form');
				var is_not_empty = !ideapark_empty($this.val().trim());
				
				if (is_not_empty && !ideapark_search_input_filled) {
					ideapark_search_input_filled = true;
					$('.js-search-clear', $form).addClass('active');
					
				} else if (!is_not_empty && ideapark_search_input_filled) {
					ideapark_search_input_filled = false;
					$('.js-search-clear', $form).removeClass('active');
				}
				ajaxSearchFunction();
			});
			
			$ideapark_search_clear.on('click', function () {
				$ideapark_search_input.val('').trigger('input').trigger('focus');
			});
			
			$ideapark_search.removeClass('disabled');
		});
		
		$('.js-search-to-top').on('click', function () {
			$('html, body').animate({scrollTop: 0}, 800, function () {
				$('.c-header__search-input').trigger('focus');
			});
		});
		
		$('.js-search-button').on('click', function () {
			ideapark_search_popup(true);
			setTimeout(function () {
				$('.c-header-search__input').trigger('focus');
			}, 500);
		});
		
		$('.js-search-close').on('click', function () {
			if (ideapark_search_popup_active) {
				ideapark_on_transition_end_callback($('.c-header-search'), function () {
					$('.c-header-search__input').val('').trigger('input').trigger('focus');
				});
				ideapark_search_popup(false);
			}
		});
		
		$(document).on('ideapark.wpadminbar.scroll', function (event, wpadminbar_height) {
			$('.c-header-search').css({
				transform: 'translateY(' + wpadminbar_height + 'px)', 'max-height': 'calc(100% - ' + wpadminbar_height + 'px)'
			});
		});
	};
	
	root.ideapark_mobile_menu_popup = function (show) {
		if (ideapark_mobile_menu_initialized) {
			if (show && !ideapark_mobile_menu_active) {
				ideapark_mobile_menu_active = true;
				$ideapark_mobile_menu.addClass('c-header__menu--active');
			} else if (ideapark_mobile_menu_active) {
				ideapark_mobile_menu_active = false;
				$ideapark_mobile_menu.removeClass('c-header__menu--active');
			}
		}
	};
	
	root.ideapark_init_mobile_menu = function () {
		if (ideapark_is_mobile_layout && !ideapark_mobile_menu_initialized && $ideapark_mobile_menu.length) {
			ideapark_mobile_menu_initialized = true;
			
			var $wrap = $('.js-mobile-menu-wrap');
			var $back = $('.js-mobile-menu-back');
			var action_lock = false;
			var ideapark_mobile_menu_init_page = function (page, $ul) {
				var $page = $('<div class="c-header__menu-page js-menu-page" data-page="' + page + '"></div>');
				ideapark_mobile_menu_page_parent[page] = $ul.parent();
				var $ul_new = $ul.detach();
				if (!page) {
					var $blocks = $('.js-mobile-blocks');
					if ($blocks.length) {
						var $li_space = $('<li class="c-mobile-menu__item-space"></li>');
						var $li = $('<li class="c-mobile-menu__item c-mobile-menu__item--blocks"></li>');
						$blocks.detach().removeClass('js-mobile-blocks').appendTo($li);
						$li_space.appendTo($ul_new);
						$li.appendTo($ul_new);
					}
				}
				$ul_new.appendTo($page);
				$page.appendTo($wrap);
			};
			
			$(document).on('ideapark.wpadminbar.scroll', function (event, wpadminbar_height) {
				$ideapark_mobile_menu.css({
					transform: 'translateY(' + wpadminbar_height + 'px)', 'max-height': 'calc(100% - ' + wpadminbar_height + 'px)'
				});
			});
			
			$ideapark_mobile_menu.find('.c-mobile-menu__item--has-children, .c-mobile-menu__subitem--has-children').each(function () {
				var $li = $(this);
				var $a = $li.children('a').first();
				var $ul_submenu = $li.children('.c-mobile-menu__submenu').first();
				if ($a.length && $ul_submenu.length) {
					if ($a.attr('href') != '#' && $a.attr('href')) {
						var $li_new = $ul_submenu.prop("tagName") == 'UL' ? $('<li class="c-mobile-menu__subitem c-mobile-menu__subitem--parent"></li>') : $('<div class="c-mobile-menu__subitem c-mobile-menu__subitem--parent c-mobile-menu__subitem--parent-div"></div>');
						$a.clone().appendTo($li_new);
						$ul_submenu.prepend($li_new);
					}
				}
			});
			
			$(document.body).on('click', '.c-mobile-menu__item--has-children > a:first-child, .c-mobile-menu__subitem--has-children > a:first-child, .c-mobile-menu__item--has-children > .a:first-child, .c-mobile-menu__subitem--has-children > .a:first-child', function (e) {
				e.preventDefault();
				if (action_lock) {
					return;
				}
				action_lock = true;
				var $submenu = $(this).closest('li').children('.c-mobile-menu__submenu');
				ideapark_mobile_menu_page++;
				ideapark_mobile_menu_init_page(ideapark_mobile_menu_page, $submenu);
				ideapark_on_transition_end_callback($wrap, function () {
					action_lock = false;
				});
				$wrap.addClass('c-header__menu-wrap--page-' + ideapark_mobile_menu_page);
				$back.addClass('c-header__menu-back--active');
			});
			
			$back.on('click', function () {
				if (action_lock || ideapark_mobile_menu_page <= 0) {
					return;
				}
				action_lock = true;
				ideapark_on_transition_end_callback($wrap, function () {
					var $page = $('.js-menu-page[data-page="' + ideapark_mobile_menu_page + '"]');
					var $ul = $page.find(">:first-child");
					$ul.detach().appendTo(ideapark_mobile_menu_page_parent[ideapark_mobile_menu_page]);
					$page.remove();
					ideapark_mobile_menu_page--;
					if (!ideapark_mobile_menu_page) {
						$back.removeClass('c-header__menu-back--active');
					}
					action_lock = false;
				});
				$wrap.removeClass('c-header__menu-wrap--page-' + ideapark_mobile_menu_page);
			});
			
			$('.js-mobile-menu-open').on('click', function () {
				if (ideapark_mobile_menu_page === -1) {
					ideapark_mobile_menu_page = 0;
					ideapark_mobile_menu_init_page(ideapark_mobile_menu_page, $('.c-mobile-menu__list'));
				}
				ideapark_mobile_menu_popup(true);
			});
			
			$('.js-mobile-menu-close').on('click', function () {
				ideapark_mobile_menu_popup(false);
			});
		}
	};
	
	root.ideapark_sidebar_popup = function (show) {
		if (ideapark_shop_sidebar_initialized) {
			if (show && !ideapark_shop_sidebar_active) {
				ideapark_shop_sidebar_active = true;
				$ideapark_shop_sidebar.addClass('c-shop-sidebar--active');
				$ideapark_shop_sidebar_wrap.addClass('c-shop-sidebar__wrap--active');
				$('body').addClass('filter-open');
			} else if (ideapark_shop_sidebar_active) {
				ideapark_shop_sidebar_active = false;
				$ideapark_shop_sidebar.removeClass('c-shop-sidebar--active');
				$ideapark_shop_sidebar_wrap.removeClass('c-shop-sidebar__wrap--active');
				$('body').removeClass('filter-open');
			}
		}
	};
	
	root.ideapark_init_shop_sidebar = function () {
		if ((ideapark_is_mobile_layout || !ideapark_is_mobile_layout && ideapark_shop_sidebar_filter_desktop) && !ideapark_shop_sidebar_initialized && $ideapark_shop_sidebar.length) {
			ideapark_shop_sidebar_initialized = true;
			
			$(document).on('ideapark.wpadminbar.scroll', function (event, wpadminbar_height) {
				if (ideapark_is_mobile_layout || ideapark_shop_sidebar_filter_desktop) {
					$ideapark_shop_sidebar.css({
						transform: 'translateY(' + wpadminbar_height + 'px)', 'max-height': 'calc(100% - ' + wpadminbar_height + 'px)'
					});
				} else {
					$ideapark_shop_sidebar.css({
						transform: '', 'max-height': ''
					});
				}
			});
			$('.js-filter-show-button').on('click', function () {
				ideapark_sidebar_popup(true);
			});
			
			$('.js-filter-close-button').on('click', function () {
				ideapark_sidebar_popup(false);
			});
			
			$('.js-filter-shadow').on('click', function () {
				ideapark_sidebar_popup(false);
			});
		}
	};
	
	root.ideapark_cart_sidebar_popup = function (show) {
		if (ideapark_cart_sidebar_initialized) {
			if (show && !ideapark_cart_sidebar_active) {
				ideapark_cart_sidebar_active = true;
				$ideapark_cart_sidebar.addClass('c-shop-sidebar--active');
				$ideapark_cart_sidebar_wrap.addClass('c-shop-sidebar__wrap--active');
			} else if (!show && ideapark_cart_sidebar_active) {
				ideapark_cart_sidebar_active = false;
				$ideapark_cart_sidebar.removeClass('c-shop-sidebar--active');
				$ideapark_cart_sidebar_wrap.removeClass('c-shop-sidebar__wrap--active');
			}
		}
	};
	
	root.ideapark_init_cart_sidebar = function () {
		
		if ((ideapark_is_mobile_layout || ideapark_wp_vars.popupCartLayout === 'sidebar') && !ideapark_cart_sidebar_initialized && $ideapark_cart_sidebar.length) {
			ideapark_cart_sidebar_initialized = true;
			
			$(document).on('ideapark.wpadminbar.scroll', function (event, wpadminbar_height) {
				if (ideapark_is_mobile_layout || ideapark_wp_vars.popupCartLayout === 'sidebar') {
					$ideapark_cart_sidebar.css({
						transform: 'translateY(' + wpadminbar_height + 'px)', 'max-height': 'calc(100% - ' + wpadminbar_height + 'px)'
					});
				}
			});
			$('.js-cart-sidebar-open').on('click', function (e) {
				e.preventDefault();
				ideapark_cart_sidebar_popup(true);
			});
			
			$('.js-cart-sidebar-close').on('click', function () {
				ideapark_cart_sidebar_popup(false);
			});
			
			$('.js-cart-sidebar-shadow').on('click', function () {
				ideapark_cart_sidebar_popup(false);
			});
		}
	};
	
	root.ideapark_init_post_image_carousel = function () {
		$('.js-post-image-carousel:not(.owl-carousel)')
			.each(function () {
				var $this = $(this);
				if (!$this.closest('.js-news-carousel:not(.owl-drag)').length) {
					$this
						.addClass('owl-carousel')
						.on('resized.owl.carousel', ideapark_owl_hide_arrows)
						.owlCarousel({
							items        : 1,
							center       : false,
							autoWidth    : false,
							loop         : false,
							margin       : 0,
							rtl          : !!ideapark_wp_vars.isRtl,
							nav          : !$this.hasClass('h-carousel--nav-hide'),
							dots         : !$this.hasClass('h-carousel--dots-hide'),
							navText      : ideapark_nav_text_subcat,
							onInitialized: ideapark_owl_hide_arrows
						});
				}
			});
	};
	
	root.ideapark_init_product_carousel = function () {
		
		$('.js-single-product-carousel')
			.each(function () {
				var $this = $(this);
				var is_carousel_init = $this.hasClass('owl-carousel');
				var is_list = $this.hasClass('c-product__slider--list');
				
				if (!is_list && !is_carousel_init || is_list && !is_carousel_init && window.innerWidth < 768) {
					var layout = $this.data('layout');
					var cnt = parseInt($this.data('cnt'));
					var is_zoom = !!$this.find(".js-product-zoom").length;
					var is_zoom_mobile_hide = !!$this.find(".js-product-zoom--mobile-hide").length;
					var is_inline_video = !!$this.find('.owl-video').length;
					var is_quick_view = $this.hasClass('c-product__slider--quick-view');
					if ($this.children().length > 1 || is_inline_video) {
						var params = {
							items     : 1,
							center    : false,
							autoHeight: true,
							loop      : false,
							video     : is_inline_video,
							mouseDrag : !is_zoom,
							touchDrag : !is_zoom || is_zoom_mobile_hide,
							margin    : 0,
							rtl       : !!ideapark_wp_vars.isRtl,
							nav       : !$this.hasClass('h-carousel--nav-hide'),
							dots      : !$this.hasClass('h-carousel--dots-hide'),
							navText   : ideapark_nav_text_def
						};
						
						if (layout === 'layout-3') {
							params.responsive = {
								0      : {
									items: 1,
								}, 360 : {
									items: Math.min(cnt, ideapark_wp_vars.singleImageCarousel ? 1 : 2)
								}, 768 : {
									items: Math.min(cnt, 3)
								}, 1190: {
									items: Math.min(cnt, 4)
								}
							};
						} else if (is_quick_view) {
							params.responsive = {
								0      : {
									dots: 1,
								}, 1190: {
									dots: !$this.hasClass('h-carousel--dots-hide'),
								}
							};
						}
						$this
							.addClass('owl-carousel')
							.on('refreshed.owl.carousel', function (event) {
								setTimeout(() => {
									$(event.target).find('.owl-dot').each(function(i) {
										$(this).attr('aria-label', ideapark_wp_vars.slideText + ' ' + (i + 1));
									});
								}, 10);
							})
							.owlCarousel(params)
							.on('changed.owl.carousel', function (event) {
								var currentItem = event.item.index;
								var $slide = $(event.target).find(".owl-item").eq(currentItem);
								var $video = $slide.find('.c-inline-video');
								if (layout !== 'layout-3') {
									if ($video.length) {
										$video[0].play();
									} else {
										$('.c-product__gallery .c-inline-video').each(function () {
											$(this)[0].pause();
										});
									}
								}
								$('.c-product__thumbs-item.active').removeClass('active');
								$('.c-product__thumbs-item').eq(currentItem).addClass('active');
								$('.js-product-thumbs-carousel').trigger('to.owl.carousel', [currentItem, 300]);
							});
					}
				} else if (is_list && is_carousel_init && window.innerWidth >= 768) {
					$this
						.off('changed.owl.carousel')
						.removeClass('owl-carousel')
						.trigger("destroy.owl.carousel");
					$('.c-product__thumbs-item.active').removeClass('active');
					$('.c-product__thumbs-item').eq(0).addClass('active');
					$('.js-product-thumbs-carousel').trigger('to.owl.carousel', [0, 300]);
				}
			});
	};
	
	root.ideapark_init_product_thumbs_carousel = function () {
		
		$('.js-product-thumbs-carousel:not(.owl-carousel)').each(function () {
			var $this = $(this);
			var layout = $this.data('layout');
			var cnt = parseInt($this.data('cnt'));
			var params = {
				center       : false,
				loop         : false,
				margin       : 10,
				rtl          : !!ideapark_wp_vars.isRtl,
				nav          : !$(this).hasClass('h-carousel--nav-hide'),
				dots         : !$(this).hasClass('h-carousel--dots-hide'),
				navText      : ideapark_nav_text,
				onInitialized: ideapark_owl_hide_arrows
			};
			params.responsive = {
				0      : {
					items: Math.min(cnt, 4), margin: 5
				}, 1190: {
					items: Math.min(cnt, 6)
				}
			};
			$this
				.addClass('owl-carousel')
				.on('resized.owl.carousel', ideapark_owl_hide_arrows)
				.owlCarousel(params);
			$('.js-single-product-thumb:not(.init)', $(this)).addClass('init').on('click', function () {
				var index = $(this).data('index');
				var $item = $(this).closest('.c-product__thumbs-item');
				$('.c-product__thumbs-item.active').removeClass('active');
				$item.addClass('active');
				$('.js-single-product-carousel').trigger("to.owl.carousel", [index, 300]);
			});
		});
	};
	
	root.ideapark_init_product_tabs = function () {
		$('.js-tabs-item-link').on('click', function (e) {
			e.preventDefault();
			var $this = $(this);
			var panel_id = $this.attr('href');
			var $item = $this.closest('.c-product__tabs-item');
			$item.toggleClass('active');
			if ($item.hasClass('active')) {
				$(panel_id).slideDown();
			} else {
				$(panel_id).slideUp();
			}
		});
		
		var hash = window.location.hash;
		var url = window.location.href;
		var $tabs = $('.js-tabs-list');
		
		if (hash.toLowerCase().indexOf('comment-') >= 0 || hash === '#reviews' || hash === '#tab-reviews') {
			$tabs.find('.reviews_tab a').trigger('click');
		} else if (url.indexOf('comment-page-') > 0 || url.indexOf('cpage=') > 0) {
			$tabs.find('.reviews_tab a').trigger('click');
		} else if (hash === '#tab-additional_information') {
			$tabs.find('.additional_information_tab a').trigger('click');
		}
	};
	
	root.ideapark_init_auto_select_width = function () {
		var f = function () {
			var $this = $(this);
			var value = $this.val();
			var $cloned = $this.clone();
			$cloned.css({width: 'auto'});
			$cloned.addClass('h-invisible-total');
			$cloned.find('option:not([value=' + value + '])').remove();
			$this.after($cloned);
			var width = $cloned.outerWidth();
			$cloned.remove();
			$this.css({width: width + 'px'});
		};
		$('select.js-auto-width:not(.init)').on('change', f).each(f).addClass('init');
	};
	
	root.ideapark_to_top_button_init = function () {
		var $ideapark_to_top_button = $('.js-to-top-button');
		if ($ideapark_to_top_button.length) {
			$ideapark_to_top_button.on('click', function () {
				$('html, body').animate({scrollTop: 0}, 800);
			});
			var f = function () {
				if ($window.scrollTop() > 500) {
					if (!$ideapark_to_top_button.hasClass('c-to-top-button--active')) {
						$ideapark_to_top_button.addClass('c-to-top-button--active');
					}
				} else {
					if ($ideapark_to_top_button.hasClass('c-to-top-button--active')) {
						$ideapark_to_top_button.removeClass('c-to-top-button--active');
					}
				}
			};
			ideapark_scroll_action_add(f);
		}
	};
	
	root.ideapark_reset_sticky_sidebar = function () {
		delete root.ideapark_scroll_offset_last;
		if (ideapark_sticky_sidebar_old_style !== null) {
			$ideapark_sticky_sidebar.attr('style', ideapark_sticky_sidebar_old_style);
			ideapark_sticky_sidebar_old_style = null;
		}
		ideapark_sticky_sidebar();
	};
	
	root.ideapark_sticky_sidebar = function () {
		if (ideapark_wp_vars.stickySidebar && $ideapark_sticky_sidebar.length && $ideapark_sticky_sidebar_nearby.length) {
			
			var sb = $ideapark_sticky_sidebar;
			var content = $ideapark_sticky_sidebar_nearby;
			var is_disable_transition = false;
			var is_enable_transition = false;
			var is_collaterals = $ideapark_sticky_sidebar.hasClass('c-cart__collaterals');
			var is_mobile = (is_collaterals || sb.hasClass('tablet-sticky')) ? window.innerWidth < 768 : ideapark_is_mobile_layout;
			
			if (is_mobile) {
				
				if (ideapark_sticky_sidebar_old_style !== null) {
					sb.attr('style', ideapark_sticky_sidebar_old_style);
					ideapark_sticky_sidebar_old_style = null;
				}
				
			} else {
				
				var sb_height = sb.outerHeight(true);
				var sb_position = sb.css('position');
				var content_height = content.outerHeight(true);
				var content_top = content.offset().top;
				var scroll_offset = $window.scrollTop();
				var window_width = $window.width();
				var no_offset = sb.data('no-offset') === 'yes' || is_collaterals && window.innerWidth < 1024;
				var added_offset = no_offset ? 0 : 30;
				var top_panel_fixed_height = 0;
				var bottom_offset = ideapark_wp_vars.bottomButtons === 'screen' && window.innerWidth < 1190 ? 68 : 0;
				
				if (ideapark_is_mobile_layout) {
					top_panel_fixed_height = ideapark_sticky_mobile_active ? $ideapark_mobile_sticky_row.outerHeight() + ideapark_adminbar_visible_height + added_offset : ideapark_adminbar_visible_height;
				} else {
					top_panel_fixed_height = ideapark_sticky_desktop_active ? $ideapark_desktop_sticky_row.outerHeight() + ideapark_adminbar_visible_height + added_offset : ideapark_adminbar_visible_height;
				}
				
				if (sb_height < content_height && scroll_offset + top_panel_fixed_height > content_top) {
					
					var sb_init = {
						'position': 'undefined', 'float': 'none', 'top': 'auto', 'bottom': 'auto'
					};
					
					if (typeof ideapark_scroll_offset_last == 'undefined') {
						root.ideapark_sb_top_last = content_top;
						root.ideapark_scroll_offset_last = scroll_offset;
						root.ideapark_scroll_dir_last = 1;
						root.ideapark_window_width_last = window_width;
					}
					
					var scroll_dir = scroll_offset - ideapark_scroll_offset_last;
					if (scroll_dir === 0) {
						scroll_dir = ideapark_scroll_dir_last;
					} else {
						scroll_dir = scroll_dir > 0 ? 1 : -1;
					}
					
					var sb_big = sb_height + added_offset + bottom_offset >= $window.height() - top_panel_fixed_height, sb_top = sb.offset().top;
					
					if (sb_top < 0) {
						sb_top = ideapark_sb_top_last;
					}
					
					if (sb_big) {
						
						if (scroll_dir != ideapark_scroll_dir_last && sb_position == 'fixed') {
							sb_init.top = sb_top - content_top;
							sb_init.position = 'absolute';
							
						} else if (scroll_dir > 0) {
							if (scroll_offset + $window.height() >= content_top + content_height + added_offset * 2 + bottom_offset) {
								if (ideapark_is_sticky_sidebar_inner || ideapark_has_loader) {
									sb_init.top = (content_height - sb_height) + 'px';
									is_disable_transition = true;
								} else {
									sb_init.bottom = 0;
								}
								sb_init.position = 'absolute';
								
							} else if (scroll_offset + $window.height() >= (sb_position == 'absolute' ? sb_top : content_top) + sb_height + added_offset + bottom_offset) {
								sb_init.bottom = added_offset + bottom_offset;
								sb_init.position = 'fixed';
								is_enable_transition = true;
							}
							
						} else {
							
							if (scroll_offset + top_panel_fixed_height <= sb_top) {
								sb_init.top = top_panel_fixed_height;
								sb_init.position = 'fixed';
								is_enable_transition = true;
							}
						}
						
					} else {
						if (scroll_offset + top_panel_fixed_height >= content_top + content_height - sb_height) {
							if (ideapark_is_sticky_sidebar_inner || ideapark_has_loader) {
								sb_init.top = (content_height - sb_height) + 'px';
								is_disable_transition = true;
								
							} else {
								sb_init.bottom = 0;
							}
							sb_init.position = 'absolute';
						} else {
							sb_init.top = top_panel_fixed_height;
							sb_init.position = 'fixed';
							is_enable_transition = true;
						}
					}
					
					if (is_disable_transition) {
						is_disable_transition = false;
						sb.addClass('js-sticky-sidebar--disable-transition');
					}
					
					if (sb_init.position != 'undefined') {
						
						if (sb.css('position') != sb_init.position || ideapark_scroll_dir_last != scroll_dir || ideapark_window_width_last != window_width) {
							
							root.ideapark_window_width_last = window_width;
							sb_init.width = sb.parent().width();
							
							if (ideapark_sticky_sidebar_old_style === null) {
								var style = sb.attr('style');
								if (!style) {
									style = '';
								}
								ideapark_sticky_sidebar_old_style = style;
							}
							sb.css(sb_init);
						}
					}
					
					if (is_enable_transition) {
						is_enable_transition = false;
						setTimeout(function () {
							sb.removeClass('js-sticky-sidebar--disable-transition');
						}, 20);
					}
					
					root.ideapark_sb_top_last = sb_top;
					root.ideapark_scroll_offset_last = scroll_offset;
					root.ideapark_scroll_dir_last = scroll_dir;
					
				} else {
					if (ideapark_sticky_sidebar_old_style !== null) {
						sb.attr('style', ideapark_sticky_sidebar_old_style);
						ideapark_sticky_sidebar_old_style = null;
					}
					setTimeout(function () {
						sb.removeClass('js-sticky-sidebar--disable-transition');
					}, 20);
				}
			}
			
		}
	};
	
	root.ideapark_hash_menu_animate = function (e) {
		if (typeof ideapark_hash_menu_animate.cnt === 'undefined') {
			ideapark_hash_menu_animate.cnt = 0;
		} else {
			ideapark_hash_menu_animate.cnt++;
		}
		var $this = $(this), $el;
		var element_selector = $this.attr('href');
		if (!element_selector && ideapark_isset(e)) {
			e.preventDefault();
			$this = $(e.target);
			element_selector = $this.attr('href');
		}
		
		if (typeof element_selector !== 'undefined' && element_selector.length > 1 && element_selector.indexOf("#tab-") !== 0 && ($el = $(element_selector)) && $el.length) {
			if ($el.offset().top == 0 && ideapark_hash_menu_animate.cnt < 5) {
				setTimeout(function () {
					ideapark_hash_menu_animate(e);
				}, 100);
				return;
			}
			ideapark_hash_menu_animate.cnt = 0;
			var offset = $el.offset().top - 25 - (ideapark_adminbar_position === 'fixed' ? ideapark_adminbar_height : 0);
			if (ideapark_is_mobile_layout) {
				ideapark_mobile_menu_popup(false);
				if ($ideapark_mobile_sticky_row.length) {
					offset -= $ideapark_mobile_sticky_row.outerHeight();
				}
			} else if (ideapark_sticky_desktop_init && $ideapark_desktop_sticky_row.length) {
				offset -= $ideapark_desktop_sticky_row.outerHeight();
			}
			$('html, body').animate({scrollTop: offset}, 800);
		}
	};
	
	root.ideapark_owl_hide_arrows = function (event) {
		var $element;
		if (event instanceof jQuery) {
			$element = event;
		} else {
			$element = $(event.target);
		}
		var $prev = $element.find('.owl-prev');
		var $next = $element.find('.owl-next');
		var dot_count = $element.find('.owl-dot').length;
		if (!$element.hasClass('h-carousel--dots-hide')) {
			if (dot_count > 1) {
				$element.find('.owl-dots').removeClass('disabled');
			} else {
				$element.find('.owl-dots').addClass('disabled');
			}
		}
		if (!$element.hasClass('h-carousel--nav-hide')) {
			$element.find('.owl-nav').removeClass('disabled');
			if ($prev.length && $next.length) {
				if ($prev.hasClass('disabled') && $next.hasClass('disabled')) {
					$prev.addClass('h-hidden');
					$next.addClass('h-hidden');
					$element.find('.owl-nav').addClass('disabled');
				} else {
					$prev.removeClass('h-hidden');
					$next.removeClass('h-hidden');
				}
			}
		}
	};
	
	root.ideapark_set_notice_offset = function (offset) {
		var $notice = $('.woocommerce-notices-wrapper--ajax');
		if ($notice.length) {
			if (typeof offset !== 'number') {
				offset = ideapark_adminbar_visible_height;
				if ((ideapark_sticky_mobile_active || !$ideapark_store_notice_top.length && !$ideapark_advert_bar_above.length) && $window.width() < 768 && ! ideapark_is_popup_active) {
					offset += $ideapark_mobile_sticky_row.outerHeight();
				}
			}
			$notice.css({
				transform: 'translateY(' + offset + 'px)'
			});
		}
	};
	
	root.ideapark_init_notice = function () {
		var $n1, $n2;
		var $wrapper_main = $('.woocommerce-notices-wrapper--ajax');
		if (!$wrapper_main.length) {
			$wrapper_main = $('<div class="woocommerce-notices-wrapper woocommerce-notices-wrapper--ajax"></div>');
			$('body').append($wrapper_main);
		}
		$('.woocommerce-notices-wrapper:not(.woocommerce-notices-wrapper--ajax)').each(function () {
			var $wrapper = $(this);
			if ($wrapper.text().trim() != '') {
				$n1 = $wrapper.find('.woocommerce-notice').detach();
				if ($n1 && $n1.length) {
					ideapark_show_notice($n1);
				}
			}
			$wrapper.remove();
		});
		
		$n2 = $('.woocommerce .woocommerce-notice').detach();
		if ($n2 && $n2.length) {
			ideapark_show_notice($n2);
		}
	};
	
	root.ideapark_search_notice = function () {
		var $notices;
		$('.woocommerce-notices-wrapper:not(.woocommerce-notices-wrapper--ajax)').each(function () {
			var $wrapper = $(this);
			if ($wrapper.text().trim() != '') {
				$notices = $wrapper.find('.woocommerce-notice').detach();
				if ($notices && $notices.length) {
					ideapark_show_notice($notices);
				}
			}
			$wrapper.remove();
		});
		$notices = $('div.woocommerce-notice:not(.shown), div.woocommerce-error:not(.shown), div.woocommerce-message:not(.shown)');
		if ($notices.length) {
			$notices.detach();
			ideapark_show_notice($notices);
		}
	};
	
	root.ideapark_show_notice = function (notice) {
		if (ideapark_empty(notice)) {
			return;
		}
		ideapark_set_notice_offset();
		var $wrapper = $('.woocommerce-notices-wrapper--ajax');
		var $notices = notice instanceof jQuery ? notice : $(notice);
		var is_new = !$wrapper.find('.woocommerce-notice').length;
		if (is_new) {
			$wrapper.css({display: 'none'});
		}
		$notices.addClass('shown');
		$notices.each(function () {
			var $notice = $(this);
			if (!$notice.find('.js-notice-close').length) {
				$notice.append($('<button class="h-cb h-cb--svg woocommerce-notice-close js-notice-close"><i class="ip-close-small woocommerce-notice-close-svg"></i></button>'));
			}
		});
		$wrapper.append($notices);
		if (is_new) {
			var dif = $wrapper.outerHeight() + 150;
			var top_orig = ideapark_is_mobile_layout ? 0 : parseInt($wrapper.css('top').replace('px', ''));
			$wrapper.css({top: (top_orig - dif) + 'px'});
			$wrapper.css({display: ''});
			$({y: top_orig}).animate({y: top_orig + dif}, {
				step       : function (y) {
					$wrapper.css({
						top: (y - dif) + 'px',
					});
				}, duration: 500, complete: function () {
					$wrapper.css({
						top: '',
					});
					$wrapper.addClass('woocommerce-notices-wrapper--transition');
				}
			});
		}
		
		$notices.find('.js-notice-close').each(function () {
			var $close = $(this);
			var $showlogin = $close.closest('.woocommerce-notice').find('.showlogin');
			let timeout = parseInt(ideapark_wp_vars.popupMessagesTimeout);
			if ($showlogin.length) {
				$showlogin.one('click', function () {
					$close.trigger('click');
					setTimeout(function () {
						var $form = $('.woocommerce-form-login');
						if ($form.length === 1) {
							$('html, body').animate({scrollTop: $form.offset().top - ideapark_header_height - 20}, 800);
						}
					}, 300);
				});
				if (timeout) {
					setTimeout(function () {
						$close.trigger('click');
					}, timeout <= 5 ? 10000 : timeout * 1000);
				}
			} else {
				if (timeout) {
					setTimeout(function () {
						$close.trigger('click');
					}, timeout * 1000);
				}
			}
		});
	};
	
	root.ideapark_show_notice_error = function (message) {
		ideapark_show_notice($('<div class="woocommerce-notice  shown" role="alert">\n' + '\t\t<i class="ip-wc-error woocommerce-notice-error-svg"></i>\n' + '\t\t' + message + '\t\t<button class="h-cb h-cb--svg woocommerce-notice-close js-notice-close"><i class="ip-close-small woocommerce-notice-close-svg"></i></button>\n' + '\t</div>'));
	};
	
	root.ideapark_init_callback_popup = function () {
		var $ideapark_callback_popup = $('.js-callback-popup');
		if ($ideapark_callback_popup.length) {
			
			$ideapark_callback_popup.each(function () {
				var $popup = $(this);
				
				var open_popup = function (e) {
					e.preventDefault();
					ideapark_mobile_menu_popup(false);
					$popup.removeClass('c-header__callback-popup--disabled');
					setTimeout(function () {
						$popup.addClass('c-header__callback-popup--active');
					}, 20);
					ideapark_grid_video_start(true);
					ideapark_is_popup_active = true;
				};
				
				$popup.on('ip-open', open_popup);
				
				if ($popup.data('button')) {
					$(document).on('click', $popup.data('button'), open_popup);
				}
				
				$('.js-callback-close', $popup).on('click', function () {
					if ($popup.hasClass('c-header__callback-popup--active')) {
						ideapark_on_transition_end_callback($popup, function () {
							//$('.c-header__callback-wrap').attr('class', 'c-header__callback-wrap');
							$popup.addClass('c-header__callback-popup--disabled');
						});
						$popup.toggleClass('c-header__callback-popup--active');
						
						ideapark_grid_video_start(false);
						$popup.trigger('ip-close');
						ideapark_is_popup_active = false;
					}
				});
				
				$(document).on('ideapark.wpadminbar.scroll', function (event, wpadminbar_height) {
					$popup.css({
						transform: 'translateY(' + wpadminbar_height + 'px)', 'max-height': 'calc(100% - ' + wpadminbar_height + 'px)'
					});
				});
			});
		}
	};
	
	root.ideapark_init_attribute_hint_popup = function () {
		$('.js-attribute-hint').on('click', function () {
			var $button = $(this), ajax_url = ideapark_wp_vars.ajaxUrl, attribute_id = $button.data('id'), $ideapark_hint_container = $('.js-attribute-hint-container'),
				$ideapark_hint_popup = $('.js-attribute-hint-popup'), data = {
					lang: $button.data('lang'), attribute_id: attribute_id, action: 'ideapark_ajax_attribute_hint'
				};
			
			$.ajax({
				type      : 'POST', url: ajax_url, data: data, dataType: 'html', cache: false, headers: {'cache-control': 'no-cache'}, beforeSend: function () {
					$button.ideapark_button('loading', 16, true);
				}, success: function (data) {
					$ideapark_hint_container.html(data);
					$ideapark_hint_popup.trigger('ip-open');
					$button.ideapark_button('reset');
					ideapark_init_accordion();
					ideapark_init_tabs();
				}
			});
		});
	};
	
	root.ideapark_load_variable_scripts = function () {
		$.ajax({
			type       : 'POST', url: ideapark_wp_vars.ajaxUrl, data: {
				action: 'ideapark_ajax_variable_scripts'
				// lang      : $button.data('lang')
			}, dataType: 'html', cache: false, headers: {'cache-control': 'no-cache'}, success: function (data) {
				if (window.requestAnimationFrame) {
					window.requestAnimationFrame(function () {
						$(document.body).append(data);
					});
				} else {
					$(document.body).append(data);
				}
			}
		});
	};
	
	root.ideapark_init_review_placeholder = function () {
		$('#reviews #commentform textarea, #reviews #commentform input').each(function () {
			var $this = $(this);
			var $label = $this.parent().find('label');
			if ($label.length) {
				$this.attr('placeholder', $label.text());
			}
		});
	};
	
	root.ideapark_init_masonry = function () {
		var $ideapark_masonry_grid = $('.js-post-masonry');
		var ideapark_masonry_grid_on = !!$ideapark_masonry_grid.length;
		var $ideapark_masonry_sidebar = $('.c-post-sidebar');
		var ideapark_masonry_sidebar_on = !!$ideapark_masonry_sidebar.length && $ideapark_masonry_sidebar.find('.widget').length > 2;
		if (ideapark_masonry_grid_on || ideapark_masonry_sidebar_on) {
			var f = function () {
				var window_width = $window.width();
				var is_sidebar_masonry_width = window_width >= 630 && window_width <= 1189;
				if (!ideapark_is_masonry_init) {
					
					ideapark_is_masonry_init = true;
					
					if (ideapark_masonry_grid_on) {
						$ideapark_masonry_grid.addClass('js-masonry');
					}
					
					var init_f = function () {
						if (ideapark_masonry_sidebar_on && is_sidebar_masonry_width) {
							ideapark_masonry_sidebar_object = new Masonry($ideapark_masonry_sidebar[0], {
								itemSelector: '.widget', percentPosition: true
							});
							$ideapark_masonry_sidebar.addClass('init-masonry');
						}
					};
					
					if (typeof root.Masonry !== 'undefined') {
						init_f();
						if (ideapark_masonry_grid_on) {
							$ideapark_masonry_grid.addClass('c-blog__grid--init-masonry');
						}
					} else {
						ideapark_require([ideapark_wp_vars.masonryUrl], function () {
							init_f();
							if (ideapark_masonry_grid_on) {
								$ideapark_masonry_grid.addClass('c-blog__grid--init-masonry');
							}
						});
					}
				} else {
					if (ideapark_masonry_sidebar_on) {
						var is_init = $ideapark_masonry_sidebar.hasClass('init-masonry');
						if (is_sidebar_masonry_width && !is_init) {
							ideapark_masonry_sidebar_object = new Masonry($ideapark_masonry_sidebar[0], {
								itemSelector: '.widget', percentPosition: true
							});
							$ideapark_masonry_sidebar.addClass('init-masonry');
						} else if (!is_sidebar_masonry_width && is_init) {
							ideapark_masonry_sidebar_object.destroy();
							ideapark_masonry_sidebar_object = null;
							$ideapark_masonry_sidebar.removeClass('init-masonry');
							setTimeout(function () {
								$ideapark_masonry_sidebar.find('.widget').css({left: '', top: ''});
							}, 300);
						}
					}
				}
			};
			f();
			ideapark_resize_action_500_add(f);
		}
	};
	
	
	root.ideapark_menu_set_height = function ($ul_main) {
		
		var cols = 1;
		if ($ul_main.hasClass('c-top-menu__submenu--columns-2')) {
			cols = 2;
		} else if ($ul_main.hasClass('c-top-menu__submenu--columns-3')) {
			cols = 3;
		} else if ($ul_main.hasClass('c-top-menu__submenu--columns-4')) {
			cols = 4;
		} else if ($ul_main.hasClass('c-top-menu__submenu--columns-5')) {
			cols = Math.floor($window.width() / 290);
		}
		var $ul = $ul_main;
		var padding_top = $ul.css('padding-top') ? parseInt($ul.css('padding-top').replace('px', '')) : 0;
		var padding_bottom = $ul.css('padding-bottom') ? parseInt($ul.css('padding-bottom').replace('px', '')) : 0;
		var heights = [];
		var max_height = 0;
		var all_sum_height = 0;
		$ul.children('li').each(function () {
			var $li = $(this);
			var height = $li.outerHeight();
			if (height > max_height) {
				max_height = height;
			}
			all_sum_height += height;
			heights.push(height);
		});
		var test_cols = 0;
		var cnt = 0;
		var test_height = max_height - 1;
		do {
			test_height++;
			cnt++;
			test_cols = 1;
			var sum_height = 0;
			for (var i = 0; i < heights.length; i++) {
				sum_height += heights[i];
				if (sum_height > test_height) {
					sum_height = 0;
					i--;
					test_cols++;
				}
			}
		} while (test_cols > cols && cnt < 1000);
		
		if (test_cols <= cols && test_height > 0) {
			$ul.css({height: (test_height + padding_top + padding_bottom) + 'px'}).addClass('mega-menu-break');
		}
	};
	
	root.ideapark_menu_fix_position = function ($ul) {
		if (!ideapark_is_mobile_layout) {
			var delta;
			var item_space = ideapark_wp_vars.menuItemSpace;
			var window_width = $window.width();
			var is_fullwidth = !($('.c-header__cell--bottom-center .js-top-menu').length || $('.c-header__cell--top-center .js-top-menu').length || $('.c-header__cell--center-center .js-top-menu').length);
			var is_HTML_block = $ul.hasClass("c-top-menu__submenu--content");
			var container_width = is_fullwidth ? window_width + (is_HTML_block ? -60 : (item_space * 2)) : 1170;
			var container_left = is_fullwidth ? (is_HTML_block ? 30 : -item_space) : $('.js-simple-container').offset().left;
			var container_right = container_left + container_width;
			var is_fullwidth_item = $ul.hasClass('c-top-menu__submenu--columns-5');
			
			if (is_HTML_block) {
				var $content = $ul.children().first();
				$content.css({'max-height': '100%'});
				
				setTimeout(function () {
					var content_top = $content.offset().top - $(window).scrollTop();
					var content_height = $content.outerHeight();
					var window_height = $window.height();
					var content_bottom = content_top + content_height;
					if (content_bottom > window_height) {
						$content.css({'max-height': (window_height - content_top) + 'px'});
					}
				}, 500);
			}
			
			if (is_fullwidth_item) {
				delta = (ideapark_wp_vars.isRtl ? -$ul.offset().left : $ul.offset().left) - parseInt($ul.css('inset-inline-start').replace('px', ''));
				$ul.css({
					'inset-inline-start': -delta
				});
			} else {
				var ul_left = $ul.offset().left;
				var ul_right = ul_left + $ul.outerWidth();
				
				if (ul_left < container_left) {
					if (ideapark_wp_vars.isRtl) {
						delta = Math.round(parseInt($ul.css('right').replace('px', '')) - container_left + ul_left);
						$ul.css({
							right: delta
						});
					} else {
						delta = Math.round(parseInt($ul.css('left').replace('px', '')) + container_left - ul_left);
						$ul.css({
							left: delta
						});
					}
					
				}
				if (ul_right > container_right) {
					if (ideapark_wp_vars.isRtl) {
						delta = Math.round(parseInt($ul.css('right').replace('px', '')) + ul_right - container_right);
						$ul.css({
							right: delta
						});
					} else {
						delta = Math.round(parseInt($ul.css('left').replace('px', '')) - ul_right + container_right);
						$ul.css({
							left: delta
						});
					}
				}
			}
		}
	};
	
	root.ideapark_mega_menu_init = function () {
		if (!ideapark_is_mobile_layout && ideapark_mega_menu_initialized === 0 && ideapark_all_is_loaded) {
			var window_width = $window.width();
			
			$('.c-top-menu__submenu--columns-1').addClass('initialized').closest('li').addClass('initialized');
			
			var main_items = $('.c-top-menu__submenu--columns-2, .c-top-menu__submenu--columns-3, .c-top-menu__submenu--columns-4, .c-top-menu__submenu--columns-5');
			if (main_items.length) {
				main_items.each(function () {
					var $ul = $(this);
					if ($ul.hasClass('c-top-menu__submenu--columns-5')) {
						ideapark_resize_action_500_add(function () {
							if (!ideapark_is_mobile_layout) {
								ideapark_menu_set_height($ul);
							}
						});
						ideapark_menu_set_height($ul);
					} else {
						ideapark_menu_set_height($ul);
					}
					ideapark_menu_fix_position($ul);
					ideapark_resize_action_500_add(function () {
						ideapark_menu_fix_position($ul);
					});
					
					$ul.addClass('initialized');
					$ul.closest('li').addClass('initialized');
				});
			}
			
			$('.c-top-menu__submenu--inner').each(function () {
				var $ul = $(this);
				var cond = ideapark_wp_vars.isRtl ? ($ul.offset().left < 0) : ($ul.offset().left + $ul.width() > window_width);
				if (cond) {
					$ul.addClass('c-top-menu__submenu--rtl');
					$ul.closest('li').children('.c-top-menu__more-svg').addClass('c-top-menu__more-svg--rtl');
				}
			});
			
			ideapark_mega_menu_initialized = 1;
		}
	};
	
	root.ideapark_init_zoom = function () {
		if (ideapark_is_mobile_layout) {
			$(".js-product-zoom--mobile-hide.init").each(function () {
				var $this = $(this);
				$this.removeClass('init').trigger('zoom.destroy');
			});
			$(".js-product-zoom:not(.js-product-zoom--mobile-hide):not(.init)").each(function () {
				var $this = $(this);
				$this.addClass('init').zoom({
					url         : $this.data('img'), duration: 0, onZoomIn: function () {
						$(this).parent().addClass('zooming');
					}, onZoomOut: function () {
						$(this).parent().removeClass('zooming');
					}
				});
			});
		} else {
			$(".js-product-zoom:not(.init)").each(function () {
				var $this = $(this);
				$this.addClass('init').zoom({
					url         : $this.data('img'), duration: 0, onZoomIn: function () {
						$(this).parent().addClass('zooming');
					}, onZoomOut: function () {
						$(this).parent().removeClass('zooming');
					}
				});
			});
		}
	};
	
	root.ideapark_init_subcat_carousel = function () {
		$('.js-header-subcat').each(function () {
			var $this = $(this);
			var container_width = $this.closest('.c-page-header__sub-cat').outerWidth();
			var items = 0;
			var items_width = 0;
			var current = 0;
			$this.find('.c-page-header__sub-cat-item').each(function () {
				if ($(this).hasClass('c-page-header__sub-cat-item--current')) {
					current = items;
				}
				items_width += $(this).outerWidth();
				items++;
			});
			if (items_width > container_width && items > 1) {
				if (!$this.hasClass('owl-carousel')) {
					$this
						.addClass('owl-carousel')
						.on('refreshed.owl.carousel', function (event) {
							setTimeout(() => {
								$(event.target).find('.owl-dot').each(function(i) {
									$(this).attr('aria-label', ideapark_wp_vars.slideText + ' ' + (i + 1));
								});
							}, 10);
						})
						.owlCarousel({
							center       : false,
							margin       : 0,
							startPosition: current,
							loop         : false,
							autoWidth    : true,
							items        : 1,
							rtl          : !!ideapark_wp_vars.isRtl,
							navText      : ideapark_nav_text_subcat,
							responsive   : {
								0      : {
									nav: false, dost: true,
								}, 1190: {
									nav: true, dots: false,
								},
							}
						}).find('.c-page-header__sub-cat-item--current').first().closest('.owl-item').addClass('current');
				}
			} else if (items > 1) {
				if ($this.hasClass('owl-carousel')) {
					$this
						.removeClass('owl-carousel')
						.trigger("destroy.owl.carousel");
				}
			}
			$this.parent().addClass('c-page-header__sub-cat--init');
		});
	};
	
	root.ideapark_init_product_combined = function () {
		$('.js-product-combined:not(.init-combined)').each(function () {
			var $list = $(this);
			if ($list.find('.c-product-grid__item').length <= 1) {
				return;
			}
			var combined = $list.data('combined');
			
			var resized = function () {
				if (ideapark_is_mobile_layout && !$list.hasClass('init')) {
					$list.addClass(combined);
					ideapark_init_product_grid_carousel();
				} else if (!ideapark_is_mobile_layout && $list.hasClass('init')) {
					$list
						.removeClass(combined)
						.removeClass('init')
						.removeClass('owl-carousel')
						.trigger("destroy.owl.carousel");
				}
			};
			
			resized();
			ideapark_resize_action_500_add(resized);
			
			$list.addClass('init-combined');
		});
	};
	
	root.ideapark_init_product_grid_carousel = function () {
		$('.js-product-grid-carousel:not(.init)').each(function () {
			var $list = $(this);
			if ($list.find('.c-product-grid__item').length <= 1) {
				return;
			}
			
			var count = $list.data('count');
			var layout = $list.data('layout');
			var layout_width = $list.data('layout-width');
			var layout_mobile = $list.data('layout-mobile');
			var window_width = $window.width();
			var is_fullwidth = $list.hasClass('c-product-grid__list--fullwidth');
			var autoplay = $list.data('autoplay') === 'yes';
			var animation_timeout = $list.data('animation-timeout');
			
			var params = {
				center       : false,
				margin       : 0,
				loop         : $list.hasClass('h-carousel--loop'),
				rtl          : !!ideapark_wp_vars.isRtl,
				nav          : !$list.hasClass('h-carousel--nav-hide'),
				dots         : !$list.hasClass('h-carousel--dots-hide'),
				navText      : ideapark_nav_text_def,
				onInitialized: ideapark_owl_hide_arrows
			};
			
			if (autoplay) {
				params.autoplay = true;
				params.autoplayTimeout = animation_timeout;
			}
			
			switch (layout) {
				case '5-per-row':
					params.responsive = {
						0      : {
							items: layout_mobile === '2-per-row-mobile' ? 2 : 1,
						}, 768 : {
							items: Math.min(4, count),
						}, 1024: {
							items: Math.min(5, count),
						},
					};
					break;
				case '4-per-row':
					params.responsive = {
						0      : {
							items: layout_mobile === '2-per-row-mobile' ? 2 : 1,
						}, 768 : {
							items: Math.min(3, count),
						}, 1024: {
							items: Math.min(4, count),
						},
					};
					break;
				case '3-per-row':
					params.responsive = {
						0     : {
							items: layout_mobile === '2-per-row-mobile' ? 2 : 1,
						}, 768: {
							items: Math.min(3, count),
						},
					};
					break;
			}
			$list
				.addClass('owl-carousel')
				.on('resized.owl.carousel', function () {
					ideapark_owl_hide_arrows($list);
					$list.trigger('arrows.owl.carousel');
				})
				.on('refreshed.owl.carousel', function () {
					$list.trigger('arrows.owl.carousel');
				})
				.on('changed.owl.carousel', function () {
					ideapark_grid_video_start();
				})
				.on('refreshed.owl.carousel', function (event) {
					setTimeout(() => {
						$(event.target).find('.owl-dot').each(function(i) {
							$(this).attr('aria-label', ideapark_wp_vars.slideText + ' ' + (i + 1));
						});
					}, 10);
				})
				.owlCarousel(params)
				.addClass('init')
				.trigger('arrows.owl.carousel');
		});
	};
	
	root.ideapark_init_venobox = function ($button) {
		if (root.VenoBox !== 'function') {
			var $play_button = $('.c-play', $button);
			var $button_loading = $play_button.length ? $play_button : $button;
			if ($button_loading.hasClass('js-loading')) {
				return;
			}
			$button_loading.ideapark_button('loading', 26);
			ideapark_require([ideapark_wp_vars.themeUri + '/assets/js/venobox/venobox.min.js', ideapark_wp_vars.themeUri + '/assets/css/venobox/venobox.min.css'], function () {
				$button_loading.ideapark_button('reset');
				new VenoBox({
					selector: ".js-video,.js-ip-video"
				});
				VenoBox().open($button[0]);
			});
		}
	};
	
	root.ideapark_init_ajax_add_to_cart = function () {
		$('form.cart:not(.init)').on('submit', function (e) {
			var is_grid = !!$(this).closest('.c-product-grid__item').length;
			var $buy_now = typeof ideapark_init_ajax_add_to_cart.buy_now !== 'undefined' && ideapark_init_ajax_add_to_cart.buy_now ? ideapark_init_ajax_add_to_cart.buy_now : false;
			ideapark_init_ajax_add_to_cart.buy_now = null;
			
			if ($(this).closest('.product-type-external').length) {
				return true;
			}
			
			if (!is_grid && !ideapark_wp_vars.ajaxAddToCart && !$buy_now) {
				return true;
			}
			
			e.preventDefault();
			var $form = $(this);
			ideapark_init_ajax_add_to_cart.buy_now = null;
			var $button = $buy_now ? $buy_now : $form.find('.single_add_to_cart_button:not(.disabled)');
			
			if ($buy_now && $button.closest('.woocommerce-variation-add-to-cart-disabled').length) {
				var $wrap = $button.parent();
				var $atc_button = $wrap.find('.single_add_to_cart_button');
				if ($atc_button.length) {
					if ($atc_button.is('.wc-variation-is-unavailable')) {
						window.alert(wc_add_to_cart_variation_params.i18n_unavailable_text);
					} else if ($atc_button.is('.wc-variation-selection-needed')) {
						window.alert(wc_add_to_cart_variation_params.i18n_make_a_selection_text);
					}
				}
				return;
			}
			
			if (typeof $form.block === 'function') {
				$form.block({message: null, overlayCSS: {background: '#fff', opacity: 0.6}});
			}
			
			var formData = new FormData($form[0]);
			formData.append('add-to-cart', $form.find('[name=add-to-cart]').val());
			
			if ($button.length) {
				$button.ideapark_button('loading', 16);
			}
			
			// Ajax action.
			$.ajax({
				url        : wc_add_to_cart_params.wc_ajax_url.toString().replace('%%endpoint%%', 'ip_add_to_cart'),
				data       : formData,
				type       : 'POST',
				processData: false,
				contentType: false,
				complete   : function (response) {
					if ($buy_now) {
						window.location = $buy_now.data('redirect');
						$buy_now = null;
						if (typeof $form.unblock === 'function') {
							$form.unblock();
						}
						$button.ideapark_button('reset');
						return;
					}
					
					$button.ideapark_button('reset');
					
					response = response.responseJSON;
					
					if (!response) {
						return;
					}
					
					if (response.error && response.product_url) {
						window.location = response.product_url;
						return;
					}
					
					// Redirect to cart option
					if (wc_add_to_cart_params.cart_redirect_after_add === 'yes') {
						window.location = wc_add_to_cart_params.cart_url;
						return;
					}
					
					// Trigger event so themes can refresh other areas.
					$(document.body).trigger('added_to_cart', [response.fragments, response.cart_hash, $button]);
					
					if (typeof $form.unblock === 'function') {
						$form.unblock();
					}
				}
			});
		}).addClass('init');
		
		$('.js-buy-now:not(.init)').on('click', function () {
			ideapark_init_ajax_add_to_cart.buy_now = $(this);
		}).addClass('init');
		
		ideapark_init_ajax_add_to_cart.initialized = true;
	};
	
	root.ideapark_infinity_loader = function ($button, e) {
		if (typeof e !== 'undefined') {
			e.preventDefault();
		}
		var $grid = $button.parent().prev().find('.c-product-grid__list');
		var url = $button.attr('href');
		var is_a = true;
		if (!url) {
			url = $button.data('href');
			is_a = false;
		}
		if ($button.hasClass('js-loading')) {
			return;
		}
		$button.ideapark_button('loading', is_a ? 19 : 35);
		$.ajax({
			url       : url, type: 'POST', data: {
				'ideapark_infinity_loading': 1
			}, success: function (results) {
				$button.ideapark_button('reset');
				if (results.products) {
					$grid.append(results.products);
					ideapark_sticky_sidebar();
					ideapark_grid_color_var_init();
					$(document.body).trigger('ideapark-infinity-loaded');
				}
				if (results.paging) {
					$button.parent().replaceWith(results.paging);
				} else {
					$button.remove();
				}
				$ideapark_infinity_loader = $('.js-load-infinity');
			}
		});
	};
	
	root.ideapark_infinity_loading = function () {
		if ($ideapark_infinity_loader && $ideapark_infinity_loader.length && !$ideapark_infinity_loader.hasClass('js-loading')) {
			if ($ideapark_infinity_loader.offset().top - $(window).scrollTop() - $(window).height() <= 300) {
				ideapark_infinity_loader($ideapark_infinity_loader);
			}
		}
	};
	
	root.ideapark_grid_color_var_init = function () {
		var ideapark_color_var_timeout = null;
		$('.js-grid-color-var:not(.init)')
			.on('click', function () {
				if (ideapark_color_var_timeout !== null) {
					clearTimeout(ideapark_color_var_timeout);
					ideapark_color_var_timeout = null;
				}
				var $this = $(this);
				var $product = $this.closest('.c-product-grid__item');
				var $image = $product.find('.c-product-grid__thumb').first();
				var $image_wrap = $image.closest('.c-product-grid__thumb-wrap');
				if ($this.hasClass('current')) {
					$product.find('.c-product-grid__color-item.current').removeClass('current hover');
					$image.attr('src', $image.data('src'));
					$image.attr('srcset', $image.data('srcset'));
					$product.find('.c-product-grid__atc-block').removeClass('c-product-grid__atc-block--hide');
					var $hover = $product.find('.c-product-grid__thumb--hover');
					if ($hover.length) {
						$hover.show();
						$image.addClass('c-product-grid__thumb--base').removeClass('c-product-grid__thumb--var');
					}
					$image.removeClass('c-product-grid__thumb--var');
					$image_wrap.removeClass('c-product-grid__thumb-wrap--var');
					return;
				}
				$product.find('.c-product-grid__thumb--hover').hide();
				$product.find('.c-product-grid__thumb--base').removeClass('c-product-grid__thumb--base');
				$product.find('.c-product-grid__thumb').addClass('c-product-grid__thumb--var');
				$product.find('.c-product-grid__thumb-wrap').addClass('c-product-grid__thumb-wrap--var');
				$product.find('.c-product-grid__color-item.current').removeClass('current hover');
				if (!ideapark_is_mobile_layout && !$product.hasClass('c-product-grid__item--2-per-row')) {
					$product.find('.c-product-grid__atc-block').addClass('c-product-grid__atc-block--hide');
				}
				$this.addClass('current hover');
				if ($image.length) {
					if (typeof $image.data('src') === 'undefined') {
						$image.data('src', $image.attr('src'));
						$image.data('srcset', $image.attr('srcset'));
					}
					$image.attr('src', $this.data('src'));
					$image.attr('srcset', $this.data('srcset'));
				}
			})
			.on('mouseout', function () {
				var $this = $(this);
				$this.removeClass('hover');
				if ($this.hasClass('current') && ideapark_color_var_timeout === null) {
					ideapark_color_var_timeout = setTimeout(function () {
						var $product = $this.closest('.c-product-grid__item');
						var $image = $product.find('.c-product-grid__thumb').first();
						var $image_wrap = $image.closest('.c-product-grid__thumb-wrap');
						$product.find('.c-product-grid__atc-block').removeClass('c-product-grid__atc-block--hide');
						$image.removeClass('c-product-grid__thumb--var');
						$image_wrap.removeClass('c-product-grid__thumb-wrap--var');
						ideapark_color_var_timeout = null;
					}, 800);
				}
			})
			.addClass('init');
		if (!ideapark_grid_color_var_init.initialized) {
			ideapark_resize_action_500_add(function () {
				$('.js-grid-color-var.hover').trigger('mouseout');
			});
		}
		ideapark_grid_color_var_init.initialized = true;
	};
	
	root.ideapark_init_filter_expand = function () {
		if (!ideapark_wp_vars.collapseFilters) {
			return;
		}
		$('.widget_product_tag_cloud .widget-title,.widget_product_categories .widget-title,.widget_price_filter .widget-title,.widget_rating_filter .widget-title, .woocommerce-widget-layered-nav .widget-title').on('click', function () {
			var $widget = $(this).parent();
			var container_class = 'ul';
			if ($widget.hasClass('widget_product_tag_cloud')) {
				container_class = '.tagcloud';
			} else if ($widget.hasClass('widget_price_filter')) {
				container_class = 'form';
			} else if ($widget.find('form.woocommerce-widget-layered-nav-dropdown').first().length) {
				container_class = 'form';
			} else if ($widget.find('.dropdown_product_cat').first().length) {
				container_class = '.select2';
			}
			var $list = $widget.find(container_class).first();
			if ($widget.hasClass('expanded')) {
				$list.slideUp({
					duration: 500, complete: function () {
						ideapark_reset_sticky_sidebar();
					}
				});
				$widget.removeClass('expanded');
			} else {
				$list.slideDown({
					duration   : 500, start: function () {
						$(this).css({
							display: "block"
						});
					}, complete: function () {
						$(this).css({
							display: "block"
						});
						ideapark_reset_sticky_sidebar();
					}
				});
				$widget.addClass('expanded');
			}
		});
	};
	
	root.ideapark_init_cart_auto_update = function () {
		var $button = $(".c-cart__shop-update-button--auto");
		if ($button.length) {
			$(document.body).on('change', 'input.qty', ideapark_debounce(function () {
				$(".c-cart__shop-update-button--auto").trigger("click");
			}, 500));
		}
	};
	
	root.ideapark_init_anchor_smooth_scrolling = function () {
		$(document.body).on('click', 'a[href^="#"]:not(.js-ip-tabs-link):not(.js-tabs-item-link):not(.woocommerce-review-link)', ideapark_hash_menu_animate);
	};
	
	root.ideapark_init_nice_select = function () {
		$('select.orderby:not(.nice-select)').niceSelect();
		$('.nice-select .list').each(function () {
			var $this = $(this), delta;
			if (ideapark_wp_vars.isRtl) {
				$this.css({right: 0});
				setTimeout(function () {
					delta = $this.offset().left;
					if (delta < 0) {
						$this.css({right: (delta - 20) + 'px'});
					}
				}, 100);
			} else {
				$this.css({left: 0});
				setTimeout(function () {
					delta = $window.width() - ($this.offset().left + $this.outerWidth());
					if (delta < 0) {
						$this.css({left: (delta - 20) + 'px'});
					}
				}, 100);
			}
		});
	};
	
	root.ideapark_grid_video_start = function (stop_all) {
		if (typeof ideapark_grid_video_start.stopped !== 'undefined' && ideapark_grid_video_start.stopped && stop_all !== false) {
			return;
		}
		if (stop_all === true) {
			ideapark_grid_video_start.stopped = true;
		}
		if (stop_all === false) {
			ideapark_grid_video_start.stopped = false;
		}
		$('.js-grid-video').each(function () {
			var $video = $(this);
			var is_visible = $video.visible(true);
			var is_active = $video.hasClass('active');
			if (stop_all) {
				if (is_active) {
					$video[0].pause();
					$video.removeClass('active');
				}
			} else {
				if (is_visible && !is_active) {
					$video[0].play();
					$video.addClass('active');
				}
				if (!is_visible && is_active) {
					$video[0].pause();
					$video.removeClass('active');
				}
			}
		});
	};
	root.ideapark_grid_video_start_debounce = ideapark_debounce(ideapark_grid_video_start, 10);
	
	root.ideapark_lightbox = function (images, index) {
		if (images.images.length) {
			var options = {
				index              : index ? index : 0,
				showHideOpacity    : true,
				bgOpacity          : 1,
				loop               : false,
				closeOnVerticalDrag: false,
				mainClass          : '',
				barsSize           : {top: 0, bottom: 0},
				captionEl          : false,
				fullscreenEl       : false,
				zoomEl             : true,
				shareEl            : false,
				counterEl          : false,
				tapToClose         : true,
				tapToToggleControls: false
			};
			
			var pswpElement = $('.pswp')[0];
			
			ideapark_wpadminbar_resize();
			
			var gallery = new PhotoSwipe(pswpElement, PhotoSwipeUI_Default, images.images, options);
			gallery.init();
			
			gallery.listen('afterChange', function () {
				if (!ideapark_empty(gallery.currItem.html)) {
					if (typeof window.wp.mediaelement !== 'undefined' && typeof window.wp.mediaelement.initialize !== 'undefined') {
						$(window.wp.mediaelement.initialize);
					}
				}
			});
			
			gallery.listen('close', function () {
				$('.pswp__video-wrap').html('');
				$('.c-product__gallery .c-inline-video').each(function () {
					var $video = $(this);
					var $owl_item = $video.closest('.owl-item');
					if (!$owl_item.length || $owl_item.hasClass('active')) {
						$video[0].play();
					}
				});
				ideapark_grid_video_start(false);
			});
			
			if (typeof window.wp.mediaelement !== 'undefined' && typeof window.wp.mediaelement.initialize !== 'undefined') {
				$(window.wp.mediaelement.initialize);
			}
		}
	};
	
	root.ideapark_init_recently = function () {
		const with_container = (typeof ideapark_recently_container !== 'undefined' && ideapark_recently_container);
		const with_product_id = (typeof ideapark_recently_product_id !== 'undefined' && ideapark_recently_product_id > 0);
		if (with_container || with_product_id) {
			if (with_product_id && !with_container) {
				if ($('.c-product__products--recently .c-product-grid__item').first().hasClass('post-' + ideapark_recently_product_id)) {
					return;
				}
			}
			$.ajax({
				url       : ideapark_wp_vars.ajaxUrl, type: 'POST', data: {
					action: 'ideapark_ajax_recently', product_id: ideapark_recently_product_id, add_only: ideapark_recently_add_only ? 1 : 0, lang: ideapark_wp_vars.locale
				}, success: function (html) {
					if (with_container && html) {
						ideapark_parse_recently(html, ideapark_recently_container);
					}
					if (ideapark_supports_html5_storage) {
						if (ideapark_recently_add_only) {
							window.localStorage.removeItem(ideapark_recently_storage_key);
						} else {
							window.localStorage.setItem(ideapark_recently_storage_key, html);
						}
					}
				}
			});
		}
	};
	
	$.fn.visible = function (partial, hidden, direction, container) {
		
		if (this.length < 1) return;
		
		direction = direction || 'both';
		
		var $t = this.length > 1 ? this.eq(0) : this, isContained = typeof container !== 'undefined' && container !== null, $c = isContained ? $(container) : $window,
			wPosition = isContained ? $c.position() : 0, t = $t.get(0), vpWidth = $c.outerWidth(), vpHeight = $c.outerHeight(),
			clientSize = hidden === true ? t.offsetWidth * t.offsetHeight : true;
		
		if (typeof t.getBoundingClientRect === 'function') {
			
			var rec = t.getBoundingClientRect(), tViz = isContained ? rec.top - wPosition.top >= 0 && rec.top < vpHeight + wPosition.top : rec.top >= 0 && rec.top < vpHeight,
				bViz = isContained ? rec.bottom - wPosition.top > 0 && rec.bottom <= vpHeight + wPosition.top : rec.bottom > 0 && rec.bottom <= vpHeight,
				lViz = isContained ? rec.left - wPosition.left >= 0 && rec.left < vpWidth + wPosition.left : rec.left >= 0 && rec.left < vpWidth,
				rViz = isContained ? rec.right - wPosition.left > 0 && rec.right < vpWidth + wPosition.left : rec.right > 0 && rec.right <= vpWidth,
				vVisible = partial ? tViz || bViz : tViz && bViz, hVisible = partial ? lViz || rViz : lViz && rViz;
			vVisible = (rec.top < 0 && rec.bottom > vpHeight) ? true : vVisible;
			hVisible = (rec.left < 0 && rec.right > vpWidth) ? true : hVisible;
			
			if (direction === 'both') return clientSize && vVisible && hVisible; else if (direction === 'vertical') return clientSize && vVisible; else if (direction === 'horizontal') return clientSize && hVisible;
		} else {
			
			var viewTop = isContained ? 0 : wPosition, viewBottom = viewTop + vpHeight, viewLeft = $c.scrollLeft(), viewRight = viewLeft + vpWidth, position = $t.position(),
				_top = position.top, _bottom = _top + $t.height(), _left = position.left, _right = _left + $t.width(), compareTop = partial === true ? _bottom : _top,
				compareBottom = partial === true ? _top : _bottom, compareLeft = partial === true ? _right : _left, compareRight = partial === true ? _left : _right;
			
			if (direction === 'both') return !!clientSize && ((compareBottom <= viewBottom) && (compareTop >= viewTop)) && ((compareRight <= viewRight) && (compareLeft >= viewLeft)); else if (direction === 'vertical') return !!clientSize && ((compareBottom <= viewBottom) && (compareTop >= viewTop)); else if (direction === 'horizontal') return !!clientSize && ((compareRight <= viewRight) && (compareLeft >= viewLeft));
		}
	};
	
	$.fn.extend({
		ideapark_button: function (option, size, ignore_size) {
			return this.each(function () {
				var $this = $(this);
				if ((typeof size === 'undefined') || !size) {
					size = '1em';
				} else if (size.toString().indexOf('px') !== -1) {
					size += 'px';
				}
				if (option === 'loading' && !$this.hasClass('js-loading')) {
					$this.data('button', $this.html());
					if (!ignore_size) {
						$this.data('css-width', $this.css('width'));
						$this.data('css-height', $this.css('height'));
					} else {
						$this.data('ignore-size', $this.css('width'));
					}
					$this.css('height', $this.outerHeight());
					$this.css('width', $this.outerWidth());
					$this.css('max-width', $this.outerWidth());
					var $loader = $('<i class="h-loading"></i>');
					$loader.css({
						width: size, height: size,
					});
					$this.html($loader);
					$this.addClass('h-after-before-hide js-loading');
				} else if (option === 'reset' && $this.hasClass('js-loading')) {
					var css_width = $this.data('css-width');
					var css_height = $this.data('css-height');
					var content = $this.data('button');
					ignore_size = ignore_size || $this.data('ignore-size');
					$this.data('button', '');
					$this.data('css-width', '');
					$this.data('css-height', '');
					$this.data('ignore-size', '');
					$this.html(content);
					$this.removeClass('h-after-before-hide js-loading');
					if (!ignore_size) {
						$this.css('max-width', css_width);
						$this.css('width', css_width);
						$this.css('height', css_height);
					} else {
						$this.css('max-width', '');
						$this.css('width', '');
						$this.css('height', '');
					}
				}
			});
		}
	});
	
	$.parseParams = function (query) {
		var re = /([^&=]+)=?([^&]*)/g;
		var decodeRE = /\+/g;
		var decode = function (str) {
			return decodeURIComponent(str.replace(decodeRE, " "));
		};
		var params = {}, e;
		while (e = re.exec(query)) {// jshint ignore:line
			var k = decode(e[1]), v = decode(e[2]);
			if (k.substring(k.length - 2) === '[]') {
				k = k.substring(0, k.length - 2);
				(params[k] || (params[k] = [])).push(v);
			} else params[k] = v;
		}
		return params;
	};
	
})(jQuery, window);

