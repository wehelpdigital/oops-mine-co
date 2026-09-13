(function ($, root, undefined) {
	
	$(function () {
		"use strict";
		
		var importing = false;
		var request_cnt = 0;
		
		var ideapark_import_demo_check_options = function () {
			$('.ip-import-demo:checked').each(function () {
				var $this = $(this);
				if ($this.closest('.ip-demo').data('revslider') === 'yes') {
					$('.ip-rev-slider-radio').show();
				} else {
					$('.ip-rev-slider-radio').hide();
				}
			});
		};
		
		$('.ip-import-demo').on('change', ideapark_import_demo_check_options);
		
		ideapark_import_demo_check_options();
		
		var $progress = $('.ip-loading-progress');
		var $continue = $('#ip-import-continue');
		var $cancel = $('#ip-import-cancel');
		var $start = $('#ip-import-submit');
		var $export = $('#ip-export-submit');
		
		if ($continue.length) {
			ideaparkImportProgress($continue.data('percent'));
			ideaparkImportOutput('');
			$progress.addClass('importing').slideDown();
			$cancel.on('click', function (e) {
				if (!importing) {
					var data = {
						action: 'ideapark_importer',
						stage : 'cancel',
						cnt   : 0
					};
					ideaparkSendImportRequest(data);
					request_cnt = 0;
				}
				$('.ip-import-continue').remove();
				$progress.removeClass('importing paused').slideUp();
				e.preventDefault();
				return false;
			});
			$continue.on('click', function (e) {
				$progress.removeClass('paused');
				if (importing) {
					alert(ideapark_wp_vars_importer.please_wait);
					e.preventDefault();
					return false;
				}
				$('.ip-import-continue').remove();
				importing = true;
				var data = {
					action: 'ideapark_importer',
					stage : 'continue',
					cnt   : request_cnt++
				};
				ideaparkSendImportRequest(data);
				e.preventDefault();
				return false;
			});
		}
		
		$start.on('click', function (e) {
			if (importing) {
				alert(ideapark_wp_vars_importer.please_wait);
				e.preventDefault();
				return false;
			}
			
			if (confirm(ideapark_wp_vars_importer.are_you_sure)) {
				$start.attr('disabled', 'disabled');
				$progress.addClass('importing').slideDown();
				importing = true;
				var data = {
					action            : 'ideapark_importer',
					stage             : 'start',
					cnt               : request_cnt++,
					import_option     : $('#ip-import input[name=import_option]:checked').val(),
					import_attachments: $('#ip-import input[name=import_attachments]:checked').length ? 1 : 0,
					import_demo       : $('#ip-import input[name=import_demo]:checked').val()
				};
				ideaparkSendImportRequest(data);
			}
			e.preventDefault();
			return false;
		});
		
		$export.on('click', function () {
			if (!$export.is(':disabled')) {
				$export.attr('disabled', 'disabled');
				$progress.addClass('importing').slideDown();
				var data = {
					action: 'ideapark_exporter',
					stage : 'start',
					cnt   : request_cnt++
				};
				
				ideaparkImportProgress(0);
				
				ideaparkSendImportRequest(data);
				
				return false;
			}
		});
		
		function ideaparkImportOutput(message) {
			$('.ip-import-output').html(message);
		}
		
		function ideaparkImportProgress(percent) {
			$('.ip-loading-state').width(percent.toString().replace('%', '') + '%');
			$('.ip-loading-info').html(ideapark_wp_vars_importer.progress + ': ' + percent + '%');
		}
		
		function ideaparkSendImportRequest(data) {
			var orig_data = data;
			var repeat = 0;
			var f = function () {
				$.post(ajaxurl, data, function (response) {
					try {
						response = jQuery.parseJSON(response);
						if (response.code == 'completed') {
							ideaparkReturnStartState();
						} else if (response.code == 'continue') {
							var data = {
								action: orig_data['action'],
								stage : 'continue',
								cnt   : request_cnt++
								// XDEBUG_PROFILE: 1
							};
							ideaparkSendImportRequest(data);
							repeat = 0;
						} else {
							ideaparkImportOutput('<h3>' + ideapark_wp_vars_importer.output_error + ':</h3><div class="ip-import-error">' + response + '</div>');
							ideaparkReturnStartState();
						}
						ideaparkImportOutput(response.msg + (response.code == 'completed' ? '' : ' ...'));
						ideaparkImportProgress(response.percent);
						
					} catch (err) {
						if (repeat > 0) {
							ideaparkImportOutput('<h3>' + ideapark_wp_vars_importer.output_error + ':</h3><div class="ip-import-error">' + err.message + '</div>');
							ideaparkReturnStartState();
						} else {
							console.log(err);
							repeat++;
							f();
						}
					}
					
				}).fail(function (jqXHR, textStatus, errorThrown) {
					if (repeat > 0) {
						ideaparkImportOutput('<h3>' + ideapark_wp_vars_importer.output_error + ':</h3><div class="ip-import-error">' + jqXHR.statusText + '</div>' + (jqXHR.statusText == 'Not Found' ? '<div>Disable apache mod_security or configure it correctly</div>' : ''));
						ideaparkReturnStartState();
					} else {
						console.log(jqXHR);
						repeat++;
						f();
					}
				});
			}
			f();
		}
		
		function ideaparkReturnStartState() {
			importing = false;
			$export.removeAttr('disabled').show();
			$start.removeAttr('disabled').show();
			request_cnt = 0;
		}
	});
	
})(jQuery, this);