/* Unified page/menu snap behavior. */
(function () {
    if (window.MamonaPerformance && window.MamonaPerformance.shouldReduceEffects()) return;
    const menuImageDirectory = 'images/menu/';
    const xlsxSharedStringType = 's';
    const xlsxInlineStringType = 'inlineStr';

    function normalizeValue(value) {
        return (value || '')
            .trim()
            .toLowerCase()
            .normalize('NFD')
            .replace(/[lL]/g, 'l')
            .replace(/ł/g, 'l')
            .replace(/[\u0300-\u036f]/g, '')
            .replace(/\s+/g, '');
    }

    function cleanMenuFileName(fileName) {
        return (fileName || '').trim().replace(/^.*[\\/]/, '');
    }

    function cssUrl(fileName) {
        return encodeURI(menuImageDirectory + cleanMenuFileName(fileName)).replace(/"/g, '%22');
    }

    function columnNameToIndex(columnName) {
        return columnName.split('').reduce((index, character) => {
            return index * 26 + character.charCodeAt(0) - 64;
        }, 0) - 1;
    }

    function getXmlText(element) {
        return element ? element.textContent.trim() : '';
    }

    function getXmlElements(element, tagName) {
        const normalizedTagName = normalizeValue(tagName);
        const allElements = element.documentElement
            ? [element.documentElement, ...Array.from(element.getElementsByTagName('*'))]
            : Array.from(element.getElementsByTagName('*'));

        return allElements.filter((item) => normalizeValue(item.localName || item.nodeName) === normalizedTagName);
    }

    function getZipEntries(bytes) {
        const entries = new Map();
        const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
        const decoder = new TextDecoder('utf-8');
        let endOffset = -1;

        for (let index = bytes.length - 22; index >= 0; index -= 1) {
            if (view.getUint32(index, true) === 0x06054b50) {
                endOffset = index;
                break;
            }
        }

        if (endOffset < 0) {
            throw new Error('Plik XLSX nie wygląda jak poprawny arkusz.');
        }

        const centralDirectorySize = view.getUint32(endOffset + 12, true);
        const centralDirectoryOffset = view.getUint32(endOffset + 16, true);
        let offset = centralDirectoryOffset;
        const directoryEnd = centralDirectoryOffset + centralDirectorySize;

        while (offset < directoryEnd) {
            if (view.getUint32(offset, true) !== 0x02014b50) {
                break;
            }

            const compressionMethod = view.getUint16(offset + 10, true);
            const compressedSize = view.getUint32(offset + 20, true);
            const uncompressedSize = view.getUint32(offset + 24, true);
            const fileNameLength = view.getUint16(offset + 28, true);
            const extraLength = view.getUint16(offset + 30, true);
            const commentLength = view.getUint16(offset + 32, true);
            const localHeaderOffset = view.getUint32(offset + 42, true);
            const fileName = decoder.decode(bytes.slice(offset + 46, offset + 46 + fileNameLength));

            entries.set(fileName, {
                compressionMethod,
                compressedSize,
                uncompressedSize,
                localHeaderOffset,
            });

            offset += 46 + fileNameLength + extraLength + commentLength;
        }

        return entries;
    }

    function inflateRaw(deflatedBytes) {
        if (!window.DecompressionStream) {
            return Promise.reject(new Error('Ta przeglądarka nie obsługuje rozpakowywania plików XLSX.'));
        }

        const stream = new Blob([deflatedBytes]).stream().pipeThrough(new DecompressionStream('deflate-raw'));

        return new Response(stream).arrayBuffer().then((buffer) => new Uint8Array(buffer));
    }

    function readZipText(bytes, entries, fileName) {
        const entry = entries.get(fileName);

        if (!entry) {
            return Promise.resolve('');
        }

        const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
        const localOffset = entry.localHeaderOffset;

        if (view.getUint32(localOffset, true) !== 0x04034b50) {
            return Promise.reject(new Error('Nie udało się odczytać wpisu XLSX: ' + fileName));
        }

        const fileNameLength = view.getUint16(localOffset + 26, true);
        const extraLength = view.getUint16(localOffset + 28, true);
        const dataStart = localOffset + 30 + fileNameLength + extraLength;
        const compressedBytes = bytes.slice(dataStart, dataStart + entry.compressedSize);
        const decoder = new TextDecoder('utf-8');

        if (entry.compressionMethod === 0) {
            return Promise.resolve(decoder.decode(compressedBytes));
        }

        if (entry.compressionMethod !== 8) {
            return Promise.reject(new Error('Nieobsługiwana kompresja w XLSX: ' + entry.compressionMethod));
        }

        return inflateRaw(compressedBytes).then((uncompressedBytes) => {
            if (entry.uncompressedSize && uncompressedBytes.length !== entry.uncompressedSize) {
                console.warn('Rozmiar wpisu XLSX różni się od deklarowanego:', fileName);
            }

            return decoder.decode(uncompressedBytes);
        });
    }

    function getFirstWorksheetPath(workbookXml, relationshipsXml) {
        const workbook = new DOMParser().parseFromString(workbookXml, 'application/xml');
        const relationships = new DOMParser().parseFromString(relationshipsXml, 'application/xml');
        const firstSheet = getXmlElements(workbook, 'sheet')[0];
        const relationshipId = firstSheet ? firstSheet.getAttribute('r:id') : '';
        const relationship = relationshipId
            ? getXmlElements(relationships, 'Relationship').find((item) => {
                return item.getAttribute('Id') === relationshipId;
            })
            : null;
        const target = relationship ? relationship.getAttribute('Target') : 'worksheets/sheet1.xml';

        if (!target) {
            return 'xl/worksheets/sheet1.xml';
        }

        return target.startsWith('/')
            ? target.replace(/^\//, '')
            : 'xl/' + target.replace(/^\.\//, '');
    }

    function parseSharedStrings(sharedStringsXml) {
        if (!sharedStringsXml) {
            return [];
        }

        const xml = new DOMParser().parseFromString(sharedStringsXml, 'application/xml');

        return getXmlElements(xml, 'si').map((item) => {
            const textNodes = getXmlElements(item, 't');

            return textNodes.map((node) => node.textContent).join('');
        });
    }

    function getCellValue(cell, sharedStrings) {
        const type = cell.getAttribute('t');

        if (type === xlsxInlineStringType) {
            return getXmlElements(cell, 't').map((node) => node.textContent).join('').trim();
        }

        const rawValue = getXmlText(getXmlElements(cell, 'v')[0]);

        if (type === xlsxSharedStringType) {
            return (sharedStrings[Number(rawValue)] || '').trim();
        }

        return rawValue.trim();
    }

    function parseWorksheetRows(worksheetXml, sharedStrings) {
        const xml = new DOMParser().parseFromString(worksheetXml, 'application/xml');
        const parserError = getXmlElements(xml, 'parsererror')[0];

        if (parserError) {
            throw new Error('Arkusz XLSX ma niepoprawną strukturę XML.');
        }

        const sheetData = getXmlElements(xml, 'sheetData')[0];
        const rows = sheetData ? getXmlElements(sheetData, 'row') : [];

        return rows.map((row) => {
            const values = [];

            getXmlElements(row, 'c').forEach((cell) => {
                const reference = cell.getAttribute('r') || '';
                const columnMatch = reference.match(/[A-Z]+/i);
                const columnIndex = columnMatch ? columnNameToIndex(columnMatch[0].toUpperCase()) : values.length;

                values[columnIndex] = getCellValue(cell, sharedStrings);
            });

            return values.map((value) => value || '');
        });
    }

    function getColumnIndex(headers, candidates) {
        const normalizedCandidates = candidates.map(normalizeValue);

        return headers.findIndex((header) => normalizedCandidates.includes(normalizeValue(header)));
    }

    function rowsToMenuItems(rows) {
        const headerRowIndex = rows.findIndex((row) => {
            const normalized = row.map(normalizeValue);

            return normalized.includes('sekcja') &&
                normalized.includes('nazwa') &&
                normalized.includes('cena') &&
                normalized.includes('plik');
        });

        if (headerRowIndex < 0) {
            throw new Error('Plik XLSX musi mieć nagłówki: Sekcja, Nazwa, Skład, Cena, Plik.');
        }

        const headers = rows[headerRowIndex];
        const indexes = {
            section: getColumnIndex(headers, ['sekcja', 'section']),
            name: getColumnIndex(headers, ['nazwa', 'name']),
            ingredients: getColumnIndex(headers, ['sklad', 'skład', 'ingredients']),
            price: getColumnIndex(headers, ['cena', 'price']),
            file: getColumnIndex(headers, ['plik', 'file']),
        };

        const sectionOrder = new Map();
        const sectionLabels = new Map();
        const items = rows.slice(headerRowIndex + 1)
            .map((row, index) => {
                const section = (row[indexes.section] || '').trim() || 'Menu';
                const sectionKey = normalizeValue(section) || 'menu';

                if (!sectionOrder.has(sectionKey)) {
                    sectionOrder.set(sectionKey, sectionOrder.size);
                    sectionLabels.set(sectionKey, section);
                }

                return {
                    section: sectionLabels.get(sectionKey),
                    sectionKey,
                    name: (row[indexes.name] || '').trim(),
                    ingredients: (row[indexes.ingredients] || '').trim(),
                    price: (row[indexes.price] || '').trim(),
                    file: (row[indexes.file] || '').trim(),
                    originalIndex: index,
                };
            })
            .filter((item) => item.name || item.ingredients || item.price || item.file);

        return items.sort((first, second) => {
            const sectionDifference = sectionOrder.get(first.sectionKey) - sectionOrder.get(second.sectionKey);

            return sectionDifference || first.originalIndex - second.originalIndex;
        });
    }

    function createMenuSlide(item) {
        const article = document.createElement('article');
        const inner = document.createElement('div');
        const title = document.createElement('h2');
        const ingredients = document.createElement('p');
        const price = document.createElement('strong');

        article.className = 'burger-slide';
        article.dataset.bg = cleanMenuFileName(item.file);
        article.dataset.section = item.section;
        article.dataset.sectionKey = item.sectionKey || normalizeValue(item.section || 'Menu');

        inner.className = 'burger-inner';
        title.textContent = item.name;
        ingredients.textContent = item.ingredients;
        price.textContent = item.price;

        inner.append(title, ingredients, price);
        article.appendChild(inner);

        return article;
    }

    function showMenuLoadError(menu) {
        menu.replaceChildren(createMenuSlide({
            name: 'Menu niedostępne',
            ingredients: 'Nie udało się wczytać pliku menu.xlsx. Uruchom stronę przez serwer lokalny albo sprawdź, czy arkusz jest obok index.html.',
            price: '',
            file: '',
            section: 'Menu'
        }));
    }

    function loadMenuSlides() {
        const menu = document.querySelector('body.menu-page #main > .post.menu-snap');

        if (!menu || !menu.dataset.menuSource) {
            return Promise.resolve();
        }

        return fetch(menu.dataset.menuSource, { cache: 'no-store' })
            .then((response) => {
                if (!response.ok) {
                    throw new Error('Nie udało się wczytać pliku XLSX.');
                }

                return response.arrayBuffer();
            })
            .then((buffer) => {
                const bytes = new Uint8Array(buffer);
                const entries = getZipEntries(bytes);

                return Promise.all([
                    readZipText(bytes, entries, 'xl/workbook.xml'),
                    readZipText(bytes, entries, 'xl/_rels/workbook.xml.rels'),
                    readZipText(bytes, entries, 'xl/sharedStrings.xml'),
                ]).then(([workbookXml, relationshipsXml, sharedStringsXml]) => {
                    const worksheetPath = getFirstWorksheetPath(workbookXml, relationshipsXml);

                    return readZipText(bytes, entries, worksheetPath).then((worksheetXml) => {
                        const sharedStrings = parseSharedStrings(sharedStringsXml);
                        const rows = parseWorksheetRows(worksheetXml, sharedStrings);
                        const items = rowsToMenuItems(rows);

                        if (!items.length) {
                            throw new Error('Plik XLSX nie zawiera pozycji menu.');
                        }

                        const slides = document.createDocumentFragment();
                        items.forEach((item) => {
                            slides.appendChild(createMenuSlide(item));
                        });

                        menu.replaceChildren(slides);
                    });
                });
            })
            .catch((error) => {
                console.error(error);
                showMenuLoadError(menu);
            });
    }

    function initMenuSnap() {
        const menu = document.querySelector('body.menu-page #main > .post.menu-snap');
        const main = document.querySelector('body.menu-page #main');
        const nav = document.querySelector('body.menu-page #nav');

        if (
            !menu ||
            !main ||
            !nav ||
            menu.dataset.galleryLoading === 'true' ||
            menu.dataset.galleryTileView === 'true' ||
            menu.dataset.snapInitialized === 'true'
        ) return;

        const isCatGallery = menu.classList.contains('cat-gallery');
        const slides = Array.from(menu.querySelectorAll(isCatGallery ? '.cat-slide' : '.burger-slide'));

        if (!slides.length) return;

        menu.dataset.snapInitialized = 'true';

        const dotsContainer = createDots();
        const dotButtons = Array.from(dotsContainer.querySelectorAll('.menu-snap-dot'));

        let isAnimating = false;
        let wheelAccumulator = 0;
        let wheelResetTimer = null;

        const duration = 540;
        const wheelThreshold = 8;
        const touchThreshold = 3;
        const touchAxisRatio = 1.05;
        const pageSnapTolerance = 12;

        function easeOutCubic(t) {
            return 1 - Math.pow(1 - t, 3);
        }

        function getMenuPageTarget() {
            return Math.round(Math.max(
                0,
                main.getBoundingClientRect().top + window.scrollY
            ));
        }

        function pageIsSnappedToMenu() {
            return Math.abs(window.scrollY - getMenuPageTarget()) <= pageSnapTolerance;
        }

        function shouldAlignPageToMenu(direction) {
            const currentY = window.scrollY || window.pageYOffset;
            const menuPageTarget = getMenuPageTarget();

            if (currentY < menuPageTarget - pageSnapTolerance) {
                return direction > 0;
            }

            if (currentY > menuPageTarget + pageSnapTolerance) {
                return direction < 0;
            }

            return false;
        }

        function clampMenuScroll(value) {
            const maxScrollTop = Math.max(0, menu.scrollHeight - menu.clientHeight);

            return Math.round(Math.max(0, Math.min(value, maxScrollTop)));
        }

        function getSlideScrollTop(slide) {
            const menuRect = menu.getBoundingClientRect();
            const slideRect = slide.getBoundingClientRect();

            return clampMenuScroll(menu.scrollTop + (slideRect.top - menuRect.top));
        }

        function getClosestSlideIndex() {
            let closestIndex = 0;
            let closestDistance = Infinity;

            slides.forEach((slide, index) => {
                const target = getSlideScrollTop(slide);
                const distance = Math.abs(target - menu.scrollTop);

                if (distance < closestDistance) {
                    closestDistance = distance;
                    closestIndex = index;
                }
            });

            return closestIndex;
        }

        function createDots() {
            const dots = document.createElement('div');
            let previousSection = null;

            dots.className = 'menu-snap-dots';
            dots.setAttribute('aria-label', 'Wybór pozycji menu');

            slides.forEach((slide, index) => {
                const section = slide.dataset.section || (isCatGallery ? 'Nasze koty' : 'Menu');
                const sectionKey = slide.dataset.sectionKey || normalizeValue(section);
                const button = document.createElement('button');
                const title = slide.querySelector('h2')?.textContent?.trim();
                const backgroundFile = slide.dataset.bg || '';
                const label = document.createElement('span');
                const fallbackName = title ? title.replace(/\s+Burger$/i, '') : 'Pozycja ' + (index + 1);
                const backgroundName = isCatGallery
                    ? fallbackName
                    : (backgroundFile ? backgroundFile.replace(/\.[^.]+$/, '') : fallbackName);

                if (sectionKey !== previousSection) {
                    const sectionTitle = document.createElement('div');

                    sectionTitle.className = 'menu-snap-section-title';
                    sectionTitle.textContent = section;
                    dots.appendChild(sectionTitle);
                    previousSection = sectionKey;
                }

                button.type = 'button';
                button.className = 'menu-snap-dot';
                button.setAttribute('aria-label', title ? 'Przejdz do: ' + title : 'Przejdz do pozycji ' + (index + 1));
                label.className = 'menu-snap-dot-label';
                label.textContent = backgroundName;
                button.appendChild(label);
                button.addEventListener('click', function (event) {
                    event.preventDefault();
                    event.stopPropagation();

                    if (!pageIsSnappedToMenu()) {
                        window.scrollTo(0, getMenuPageTarget());
                    }

                    wheelAccumulator = 0;
                    goToSlide(index);

                    if (typeof button.blur === 'function') {
                        button.blur();
                    }
                });

                dots.appendChild(button);

            });

            dots.setAttribute('aria-hidden', 'true');
            main.appendChild(dots);

            return dots;
        }

        function applySlideBackgrounds() {
            if (isCatGallery) return;

            slides.forEach((slide) => {
                const backgroundFile = slide.dataset.bg;

                if (!backgroundFile) return;

                slide.style.backgroundImage =
                    'linear-gradient(rgba(0,0,0,.55), rgba(0,0,0,.55)), url("' +
                    cssUrl(backgroundFile) +
                    '")';
            });
        }

        function updateDots(activeIndex) {
            const index = typeof activeIndex === 'number' ? activeIndex : getClosestSlideIndex();

            dotButtons.forEach((button, buttonIndex) => {
                const isActive = buttonIndex === index;

                button.classList.toggle('is-active', isActive);
                button.setAttribute('aria-current', isActive ? 'true' : 'false');
            });

            const activeSlide = slides[index];
            dotsContainer.classList.toggle('is-light-slide', !!activeSlide?.classList.contains('cat-slide--light'));
        }


        function updateDotsVisibility() {
            const shouldBeVisible = pageIsSnappedToMenu();

            const menuRect = menu.getBoundingClientRect();
            const visibleTop = Math.max(0, menuRect.top);
            const visibleBottom = Math.min(window.innerHeight, menuRect.bottom);
            if (visibleBottom > visibleTop) {
                dotsContainer.style.top = ((visibleTop + visibleBottom) / 2) + 'px';
            }

            dotsContainer.classList.toggle('is-visible', shouldBeVisible);
            dotsContainer.setAttribute('aria-hidden', shouldBeVisible ? 'false' : 'true');
        }

        let dotsVisibilityFrame = null;

        function requestDotsVisibilityUpdate() {
            if (dotsVisibilityFrame) return;

            dotsVisibilityFrame = requestAnimationFrame(function () {
                dotsVisibilityFrame = null;
                updateDotsVisibility();
            });
        }

        function animateScrollTo(targetScrollTop) {
            let startScrollTop = null;
            let distance = null;
            let startTime = null;

            targetScrollTop = clampMenuScroll(targetScrollTop);

            if (Math.abs(targetScrollTop - menu.scrollTop) < 3) {
                menu.scrollTop = targetScrollTop;
                updateDots();
                return;
            }

            isAnimating = true;

            function step(currentTime) {
                if (startTime === null) {
                    startScrollTop = menu.scrollTop;
                    distance = targetScrollTop - startScrollTop;
                    startTime = currentTime;
                }

                const elapsed = currentTime - startTime;
                const progress = Math.min(elapsed / duration, 1);
                const eased = easeOutCubic(progress);

                menu.scrollTop = startScrollTop + distance * eased;

                if (progress < 1) {
                    requestAnimationFrame(step);
                } else {
                    menu.scrollTop = targetScrollTop;
                    updateDots();
                    isAnimating = false;

                    window.setTimeout(function () {
                        if (!isAnimating) {
                            menu.scrollTop = targetScrollTop;
                            updateDots();
                        }
                    }, 90);
                }
            }

            requestAnimationFrame(step);
        }

        function goToSlide(index) {
            const safeIndex = Math.max(0, Math.min(index, slides.length - 1));
            const target = getSlideScrollTop(slides[safeIndex]);

            updateDots(safeIndex);
            animateScrollTo(target);
        }

        window.addEventListener('bueno:gallery-lightbox-change', function (event) {
            const detail = event.detail || {};
            if (detail.gallery !== menu || !menu.isConnected) return;
            const index = Number(detail.index);
            if (!Number.isFinite(index)) return;
            wheelAccumulator = 0;
            goToSlide(index);
        });

        function isEditableTarget(target) {
            const element = target instanceof Element ? target : null;

            if (!element) return false;

            return !!element.closest(
                'input, textarea, select, button, [contenteditable="true"]'
            );
        }

        function getKeyboardDirection(event) {
            if (event.key === 'ArrowDown') return 1;
            if (event.key === 'ArrowRight') return 1;
            if (event.key === 'PageDown') return 1;

            if (event.key === 'ArrowUp') return -1;
            if (event.key === 'ArrowLeft') return -1;
            if (event.key === 'PageUp') return -1;

            if (event.key === ' ' || event.code === 'Space' || event.key === 'Spacebar') {
                return event.shiftKey ? -1 : 1;
            }

            return 0;
        }

        function resetWheelAccumulatorSoon() {
            clearTimeout(wheelResetTimer);

            wheelResetTimer = setTimeout(function () {
                wheelAccumulator = 0;
            }, 140);
        }

        function canCaptureMenuMove(direction) {
            if (!pageIsSnappedToMenu()) {
                return false;
            }

            const scrollingDown = direction > 0;
            const scrollingUp = direction < 0;

            const atTop = menu.scrollTop <= 1;
            const atBottom =
                Math.ceil(menu.scrollTop + menu.clientHeight) >= menu.scrollHeight - 1;

            return !((scrollingDown && atBottom) || (scrollingUp && atTop));
        }

        function moveMenu(direction) {
            const currentIndex = getClosestSlideIndex();
            const nextIndex = currentIndex + direction;

            goToSlide(nextIndex);
        }

        function captureMenuEvent(event) {
            if (event.cancelable) {
                event.preventDefault();
            }

            event.stopPropagation();
        }

        function handleWheelMove(event) {
            if (document.body.classList.contains('cat-lightbox-open')) return;
            const delta = event.deltaY;

            if (delta === 0) return;

            if (isAnimating) {
                captureMenuEvent(event);
                requestDotsVisibilityUpdate();
                return;
            }

            const direction = delta > 0 ? 1 : -1;

            if (!pageIsSnappedToMenu()) {
                captureMenuEvent(event);

                if (!isAnimating && shouldAlignPageToMenu(direction)) {
                    alignPageToMenu();
                }

                requestDotsVisibilityUpdate();
                return;
            }

            if (!canCaptureMenuMove(direction)) {
                requestDotsVisibilityUpdate();
                return;
            }

            captureMenuEvent(event);

            if (isAnimating) return;

            wheelAccumulator += delta;
            resetWheelAccumulatorSoon();

            if (Math.abs(wheelAccumulator) < wheelThreshold) {
                return;
            }

            wheelAccumulator = 0;
            moveMenu(direction);
        }

        let dotsUpdateFrame = null;
        let dotsSettleTimer = null;
        let lockedMenuScrollTop = null;
        let menuTouchStartX = null;
        let menuTouchStartY = null;
        let menuTouchHandled = false;
        let menuTouchStartedAwayFromMenu = false;

        function requestDotsUpdate() {
            if (dotsUpdateFrame) return;

            dotsUpdateFrame = requestAnimationFrame(function () {
                dotsUpdateFrame = null;
                updateDots();
                updateDotsVisibility();
            });
        }

        function requestSettledDotsUpdate() {
            requestDotsUpdate();
            clearTimeout(dotsSettleTimer);
            dotsSettleTimer = setTimeout(requestDotsUpdate, 260);
        }

        function alignPageToMenu() {
            window.scrollTo(0, getMenuPageTarget());
            requestDotsVisibilityUpdate();
        }

        function shouldAlignMenuFromTouch(point) {
            if (menuTouchStartX === null || menuTouchStartY === null || !point) return false;

            const deltaX = menuTouchStartX - point.clientX;
            const deltaY = menuTouchStartY - point.clientY;

            if (deltaY < pageSnapTolerance) return false;
            if (Math.abs(deltaY) < Math.abs(deltaX) * touchAxisRatio) return false;

            return true;
        }

        function getMenuTouchPoint(event) {
            if (event.touches && event.touches.length === 1) {
                return event.touches[0];
            }

            if (event.changedTouches && event.changedTouches.length === 1) {
                return event.changedTouches[0];
            }

            return null;
        }

        function getMenuTouchDirection(point) {
            if (menuTouchStartX === null || menuTouchStartY === null || !point) return 0;

            const deltaX = menuTouchStartX - point.clientX;
            const deltaY = menuTouchStartY - point.clientY;

            if (Math.abs(deltaY) < touchThreshold) return 0;
            if (Math.abs(deltaY) < Math.abs(deltaX) * touchAxisRatio) return 0;

            return deltaY > 0 ? 1 : -1;
        }

        function handleMenuTouchStart(event) {
            const point = getMenuTouchPoint(event);

            lockedMenuScrollTop = menu.scrollTop;
            menuTouchStartX = point ? point.clientX : null;
            menuTouchStartY = point ? point.clientY : null;
            menuTouchHandled = false;
            menuTouchStartedAwayFromMenu = !pageIsSnappedToMenu();
        }

        function handleMenuTouchMove(event) {
            const point = getMenuTouchPoint(event);

            if (menuTouchHandled) {
                captureMenuEvent(event);
                return;
            }

            if (menuTouchStartedAwayFromMenu) {
                captureMenuEvent(event);

                if (lockedMenuScrollTop !== null) {
                    menu.scrollTop = lockedMenuScrollTop;
                }

                if (shouldAlignMenuFromTouch(point)) {
                    menuTouchHandled = true;
                    alignPageToMenu();
                }

                return;
            }

            const direction = getMenuTouchDirection(point);

            if (!direction) return;
            if (!canCaptureMenuMove(direction)) return;

            captureMenuEvent(event);

            menuTouchHandled = true;
            wheelAccumulator = 0;

            if (isAnimating) return;

            moveMenu(direction);
        }

        function handleMenuTouchEnd() {
            lockedMenuScrollTop = null;
            menuTouchStartX = null;
            menuTouchStartY = null;
            menuTouchHandled = false;
            menuTouchStartedAwayFromMenu = false;
            requestSettledDotsUpdate();
        }

        applySlideBackgrounds();
        updateDots(0);
        updateDotsVisibility();

        window.addEventListener('bueno:gallery-tone-ready', function () {
            updateDots();
        });

        window.addEventListener('scroll', requestDotsVisibilityUpdate, { passive: true });
        window.addEventListener('resize', requestDotsVisibilityUpdate);

        menu.addEventListener('scroll', requestDotsUpdate, { passive: true });
        menu.addEventListener('scrollend', requestDotsUpdate, { passive: true });
        menu.addEventListener('touchstart', handleMenuTouchStart, { passive: true, capture: true });
        menu.addEventListener('touchmove', handleMenuTouchMove, { passive: false, capture: true });
        menu.addEventListener('touchmove', requestDotsUpdate, { passive: true });
        menu.addEventListener('touchend', handleMenuTouchEnd, { passive: true });
        menu.addEventListener('touchcancel', handleMenuTouchEnd, { passive: true });

        menu.addEventListener('wheel', handleWheelMove, { passive: false });

        /* Keep gallery/menu slide movement first even when the pointer is
           outside the scrollable panel while the page is snapped to it. */
        window.addEventListener('wheel', function (event) {
            if (document.querySelector('body.menu-page #main > .post.menu-snap') !== menu) return;
            if (!pageIsSnappedToMenu()) return;

            handleWheelMove(event);
        }, { passive: false, capture: true });

        dotButtons.forEach((button) => {
            button.addEventListener('wheel', handleWheelMove, { passive: false });
        });

        window.addEventListener('keydown', function (event) {
            if (document.querySelector('body.menu-page #main > .post.menu-snap') !== menu) return;
            if (event.defaultPrevented) return;
            if (isEditableTarget(event.target)) return;

            const direction = getKeyboardDirection(event);

            if (!direction) return;
            if (!canCaptureMenuMove(direction)) {
                requestDotsVisibilityUpdate();
                return;
            }

            captureMenuEvent(event);

            if (isAnimating) return;

            moveMenu(direction);

        }, { capture: false });
    }

    function bootMenu() {
        const menu = document.querySelector('body.menu-page #main > .post.menu-snap');

        if (!menu) return;

        if (menu.classList.contains('cat-gallery')) {
            initMenuSnap();
            return;
        }

        loadMenuSlides().then(initMenuSnap);
    }

    window.addEventListener('bueno:cat-gallery-ready', initMenuSnap);

    if (document.readyState === 'complete') {
        requestAnimationFrame(bootMenu);
    } else {
        window.addEventListener('load', function () {
            requestAnimationFrame(bootMenu);
        }, { once: true });
    }
})();


(function () {
    if (window.MamonaPerformance && window.MamonaPerformance.shouldReduceEffects()) return;
    function initMainSnap() {
        const intro = document.querySelector('#intro');
        const header = document.querySelector('#header');
        const nav = document.querySelector('#nav');
        const main = document.querySelector('#main');
        const footer = document.querySelector('#footer');
        let menu = document.querySelector('#main > .post.menu-snap');

        /* Keep the page-position snap on every page.  The wheel handler below
           leaves long main content to the browser, while touch and keyboard
           gestures still snap between intro, header, main and footer. */
        if (!nav || !main || !footer) return;

        let mainScrollEnabled = !menu && !document.body.classList.contains('admin-page');

        window.buenoRefreshMainSnap = function () {
            menu = document.querySelector('#main > .post.menu-snap');
            mainScrollEnabled = !menu && !document.body.classList.contains('admin-page');
        };

        if ('scrollRestoration' in history) {
            history.scrollRestoration = 'manual';
        }

        let isPageAnimating = false;
        let wheelAccumulator = 0;
        let wheelResetTimer = null;
        let touchStartX = null;
        let touchStartY = null;
        let touchStartTarget = null;
        let touchHandled = false;
        let lastTouchY = null;
        let touchStartedBelowFooterTop = false;
        let footerWheelGateActive = false;
        let footerWheelGateTimer = null;
        let footerGateFrame = null;

        const duration = 450;
        const wheelThreshold = 3;
        const touchThreshold = 3;
        const menuAlignTouchThreshold = 3;
        const touchAxisRatio = 1.05;
        const snapTolerance = 12;

        /*
            Ten offset możesz potem lekko korygować.
            0 = snap dokładnie do #header.
            Jeśli pozycja z BUENO będzie za wysoko, daj np. 40.
            Jeśli będzie za nisko, daj np. -40.
        */
        const introSnapOffset = 0;

        function easeOutCubic(t) {
            return 1 - Math.pow(1 - t, 3);
        }

        function clamp(value, min, max) {
            return Math.max(min, Math.min(value, max));
        }

        function maxScrollY() {
            return Math.max(
                0,
                document.documentElement.scrollHeight - window.innerHeight
            );
        }

        function clampScroll(value) {
            return Math.round(clamp(value, 0, maxScrollY()));
        }

        function navHeight() {
            return nav.offsetHeight || 0;
        }

        function documentLayoutTop(element) {
            let top = 0;
            let current = element;

            while (current) {
                top += current.offsetTop || 0;
                current = current.offsetParent;
            }

            return top;
        }

        function hasIntroStep() {
            /*
                Tylko index.html ma intro.
                Menu burgerowe nie powinno mieć tego dodatkowego kroku.
            */
            return !!intro && !!header && !menu && !document.body.classList.contains('page-no-intro');
        }

        function getIntroHeaderTarget() {
            if (!header) return 0;

            return clampScroll(
                header.getBoundingClientRect().top +
                window.scrollY +
                introSnapOffset
            );
        }

        function getMainStartTarget() {
            const mobileMenuSnapOffset =
                menu && window.matchMedia('(max-width: 980px)').matches ? 2 : 0;

            return clampScroll(
                documentLayoutTop(main) +
                mobileMenuSnapOffset
            );
        }

        function mainHasOverflow() {
            return mainScrollEnabled && main.scrollHeight > main.clientHeight + 2;
        }

        function mainAtTop() {
            return !mainHasOverflow() || main.scrollTop <= 1;
        }

        function mainAtBottom() {
            return !mainHasOverflow() || Math.ceil(main.scrollTop + main.clientHeight) >= main.scrollHeight - 1;
        }

        function pageIsAtMainShell() {
            return mainScrollEnabled && Math.abs((window.scrollY || window.pageYOffset) - getMainStartTarget()) <= snapTolerance;
        }

        function syncNavToMain() {
            if (!document.body.classList.contains('menu-page')) return;

            const mainRect = main.getBoundingClientRect();
            const mainDocumentTop = documentLayoutTop(main);
            nav.style.setProperty('--nav-main-top', `${Math.round(mainDocumentTop)}px`);
            nav.style.setProperty('width', `${Math.round(mainRect.width)}px`, 'important');
            // The public nav is centered with the same auto margins as #main.
            // A legacy inline 50% anchor combined with removed translate(-50%)
            // pushed the bar to the right, so horizontal placement stays in CSS.
            nav.style.removeProperty('left');
            footer.style.setProperty('width', `${Math.round(mainRect.width)}px`, 'important');
            footer.style.setProperty('max-width', 'none', 'important');
        }

        function syncFooterNavigation() {
            if (!document.body.classList.contains('menu-page')) {
                return;
            }

            syncNavToMain();

            if (window.innerWidth <= 980) {
                document.body.classList.remove('is-footer-nav-collapsed');
                return;
            }

            const currentY = window.scrollY || window.pageYOffset;
            const footerTarget = getFooterTarget();
            const collapseThreshold = Math.max(snapTolerance, 24);
            const shouldCollapse = currentY >= footerTarget - collapseThreshold;

            if (shouldCollapse) {
                document.body.classList.add('is-footer-nav-collapsed');
                return;
            }

            document.body.classList.remove('is-footer-nav-collapsed');
        }

        function pageIsSnappedToMenu() {
            if (!menu) return true;

            return Math.abs((window.scrollY || window.pageYOffset) - getMainStartTarget()) <= snapTolerance;
        }

        function touchStartedInsideMenu() {
            return !!(
                menu &&
                touchStartTarget instanceof Element &&
                menu.contains(touchStartTarget)
            );
        }

        function getMainEndTarget() {
            const mainStart = getMainStartTarget();

            if (mainScrollEnabled) {
                return mainStart;
            }

            const rawMainEnd =
                documentLayoutTop(main) +
                main.offsetHeight -
                window.innerHeight;

            /*
                Ważny fix:
                jeśli #main jest niższy niż viewport, koniec maina nie może wypaść
                wyżej niż początek maina. To naprawia snap z footera za wysoko.
            */
            return clampScroll(Math.max(mainStart, rawMainEnd));
        }

        function getFooterTarget() {
            const footerTopTarget = getFooterTopTarget();
            const footerBottomTarget =
                footer.getBoundingClientRect().bottom +
                window.scrollY -
                window.innerHeight;

            return clampScroll(
                Math.max(footerTopTarget, footerBottomTarget)
            );
        }

        function getFooterTopTarget() {
            // Keep the raw document position here. Clamping this value to the
            // document bottom makes a tall footer look as if its top were
            // already visible while the viewport is still at the footer end.
            return footer.getBoundingClientRect().top +
                window.scrollY -
                navHeight();
        }

        function getMenuBottomTarget() {
            if (!menu) return 0;

            return Math.max(0, menu.scrollHeight - menu.clientHeight);
        }

        function menuAtTop() {
            if (!menu) return true;
            return menu.scrollTop <= 1;
        }

        function menuAtBottom() {
            if (!menu) return true;

            return Math.ceil(menu.scrollTop + menu.clientHeight) >= menu.scrollHeight - 1;
        }

        function animateWindowTo(targetScrollY) {
            let startScrollY = null;
            let distance = null;
            let startTime = null;

            targetScrollY = clampScroll(targetScrollY);
            if (Math.abs(targetScrollY - (window.scrollY || window.pageYOffset)) < 3) {
                window.scrollTo(0, targetScrollY);
                return;
            }

            isPageAnimating = true;

            function step(currentTime) {
                if (startTime === null) {
                    /*
                        Czytamy pozycje dopiero w pierwszej klatce animacji.
                        Dzieki temu snap nie cofnie strony do pozycji z momentu
                        eventu wheel, jesli przegladarka zdazyla ruszyc scroll
                        o pare pikseli przed requestAnimationFrame.
                    */
                    startScrollY = window.scrollY || window.pageYOffset;
                    distance = targetScrollY - startScrollY;
                    startTime = currentTime;
                }

                const elapsed = currentTime - startTime;
                const progress = Math.min(elapsed / duration, 1);
                const eased = easeOutCubic(progress);

                window.scrollTo(0, startScrollY + distance * eased);

                if (progress < 1) {
                    requestAnimationFrame(step);
                } else {
                    window.scrollTo(0, targetScrollY);
                    isPageAnimating = false;

                    window.setTimeout(function () {
                        if (!isPageAnimating) {
                            window.scrollTo(0, targetScrollY);
                        }
                    }, 90);
                }
            }

            requestAnimationFrame(step);
        }

        function animateWindowAndMenuTo(targetScrollY, targetMenuScrollTop, customDuration) {
            let startScrollY = null;
            let startMenuScrollTop = null;
            let windowDistance = null;
            let menuDistance = null;
            let startTime = null;
            const durationMs = customDuration || 700;
            if (
                Math.abs(targetScrollY - (window.scrollY || window.pageYOffset)) < 3 &&
                (!menu || Math.abs(targetMenuScrollTop - menu.scrollTop) < 3)
            ) {
                window.scrollTo(0, targetScrollY);

                if (menu) {
                    menu.scrollTop = targetMenuScrollTop;
                }

                return;
            }

            isPageAnimating = true;

            function step(currentTime) {
                if (startTime === null) {
                    startScrollY = window.scrollY || window.pageYOffset;
                    startMenuScrollTop = menu ? menu.scrollTop : 0;
                    windowDistance = targetScrollY - startScrollY;
                    menuDistance = targetMenuScrollTop - startMenuScrollTop;
                    startTime = currentTime;
                }

                const elapsed = currentTime - startTime;
                const progress = Math.min(elapsed / durationMs, 1);
                const eased = easeOutCubic(progress);

                window.scrollTo(0, startScrollY + windowDistance * eased);

                if (menu) {
                    menu.scrollTop = startMenuScrollTop + menuDistance * eased;
                }

                if (progress < 1) {
                    requestAnimationFrame(step);
                } else {
                    window.scrollTo(0, targetScrollY);

                    if (menu) {
                        menu.scrollTop = targetMenuScrollTop;
                    }

                    isPageAnimating = false;

                    window.setTimeout(function () {
                        if (!isPageAnimating) {
                            window.scrollTo(0, targetScrollY);

                            if (menu) {
                                menu.scrollTop = targetMenuScrollTop;
                            }
                        }
                    }, 90);
                }
            }

            requestAnimationFrame(step);
        }

        function animateWindowToMenu(customDuration, targetMenuScrollTop) {
            const nextMenuScrollTop = typeof targetMenuScrollTop === 'number'
                ? targetMenuScrollTop
                : (menu ? menu.scrollTop : 0);
            const durationMs = customDuration || duration;

            animateWindowAndMenuTo(getMainStartTarget(), nextMenuScrollTop, durationMs);

            window.setTimeout(function () {
                window.scrollTo(0, getMainStartTarget());

                if (menu) {
                    menu.scrollTop = nextMenuScrollTop;
                }
            }, durationMs + 80);
        }

        function animateMainToBottom(customDuration) {
            if (!mainScrollEnabled || !mainHasOverflow()) return;

            const durationMs = customDuration || 700;
            const startScrollTop = main.scrollTop;
            const targetScrollTop = Math.max(0, main.scrollHeight - main.clientHeight);
            const distance = targetScrollTop - startScrollTop;
            const startTime = performance.now();

            function step(currentTime) {
                const progress = Math.min((currentTime - startTime) / durationMs, 1);
                main.scrollTop = startScrollTop + distance * easeOutCubic(progress);

                if (progress < 1) {
                    requestAnimationFrame(step);
                } else {
                    main.scrollTop = targetScrollTop;
                }
            }

            requestAnimationFrame(step);
        }

        function animateWindowAndMainTo(targetScrollY, targetMainScrollTop, customDuration) {
            const durationMs = customDuration || 700;
            const startScrollY = window.scrollY || window.pageYOffset;
            const startMainScrollTop = main.scrollTop;
            const windowDistance = targetScrollY - startScrollY;
            const mainDistance = targetMainScrollTop - startMainScrollTop;
            const startTime = performance.now();

            isPageAnimating = true;

            function step(currentTime) {
                const progress = Math.min((currentTime - startTime) / durationMs, 1);
                const eased = easeOutCubic(progress);

                // Queue the next movement before scroll events schedule the
                // parallax update, so the background is calculated after both
                // scroll containers have reached this frame's position.
                if (progress < 1) {
                    requestAnimationFrame(step);
                }

                window.scrollTo(0, startScrollY + windowDistance * eased);
                main.scrollTop = startMainScrollTop + mainDistance * eased;

                if (progress < 1) {
                    return;
                }

                window.scrollTo(0, targetScrollY);
                main.scrollTop = targetMainScrollTop;
                isPageAnimating = false;
            }

            requestAnimationFrame(step);
        }

        function animateFooterJump() {
            const currentFooterRect = footer.getBoundingClientRect();

            // The footer is already on screen: clicking Kontakt again must not
            // recalculate a different document target and jump back to header.
            if (currentFooterRect.top < window.innerHeight && currentFooterRect.bottom > 0) {
                return;
            }

            const targetScrollY = getFooterTarget();
            const targetMenuScrollTop = menu ? getMenuBottomTarget() : 0;
			const targetMainScrollTop = Math.max(0, main.scrollHeight - main.clientHeight);
			const windowAlreadyAtTarget = Math.abs((window.scrollY || window.pageYOffset) - targetScrollY) < 3;
			const mainAlreadyAtTarget = !mainScrollEnabled || !mainHasOverflow() || Math.abs(main.scrollTop - targetMainScrollTop) < 2;

			if (windowAlreadyAtTarget && mainAlreadyAtTarget) {
				return;
			}

            if (mainScrollEnabled && mainHasOverflow()) {
                animateWindowAndMainTo(
                    targetScrollY,
                    targetMainScrollTop,
                    700
                );
                return;
            }

            animateWindowAndMenuTo(targetScrollY, targetMenuScrollTop, 700);
        }

        function animateMenuHomeJump() {
            animateWindowToMenu(700, 0);
        }

        function shouldReturnFromFooterToMenu(directionDown, targetScrollY) {
            if (!menu || directionDown) return false;
            if (Math.abs(targetScrollY - getMainStartTarget()) > snapTolerance) return false;

            return (window.scrollY || window.pageYOffset) >= getFooterTarget() - snapTolerance;
        }

        function resetWheelAccumulatorSoon() {
            clearTimeout(wheelResetTimer);

            wheelResetTimer = setTimeout(function () {
                wheelAccumulator = 0;
            }, 140);
        }

        function isEditableTarget(target) {
            const element = target instanceof Element ? target : null;

            if (!element) return false;

            return !!element.closest(
                'input, textarea, select, button, [contenteditable="true"]'
            );
        }

        function isLikelyTouchpadWheel(event) {
            if (event.deltaMode !== 0) return false;

            const deltaX = Math.abs(event.deltaX);
            const deltaY = Math.abs(event.deltaY);

            /*
                Touchpad emituje serię drobnych, pikselowych impulsów
                (często również z osią X). Zostawiamy je przeglądarce,
                bo jej natywna bezwładność jest wtedy wyraźnie lepsza.
            */
            return deltaX > 0 || (deltaY > 0 && deltaY < 60);
        }

        function resetTouchState() {
            touchStartX = null;
            touchStartY = null;
            touchStartTarget = null;
            touchHandled = false;
            lastTouchY = null;
            touchStartedBelowFooterTop = false;
        }

        function footerTopIsBelowViewportStart() {
            return footer.getBoundingClientRect().top < -snapTolerance;
        }

        function footerTopScrollTarget() {
            return clampScroll(getFooterTopTarget());
        }

        function keepFooterTopAsRequiredStop() {
            if (!footerWheelGateActive || footerGateFrame) return;

            footerGateFrame = requestAnimationFrame(function () {
                footerGateFrame = null;

                if (!footerWheelGateActive || footerTopIsBelowViewportStart()) return;

                const target = footerTopScrollTarget();
                if (Math.abs((window.scrollY || window.pageYOffset) - target) > 1) {
                    window.scrollTo(0, target);
                }
            });
        }

        function holdFooterWheelGate() {
            footerWheelGateActive = true;
            clearTimeout(footerWheelGateTimer);
            footerWheelGateTimer = setTimeout(function () {
                footerWheelGateActive = false;
            }, 260);
        }

        function getTouchPoint(event) {
            if (event.touches && event.touches.length === 1) {
                return event.touches[0];
            }

            if (event.changedTouches && event.changedTouches.length === 1) {
                return event.changedTouches[0];
            }

            return null;
        }

        function getTouchDirection(point) {
            if (touchStartX === null || touchStartY === null || !point) return null;

            const deltaX = touchStartX - point.clientX;
            const deltaY = touchStartY - point.clientY;

            if (Math.abs(deltaY) < touchThreshold) return null;
            if (Math.abs(deltaY) < Math.abs(deltaX) * touchAxisRatio) return null;

            return deltaY > 0;
        }

        function shouldAlignMenuPageBeforeMenuMove(point) {
            if (!touchStartedInsideMenu()) return false;
            if (pageIsSnappedToMenu()) return false;
            if (touchStartX === null || touchStartY === null || !point) return false;

            const deltaX = touchStartX - point.clientX;
            const deltaY = touchStartY - point.clientY;

            if (deltaY < menuAlignTouchThreshold) return false;
            if (Math.abs(deltaY) < Math.abs(deltaX) * touchAxisRatio) return false;

            return true;
        }

        function getKeyboardAction(event) {
            if (event.key === 'ArrowDown') return 'down';
            if (event.key === 'ArrowRight') return 'down';
            if (event.key === 'PageDown') return 'down';

            if (event.key === 'ArrowUp') return 'up';
            if (event.key === 'ArrowLeft') return 'up';
            if (event.key === 'PageUp') return 'up';

            if (event.key === 'Home') return 'home';
            if (event.key === 'End') return 'end';

            if (event.key === ' ' || event.code === 'Space' || event.key === 'Spacebar') {
                return event.shiftKey ? 'up' : 'down';
            }

            return null;
        }

        function getTargetForDirection(directionDown, eventTarget) {
            const currentY = window.scrollY || window.pageYOffset;

            const introHeaderTarget = getIntroHeaderTarget();
            const mainStartTarget = getMainStartTarget();
            const mainEndTarget = getMainEndTarget();
            const footerTarget = getFooterTarget();
            const footerTopTarget = getFooterTopTarget();

            const directionUp = !directionDown;

            const pageBeforeIntroHeader =
                hasIntroStep() &&
                currentY < introHeaderTarget - snapTolerance;

            const hasHeaderSnapTarget =
                !!header &&
                introHeaderTarget < mainStartTarget - snapTolerance;

            /*
                Galeria ma własny przewijany panel (.menu-snap), dlatego nie
                przechodzi przez zwykły blok "intro -> header" poniżej.
                Bez tego pierwszy scroll z pełnego logo omijał header i
                lądował od razu na galerii. Header jest osobnym, obowiązkowym
                przystankiem także dla galerii.
            */
            const pageBeforeHeader =
                hasHeaderSnapTarget &&
                currentY < introHeaderTarget - snapTolerance;

            const pageAtIntroHeader =
                hasHeaderSnapTarget &&
                Math.abs(currentY - introHeaderTarget) <= snapTolerance;

            const pageBetweenIntroHeaderAndMain =
                hasIntroStep() &&
                currentY > introHeaderTarget + snapTolerance &&
                currentY < mainStartTarget - snapTolerance;

            const pageBeforeMain =
                currentY < mainStartTarget - snapTolerance;

            const pageAtMainStart =
                Math.abs(currentY - mainStartTarget) <= snapTolerance;

            const pageInsideMain =
                currentY > mainStartTarget + snapTolerance &&
                currentY < mainEndTarget - snapTolerance;

            const pageAtMainEnd =
                Math.abs(currentY - mainEndTarget) <= snapTolerance;

            const pageBetweenMainEndAndFooter =
                currentY > mainEndTarget + snapTolerance &&
                currentY < footerTarget - snapTolerance;

            const pageAtFooter =
                Math.abs(currentY - footerTarget) <= snapTolerance;

            const pageAfterFooter =
                currentY > footerTarget + snapTolerance;

            const pageAtFooterTop =
                Math.abs(currentY - footerTopTarget) <= snapTolerance;
            const footerRect = footer.getBoundingClientRect();
            const footerTopVisible = footerRect.top >= -snapTolerance;

            const mainIsLong = mainScrollEnabled
                ? mainHasOverflow()
                : mainEndTarget > mainStartTarget + snapTolerance;

            if (mainScrollEnabled && pageAtMainStart) {
                if (directionDown && !mainAtBottom()) {
                    return null;
                }

                if (directionUp && !mainAtTop()) {
                    return null;
                }
            }

            /*
                MENU.HTML:
                header -> menu
                burgery obsluguje sekcja menu w tym samym pliku
                koniec menu -> footer
            */
            if (menu) {
                const cursorInsideMenu = menu.contains(eventTarget);

                if (directionUp && pageAtIntroHeader) {
                    return 0;
                }

                if (directionDown && pageBeforeHeader) {
                    return introHeaderTarget;
                }

                if (directionDown && pageBeforeMain) {
                    return mainStartTarget;
                }

                if (directionUp && currentY > mainStartTarget + snapTolerance) {
                    return mainStartTarget;
                }

                if (pageAtMainStart) {
                    if (cursorInsideMenu) {
                        if (directionDown && !menuAtBottom()) {
                            return null;
                        }

                        if (directionUp && !menuAtTop()) {
                            return null;
                        }
                    }

                    if (directionDown && menuAtBottom()) {
                        return footerTarget;
                    }

                    if (directionUp && menuAtTop()) {
                        return hasHeaderSnapTarget ? introHeaderTarget : 0;
                    }
                }

                return null;
            }

            /*
                INDEX.HTML:
                pierwszy lekki scroll nie idzie od razu do maina,
                tylko do pozycji z widocznym BUENO w headerze.
            */
            if (hasIntroStep()) {
                if (directionDown && pageBeforeIntroHeader) {
                    return introHeaderTarget;
                }

                if (
                    directionDown &&
                    (pageAtIntroHeader || pageBetweenIntroHeaderAndMain)
                ) {
                    return mainStartTarget;
                }

                if (
                    directionUp &&
                    (pageAtMainStart || pageBetweenIntroHeaderAndMain)
                ) {
                    return introHeaderTarget;
                }

                if (directionUp && pageAtIntroHeader) {
                    return 0;
                }
            }

            /*
                PODSTRONY / zwykłe strony bez intro:
                header -> main.
            */
            if (!hasIntroStep()) {
                if (directionDown && pageBeforeMain) {
                    return mainStartTarget;
                }

                if (directionUp && pageAtMainStart) {
                    return 0;
                }
            }

            /*
                Długi #main:
                pozwalamy normalnie scrollować treść.
            */
            if (mainIsLong) {
                if (directionDown && (pageAtMainStart || pageInsideMain) && (!mainScrollEnabled || !mainAtBottom())) {
                    return null;
                }

                if (directionUp && (pageInsideMain || pageAtMainEnd) && (!mainScrollEnabled || !mainAtTop())) {
                    return null;
                }
            }

            /*
                Krótki #main:
                z początku maina można snapować od razu do footera.
            */
            if (!mainIsLong && directionDown && pageAtMainStart) {
                return footerTarget;
            }

            /*
                Koniec #main -> footer.
            */
            if (
                directionDown &&
                (pageAtMainEnd || pageBetweenMainEndAndFooter)
            ) {
                return footerTarget;
            }

            /*
                Footer -> koniec #main.
                Przy krótkim #main będzie to automatycznie początek #main,
                bo getMainEndTarget() jest zabezpieczone Math.max().
            */
            if (
                directionUp &&
                (pageAtFooter || pageAfterFooter || pageAtFooterTop)
            ) {
                // On very short/tall-footer viewports, the footer jump target
                // is its bottom. Let the browser reveal the footer normally
                // until its top is visible before snapping back to #main.
                if (!footerTopVisible) {
                    return null;
                }

                return mainEndTarget;
            }

            return null;
        }

        function getTargetForTouchDirection(directionDown, eventTarget) {
            const target = getTargetForDirection(directionDown, eventTarget);

            return target;
        }

        window.addEventListener('wheel', function (event) {
            if (document.body.classList.contains('cat-lightbox-open')) return;
            if (event.target instanceof Element && event.target.closest('#navPanel')) return;
            const delta = event.deltaY;

            if (
                delta < 0 &&
                event.target instanceof Element &&
                footer.contains(event.target) &&
                (footerTopIsBelowViewportStart() || footerWheelGateActive)
            ) {
                holdFooterWheelGate();

                if (!footerTopIsBelowViewportStart()) {
                    if (event.cancelable) event.preventDefault();
                    event.stopPropagation();
                    animateWindowTo(footerTopScrollTarget());
                }

                return;
            }

            if (
                event.target instanceof Element &&
                footer.contains(event.target) &&
                footer.getBoundingClientRect().top < -snapTolerance
            ) return;

            if (delta === 0) return;

            if (isPageAnimating) {
                if (event.cancelable) {
                    event.preventDefault();
                }

                event.stopPropagation();
                return;
            }

            if (mainScrollEnabled && pageIsAtMainShell() && mainHasOverflow()) {
                const directionDown = delta > 0;
                const canScrollMain = (directionDown && !mainAtBottom()) || (!directionDown && !mainAtTop());

                if (canScrollMain) {
                    if (event.cancelable) {
                        event.preventDefault();
                    }

                    event.stopPropagation();
                    main.scrollTop = Math.max(0, Math.min(main.scrollHeight - main.clientHeight, main.scrollTop + delta));
                    return;
                }
            }

            const directionDown = delta > 0;
            const target = getTargetForDirection(directionDown, event.target);

            /* A null target intentionally leaves scrolling to the browser. */
            if (target === null) return;

            event.preventDefault();
            event.stopPropagation();

            if (menu && Math.abs(target - getMainStartTarget()) <= snapTolerance) {
                animateWindowToMenu(
                    undefined,
                    shouldReturnFromFooterToMenu(directionDown, target) ? getMenuBottomTarget() : undefined
                );
                return;
            }

            wheelAccumulator += delta;
            resetWheelAccumulatorSoon();

            if (Math.abs(wheelAccumulator) < wheelThreshold) {
                return;
            }

            wheelAccumulator = 0;

            animateWindowTo(target);

        }, { passive: false, capture: true });

        window.addEventListener('touchstart', function (event) {
            if (event.defaultPrevented) return;

            const point = getTouchPoint(event);

            if (!point) {
                resetTouchState();
                return;
            }

            touchStartX = point.clientX;
            touchStartY = point.clientY;
            touchStartTarget = event.target;
            touchHandled = false;
            lastTouchY = point.clientY;
            touchStartedBelowFooterTop = touchStartTarget instanceof Element &&
                footer.contains(touchStartTarget) &&
                footerTopIsBelowViewportStart();
        }, { passive: true, capture: true });

        window.addEventListener('touchmove', function (event) {
            if (touchStartTarget instanceof Element && touchStartTarget.closest('#navPanel')) return;
            if (touchStartedBelowFooterTop) {
                if (footerTopIsBelowViewportStart()) return;

                if (event.cancelable) event.preventDefault();
                event.stopPropagation();
                touchHandled = true;

                if (!isPageAnimating) {
                    animateWindowTo(footerTopScrollTarget());
                }

                return;
            }

            if (touchHandled) {
                if (event.cancelable) {
                    event.preventDefault();
                }

                event.stopPropagation();
                return;
            }

            if (isPageAnimating) {
                if (event.cancelable) {
                    event.preventDefault();
                }

                event.stopPropagation();
                return;
            }

            const point = getTouchPoint(event);

            if (shouldAlignMenuPageBeforeMenuMove(point)) {
                if (event.cancelable) {
                    event.preventDefault();
                }

                event.stopPropagation();

                touchHandled = true;

                if (!isPageAnimating) {
                    animateWindowToMenu();
                }

                return;
            }

            if (mainScrollEnabled && pageIsAtMainShell() && point && lastTouchY !== null && mainHasOverflow()) {
                const delta = lastTouchY - point.clientY;
                const directionDown = delta > 0;
                const canScrollMain = (directionDown && !mainAtBottom()) || (!directionDown && !mainAtTop());
                lastTouchY = point.clientY;

                if (Math.abs(delta) >= 1 && canScrollMain) {
                    if (event.cancelable) {
                        event.preventDefault();
                    }

                    event.stopPropagation();
                    main.scrollTop = Math.max(0, Math.min(main.scrollHeight - main.clientHeight, main.scrollTop + delta));
                    return;
                }
            }

            const directionDown = getTouchDirection(point);

            if (directionDown === null) return;

            const target = getTargetForTouchDirection(directionDown, touchStartTarget || event.target);

            if (target === null) return;

            if (event.cancelable) {
                event.preventDefault();
            }

            event.stopPropagation();

            touchHandled = true;

            if (isPageAnimating) return;

            if (menu && Math.abs(target - getMainStartTarget()) <= snapTolerance) {
                animateWindowToMenu(
                    undefined,
                    shouldReturnFromFooterToMenu(directionDown, target) ? getMenuBottomTarget() : undefined
                );
                return;
            }

            animateWindowTo(target);
        }, { passive: false, capture: true });

        window.addEventListener('touchend', function (event) {
            if (touchStartTarget instanceof Element && touchStartTarget.closest('#navPanel')) {
                resetTouchState();
                return;
            }

            if (touchStartedBelowFooterTop) {
                if (!footerTopIsBelowViewportStart() && !isPageAnimating) {
                    animateWindowTo(footerTopScrollTarget());
                }

                resetTouchState();
                return;
            }

            if (!touchHandled) {
                const point = getTouchPoint(event);
                const directionDown = getTouchDirection(point);

                if (directionDown !== null) {
                    const target = getTargetForTouchDirection(directionDown, touchStartTarget || event.target);

                    if (target !== null && !isPageAnimating) {
                        if (menu && Math.abs(target - getMainStartTarget()) <= snapTolerance) {
                            animateWindowToMenu(
                                undefined,
                                shouldReturnFromFooterToMenu(directionDown, target) ? getMenuBottomTarget() : undefined
                            );
                        }
                        else {
                            animateWindowTo(target);
                        }
                    }
                }
            }

            resetTouchState();
        }, { passive: true, capture: true });

        window.addEventListener('touchcancel', resetTouchState, { passive: true, capture: true });
        window.addEventListener('scroll', keepFooterTopAsRequiredStop, { passive: true });

        window.addEventListener('keydown', function (event) {
            if (event.defaultPrevented) return;
            if (isEditableTarget(event.target)) return;

            const action = getKeyboardAction(event);

            if (!action) return;

            if (mainScrollEnabled && pageIsAtMainShell() && (action === 'down' || action === 'up') && mainHasOverflow()) {
                const directionDown = action === 'down';
                const canScrollMain = (directionDown && !mainAtBottom()) || (!directionDown && !mainAtTop());

                if (canScrollMain) {
                    event.preventDefault();
                    event.stopPropagation();
                    main.scrollTop = Math.max(0, Math.min(main.scrollHeight - main.clientHeight, main.scrollTop + (directionDown ? main.clientHeight * 0.82 : -main.clientHeight * 0.82)));
                    return;
                }
            }

            let target = null;

            if (action === 'home') {
                target = 0;
            }

            else if (action === 'end') {
                target = getFooterTarget();
            }

            else {
                target = getTargetForDirection(action === 'down', event.target);
            }

            if (target === null) return;

            event.preventDefault();
            event.stopPropagation();

            if (isPageAnimating) return;

            if (menu && Math.abs(target - getMainStartTarget()) <= snapTolerance) {
                animateWindowToMenu(
                    undefined,
                    shouldReturnFromFooterToMenu(action === 'down', target) ? getMenuBottomTarget() : undefined
                );
                return;
            }

            animateWindowTo(target);

        }, { capture: true });

        window.addEventListener('scroll', syncFooterNavigation, { passive: true });
        window.addEventListener('resize', syncFooterNavigation, { passive: true });
        main.addEventListener('scroll', syncFooterNavigation, { passive: true });
        syncFooterNavigation();

		if ('ResizeObserver' in window) {
			new ResizeObserver(syncFooterNavigation).observe(main);
		}

		window.setTimeout(syncFooterNavigation, 250);
		window.setTimeout(syncFooterNavigation, 750);

        document.addEventListener('click', function (event) {
            const target = event.target instanceof Element ? event.target : null;
            const headerLink = target ? target.closest('a.nav-header-jump') : null;
            const menuLink = target ? target.closest('a.nav-menu-jump') : null;
            const footerLink = target ? target.closest('a.nav-footer-jump') : null;

            if (!headerLink && !menuLink && !footerLink) return;

            event.preventDefault();

			// The legacy hamburger panel schedules window.location.href for every
			// link after closing. Stop that handler for in-page snap links; otherwise
			// Kontakt reloads the page and briefly resets the parallax/header.
			if (target && target.closest('#navPanel')) {
				event.stopPropagation();
				document.body.classList.remove('is-navPanel-visible');
			}

            if (isPageAnimating) return;

            if (headerLink) {
                animateWindowTo(getIntroHeaderTarget());
                return;
            }

            if (menuLink) {
                animateMenuHomeJump();
                return;
            }

            animateFooterJump();
        }, { capture: true });
    }

    if (document.querySelector('#nav') && document.querySelector('#main') && document.querySelector('#footer')) {
        initMainSnap();
    } else {
        document.addEventListener('DOMContentLoaded', initMainSnap, { once: true });
    }
})();
