(function () {
	'use strict';

	document.addEventListener('click', function (event) {
		var button = event.target.closest('[data-copy-target]');
		var target;
		var originalLabel;

		if (!button) {
			return;
		}
		target = document.getElementById(button.getAttribute('data-copy-target'));
		if (!target) {
			return;
		}
		originalLabel = button.textContent;
		navigator.clipboard.writeText(target.value || target.textContent || '').then(function () {
			button.textContent = 'Skopiowano';
			window.setTimeout(function () {
				button.textContent = originalLabel;
			}, 1600);
		}).catch(function () {
			if (typeof target.select === 'function') {
				target.select();
				document.execCommand('copy');
				button.textContent = 'Skopiowano';
			}
		});
	});

	document.querySelectorAll('[data-feedback-form]').forEach(function (form) {
		var shell = form.closest('[data-feedback-storage]');
		var notes = form.querySelector('[data-feedback-notes]');
		var scope = form.querySelector('[data-feedback-scope]');
		var sectionField = form.querySelector('[data-section-field]');
		var saved = form.querySelector('[data-feedback-saved]');
		var key = shell ? shell.getAttribute('data-feedback-storage') : 'proposal-feedback';
		var timer;
		try {
			var draft = JSON.parse(window.localStorage.getItem(key) || '{}');
			if (draft.notes) notes.value = draft.notes;
			if (draft.scope && scope) scope.value = draft.scope;
		} catch (ignore) {}
		function updateScope() {
			if (sectionField && scope) sectionField.hidden = scope.value !== 'section';
		}
		function saveDraft() {
			window.clearTimeout(timer);
			timer = window.setTimeout(function () {
				try {
					window.localStorage.setItem(key, JSON.stringify({ notes: notes.value, scope: scope ? scope.value : 'auto' }));
					saved.textContent = 'Szkic uwag zapisany lokalnie.';
				} catch (ignore) {}
			}, 250);
		}
		updateScope();
		if (scope) scope.addEventListener('change', function () { updateScope(); saveDraft(); });
		notes.addEventListener('input', saveDraft);
		form.addEventListener('submit', function () { try { window.localStorage.removeItem(key); } catch (ignore) {} });
	});
}());
