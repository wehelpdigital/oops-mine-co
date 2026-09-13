(function ($) {
	"use strict";
	var product_gallery_frame;
	
	var ideapark_variation_init = function () {
		$(document).off('click', '.ideapark-variation-add');
		$(document).off('click', '.ideapark-variation-remove');
		
		$(document).on('click', '.ideapark-variation-add', ideapark_variation_add);
		$(document).on('click', '.ideapark-variation-remove', ideapark_variation_remove);
		
		$('.woocommerce_variation').each(function () {
			var optionsWrapper = $(this).find('.options:first');
			var galleryWrapper = $(this).find('.ideapark-variation-gallery-wrapper');
			
			galleryWrapper.insertBefore(optionsWrapper);
		});
		
		ideapark_variation_sortable();
	};
	
	var ideapark_variation_sortable = function () {
		$('.ideapark-variation-gallery-images').sortable({
			items               : '.ideapark-variation-gallery-image',
			cursor              : 'move',
			scrollSensitivity   : 40,
			forcePlaceholderSize: true,
			forceHelperSize     : false,
			helper              : 'clone',
			opacity             : 0.65,
			placeholder         : 'ideapark-variation-gallery-image ideapark-variation-placeholder',
			
			start : function start(event, ui) {
				ui.item.css('background-color', '#F6F6F6');
			},
			stop  : function stop(event, ui) {
				ui.item.removeAttr('style');
			},
			update: function update() {
				ideapark_variation_changed($(this));
			}
		});
	};
	
	var ideapark_variation_changed = function ($el) {
		$el.closest('.woocommerce_variation').addClass('variation-needs-update');
		$('button.cancel-variation-changes, button.save-variation-changes').removeAttr('disabled');
		$('#variable_product_options').trigger('woocommerce_variations_input_changed');
		
		$el.closest('.dokan-product-variation-items').addClass('variation-needs-update');
		$('.dokan-product-variation-wrapper').trigger('dokan_variations_input_changed');
	}
	
	var $ideapark_variation_el;
	var ideapark_product_variation_id;
	var ideapark_product_variation_loop;
	
	var ideapark_variation_add = function (event) {
		event.preventDefault();
		
		$ideapark_variation_el = $(this);
		ideapark_product_variation_id = $ideapark_variation_el.data('product_variation_id');
		ideapark_product_variation_loop = $ideapark_variation_el.data('product_variation_loop');
		
		var $feature_image = $ideapark_variation_el.closest('.data').find('.upload_image_id');
		
		if ($feature_image.length) {
			if (!parseInt($feature_image.val())) {
				alert(ideapark_variation_vars.main_image);
				return;
			}
		}
		
		if (product_gallery_frame) {
			product_gallery_frame.open();
			return;
		}
		
		// Create the media frame.
		product_gallery_frame = wp.media.frames.product_gallery = wp.media({
			// Set the title of the modal.
			title : ideapark_variation_vars.choose_image,
			button: {
				text: ideapark_variation_vars.add_image
			},
			states: [
				new wp.media.controller.Library({
					title     : ideapark_variation_vars.choose_image,
					filterable: 'all',
					multiple  : true
				})
			]
		});
		
		// When an image is selected, run a callback.
		product_gallery_frame.on('select', function () {
			var images = product_gallery_frame.state().get('selection').toJSON();
			
			var html = images.map(function (image) {
				if (image.type === 'image') {
					var id = image.id,
						_image$sizes = image.sizes;
					_image$sizes = _image$sizes === undefined ? {} : _image$sizes;
					var thumbnail = _image$sizes.thumbnail,
						full = _image$sizes.full;
					
					var url = thumbnail ? thumbnail.url : full.url;
					var template = wp.template('ideapark-variation-gallery-image');
					return template({id: id, url: url, product_variation_id: ideapark_product_variation_id, loop: ideapark_product_variation_loop});
				}
			}).join('');
			
			$ideapark_variation_el.closest('.ideapark-variation-gallery-wrapper').find('.ideapark-variation-gallery-images').append(html);
			
			ideapark_variation_sortable();
			ideapark_variation_changed($ideapark_variation_el);
		});
		
		// Finally, open the modal.
		product_gallery_frame.open();
	};
	var ideapark_variation_remove = function (event) {
		var $el = $(this);
		event.preventDefault();
		ideapark_variation_changed($el);
		$el.closest('.ideapark-variation-gallery-image').remove();
		ideapark_variation_sortable();
	};
	$('#woocommerce-product-data').on('woocommerce_variations_loaded', ideapark_variation_init);
	$('#variable_product_options').on('woocommerce_variations_added', ideapark_variation_init);
	$('.dokan-product-variation-wrapper').on('dokan_variations_loaded dokan_variations_added', ideapark_variation_init);
})(jQuery);