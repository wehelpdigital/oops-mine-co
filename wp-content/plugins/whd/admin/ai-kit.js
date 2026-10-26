/**
 * What Write with AI and Bulk products share in the browser: the requests (with the background
 * jobs behind them), the lightbox and the loader.
 *
 * Both screens run the same chain of steps against the same endpoints — the product screen for
 * the product it is on, Bulk products for each draft it makes — so how a step is sent, how its job
 * is waited for and what a dead request is reported as must not differ between them.
 *
 *   WHDAIKit.client( { root, nonce, last, strings, prepare } )  → { api, send, lastWords }
 *   WHDAIKit.lightbox( strings )                               → { open }
 *   WHDAIKit.loader( strings )                                 → { open, step, note, kicker, photo, tick, more, picture, finish }
 *   WHDAIKit.framePicker( { frames, value, none, … } )         → { el, value, set } — the size new pictures are drawn at
 */
( function () {
	'use strict';

	function esc( text ) {
		var d = document.createElement( 'div' );
		d.textContent = text == null ? '' : String( text );
		return d.innerHTML;
	}

	/* sprintf for the two shapes the strings use: %d / %s, and %1$d / %2$d. */
	function fmt( text ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var i = 0;
		return String( text || '' ).replace( /%(\d+)\$[sd]|%[sd]/g, function ( m, n ) {
			return String( n ? args[ n - 1 ] : args[ i++ ] );
		} );
	}

	/* ─────────────────────────── requests ─────────────────────────── */

	/* How long a job may run before it is given up on: the slowest step takes about two minutes. */
	var JOB_LIMIT = 8 * 60 * 1000;

	/*
	 * opts.root     the endpoint base, a step name is appended to it
	 * opts.nonce    the REST nonce
	 * opts.last     the endpoint that says what a dead request was doing
	 * opts.jobs     the endpoint a job is asked after at (default: root + 'job')
	 * opts.strings  error, tooLong
	 * opts.prepare  optional: called with each body before it is sent
	 */
	function client( opts ) {
		var T = opts.strings || {};

		/* What the server was doing when a request died, if it left a note; '' when it did not. */
		function lastWords() {
			if ( ! opts.last ) {
				return Promise.resolve( '' );
			}
			return window.fetch( opts.last, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': opts.nonce },
				body: '{}'
			} ).then( function ( r ) {
				return r.ok ? r.json() : {};
			} ).then( function ( j ) {
				return ( j && j.said ) || '';
			} ).catch( function () {
				return '';
			} );
		}

		function send( step, body ) {
			if ( opts.prepare ) {
				opts.prepare( body );
			}
			return post( opts.root + step, body );
		}

		function post( url, body ) {
			return window.fetch( url, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': opts.nonce },
				body: JSON.stringify( body )
			} ).then( function ( r ) {
				return r.text().then( function ( raw ) {
					var json = null;
					try {
						json = JSON.parse( raw );
					} catch ( e ) {
						/*
						 * A page instead of an answer: the host ended the request — a killed process, a
						 * gateway timeout. The server leaves a note of what it was doing before each step
						 * that could do that; ask for it, so the message says where, not just "500".
						 */
						var plain = ( T.error || 'Something went wrong' ) + ' (HTTP ' + r.status + ')';
						return lastWords().then( function ( said ) {
							throw new Error( said ? plain + '. ' + said : plain );
						} );
					}
					if ( ! r.ok ) {
						throw new Error( ( json && json.message ) || ( T.error || 'Something went wrong' ) );
					}
					return json;
				} );
			} );
		}

		function wait( job ) {
			var started = Date.now();
			var misses = 0;
			return new Promise( function ( resolve, reject ) {
				( function poll() {
					post( opts.jobs || opts.root + 'job', { job: job } ).then( function ( s ) {
						misses = 0;
						if ( 'done' === s.status ) {
							resolve( s.result );
							return;
						}
						if ( 'error' === s.status ) {
							reject( new Error( s.message || T.error ) );
							return;
						}
						if ( Date.now() - started > JOB_LIMIT ) {
							lastWords().then( function ( said ) {
								reject( new Error( T.tooLong + ( said ? ' ' + said : '' ) ) );
							} );
							return;
						}
						window.setTimeout( poll, 2500 );
					}, function ( e ) {
						// One check that did not get through is not the job failing; a run of them is.
						if ( ++misses > 5 ) {
							reject( e );
						} else {
							window.setTimeout( poll, 4000 );
						}
					} );
				}() );
			} );
		}

		return {
			/*
			 * One step of a run. The steps that wait on a model answer at once with a job number and
			 * do the work after the connection has closed — a host that ends quiet requests after a
			 * minute ended "Writing the page" with a bare HTTP 500 — so a job is waited for here, a
			 * few seconds at a time, and the caller gets the step's answer either way.
			 */
			api: function ( step, body ) {
				return send( step, body ).then( function ( json ) {
					return json && json.job ? wait( json.job ) : json;
				} );
			},
			send: send,
			lastWords: lastWords
		};
	}

	/* ─────────────────────────── the lightbox ───────────────────────────
	   A picture clicked opens large, over the screen, so a drawn one can be checked button by button
	   before it goes anywhere. Arrows and ← → step through the rest of its set; Escape, the cross or a
	   click on the dark around it closes it, and the focus goes back to the picture that opened it.
	   A <dialog> on the body, so it sits above everything — the loader included. */

	function lightbox( T ) {
		var dialog = null;
		var items = [];
		var at = 0;
		var opener = null;

		function build() {
			dialog = document.createElement( 'dialog' );
			dialog.className = 'whd-lightbox';
			dialog.setAttribute( 'aria-label', T.lightbox );
			dialog.innerHTML =
				'<figure class="whd-lightbox__figure" tabindex="-1" autofocus>' +
					'<img class="whd-lightbox__img" alt="">' +
					'<figcaption class="whd-lightbox__cap">' +
						'<span class="whd-lightbox__title"></span>' +
						'<span class="whd-lightbox__state"></span>' +
						'<span class="whd-lightbox__count"></span>' +
						'<ul class="whd-lightbox__notes"></ul>' +
					'</figcaption>' +
				'</figure>' +
				'<button type="button" class="whd-lightbox__btn whd-lightbox__close" aria-label="' + esc( T.close ) + '"></button>' +
				'<button type="button" class="whd-lightbox__btn whd-lightbox__prev" aria-label="' + esc( T.previous ) + '"></button>' +
				'<button type="button" class="whd-lightbox__btn whd-lightbox__next" aria-label="' + esc( T.next ) + '"></button>';

			var img = dialog.querySelector( '.whd-lightbox__img' );
			img.addEventListener( 'load', function () {
				dialog.classList.remove( 'is-loading' );
			} );
			img.addEventListener( 'error', function () {
				dialog.classList.remove( 'is-loading' );
			} );
			dialog.querySelector( '.whd-lightbox__close' ).addEventListener( 'click', function () {
				dialog.close();
			} );
			dialog.querySelector( '.whd-lightbox__prev' ).addEventListener( 'click', function () {
				show( at - 1 );
			} );
			dialog.querySelector( '.whd-lightbox__next' ).addEventListener( 'click', function () {
				show( at + 1 );
			} );
			// The dark around the picture is the dialog itself; the picture and the buttons are not.
			dialog.addEventListener( 'click', function ( e ) {
				if ( e.target === dialog ) {
					dialog.close();
				}
			} );
			dialog.addEventListener( 'keydown', function ( e ) {
				if ( 'ArrowLeft' === e.key ) {
					e.preventDefault();
					show( at - 1 );
				} else if ( 'ArrowRight' === e.key ) {
					e.preventDefault();
					show( at + 1 );
				}
			} );
			dialog.addEventListener( 'close', function () {
				if ( opener && opener.focus ) {
					opener.focus();
				}
			} );
			document.body.appendChild( dialog );
		}

		function show( i ) {
			if ( ! items.length ) {
				return;
			}
			at = ( i + items.length ) % items.length; // round the ends, so the arrows never go dead
			var item = items[ at ];
			var img = dialog.querySelector( '.whd-lightbox__img' );
			if ( img.getAttribute( 'src' ) !== item.src ) {
				dialog.classList.add( 'is-loading' );
				img.src = item.src;
			}
			img.alt = item.caption || '';
			dialog.querySelector( '.whd-lightbox__title' ).textContent = item.caption || '';
			var state = dialog.querySelector( '.whd-lightbox__state' );
			state.textContent = item.state || '';
			state.className = 'whd-lightbox__state' + ( item.tone ? ' is-' + item.tone : '' );
			dialog.querySelector( '.whd-lightbox__count' ).textContent = items.length > 1 ? fmt( T.ofTotal, at + 1, items.length ) : '';
			var notes = dialog.querySelector( '.whd-lightbox__notes' );
			notes.innerHTML = ( item.notes || [] ).map( function ( n ) {
				return '<li>' + esc( n ) + '</li>';
			} ).join( '' );
			dialog.classList.toggle( 'is-single', items.length < 2 );
		}

		return {
			/* list: [ { src, caption, state, tone, notes[] } ]; from: the element to give focus back to. */
			open: function ( list, index, from ) {
				if ( ! list.length ) {
					return;
				}
				if ( ! dialog ) {
					build();
				}
				items = list;
				opener = from || null;
				show( index || 0 );
				if ( ! dialog.open ) {
					dialog.showModal();
				}
			}
		};
	}

	/* ─────────────────────────── the loader ───────────────────────────
	   A run takes minutes, so while it works the page is covered by a modal that says what is
	   happening: a photograph in a viewfinder with a scan line passing over it, the step and its
	   detail, a bar, the time so far, and each new picture as it lands. It closes itself when the
	   run is done, and stays open with the reason and a Close button when the run stops. */

	function loader( T ) {
		var dialog = null;
		var clock = null;
		var started = 0;
		var done = 0;
		var total = 1;
		var estimate = 0;

		function part( sel ) {
			return dialog ? dialog.querySelector( sel ) : null;
		}

		function minutes( s ) {
			var m = Math.floor( s / 60 );
			var r = Math.floor( s % 60 );
			return m + ':' + ( r < 10 ? '0' : '' ) + r;
		}

		function paint() {
			var pct = Math.max( 4, Math.min( 100, Math.round( 100 * done / Math.max( 1, total ) ) ) );
			var bar = part( '.whd-studio-loader__bar' );
			if ( bar ) {
				bar.firstChild.style.width = pct + '%';
				bar.setAttribute( 'aria-valuenow', String( pct ) );
			}
		}

		function tock() {
			var time = part( '.whd-studio-loader__time' );
			if ( ! time ) {
				return;
			}
			var spent = ( Date.now() - started ) / 1000;
			time.textContent = fmt( T.loaderSoFar, minutes( spent ) ) +
				( estimate ? ' · ' + fmt( T.loaderUsually, Math.max( 1, Math.round( estimate / 60 ) ), Math.round( estimate / 60 ) + 1 ) : '' );
		}

		function close( after ) {
			if ( ! dialog ) {
				return;
			}
			var d = dialog;
			window.clearInterval( clock );
			d.classList.add( 'is-leaving' );
			window.setTimeout( function () {
				if ( d.open ) {
					d.close();
				}
				d.remove();
				if ( after ) {
					after();
				}
			}, 260 );
			dialog = null;
		}

		return {
			/* photo: the picture for the viewfinder; units: how many steps make the bar full;
			   seconds: what a run like this usually takes, for the estimate. */
			open: function ( photo, units, seconds ) {
				if ( dialog ) {
					close();
				}
				done = 0;
				total = Math.max( 1, units );
				estimate = seconds || 0;
				started = Date.now();
				dialog = document.createElement( 'dialog' );
				dialog.className = 'whd-studio-loader';
				dialog.setAttribute( 'aria-labelledby', 'whd-studio-loader-h' );
				dialog.innerHTML =
					'<div class="whd-studio-loader__frame">' +
						'<img class="whd-studio-loader__img" src="' + esc( photo || '' ) + '" alt=""' + ( photo ? '' : ' hidden' ) + '>' +
						'<span class="whd-studio-loader__scan" aria-hidden="true"></span>' +
						'<span class="whd-studio-loader__corner is-tl" aria-hidden="true"></span>' +
						'<span class="whd-studio-loader__corner is-tr" aria-hidden="true"></span>' +
						'<span class="whd-studio-loader__corner is-bl" aria-hidden="true"></span>' +
						'<span class="whd-studio-loader__corner is-br" aria-hidden="true"></span>' +
						'<span class="whd-studio-loader__badge" aria-hidden="true"></span>' +
					'</div>' +
					'<p class="whd-studio-loader__kicker" hidden></p>' +
					// Focus lands on the heading: a ring round a button the moment it opens reads as "press me".
					'<h2 class="whd-studio-loader__h" id="whd-studio-loader-h" aria-live="polite" tabindex="-1" autofocus></h2>' +
					'<p class="whd-studio-loader__note"></p>' +
					'<div class="whd-studio-loader__bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-label="' + esc( T.loaderProgress ) + '"><span></span></div>' +
					'<p class="whd-studio-loader__time"></p>' +
					'<div class="whd-studio-loader__pics"></div>';
				/*
				 * Nothing closes it while the run works — not Escape, and no "Hide" link either: a
				 * page that can be scrolled and edited under a run in progress is a page whose fields
				 * get typed into while the run is about to fill them. It closes itself when the run is
				 * done, and gives a Close button only once the run has stopped.
				 */
				dialog.addEventListener( 'cancel', function ( e ) {
					e.preventDefault();
				} );
				document.body.appendChild( dialog );
				dialog.showModal();
				paint();
				tock();
				clock = window.setInterval( tock, 1000 );
			},

			/* hint: a line under the step saying what it is doing, until a note replaces it. */
			step: function ( label, hint ) {
				var h = part( '.whd-studio-loader__h' );
				var n = part( '.whd-studio-loader__note' );
				if ( h ) {
					h.textContent = label;
				}
				if ( n ) {
					n.textContent = hint || '';
				}
			},

			note: function ( text ) {
				var n = part( '.whd-studio-loader__note' );
				if ( n ) {
					n.textContent = text || '';
				}
			},

			/* A line above the step — which piece of a batch this is. */
			kicker: function ( text ) {
				var k = part( '.whd-studio-loader__kicker' );
				if ( k ) {
					k.textContent = text || '';
					k.hidden = ! text;
				}
			},

			/* Another photograph in the viewfinder, when a batch moves on to its next piece. */
			photo: function ( url ) {
				var img = part( '.whd-studio-loader__img' );
				if ( img && url ) {
					img.src = url;
					img.hidden = false;
				}
			},

			/* One step finished; or more steps than were counted (a repair). */
			tick: function () {
				done++;
				paint();
			},
			more: function ( n ) {
				total += n;
				paint();
			},

			picture: function ( url ) {
				var pics = part( '.whd-studio-loader__pics' );
				if ( pics && url ) {
					var img = document.createElement( 'img' );
					img.src = url;
					img.alt = '';
					pics.appendChild( img );
				}
			},

			/* ok: a tick, then gone. Not ok: it stays, with the reason and a Close. */
			finish: function ( ok, message, after ) {
				if ( ! dialog ) {
					if ( after ) {
						after();
					}
					return;
				}
				window.clearInterval( clock );
				tock();
				done = total;
				paint();
				dialog.classList.add( ok ? 'is-done' : 'is-bad' );
				this.kicker( '' );
				this.step( ok ? T.loaderDone : T.stopped );
				this.note( ok ? ( message || T.loaderDoneNote ) : message );
				if ( ! dialog.open ) {
					dialog.showModal();
				}
				if ( ok ) {
					window.setTimeout( function () {
						close( after );
					}, 1500 );
				} else {
					var foot = document.createElement( 'p' );
					foot.className = 'whd-studio-loader__foot';
					foot.innerHTML = '<button type="button" class="button">' + esc( T.loaderClose ) + '</button>';
					foot.firstChild.addEventListener( 'click', function () {
						close();
					} );
					dialog.appendChild( foot );
					foot.firstChild.focus();
					if ( after ) {
						after();
					}
				}
			}
		};
	}

	/* ─────────────────────────── the size picker ─────────────────────────── */

	var pickers = 0;

	/*
	 * The size new pictures are drawn at: the Image Editor's sizes (WHD_AI_Images::frames()) in one
	 * select, grouped, with an outline of the shape beside it and a line saying what will be made.
	 *
	 * opts.frames    [ { key, name, group, w, h, aspect } ]
	 * opts.value     the key chosen, '' for none
	 * opts.none      the words for '' — what this screen draws when no size is chosen
	 * opts.noneNote  the line under it for ''
	 * opts.after     optional: a sentence added to the line under a chosen size
	 * opts.strings   frameLabel, frameNote, frameOption
	 * opts.onChange  called with the key
	 *
	 * @return { el, value(), set( key ) }
	 */
	function framePicker( opts ) {
		var T = opts.strings || {};
		var frames = opts.frames || [];
		var id = 'whd-frame-' + ( ++pickers );
		var wrap = document.createElement( 'div' );
		wrap.className = 'whd-frame';

		var groups = [];
		var html = '<option value="">' + esc( opts.none ) + '</option>';
		frames.forEach( function ( f ) {
			if ( groups.indexOf( f.group ) === -1 ) {
				groups.push( f.group );
			}
		} );
		groups.forEach( function ( g ) {
			html += '<optgroup label="' + esc( g ) + '">' + frames.filter( function ( f ) {
				return f.group === g;
			} ).map( function ( f ) {
				return '<option value="' + esc( f.key ) + '">' + esc( fmt( T.frameOption, f.name, f.w, f.h ) ) + '</option>';
			} ).join( '' ) + '</optgroup>';
		} );

		wrap.innerHTML = '<label class="whd-studio__label" for="' + id + '">' + esc( T.frameLabel ) + '</label>' +
			'<div class="whd-frame__row">' +
				'<span class="whd-frame__shape" aria-hidden="true"><i></i></span>' +
				'<select class="whd-frame__select" id="' + id + '">' + html + '</select>' +
			'</div>' +
			'<p class="description whd-frame__note"></p>';
		var select = wrap.querySelector( 'select' );
		var box = wrap.querySelector( '.whd-frame__shape i' );
		var note = wrap.querySelector( '.whd-frame__note' );

		function find( key ) {
			return frames.filter( function ( f ) {
				return f.key === key;
			} )[ 0 ] || null;
		}

		function paint() {
			var f = find( select.value );
			// The outline fits a 26-pixel square, long side to the edge; no size is a dashed square.
			box.style.width = ( f ? Math.round( 26 * Math.min( 1, f.w / f.h ) ) : 26 ) + 'px';
			box.style.height = ( f ? Math.round( 26 * Math.min( 1, f.h / f.w ) ) : 26 ) + 'px';
			box.parentNode.classList.toggle( 'is-none', ! f );
			note.textContent = f ? fmt( T.frameNote, f.aspect, f.w, f.h ) + ( opts.after ? ' ' + opts.after : '' ) : ( opts.noneNote || '' );
		}

		select.value = find( opts.value || '' ) ? opts.value : '';
		select.addEventListener( 'change', function () {
			paint();
			if ( opts.onChange ) {
				opts.onChange( select.value );
			}
		} );
		paint();

		return {
			el: wrap,
			value: function () {
				return select.value;
			},
			set: function ( key ) {
				select.value = find( key || '' ) ? key : '';
				paint();
			}
		};
	}

	window.WHDAIKit = {
		esc: esc,
		fmt: fmt,
		client: client,
		lightbox: lightbox,
		loader: loader,
		framePicker: framePicker
	};
}() );
