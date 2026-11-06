/* Oops, Mine Co. — what the video editor (inc/video-editor.php) adds to a video: cut parts skipped, and
   text, tap-to-shop dots, polls, buttons, "choose what's next" and sign-ups over the picture at their
   moments. Nothing is in the file; all of it happens here while the video plays.

   The drawing is shared: the editor's preview calls OMCVideoPlay.render() too, so what is seen there is
   what shoppers get. A video with nothing edited never loads this, and keeps the browser's own player. */
(function () {
	'use strict';

	function el(tag, cls, text) {
		var n = document.createElement(tag);
		if (cls) { n.className = cls; }
		if (null != text) { n.textContent = text; }
		return n;
	}

	function clock(s) {
		s = Math.max(0, Math.round(s || 0));
		var h = Math.floor(s / 3600), m = Math.floor(s / 60) % 60, r = ('0' + (s % 60)).slice(-2);
		return h ? h + ':' + ('0' + m).slice(-2) + ':' + r : m + ':' + r;
	}

	/* The video as it plays once its cuts are skipped. Everything stored is in the file's own seconds;
	   "shown" is what a viewer's clock says. */
	function timeline(cuts, duration) {
		var list = (cuts || []).map(function (c) { return [+c[0], Math.min(+c[1], duration)]; })
			.filter(function (c) { return c[1] > c[0]; })
			.sort(function (a, b) { return a[0] - b[0]; });
		var gone = list.reduce(function (sum, c) { return sum + c[1] - c[0]; }, 0);
		var tail = list.length && list[list.length - 1][1] >= duration - 0.05 ? list[list.length - 1][0] : duration;
		return {
			cuts: list,
			duration: duration,
			length: Math.max(0, duration - gone),
			first: list.length && list[0][0] <= 0.05 ? list[0][1] : 0,
			last: tail,
			shown: function (t) {
				var s = t;
				list.forEach(function (c) {
					if (t >= c[1]) { s -= c[1] - c[0]; } else if (t > c[0]) { s -= t - c[0]; }
				});
				return Math.max(0, s);
			},
			source: function (s) {
				var t = s;
				for (var i = 0; i < list.length; i++) {
					if (t < list[i][0]) { break; }
					t += list[i][1] - list[i][0];
				}
				return Math.min(t, duration);
			},
			/* Where to go if this moment has been cut: the end of the cut, or -1 when it has not. */
			after: function (t) {
				for (var i = 0; i < list.length; i++) {
					if (t >= list[i][0] && t < list[i][1] - 0.02) { return list[i][1]; }
				}
				return -1;
			}
		};
	}

	/* ─────────────────────────── the things over the picture ─────────────────────────── */

	function closeButton(item, env) {
		var x = el('button', 'omc-vo__x', '×');
		x.type = 'button';
		x.setAttribute('aria-label', env.strings.close);
		x.addEventListener('click', function () { env.close(item); });
		return x;
	}

	function pollCard(item, env) {
		var root = el('div', 'omc-vo omc-vo--card omc-vo--poll');
		root.appendChild(closeButton(item, env));
		root.appendChild(el('p', 'omc-vo__q', item.question));
		var opts = el('div', 'omc-vo__opts');
		var rows = (item.options || []).map(function (label, i) {
			var b = el('button', 'omc-vo__opt');
			b.type = 'button';
			b.appendChild(el('span', 'omc-vo__fill'));
			b.appendChild(el('span', 'omc-vo__lab', label));
			b.appendChild(el('span', 'omc-vo__pct'));
			b.addEventListener('click', function () {
				if (root.classList.contains('is-voted')) { return; }
				paint(env.fresh(item, i), i); // straight away; the server's count replaces it
				env.vote(item, i).then(function (counts) { if (counts) { paint(counts, i); } env.done(item); });
			});
			opts.appendChild(b);
			return b;
		});
		function paint(counts, mine) {
			var total = rows.reduce(function (sum, b, i) { return sum + (+counts[i] || 0); }, 0) || 1;
			root.classList.add('is-voted');
			rows.forEach(function (b, i) {
				var p = Math.round((+counts[i] || 0) / total * 100);
				b.style.setProperty('--p', p + '%');
				b.querySelector('.omc-vo__pct').textContent = p + '%';
				b.classList.toggle('is-mine', i === mine);
				b.setAttribute('aria-pressed', i === mine ? 'true' : 'false');
			});
		}
		root.appendChild(opts);
		var mine = env.voted(item);
		if (null !== mine) { paint(env.counts(item), mine); }
		return root;
	}

	function jumpCard(item, env) {
		var root = el('div', 'omc-vo omc-vo--card omc-vo--jump');
		root.appendChild(closeButton(item, env));
		root.appendChild(el('p', 'omc-vo__q', item.question));
		var opts = el('div', 'omc-vo__opts');
		(item.choices || []).forEach(function (c, i) {
			var b = el('button', 'omc-vo__opt omc-vo__opt--go');
			b.type = 'button';
			b.appendChild(el('span', 'omc-vo__lab', c.label));
			b.addEventListener('click', function () {
				env.act(item, i);
				env.jump(item, +c.to || 0);
			});
			opts.appendChild(b);
		});
		root.appendChild(opts);
		return root;
	}

	function signupCard(item, env) {
		var root = el('div', 'omc-vo omc-vo--card omc-vo--signup');
		root.appendChild(closeButton(item, env));
		root.appendChild(el('p', 'omc-vo__q', item.heading));
		var form = el('form', 'omc-vo__form');
		form.noValidate = true;
		var input = el('input');
		input.type = 'email';
		input.required = true;
		input.placeholder = env.strings.email;
		input.setAttribute('aria-label', env.strings.email);
		input.autocomplete = 'email';
		var go = el('button', 'omc-vo__send', item.button);
		go.type = 'submit';
		form.appendChild(input);
		form.appendChild(go);
		root.appendChild(form);
		var note = el('p', 'omc-vo__note');
		note.setAttribute('role', 'status');
		root.appendChild(note);
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(input.value)) {
				note.textContent = env.strings.badEmail;
				input.focus();
				return;
			}
			go.disabled = true;
			env.signup(item, input.value).then(function (message) {
				note.textContent = message;
				form.hidden = true;
				env.done(item);
			}).catch(function (error) {
				note.textContent = error.message;
				go.disabled = false;
			});
		});
		return root;
	}

	function render(item, env) {
		var node;
		switch (item.type) {
			case 'text':
				node = el('div', 'omc-vo omc-vo--text is-' + (item.style || 'serif') + ' is-' + (item.size || 'm'), item.text);
				break;
			case 'button':
				node = el('a', 'omc-vo omc-vo--cta', item.label);
				node.href = item.url;
				if (0 !== String(item.url).indexOf(window.location.origin) && '/' !== String(item.url).charAt(0)) {
					node.target = '_blank';
					node.rel = 'noopener';
				}
				node.addEventListener('click', function () { env.act(item, 0); });
				break;
			case 'spot':
				var piece = env.piece(item.product);
				node = el('button', 'omc-vo omc-vo--spot');
				node.type = 'button';
				node.setAttribute('aria-label', env.strings.shop + (piece ? ': ' + piece.name : ''));
				node.appendChild(el('i'));
				if (piece) { node.appendChild(el('span', 'omc-vo__tip', piece.name)); }
				node.addEventListener('click', function () { env.act(item, 0); env.openPiece(item.product); });
				break;
			case 'poll':
				node = pollCard(item, env);
				break;
			case 'jump':
				node = jumpCard(item, env);
				break;
			case 'signup':
				node = signupCard(item, env);
				break;
			default:
				node = el('div', 'omc-vo');
		}
		place(node, item);
		return node;
	}

	/* Cards and words: 0 to 1 of the room they leave (see omc-video-play.css). A dot: a point on the picture. */
	function place(node, item) {
		node.style.setProperty('--x', +item.x || 0);
		node.style.setProperty('--y', +item.y || 0);
	}

	window.OMCVideoPlay = { timeline: timeline, render: render, place: place, clock: clock };

	/* ─────────────────────────── on the page ─────────────────────────── */

	var holder = document.getElementById('omc-video-play');
	var video = document.querySelector('.js-omc-video');
	var frame = video && video.closest('.omc-video__frame');
	if (!holder || !frame) { return; }

	var D = JSON.parse(holder.textContent);
	var T = D.i18n;
	var tl = timeline(D.cuts, +D.duration || 0);
	var items = D.items || [];
	var counts = D.counts || {};
	var nodes = {};
	var closed = {};   // shut with × until that moment comes round again
	var paused = {};   // has stopped the video once already
	var waiting = null; // the question the video is stopped on
	var scrubbing = false;
	var store = {
		get: function (k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } },
		set: function (k, v) { try { window.localStorage.setItem(k, v); } catch (e) { /* private mode: it just forgets */ } }
	};

	function voteKey(item) { return 'omc_vote_' + D.page + '_' + item.id; }

	function post(url, body) {
		return window.fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body), keepalive: true })
			.then(function (r) { return r.ok ? r.json() : null; })
			.catch(function () { return null; });
	}

	function shut(item) {
		closed[item.id] = true;
		sync(video.currentTime);
		env.done(item);
	}

	var env = {
		strings: T,
		piece: function (id) { return window.OMCShop ? window.OMCShop.piece(id) : null; },
		close: shut,
		openPiece: function (id) {
			var going = !video.paused;
			video.pause();
			if (window.OMCVideoWindow) {
				window.OMCVideoWindow.open(id, {
					label: T.now,
					x: D.place.x,
					y: D.place.y,
					onClose: function () { if (going) { video.play().catch(function () {}); } }
				});
			}
		},
		act: function (item, choice) { post(D.act, { page: D.page, item: item.id, choice: choice }); },
		voted: function (item) {
			var v = store.get(voteKey(item));
			return null === v ? null : parseInt(v, 10);
		},
		counts: function (item) { return counts[item.id] || []; },
		/* The tally with this vote in it, before the server has answered. */
		fresh: function (item, i) {
			var c = (counts[item.id] || []).slice();
			(item.options || []).forEach(function (o, k) { c[k] = +c[k] || 0; });
			c[i] += 1;
			counts[item.id] = c;
			store.set(voteKey(item), String(i));
			return c;
		},
		vote: function (item, i) {
			return post(D.act, { page: D.page, item: item.id, choice: i }).then(function (r) {
				if (r && r.counts) { counts[item.id] = r.counts; return r.counts; }
				return null;
			});
		},
		/* Answered: if the video stopped for this, give them a moment with the result and carry on. */
		done: function (item) {
			if (waiting && waiting.id === item.id) {
				waiting = null;
				setTimeout(function () { video.play().catch(function () {}); }, 1400);
			}
		},
		jump: function (item, to) {
			waiting = null;
			closed[item.id] = true;
			sync(video.currentTime);
			video.currentTime = to;
			video.play().catch(function () {});
		},
		signup: function (item, email) {
			var body = new FormData();
			body.append('action', 'omc_subscribe');
			body.append('nonce', D.signup.nonce);
			body.append('source', D.signup.source);
			body.append('email', email);
			return window.fetch(D.signup.url, { method: 'POST', body: body, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (!res || !res.success) { throw new Error((res && res.data && res.data.message) || T.oops); }
					document.dispatchEvent(new CustomEvent('omc:subscribed', { detail: { source: D.signup.source, event_id: res.data.event_id || '' } }));
					return res.data.message;
				})
				.catch(function (e) { throw new Error(e.message || T.oops); });
		}
	};

	var layer = el('div', 'omc-vo-layer');
	frame.appendChild(layer);

	function sync(t) {
		items.forEach(function (item) {
			var on = t >= item.at && t < item.at + item.for && !closed[item.id];
			var node = nodes[item.id];
			if (on && !node) {
				node = nodes[item.id] = render(item, env);
				layer.appendChild(node);
				window.requestAnimationFrame(function () { node.classList.add('is-in'); });
				if (item.pause && !paused[item.id] && null === env.voted(item)) {
					paused[item.id] = true;
					waiting = item;
					video.pause();
				}
			} else if (node && !on && !node.contains(document.activeElement)) { // not while somebody is typing in it
				node.remove();
				delete nodes[item.id];
			}
		});
	}

	/* ─ the controls: the browser's own would show the cut parts as still being there ─ */

	frame.classList.add('has-vc');
	video.removeAttribute('controls');

	var big = el('button', 'omc-vc__big');
	big.type = 'button';
	big.setAttribute('aria-label', T.play);
	frame.appendChild(big);

	var bar = el('div', 'omc-vc');
	var playBtn = el('button', 'omc-vc__btn omc-vc__play');
	playBtn.type = 'button';
	playBtn.setAttribute('aria-label', T.play);
	var time = el('span', 'omc-vc__time', '0:00 / ' + clock(tl.length));
	var track = el('div', 'omc-vc__track');
	var range = el('input', 'omc-vc__range');
	range.type = 'range';
	range.min = 0;
	range.max = tl.length || 1;
	range.step = 0.1;
	range.value = 0;
	range.setAttribute('aria-label', T.seek);
	track.appendChild(range);
	var muteBtn = el('button', 'omc-vc__btn omc-vc__mute');
	muteBtn.type = 'button';
	muteBtn.setAttribute('aria-label', T.mute);
	var fullBtn = el('button', 'omc-vc__btn omc-vc__full');
	fullBtn.type = 'button';
	fullBtn.setAttribute('aria-label', T.full);
	bar.appendChild(playBtn);
	bar.appendChild(time);
	bar.appendChild(track);
	bar.appendChild(muteBtn);
	bar.appendChild(fullBtn);
	frame.appendChild(bar);

	/* A little mark on the bar for each piece and each question. */
	var marks = [];
	document.querySelectorAll('.omc-vshop__item').forEach(function (m) { marks.push(parseInt(m.getAttribute('data-at'), 10) || 0); });
	items.forEach(function (i) { if ('text' !== i.type) { marks.push(i.at); } });
	marks.forEach(function (at) {
		var tick = el('i', 'omc-vc__tick');
		tick.style.left = (tl.shown(at) / (tl.length || 1) * 100) + '%';
		track.appendChild(tick);
	});

	function paintBar(t) {
		var s = tl.shown(t);
		if (!scrubbing) { range.value = s; }
		track.style.setProperty('--done', (s / (tl.length || 1) * 100) + '%');
		time.textContent = clock(s) + ' / ' + clock(tl.length);
	}

	/* Called on every frame while it plays, so a cut is skipped before anybody sees into it. */
	function look() {
		var t = video.currentTime;
		var to = tl.after(t);
		if (to >= 0) {
			if (to >= tl.duration - 0.05) {
				finish();
			} else {
				video.currentTime = to;
			}
			return;
		}
		if (t >= tl.last - 0.04 && tl.last < tl.duration) {
			finish();
			return;
		}
		paintBar(t);
		sync(t);
	}

	function finish() {
		video.pause();
		frame.classList.add('is-ended');
	}

	function loop() {
		if (video.paused || video.ended) { return; }
		look();
		window.requestAnimationFrame(loop);
	}

	function toggle() {
		if (video.paused) {
			if (frame.classList.contains('is-ended') || video.ended) { video.currentTime = tl.first; }
			video.play().catch(function () {});
		} else {
			video.pause();
		}
	}

	video.addEventListener('play', function () {
		frame.classList.remove('is-ended');
		frame.classList.add('is-playing', 'is-started');
		playBtn.setAttribute('aria-label', T.pause);
		if (waiting) { waiting = null; } // pressing play is a way of saying "not now"
		idleSoon();
		loop();
	});
	video.addEventListener('pause', function () {
		frame.classList.remove('is-playing');
		playBtn.setAttribute('aria-label', T.play);
		frame.classList.remove('is-idle');
	});
	video.addEventListener('ended', finish);
	video.addEventListener('timeupdate', look); // a tab in the background has no frames to go by
	video.addEventListener('loadedmetadata', function () { if (video.currentTime < tl.first) { video.currentTime = tl.first; } });
	video.addEventListener('seeked', function () {
		var t = video.currentTime;
		items.forEach(function (i) {
			if (t < i.at) {
				delete closed[i.id];
				delete paused[i.id];
			}
		});
		look();
	});
	video.addEventListener('volumechange', function () { frame.classList.toggle('is-muted', video.muted); });

	video.addEventListener('click', toggle);
	big.addEventListener('click', toggle);
	playBtn.addEventListener('click', toggle);
	muteBtn.addEventListener('click', function () { video.muted = !video.muted; });
	fullBtn.addEventListener('click', function () {
		if (document.fullscreenElement) { document.exitFullscreen(); return; }
		if (frame.requestFullscreen) { frame.requestFullscreen(); } else if (video.webkitEnterFullscreen) { video.webkitEnterFullscreen(); }
	});
	range.addEventListener('input', function () {
		scrubbing = true;
		video.currentTime = tl.source(parseFloat(range.value));
		time.textContent = clock(parseFloat(range.value)) + ' / ' + clock(tl.length);
	});
	range.addEventListener('change', function () { scrubbing = false; });

	/* The bar steps back while it plays, and comes back when somebody moves. */
	var idle = null;
	function idleSoon() {
		clearTimeout(idle);
		frame.classList.remove('is-idle');
		idle = setTimeout(function () { if (!video.paused) { frame.classList.add('is-idle'); } }, 2600);
	}
	frame.addEventListener('pointermove', idleSoon);
	frame.addEventListener('pointerdown', idleSoon);
})();
