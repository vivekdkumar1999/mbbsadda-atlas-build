/* Background loop videos: a section with data-bgv="NAME" gets a muted looping video behind it.
   The poster shows first; the video only loads when the section is on screen, pauses when it
   leaves, and never plays for people who ask for reduced motion or data saving. */
(function () {
	var secs = document.querySelectorAll('[data-bgv]');
	if (!secs.length) return;
	var base = (window.MBX_BGV || '');
	var calm = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
	var save = navigator.connection && (navigator.connection.saveData || /2g/.test(navigator.connection.effectiveType || ''));
	[].forEach.call(secs, function (s) {
		var n = s.getAttribute('data-bgv');
		s.classList.add('mbx-bgsec');
		var w = document.createElement('div');
		w.className = 'mbx-bgv'; w.setAttribute('aria-hidden', 'true');
		w.innerHTML = '<img class="mbx-bgv-poster" alt="" decoding="async" src="' + base + n + '.webp">';
		s.insertBefore(w, s.firstChild);
		if (calm || save || !window.IntersectionObserver) return;
		var v;
		new IntersectionObserver(function (es) {
			es.forEach(function (e) {
				if (e.isIntersecting) {
					if (!v) {
						v = document.createElement('video');
						v.muted = true; v.loop = true; v.playsInline = true; v.setAttribute('playsinline', ''); v.setAttribute('muted', '');
						v.preload = 'auto';
						v.innerHTML = '<source src="' + base + n + '.webm" type="video/webm"><source src="' + base + n + '.mp4" type="video/mp4">';
						v.addEventListener('playing', function () { w.classList.add('is-playing'); });
						w.appendChild(v);
					}
					var p = v.play(); if (p && p.catch) p.catch(function () {});
				} else if (v) { v.pause(); }
			});
		}, { rootMargin: '120px 0px' }).observe(s);
	});
})();
