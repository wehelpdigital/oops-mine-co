(function ($, root, undefined) {
	$(document).ready(function ($) {
		
		/***** Colour picker *****/
		
		$('.color-picker').each(function () {
			$(this).wpColorPicker();
		});
		
		/***** Uploading images *****/
		
		var file_frame;
		
		jQuery.fn.uploadMediaFile = function (button, preview_media) {
			var button_id = button.attr('id');
			var field_id = button_id.replace('_button', '');
			var preview_id = button_id.replace('_button', '_preview');
			
			// If the media frame already exists, reopen it.
			if (file_frame) {
				file_frame.open();
				return;
			}
			
			// Create the media frame.
			file_frame = wp.media.frames.file_frame = wp.media({
				title   : $(this).data('uploader_title'),
				button  : {
					text: $(this).data('uploader_button_text'),
				},
				multiple: false
			});
			
			// When an image is selected, run a callback.
			file_frame.on('select', function () {
				attachment = file_frame.state().get('selection').first().toJSON();
				$("#" + field_id).val(attachment.id);
				if (preview_media) {
					$("#" + preview_id).attr('src', attachment.sizes.thumbnail.url);
				}
				file_frame = false;
			});
			
			// Finally, open the modal
			file_frame.open();
		}
		
		$('.image_upload_button').on('click', function () {
			jQuery.fn.uploadMediaFile($(this), true);
		});
		
		$('.image_delete_button').on('click', function () {
			$(this).closest('td').find('.image_data_field').val('');
			$(this).closest('td').find('.image_preview').remove();
			return false;
		});
	});
	
	root.ideaparkSelectWithIcons = function (state) {
		if (!state.id) {
			return state.text;
		}
		var $state = $(
			'<span><i class="select2-results__option-icon ' + state.id + '"></i> ' + state.text + '</span>'
		);
		return $state;
	};
	
	function update() {
		var $this = $(this),
			options = $this.data('options');
		
		if (typeof options.templateResult !== 'undefined' && options.templateResult === "ideaparkSelectWithIcons") {
			options.templateResult = ideaparkSelectWithIcons;
			options.templateSelection = ideaparkSelectWithIcons;
			$this.data('options', options);
		}
	}
	
	$('.rwmb-select_advanced').each(update);
	
	function filter_widget_cond_fields($widget) {
		let $select = $('.js-filter-attribute', $widget);
		if ($select.length) {
			let types = $('.js-filter-field-type', $widget).data('value');
			let $field_image_size = $('.js-filter-field-image-size', $widget).closest('p');
			let $field_layout = $('.js-filter-field-layout', $widget).closest('p');
			let type = types[ $select.val() ];
			$field_image_size.hide();
			$field_layout.hide();
			switch(type) {
				case 'image':
					$field_image_size.show();
					$field_layout.show();
					break;
				case 'color':
					$field_layout.show();
					break;
			}
		}
	}
	
	$('.js-filter-attribute').each(function(){
		let $widget = $(this).closest('.widget');
		filter_widget_cond_fields($widget);
	});
	
	jQuery(document).on('widget-added widget-updated', function (event, widget) {
		filter_widget_cond_fields($(widget));
	});
	
	$(document.body).on('change', '.js-filter-attribute', function (){
		let $widget = $(this).closest('.widget');
		filter_widget_cond_fields($widget);
	});
})(jQuery, this);