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
}());
