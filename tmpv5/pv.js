/* MBBS Adda v3: tables with 3+ columns get a label on each cell, so on phones every row
   can show as a card (see v3.css) instead of a table you have to scroll sideways. */
(function () {
	function prep(t) {
		if (t.dataset.mbxCards) return;
		t.dataset.mbxCards = '1';
		var head = t.querySelector('thead tr') || t.querySelector('tr');
		if (!head) return;
		var labels = [].map.call(head.children, function (c) { return (c.textContent || '').trim(); });
		if (labels.length < 3) return;
		t.classList.add('mbx-cards');
		if (labels.length === 3) t.classList.add('mbx-c3');
		var rows = t.querySelectorAll('tbody tr');
		if (!t.querySelector('thead')) rows = [].slice.call(t.querySelectorAll('tr'), 1);
		[].forEach.call(rows, function (tr) {
			[].forEach.call(tr.children, function (td, i) {
				if (labels[i] && !td.hasAttribute('data-l')) td.setAttribute('data-l', labels[i]);
			});
		});
	}
	function scan(root) {
		[].forEach.call((root || document).querySelectorAll('table.mqb-table, .kqb-answer table'), prep);
	}
	scan();
	if (window.MutationObserver) {
		var pend = 0;
		new MutationObserver(function () {
			if (pend) return;
			pend = requestAnimationFrame(function () { pend = 0; scan(); });
		}).observe(document.body, { childList: true, subtree: true });
	}
})();

/* Subject pages: the hero becomes a night card with the subject's 3D loop behind it. */
(function () {
	var hero = document.querySelector('.kqb > .kqb-hero:not(.kqb-single-head)');
	if (!hero || hero.querySelector('.kqb-hero-card')) return;
	var slug = (location.pathname.split('/')[1] || '').toLowerCase();
	var map = [
		[/anatom|forensic|ortho/, 'L5'], [/micro|community|psm|paed|pedia|anaes/, 'L1'], [/physio|medicine|cardio/, 'L3'],
		[/bioch|^ent\b|ophth|derma|psych/, 'L2'], [/patho|pharma|surg|obst|gyn|obg/, 'L4']
	];
	var v = 'L1';
	for (var i = 0; i < map.length; i++) { if (map[i][0].test(slug)) { v = map[i][1]; break; } }
	var card = document.createElement('div');
	card.className = 'kqb-hero-card';
	while (hero.firstChild) card.appendChild(hero.firstChild);
	hero.appendChild(card);
	card.setAttribute('data-bgv', v);
})();

/* Answer pages: a reading-progress line, and on wide screens an "In this answer" list. */
(function () {
	var body = document.querySelector('.kqb-single .kqb-single-body');
	if (!body) return;
	var bar = document.createElement('div'); bar.className = 'mbx-progress'; bar.setAttribute('aria-hidden', 'true');
	document.body.appendChild(bar);
	var heads = [].slice.call(body.querySelectorAll('.kqb-answer .mqb-sec'));
	var toc, links = [];
	if (heads.length >= 3) {
		toc = document.createElement('nav'); toc.className = 'mbx-toc'; toc.setAttribute('aria-label', 'In this answer');
		toc.innerHTML = '<b>In this answer</b>';
		heads.forEach(function (h, i) {
			if (!h.id) h.id = 'sec-' + (i + 1);
			var a = document.createElement('a'); a.href = '#' + h.id; a.textContent = (h.textContent || '').trim();
			toc.appendChild(a); links.push(a);
		});
		document.body.appendChild(toc);
	}
	var ticking = 0;
	function update() {
		ticking = 0;
		var r = body.getBoundingClientRect(), vh = window.innerHeight;
		var p = Math.min(1, Math.max(0, (vh * 0.35 - r.top) / Math.max(1, r.height - vh * 0.5)));
		bar.style.transform = 'scaleX(' + p.toFixed(4) + ')';
		if (toc) {
			toc.style.left = Math.max(12, r.left - 250) + 'px';
			var on = 0;
			for (var i = 0; i < heads.length; i++) { if (heads[i].getBoundingClientRect().top < 140) on = i; }
			links.forEach(function (a, i) { a.classList.toggle('is-on', i === on); });
			toc.style.visibility = r.top < 110 && r.bottom > vh * 0.4 ? 'visible' : 'hidden';
		}
	}
	function req() { if (!ticking) { ticking = 1; requestAnimationFrame(update); } }
	window.addEventListener('scroll', req, { passive: true });
	window.addEventListener('resize', req);
	update();
})();

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
		w.innerHTML = '<img class="mbx-bgv-poster" alt="" decoding="async" onerror="this.remove()" src="' + base + n + '.webp">';
		s.insertBefore(w, s.firstChild);
		// the loops are transparent VP9; Safari can't draw that, so it keeps the still
		var safari = /^((?!chrome|android|crios|fxios|edg).)*safari/i.test(navigator.userAgent);
		if (calm || save || safari || !window.IntersectionObserver) return;
		var v;
		new IntersectionObserver(function (es) {
			es.forEach(function (e) {
				if (e.isIntersecting) {
					if (!v) {
						v = document.createElement('video');
						v.muted = true; v.loop = true; v.playsInline = true; v.setAttribute('playsinline', ''); v.setAttribute('muted', '');
						v.preload = 'auto';
						v.innerHTML = '<source src="' + base + n + '.webm" type="video/webm">';
						v.addEventListener('playing', function () { w.classList.add('is-playing'); });
						var last = v.querySelector('source:last-child');
						if (last) last.addEventListener('error', function () { v.remove(); });
						w.appendChild(v);
					}
					var p = v.play(); if (p && p.catch) p.catch(function () {});
				} else if (v) { v.pause(); }
			});
		}, { rootMargin: '120px 0px' }).observe(s);
	});
})();
