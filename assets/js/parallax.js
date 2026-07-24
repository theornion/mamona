(function () {
    const bg = document.querySelector('#wrapper > .bg');
    const main = document.querySelector('#main');
    if (!bg) return;

    const intro = document.querySelector('#intro');
    const header = document.querySelector('#header');
    const menu = document.querySelector('#main > .post.menu-snap');
    const usesStaticIntro = document.body.classList.contains('page-no-intro');

    function clearHiddenIntroStyles() {
        if (!intro) return;

        intro.querySelectorAll('.image.logo, p, .intro-brand').forEach(function (element) {
            element.removeAttribute('style');
        });
    }

    function getStaticHeaderScrollTop() {
        if (!intro || !header) return 0;

        // Header jest wizualnie wyciągnięty w górę ujemnym marginesem, więc
        // offsetTop na telefonie może zwrócić 0. Różnica wysokości intro i
        // headera jest stabilnym punktem, w którym mały header trafia na górę.
        return Math.max(0, intro.offsetHeight - header.offsetHeight);
    }

    /*
        Podstrona startuje przy headerze i nie odtwarza animacji intro, ale po
        przewinięciu do góry pełne logo, slogan i duże Bueno mają być widoczne.
        Wygenerowany HTML ukrywa je inline do czasu animacji, dlatego statyczny
        wariant musi zdjąć ten stan od razu.
    */
    if (intro && usesStaticIntro) {
        clearHiddenIntroStyles();

        // Ten skrypt jest ładowany na końcu dokumentu, więc możemy ustawić
        // docelową pozycję zanim przeglądarka pokaże gotowy widok podstrony.
        // Dzięki temu nie ma krótkiego błysku pełnego intro po odświeżeniu.
        const initialHeaderTop = getStaticHeaderScrollTop();
        intro.classList.add('hidden');
        document.documentElement.scrollTop = initialHeaderTop;
        document.body.scrollTop = initialHeaderTop;
        window.scrollTo(0, initialHeaderTop);
        window.requestAnimationFrame(function () {
            document.body.classList.remove('static-header-start');
        });
    }

    function hasInternalMainScroll() {
        return !!main && main.scrollHeight > main.clientHeight + 2;
    }

    /*
        index.html ma intro.
        strona menu nie ma intro, ale ma .menu-snap.
        inne podstrony bez intro też lecą tym samym torem.
    */
    const isIndexPage = !!intro && !!header && !menu && !usesStaticIntro;

    const speed = 0.10;
    const bgWidth = 1680;
    const bgHeight = 2307;
    const mobileReserve = 180;
    const sharedMobileMenuScreens = 10.5;
    const mobileMenuGuyAnchorX = 0.254;
    const mobileLadyAnchorX = 0.738;

    /*
        WAŻNE:
        Jeśli w main_snap.js masz:
        const introSnapOffset = 0;

        to tutaj też zostaw 0.

        Jeśli tam zmienisz np. na 40 albo -40,
        tutaj wpisz dokładnie tę samą wartość.
    */
    const introSnapOffset = 0;

    /*
        Dodatkowa ręczna korekta tylko dla index.html:
        dodatnia wartość = tło w index niżej
        ujemna wartość = tło w index wyżej

        Na start zostaw 0.
        Jeśli dalej będzie minimalny przeskok, spróbuj np. 20 albo -20.
    */
    const indexFineTune = 0;
    let backgroundLayoutKey = '';
    let mobileLayoutHeight = 0;
    let mobileLayoutWidth = 0;

    function markBackgroundReady() {
        if (!bg) return;

        let backgroundLoaded = false;
        let pageLoaded = document.readyState === 'complete';
        let readyQueued = false;
        const image = new Image();

        function revealWhenReady() {
            if (!backgroundLoaded || !pageLoaded || readyQueued) return;

            readyQueued = true;

            window.setTimeout(function () {
                document.body.classList.add('is-bg-ready');

                if (intro && !usesStaticIntro) {
                    window.setTimeout(function () {
                        clearHiddenIntroStyles();
                        document.body.classList.add('intro-ready');
                    }, 180);
                }
            }, 70);
        }

        image.onload = function () {
            backgroundLoaded = true;
            revealWhenReady();
        };

        const assetBase = document.body.dataset.assetBase ||
            (window.location.pathname.includes('/pages/') || window.location.pathname.includes('/php/') ? '../' : '');
        image.src = assetBase + 'images/bg6.webp';

        if (image.complete) {
            backgroundLoaded = true;
            revealWhenReady();
        }

        window.addEventListener('load', function () {
            pageLoaded = true;
            revealWhenReady();
        });
    }

    function getDocumentScrollRange() {
        const doc = document.documentElement;
        const viewportHeight = window.innerHeight || doc.clientHeight;

        return Math.max(0, doc.scrollHeight - viewportHeight);
    }

    function getMenuScrollRange() {
        if (!menu) return 0;

        return Math.max(0, menu.scrollHeight - menu.clientHeight);
    }

    function getMobileBackgroundLift(viewportHeight) {
        const viewportWidth = window.innerWidth || document.documentElement.clientWidth;

        if (viewportWidth > 980) return 0;

        return Math.round(Math.min(220, Math.max(130, viewportHeight * 0.22)));
    }

    function getLogoCenterX() {
        const logo = document.querySelector('#intro:not(.hidden) .image.logo, #header .logo');

        if (!logo) {
            return (window.innerWidth || document.documentElement.clientWidth) / 2;
        }

        const rect = logo.getBoundingClientRect();

        return rect.left + rect.width / 2;
    }

    function getMobileImageAnchorX() {
        return menu ? mobileMenuGuyAnchorX : mobileLadyAnchorX;
    }

    function getHeaderDocumentTop() {
        if (!header) return 0;

        return header.getBoundingClientRect().top + window.scrollY;
    }

    function getEffectiveScrollY(scrollY) {
        /*
            Fix dla index.html:

            Parallax nie liczy już scrolla od samego początku intro,
            tylko od momentu pozycji z widocznym nagłówkiem BUENO.

            Dzięki temu:
            index.html w pozycji headera ma takie samo tło jak
            strona menu i inne strony bez intro na ich pozycji headera.
        */
        if (isIndexPage) {
            const compensation = getHeaderDocumentTop() + introSnapOffset;
            let effectiveScrollY = Math.max(0, scrollY - compensation);

            if (hasInternalMainScroll() && main) {
                effectiveScrollY += main.scrollTop;
            }

            return effectiveScrollY;
        }

        let effectiveScrollY = scrollY;

        if (hasInternalMainScroll() && main) {
            effectiveScrollY += main.scrollTop;
        }

        if (menu) {
            effectiveScrollY += menu.scrollTop;
        }

        return effectiveScrollY;
    }

    function getMaxEffectiveScrollY() {
        const documentRange = getDocumentScrollRange();

        if (isIndexPage) {
            const compensation = getHeaderDocumentTop() + introSnapOffset;
            let effectiveRange = Math.max(0, documentRange - compensation);

            if (hasInternalMainScroll() && main) {
                effectiveRange += Math.max(0, main.scrollHeight - main.clientHeight);
            }

            return effectiveRange;
        }

        let effectiveRange = documentRange;

        if (hasInternalMainScroll() && main) {
            effectiveRange += Math.max(0, main.scrollHeight - main.clientHeight);
        }

        if (menu) {
            effectiveRange += getMenuScrollRange();
        }

        return effectiveRange;
    }

    function getMaxShift(layoutViewportHeight) {
        const viewportHeight = layoutViewportHeight || window.innerHeight || document.documentElement.clientHeight;
        const sharedMenuScrollY = viewportHeight * Math.max(0, sharedMobileMenuScreens - 1);

        if ((window.innerWidth || document.documentElement.clientWidth) <= 980) {
            return Math.ceil(sharedMenuScrollY * speed + Math.max(0, indexFineTune));
        }

        const effectiveScrollY = Math.max(getMaxEffectiveScrollY(), sharedMenuScrollY);

        return Math.ceil(effectiveScrollY * speed + Math.max(0, indexFineTune));
    }

    function updateBackgroundSize() {
        if (!bg) return;

        const viewportWidth = window.innerWidth || document.documentElement.clientWidth;
        const viewportHeight = window.innerHeight || document.documentElement.clientHeight;
        const isMobileLayout = viewportWidth <= 980;
        const isMobileLandscape = isMobileLayout && window.matchMedia('(orientation: landscape)').matches;
        const screenHeight = window.screen && window.screen.height ? window.screen.height : viewportHeight;
        const layoutViewportHeight = isMobileLayout
            ? Math.max(viewportHeight, screenHeight)
            : viewportHeight;
        const mobileLift = getMobileBackgroundLift(layoutViewportHeight);
        const nextLayoutKey = isMobileLayout
            ? `${viewportWidth}:${window.matchMedia('(orientation: portrait)').matches ? 'portrait' : 'landscape'}:mobile`
            : `${viewportWidth}x${viewportHeight}:desktop`;

        if (nextLayoutKey === backgroundLayoutKey) return;

        backgroundLayoutKey = nextLayoutKey;
        mobileLayoutWidth = isMobileLayout ? viewportWidth : 0;
        mobileLayoutHeight = isMobileLayout ? layoutViewportHeight : 0;

        bg.style.setProperty('--bg-responsive-y', mobileLift ? `-${mobileLift}px` : '0px');

        if (viewportWidth > 980) {
            bg.style.setProperty('--bg-responsive-x', '50%');
            bg.style.setProperty('--bg-responsive-width', '100vw');
            return;
        }

        const requiredHeight = (mobileLayoutHeight || layoutViewportHeight) + mobileLift + getMaxShift(mobileLayoutHeight || layoutViewportHeight) + mobileReserve;
        const requiredWidth = Math.ceil(requiredHeight * (bgWidth / bgHeight));
        const responsiveWidth = Math.max(viewportWidth, requiredWidth);

        if (isMobileLandscape) {
            bg.style.setProperty('--bg-responsive-x', '50%');
            bg.style.setProperty('--bg-responsive-width', `${responsiveWidth}px`);
            return;
        }

        const mobileX = Math.round(getLogoCenterX() - responsiveWidth * getMobileImageAnchorX());

        bg.style.setProperty('--bg-responsive-x', `${mobileX}px`);
        bg.style.setProperty('--bg-responsive-width', `${responsiveWidth}px`);
    }

    function updateParallax() {
        const scrollY = window.scrollY || window.pageYOffset;

        // Podstrony rozpoczynają się przy headerze. Pełne intro pozostaje nad
        // nim dostępne po przewinięciu w górę, ale nie może nakładać się na
        // małe logo headera w normalnej pozycji strony.
        if (usesStaticIntro && intro && header) {
            const introHideThreshold = Math.max(0, getStaticHeaderScrollTop() - 8);
            const isAboveHeader = scrollY < introHideThreshold;
            document.body.classList.toggle('intro-above-header', isAboveHeader);
            intro.classList.toggle('hidden', !isAboveHeader);
        }

        updateBackgroundSize();

        const effectiveScrollY = getEffectiveScrollY(scrollY);

        const rawY =
            effectiveScrollY * -speed +
            (isIndexPage ? indexFineTune : 0);

        if (bg) {
            bg.style.setProperty('--bg-parallax-y', `${rawY}px`);
        }

    }

    // Coalesce window/main scroll events into one frame. During the Kontakt
    // jump both containers move together; updating the background after only
    // one of them has moved causes a visible one-frame flicker.
    let parallaxFrame = null;
    function requestParallaxUpdate() {
        if (parallaxFrame) return;

        parallaxFrame = window.requestAnimationFrame(function () {
            parallaxFrame = null;
            updateParallax();
        });
    }

    markBackgroundReady();
    updateParallax();

    window.addEventListener('scroll', requestParallaxUpdate, { passive: true });
    window.addEventListener('resize', requestParallaxUpdate);
    window.addEventListener('load', requestParallaxUpdate);

    if (document.body.classList.contains('page-no-intro') && header) {
        window.addEventListener('load', function () {
            window.requestAnimationFrame(function () {
                window.scrollTo(0, getStaticHeaderScrollTop());
            requestParallaxUpdate();
            });
        });
    }

    if (main) {
        main.addEventListener('scroll', requestParallaxUpdate, { passive: true });
    }

    if (menu) {
        menu.addEventListener('scroll', requestParallaxUpdate, { passive: true });

        if ('ResizeObserver' in window) {
            new ResizeObserver(updateParallax).observe(menu);
        }

        if ('MutationObserver' in window) {
            new MutationObserver(updateParallax).observe(menu, { childList: true, subtree: true });
        }
    }

    if (main && main !== menu) {
        if ('ResizeObserver' in window) {
            new ResizeObserver(updateParallax).observe(main);
        }

        if ('MutationObserver' in window) {
            new MutationObserver(updateParallax).observe(main, { childList: true, subtree: true });
        }
    }
})();
