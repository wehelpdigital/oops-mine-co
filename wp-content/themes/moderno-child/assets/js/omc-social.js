/* Oops, Mine Co. — "From our feed": swap the link card for the provider's embed
   once the card is close to the viewport (no dependencies, no third-party code
   runs until then). Cards without a <template> keep their link card. */
(function () {
	'use strict';

	var cards = document.querySelectorAll('.js-omc-embed');
	if (!cards.length) { return; }

	/* Scripts parsed out of a string never run on their own — copy each one into a
	   fresh element so the provider (TikTok's embed.js and friends) starts up. */
	var runScripts = function (holder) {
		var old = holder.querySelectorAll('script');
		Array.prototype.forEach.call(old, function (node) {
			var script = document.createElement('script');
			Array.prototype.forEach.call(node.attributes, function (attr) {
				script.setAttribute(attr.name, attr.value);
			});
			if (!node.src) { script.text = node.textContent; }
			node.parentNode.replaceChild(script, node);
		});
	};

	var load = function (card) {
		if (card.getAttribute('data-omc-loaded')) { return; }
		var tpl = card.querySelector('template');
		if (!tpl) { return; }
		card.setAttribute('data-omc-loaded', '1');

		var holder = document.createElement('div');
		holder.className = 'omc-feed__embed';
		holder.innerHTML = tpl.innerHTML;

		Array.prototype.forEach.call(holder.querySelectorAll('iframe'), function (frame) {
			frame.setAttribute('loading', 'lazy');
			if (!frame.getAttribute('title')) {
				frame.setAttribute('title', card.getAttribute('data-omc-title') || 'Video');
			}
		});

		card.innerHTML = '';
		card.appendChild(holder);
		card.classList.add('is-loaded');
		runScripts(holder);
	};

	if (!('IntersectionObserver' in window)) {
		Array.prototype.forEach.call(cards, load);
		return;
	}

	var io = new IntersectionObserver(function (entries) {
		entries.forEach(function (entry) {
			if (entry.isIntersecting) {
				io.unobserve(entry.target);
				load(entry.target);
			}
		});
	}, { rootMargin: '300px 0px' });

	Array.prototype.forEach.call(cards, function (card) { io.observe(card); });
}());
