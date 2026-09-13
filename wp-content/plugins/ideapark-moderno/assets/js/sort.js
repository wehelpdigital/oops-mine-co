(function ($) {
	var fixHelper = function (e, ui) {
		ui.children().each(function () {
			$(this).width($(this).width());
		});
		return ui;
	};
	var disableSortable = function () {
		var $list = $(this);
		var $table = $list.closest('table');
		var $checkbox = $("<p class='ip-disable-sortable'><label><input type='checkbox' class='js-disable-sortable' /> " + ideapark_sort_vars.disableSortable + "</label></p>");
		$checkbox.find('.js-disable-sortable').on('change', function () {
			if ($(this).is(":checked")) {
				$list.sortable("disable");
				localStorage.setItem('ip-disable-sortable', 'yes');
			} else {
				$list.sortable("enable");
				localStorage.setItem('ip-disable-sortable', '');
			}
		});
		$checkbox.insertBefore($table);
	};
	
	$('table.posts #the-list').sortable({
		'items' : 'tr',
		'axis'  : 'y',
		'helper': fixHelper,
		'update': function (e, ui) {
			$.post(ajaxurl, {
				action   : 'update-post-order',
				post_type: $('input[name="post_type"]').val(),
				order    : $('#the-list').sortable('serialize'),
			});
		}
	}).each(disableSortable);
	
	$('table.tags #the-list').sortable({
		'items' : 'tr',
		'axis'  : 'y',
		'helper': fixHelper,
		'update': function (e, ui) {
			$.post(ajaxurl, {
				action  : 'update-term-order',
				taxonomy: $('input[name="taxonomy"]').val(),
				order   : $('#the-list').sortable('serialize'),
			});
		}
	}).each(disableSortable);
	
	if (localStorage.getItem('ip-disable-sortable') === 'yes') {
		$('.js-disable-sortable').prop('checked', true).trigger('change');
	}
	
	if (typeof ideapark_sort_vars != 'undefined' && ideapark_sort_vars.notice) {
		$('#col-right .col-wrap').append('<div class="ideapark-sortable-notice">' + ideapark_sort_vars.notice + '</div>');
	}
	
})(jQuery);