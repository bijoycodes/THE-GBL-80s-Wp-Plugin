(function () {
	'use strict';
	var root = document.getElementById('gbl-cp');
	if (!root || !document.querySelector) { return; }
	root.classList.add('is-js');

	var tabs = Array.prototype.slice.call(root.querySelectorAll('[data-tab]'));
	var panels = Array.prototype.slice.call(root.querySelectorAll('.gbl-cp__panel'));
	var ids = tabs.map(function (t) { return t.getAttribute('data-tab'); });
	var STEP = 12;

	/* ------------------------------------------------------------- tabs */
	function show(id, scroll) {
		if (ids.indexOf(id) < 0) { id = ids[0]; }
		tabs.forEach(function (t) {
			var on = t.getAttribute('data-tab') === id;
			t.classList.toggle('is-active', on);
			t.setAttribute('aria-selected', on ? 'true' : 'false');
			t.setAttribute('tabindex', on ? '0' : '-1');
		});
		panels.forEach(function (p) { p.hidden = p.id !== id; });
		if (scroll) {
			var bar = root.querySelector('.gbl-cp__tabs');
			if (bar) { window.scrollTo({ top: bar.getBoundingClientRect().top + window.pageYOffset - 0, behavior: 'smooth' }); }
		}
	}

	tabs.forEach(function (t, i) {
		t.addEventListener('click', function (e) {
			e.preventDefault();
			var id = t.getAttribute('data-tab');
			if (window.history && history.replaceState) { history.replaceState(null, '', '#' + id); }
			show(id, true);
		});
		t.addEventListener('keydown', function (e) {
			var n = null;
			if (e.key === 'ArrowRight') { n = tabs[(i + 1) % tabs.length]; }
			if (e.key === 'ArrowLeft') { n = tabs[(i - 1 + tabs.length) % tabs.length]; }
			if (n) { e.preventDefault(); n.focus(); n.click(); }
		});
	});
	window.addEventListener('hashchange', function () { show(location.hash.replace('#', ''), true); });
	var start = location.hash.replace('#', '');
	show(start, false);
	if (ids.indexOf(start) >= 0) { setTimeout(function () { show(start, true); }, 60); }

	/* ----------------------------------------------- filter + show more */
	function setupPanel(panel) {
		var q = panel.querySelector('[data-f="q"]');
		var type = panel.querySelector('[data-f="type"]');
		var year = panel.querySelector('[data-f="year"]');
		var count = panel.querySelector('.gbl-pf__count');
		var none = panel.querySelector('.gbl-cp__none');
		var groups = Array.prototype.slice.call(panel.querySelectorAll('.gbl-pgroup'));
		var limits = groups.map(function () { return STEP; });

		function run() {
			var words = q ? q.value.toLowerCase().split(/\s+/).filter(Boolean) : [];
			var tv = type ? type.value : '';
			var yv = year ? year.value : '';
			var total = 0, shown = 0;
			groups.forEach(function (g, gi) {
				var cards = Array.prototype.slice.call(g.querySelectorAll('.gbl-pc'));
				var matches = 0, visible = 0;
				cards.forEach(function (c) {
					var s = c.getAttribute('data-search') || '';
					var ok = (!tv || c.getAttribute('data-type') === tv) &&
						(!yv || c.getAttribute('data-year') === yv) &&
						words.every(function (w) { return s.indexOf(w) > -1; });
					if (ok) { matches++; }
					var show = ok && matches <= limits[gi];
					c.hidden = !show;
					if (show) { visible++; }
				});
				total += matches; shown += visible;
				g.hidden = matches === 0;
				var more = g.querySelector('.gbl-more');
				if (more) {
					more.hidden = matches <= limits[gi];
					more.textContent = 'Show more (' + (matches - limits[gi]) + ')';
				}
			});
			if (none) { none.hidden = total > 0; }
			if (count) { count.textContent = total + (total === 1 ? ' result' : ' results'); }
		}

		groups.forEach(function (g, gi) {
			var more = g.querySelector('.gbl-more');
			if (more) { more.addEventListener('click', function () { limits[gi] += STEP; run(); }); }
		});
		[q, type, year].forEach(function (el) {
			if (el) { el.addEventListener('input', function () { groups.forEach(function (g, i) { limits[i] = STEP; }); run(); }); el.addEventListener('change', run); }
		});
		run();
	}
	panels.forEach(setupPanel);

	/* ---------------------------------------------------------- lightbox */
	var lb = document.getElementById('gbl-lb');
	var body = document.getElementById('gbl-lb-body');
	var prevBtn = lb.querySelector('[data-lb-prev]');
	var nextBtn = lb.querySelector('[data-lb-next]');
	var current = null, list = [], lastFocus = null;

	function open(btn) {
		var panel = btn.closest('.gbl-cp__panel');
		list = Array.prototype.slice.call(panel.querySelectorAll('.gbl-pc:not([hidden]) [data-gbl-lb]'));
		current = list.indexOf(btn);
		fill();
		lastFocus = btn;
		lb.hidden = false;
		document.documentElement.style.overflow = 'hidden';
		lb.querySelector('.gbl-lb__x').focus();
	}
	function fill() {
		var src = document.getElementById('lb-' + list[current].getAttribute('data-gbl-lb'));
		body.innerHTML = src ? src.innerHTML : '';
		prevBtn.hidden = current <= 0;
		nextBtn.hidden = current >= list.length - 1;
		lb.scrollTop = 0;
	}
	function close() {
		lb.hidden = true; body.innerHTML = '';
		document.documentElement.style.overflow = '';
		if (lastFocus) { lastFocus.focus(); }
	}
	root.addEventListener('click', function (e) {
		var b = e.target.closest ? e.target.closest('[data-gbl-lb]') : null;
		if (b && !lb.contains(b)) { open(b); return; }
		if (e.target === lb || (e.target.closest && e.target.closest('[data-lb-close]'))) { close(); return; }
		if (e.target.closest && e.target.closest('[data-lb-prev]') && current > 0) { current--; fill(); }
		if (e.target.closest && e.target.closest('[data-lb-next]') && current < list.length - 1) { current++; fill(); }
	});
	document.addEventListener('keydown', function (e) {
		if (lb.hidden) { return; }
		if (e.key === 'Escape') { close(); }
		if (e.key === 'ArrowLeft' && current > 0) { current--; fill(); }
		if (e.key === 'ArrowRight' && current < list.length - 1) { current++; fill(); }
	});
}());
