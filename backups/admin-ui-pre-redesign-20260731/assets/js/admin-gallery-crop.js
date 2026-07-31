(function () {
    const minimumCropSize = 0.08;
    const clamp = (value, minimum, maximum) => Math.min(maximum, Math.max(minimum, value));

    function defaultCrop(editor, image) {
        const aspect = Number(editor.dataset.cropAspect) || 0;
        const imageWidth = image && image.naturalWidth ? image.naturalWidth : 0;
        const imageHeight = image && image.naturalHeight ? image.naturalHeight : 0;

        if (!aspect || !imageWidth || !imageHeight) {
            return { x: 0, y: 0, width: 1, height: 1, imageWidth, imageHeight };
        }

        const imageAspect = imageWidth / imageHeight;
        let width = 1;
        let height = 1;

        if (imageAspect > aspect) {
            width = aspect / imageAspect;
        } else {
            height = imageAspect / aspect;
        }

        return {
            x: (1 - width) / 2,
            y: (1 - height) / 2,
            width,
            height,
            imageWidth,
            imageHeight
        };
    }

    function normalizedAspect(editor, image) {
        const aspect = Number(editor.dataset.cropAspect) || 0;
        const imageWidth = image && image.naturalWidth ? image.naturalWidth : 0;
        const imageHeight = image && image.naturalHeight ? image.naturalHeight : 0;

        return aspect && imageWidth && imageHeight ? aspect * imageHeight / imageWidth : 0;
    }

    function fitCropToAspect(crop, editor, image) {
        const ratio = normalizedAspect(editor, image);
        if (!ratio) return crop;

        const centerX = crop.x + crop.width / 2;
        const centerY = crop.y + crop.height / 2;
        let width = crop.width;
        let height = crop.height;

        if (width / height > ratio) {
            width = height * ratio;
        } else {
            height = width / ratio;
        }

        width = Math.min(width, 1);
        height = Math.min(height, 1);

        return {
            ...crop,
            x: clamp(centerX - width / 2, 0, 1 - width),
            y: clamp(centerY - height / 2, 0, 1 - height),
            width,
            height
        };
    }

    function normalizeCrop(value, image, editor) {
        let crop = value;
        if (typeof crop === 'string') {
            try { crop = JSON.parse(crop); } catch (error) { crop = {}; }
        }
        if (!crop || typeof crop !== 'object') crop = {};
        if (!(Number(crop.width) > 0) || !(Number(crop.height) > 0)) {
            return defaultCrop(editor, image);
        }
        const x = clamp(Number(crop.x) || 0, 0, 1 - minimumCropSize);
        const y = clamp(Number(crop.y) || 0, 0, 1 - minimumCropSize);
        const width = clamp(Number(crop.width) || 1, minimumCropSize, 1 - x);
        const height = clamp(Number(crop.height) || 1, minimumCropSize, 1 - y);
        return fitCropToAspect({
            x,
            y,
            width,
            height,
            imageWidth: image && image.naturalWidth ? image.naturalWidth : (Number(crop.imageWidth) || 0),
            imageHeight: image && image.naturalHeight ? image.naturalHeight : (Number(crop.imageHeight) || 0)
        }, editor, image);
    }

    function resizeCropWithAspect(start, direction, dx, dy, editor, image) {
        const ratio = normalizedAspect(editor, image);
        if (!ratio || !/^(nw|ne|se|sw)$/.test(direction)) return null;

        const growsEast = direction.includes('e');
        const growsSouth = direction.includes('s');
        const anchorX = growsEast ? start.x : start.x + start.width;
        const anchorY = growsSouth ? start.y : start.y + start.height;
        const pointerX = (growsEast ? start.x + start.width : start.x) + dx;
        const pointerY = (growsSouth ? start.y + start.height : start.y) + dy;
        const rawWidth = Math.abs(pointerX - anchorX);
        const rawHeight = Math.abs(pointerY - anchorY);
        const minimumHeight = Math.max(minimumCropSize, minimumCropSize / ratio);
        const maximumWidth = growsEast ? 1 - anchorX : anchorX;
        const maximumHeight = growsSouth ? 1 - anchorY : anchorY;
        const maximumScaleHeight = Math.min(maximumHeight, maximumWidth / ratio);
        const projectedHeight = (ratio * rawWidth + rawHeight) / (ratio * ratio + 1);
        const height = clamp(projectedHeight, Math.min(minimumHeight, maximumScaleHeight), maximumScaleHeight);
        const width = height * ratio;

        return {
            ...start,
            x: growsEast ? anchorX : anchorX - width,
            y: growsSouth ? anchorY : anchorY - height,
            width,
            height
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

    function saveCrop(editor, value) {
        const image = editor.querySelector('[data-crop-image]');
        const input = editor.querySelector('[data-crop-value]');
        const box = editor.querySelector('[data-crop-box]');
        if (!image || !input || !box) return;
        const crop = normalizeCrop(value, image, editor);
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

    function initializeEditor(editor, resetCrop) {
        if (!editor) return;
        const image = editor.querySelector('[data-crop-image]');
        const input = editor.querySelector('[data-crop-value]');
        const box = editor.querySelector('[data-crop-box]');
        if (!image || !input || !box) return;

        const ready = function () {
            editor.hidden = false;
            saveCrop(editor, resetCrop ? null : input.value);
        };
        if (image.complete && image.naturalWidth) ready();
        else image.addEventListener('load', ready, { once: true });

        if (editor.dataset.galleryCropBound === 'true') return;
        editor.dataset.galleryCropBound = 'true';
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
                    const fixedAspectCrop = resizeCropWithAspect(start, direction, dx, dy, editor, image);
                    if (fixedAspectCrop) Object.assign(crop, fixedAspectCrop);
                }
                saveCrop(editor, crop);
            };
            const finish = function (finishEvent) {
                if (finishEvent.pointerId !== event.pointerId) return;
                window.removeEventListener('pointermove', move);
                window.removeEventListener('pointerup', finish);
                window.removeEventListener('pointercancel', finish);
            };
            window.addEventListener('pointermove', move, { passive: false });
            window.addEventListener('pointerup', finish);
            window.addEventListener('pointercancel', finish);
        });
    }

    function useFile(editor, file, resetCrop) {
        const image = editor.querySelector('[data-crop-image]');
        if (!image || !file) return;
        if (editor._buenoObjectUrl) URL.revokeObjectURL(editor._buenoObjectUrl);
        editor._buenoObjectUrl = URL.createObjectURL(file);
        image.src = editor._buenoObjectUrl;
        initializeEditor(editor, resetCrop);
    }

    function createCropVariant(titleText, descriptionText, inputName, modifierClass, aspect) {
        const variant = document.createElement('section');
        variant.className = `admin-gallery-crop-variant${modifierClass ? ` ${modifierClass}` : ''}`;
        const title = document.createElement('h4');
        title.textContent = titleText;
        const description = document.createElement('p');
        description.textContent = descriptionText;
        const editor = document.createElement('div');
        editor.className = 'admin-crop-editor';
        editor.dataset.cropEditor = '';
        editor.dataset.cropAspect = String(aspect);
        editor.innerHTML = `<img alt="Podgląd: ${titleText}" data-crop-image><div class="admin-crop-box" data-crop-box><span data-crop-handle="nw"></span><span data-crop-handle="n"></span><span data-crop-handle="ne"></span><span data-crop-handle="e"></span><span data-crop-handle="se"></span><span data-crop-handle="s"></span><span data-crop-handle="sw"></span><span data-crop-handle="w"></span></div><input type="hidden" name="${inputName}" value="" data-crop-value>`;
        variant.append(title, description, editor);
        return { variant, editor };
    }

    function createUploadPreview(file, index) {
        const card = document.createElement('article');
        card.className = 'admin-gallery-crop-card';
        const title = document.createElement('strong');
        title.textContent = `Zdjęcie ${index + 1}: ${file.name}`;
        const variants = document.createElement('div');
        variants.className = 'admin-gallery-crop-variants';
        const desktop = createCropVariant('Kadr desktop 2:1', 'Szerokie ekrany, tablet i telefon obrócony poziomo.', 'image_crops[]', '', 2);
        const mobile = createCropVariant('Kadr mobile 1:2', 'Telefon trzymany pionowo w trybie jednego zdjęcia.', 'image_mobile_crops[]', 'admin-gallery-crop-variant--mobile', 0.5);
        variants.append(desktop.variant, mobile.variant);
        card.append(title, variants);
        useFile(desktop.editor, file, true);
        useFile(mobile.editor, file, true);
        return card;
    }

    function initializePage() {
        document.querySelectorAll('[data-gallery-layout-settings]').forEach((form) => {
            if (form.dataset.galleryLayoutBound === 'true') return;
            form.dataset.galleryLayoutBound = 'true';
            const tileInput = form.querySelector('[data-gallery-tile-view-input]');
            const mobileInput = form.querySelector('[data-gallery-mobile-two-up-input]');
            const mobileOption = form.querySelector('[data-gallery-mobile-layout-option]');
            if (!tileInput || !mobileInput || !mobileOption) return;
            const syncLayoutOptions = () => {
                const isTileView = tileInput.checked;
                if (isTileView) mobileInput.checked = false;
                mobileInput.disabled = isTileView;
                mobileOption.classList.toggle('is-disabled', isTileView);
                mobileOption.setAttribute('aria-disabled', isTileView ? 'true' : 'false');
            };
            tileInput.addEventListener('change', syncLayoutOptions);
            syncLayoutOptions();
        });

        document.querySelectorAll('[data-gallery-crop-upload]').forEach((form) => {
            if (form.dataset.galleryCropBound === 'true') return;
            form.dataset.galleryCropBound = 'true';
            form.classList.toggle('admin-gallery-crop--desktop-only', form.dataset.galleryMobileTwoUp === 'true');
            const input = form.querySelector('[data-gallery-images-input]');
            const previews = form.querySelector('[data-gallery-crop-previews]');
            if (!input || !previews) return;
            input.addEventListener('change', function () {
                previews.querySelectorAll('[data-crop-editor]').forEach((editor) => {
                    if (editor._buenoObjectUrl) URL.revokeObjectURL(editor._buenoObjectUrl);
                });
                previews.replaceChildren(...Array.from(input.files || []).map(createUploadPreview));
            });
        });

        document.querySelectorAll('[data-gallery-single-crop]').forEach((form) => {
            if (form.dataset.galleryCropBound === 'true') return;
            form.dataset.galleryCropBound = 'true';
            form.classList.toggle('admin-gallery-crop--desktop-only', form.dataset.galleryMobileTwoUp === 'true');
            const input = form.querySelector('[data-gallery-image-input]');
            const editors = Array.from(form.querySelectorAll('[data-crop-editor]'));
            editors.forEach((editor) => initializeEditor(editor, false));
            if (input && editors.length > 0) input.addEventListener('change', function () {
                const file = input.files && input.files[0];
                if (file) editors.forEach((editor) => useFile(editor, file, true));
            });
        });
    }

    window.buenoInitGalleryCrop = initializePage;
    initializePage();
}());
