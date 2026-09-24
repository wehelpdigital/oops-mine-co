/* Oops, Mine Co. — style video pages: chapter jumps, #t= deep links and a "currently playing"
   marker on the chapter list. No dependencies; the player itself is the browser's own. */
(function () {
	'use strict';

	var video = document.querySelector('.js-omc-video');
	if (!video) { return; }

	var chapters = Array.prototype.slice.call(document.querySelectorAll('.js-omc-video-seek'));

	/* The player is preload="none" so the page stays light; asking for a time before any data has
	   arrived is ignored by every browser, so load first and seek once metadata is in. */
	function seek(seconds) {
		var go = function () {
			try { video.currentTime = seconds; } catch (e) { /* seeking not ready yet */ }
			var playing = video.play();
			if (playing && typeof playing.catch === 'function') { playing.catch(function () { /* autoplay refused — the poster stays */ }); }
		};
		if (video.readyState >= 1) { go(); return; }
		video.addEventListener('loadedmetadata', go, { once: true });
		if (video.preload === 'none') { video.preload = 'metadata'; }
		video.load();
	}

	chapters.forEach(function (button) {
		button.addEventListener('click', function () {
			var at = parseInt(button.getAttribute('data-at'), 10) || 0;
			seek(at);
			video.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
		});
	});

	/* Highlight the chapter the video is inside. */
	if (chapters.length) {
		var marks = chapters.map(function (b) { return parseInt(b.getAttribute('data-at'), 10) || 0; });
		video.addEventListener('timeupdate', function () {
			var t = video.currentTime, current = -1;
			for (var i = 0; i < marks.length; i++) { if (t >= marks[i]) { current = i; } }
			chapters.forEach(function (b, i) { b.classList.toggle('is-current', i === current); });
		});
	}

	/* /page/#t=23 jumps straight to that moment — the links Google surfaces as key moments. */
	function fromHash() {
		var m = /(?:^|#)t=(\d+)/.exec(window.location.hash || '');
		if (m) { seek(parseInt(m[1], 10)); }
	}
	window.addEventListener('hashchange', fromHash);
	fromHash();
})();
