/**
 * Creating WordPress media upload menu for selecting theme images.
 * @author ideapark.kz
 * @version 1.0.0
 */

(function ($) {
	wp.media.ideaparkMediaManager = {
		init: function () {
			var controllerName = '';

			this.frame = wp.media.frames.ideaparkMediaManager = wp.media({
				title   : 'Select or Upload Image',
				library : {
					type: 'image'
				},
				button  : {
					text: 'Choose Image'
				},
				multiple: false
			});
			this.frame.on('select', function () {
				var attachment = wp.media.ideaparkMediaManager.frame.state().get('selection').first();
				var myurl = typeof (attachment.attributes.sizes.thumbnail) != 'undefined' ? attachment.attributes.sizes.thumbnail.url : attachment.attributes.sizes.full.url;
				var mypid = attachment.attributes.id;
				var $parent = wp.media.ideaparkMediaManager.$el.parent();

				$('#' + controllerName).val(mypid).trigger('change');
				$('.ideapark-media-manager-target', $parent).html('<img src="' + myurl + '" />');
				$('.ideapark-media-manager-remove', $parent).show();
			});
			this.frame.on('open', function () {
				var selection = wp.media.frames.ideaparkMediaManager.state().get('selection');
				var selected = $('#' + controllerName).val();
				if (selected) {
					selection.add(wp.media.attachment(selected));
				}
			});
			$(document).on('click', '.ideapark-media-manager-link', function (event) {
				wp.media.ideaparkMediaManager.$el = $(this);
				controllerName = $(this).data('controller');
				event.preventDefault();
				wp.media.ideaparkMediaManager.frame.open();
			});

			$(document).on('click', '.ideapark-media-manager-remove', function (event) {
				$(this).parent().find('.ideapark-media-manager-target').html('');
				$('#' + $(this).data('controller')).val(0).trigger('change');
				$(this).hide();
				event.preventDefault();
			});
		}
	};
	wp.media.ideaparkMediaManager.init();

}(jQuery));