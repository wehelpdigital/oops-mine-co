(function ($) {
	
	'use strict';
	
	window.IP_Wishlist = {
		
		init_product_button: function () {
			if ($.fn.cookie || typeof (Cookies) !== 'undefined') {
				
				var wishlistCookie = $.fn.cookie ? $.cookie(ideapark_wp_vars.wishlistCookieName) : Cookies.get(ideapark_wp_vars.wishlistCookieName);
				if (wishlistCookie) {
					wishlistCookie = JSON.parse(wishlistCookie);
					
					for (var id in wishlistCookie) {
						if (wishlistCookie.hasOwnProperty(id)) {
							$('.c-wishlist__item-' + wishlistCookie[id] + '-btn').addClass('c-wishlist__btn--added');
						}
					}
				}
			}
		},
		
		init: function () {
			var wishlistAjax = false;
			
			this.init_product_button();
			
			$(document).on('click', '.js-wishlist-btn', function (e) {
				e.preventDefault();
				
				if (wishlistAjax) {
					return;
				}
				
				var $button = $(this),
					productId = $(this).data('product-id'),
					$buttons = $('.c-wishlist__item-' + productId + '-btn');
				
				$buttons.removeClass('c-wishlist__btn--added');
				
				var size = $button.data('size');
				if (size) {
					size = parseInt(size);
				} else {
					size = '';
				}
				$button.ideapark_button('loading', size, true);
				
				wishlistAjax = $.ajax({
					type    : 'POST',
					url     : ideapark_wp_vars.ajaxUrl,
					data    : {
						action    : 'ideapark_wishlist_toggle',
						product_id: productId
					},
					dataType: 'json',
					cache   : false,
					headers : {'cache-control': 'no-cache'},
					complete: function () {
						wishlistAjax = false;
					},
					success : function (json) {
						$(document.body).trigger('wc_fragment_refresh');
						
						$button.ideapark_button('reset');
						
						if (json.status === '1') {
							$('body').trigger('wishlist_added_item');
							$buttons.attr('title', ideapark_wp_vars.wishlistTitleRemove);
							$buttons.addClass('c-wishlist__btn--added');
						} else {
							$('body').trigger('wishlist_removed_item');
							$buttons.attr('title', ideapark_wp_vars.wishlistTitleAdd);
						}
					}
				});
			});
			
			
			var $wishlistTable = $('.js-wishlist-table');
			
			
			if ($wishlistTable.length) {
				
				var _wishlistRemoveItem = function ($this) {
					var $thisTr = $this.closest('tr'),
						productId = $thisTr.data('product-id');
					
					$thisTr.addClass('loading');
					
					$.ajax({
						type    : 'POST',
						url     : ideapark_wp_vars.ajaxUrl,
						data    : {
							action    : 'ideapark_wishlist_toggle',
							product_id: productId
						},
						dataType: 'json',
						cache   : false,
						headers : {'cache-control': 'no-cache'},
						success : function (json) {
							$(document.body).trigger('wc_fragment_refresh');
							
							var $share_link = $('#ip-wishlist-share-link');
							$('body').trigger('wishlist_removed_item');
							if ($share_link.length === 1 && typeof json.share_link !== 'undefined') {
								$share_link.val(json.share_link);
							}
							if (json.count > 0) {
							} else {
								$('.js-wishlist').css('display', 'none');
								$('.js-wishlist-empty').removeClass('h-hidden');
							}
							$thisTr.fadeOut(150, function () {
								$(this).remove();
							});
						}
						
					}).fail(function () {
						$thisTr.removeClass('loading');
					});
					
				};
				
				
				$wishlistTable.on('click', '.js-wishlist-remove', function (e) {
					e.preventDefault();
					
					var $this = $(this);
					
					if ($this.hasClass('clicked')) {
						return;
					}
					
					$this.addClass('clicked');
					
					_wishlistRemoveItem($this);
				});
			}
			
			$('.js-wishlist-fix').each(function (){
				var productId = $(this).val();
				$.ajax({
					type    : 'POST',
					url     : ideapark_wp_vars.ajaxUrl,
					data    : {
						action    : 'ideapark_wishlist_toggle',
						product_id: productId
					},
					dataType: 'json',
					cache   : false,
					headers : {'cache-control': 'no-cache'},
					success : function (json) {
						$(document.body).trigger('wc_fragment_refresh');
					}
				});
			});
		}
	};
	
	$(document).ready(function () {
		IP_Wishlist.init();
	});
	
})(jQuery);
