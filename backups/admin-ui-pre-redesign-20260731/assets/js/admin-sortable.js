(function () {
    'use strict';

    let draggedItem = null;

    function getList(element) {
        return element instanceof Element ? element.closest('.admin-sortable-list') : null;
    }

    function persistOrder(list) {
        const endpoint = list.dataset.sortUrl;
        const action = list.dataset.sortAction;
        const csrf = list.dataset.sortCsrf;

        if (!endpoint || !action || !csrf) {
            return;
        }

        const data = new FormData();
        data.append('csrf', csrf);
        data.append('action', action);
        list.querySelectorAll('.admin-sortable-item[data-sort-id]').forEach(function (item) {
            data.append('order[]', item.dataset.sortId);
        });

        list.classList.add('is-saving-order');
        window.fetch(endpoint, {
            method: 'POST',
            body: data,
            credentials: 'same-origin',
            headers: {'X-Requested-With': 'XMLHttpRequest'}
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('Nie udało się zapisać kolejności.');
                }
            })
            .catch(function () {
                window.alert('Nie udało się zapisać kolejności. Spróbuj ponownie.');
            })
            .finally(function () {
                list.classList.remove('is-saving-order');
            });
    }

    document.addEventListener('dragstart', function (event) {
        const item = event.target.closest('.admin-sortable-item[draggable="true"]');

        if (!item) {
            return;
        }

        draggedItem = item;
        item.classList.add('is-dragging');
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', item.dataset.sortId || '');
    });

    document.addEventListener('dragover', function (event) {
        const list = getList(event.target);

        if (!list || !draggedItem || !list.contains(draggedItem)) {
            return;
        }

        event.preventDefault();
        const target = event.target.closest('.admin-sortable-item[data-sort-id]');

        if (!target || target === draggedItem) {
            return;
        }

        const bounds = target.getBoundingClientRect();
        const isGrid = window.getComputedStyle(list).display === 'grid';
        const verticalMiddle = bounds.top + bounds.height / 2;
        const horizontalMiddle = bounds.left + bounds.width / 2;
        const pointerInsideRow = event.clientY >= bounds.top && event.clientY <= bounds.bottom;
        const insertAfter = isGrid && pointerInsideRow
            ? event.clientX > horizontalMiddle
            : event.clientY > verticalMiddle;
        list.insertBefore(draggedItem, insertAfter ? target.nextSibling : target);
    });

    document.addEventListener('dragend', function () {
        if (!draggedItem) {
            return;
        }

        const list = getList(draggedItem);
        draggedItem.classList.remove('is-dragging');
        draggedItem = null;

        if (list) {
            persistOrder(list);
        }
    });
}());
