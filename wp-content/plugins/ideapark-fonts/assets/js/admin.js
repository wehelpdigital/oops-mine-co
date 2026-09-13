(function ($, root, undefined) {
	"use strict";
	
	$('.ideapark-fonts__item-view').on('click', function () {
		var $this = $(this);
		var $wrap = $this.closest('.ideapark-fonts__item').find('.ideapark-fonts__wrap');
		$wrap.slideToggle(500, function () {
			$this.toggleClass('dashicons-arrow-down-alt2').toggleClass('dashicons-arrow-up-alt2');
		});
	});
	
	$('.js-ideapark-fonts-font').on('click', function (e) {
		e.stopPropagation();
		e.preventDefault();
		$(this).closest('.ideapark-fonts__item').find('.ideapark-fonts__item-view').trigger('click');
	});
	
	$('.ideapark-fonts__expand-all').on('click', function () {
		$('.ideapark-fonts__item-view.dashicons-arrow-down-alt2').trigger('click');
	});
	
	$('.ideapark-fonts__collapse-all').on('click', function () {
		$('.ideapark-fonts__item-view.dashicons-arrow-up-alt2').trigger('click');
	});
	
	$('.js-ideapark-fonts-icon').on('click', function (e) {
		var $this = $(this);
		e.stopPropagation();
		e.preventDefault();
		$('.ideapark-fonts__code').html($this.data('class'));
		$('.ideapark-fonts__popup-img').attr('class', 'ideapark-fonts__popup-img').addClass($this.data('class'));
		$('.ideapark-fonts__popup').addClass('ideapark-fonts__popup--active');
	});
	
	$('.ideapark-fonts__popup-close').on('click', function () {
		$('.ideapark-fonts__popup--active').removeClass('ideapark-fonts__popup--active');
	});
	
})(jQuery, this);
