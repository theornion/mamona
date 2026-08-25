(function () {
    const gallery = document.querySelector('.cat-gallery');

    if (!gallery) {
        return;
    }

    const isCustomGallery = Boolean(gallery.dataset.gallerySource);
    if (!isCustomGallery) {
        return;
    }
    const isEmbeddedPreview = gallery.dataset.galleryEmbedded === 'true';
    const source = gallery.dataset.gallerySource;
    const sectionTitle = gallery.dataset.galleryTitle || 'Nasze koty';
    const sectionKey = sectionTitle.toLowerCase().replace(/[^a-z0-9ąćęłńóśźż]+/gi, '-').replace(/^-|-$/g, '');
    const cropLayouts = [];
    let mobileTwoUp = false;
    let tileView = false;
    const clamp = (number, minimum, maximum) => Math.min(maximum, Math.max(minimum, number));
    let galleryItems = [];
    let activeLightboxIndex = 0;
    let lastLightboxTrigger = null;
    let lightbox = null;
    let lightboxImage = null;
    let lightboxCaption = null;
    let lightboxPreviousThumbnails = null;
    let lightboxNextThumbnails = null;
    let lightboxPreviousControl = null;
    let lightboxNextControl = null;
    let lightboxTransitionToken = 0;
    let lightboxFrame = null;
    let lightboxThumbnailStorage = null;
    let lightboxItemImages = [];
    let lightboxItemButtons = [];

    if (isCustomGallery) {
        gallery.dataset.galleryLoading = 'true';
    }

    function defaultCrop(image, aspect) {
        const imageWidth = image.naturalWidth || 0;
        const imageHeight = image.naturalHeight || 0;
        const imageAspect = imageHeight ? imageWidth / imageHeight : aspect;
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

    function normalizedCrop(value, image, aspect) {
        const crop = value && typeof value === 'object' ? value : {};
        if (!(Number(crop.width) > 0) || !(Number(crop.height) > 0)) {
            return defaultCrop(image, aspect);
        }
        const x = clamp(Number(crop.x) || 0, 0, 0.92);
        const y = clamp(Number(crop.y) || 0, 0, 0.92);
        const normalized = {
            x,
            y,
            width: clamp(Number(crop.width) || 1, 0.08, 1 - x),
            height: clamp(Number(crop.height) || 1, 0.08, 1 - y),
            imageWidth: image.naturalWidth,
            imageHeight: image.naturalHeight
        };
        const normalizedAspect = aspect * image.naturalHeight / image.naturalWidth;
        const centerX = normalized.x + normalized.width / 2;
        const centerY = normalized.y + normalized.height / 2;

        if (normalized.width / normalized.height > normalizedAspect) {
            normalized.width = normalized.height * normalizedAspect;
        } else {
            normalized.height = normalized.width / normalizedAspect;
        }
        normalized.x = clamp(centerX - normalized.width / 2, 0, 1 - normalized.width);
        normalized.y = clamp(centerY - normalized.height / 2, 0, 1 - normalized.height);

        return normalized;
    }

    function interpolateCrop(desktopCrop, mobileCrop, progress) {
        const mix = (from, to) => from + (to - from) * progress;

        return {
            x: mix(desktopCrop.x, mobileCrop.x),
            y: mix(desktopCrop.y, mobileCrop.y),
            width: mix(desktopCrop.width, mobileCrop.width),
            height: mix(desktopCrop.height, mobileCrop.height),
            imageWidth: desktopCrop.imageWidth,
            imageHeight: desktopCrop.imageHeight
        };
    }

    function cropProgressForContainer(desktopCrop, mobileCrop, slide) {
        const containerWidth = slide.clientWidth || window.innerWidth;
        const containerHeight = slide.clientHeight || window.innerHeight;
        if (!containerWidth || !containerHeight) return 0;

        const containerAspect = containerWidth / containerHeight;
        const desktopWidth = desktopCrop.width * desktopCrop.imageWidth;
        const desktopHeight = desktopCrop.height * desktopCrop.imageHeight;
        const mobileWidth = mobileCrop.width * mobileCrop.imageWidth;
        const mobileHeight = mobileCrop.height * mobileCrop.imageHeight;
        const widthDelta = mobileWidth - desktopWidth;
        const heightDelta = mobileHeight - desktopHeight;
        const denominator = widthDelta - containerAspect * heightDelta;

        if (Math.abs(denominator) < 0.000001) {
            return containerAspect <= 0.5 ? 1 : 0;
        }

        return clamp(
            (containerAspect * desktopHeight - desktopWidth) / denominator,
            0,
            1
        );
    }

    function responsiveCrop(cat, image, slide) {
        const desktopCrop = normalizedCrop(cat.crop, image, 2);

        if (mobileTwoUp) {
            return { crop: desktopCrop, key: 'desktop' };
        }

        const mobileCrop = normalizedCrop(cat.mobileCrop, image, 0.5);
        const progress = cropProgressForContainer(desktopCrop, mobileCrop, slide);

        return {
            crop: interpolateCrop(desktopCrop, mobileCrop, progress),
            key: progress <= 0 ? 'desktop' : (progress >= 1 ? 'mobile' : `blend-${progress.toFixed(4)}`)
        };
    }

    function positionCroppedBackground(slide, image, crop) {
        const containerWidth = slide.clientWidth || window.innerWidth;
        const containerHeight = slide.clientHeight || window.innerHeight;
        if (!containerWidth || !containerHeight || !crop.imageWidth || !crop.imageHeight) return;
        const scale = Math.max(
            containerWidth / (crop.width * crop.imageWidth),
            containerHeight / (crop.height * crop.imageHeight)
        );
        const imageWidth = crop.imageWidth * scale;
        const imageHeight = crop.imageHeight * scale;
        const left = containerWidth / 2 - (crop.x + crop.width / 2) * imageWidth;
        const top = containerHeight / 2 - (crop.y + crop.height / 2) * imageHeight;
        slide.style.backgroundSize = `${imageWidth}px ${imageHeight}px`;
        slide.style.backgroundPosition = `${left}px ${top}px`;
        slide.style.backgroundRepeat = 'no-repeat';
    }

    function syncUnderlyingGallery(index) {
        if (tileView) {
            // W widoku kafelków pełny podgląd działa niezależnie. Przewijanie
            // siatki pod maską tylko poruszało stroną i nie ułatwiało nawigacji.
            return;
        }

        window.dispatchEvent(new CustomEvent('bueno:gallery-lightbox-change', {
            detail: { gallery, index }
        }));

        if (gallery.dataset.snapInitialized !== 'true') {
            const slide = gallery.querySelectorAll('.cat-slide')[index];
            if (slide) {
                gallery.scrollTo({ top: slide.offsetTop, behavior: 'smooth' });
            }
        }
    }

    function createLightboxItem(index) {
        const item = galleryItems[index];
        const title = String(item.name || '').trim();
        const button = document.createElement('button');
        const image = document.createElement('img');

        button.type = 'button';
        button.className = 'cat-lightbox-thumbnail';
        button.dataset.index = String(index);
        button.setAttribute('aria-label', title !== ''
            ? `Pokaż zdjęcie: ${title}`
            : `Pokaż zdjęcie ${index + 1}`);
        image.src = item.image;
        image.alt = '';
        image.loading = 'eager';
        image.dataset.index = String(index);
        button.appendChild(image);
        button.addEventListener('click', () => updateLightbox(index, index > activeLightboxIndex ? 1 : -1));
        lightboxItemImages[index] = image;
        lightboxItemButtons[index] = button;
        return { button, image };
    }

    function positionLightboxThumbnails() {
        if (!lightbox || lightbox.hidden || !lightboxImage) return;
        const rect = lightboxImage.getBoundingClientRect();
        if (!rect.width) return;
        lightbox.style.setProperty('--cat-lightbox-image-left', `${rect.left}px`);
        lightbox.style.setProperty('--cat-lightbox-image-right', `${Math.max(0, window.innerWidth - rect.right)}px`);
        const nearThumbnail = lightbox.querySelector('.cat-lightbox-thumbnail--near');
        lightbox.style.setProperty(
            '--cat-lightbox-near-half',
            `${nearThumbnail ? nearThumbnail.getBoundingClientRect().width / 2 : 0}px`
        );
    }

    function renderLightboxThumbnails() {
        if (!lightboxPreviousThumbnails || !lightboxNextThumbnails || !lightboxFrame || !lightboxThumbnailStorage) return;
        lightboxPreviousThumbnails.replaceChildren();
        lightboxNextThumbnails.replaceChildren();
        lightboxItemButtons.forEach((button) => {
            button.className = 'cat-lightbox-thumbnail';
            lightboxThumbnailStorage.appendChild(button);
        });

        const activeImage = lightboxItemImages[activeLightboxIndex];
        const activeButton = lightboxItemButtons[activeLightboxIndex];
        if (!activeImage || !activeButton) return;
        activeButton.classList.add('cat-lightbox-item--current');
        activeImage.className = 'cat-lightbox-image';
        activeImage.alt = String(galleryItems[activeLightboxIndex].name || '').trim()
            || `Zdjęcie ${activeLightboxIndex + 1} z ${galleryItems.length}`;
        lightboxFrame.insertBefore(activeButton, lightboxCaption);
        lightboxImage = activeImage;

        for (let offset = 2; offset >= 1; offset -= 1) {
            const index = activeLightboxIndex - offset;
            if (index >= 0) {
                const button = lightboxItemButtons[index];
                lightboxItemImages[index].className = '';
                button.classList.add(`cat-lightbox-thumbnail--${offset === 1 ? 'near' : 'far'}`);
                lightboxPreviousThumbnails.appendChild(button);
            }
        }
        for (let offset = 1; offset <= 2; offset += 1) {
            const index = activeLightboxIndex + offset;
            if (index < galleryItems.length) {
                const button = lightboxItemButtons[index];
                lightboxItemImages[index].className = '';
                button.classList.add(`cat-lightbox-thumbnail--${offset === 1 ? 'near' : 'far'}`);
                lightboxNextThumbnails.appendChild(button);
            }
        }

        requestAnimationFrame(positionLightboxThumbnails);
    }

    function animateLightboxChange(oldItemRects, direction) {
        if (!lightbox || !lightboxImage || !direction) return;
        const animations = [];
        lightbox.classList.add('is-transitioning-items');

        lightbox.querySelectorAll('.cat-lightbox-item--current, .cat-lightbox-thumbnail--near, .cat-lightbox-thumbnail--far').forEach((item) => {
            const oldState = oldItemRects.get(item.dataset.index);
            const newRect = item.getBoundingClientRect();
            if (!newRect.width || !newRect.height) return;
            const targetOpacity = Number(getComputedStyle(item).opacity);
            const keyframes = oldState?.rect.width && oldState?.rect.height
                ? [
                    {
                        transform: `translate(${oldState.rect.left - newRect.left}px, ${oldState.rect.top - newRect.top}px) scale(${oldState.rect.width / newRect.width}, ${oldState.rect.height / newRect.height})`,
                        opacity: oldState.opacity
                    },
                    { transform: 'translate(0, 0) scale(1)', opacity: targetOpacity }
                ]
                : [
                    { transform: `translateX(${direction > 0 ? '1rem' : '-1rem'}) scale(.88)`, opacity: 0 },
                    { transform: 'translateX(0) scale(1)', opacity: targetOpacity }
                ];

            animations.push(item.animate(keyframes, {
                duration: 430,
                easing: 'cubic-bezier(.22,.8,.24,1)',
                fill: 'forwards'
            }));
        });

        const cleanup = () => {
            lightbox.classList.remove('is-transitioning-items');
            animations.forEach((animation) => animation.cancel());
        };
        Promise.all(animations.map((animation) => animation.finished.catch(() => null))).then(cleanup);
    }

    function updateLightbox(index, direction = 0) {
        if (!lightbox || galleryItems.length === 0) return;
        const nextIndex = clamp(index, 0, galleryItems.length - 1);
        if (nextIndex === activeLightboxIndex && !lightbox.hidden) return;
        const transitionToken = ++lightboxTransitionToken;
        const resolvedDirection = direction || Math.sign(nextIndex - activeLightboxIndex);
        const shouldAnimate = !lightbox.hidden && resolvedDirection !== 0;
        const oldItemRects = new Map();
        if (shouldAnimate) {
            lightbox.querySelectorAll('.cat-lightbox-item--current, .cat-lightbox-thumbnail--near, .cat-lightbox-thumbnail--far').forEach((item) => {
                oldItemRects.set(item.dataset.index, {
                    rect: item.getBoundingClientRect(),
                    opacity: Number(getComputedStyle(item).opacity)
                });
            });
        }

        activeLightboxIndex = nextIndex;
        const item = galleryItems[activeLightboxIndex];
        const title = String(item.name || '').trim();
        const description = String(item.description || '').trim();

        if (lightboxCaption) {
            const heading = document.createElement('strong');
            const details = document.createElement('span');
            heading.textContent = title !== ''
                ? `${activeLightboxIndex + 1} / ${galleryItems.length} — ${title}`
                : `${activeLightboxIndex + 1} / ${galleryItems.length}`;
            lightboxCaption.replaceChildren(heading);
            if (description !== '') {
                details.textContent = description;
                lightboxCaption.appendChild(details);
            }
        }
        renderLightboxThumbnails();
        if (lightboxPreviousControl) lightboxPreviousControl.disabled = activeLightboxIndex === 0;
        if (lightboxNextControl) lightboxNextControl.disabled = activeLightboxIndex === galleryItems.length - 1;
        syncUnderlyingGallery(activeLightboxIndex);

        const finishChange = () => {
            if (transitionToken !== lightboxTransitionToken) return;
            positionLightboxThumbnails();
            if (shouldAnimate) {
                animateLightboxChange(oldItemRects, resolvedDirection);
            }
        };
        if (lightboxImage.complete) {
            requestAnimationFrame(finishChange);
        } else {
            lightboxImage.addEventListener('load', finishChange, { once: true });
        }
    }

    function closeLightbox() {
        if (!lightbox || lightbox.hidden) return;
        lightbox.hidden = true;
        document.body.classList.remove('cat-lightbox-open');
        if (lastLightboxTrigger && typeof lastLightboxTrigger.focus === 'function') {
            lastLightboxTrigger.focus({ preventScroll: true });
        }
    }

    function openLightbox(index, trigger) {
        if (!lightbox) return;
        lastLightboxTrigger = trigger || null;
        updateLightbox(index);
        lightbox.hidden = false;
        document.body.classList.add('cat-lightbox-open');
        lightbox.querySelector('.cat-lightbox-close')?.focus({ preventScroll: true });
    }

    function createLightbox() {
        const overlay = document.createElement('div');
        const imageFrame = document.createElement('figure');
        const caption = document.createElement('figcaption');
        const close = document.createElement('button');
        const previous = document.createElement('button');
        const next = document.createElement('button');
        const previousThumbnails = document.createElement('div');
        const nextThumbnails = document.createElement('div');
        const thumbnailStorage = document.createElement('div');
        let lightboxPointerStart = null;
        let lightboxPointerMoved = false;

        overlay.className = 'cat-lightbox';
        overlay.dataset.buenoGalleryLightbox = '';
        overlay.hidden = true;
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-label', 'Pełny podgląd zdjęcia');

        imageFrame.className = 'cat-lightbox-frame';
        caption.className = 'cat-lightbox-caption';
        thumbnailStorage.className = 'cat-lightbox-thumbnail-storage';

        close.type = 'button';
        close.className = 'cat-lightbox-control cat-lightbox-close';
        close.setAttribute('aria-label', 'Zamknij pełny podgląd');
        close.innerHTML = '<span class="cat-lightbox-control-icon cat-lightbox-control-icon--close" aria-hidden="true"></span>';

        previous.type = 'button';
        previous.className = 'cat-lightbox-control cat-lightbox-arrow cat-lightbox-previous';
        previous.setAttribute('aria-label', 'Poprzednie zdjęcie');
        previous.innerHTML = '<span class="cat-lightbox-control-icon cat-lightbox-control-icon--previous" aria-hidden="true"></span>';

        next.type = 'button';
        next.className = 'cat-lightbox-control cat-lightbox-arrow cat-lightbox-next';
        next.setAttribute('aria-label', 'Następne zdjęcie');
        next.innerHTML = '<span class="cat-lightbox-control-icon cat-lightbox-control-icon--next" aria-hidden="true"></span>';

        previousThumbnails.className = 'cat-lightbox-thumbnails cat-lightbox-thumbnails--previous';
        previousThumbnails.setAttribute('aria-label', 'Poprzednie zdjęcia');
        nextThumbnails.className = 'cat-lightbox-thumbnails cat-lightbox-thumbnails--next';
        nextThumbnails.setAttribute('aria-label', 'Następne zdjęcia');

        imageFrame.append(caption);
        overlay.append(close, previous, imageFrame, next, previousThumbnails, nextThumbnails, thumbnailStorage);
        document.body.appendChild(overlay);

        close.addEventListener('click', closeLightbox);
        previous.addEventListener('click', () => updateLightbox(activeLightboxIndex - 1, -1));
        next.addEventListener('click', () => updateLightbox(activeLightboxIndex + 1, 1));
        overlay.addEventListener('click', (event) => {
            if (lightboxPointerMoved) {
                lightboxPointerMoved = false;
                return;
            }
            if (event.target === overlay || event.target === imageFrame) closeLightbox();
        });
        imageFrame.addEventListener('pointerdown', (event) => {
            if (event.pointerType === 'mouse' && event.button !== 0) return;
            lightboxPointerStart = {
                id: event.pointerId,
                x: event.clientX,
                y: event.clientY
            };
            imageFrame.setPointerCapture?.(event.pointerId);
        });
        imageFrame.addEventListener('pointermove', (event) => {
            if (!lightboxPointerStart || lightboxPointerStart.id !== event.pointerId) return;
            const deltaX = event.clientX - lightboxPointerStart.x;
            const deltaY = event.clientY - lightboxPointerStart.y;
            if (Math.abs(deltaX) > 8 && Math.abs(deltaX) > Math.abs(deltaY)) {
                lightboxPointerMoved = true;
                event.preventDefault();
            }
        });
        imageFrame.addEventListener('pointerup', (event) => {
            if (!lightboxPointerStart || lightboxPointerStart.id !== event.pointerId) return;
            const deltaX = event.clientX - lightboxPointerStart.x;
            const deltaY = event.clientY - lightboxPointerStart.y;
            imageFrame.releasePointerCapture?.(event.pointerId);
            lightboxPointerStart = null;
            if (Math.abs(deltaX) < 45 || Math.abs(deltaX) <= Math.abs(deltaY)) return;
            lightboxPointerMoved = true;
            updateLightbox(activeLightboxIndex + (deltaX < 0 ? 1 : -1), deltaX < 0 ? 1 : -1);
        });
        imageFrame.addEventListener('pointercancel', (event) => {
            if (lightboxPointerStart?.id === event.pointerId) {
                imageFrame.releasePointerCapture?.(event.pointerId);
            }
            lightboxPointerStart = null;
        });
        window.addEventListener('keydown', (event) => {
            if (overlay.hidden) return;
            if (event.key === 'Escape') {
                event.preventDefault();
                event.stopImmediatePropagation();
                closeLightbox();
            } else if (event.key === 'ArrowLeft') {
                event.preventDefault();
                event.stopImmediatePropagation();
                updateLightbox(activeLightboxIndex - 1, -1);
            } else if (event.key === 'ArrowRight') {
                event.preventDefault();
                event.stopImmediatePropagation();
                updateLightbox(activeLightboxIndex + 1, 1);
            }
        }, true);

        lightbox = overlay;
        lightboxCaption = caption;
        lightboxFrame = imageFrame;
        lightboxThumbnailStorage = thumbnailStorage;
        lightboxPreviousThumbnails = previousThumbnails;
        lightboxNextThumbnails = nextThumbnails;
        lightboxPreviousControl = previous;
        lightboxNextControl = next;
        lightboxItemImages = [];
        lightboxItemButtons = [];
        galleryItems.forEach((item, index) => {
            const lightboxItem = createLightboxItem(index);
            thumbnailStorage.appendChild(lightboxItem.button);
        });
        window.addEventListener('resize', positionLightboxThumbnails, { passive: true });
    }

    function bindLightboxTrigger(element, index) {
        let pointerStart = null;

        element.tabIndex = 0;
        element.setAttribute('role', 'button');
        element.setAttribute('aria-label', `Otwórz pełne zdjęcie ${index + 1} z ${galleryItems.length}`);
        element.addEventListener('pointerdown', (event) => {
            pointerStart = { x: event.clientX, y: event.clientY };
        });
        element.addEventListener('pointercancel', () => {
            pointerStart = null;
        });
        element.addEventListener('click', (event) => {
            if (pointerStart) {
                const distance = Math.hypot(event.clientX - pointerStart.x, event.clientY - pointerStart.y);
                pointerStart = null;
                if (distance > 8) return;
            }
            openLightbox(index, element);
        });
        element.addEventListener('keydown', (event) => {
            if (event.key !== 'Enter' && event.key !== ' ') return;
            event.preventDefault();
            event.stopPropagation();
            openLightbox(index, element);
        });
    }

    function createSlide(cat, index) {
        const slide = document.createElement('article');
        slide.className = 'burger-slide cat-slide';
        slide.dataset.section = sectionTitle;
        slide.dataset.sectionKey = sectionKey || 'galeria';
        slide.dataset.bg = cat.image;
        slide.style.backgroundImage = `url("${encodeURI(cat.image).replace(/"/g, '%22')}")`;
        bindLightboxTrigger(slide, index);

        // The gallery uses a separately composed crop on phones. Tone detection
        // follows the crop currently visible so the navigation dots keep contrast.
        const preview = new Image();
        preview.onload = () => {
            const canvas = document.createElement('canvas');
            const size = 32;
            canvas.width = size;
            canvas.height = size;
            const context = canvas.getContext('2d', { willReadFrequently: true });
            if (!context) return;
            let lastCropMode = '';
            const layout = () => {
                const responsive = responsiveCrop(cat, preview, slide);
                const crop = responsive.crop;
                positionCroppedBackground(slide, preview, crop);
                if (responsive.key === lastCropMode) return;
                lastCropMode = responsive.key;
                context.clearRect(0, 0, size, size);
                context.drawImage(
                    preview,
                    crop.x * preview.naturalWidth,
                    crop.y * preview.naturalHeight,
                    crop.width * preview.naturalWidth,
                    crop.height * preview.naturalHeight,
                    0,
                    0,
                    size,
                    size
                );
                const pixels = context.getImageData(0, 0, size, size).data;
                let luminance = 0;
                for (let i = 0; i < pixels.length; i += 4) {
                    luminance += (0.2126 * pixels[i] + 0.7152 * pixels[i + 1] + 0.0722 * pixels[i + 2]) / 255;
                }
                const isLight = luminance / (pixels.length / 4) > 0.58;
                slide.classList.toggle('cat-slide--light', isLight);
                slide.classList.toggle('cat-slide--dark', !isLight);
                window.dispatchEvent(new CustomEvent('bueno:gallery-tone-ready'));
            };
            cropLayouts.push(layout);
            layout();
        };
        preview.src = cat.image;

        const content = document.createElement('div');
        content.className = 'burger-inner cat-inner';
        const nameText = String(cat.name || '').trim();
        const descriptionText = String(cat.description || '').trim();

        // Do not add an empty text panel: an image without metadata should
        // remain a clean, unobstructed slide.
        if (nameText !== '' || descriptionText !== '') {
            if (nameText !== '') {
                const name = document.createElement('h2');
                name.textContent = nameText;
                content.appendChild(name);
            }
            if (descriptionText !== '') {
                const description = document.createElement('p');
                description.textContent = descriptionText;
                content.appendChild(description);
            }
            slide.appendChild(content);
        }

        const openHint = document.createElement('span');
        openHint.className = 'cat-slide-open-hint';
        openHint.textContent = 'Kliknij zdjęcie, aby zobaczyć je w całości';
        slide.appendChild(openHint);

        return slide;
    }

    function createTile(cat, index) {
        const tile = document.createElement('article');
        const image = document.createElement('img');
        const nameText = String(cat.name || '').trim();
        const descriptionText = String(cat.description || '').trim();

        tile.className = 'cat-gallery-tile';
        image.src = cat.image;
        image.alt = nameText || `Zdjęcie ${index + 1} z ${galleryItems.length}`;
        image.loading = 'lazy';
        image.decoding = 'async';
        image.addEventListener('load', () => {
            const crop = normalizedCrop(cat.crop, image, 2);
            const centerX = clamp(crop.x + crop.width / 2, 0, 1) * 100;
            const centerY = clamp(crop.y + crop.height / 2, 0, 1) * 100;
            image.style.objectPosition = `${centerX}% ${centerY}%`;
        });
        tile.appendChild(image);

        if (nameText !== '' || descriptionText !== '') {
            const caption = document.createElement('div');
            caption.className = 'cat-gallery-tile-caption';

            if (nameText !== '') {
                const name = document.createElement('h2');
                name.textContent = nameText;
                caption.appendChild(name);
            }
            if (descriptionText !== '') {
                const description = document.createElement('p');
                description.textContent = descriptionText;
                caption.appendChild(description);
            }
            tile.appendChild(caption);
        }

        bindLightboxTrigger(tile, index);
        return tile;
    }

    function createEmbeddedPreview(cat, index) {
        const preview = document.createElement('div');
        const image = document.createElement('img');
        const title = String(cat.name || '').trim();

        preview.className = 'post-linked-gallery-thumbnail';
        image.src = cat.image;
        image.alt = title || `Zdjęcie ${index + 1} z galerii`;
        image.loading = 'eager';
        preview.appendChild(image);
        return preview;
    }

    fetch(source, { headers: { Accept: 'application/json' } })
        .then((response) => {
            if (!response.ok) {
                throw new Error('Nie udało się pobrać galerii.');
            }

            return response.json();
        })
        .then((payload) => {
            const cats = isCustomGallery
                ? (Array.isArray(payload.items) ? payload.items : [])
                : (Array.isArray(payload.cats) ? payload.cats : []);

            mobileTwoUp = isCustomGallery && payload.mobileTwoUp === true;
            tileView = isCustomGallery && payload.tileView === true;
            if (isEmbeddedPreview) {
                mobileTwoUp = false;
                tileView = true;
            }
            gallery.classList.toggle('cat-gallery--mobile-two-up', mobileTwoUp);
            gallery.classList.toggle('cat-gallery--tiles', tileView);
            gallery.dataset.galleryTileView = tileView ? 'true' : 'false';

            if (tileView) {
                gallery.removeAttribute('data-snap-initialized');
                document.querySelector('#main > .menu-snap-dots')?.remove();
            }

            if (cats.length === 0) {
                throw new Error('Galeria nie zawiera jeszcze żadnych zdjęć.');
            }

            galleryItems = cats;
            if (!lightbox) createLightbox();
            if (isEmbeddedPreview) {
                const renderEmbeddedPreview = () => {
                    const header = document.createElement('header');
                    const kicker = document.createElement('span');
                    const title = document.createElement('h2');
                    const hint = document.createElement('p');
                    const thumbnails = document.createElement('div');
                    const usableWidth = Math.max(1, gallery.clientWidth - 48);
                    const previewCount = Math.min(cats.length, Math.max(1, Math.floor(usableWidth / 112)));
                    kicker.textContent = 'Galeria';
                    title.textContent = sectionTitle;
                    hint.textContent = 'Kliknij, żeby zobaczyć galerię.';
                    thumbnails.className = 'post-linked-gallery-thumbnails';
                    thumbnails.style.setProperty('--post-gallery-preview-count', String(previewCount));
                    thumbnails.append(...cats.slice(0, previewCount).map(createEmbeddedPreview));
                    header.append(kicker, title, hint);
                    gallery.replaceChildren(header, thumbnails);
                };

                renderEmbeddedPreview();
                bindLightboxTrigger(gallery, 0);
                if ('ResizeObserver' in window) {
                    let lastPreviewCount = 0;
                    const updatePreviewCount = () => {
                        const nextCount = Math.min(cats.length, Math.max(1, Math.floor(Math.max(1, gallery.clientWidth - 48) / 112)));
                        if (nextCount === lastPreviewCount) return;
                        lastPreviewCount = nextCount;
                        renderEmbeddedPreview();
                    };
                    updatePreviewCount();
                    new ResizeObserver(updatePreviewCount).observe(gallery);
                }
            } else if (tileView) {
                const hint = document.createElement('p');
                hint.className = 'cat-gallery-tiles-hint';
                hint.textContent = 'Kliknij zdjęcie, aby otworzyć pełny podgląd.';
                gallery.replaceChildren(hint, ...cats.map(createTile));
            } else {
                gallery.replaceChildren(...cats.map(createSlide));
            }
            gallery.dataset.galleryLoading = 'false';
            if (typeof window.buenoRefreshMainSnap === 'function') {
                window.buenoRefreshMainSnap();
            }
            window.requestAnimationFrame(() => cropLayouts.forEach((layout) => layout()));
            window.dispatchEvent(new CustomEvent('bueno:cat-gallery-ready'));
        })
        .catch((error) => {
            const message = document.createElement('p');
            message.className = 'cat-gallery-status';
            message.textContent = error.message;
            gallery.dataset.galleryLoading = 'false';
            gallery.replaceChildren(message);
        });

    window.addEventListener('resize', () => {
        window.requestAnimationFrame(() => cropLayouts.forEach((layout) => layout()));
    });
}());
