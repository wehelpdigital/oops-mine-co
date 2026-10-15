/**
 * The SEO score box.
 *
 * Reads whatever the editor is holding right now — block editor, classic editor or the
 * WooCommerce product screen — and asks the server what it is worth. The analysis lives in PHP so
 * that the score written on save and the score shown here can never disagree.
 */
( function () {
	'use strict';

	var D = window.WHD_SEO_DATA || {};
	var T = D.i18n || {};

	var root = document.getElementById( 'whd-seo' );
	if ( ! root ) {
		return;
	}

	var postId = parseInt( root.getAttribute( 'data-post' ), 10 ) || 0;
	var picker = document.getElementById( 'whd-seo-keyphrase' );
	var hidden = document.getElementById( 'whd-seo-keyphrase-input' );
	var bullet = document.getElementById( 'whd-seo-bullet' );
	var score = document.getElementById( 'whd-seo-score' );
	var list = document.getElementById( 'whd-seo-checks' );
	var refresh = document.getElementById( 'whd-seo-refresh' );
	var seoTitle = document.getElementById( 'whd-seo-title' );
	var seoDesc = document.getElementById( 'whd-seo-desc' );
	var noindex = document.getElementById( 'whd-seo-noindex' );

	/* ─── what the editor currently holds ─── */

	function fromBlockEditor() {
		if ( ! window.wp || ! wp.data || ! wp.data.select( 'core/editor' ) ) {
			return null;
		}
		var editor = wp.data.select( 'core/editor' );
		if ( ! editor.getEditedPostAttribute ) {
			return null;
		}
		return {
			title: editor.getEditedPostAttribute( 'title' ) || '',
			content: editor.getEditedPostContent ? editor.getEditedPostContent() : '',
			slug: editor.getEditedPostAttribute( 'slug' ) || ''
		};
	}

	function fromClassic() {
		var title = document.getElementById( 'title' );
		var body = document.getElementById( 'content' );
		var text = '';

		// TinyMCE holds the copy while the visual tab is open; the textarea is stale until it blurs.
		if ( window.tinymce && tinymce.get( 'content' ) && ! tinymce.get( 'content' ).isHidden() ) {
			text = tinymce.get( 'content' ).getContent();
		} else if ( body ) {
			text = body.value;
		}

		// A product's short description is copy too, and it is where the first paragraph often is.
		var excerpt = document.getElementById( 'excerpt' );
		if ( excerpt && excerpt.value ) {
			text = excerpt.value + '\n' + text;
		}
		if ( window.tinymce && tinymce.get( 'excerpt' ) && ! tinymce.get( 'excerpt' ).isHidden() ) {
			text = tinymce.get( 'excerpt' ).getContent() + '\n' + text;
		}

		var slugField = document.getElementById( 'post_name' ) || document.getElementById( 'editable-post-name-full' );

		return {
			title: title ? title.value : '',
			content: text,
			slug: slugField ? ( slugField.value || slugField.textContent || '' ) : ''
		};
	}

	function editorState() {
		return fromBlockEditor() || fromClassic() || { title: '', content: '', slug: '' };
	}

	/* ─── asking ─── */

	var pending = null;
	var last = '';

	function analyse() {
		var keyphrase = hidden ? hidden.value : '';
		var state = editorState();
		var body = {
			post_id: postId,
			keyphrase: keyphrase,
			title: state.title,
			content: state.content,
			slug: state.slug,
			seo_title: seoTitle ? seoTitle.value : null,
			meta_description: seoDesc ? seoDesc.value : null,
			noindex: noindex && noindex.checked ? 1 : 0
		};

		var signature = JSON.stringify( body );
		if ( signature === last ) {
			return;
		}
		last = signature;

		list.innerHTML = '<p class="description">' + ( T.reading || 'Reading the page…' ) + '</p>';

		fetch( D.rest.root + 'analyse', {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': D.rest.nonce },
			body: signature
		} ).then( function ( r ) {
			return r.json();
		} ).then( paint ).catch( function () {
			list.innerHTML = '<p class="description">' + ( T.error || 'Could not read the page.' ) + '</p>';
		} );
	}

	function later() {
		window.clearTimeout( pending );
		pending = window.setTimeout( analyse, 900 );
	}

	/* ─── showing ─── */

	function paint( result ) {
		if ( ! result || ! result.groups ) {
			list.innerHTML = '<p class="description">' + ( T.error || 'Could not read the page.' ) + '</p>';
			return;
		}

		bullet.className = 'whd-seo__bullet is-' + result.rating;
		score.textContent = result.score + ' / 100 · ' + result.label;
		score.className = 'whd-seo__score is-' + result.rating;

		var html = '';
		result.groups.forEach( function ( group ) {
			html += '<div class="whd-seo__group">';
			html += '<p class="whd-seo__group-name">' + group.label + '</p>';
			html += '<ul class="whd-seo__list">';
			group.checks.forEach( function ( check ) {
				html += '<li class="whd-seo__item is-' + check.status + '">'
					+ '<span class="whd-seo__mark" aria-hidden="true"></span>'
					+ '<span class="whd-seo__text">' + check.text + '</span></li>';
			} );
			html += '</ul></div>';
		} );
		html += '<p class="whd-seo__words">' + result.words + ' words</p>';
		list.innerHTML = html;
	}

	/* ─── the result as a searcher would see it ─── */

	var preview = document.getElementById( 'whd-seo-preview' );
	var pvTitle = document.getElementById( 'whd-seo-preview-title' );
	var pvDesc = document.getElementById( 'whd-seo-preview-desc' );
	var pvUrl = document.getElementById( 'whd-seo-preview-url' );

	function clip( text, max ) {
		text = ( text || '' ).replace( /\s+/g, ' ' ).trim();
		return text.length > max ? text.slice( 0, max - 1 ).replace( /\s\S*$/, '' ) + '…' : text;
	}

	function paintPreview() {
		if ( ! preview ) {
			return;
		}
		var state = editorState();
		var title = ( seoTitle && seoTitle.value.trim() ) || state.title || '';
		var desc = seoDesc ? seoDesc.value.trim() : '';

		pvUrl.textContent = ( D.home || '' ) + ( state.slug ? state.slug + '/' : '' );
		pvTitle.textContent = clip( title, 60 ) || ( T.noTitle || 'Untitled' );
		pvDesc.textContent = desc ? clip( desc, 158 ) : ( T.noDesc || 'Google will write this itself from the copy.' );
		pvDesc.className = 'whd-seo__preview-desc' + ( desc ? '' : ' is-empty' );

		count( seoTitle, document.getElementById( 'whd-seo-title-count' ), 60, 30 );
		count( seoDesc, document.getElementById( 'whd-seo-desc-count' ), 158, 100 );
	}

	function count( field, out, max, min ) {
		if ( ! field || ! out ) {
			return;
		}
		var n = field.value.trim().length;
		out.textContent = n + ' / ' + max;
		out.className = 'whd-seo__count' + ( n > max || ( n && n < min ) ? ' is-warn' : '' );
	}

	[ seoTitle, seoDesc ].forEach( function ( field ) {
		if ( field ) {
			field.addEventListener( 'input', function () {
				paintPreview();
				later();
			} );
		}
	} );
	if ( noindex ) {
		noindex.addEventListener( 'change', function () {
			last = '';
			analyse();
		} );
	}
	paintPreview();

	/* ─── listening ─── */

	if ( refresh ) {
		refresh.addEventListener( 'click', function () {
			last = '';
			analyse();
		} );
	}

	/* the picker holds one phrase; mirror it into the field that gets saved */
	function syncKeyphrase() {
		if ( ! hidden || ! window.WHDKeywords ) {
			return;
		}
		var chosen = WHDKeywords.chosen( picker );
		var next = chosen.length ? chosen[ chosen.length - 1 ] : '';
		if ( next !== hidden.value ) {
			hidden.value = next;
			last = '';
			analyse();
		}
	}

	if ( picker ) {
		// The picker announces every change; no need to watch the DOM for it.
		picker.addEventListener( 'whd:keywords', syncKeyphrase );
	}

	[ 'title', 'content', 'excerpt', 'post_name' ].forEach( function ( id ) {
		var el = document.getElementById( id );
		if ( el ) {
			el.addEventListener( 'input', later );
		}
	} );

	/* The block editor's store changes many times a second, and a debounce reset on every one of
	   them never fires — the box sat on "Reading the page…" for as long as the screen was open.
	   Only an actual change to the words is worth rescheduling for. */
	if ( window.wp && wp.data && wp.data.subscribe ) {
		var lastSeen = '';
		wp.data.subscribe( function () {
			var state = fromBlockEditor();
			if ( ! state ) {
				return;
			}
			var now = state.title + '\u0000' + state.slug + '\u0000' + state.content.length;
			if ( now === lastSeen ) {
				return;
			}
			lastSeen = now;
			later();
		} );
	}

	analyse();
}() );
