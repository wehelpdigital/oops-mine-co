/**
 * AI content: the post-screen panel and the two buttons on the Brief tab.
 *
 * Vanilla, like the rest of this plugin's admin. The only thing worth knowing is how a draft
 * reaches the page: copy that has a field on this screen (the long and short descriptions) is put
 * into that field in the browser, so it goes through WordPress's own save and never clobbers an
 * edit in progress. Copy with nowhere to live on this screen (the product story, the search
 * snippet) is written by the REST endpoint instead.
 */
( function () {
	'use strict';

	var D = window.WHD_AI_DATA || {};
	var T = D.i18n || {};

	function api( path, method, body ) {
		return fetch( D.rest.root + path, {
			method: method,
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': D.rest.nonce },
			body: body ? JSON.stringify( body ) : undefined
		} ).then( function ( r ) {
			return r.json().then( function ( json ) {
				if ( ! r.ok ) {
					throw new Error( ( json && json.message ) || T.error );
				}
				return json;
			} );
		} );
	}

	/* ─────────────────────────── Brief tab ─────────────────────────── */

	function brief() {
		var field = document.getElementById( 'whd-ai-brief' );
		var draft = document.getElementById( 'whd-ai-draft' );
		var polish = document.getElementById( 'whd-ai-polish' );
		var status = document.getElementById( 'whd-ai-brief-status' );
		if ( ! field ) {
			return;
		}

		function run( mode, button ) {
			button.disabled = true;
			status.textContent = T.drafting;
			api( 'brief', 'POST', { mode: mode, text: field.value } ).then( function ( r ) {
				field.value = r.text;
				status.textContent = '';
			} ).catch( function ( e ) {
				status.textContent = e.message;
			} ).then( function () {
				button.disabled = false;
			} );
		}

		draft.addEventListener( 'click', function () {
			run( 'draft', draft );
		} );
		if ( polish ) {
			polish.addEventListener( 'click', function () {
				run( 'polish', polish );
			} );
		}
	}

	/* ─────────────────────────── Rules tab ─────────────────────────── */

	function rules() {
		var list = document.getElementById( 'whd-ai-rulelist' );
		var add = document.getElementById( 'whd-ai-add-rule' );
		if ( ! list ) {
			return;
		}

		list.addEventListener( 'click', function ( e ) {
			var remove = e.target.closest( '.whd-ai-rule__remove' );
			if ( remove ) {
				remove.closest( '.whd-ai-rule' ).remove();
			}
		} );

		add.addEventListener( 'click', function () {
			// Index by position, not by count: removing a row must not make two rows share a name.
			var i = list.querySelectorAll( '.whd-ai-rule' ).length;
			var name = list.querySelector( '.whd-ai-rule__text' );
			var base = name ? name.getAttribute( 'name' ).replace( /\[\d+\]\[text\]$/, '' ) : 'whd_ai[rules]';
			var row = document.createElement( 'div' );
			row.className = 'whd-ai-rule';
			row.innerHTML =
				'<label class="whd-ai-rule__on"><input type="checkbox" checked value="1" name="' + base + '[' + i + '][on]"></label>' +
				'<textarea class="whd-ai-rule__text" rows="2" name="' + base + '[' + i + '][text]"></textarea>' +
				'<button type="button" class="button-link whd-ai-rule__remove">&times;</button>';
			list.appendChild( row );
			row.querySelector( 'textarea' ).focus();
		} );
	}

	/* ─────────────────────────── the post-screen panel ─────────────────────────── */

	function panel() {
		var root = document.getElementById( 'whd-ai-panel' );
		var target = document.getElementById( 'whd-ai-target' );
		var chips = document.getElementById( 'whd-ai-chips' );
		// The panel is replaced by a short message when no model is connected, so check for the
		// controls rather than for a container.
		if ( ! root || ! target || ! chips ) {
			return;
		}

		var postId = parseInt( root.getAttribute( 'data-post' ), 10 );
		var extra = document.getElementById( 'whd-ai-keywords' );
		var instruction = document.getElementById( 'whd-ai-instruction' );
		var go = document.getElementById( 'whd-ai-go' );
		var status = document.getElementById( 'whd-ai-status' );
		var result = document.getElementById( 'whd-ai-result' );
		var check = document.getElementById( 'whd-ai-check' );
		var text = document.getElementById( 'whd-ai-text' );
		var apply = document.getElementById( 'whd-ai-apply' );
		var copy = document.getElementById( 'whd-ai-copy' );
		var again = document.getElementById( 'whd-ai-again' );
		var count = document.getElementById( 'whd-ai-count' );
		var chosen = {};
		var lastHtml = '';   // the server's own conversion of the last draft
		var lastText = '';   // what it looked like before anyone edited the box

		( D.suggested || [] ).forEach( function ( keyword, i ) {
			var chip = document.createElement( 'button' );
			chip.type = 'button';
			chip.className = 'whd-ai-chip';
			chip.textContent = keyword;
			// The top three are on by default: enough to steer the copy, few enough to stay natural.
			if ( i < 3 ) {
				chip.classList.add( 'is-on' );
				chosen[ keyword ] = true;
			}
			chip.addEventListener( 'click', function () {
				if ( chosen[ keyword ] ) {
					delete chosen[ keyword ];
					chip.classList.remove( 'is-on' );
				} else {
					chosen[ keyword ] = true;
					chip.classList.add( 'is-on' );
				}
			} );
			chips.appendChild( chip );
		} );
		if ( ! ( D.suggested || [] ).length ) {
			chips.innerHTML = '<span class="whd-ai-chips__empty">' +
				'<a href="' + D.settings + '&tab=keywords">' + ( T.noKeywords || 'Add keywords' ) + '</a></span>';
		}

		function keywords() {
			var list = Object.keys( chosen );
			( extra.value || '' ).split( ',' ).forEach( function ( k ) {
				k = k.trim();
				if ( k && list.indexOf( k ) === -1 ) {
					list.push( k );
				}
			} );
			return list;
		}

		function spec() {
			var key = target.value;
			var found = null;
			( D.targets || [] ).forEach( function ( t ) {
				if ( t.key === key ) {
					found = t;
				}
			} );
			return found;
		}

		function paintCheck( c ) {
			var ok = c.ok;
			var html = '<p class="whd-ai-check__head ' + ( ok ? 'is-ok' : 'is-bad' ) + '">' +
				( ok ? T.passed : T.failed ) + '</p>';
			if ( ! ok ) {
				html += '<ul class="whd-ai-check__list">';
				c.issues.forEach( function ( issue ) {
					html += '<li>' + escapeHtml( issue.detail ) + '</li>';
				} );
				html += '</ul>';
			}
			var names = Object.keys( c.keywords || {} );
			if ( names.length ) {
				html += '<p class="whd-ai-check__kw">';
				names.forEach( function ( k ) {
					html += '<span class="whd-ai-kwtag ' + ( c.keywords[ k ] ? 'is-in' : 'is-out' ) + '">' + escapeHtml( k ) + '</span>';
				} );
				html += '</p>';
			}
			check.innerHTML = html;
		}

		function escapeHtml( s ) {
			var d = document.createElement( 'div' );
			d.textContent = s;
			return d.innerHTML;
		}

		function paintCount() {
			var words = ( text.value.trim().match( /\S+/g ) || [] ).length;
			count.textContent = words + ' ' + T.words + ' · ' + text.value.length + ' ' + T.chars;
		}

		function applyLabel() {
			var s = spec();
			if ( ! s ) {
				return;
			}
			var labels = {
				content: 'Put it in the editor',
				excerpt: 'Put it in the short description',
				story: 'Save as the product story',
				meta_description: 'Save as the meta description',
				seo_title: 'Save as the search title'
			};
			apply.textContent = labels[ s.apply ] || 'Apply';
		}
		target.addEventListener( 'change', applyLabel );
		applyLabel();

		text.addEventListener( 'input', paintCount );

		go.addEventListener( 'click', function () {
			go.disabled = true;
			status.textContent = T.writing;
			check.innerHTML = '';
			api( 'generate', 'POST', {
				post_id: postId,
				target: target.value,
				keywords: keywords(),
				instruction: instruction.value
			} ).then( function ( r ) {
				lastHtml = r.html || '';
				lastText = r.text;
				text.value = r.text;
				result.hidden = false;
				paintCheck( r.check );
				paintCount();
				status.textContent = r.attempts > 1 ? '(' + r.model + ', rewritten once)' : '(' + r.model + ')';
				go.textContent = T.rewrite;
			} ).catch( function ( e ) {
				status.textContent = e.message;
			} ).then( function () {
				go.disabled = false;
			} );
		} );

		again.addEventListener( 'click', function () {
			go.click();
		} );

		copy.addEventListener( 'click', function () {
			text.select();
			document.execCommand( 'copy' );
			status.textContent = T.copied;
		} );

		apply.addEventListener( 'click', function () {
			var s = spec();
			if ( ! s ) {
				return;
			}
			if ( s.inline ) {
				// Untouched, use the server's conversion; edited, convert what is actually there.
				var html = text.value === lastText ? lastHtml : markdownish( text.value );
				var placed = s.apply === 'excerpt' ? intoExcerpt( html ) : intoContent( html );
				status.textContent = placed ? T.inserted : T.noEditor;
				return;
			}
			apply.disabled = true;
			status.textContent = T.writing;
			api( 'apply', 'POST', {
				post_id: postId,
				target: target.value,
				text: text.value,
				keywords: keywords()
			} ).then( function ( r ) {
				status.textContent = r.message;
			} ).catch( function ( e ) {
				status.textContent = e.message;
			} ).then( function () {
				apply.disabled = false;
			} );
		} );

		/**
		 * A last-resort markdown pass for text the user edited in the box.
		 * The server does the real conversion; this only has to survive headings and paragraphs.
		 */
		function markdownish( md ) {
			return md.split( /\n{2,}/ ).map( function ( block ) {
				var m = block.match( /^(#{2,4})\s+(.*)$/ );
				if ( m ) {
					var level = Math.min( 4, m[ 1 ].length );
					return '<h' + level + '>' + escapeHtml( m[ 2 ].trim() ) + '</h' + level + '>';
				}
				return '<p>' + escapeHtml( block.trim() ).replace( /\n/g, '<br>' ) + '</p>';
			} ).join( '\n' );
		}

		/** Append to the main editor, whichever one this screen is using. */
		function intoContent( html ) {
			if ( window.wp && wp.data && wp.data.select( 'core/editor' ) ) {
				var current = wp.data.select( 'core/editor' ).getEditedPostAttribute( 'content' ) || '';
				wp.data.dispatch( 'core/editor' ).editPost( { content: current + '\n\n' + html } );
				return true;
			}
			return intoTinyMce( 'content', html ) || intoTextarea( 'content', html );
		}

		function intoExcerpt( html ) {
			return intoTinyMce( 'excerpt', html ) || intoTextarea( 'excerpt', html );
		}

		function intoTinyMce( id, html ) {
			var editor = window.tinymce && tinymce.get( id );
			if ( ! editor || editor.isHidden() ) {
				return false;
			}
			editor.setContent( editor.getContent() + html );
			editor.fire( 'change' );
			return true;
		}

		function intoTextarea( id, html ) {
			var field = document.getElementById( id );
			if ( ! field ) {
				return false;
			}
			field.value = ( field.value ? field.value + '\n\n' : '' ) + html;
			field.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			return true;
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}

	function start() {
		brief();
		rules();
		panel();
	}
}() );
