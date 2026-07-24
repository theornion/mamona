(function () {
    'use strict';

    const main = document.querySelector('#main');
    const nav = document.querySelector('#nav');
    let isLoading = false;

    if (!main || !nav) {
        return;
    }

    function isPublicPage(url) {
        return url.origin === window.location.origin
            && (url.pathname === '/'
                || /\/(?:index|pages\/[^/]+)\.html$/i.test(url.pathname));
    }

    function shouldHandleLink(link, url, event) {
        return link
            && event.button === 0
            && !event.defaultPrevented
            && !event.metaKey
            && !event.ctrlKey
            && !event.shiftKey
            && !event.altKey
            && !link.target
            && !link.hasAttribute('download')
            && isPublicPage(url)
            && !(url.pathname === window.location.pathname && url.hash !== '');
    }

    function getFeatureScripts(documentText, targetUrl) {
        const nextDocument = new DOMParser().parseFromString(documentText, 'text/html');
        const featureNames = ['news-feed.js', 'gallery-overview.js', 'cat-gallery.js', 'heading-scroll.js'];

        return Array.from(nextDocument.querySelectorAll('script[src]'))
            .map((script) => script.getAttribute('src'))
            .filter((src) => src && featureNames.some((name) => src.includes(name)))
            .map((src) => new URL(src, targetUrl).href)
            .filter((src, index, list) => list.indexOf(src) === index);
    }

    function runScript(src) {
        return new Promise((resolve) => {
            const script = document.createElement('script');
            script.src = src;
            script.async = false;
            script.onload = resolve;
            script.onerror = resolve;
            document.body.appendChild(script);
        });
    }

    function updateNavigation(nextDocument, targetUrl) {
        const currentLinks = nav.querySelector('.links');
        const nextLinks = nextDocument.querySelector('#nav .links');

        if (currentLinks && nextLinks) {
            const replacement = nextLinks.cloneNode(true);

            replacement.querySelectorAll('a[href]').forEach((link) => {
                if (link.classList.contains('nav-footer-jump')) {
                    link.href = '#footer';
                    return;
                }

                link.href = new URL(link.getAttribute('href'), targetUrl).href;
            });

            currentLinks.replaceWith(replacement);
        }
    }

    function normalizeContentLinks(container, targetUrl) {
        container.querySelectorAll('a[href]').forEach((link) => {
            link.href = new URL(link.getAttribute('href'), targetUrl).href;
        });
    }

    function updateCustomScrollbar() {
        const mask = document.querySelector('.main-custom-scrollbar-mask');

        if (mask) {
            mask.hidden = Boolean(main.querySelector(':scope > .post.menu-snap'));
        }
    }

    function updatePageMode(nextDocument) {
        const isSubpage = nextDocument.body.classList.contains('page-no-intro');

        document.body.classList.toggle('page-no-intro', isSubpage);
        document.body.classList.toggle(
            'static-header-start',
            isSubpage && nextDocument.body.classList.contains('static-header-start')
        );
    }

    function replacePage(documentText, targetUrl, pushHistory) {
        const nextDocument = new DOMParser().parseFromString(documentText, 'text/html');
        const nextMain = nextDocument.querySelector('#main');
        const featureScripts = getFeatureScripts(documentText, targetUrl);

        if (!nextMain) {
            window.location.assign(targetUrl.href);
            return Promise.resolve();
        }

        const replacement = nextMain.cloneNode(true);
        normalizeContentLinks(replacement, targetUrl);
        main.innerHTML = replacement.innerHTML;
        updatePageMode(nextDocument);
        updateNavigation(nextDocument, targetUrl);
        document.title = nextDocument.title || document.title;

        if (pushHistory) {
            window.history.pushState({}, '', targetUrl.href);
        }

        if (typeof window.buenoRefreshMainSnap === 'function') {
            window.buenoRefreshMainSnap();
        }

        updateCustomScrollbar();

        const header = document.querySelector('#header');
        if (header) {
            window.scrollTo(0, Math.max(0, header.getBoundingClientRect().top + window.scrollY));
        }

        return featureScripts.reduce((promise, src) => promise.then(() => runScript(src)), Promise.resolve());
    }

    function loadPage(url, pushHistory) {
        if (isLoading) {
            return;
        }

        isLoading = true;
        document.documentElement.classList.add('public-page-loading');

        window.fetch(url.href, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error('Nie udało się pobrać strony.');
                }

                return response.text();
            })
            .then((documentText) => replacePage(documentText, url, pushHistory))
            .catch(() => window.location.assign(url.href))
            .finally(() => {
                isLoading = false;
                document.documentElement.classList.remove('public-page-loading');
            });
    }

    document.addEventListener('click', (event) => {
        const link = event.target.closest('a');

        if (!link) {
            return;
        }

        const url = new URL(link.href, window.location.href);

        if (!shouldHandleLink(link, url, event)) {
            return;
        }

        event.preventDefault();
        loadPage(url, true);
    });

    window.addEventListener('popstate', () => {
        loadPage(new URL(window.location.href), false);
    });
}());
