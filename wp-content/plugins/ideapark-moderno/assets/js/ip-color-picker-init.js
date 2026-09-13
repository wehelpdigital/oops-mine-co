
(function($) {
	
	'use strict';
	
	function initColorPicker(widget) {
		widget.find('.ip-widget-color-picker').wpColorPicker();
		widget.find('.ip-widget-attributes-list .wp-color-result').each(function(){
			$(this).attr('data-name', $(this).closest('li').data('name'));
		});
	}
	
	function onFormUpdate(event, widget) {
		initColorPicker(widget);
	}
	
	var $document = $(document);
	
	$document.on('widget-added widget-updated', onFormUpdate);
	
	$document.ready(function() {
		$('.widget:has(.ip-widget-color-picker)').each(function() {
			initColorPicker($(this));
		});
	});
} (jQuery));
