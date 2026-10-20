/**
 * The keyword picker: type, see what the list has, pick as many as you like.
 *
 * A fixed row of chips only ever showed the handful somebody decided to put in front of you,
 * which is no use against a list of several hundred. This searches the lot, shows the volume and
 * the difficulty beside each one so the choice is informed, and takes a phrase that is not in the
 * list at all — typing it and pressing Enter is a perfectly good way to ask for a keyword.
 *
 * Mount it on any element carrying data-whd-keywords. Read what was chosen with
 * WHDKeywords.chosen( element ), or listen for the 'whd:keywords' event it fires.
 */
( function () {
	'use strict';

	var ENDPOINT = '';
	var NONCE = '';

	function config() {
		var d = window.WHD_AI_DATA || window.WHD_AI_WRITER || window.WHD_SEO_DATA || window.WHD_AIP || {};
		if ( d.rest ) {
			ENDPOINT = d.rest.root + 'keywords';
			NONCE = d.rest.nonce;
		}
	}

	function el( tag, cls, text ) {
		var node = document.createElement( tag );
		if ( cls ) {
			node.className = cls;
		}
		if ( text !== undefined ) {
			node.textContent = text;
		}
		return node;
	}

	function mount( root ) {
		if ( ! root || root.__whdKeywords ) {
			return;
		}
		root.__whdKeywords = true;

		var tokens = el( 'div', 'whd-kw__tokens' );
		var input = el( 'input', 'whd-kw__input' );
		input.type = 'text';
		input.autocomplete = 'off';
		input.placeholder = root.getAttribute( 'data-placeholder' ) || 'Search your keywords, or type your own';
		var menu = el( 'ul', 'whd-kw__menu' );
		menu.hidden = true;

		/* Typing searches the list, but only ever shows the twenty best matches — no way to look
		   through several hundred, or to find one whose wording you half remember. */
		var browse = el( 'button', 'button button-small whd-kw__browse', 'Browse all' );
		browse.type = 'button';

		var field = el( 'div', 'whd-kw__field' );
		field.appendChild( tokens );
		field.appendChild( input );
		root.appendChild( field );
		root.appendChild( browse );
		root.appendChild( menu );

		var chosen = [];
		var results = [];
		var active = -1;
		var timer = null;

		// Anything already chosen server-side, as a comma list.
		( root.getAttribute( 'data-selected' ) || '' ).split( ',' ).forEach( function ( word ) {
			word = word.trim();
			if ( word ) {
				chosen.push( word );
			}
		} );

		function announce() {
			root.dispatchEvent( new CustomEvent( 'whd:keywords', { bubbles: true, detail: { keywords: chosen.slice() } } ) );
		}

		function paintTokens() {
			tokens.innerHTML = '';
			chosen.forEach( function ( word, i ) {
				var token = el( 'span', 'whd-kw__token' );
				token.appendChild( el( 'span', 'whd-kw__token-text', word ) );
				var drop = el( 'button', 'whd-kw__token-drop' );
				drop.type = 'button';
				drop.innerHTML = '&times;';
				drop.setAttribute( 'aria-label', 'Remove ' + word );
				drop.addEventListener( 'click', function () {
					chosen.splice( i, 1 );
					paintTokens();
					announce();
					input.focus();
				} );
				token.appendChild( drop );
				tokens.appendChild( token );
			} );
			root.classList.toggle( 'has-tokens', chosen.length > 0 );
		}

		function add( word ) {
			word = ( word || '' ).trim().replace( /,+$/, '' );
			if ( ! word ) {
				return;
			}
			var already = chosen.some( function ( w ) {
				return w.toLowerCase() === word.toLowerCase();
			} );
			if ( ! already ) {
				/* A focus keyphrase is one phrase. data-max lets a screen say so, and the newest
				   choice replaces the oldest rather than being refused — picking again is how
				   somebody changes their mind. */
				var max = parseInt( root.getAttribute( 'data-max' ), 10 ) || 0;
				chosen.push( word );
				if ( max > 0 ) {
					chosen = chosen.slice( -max );
				}
				paintTokens();
				announce();
			}
			input.value = '';
			close();
		}

		function close() {
			menu.hidden = true;
			menu.innerHTML = '';
			results = [];
			active = -1;
		}

		function paintMenu( rows, typed ) {
			results = rows;
			menu.innerHTML = '';
			active = -1;

			rows.forEach( function ( row, i ) {
				var li = el( 'li', 'whd-kw__option' );
				li.setAttribute( 'role', 'option' );
				li.appendChild( el( 'span', 'whd-kw__option-word', row.keyword ) );

				var meta = el( 'span', 'whd-kw__option-meta' );
				if ( row.volume ) {
					meta.appendChild( el( 'span', 'whd-kw__stat', row.volume.toLocaleString() + '/mo' ) );
				}
				if ( row.difficulty ) {
					var band = row.difficulty < 30 ? 'is-easy' : ( row.difficulty < 55 ? 'is-mid' : 'is-hard' );
					meta.appendChild( el( 'span', 'whd-kw__stat ' + band, 'KD ' + row.difficulty ) );
				}
				if ( row.used ) {
					meta.appendChild( el( 'span', 'whd-kw__stat is-used', 'used ' + row.used + '×' ) );
				}
				li.appendChild( meta );
				li.addEventListener( 'mousedown', function ( e ) {
					e.preventDefault();
					add( row.keyword );
				} );
				menu.appendChild( li );
			} );

			// A phrase of your own is a first-class answer, not a fallback.
			var exact = rows.some( function ( r ) {
				return r.keyword.toLowerCase() === typed.toLowerCase();
			} );
			if ( typed && ! exact ) {
				var li = el( 'li', 'whd-kw__option whd-kw__option--new' );
				li.appendChild( el( 'span', 'whd-kw__option-word', '“' + typed + '”' ) );
				li.appendChild( el( 'span', 'whd-kw__option-meta', 'use this, not in your list' ) );
				li.addEventListener( 'mousedown', function ( e ) {
					e.preventDefault();
					add( typed );
				} );
				menu.appendChild( li );
				results = rows.concat( [ { keyword: typed } ] );
			}

			if ( ! menu.children.length ) {
				menu.appendChild( el( 'li', 'whd-kw__empty', 'Nothing in your list matches. Press Enter to use it anyway.' ) );
			}
			menu.hidden = false;
		}

		function highlight( next ) {
			var options = menu.querySelectorAll( '.whd-kw__option' );
			if ( ! options.length ) {
				return;
			}
			if ( active > -1 && options[ active ] ) {
				options[ active ].classList.remove( 'is-active' );
			}
			active = ( next + options.length ) % options.length;
			options[ active ].classList.add( 'is-active' );
			options[ active ].scrollIntoView( { block: 'nearest' } );
		}

		/*
		 * Answers can arrive out of order — the empty search fired on focus is the slowest and the
		 * least wanted, and it was landing on top of what somebody had just typed. Only the most
		 * recent request is allowed to paint.
		 */
		var sequence = 0;

		function search() {
			var typed = input.value.trim();
			if ( ! ENDPOINT ) {
				return;
			}
			var mine = ++sequence;
			fetch( ENDPOINT + '?search=' + encodeURIComponent( typed ) + '&limit=20', {
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': NONCE }
			} ).then( function ( r ) {
				return r.json();
			} ).then( function ( json ) {
				if ( mine !== sequence ) {
					return;
				}
				var rows = ( json.keywords || [] ).filter( function ( row ) {
					return ! chosen.some( function ( w ) {
						return w.toLowerCase() === row.keyword.toLowerCase();
					} );
				} );
				paintMenu( rows, typed );
			} ).catch( function () {
				if ( mine === sequence ) {
					paintMenu( [], typed );
				}
			} );
		}

		input.addEventListener( 'input', function () {
			clearTimeout( timer );
			timer = setTimeout( search, 160 );
		} );
		input.addEventListener( 'focus', search );
		input.addEventListener( 'blur', function () {
			setTimeout( close, 120 );
		} );

		input.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'ArrowDown' ) {
				e.preventDefault();
				highlight( active + 1 );
			} else if ( e.key === 'ArrowUp' ) {
				e.preventDefault();
				highlight( active - 1 );
			} else if ( e.key === 'Enter' ) {
				e.preventDefault();
				add( active > -1 && results[ active ] ? results[ active ].keyword : input.value );
			} else if ( e.key === 'Escape' ) {
				close();
			} else if ( e.key === ',' ) {
				e.preventDefault();
				add( input.value );
			} else if ( e.key === 'Backspace' && ! input.value && chosen.length ) {
				chosen.pop();
				paintTokens();
				announce();
			}
		} );

		field.addEventListener( 'click', function ( e ) {
			if ( e.target === field || e.target === tokens ) {
				input.focus();
			}
		} );

		browse.addEventListener( 'click', function () {
			openBrowser( root, add, chosen.slice() );
		} );

		root.__whdChosen = function () {
			return chosen.slice();
		};
		// Mounting happens on its own as the page loads, so anything the screen wants pre-selected
		// arrives after the fact. This is how it gets in.
		root.__whdAdd = add;
		paintTokens();
	}

	/* ─────────────────────────── the browse window ───────────────────────────
	   One at a time, built when first asked for and reused after that. It pages through the
	   whole list, sorts it however you want to look at it, and can hide the phrases another
	   page has already been written for — which is usually what somebody is hunting for. */

	function openBrowser( root, addWord, chosenNow ) {
		var max = parseInt( root.getAttribute( 'data-max' ), 10 ) || 0;
		var picked = {};
		var rows = [];
		var offset = 0;
		var total = 0;
		var loading = false;

		var back = el( 'div', 'whd-kwb' );
		var panel = el( 'div', 'whd-kwb__panel' );
		back.appendChild( panel );

		var head = el( 'div', 'whd-kwb__head' );
		var title = el( 'h2', 'whd-kwb__title', 'Your keywords' );
		var close = el( 'button', 'whd-kwb__close' );
		close.type = 'button';
		close.setAttribute( 'aria-label', 'Close' );
		close.innerHTML = '&times;';
		head.appendChild( title );
		head.appendChild( close );

		var tools = el( 'div', 'whd-kwb__tools' );
		var search = el( 'input', 'whd-kwb__search' );
		search.type = 'search';
		search.placeholder = 'Search all keywords…';
		var sort = el( 'select', 'whd-kwb__sort' );
		[
			[ 'volume|DESC', 'Most searched' ],
			[ 'volume|ASC', 'Least searched' ],
			[ 'difficulty|ASC', 'Easiest to rank' ],
			[ 'difficulty|DESC', 'Hardest to rank' ],
			[ 'keyword|ASC', 'A to Z' ],
			[ 'used|ASC', 'Least used' ]
		].forEach( function ( pair ) {
			var o = el( 'option', '', pair[ 1 ] );
			o.value = pair[ 0 ];
			sort.appendChild( o );
		} );
		var unusedWrap = el( 'label', 'whd-kwb__tick' );
		var unused = el( 'input' );
		unused.type = 'checkbox';
		unusedWrap.appendChild( unused );
		unusedWrap.appendChild( document.createTextNode( ' Not used yet' ) );

		tools.appendChild( search );
		tools.appendChild( sort );
		tools.appendChild( unusedWrap );

		var list = el( 'div', 'whd-kwb__list' );
		var more = el( 'button', 'button whd-kwb__more', 'Show more' );
		more.type = 'button';
		more.hidden = true;

		var foot = el( 'div', 'whd-kwb__foot' );
		var count = el( 'span', 'whd-kwb__count', '' );
		var cancel = el( 'button', 'button whd-kwb__cancel', 'Cancel' );
		cancel.type = 'button';
		var add = el( 'button', 'button button-primary whd-kwb__add', 'Add selected' );
		add.type = 'button';
		foot.appendChild( count );
		foot.appendChild( cancel );
		foot.appendChild( add );

		panel.appendChild( head );
		panel.appendChild( tools );
		panel.appendChild( list );
		panel.appendChild( more );
		panel.appendChild( foot );
		document.body.appendChild( back );

		var already = {};
		( chosenNow || [] ).forEach( function ( w ) {
			already[ w.toLowerCase() ] = true;
		} );

		function tally() {
			var n = Object.keys( picked ).length;
			count.textContent = n ? n + ' selected' : ( total ? total + ' keywords' : '' );
			add.disabled = ! n;
		}

		function paint( fresh ) {
			if ( fresh ) {
				list.innerHTML = '';
			}
			rows.forEach( function ( row ) {
				var line = el( 'label', 'whd-kwb__row' );
				var box = el( 'input' );
				box.type = max === 1 ? 'radio' : 'checkbox';
				box.name = 'whd-kwb-pick';
				box.value = row.keyword;
				box.checked = !! picked[ row.keyword ];
				if ( already[ row.keyword.toLowerCase() ] ) {
					line.classList.add( 'is-already' );
					box.disabled = true;
					box.checked = true;
				}
				box.addEventListener( 'change', function () {
					if ( max === 1 ) {
						picked = {};
					}
					if ( box.checked ) {
						picked[ row.keyword ] = true;
					} else {
						delete picked[ row.keyword ];
					}
					tally();
				} );

				var word = el( 'span', 'whd-kwb__word', row.keyword );
				var vol = el( 'span', 'whd-kwb__vol', row.volume ? row.volume.toLocaleString() : '—' );
				var sd = el( 'span', 'whd-kwb__sd', row.difficulty ? 'SD ' + row.difficulty : '' );
				var use = el( 'span', 'whd-kwb__used', row.used ? 'used ' + row.used + '×' : 'unused' );
				if ( ! row.used ) {
					use.classList.add( 'is-free' );
				}

				line.appendChild( box );
				line.appendChild( word );
				line.appendChild( vol );
				line.appendChild( sd );
				line.appendChild( use );
				list.appendChild( line );
			} );
			tally();
		}

		/* A request in flight must not swallow the next one. Typing while the first page is
		   still loading used to drop the search and leave the window showing the old list, so
		   every request takes a ticket and only the newest one is allowed to paint. */
		var ticket = 0;

		function load( fresh ) {
			var mine = ++ticket;
			loading = true;
			more.disabled = true;
			if ( fresh ) {
				offset = 0;
				list.innerHTML = '<p class="whd-kwb__empty">Looking…</p>';
			}
			/* The route is a GET, like the autocomplete above — posting to it answers 404. */
			var parts = sort.value.split( '|' );
			var query = '?browse=1&limit=200'
				+ '&search=' + encodeURIComponent( search.value.trim() )
				+ '&orderby=' + encodeURIComponent( parts[ 0 ] )
				+ '&order=' + encodeURIComponent( parts[ 1 ] )
				+ '&offset=' + offset;

			fetch( ENDPOINT + query, {
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': NONCE }
			} ).then( function ( r ) {
				return r.json();
			} ).then( function ( data ) {
				if ( mine !== ticket ) {
					return;
				}
				total = data.total || 0;
				rows = ( data.keywords || [] ).filter( function ( row ) {
					return ! unused.checked || ! row.used;
				} );
				if ( fresh ) {
					list.innerHTML = '';
				}
				if ( ! rows.length && fresh ) {
					list.innerHTML = '<p class="whd-kwb__empty">Nothing matches that.</p>';
				} else {
					paint( false );
				}
				offset += ( data.keywords || [] ).length;
				more.hidden = offset >= total;
				title.textContent = 'Your keywords — ' + total.toLocaleString();
			} ).catch( function () {
				if ( mine === ticket ) {
					list.innerHTML = '<p class="whd-kwb__empty">The list could not be read.</p>';
				}
			} ).then( function () {
				if ( mine === ticket ) {
					loading = false;
					more.disabled = false;
				}
			} );
		}

		var typing = null;
		search.addEventListener( 'input', function () {
			window.clearTimeout( typing );
			typing = window.setTimeout( function () {
				load( true );
			}, 250 );
		} );
		sort.addEventListener( 'change', function () {
			load( true );
		} );
		unused.addEventListener( 'change', function () {
			load( true );
		} );
		more.addEventListener( 'click', function () {
			load( false );
		} );

		function shut() {
			document.removeEventListener( 'keydown', onKey );
			back.remove();
		}
		function onKey( e ) {
			if ( 'Escape' === e.key ) {
				shut();
			}
		}
		document.addEventListener( 'keydown', onKey );
		close.addEventListener( 'click', shut );
		cancel.addEventListener( 'click', shut );
		back.addEventListener( 'click', function ( e ) {
			if ( e.target === back ) {
				shut();
			}
		} );
		add.addEventListener( 'click', function () {
			Object.keys( picked ).forEach( addWord );
			shut();
		} );

		load( true );
		search.focus();
	}

	window.WHDKeywords = {
		mount: mount,
		chosen: function ( root ) {
			return root && root.__whdChosen ? root.__whdChosen() : [];
		},
		add: function ( root, words ) {
			if ( ! root ) {
				return;
			}
			mount( root );
			( Array.isArray( words ) ? words : [ words ] ).forEach( function ( word ) {
				if ( root.__whdAdd ) {
					root.__whdAdd( word );
				}
			} );
		}
	};

	function start() {
		config();
		Array.prototype.forEach.call( document.querySelectorAll( '[data-whd-keywords]' ), mount );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}() );
