(function ($, document, i18n, rwmb) {
	"use strict";
	
	function showSuccessMessage() {
		$('#addtag p.submit').before('<div id="mb-term-meta-message" class="notice notice-success"><p><strong>' + i18n.addedMessage + '</strong></p></div>');
		
		setTimeout(function () {
			$('#mb-term-meta-message').fadeOut();
		}, 2000);
	}
	
	/*
	 * Clear inputs value when added term.
	 */
	$(document).on('ajaxSuccess', function (e, request, settings) {
		if (settings.data.indexOf('action=add-tag') < 0) {
			return;
		}
		
		$('.rwmb-label .rwmb-required').not(':first').remove();
		
		if (request.responseText && request.responseText.indexOf('wp_error') === -1) {
			
			// TinyMCE.
			if (typeof tinyMCE !== 'undefined') {
				tinyMCE.activeEditor.setContent('');
			}
			
			$('.rwmb-range + .rwmb-output').text('');
			$('.rwmb-image_advanced').trigger('media:reset');
			$('.rwmb-media-list').html('');
			$('.rwmb-color').val('');
			$('.rwmb-input .wp-color-result').css('background-color', '');
			$('.rwmb-meta-box :input:checkbox, .rwmb-meta-box :input:radio').prop('checked', false);
			$('.rwmb-image-select').removeClass('rwmb-active');
			$('.rwmb-clone:not(:first-of-type)').remove();
			$('.rwmb-form').trigger("reset");
			$('.rwmb-select_advanced').trigger('change');
			$('input[value!=""]').each(function() {
				$(this).val($(this).attr('value'));
			});
			$('input[type="radio"][checked]').prop('checked', true);
			$('input[type="checkbox"][checked]').prop('checked', true);
			if (typeof rwmb.runConditionalLogic == 'function') {
				rwmb.runConditionalLogic();
			}
		}
		
		$('html, body').animate({scrollTop: 0}, 800);
		
	});
	
	function makeEditorsSave() {
		if (typeof tinyMCE === 'undefined') {
			return;
		}
		var editors = tinyMCE.editors;
		
		for (var i in editors) {
			editors[i].on('change', editors[i].save);
		}
	}
	
	$(function () {
		var $form = $('#addtag.rwmb-form');
		if ($form.length) {
			$form.on('submit', function (e) {
				e.preventDefault();
			})
			var $submit = $('#submit');
			$submit.hide();
			$submit.after('<button type="button" id="submit-rwmb" class="button button-primary">' + $submit.attr('value') + '</button>');
			var $submit_rwmb = $('#submit-rwmb');
			$submit_rwmb.on('click', function (e) {
				if ($form.data('validator').settings) {
					$form.data('validator').settings.submitHandler = function () {
						$('#rwmb-validation-message').remove();
						$submit.trigger('click');
					};
					$form.trigger('submit');
				}
			});
			setTimeout(makeEditorsSave, 500);
		}
	});
	
})(jQuery, document, MBTermMeta, rwmb);
