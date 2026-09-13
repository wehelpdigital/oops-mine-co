(function ($, root, undefined) {
	"use strict";
	$(document).ready(function () {
			$(document.body)
				.on('click', '.ideapark_plugins_installer_link', function (e) {
						e.preventDefault();
						var $this = $(this);
						if ($this.hasClass('process-now')) {
							return;
						}
						$this.addClass('process-now updating-message');
						
						ideapark_plugin_install_action($this);
						return false;
					}
				)
				.on('click', '.ideapark_child_installer_link', function (e) {
						e.preventDefault();
						var $button = $(this);
						if ($button.hasClass('process-now')) {
							return;
						}
						$button.addClass('process-now updating-message');
						
						$.ajax({
							url     : ajaxurl,
							type    : 'POST',
							dataType: 'json',
							data    : {
								action  : 'ideapark_install_child',
								security: ideapark_pi_vars.ajaxNonce
							},
							success : function (result) {
								$button.removeClass('process-now updating-message');
								if (typeof result !== 'undefined') {
									if (result.success) {
										$button.replaceWith('<p class="ideapark_about_success">' + result.data + '</p>');
									} else {
										$button.replaceWith('<p class="ideapark_about_error">' + result.data + '</p>');
									}
								} else {
									$error.html(ideapark_pi_vars.errorText);
								}
							},
							error   : function (xhr, ajaxOptions, thrownError) {
								$button.removeClass('process-now updating-message');
								$error.html(ideapark_pi_vars.errorText);
							}
						});
						return false;
					}
				)
				.on('submit', '.js-register-form', function (e) {
						e.preventDefault();
						var $button = $(this).find('.js-register-theme');
						var $error = $('.js-purchase-error');
						if ($button.hasClass('process-now')) {
							return;
						}
						$button.addClass('process-now updating-message');
						$error.html('');
						
						$.ajax({
							url     : ajaxurl,
							type    : 'POST',
							dataType: 'json',
							data    : {
								action  : 'ideapark_theme_register',
								code    : $('#ideapark-purchase-code').val(),
								security: ideapark_pi_vars.ajaxNonce
							},
							success : function (result) {
								if (typeof result !== 'undefined') {
									if (result.success) {
										document.location = ideapark_pi_vars.dashboardUrl;
									} else {
										$button.removeClass('process-now updating-message');
										$error.html(result.data);
									}
								} else {
									$button.removeClass('process-now updating-message');
									$error.html(ideapark_pi_vars.errorText);
								}
							},
							error   : function (xhr, ajaxOptions, thrownError) {
								$button.removeClass('process-now updating-message');
								$error.html(ideapark_pi_vars.errorText + ': ' + xhr.responseText + ' (' + xhr.status + ')');
							}
						});
						return false;
					}
				)
				.on('click', '.js-deregister-theme', function (e) {
						e.preventDefault();
						var $button = $(this);
						var $error = $('.js-purchase-error');
						if (!confirm($button.data('confirm'))) return;
						if ($button.hasClass('process-now')) {
							return;
						}
						$button.addClass('process-now updating-message');
						$error.html('');
						
						$.ajax({
							url     : ajaxurl,
							type    : 'POST',
							dataType: 'json',
							data    : {
								action  : 'ideapark_theme_deregister',
								security: ideapark_pi_vars.ajaxNonce
							},
							success : function (result) {
								$button.removeClass('process-now updating-message');
								if (typeof result !== 'undefined') {
									if (result.success) {
										document.location = ideapark_pi_vars.dashboardUrl;
									} else {
										$error.html(result.data);
									}
								} else {
									$error.html(ideapark_pi_vars.errorText);
								}
							},
							error   : function (xhr, ajaxOptions, thrownError) {
								$button.removeClass('process-now updating-message');
								$error.html(ideapark_pi_vars.errorText);
							}
						});
						return false;
					}
				)
				.on('click', '.js-check-for-updates', function (e) {
						e.preventDefault();
						var $button = $(this);
						
						if ($button.hasClass('process-now')) {
							return;
						}
						$button.addClass('process-now updating-message');
						
						$.ajax({
							url     : ajaxurl,
							type    : 'POST',
							dataType: 'json',
							data    : {
								action  : 'ideapark_theme_check',
								security: ideapark_pi_vars.ajaxNonce
							},
							success : function (result) {
								if (typeof result !== 'undefined') {
									if (!result.success && result.data) {
										$button.removeClass('process-now updating-message');
										$button.replaceWith('<span style="color:red">' + result.data + '</span>');
									} else if (result.data.is_up_to_date) {
										$button.removeClass('process-now updating-message');
										$button.replaceWith('<span style="color:green">' + result.data.is_up_to_date + '</span>');
									} else {
										document.location = ideapark_pi_vars.dashboardUrl;
									}
								} else {
									$button.replaceWith(ideapark_pi_vars.errorText);
								}
							},
							error   : function (xhr, ajaxOptions, thrownError) {
								$button.removeClass('process-now updating-message');
								$button.replaceWith(ideapark_pi_vars.errorText);
							}
						});
						return false;
					}
				);
		}
	);
	
	var ideapark_plugin_install_current_action = '';
	var ideapark_plugin_install_current_action_cnt = 0;
	var ideapark_plugin_install_button_text_default = '';
	var $error = $('.ideapark_plugins_installer_error');
	var ideapark_plugin_install_action = function ($button) {
		
		var is_additional = $button.hasClass('additional');
		var is_core_update = $button.hasClass('core');
		var is_main = $button.hasClass('main');
		var $step = $button.closest('.step');
		var array_values = [];
		
		if (is_additional) {
			$('.ideapark_additional_plugin').each(function () {
				if ($(this).is(':checked')) {
					array_values.push($(this).val());
				}
			});
			if (!array_values.length) {
				$button.removeClass('process-now updating-message');
				return;
			}
		}
		
		if (is_main) {
			$('.ideapark_main_plugin').each(function () {
				if ($(this).is(':checked')) {
					array_values.push($(this).val());
				}
			});
			if (!array_values.length) {
				$button.removeClass('process-now updating-message');
				return;
			}
		}
		
		$error.html('');
		$.ajax({
			url     : ajaxurl,
			type    : 'POST',
			dataType: 'json',
			data    : {
				action        : 'ideapark_about_ajax',
				is_main       : is_main ? 1 : 0,
				is_additional : is_additional ? 1 : 0,
				is_core_update: is_core_update ? 1 : 0,
				plugins       : array_values.join(',')
			},
			success : function (result) {
				if (typeof result !== 'undefined' && result.action) {
					if (ideapark_plugin_install_current_action == result.action) {
						ideapark_plugin_install_current_action_cnt++;
					} else {
						ideapark_plugin_install_current_action_cnt = 0;
					}
					if (ideapark_plugin_install_current_action_cnt < 2) {
						if (!ideapark_plugin_install_button_text_default) {
							ideapark_plugin_install_button_text_default = $button.html();
						}
						ideapark_plugin_install_current_action = result.action;
						$button.html(result.name);
						$.get(result.action, function () {
							ideapark_plugin_install_action($button)
						});
					} else {
						$button.removeClass('process-now updating-message');
						$button.html(ideapark_plugin_install_button_text_default);
					}
				} else if (typeof result !== 'undefined' && result.success) {
					$button.removeClass('process-now updating-message');
					if (is_core_update) {
						$button.addClass('hidden');
						$('.core-plugins-updated ').removeClass('hidden');
					} else if (is_main) {
						if (result.list != '') {
							$button.html(ideapark_plugin_install_button_text_default);
						} else {
							$button.addClass('hidden');
							$('.main-plugins-installed').removeClass('hidden');
						}
						$('.plugins_list', $step).replaceWith(result.list);
					} else if (!is_additional) {
						$button.addClass('hidden');
						$('.ideapark_plugins_installer_success').removeClass('hidden');
						$('.ideapark_about_notes,.ideapark_about_description').addClass('hidden');
						$('.ideapark_about_next_step').removeClass('hidden');
					} else {
						if (result.list != '') {
							$button.html(ideapark_plugin_install_button_text_default);
						} else {
							$button.addClass('hidden');
							$('.additional-plugins-installed').removeClass('hidden');
						}
						$('.plugins_list', $step).replaceWith(result.list);
					}
				} else {
					$button.removeClass('process-now updating-message');
					$error.html(ideapark_pi_vars.errorText);
				}
			},
			error   : function (xhr, ajaxOptions, thrownError) {
				$button.removeClass('process-now updating-message');
				$error.html(ideapark_pi_vars.errorText);
			}
		});
	}
})(jQuery, this);