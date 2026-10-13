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
				product_copy: 'Put both in their fields',
				story: 'Save as the product story',
				meta_description: 'Save as the meta description',
				seo_title: 'Save as the search title'
			};
			apply.textContent = labels[ s.apply ] || 'Apply';

			// The photographs belong to the story's rows, so the offer only makes sense there.
			var also = document.querySelector( '.whd-ai-result__also' );
			if ( also ) {
				also.hidden = 'story' !== s.apply;
			}
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
			if ( 'product_copy' === s.apply ) {
				var halves = splitCopy( text.value );
				if ( ! halves ) {
					status.textContent = T.noHalves || 'Could not tell the two halves apart — check the SHORT and DESCRIPTION headings.';
					return;
				}
				var written = ( fieldText( 'content' ).trim() + fieldText( 'excerpt' ).trim() ).length;
				if ( written && ! window.confirm( T.confirmCopy || 'Replace the description and the short description with these?' ) ) {
					status.textContent = T.keptAsIs || 'Left as they were.';
					return;
				}
				var intoShort = intoExcerpt( markdownish( halves.short ), true );
				var intoLong = intoContent( markdownish( halves.long ), true );
				status.textContent = ( intoShort && intoLong )
					? ( T.bothPlaced || 'Both fields filled — save the product to keep them.' )
					: T.noEditor;
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
				apply.disabled = false;

				/*
				 * A story is written in sections and read as spreads, so the sections without a
				 * photograph are the half of the job the words cannot do. Carry on into them
				 * unless the box has been unticked.
				 */
				var also = document.getElementById( 'whd-ai-also-photos' );
				var short = r.photos && r.photos.missing;
				if ( short && also && also.checked ) {
					status.textContent = r.message + ' ' + ( T.andPhotos || 'Now the photographs…' );
					drawMissing( postId, short, function ( made ) {
						status.textContent = r.message + ' ' + ( made
							? ( T.andDrawn || '%d photograph(s) drawn.' ).replace( '%d', made )
							: ( T.andNone || 'No photographs were drawn.' ) );
					} );
				}
			} ).catch( function ( e ) {
				status.textContent = e.message;
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
		/**
		 * Pull the two halves out of a draft written for both description fields.
		 *
		 * The model is asked for "## SHORT" and "## DESCRIPTION", but people edit the box before
		 * applying, so this accepts the headings with or without hashes and in either order, and
		 * gives up rather than guessing if only one of them is there.
		 */
		function splitCopy( raw ) {
			var mark = /^[#\s]*(SHORT(?:\s+DESCRIPTION)?|DESCRIPTION|LONG(?:\s+DESCRIPTION)?)\s*:?\s*$/i;
			var lines = String( raw ).split( /\r?\n/ );
			var found = {};
			var current = null;
			lines.forEach( function ( line ) {
				var hit = line.match( mark );
				if ( hit ) {
					current = /^short/i.test( hit[ 1 ] ) ? 'short' : 'long';
					found[ current ] = found[ current ] || [];
					return;
				}
				if ( current ) {
					found[ current ].push( line );
				}
			} );
			if ( ! found.short || ! found.long ) {
				return null;
			}
			var short = found.short.join( '\n' ).trim();
			var long = found.long.join( '\n' ).trim();

			return ( short && long ) ? { short: short, long: long } : null;
		}

		/* Adding is the default everywhere, so a draft can never eat an edit in progress. The two
		   halves of the product copy replace instead, once, after asking — a short description with
		   the old one still above it is not a short description. */

		function fieldText( id ) {
			if ( 'content' === id && window.wp && wp.data && wp.data.select( 'core/editor' ) ) {
				return wp.data.select( 'core/editor' ).getEditedPostAttribute( 'content' ) || '';
			}
			var editor = window.tinymce && tinymce.get( id );
			if ( editor && ! editor.isHidden() ) {
				return editor.getContent();
			}
			var field = document.getElementById( id );
			return field ? field.value : '';
		}

		function intoContent( html, replace ) {
			if ( window.wp && wp.data && wp.data.select( 'core/editor' ) ) {
				var current = wp.data.select( 'core/editor' ).getEditedPostAttribute( 'content' ) || '';
				wp.data.dispatch( 'core/editor' ).editPost( { content: replace ? html : current + '\n\n' + html } );
				return true;
			}
			return intoTinyMce( 'content', html, replace ) || intoTextarea( 'content', html, replace );
		}

		function intoExcerpt( html, replace ) {
			return intoTinyMce( 'excerpt', html, replace ) || intoTextarea( 'excerpt', html, replace );
		}

		function intoTinyMce( id, html, replace ) {
			var editor = window.tinymce && tinymce.get( id );
			if ( ! editor || editor.isHidden() ) {
				return false;
			}
			editor.setContent( replace ? html : editor.getContent() + html );
			editor.fire( 'change' );
			return true;
		}

		function intoTextarea( id, html, replace ) {
			var field = document.getElementById( id );
			if ( ! field ) {
				return false;
			}
			field.value = replace ? html : ( field.value ? field.value + '\n\n' : '' ) + html;
			field.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			return true;
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}

	/* ─────────────────────────── the story's photographs ───────────────────────────
	   One request per picture. Each takes somewhere around fifteen seconds, so asking for four in
	   one go is asking for a gateway timeout; this walks them instead and shows each as it lands.

	   Two ways in: the button in the photographs row, and the end of writing a story — a story with
	   four sections and one photograph is only half made, so the panel carries straight on. */

	function drawMissing( postId, left, done ) {
		var root = document.getElementById( 'whd-ai-photos' );
		var status = document.getElementById( 'whd-ai-photos-status' );
		var state = document.getElementById( 'whd-ai-photos-state' );
		var grid = document.getElementById( 'whd-ai-photos-grid' );
		var go = document.getElementById( 'whd-ai-photos-go' );
		if ( ! root || ! grid || ! left ) {
			if ( done ) {
				done( 0 );
			}
			return;
		}

		var made = 0;
		if ( go ) {
			go.disabled = true;
		}
		if ( root.scrollIntoView ) {
			root.scrollIntoView( { block: 'nearest' } );
		}

		function next() {
			if ( status ) {
				status.textContent = ( T.drawing || 'Drawing…' ) + ' ' + ( made + 1 ) + '/' + left;
			}
			api( 'photos', 'POST', { post_id: postId, count: 1 } ).then( function ( r ) {
				( r.made || [] ).forEach( function ( picture ) {
					var img = document.createElement( 'img' );
					img.src = picture.url;
					img.alt = '';
					img.className = 'whd-ai-photos__shot';
					grid.hidden = false;
					grid.appendChild( img );
					intoProductGallery( picture );
				} );
				// The screen was loaded with the old list; bring it up to date or Update writes
				// that old list straight back over the new pictures.
				if ( typeof r.gallery === 'string' ) {
					var field = document.getElementById( 'product_image_gallery' );
					if ( field ) {
						field.value = r.gallery;
					}
				}
				( r.skipped || [] ).forEach( function ( why ) {
					if ( status ) {
						status.textContent = why;
					}
				} );
				made += ( r.made || [] ).length;
				if ( state ) {
					state.textContent = ( T.rowsHave || '%1$d of %2$d rows have a photograph.' )
						.replace( '%1$d', Math.min( r.have, r.rows ) ).replace( '%2$d', r.rows );
				}
				root.setAttribute( 'data-missing', r.missing );

				// It refused this one: stop rather than ask four times for the same refusal.
				if ( ! ( r.made || [] ).length ) {
					if ( go ) {
						go.disabled = false;
					}
					if ( done ) {
						done( made );
					}
					return;
				}
				if ( made < left && r.missing > 0 ) {
					next();
					return;
				}
				if ( status ) {
					status.textContent = T.drawn || 'Done — check them before you publish.';
				}
				if ( go ) {
					go.disabled = r.missing === 0;
					if ( r.missing === 0 ) {
						go.textContent = T.allRows || 'Every row has one';
					}
				}
				if ( done ) {
					done( made );
				}
			} ).catch( function ( e ) {
				if ( status ) {
					status.textContent = e.message;
				}
				if ( go ) {
					go.disabled = false;
				}
				if ( done ) {
					done( made );
				}
			} );
		}

		next();
	}

	/**
	 * Show a drawn picture in WooCommerce's own gallery box.
	 *
	 * It is already attached in the database. This is so the screen agrees — the thumbnail is
	 * there to be seen, dragged and deleted like any other, and the hidden field beside it posts
	 * the picture rather than losing it.
	 */
	function intoProductGallery( picture ) {
		var list = document.querySelector( '#product_images_container ul.product_images' );
		if ( ! list || ! picture.id ) {
			return;
		}
		if ( list.querySelector( '[data-attachment_id="' + picture.id + '"]' ) ) {
			return;
		}
		var li = document.createElement( 'li' );
		li.className = 'image';
		li.setAttribute( 'data-attachment_id', picture.id );
		li.innerHTML = '<img src="' + ( picture.thumb || picture.url ) + '" alt="" />'
			+ '<ul class="actions"><li><a href="#" class="delete tips" data-tip="Delete image">Delete</a></li></ul>';
		var add = list.querySelector( '.add_product_images' );
		if ( add ) {
			list.insertBefore( li, add );
		} else {
			list.appendChild( li );
		}
	}

	function photos() {
		var root = document.getElementById( 'whd-ai-photos' );
		var go = document.getElementById( 'whd-ai-photos-go' );
		if ( ! root || ! go ) {
			return;
		}
		go.addEventListener( 'click', function () {
			drawMissing( parseInt( root.getAttribute( 'data-post' ), 10 ), parseInt( root.getAttribute( 'data-missing' ), 10 ) || 0 );
		} );
	}

	function start() {
		brief();
		rules();
		panel();
		photos();
	}
}() );
