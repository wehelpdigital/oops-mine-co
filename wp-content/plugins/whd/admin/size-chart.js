/**
 * The size chart builder.
 *
 * The form works without this: it is a real table of inputs and it posts with the page. All this
 * adds is adding and removing rows and columns, and a preview of what the shopper will see, so
 * nobody has to save and open a product to find out whether the chart reads right.
 */
( function () {
	'use strict';

	var root = document.getElementById( 'whd-chart-grid' );
	if ( ! root ) {
		return;
	}

	var table = root.querySelector( '.whd-grid__table' );
	var head = table.querySelector( 'thead tr' );
	var body = table.querySelector( 'tbody' );
	var preview = root.querySelector( '[data-whd-preview]' );
	var intro = document.getElementById( 'whd-chart-intro' );
	var note = document.getElementById( 'whd-chart-note' );
	var unit = document.getElementById( 'whd-chart-unit' );

	function columns() {
		return head.querySelectorAll( 'input' ).length;
	}

	function cell( value ) {
		var td = document.createElement( 'td' );
		var input = document.createElement( 'input' );
		input.type = 'text';
		input.name = 'whd_chart_cells[]';
		input.value = value || '';
		td.appendChild( input );
		return td;
	}

	function dropButton( what ) {
		var b = document.createElement( 'button' );
		b.type = 'button';
		b.className = 'whd-grid__drop';
		b.innerHTML = '&times;';
		b.setAttribute( what === 'row' ? 'data-whd-drop-row' : 'data-whd-drop-col', '' );
		b.title = what === 'row' ? 'Remove this row' : 'Remove this column';
		return b;
	}

	function addRow() {
		var tr = document.createElement( 'tr' );
		var handle = document.createElement( 'th' );
		handle.className = 'whd-grid__handle';
		handle.appendChild( dropButton( 'row' ) );
		tr.appendChild( handle );
		for ( var i = 0; i < columns(); i++ ) {
			tr.appendChild( cell( '' ) );
		}
		body.appendChild( tr );
		var first = tr.querySelector( 'input' );
		if ( first ) {
			first.focus();
		}
		paint();
	}

	function addColumn() {
		var th = document.createElement( 'th' );
		var input = document.createElement( 'input' );
		input.type = 'text';
		input.name = 'whd_chart_cols[]';
		input.placeholder = 'Inseam';
		th.appendChild( input );
		th.appendChild( dropButton( 'col' ) );
		head.appendChild( th );
		Array.prototype.forEach.call( body.querySelectorAll( 'tr' ), function ( tr ) {
			tr.appendChild( cell( '' ) );
		} );
		input.focus();
		paint();
	}

	/* A column is a cell in every row, so removing one means removing its index everywhere. */
	function dropColumn( th ) {
		var index = Array.prototype.indexOf.call( head.children, th );
		if ( index < 1 || columns() < 2 ) {
			return;
		}
		head.removeChild( th );
		Array.prototype.forEach.call( body.querySelectorAll( 'tr' ), function ( tr ) {
			if ( tr.children[ index ] ) {
				tr.removeChild( tr.children[ index ] );
			}
		} );
		paint();
	}

	root.addEventListener( 'click', function ( e ) {
		var target = e.target;
		if ( target.hasAttribute( 'data-whd-add-row' ) ) {
			addRow();
		} else if ( target.hasAttribute( 'data-whd-add-col' ) ) {
			addColumn();
		} else if ( target.hasAttribute( 'data-whd-drop-row' ) ) {
			var tr = target.closest( 'tr' );
			if ( tr && body.querySelectorAll( 'tr' ).length > 1 ) {
				body.removeChild( tr );
				paint();
			}
		} else if ( target.hasAttribute( 'data-whd-drop-col' ) ) {
			dropColumn( target.closest( 'th' ) );
		}
	} );

	root.addEventListener( 'input', paint );
	root.addEventListener( 'change', paint );

	/* ─── the preview, drawn the same way the product page draws it ─── */

	function escapeHtml( s ) {
		return String( s ).replace( /[&<>"]/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ c ];
		} );
	}

	function paint() {
		if ( ! preview ) {
			return;
		}
		var names = Array.prototype.map.call( head.querySelectorAll( 'input' ), function ( i ) {
			return i.value.trim();
		} );
		var suffix = unit && unit.value === 'in' ? '″' : ( unit && unit.value === 'cm' ? ' cm' : '' );
		var html = '';

		if ( intro && intro.value.trim() ) {
			html += '<p class="whd-sc__lede">' + escapeHtml( intro.value.trim() ) + '</p>';
		}
		if ( names.filter( Boolean ).length ) {
			html += '<div class="whd-sc__tablewrap"><table class="whd-sc__table"><thead><tr>';
			names.forEach( function ( n ) {
				html += '<th>' + escapeHtml( n ) + '</th>';
			} );
			html += '</tr></thead><tbody>';

			Array.prototype.forEach.call( body.querySelectorAll( 'tr' ), function ( tr ) {
				var values = Array.prototype.map.call( tr.querySelectorAll( 'input' ), function ( i ) {
					return i.value.trim();
				} );
				if ( ! values.join( '' ) ) {
					return;
				}
				html += '<tr>';
				names.forEach( function ( n, i ) {
					var v = values[ i ] || '';
					if ( v && suffix && i > 0 && /^[\d.,\s\-–—/]+$/.test( v ) ) {
						v += suffix;
					}
					html += i ? '<td>' + escapeHtml( v ) + '</td>' : '<th>' + escapeHtml( v ) + '</th>';
				} );
				html += '</tr>';
			} );
			html += '</tbody></table></div>';
		}
		if ( note && note.value.trim() ) {
			html += '<p class="whd-sc__note">' + escapeHtml( note.value.trim() ) + '</p>';
		}
		preview.innerHTML = html || '<p class="whd-grid__empty">Nothing to show yet.</p>';
	}

	paint();
}() );
