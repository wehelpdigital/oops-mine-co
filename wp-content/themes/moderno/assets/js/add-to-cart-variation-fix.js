(function ($, root, undefined) {
	"use strict";
	/**
	 * Sets product images for the chosen variation
	 */
	var
		ideapark_variation_gallery_cache = {},
		ideapark_variation_default_slider = null,
		ideapark_variation_default_thumbs = null,
		ideapark_variation_gallery_loaded = false,
		ideapark_variation_gallery_timer = null;
	
	$.fn.wc_variations_image_update = function (variation) {
		var $form = this;
		IdeaparkQueue.enqueue(() => ideapark_variations_image_update($form, variation));
	};
	
	$(document.body).trigger('ideapark-variations-init');
	
	var ideapark_variations_image_update = function ($form, variation) {
		return new Promise((resolve, reject) => {
			
			if (variation && variation.has_variation_gallery_images) {
				if (typeof ideapark_variation_gallery_cache[variation.variation_id] !== 'undefined') {
					ideapark_switch_variation_gallery(ideapark_variation_gallery_cache[variation.variation_id]);
					resolve();
				} else {
					$.ajax({
						url    : ideapark_wp_vars.ajaxUrl,
						type   : 'POST',
						data   : {
							action      : 'ideapark_variation_images',
							variation_id: variation.variation_id,
							is_quickview: $form.closest('.product').hasClass('c-product--quick-view') ? 1 : 0,
						},
						success: function (html) {
							ideapark_variation_gallery_cache[variation.variation_id] = html;
							ideapark_switch_variation_gallery(html);
							resolve();
						}
					});
				}
			} else {
				var f = function () {
					var $product = $form.closest('.product'),
						$product_gallery = $product.find('.c-product__slider'),
						$product_img_wrap = $product_gallery.find('.c-product__slider-item').first(),
						$product_img = $product_img_wrap.find('.c-product__slider-img'),
						$product_link = $product_img_wrap.find('a').first(),
						$product_zoom = $product_img_wrap.find('.js-product-zoom'),
						$gallery_img = $product.find('.c-product__thumbs-img').first();
					
					if (ideapark_variation_default_slider === null) {
						ideapark_variation_default_slider = $("<div />").append($product_gallery.clone()).html();
					}
					
					if (variation && variation.image && variation.image.src && variation.image.src.length > 1) {
						$product_gallery.data('featured', true);
						
						$product_img.wc_set_variation_attr('src', variation.image.src);
						$product_img.wc_set_variation_attr('height', variation.image.src_h);
						$product_img.wc_set_variation_attr('width', variation.image.src_w);
						$product_img.wc_set_variation_attr('srcset', variation.image.srcset);
						$product_img.wc_set_variation_attr('sizes', variation.image.sizes);
						$product_img.wc_set_variation_attr('title', variation.image.title);
						$product_img.wc_set_variation_attr('alt', variation.image.alt);
						$product_img.wc_set_variation_attr('data-src', variation.image.full_src);
						$product_img.wc_set_variation_attr('data-large_image', variation.image.full_src);
						$product_img.wc_set_variation_attr('data-large_image_width', variation.image.full_src_w);
						$product_img.wc_set_variation_attr('data-large_image_height', variation.image.full_src_h);
						$product_link.wc_set_variation_attr('href', variation.image.full_src);
						$product_img_wrap.wc_set_variation_attr('data-thumb', variation.image.src);
						if ($product_zoom.length) {
							var old_img = $product_zoom.data('img');
							$product_zoom.wc_set_variation_attr('data-img', variation.image.full_src);
							$product_zoom.data('img', $product_zoom.attr('data-img'));
							if (old_img != $product_zoom.data('img')) {
								$product_zoom.removeClass('init').trigger('zoom.destroy');
								ideapark_init_zoom();
							}
						}
						
						$gallery_img.wc_set_variation_attr('srcset', variation.image.srcset);
						$gallery_img.wc_set_variation_attr('src', variation.image.gallery_thumbnail_src);
						
						var $carousel = $('.js-single-product-carousel.owl-loaded');
						if ($carousel.length === 1) {
							$carousel.trigger('to.owl.carousel', [0, 100]);
						}
					} else {
						$product_img.wc_reset_variation_attr('src');
						$product_img.wc_reset_variation_attr('width');
						$product_img.wc_reset_variation_attr('height');
						$product_img.wc_reset_variation_attr('srcset');
						$product_img.wc_reset_variation_attr('sizes');
						$product_img.wc_reset_variation_attr('title');
						$product_img.wc_reset_variation_attr('data-caption');
						$product_img.wc_reset_variation_attr('alt');
						$product_img.wc_reset_variation_attr('data-src');
						$product_img.wc_reset_variation_attr('data-large_image');
						$product_img.wc_reset_variation_attr('data-large_image_width');
						$product_img.wc_reset_variation_attr('data-large_image_height');
						$product_img_wrap.wc_reset_variation_attr('data-thumb');
						if ($product_zoom.length) {
							$product_zoom.wc_reset_variation_attr('data-img');
							$product_zoom.data('img', $product_zoom.attr('data-img'));
							$product_zoom.removeClass('init').trigger('zoom.destroy');
							ideapark_init_zoom();
						}
						$product_link.wc_reset_variation_attr('href');
						$gallery_img.wc_reset_variation_attr('src');
						$gallery_img.wc_reset_variation_attr('srcset');
					}
					
					resolve();
				};
				
				if (ideapark_variation_gallery_loaded) {
					ideapark_switch_variation_gallery('', f);
				} else {
					f();
				}
			}
			
			window.setTimeout(function () {
				$(window).trigger('resize');
			}, 20);
		});
	};
	
	var ideapark_switch_variation_gallery = function (html, callback) {
		var is_switch_to_default = false;
		
		if (html === '' && ideapark_variation_default_slider) {
			is_switch_to_default = true;
			html = ideapark_variation_default_slider;
			if (ideapark_variation_default_thumbs) {
				html += ideapark_variation_default_thumbs;
			}
		}
		
		if (!html) {
			if (typeof callback === 'function') {
				callback();
			}
			return;
		}
		
		var $slider = $('.c-product__slider'), $thumbs = $('.c-product__thumbs'),
			$gallery = $('.c-product__gallery');
		
		var hash = $slider.data('hash');
		var result = html.match(/data-hash="([a-f0-9]*)"/);
		if (hash && result !== null) {
			var hash_new = Array.from(result)[1];
			
			if (hash == hash_new && !$slider.data('featured')) {
				if (typeof callback === 'function') {
					callback();
				}
				return;
			}
			$slider.data('featured', false);
		}
		
		$slider.addClass('h-fade--out');
		$thumbs.addClass('h-fade--out');
		
		var $new = $("<div class='h-hidden' />").append(html);
		$new.find('.c-play--disabled').removeClass('c-play--disabled');
		$new.find('.h-fade').addClass('h-fade--out');
		$new.find('.c-product__thumbs-item').first().addClass('active');
		
		if (ideapark_variation_gallery_timer !== null) {
			clearTimeout(ideapark_variation_gallery_timer);
			ideapark_variation_gallery_timer = null;
		}
		
		ideapark_variation_gallery_timer = setTimeout(function () {
			if ($slider.length) {
				if ($slider.hasClass('owl-carousel')) {
					$slider
						.removeClass('owl-carousel')
						.trigger("destroy.owl.carousel");
				}
				$slider
					.find('.init,.active')
					.removeClass('init active');
				
				if (ideapark_variation_default_slider === null) {
					ideapark_variation_default_slider = $("<div />").append($slider.clone()).html();
				}
				$slider.remove();
			}
			
			if ($thumbs.length) {
				if ($thumbs.hasClass('owl-carousel')) {
					$thumbs
						.removeClass('owl-carousel')
						.trigger("destroy.owl.carousel");
				}
				$thumbs
					.find('.init,.active')
					.removeClass('init active');
				
				if (ideapark_variation_default_thumbs === null) {
					ideapark_variation_default_thumbs = $("<div />").append($thumbs.clone()).html();
				}
				$thumbs.closest(".c-product__thumbs-outer").remove();
			} else {
				ideapark_variation_default_thumbs = '';
			}
			
			var $slider_new = $('.c-product__slider', $new), $thumbs_new = $('.c-product__thumbs', $new);
			
			if ($slider_new.length) {
				$slider_new.detach().appendTo($gallery);
				ideapark_init_product_carousel();
				ideapark_init_zoom();
				$slider_new.removeClass('h-fade--out');
			}
			
			if ($thumbs_new.length) {
				$gallery.append('<div class="c-product__thumbs-outer"></div>');
				$thumbs_new.detach().appendTo($gallery.find(".c-product__thumbs-outer"));
				ideapark_init_product_thumbs_carousel();
				$thumbs_new.removeClass('h-fade--out');
			}
			
			$new.remove();
			
			ideapark_variation_gallery_loaded = !is_switch_to_default;
			
			if (typeof callback === 'function') {
				callback();
			}
			ideapark_variation_gallery_timer = null;
		}, 300);
	};
})(jQuery, this);