/* WHD popups — triggers (exit-intent / delay / scroll), "seen" cookies and cookie-based countdowns. */
(function () {
	'use strict';

	var cookie = {
		get: function (name) {
			var m = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/[.$?*|{}()[\]\\/+^]/g, '\\$&') + '=([^;]*)'));
			return m ? decodeURIComponent(m[1]) : null;
		},
		set: function (name, value, days) {
			var d = new Date();
			d.setTime(d.getTime() + Math.max(0.01, days || 0) * 864e5);
			document.cookie = name + '=' + encodeURIComponent(value) + '; expires=' + d.toUTCString() + '; path=/; SameSite=Lax';
		}
	};

	function pad(n) { return (n < 10 ? '0' : '') + n; }
	function format(ms) {
		var s = Math.max(0, Math.round(ms / 1000));
		var h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sec = s % 60;
		return (h ? pad(h) + ':' : '') + pad(m) + ':' + pad(sec);
	}

	function Popup(el) {
		var cfg;
		try { cfg = JSON.parse(el.getAttribute('data-config') || '{}'); } catch (e) { cfg = {}; }
		this.el = el;
		this.cfg = cfg;
		this.id = cfg.id || 'popup';
		this.preview = !!cfg.preview;
		this.seenKey = 'whd_seen_' + this.id;
		this.shown = false;
		this.timers = [];
		this.dialog = el.querySelector('.whd-popup__dialog');
		this.init();
	}

	Popup.prototype.init = function () {
		var self = this;
		if (!this.preview && cookie.get(this.seenKey)) { return; }
		this.el.querySelectorAll('[data-whd-close], .whd-popup__close').forEach(function (b) { b.addEventListener('click', function () { self.close(); }); });
		document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && self.shown) { self.close(); } });

		if (this.preview) { this.show(); return; }

		var scrollOk = !(this.cfg.scrollPct > 0);
		var checkScroll = function () {
			var max = document.documentElement.scrollHeight - window.innerHeight;
			if (max <= 0 || (window.scrollY / max) * 100 >= self.cfg.scrollPct) { scrollOk = true; window.removeEventListener('scroll', checkScroll); if (self.cfg.trigger === 'delay' && self.delayDone) { self.show(); } }
		};
		if (!scrollOk) { window.addEventListener('scroll', checkScroll, { passive: true }); }

		if (this.cfg.trigger === 'exit') {
			var armed = false;
			this.timers.push(setTimeout(function () { armed = true; }, Math.max(0, this.cfg.delay || 0) * 1000));
			var coarse = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;
			if (!coarse) {
				document.addEventListener('mouseout', function (e) {
					if (armed && scrollOk && !e.relatedTarget && !e.toElement && e.clientY <= 0) { self.show(); }
				});
			} else {
				// Touch devices have no cursor: fire on a quick upward scroll (heading for the address bar / back button).
				var lastY = window.scrollY, lastT = Date.now(), maxY = 0;
				window.addEventListener('scroll', function () {
					var y = window.scrollY, t = Date.now();
					maxY = Math.max(maxY, y);
					if (armed && scrollOk && maxY > 400 && lastY - y > 220 && t - lastT < 450) { self.show(); }
					lastY = y; lastT = t;
				}, { passive: true });
			}
		} else {
			this.timers.push(setTimeout(function () { self.delayDone = true; if (scrollOk) { self.show(); } }, Math.max(0, this.cfg.delay || 0) * 1000));
		}
	};

	Popup.prototype.show = function () {
		if (this.shown) { return; }
		var self = this;
		this.shown = true;
		this.el.hidden = false;
		// Reserve the "seen" cookie the moment it appears — the visitor never sees it twice within the period.
		if (!this.preview) { cookie.set(this.seenKey, '1', this.cfg.cookieDays || 0.5); }
		requestAnimationFrame(function () { self.el.classList.add('is-open'); });
		document.body.classList.add('whd-popup-open');
		try { this.dialog.focus({ preventScroll: true }); } catch (e) {}
		this.el.querySelectorAll('.whd-countdown').forEach(function (cd) { self.countdown(cd); });
		document.dispatchEvent(new CustomEvent('whd:popup:show', { detail: { id: this.id } }));
	};

	Popup.prototype.close = function () {
		var self = this;
		if (this.preview) { return; }
		this.el.classList.remove('is-open');
		document.body.classList.remove('whd-popup-open');
		this.timers.forEach(clearTimeout);
		setTimeout(function () { self.el.hidden = true; }, 350);
	};

	/** Evergreen countdown: the end time lives in a cookie so it survives reloads and page changes. */
	Popup.prototype.countdown = function (cd) {
		var self = this;
		var minutes = parseInt(cd.getAttribute('data-minutes'), 10) || 15;
		var key = 'whd_cd_' + this.id + '_' + (cd.getAttribute('data-key') || '0');
		var time = cd.querySelector('.whd-countdown__time');
		var end = this.preview ? Date.now() + minutes * 60000 : parseInt(cookie.get(key), 10);
		if (!end || isNaN(end)) {
			end = Date.now() + minutes * 60000;
			cookie.set(key, String(end), Math.max(this.cfg.cookieDays || 1, minutes / 1440));
		}
		var tick = function () {
			var left = end - Date.now();
			if (left <= 0) {
				time.textContent = '00:00';
				var expired = cd.getAttribute('data-expired');
				if (expired) { var label = cd.querySelector('.whd-countdown__label'); if (label) { label.textContent = expired; } }
				cd.classList.add('is-expired');
				if (cd.getAttribute('data-hide') === '1' && !self.preview) { self.close(); }
				return;
			}
			time.textContent = format(left);
			self.timers.push(setTimeout(tick, 1000));
		};
		tick();
	};

	function boot() {
		document.querySelectorAll('.whd-popup').forEach(function (el) { if (!el.__whd) { el.__whd = new Popup(el); } });
	}
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})();
