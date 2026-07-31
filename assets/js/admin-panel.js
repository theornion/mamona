(function () {
	'use strict';

	var isLoading = false;

	function syncAdminPanelLinks() {
		if (typeof window.buenoSyncNavPanel === 'function') {
			window.buenoSyncNavPanel();
		}
	}

	function syncAdminNavigation() {
		var main = document.querySelector('.admin-main');
		var toggle = document.querySelector('#navPanelToggle');

		if (!main) {
			return;
		}

		// The desktop nav is sticky inside the admin shell, so it never needs a
		// second hamburger while the content is being scrolled.
		var collapsed = false;
		document.body.classList.toggle('admin-nav-collapsed', collapsed);

		if (toggle) {
			toggle.classList.toggle('admin-nav-scroll-visible', collapsed);
		}

		syncAdminPanelLinks();
	}

	function bindAdminNavigationScroll() {
		var main = document.querySelector('.admin-main');

		if (!main || main.dataset.navScrollBound === 'true') {
			syncAdminNavigation();
			return;
		}

		main.dataset.navScrollBound = 'true';
		window.addEventListener('resize', function () {
			window.requestAnimationFrame(syncAdminNavigation);
		});
		syncAdminNavigation();
	}

	function isAdminRoute(url) {
		return url.origin === window.location.origin
			&& /\/admin-[a-z-]+\.php$/i.test(url.pathname);
	}

	function runInlineScripts(container) {
		container.querySelectorAll('script').forEach(function (script) {
			var replacement = document.createElement('script');

			if (script.src) {
				replacement.src = script.src;
			} else {
				replacement.textContent = script.textContent;
			}

			script.replaceWith(replacement);
		});
	}

	function replacePanel(documentText, url, shouldPushHistory) {
		var parser = new DOMParser();
		var nextDocument = parser.parseFromString(documentText, 'text/html');
		var currentMain = document.querySelector('.admin-main');
		var nextMain = nextDocument.querySelector('.admin-main');
		var currentCard = currentMain ? currentMain.querySelector('.admin-card') : null;
		var nextCard = nextMain ? nextMain.querySelector('.admin-card') : null;
		var currentNav = document.querySelector('.admin-nav');
		var nextNav = nextDocument.querySelector('.admin-nav');
		var currentContext = document.querySelector('.admin-context');
		var nextContext = nextDocument.querySelector('.admin-context');

		if (!currentMain || !nextMain || !nextNav) {
			window.location.assign(url);
			return;
		}

		if (currentCard && nextCard) {
			currentCard.className = nextCard.className;
			currentCard.innerHTML = nextCard.innerHTML;
			runInlineScripts(currentCard);
		} else {
			currentMain.innerHTML = nextMain.innerHTML;
			runInlineScripts(currentMain);
		}

		if (currentNav) {
			currentNav.replaceWith(nextNav);
		}

		if (currentContext && nextContext) {
			currentContext.replaceWith(nextContext);
		}

		document.title = nextDocument.title;

		if (shouldPushHistory) {
			window.history.pushState({}, '', url);
		}

		bindAdminNavigationScroll();
		closeAdminMenu();
		if (typeof window.buenoInitPostEditor === 'function') {
			window.buenoInitPostEditor();
		}
		if (typeof window.buenoInitGalleryCrop === 'function') {
			window.buenoInitGalleryCrop();
		}
	}

	function closeAdminMenu() {
		document.body.classList.remove('admin-menu-open');
		var toggle = document.querySelector('.admin-nav-toggle');
		if (toggle) {
			toggle.setAttribute('aria-expanded', 'false');
		}
	}

	function dismissConfigReminder() {
		var reminder = document.querySelector('[data-admin-config-reminder]');

		if (!reminder) {
			return;
		}

		reminder.hidden = true;
		try {
			window.sessionStorage.setItem('mamona-admin-config-reminder-dismissed', '1');
		} catch (error) {
			// The reminder is still dismissed for the current document when
			// storage is unavailable (for example in strict privacy mode).
		}
	}

	function restoreConfigReminderState() {
		var reminder = document.querySelector('[data-admin-config-reminder]');

		if (!reminder) {
			return;
		}

		try {
			reminder.hidden = window.sessionStorage.getItem('mamona-admin-config-reminder-dismissed') === '1';
		} catch (error) {
			reminder.hidden = false;
		}
	}

	document.addEventListener('click', function (event) {
		var toggle = event.target.closest('.admin-nav-toggle');

		if (toggle) {
			var isOpen = document.body.classList.toggle('admin-menu-open');
			toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
			return;
		}

		if (event.target.closest('.admin-nav-close, .admin-nav-backdrop')) {
			closeAdminMenu();
		}

		if (event.target.closest('[data-admin-config-reminder-close]')) {
			dismissConfigReminder();
		}
	});

	document.addEventListener('keydown', function (event) {
		if (event.key === 'Escape') {
			closeAdminMenu();
		}
	});

	function loadPanel(url, options) {
		if (isLoading) {
			return;
		}

		isLoading = true;
		document.documentElement.classList.add('admin-panel-loading');

		return window.fetch(url, {
			method: options && options.method ? options.method : 'GET',
			body: options && options.body ? options.body : undefined,
			credentials: 'same-origin',
			headers: {
				'X-Requested-With': 'XMLHttpRequest'
			}
		})
			.then(function (response) {
				if (!response.ok) {
					throw new Error('Nie udało się pobrać panelu.');
				}

				return response.text().then(function (documentText) {
					return {documentText: documentText, url: response.url || url};
				});
			})
			.then(function (result) {
				replacePanel(result.documentText, result.url, !options || options.pushHistory !== false);
			})
			.catch(function () {
				window.location.assign(url);
			})
			.finally(function () {
				isLoading = false;
				document.documentElement.classList.remove('admin-panel-loading');
			});
	}

	document.addEventListener('click', function (event) {
		var link = event.target.closest('a');

		if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || link.target || link.hasAttribute('download')) {
			return;
		}

		var url = new URL(link.href, window.location.href);

		if (url.hash === '#navPanel') {
			return;
		}

		if (!isAdminRoute(url)) {
			return;
		}

		event.preventDefault();
		loadPanel(url.href);
	});

	document.addEventListener('submit', function (event) {
		var form = event.target;

		if (event.defaultPrevented || !(form instanceof HTMLFormElement) || !form.closest('.admin-main') || form.target || form.method.toLowerCase() !== 'post') {
			return;
		}

		var url = new URL(form.action || window.location.href, window.location.href);

		if (!isAdminRoute(url)) {
			return;
		}

		var formData = new FormData(form);

		if (event.submitter && event.submitter.name) {
			formData.append(event.submitter.name, event.submitter.value);
		}

		event.preventDefault();
		loadPanel(url.href, {
			method: 'POST',
			body: formData,
			pushHistory: true
		});
	});

	document.addEventListener('change', function (event) {
		var target = event.target;

		if (!(target instanceof HTMLInputElement)) {
			return;
		}

		var trashForm = target.closest('.admin-trash-selection');

		if (trashForm) {
			var trashCheckboxes = Array.prototype.slice.call(trashForm.querySelectorAll('input[name="items[]"]'));
			var trashSelectAll = trashForm.querySelector('[data-select-all]');

			if (target.matches('[data-select-all]')) {
				trashCheckboxes.forEach(function (checkbox) {
					checkbox.checked = target.checked;
				});
				return;
			}

			if (target.matches('input[name="items[]"]') && trashSelectAll) {
				var trashSelected = trashCheckboxes.filter(function (checkbox) {
					return checkbox.checked;
				}).length;
				trashSelectAll.checked = trashSelected === trashCheckboxes.length && trashSelected > 0;
				trashSelectAll.indeterminate = trashSelected > 0 && trashSelected < trashCheckboxes.length;
			}
			return;
		}

		var form = target.closest('.admin-message-selection');

		if (!form) {
			return;
		}

		var messageCheckboxes = Array.prototype.slice.call(form.querySelectorAll('.admin-message-select input'));
		var selectAll = form.querySelector('.admin-message-select-all input');

		if (target.matches('.admin-message-select-all input')) {
			messageCheckboxes.forEach(function (checkbox) {
				checkbox.checked = target.checked;
			});
			return;
		}

		if (target.matches('.admin-message-select input') && selectAll) {
			var selectedCount = messageCheckboxes.filter(function (checkbox) {
				return checkbox.checked;
			}).length;
			selectAll.checked = selectedCount === messageCheckboxes.length && selectedCount > 0;
			selectAll.indeterminate = selectedCount > 0 && selectedCount < messageCheckboxes.length;
		}
	});

	window.addEventListener('popstate', function () {
		loadPanel(window.location.href, {pushHistory: false});
	});

	bindAdminNavigationScroll();
	restoreConfigReminderState();
}());
