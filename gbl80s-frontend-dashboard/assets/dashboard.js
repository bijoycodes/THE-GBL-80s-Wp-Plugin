(function () {
	'use strict';
	var app = document.getElementById('gbl-app');
	if (!app) { return; }
	var ajaxUrl = app.getAttribute('data-ajax');
	var nonce = app.getAttribute('data-nonce');
	var modal = document.getElementById('gbl-modal');
	var body = document.getElementById('gbl-modal-body');
	var toastEl = document.getElementById('gbl-toast');
	var toastTimer = null;

	function toast(msg, isError) {
		toastEl.textContent = msg;
		toastEl.className = 'gbl-toast is-on' + (isError ? ' is-err' : '');
		clearTimeout(toastTimer);
		toastTimer = setTimeout(function () { toastEl.className = 'gbl-toast'; }, 3200);
	}

	function post(action, data) {
		var fd = new FormData();
		fd.append('action', action);
		fd.append('nonce', nonce);
		Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
		return fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd }).then(function (r) {
			return r.json().catch(function () { return { success: false, data: { message: 'Unexpected response.' } }; });
		});
	}

	function failMsg(res) {
		return (res && res.data && res.data.message) ? res.data.message : 'Something went wrong.';
	}

	function closeMenus() {
		var pops = app.querySelectorAll('.gbl-pop');
		for (var i = 0; i < pops.length; i++) { pops[i].hidden = true; }
	}

	function openModal(id) {
		body.innerHTML = '<div class="gbl-d"><p>Loading...</p></div>';
		modal.hidden = false;
		post('gbl_fd_detail', { id: id }).then(function (res) {
			if (res.success) { body.innerHTML = res.data.html; } else { modal.hidden = true; toast(failMsg(res), true); }
		}).catch(function () { modal.hidden = true; toast('Network error.', true); });
	}

	function closeModal() { modal.hidden = true; body.innerHTML = ''; }

	function review(id, decision, note, button) {
		if (button) { button.disabled = true; }
		post('gbl_fd_review', { id: id, decision: decision, note: note || '' }).then(function (res) {
			if (res.success) {
				toast(res.data.message);
				setTimeout(function () { window.location.reload(); }, 700);
			} else {
				if (button) { button.disabled = false; }
				toast(failMsg(res), true);
			}
		}).catch(function () { if (button) { button.disabled = false; } toast('Network error.', true); });
	}

	document.addEventListener('click', function (e) {
		var t = e.target.closest ? e.target : null;
		if (!t) { return; }
		var el;

		if ((el = t.closest('[data-gbl-toggle]'))) {
			e.preventDefault();
			var pop = document.getElementById(el.getAttribute('data-gbl-toggle'));
			var wasHidden = pop && pop.hidden;
			closeMenus();
			if (pop && wasHidden) { pop.hidden = false; }
			return;
		}
		if (!t.closest('.gbl-pop')) { closeMenus(); }

		if ((el = t.closest('[data-gbl-open]'))) {
			e.preventDefault(); closeMenus(); openModal(el.getAttribute('data-gbl-open')); return;
		}
		if ((el = t.closest('[data-gbl-quick]'))) {
			e.preventDefault(); closeMenus();
			if (window.confirm('Approve and publish this item?')) { review(el.getAttribute('data-gbl-quick'), 'approve', '', null); }
			return;
		}
		if ((el = t.closest('[data-decision]'))) {
			var box = t.closest('.gbl-d__review');
			var note = box ? box.querySelector('textarea').value : '';
			var decision = el.getAttribute('data-decision');
			if (decision === 'remove' && !window.confirm('Move this item to Trash?')) { return; }
			review(box.getAttribute('data-id'), decision, note, el);
			return;
		}
		if (t.closest('[data-gbl-close]') || t === modal) { closeModal(); return; }
		if ((el = t.closest('[data-gbl-resolve]'))) {
			el.disabled = true;
			post('gbl_fd_resolve', { id: el.getAttribute('data-gbl-resolve') }).then(function (res) {
				if (res.success) { toast(res.data.message); setTimeout(function () { window.location.reload(); }, 600); }
				else { el.disabled = false; toast(failMsg(res), true); }
			});
			return;
		}
		if ((el = t.closest('[data-gbl-saveuser]'))) {
			var row = t.closest('tr');
			el.disabled = true;
			post('gbl_fd_user_update', {
				user_id: el.getAttribute('data-gbl-saveuser'),
				role: row.querySelector('[data-f="role"]').value,
				club: row.querySelector('[data-f="club"]').value
			}).then(function (res) {
				el.disabled = false;
				toast(res.success ? res.data.message : failMsg(res), !res.success);
			});
			return;
		}
		if (t.closest('[data-gbl-menu]')) { app.classList.toggle('is-nav'); return; }
		if (app.classList.contains('is-nav') && !t.closest('.gbl-side')) { app.classList.remove('is-nav'); }
	});

	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape') { closeModal(); closeMenus(); }
	});

	var clubForm = document.getElementById('gbl-clubform');
	if (clubForm) {
		clubForm.addEventListener('submit', function (e) {
			e.preventDefault();
			post('gbl_fd_club_profile', { club: clubForm.getAttribute('data-club'), description: clubForm.elements.description.value, history: clubForm.elements.history.value }).then(function (res) {
				toast(res.success ? res.data.message : failMsg(res), !res.success);
			});
		});
	}
	var repair = document.querySelector('[data-gbl-repair]');
	if (repair) {
		repair.addEventListener('click', function () {
			repair.disabled = true;
			post('gbl_fd_repair_clubs', {}).then(function (res) {
				repair.disabled = false;
				toast(res.success ? res.data.message : failMsg(res), !res.success);
			});
		});
	}

	var settings = document.getElementById('gbl-settings');
	if (settings) {
		settings.addEventListener('submit', function (e) {
			e.preventDefault();
			var data = {};
			Array.prototype.forEach.call(settings.elements, function (f) { if (f.name) { data[f.name] = f.value; } });
			post('gbl_fd_settings', data).then(function (res) {
				toast(res.success ? res.data.message : failMsg(res), !res.success);
			});
		});
	}
}());
