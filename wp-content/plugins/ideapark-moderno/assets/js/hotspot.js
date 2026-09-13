(function ($) {
	"use strict";
	$(function () {
		var $ideapark_hotspot_data_field = null;
		var $ideapark_hotspot_data = null;
		$(window).on('resize', ideapark_hotspot_debounce(function () {
			ideapark_hotspot_show_all();
		}, 500));
		$(document)
			.on('click', '.js-ideapark-edit-hotspot', function () {
				$('.js-hotspot-defer').each(function(){
					var $this = $(this);
					var a = JSON.parse($this.val());
					$('body').append(a.code);
					$this.remove();
				});
				var $this = $(this);
				var $repeater = $this.closest('.elementor-repeater-row-controls');
				var $image = $repeater.find('.elementor-control-' + $this.data('control') + ' .elementor-control-media__preview');
				var image = $image.css('background-image').slice(4, -1).replace(/"/g, "");
				$ideapark_hotspot_data_field = null;
				$ideapark_hotspot_data = null;
				if (image && $('.ideapark-hotspot').length === 0) {
					
					$('body').append('<div class="ideapark-hotspot"><div class="ideapark-hotspot__wrap js-ideapark-hotspot-wrap"><a class="ideapark-hotspot__close js-ideapark-close-hotspot dashicons dashicons-no-alt" href="#" onclick="return false;"></a><img alt="" src="" class="ideapark-hotspot__image js-ideapark-hotspot-image"></div></div>');
					
					$(".js-ideapark-hotspot-image").one("load", function () {
						ideapark_hotspot_show_all();
					}).attr('src', image);
					
					$ideapark_hotspot_data_field = $repeater.find('.js-ideapark-hotspot-data');
					var val = $ideapark_hotspot_data_field.val();
					if (val != '') {
						$ideapark_hotspot_data = JSON.parse(val);
					} else {
						$ideapark_hotspot_data = [];
					}
				}
			})
			.on('click', '.js-ideapark-close-hotspot', function () {
				var $this = $(this);
				var $popup = $this.closest('.ideapark-hotspot');
				$popup.remove();
				$ideapark_hotspot_data_field = null;
				$ideapark_hotspot_data = null;
			})
			.on('click', '.js-ideapark-hotspot-image', function (e) {
				if ($ideapark_hotspot_data !== null) {
					var $this = $(this);
					var width = $this.width();
					var height = $this.height();
					var x = e.pageX - $this.offset().left;
					var y = e.pageY - $this.offset().top;
					ideapark_hotspot_search('', '', function (product_id, title) {
						if (product_id != null && product_id != '') {
							$ideapark_hotspot_data.push({
								x         : x / width * 100,
								y         : y / height * 100,
								title     : title,
								product_id: product_id
							});
							var index = $ideapark_hotspot_data.length - 1;
							ideapark_hotspot_show_spot($ideapark_hotspot_data[index], index);
							$ideapark_hotspot_data_field.val(JSON.stringify($ideapark_hotspot_data)).trigger('input');
						}
					}, x, y);
				}
			});
		
		function ideapark_hotspot_search(product_id, title, callback, left, top) {
			var $wrap = $('.js-ideapark-hotspot-wrap');
			if ($('.ideapark-hotspot__modal').length) {
				$wrap.trigger('ideapark-search-close');
			}
			var dialog = '<div class="ideapark-hotspot__modal"><select class="wc-product-search js-ideapark-hotspot-product" data-allow_clear="true" data-display_stock="true"  data-placeholder="Search for a product&hellip;"><option value="' + product_id + '">' + title + '</option></select><a href="" onclick="return false;" class="ideapark-hotspot__search-close dashicons dashicons-no-alt js-search-close"></a></div>';
			$wrap.append(dialog);
			var $select = $wrap.find('.js-ideapark-hotspot-product');
			
			if (typeof left !== 'undefined' && typeof top !== 'undefined') {
				$wrap.find('.ideapark-hotspot__modal').css({
					left: left + 'px',
					top : top + 'px'
				});
			}
			var close_f = function () {
				var product_id = $select.val();
				var title = $select.find('option:selected').text();
				$('.js-ideapark-hotspot-wrap').find('.ideapark-hotspot__modal').remove();
				callback(product_id, title);
			};
			$wrap.on('ideapark-search-close', close_f);
			$wrap.find('.js-search-close').on('click', close_f);
			$select.on('change', close_f);
			$(document.body).trigger('wc-enhanced-select-init');
		}
		
		function ideapark_hotspot_show_all() {
			if ($ideapark_hotspot_data !== null) {
				$('.js-ideapark-hotspot-point').remove();
				$ideapark_hotspot_data.forEach(function (item, i, arr) {
					ideapark_hotspot_show_spot(item, i);
				});
			}
		}
		
		function ideapark_hotspot_debounce(func, wait, immediate) {
			var timeout;
			return function () {
				var context = this, args = arguments;
				var later = function () {
					timeout = null;
					if (!immediate) func.apply(context, args);
				};
				var callNow = immediate && !timeout;
				clearTimeout(timeout);
				timeout = setTimeout(later, wait);
				if (callNow) func.apply(context, args);
			};
		}
		
		function ideapark_hotspot_show_spot(point, index) {
			var $wrap = $('.js-ideapark-hotspot-wrap');
			var $image = $wrap.find('.js-ideapark-hotspot-image');
			var left = Math.round(point.x * $image.width() / 100);
			var top = Math.round(point.y * $image.height() / 100);
			if ($wrap.length == 1) {
				var $point = $('<span data-index="' + index + '" class="ideapark-hotspot__point js-ideapark-hotspot-point" style="left: ' + left + 'px;top: ' + top + 'px;"><a href="#" onclick="return false;" class="ideapark-hotspot__point-edit dashicons dashicons-edit js-point-edit"></a><a href="#" onclick="return false;" class="ideapark-hotspot__point-close dashicons dashicons-no-alt js-point-close"></a></span>');
				$point.attr('data-title', point.title);
				var $edit = $point.find('.js-point-edit');
				var $close = $point.find('.js-point-close');
				$edit.data('index', index).on('click', function () {
					var $this = $(this);
					var index = $this.data('index');
					var point = $ideapark_hotspot_data[index];
					
					ideapark_hotspot_search(point.product_id, point.title, function (product_id, title) {
						if (product_id != null) {
							var $point = $this.closest('.js-ideapark-hotspot-point');
							if (product_id !== "") {
								point.product_id = product_id;
								point.title = title;
								$ideapark_hotspot_data[index] = point;
								$point.attr('data-title', title);
								$ideapark_hotspot_data_field.val(JSON.stringify($ideapark_hotspot_data)).trigger('input');
							} else {
								$point.find('.js-point-close').trigger('click');
							}
						}
					}, left, top);
					
				});
				$close.data('index', index).on('click', function () {
					$ideapark_hotspot_data.splice($(this).data('index'), 1);
					$ideapark_hotspot_data_field.val(JSON.stringify($ideapark_hotspot_data)).trigger('input');
					ideapark_hotspot_show_all();
				});
				
				$wrap.append($point);
			}
		}
	});
	
})(jQuery);
