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
