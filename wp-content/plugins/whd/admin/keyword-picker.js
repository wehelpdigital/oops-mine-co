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
		var d = window.WHD_AI_DATA || window.WHD_AI_WRITER || {};
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

		var field = el( 'div', 'whd-kw__field' );
		field.appendChild( tokens );
		field.appendChild( input );
		root.appendChild( field );
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
				chosen.push( word );
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

		root.__whdChosen = function () {
			return chosen.slice();
		};
		// Mounting happens on its own as the page loads, so anything the screen wants pre-selected
		// arrives after the fact. This is how it gets in.
		root.__whdAdd = add;
		paintTokens();
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
