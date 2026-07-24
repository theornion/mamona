(function () {
    function initializePostEditorPage() {
    const container = document.querySelector('[data-existing-count]');
    const addButton = document.querySelector('[data-add-post-image]');
    const content = document.querySelector('[data-post-content-dropzone]');

    if (!container || !addButton || !content) return;
    if (container.dataset.postEditorBound === 'true') return;
    container.dataset.postEditorBound = 'true';

    const clamp = (value, minimum, maximum) => Math.min(maximum, Math.max(minimum, value));
    const minimumCropSize = 0.08;
    let nextIndex = Number(container.dataset.existingCount || 0) + 1;
    let lastCaret = content.value.length;

    function normalizeCrop(value, image) {
        let crop = value;
        if (typeof crop === 'string') {
            try { crop = JSON.parse(crop); } catch (error) { crop = {}; }
        }
        if (!crop || typeof crop !== 'object') crop = {};
        const x = clamp(Number(crop.x) || 0, 0, 1 - minimumCropSize);
        const y = clamp(Number(crop.y) || 0, 0, 1 - minimumCropSize);
        const width = clamp(Number(crop.width) || 1, minimumCropSize, 1 - x);
        const height = clamp(Number(crop.height) || 1, minimumCropSize, 1 - y);
        return {
            x,
            y,
            width,
            height,
            imageWidth: image && image.naturalWidth ? image.naturalWidth : (Number(crop.imageWidth) || 0),
            imageHeight: image && image.naturalHeight ? image.naturalHeight : (Number(crop.imageHeight) || 0)
        };
    }

    function cropMasks(editor, box) {
        let masks = Array.from(editor.querySelectorAll('[data-crop-mask]'));

        if (masks.length === 4) return masks;
        masks.forEach((mask) => mask.remove());
        masks = ['top', 'right', 'bottom', 'left'].map((side) => {
            const mask = document.createElement('span');
            mask.className = `admin-crop-mask admin-crop-mask--${side}`;
            mask.dataset.cropMask = side;
            editor.insertBefore(mask, box);
            return mask;
        });
        return masks;
    }

    function saveCrop(editor, crop) {
        const image = editor.querySelector('[data-crop-image]');
        const input = editor.querySelector('[data-crop-value]');
        const box = editor.querySelector('[data-crop-box]');
        crop = normalizeCrop(crop, image);
        box.style.left = `${crop.x * 100}%`;
        box.style.top = `${crop.y * 100}%`;
        box.style.width = `${crop.width * 100}%`;
        box.style.height = `${crop.height * 100}%`;
        const masks = Object.fromEntries(cropMasks(editor, box).map((mask) => [mask.dataset.cropMask, mask]));
        masks.top.style.height = `${crop.y * 100}%`;
        masks.bottom.style.top = `${(crop.y + crop.height) * 100}%`;
        masks.bottom.style.height = `${Math.max(0, 1 - crop.y - crop.height) * 100}%`;
        masks.left.style.top = `${crop.y * 100}%`;
        masks.left.style.width = `${crop.x * 100}%`;
        masks.left.style.height = `${crop.height * 100}%`;
        masks.right.style.top = `${crop.y * 100}%`;
        masks.right.style.left = `${(crop.x + crop.width) * 100}%`;
        masks.right.style.width = `${Math.max(0, 1 - crop.x - crop.width) * 100}%`;
        masks.right.style.height = `${crop.height * 100}%`;
        input.value = JSON.stringify(crop);
        editor._buenoCrop = crop;
    }

    function initializeCropEditor(editor, resetCrop) {
        if (!editor) return;
        const image = editor.querySelector('[data-crop-image]');
        const input = editor.querySelector('[data-crop-value]');
        const box = editor.querySelector('[data-crop-box]');
        if (!image || !input || !box) return;

        const ready = function () {
            editor.hidden = false;
            saveCrop(editor, resetCrop ? {} : input.value);
        };
        if (image.complete && image.naturalWidth) ready();
        else image.addEventListener('load', ready, { once: true });

        if (editor.dataset.cropBound === 'true') return;

        box.addEventListener('dragstart', (event) => event.preventDefault());
        box.addEventListener('pointerdown', function (event) {
            if (event.isPrimary === false || (event.pointerType === 'mouse' && event.button !== 0)) return;
            event.preventDefault();
            event.stopPropagation();

            const handle = event.target.closest('[data-crop-handle]');
            const direction = handle ? handle.dataset.cropHandle : 'move';
            const startX = event.clientX;
            const startY = event.clientY;
            const start = { ...editor._buenoCrop };
            const editorRect = editor.getBoundingClientRect();
            const card = editor.closest('[data-post-image-card]');
            if (card) card.draggable = false;
            try { box.setPointerCapture(event.pointerId); } catch (error) {}

            const move = function (moveEvent) {
                if (moveEvent.pointerId !== event.pointerId) return;
                moveEvent.preventDefault();
                const dx = (moveEvent.clientX - startX) / Math.max(1, editorRect.width);
                const dy = (moveEvent.clientY - startY) / Math.max(1, editorRect.height);
                const crop = { ...start };

                if (direction === 'move') {
                    crop.x = clamp(start.x + dx, 0, 1 - start.width);
                    crop.y = clamp(start.y + dy, 0, 1 - start.height);
                } else {
                    if (direction.includes('w')) {
                        crop.x = clamp(start.x + dx, 0, start.x + start.width - minimumCropSize);
                        crop.width = start.width + start.x - crop.x;
                    }
                    if (direction.includes('e')) {
                        crop.width = clamp(start.width + dx, minimumCropSize, 1 - start.x);
                    }
                    if (direction.includes('n')) {
                        crop.y = clamp(start.y + dy, 0, start.y + start.height - minimumCropSize);
                        crop.height = start.height + start.y - crop.y;
                    }
                    if (direction.includes('s')) {
                        crop.height = clamp(start.height + dy, minimumCropSize, 1 - start.y);
                    }
                }
                saveCrop(editor, crop);
            };

            const finish = function (finishEvent) {
                if (finishEvent.pointerId !== event.pointerId) return;
                window.removeEventListener('pointermove', move);
                window.removeEventListener('pointerup', finish);
                window.removeEventListener('pointercancel', finish);
                if (card) card.draggable = true;
            };
            window.addEventListener('pointermove', move, { passive: false });
            window.addEventListener('pointerup', finish);
            window.addEventListener('pointercancel', finish);
        });
        editor.dataset.cropBound = 'true';
    }

    function loadFileIntoCropEditor(fileInput, editor) {
        const file = fileInput.files && fileInput.files[0];
        if (!file) return;
        const image = editor.querySelector('[data-crop-image]');
        const input = editor.querySelector('[data-crop-value]');
        if (editor._buenoObjectUrl) URL.revokeObjectURL(editor._buenoObjectUrl);
        editor._buenoObjectUrl = URL.createObjectURL(file);
        editor.hidden = false;
        input.value = '';
        image.src = editor._buenoObjectUrl;
        initializeCropEditor(editor, true);
    }

    function rememberCaret() {
        lastCaret = typeof content.selectionStart === 'number' ? content.selectionStart : content.value.length;
    }

    function insertToken(token) {
        const insertion = `\n${token}\n`;
        const rememberedCaret = clamp(Number(lastCaret) || 0, 0, content.value.length);
        const start = document.activeElement === content && typeof content.selectionStart === 'number'
            ? content.selectionStart
            : rememberedCaret;
        const end = document.activeElement === content && typeof content.selectionEnd === 'number'
            ? content.selectionEnd
            : rememberedCaret;
        content.focus();
        content.setRangeText(insertion, start, end, 'end');
        rememberCaret();
        content.dispatchEvent(new Event('input', { bubbles: true }));
    }

    function renumberImageCards(removedToken) {
        const cards = Array.from(container.querySelectorAll('[data-post-image-card]'));
        const tokenMap = {};
        if (removedToken) tokenMap[removedToken] = '';

        cards.forEach((card, index) => {
            const oldToken = card.dataset.imageToken;
            const newToken = `[[Z${index + 1}]]`;
            tokenMap[oldToken] = newToken;
            card.dataset.imageToken = newToken;
            const label = card.querySelector('.admin-post-image-card-header strong');
            if (label) {
                label.textContent = `${card.classList.contains('admin-post-content-image-input') ? 'Nowe zdjęcie' : 'Zdjęcie'} ${newToken}`;
            }
            const removeButton = card.querySelector('[data-remove-post-image]');
            if (removeButton) removeButton.setAttribute('aria-label', `Usuń zdjęcie ${index + 1}`);
        });

        content.value = content.value.replace(/\[\[Z\d+\]\]/g, (token) => Object.prototype.hasOwnProperty.call(tokenMap, token) ? tokenMap[token] : token);
        content.dispatchEvent(new Event('input', { bubbles: true }));
        nextIndex = cards.length;
        rememberCaret();
    }

    function removeImageCard(card) {
        const removedToken = card.dataset.imageToken || '';
        const editor = card.querySelector('[data-crop-editor]');
        if (editor && editor._buenoObjectUrl) URL.revokeObjectURL(editor._buenoObjectUrl);
        card.remove();
        renumberImageCards(removedToken);
    }

    function wireCard(card) {
        const currentToken = () => card.dataset.imageToken;
        const insertButton = card.querySelector('[data-insert-image-token]');
        const fileInput = card.querySelector('[data-content-image-input]');
        const editor = card.querySelector('[data-crop-editor]');
        const touchDragHandle = card.querySelector('.admin-post-image-card-header strong');
        const removeButton = card.querySelector('[data-remove-post-image]');

        if (fileInput && insertButton) insertButton.disabled = !(fileInput.files && fileInput.files.length);
        if (insertButton) insertButton.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            insertToken(currentToken());
        });
        if (removeButton) removeButton.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            removeImageCard(card);
        });
        card.addEventListener('dragstart', function (event) {
            if (fileInput && !(fileInput.files && fileInput.files.length)) {
                event.preventDefault();
                card.classList.add('needs-image');
                return;
            }
            if (event.target.closest('.admin-crop-editor, button, input')) {
                event.preventDefault();
                return;
            }
            event.dataTransfer.effectAllowed = 'copy';
            event.dataTransfer.setData('text/plain', currentToken());
            card.classList.add('is-dragging');
        });
        card.addEventListener('dragend', () => card.classList.remove('is-dragging'));

        if (touchDragHandle) {
            touchDragHandle.addEventListener('pointerdown', function (event) {
                if (event.pointerType === 'mouse' || event.isPrimary === false) return;
                if (fileInput && !(fileInput.files && fileInput.files.length)) {
                    card.classList.add('needs-image');
                    return;
                }

                event.preventDefault();
                event.stopPropagation();
                const pointerId = event.pointerId;
                const startX = event.clientX;
                const startY = event.clientY;
                let dragging = false;
                let isOverContent = false;
                let ghost = null;

                try { touchDragHandle.setPointerCapture(pointerId); } catch (error) {}

                const move = function (moveEvent) {
                    if (moveEvent.pointerId !== pointerId) return;
                    const distance = Math.hypot(moveEvent.clientX - startX, moveEvent.clientY - startY);

                    if (!dragging && distance >= 8) {
                        dragging = true;
                        card.classList.add('is-dragging');
                        ghost = document.createElement('div');
                        ghost.className = 'admin-post-touch-drag-ghost';
                        ghost.textContent = `Wstaw ${currentToken()}`;
                        document.body.appendChild(ghost);
                    }

                    if (!dragging) return;
                    moveEvent.preventDefault();
                    ghost.style.left = `${moveEvent.clientX}px`;
                    ghost.style.top = `${moveEvent.clientY}px`;
                    const contentRect = content.getBoundingClientRect();
                    isOverContent = moveEvent.clientX >= contentRect.left
                        && moveEvent.clientX <= contentRect.right
                        && moveEvent.clientY >= contentRect.top
                        && moveEvent.clientY <= contentRect.bottom;
                    content.classList.toggle('is-image-drop-target', isOverContent);
                };

                const finish = function (finishEvent) {
                    if (finishEvent.pointerId !== pointerId) return;
                    window.removeEventListener('pointermove', move);
                    window.removeEventListener('pointerup', finish);
                    window.removeEventListener('pointercancel', finish);
                    card.classList.remove('is-dragging');
                    content.classList.remove('is-image-drop-target');
                    if (ghost) ghost.remove();
                    if (dragging && isOverContent && finishEvent.type !== 'pointercancel') insertToken(currentToken());
                };

                window.addEventListener('pointermove', move, { passive: false });
                window.addEventListener('pointerup', finish);
                window.addEventListener('pointercancel', finish);
            });
        }

        if (editor && !editor.hidden) initializeCropEditor(editor, false);
        if (fileInput && editor) {
            fileInput.addEventListener('change', function () {
                loadFileIntoCropEditor(fileInput, editor);
                if (insertButton) insertButton.disabled = !(fileInput.files && fileInput.files.length);
                card.classList.remove('needs-image');
            });
        }
    }

    function createNewCard(index) {
        const card = document.createElement('article');
        const token = `[[Z${index}]]`;
        card.className = 'admin-post-content-image-card admin-post-content-image-input';
        card.dataset.postImageCard = '';
        card.dataset.imageToken = token;
        card.draggable = true;
        card.innerHTML = `
            <header class="admin-post-image-card-header">
                <strong>Nowe zdjęcie ${token}</strong>
                <span class="admin-post-image-actions">
                    <button type="button" data-insert-image-token disabled>Wstaw zdjęcie tutaj</button>
                    <button class="admin-message-quick-trash admin-post-image-remove" type="button" data-remove-post-image title="Usuń zdjęcie" aria-label="Usuń zdjęcie ${index}">
                        <img src="../images/icons/kosz.svg" alt="" aria-hidden="true">
                    </button>
                </span>
            </header>
            <input id="content_image_${index}" type="file" name="content_images[]" accept="image/jpeg,image/png,image/webp" data-content-image-input>
            <div class="admin-crop-editor" data-crop-editor hidden>
                <img alt="Podgląd nowego zdjęcia" data-crop-image>
                <div class="admin-crop-box" data-crop-box><span data-crop-handle="nw"></span><span data-crop-handle="n"></span><span data-crop-handle="ne"></span><span data-crop-handle="e"></span><span data-crop-handle="se"></span><span data-crop-handle="s"></span><span data-crop-handle="sw"></span><span data-crop-handle="w"></span></div>
                <input type="hidden" name="content_image_crops[]" value="" data-crop-value>
            </div>
            <p class="admin-post-image-drag-hint">Po wybraniu pliku ustaw kadr. Aby wstawić zdjęcie, użyj przycisku lub przeciągnij napis „Zdjęcie” na pole treści.</p>
        `;
        wireCard(card);
        return card;
    }

    content.addEventListener('click', rememberCaret);
    content.addEventListener('input', rememberCaret);
    content.addEventListener('focus', rememberCaret);
    content.addEventListener('blur', rememberCaret);
    content.addEventListener('mouseup', rememberCaret);
    content.addEventListener('keyup', rememberCaret);
    content.addEventListener('select', rememberCaret);
    content.addEventListener('touchend', function () {
        window.setTimeout(rememberCaret, 0);
    }, { passive: true });
    content.addEventListener('dragover', function (event) {
        if (!event.dataTransfer.types.includes('text/plain')) return;
        event.preventDefault();
        event.dataTransfer.dropEffect = 'copy';
        content.classList.add('is-image-drop-target');
    });
    content.addEventListener('dragleave', () => content.classList.remove('is-image-drop-target'));
    content.addEventListener('drop', function (event) {
        const token = event.dataTransfer.getData('text/plain');
        if (!/^\[\[Z\d+\]\]$/.test(token)) return;
        event.preventDefault();
        content.classList.remove('is-image-drop-target');
        insertToken(token);
    });

    container.querySelectorAll('[data-post-image-card]').forEach(wireCard);

    const mainInput = document.querySelector('[data-main-image-input]');
    const mainEditor = mainInput && mainInput.parentElement.querySelector('.admin-post-main-image-card [data-crop-editor]');
    const mainRemoveInput = document.querySelector('[data-remove-main-image]');
    const mainRemoveButton = document.querySelector('[data-remove-main-image-button]');
    if (mainEditor && !mainEditor.hidden) initializeCropEditor(mainEditor, false);
    if (mainInput && mainEditor) {
        mainInput.addEventListener('change', () => {
            if (!(mainInput.files && mainInput.files.length)) return;
            loadFileIntoCropEditor(mainInput, mainEditor);
            if (mainRemoveInput) mainRemoveInput.value = '0';
            if (mainRemoveButton) mainRemoveButton.hidden = false;
        });
    }
    if (mainRemoveButton && mainInput && mainEditor) {
        mainRemoveButton.addEventListener('click', function () {
            mainInput.value = '';
            if (mainRemoveInput) mainRemoveInput.value = '1';
            if (mainEditor._buenoObjectUrl) {
                URL.revokeObjectURL(mainEditor._buenoObjectUrl);
                mainEditor._buenoObjectUrl = '';
            }
            const mainImage = mainEditor.querySelector('[data-crop-image]');
            const cropInput = mainEditor.querySelector('[data-crop-value]');
            if (mainImage) mainImage.removeAttribute('src');
            if (cropInput) cropInput.value = '';
            mainEditor.hidden = true;
            mainRemoveButton.hidden = true;
        });
    }

    addButton.addEventListener('click', function () {
        const lastNewInput = container.querySelector('.admin-post-content-image-input:last-of-type [data-content-image-input]');
        if (lastNewInput && !(lastNewInput.files && lastNewInput.files.length)) {
            lastNewInput.focus();
            lastNewInput.closest('[data-post-image-card]').classList.add('needs-image');
            return;
        }
        nextIndex += 1;
        const card = createNewCard(nextIndex);
        container.appendChild(card);
        card.querySelector('input[type="file"]').focus();
    });
    }

    window.buenoInitPostEditor = initializePostEditorPage;
    initializePostEditorPage();
}());
