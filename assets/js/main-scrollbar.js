(function () {
    const main = document.querySelector('body.menu-page:not(.admin-page) #main');
    const wrapper = document.querySelector('#wrapper');

    if (!main || main.querySelector(':scope > .post.menu-snap')) {
        return;
    }

    const mask = document.createElement('div');
    const bar = document.createElement('div');
    const thumb = document.createElement('button');
    let dragStartY = 0;

    mask.className = 'main-custom-scrollbar-mask';
    mask.setAttribute('aria-hidden', 'true');
    bar.className = 'main-custom-scrollbar';
    bar.setAttribute('aria-hidden', 'true');
    thumb.className = 'main-custom-scrollbar-thumb';
    thumb.type = 'button';
    thumb.tabIndex = -1;
    bar.appendChild(thumb);
    mask.appendChild(bar);
    (wrapper || document.body).appendChild(mask);
    main.classList.add('has-custom-scrollbar');

    function updateScrollbar() {
        const rect = main.getBoundingClientRect();
        const maxScroll = Math.max(0, main.scrollHeight - main.clientHeight);
        const intersectsViewport = rect.bottom > 0 && rect.top < window.innerHeight;

        mask.hidden = !intersectsViewport;

        if (!intersectsViewport) {
            return;
        }

        mask.style.top = `${rect.top}px`;
        mask.style.left = `${rect.left}px`;
        mask.style.width = `${rect.width}px`;
        mask.style.height = `${rect.height}px`;

        const trackHeight = Math.max(0, bar.clientHeight);
        const isVisible = maxScroll > 1 && trackHeight > 32;

        mask.hidden = !isVisible;

        if (!isVisible) {
            return;
        }

        const thumbHeight = Math.max(32, trackHeight * (main.clientHeight / main.scrollHeight));
        const thumbTravel = Math.max(0, trackHeight - thumbHeight);
        const thumbTop = maxScroll > 0 ? thumbTravel * (main.scrollTop / maxScroll) : 0;

        thumb.style.height = `${thumbHeight}px`;
        thumb.style.transform = `translateY(${thumbTop}px)`;
    }

    function setScrollFromPointer(clientY, centered) {
        const barRect = bar.getBoundingClientRect();
        const thumbHeight = thumb.offsetHeight;
        const thumbTravel = Math.max(1, barRect.height - thumbHeight);
        const pointerOffset = centered ? thumbHeight / 2 : dragStartY;
        const thumbTop = Math.max(0, Math.min(thumbTravel, clientY - barRect.top - pointerOffset));
        const maxScroll = Math.max(0, main.scrollHeight - main.clientHeight);

        main.scrollTop = (thumbTop / thumbTravel) * maxScroll;
    }

    thumb.addEventListener('pointerdown', function (event) {
        const thumbRect = thumb.getBoundingClientRect();
        dragStartY = event.clientY - thumbRect.top;
        thumb.setPointerCapture(event.pointerId);
        event.preventDefault();
    });

    thumb.addEventListener('pointermove', function (event) {
        if (!thumb.hasPointerCapture(event.pointerId)) {
            return;
        }

        setScrollFromPointer(event.clientY, false);
    });

    bar.addEventListener('pointerdown', function (event) {
        if (event.target === thumb) {
            return;
        }

        setScrollFromPointer(event.clientY, true);
    });

    main.addEventListener('scroll', updateScrollbar, { passive: true });
    window.addEventListener('scroll', updateScrollbar, { passive: true });
    window.addEventListener('resize', updateScrollbar, { passive: true });
    window.addEventListener('load', updateScrollbar, { once: true });
    new ResizeObserver(updateScrollbar).observe(main);
    new MutationObserver(updateScrollbar).observe(main, { childList: true, subtree: true });
    updateScrollbar();
}());
