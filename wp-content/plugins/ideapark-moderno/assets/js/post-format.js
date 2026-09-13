(function ($) {
	var ideapark_post_format_try = 10;
	function ideapark_post_format() {
		ideapark_post_format_try --;
		var format = $('.editor-post-format select').val();
		
		if (typeof format === 'undefined') {
			format = $("input[name='post_format']:checked").val();
		}
		
		if (typeof format === 'undefined') {
			format = $("input.components-radio-control__input:checked").val();
		}
		
		if (typeof format === 'undefined') {
			format = ideapark_post_format_vars.post_format;
		}
		
		if (typeof format === 'undefined' && ideapark_post_format_try > 0) {
			setTimeout(ideapark_post_format, 500);
			return;
		}
		
		$('#image-gallery,#youtube-video-url,#author-of-the-quote').removeClass('active');
		if (format == 'gallery') {
			$('#image-gallery').addClass('active');
		} else if (format == 'video') {
			$('#youtube-video-url').addClass('active');
		} else if (format == 'quote') {
			$('#author-of-the-quote').addClass('active');
		}
	}
	
	$(document).ready(function () {
		ideapark_post_format();
		$(document).on('change', '.editor-post-format select,.post-format,.components-radio-control__input', ideapark_post_format);
	});
})(jQuery);