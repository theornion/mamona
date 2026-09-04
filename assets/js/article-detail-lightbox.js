(function () {
    'use strict';

    var activeTrigger = null;
    var lightbox = null;
    var image = null;
    var caption = null;

    function closeLightbox() {
        if (!lightbox || lightbox.hidden) return;
        lightbox.hidden = true;
        document.body.classList.remove('article-detail-lightbox-open');
        if (activeTrigger) activeTrigger.focus();
        activeTrigger = null;
    }

    function ensureLightbox() {
        if (lightbox) return;
        lightbox = document.createElement('div');
        lightbox.className = 'article-detail-lightbox';
        lightbox.hidden = true;
        lightbox.setAttribute('role', 'dialog');
        lightbox.setAttribute('aria-modal', 'true');
        lightbox.setAttribute('aria-label', 'Powiększony widok ilustracji');
        lightbox.innerHTML = '<div class="article-detail-lightbox__panel" role="document"><button class="article-detail-lightbox__close" type="button" aria-label="Zamknij powiększenie">×</button><img class="article-detail-lightbox__image" alt=""><p class="article-detail-lightbox__caption"></p></div>';
        document.body.appendChild(lightbox);
        image = lightbox.querySelector('.article-detail-lightbox__image');
        caption = lightbox.querySelector('.article-detail-lightbox__caption');
        lightbox.querySelector('.article-detail-lightbox__close').addEventListener('click', closeLightbox);
        lightbox.addEventListener('click', function (event) {
            if (event.target === lightbox) closeLightbox();
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') closeLightbox();
        });
    }

    function openLightbox(trigger) {
        if (!trigger) return;
        ensureLightbox();
        activeTrigger = trigger;
        var thumbnail = trigger.querySelector('img');
        image.src = trigger.href;
        image.alt = thumbnail ? thumbnail.alt : '';
        caption.textContent = trigger.dataset.articleZoomCaption || image.alt;
        lightbox.hidden = false;
        document.body.classList.add('article-detail-lightbox-open');
        lightbox.querySelector('.article-detail-lightbox__close').focus();
    }

    function activateZoom(event, trigger) {
        event.preventDefault();
        event.stopPropagation();
        openLightbox(trigger);
    }

    function bindTrigger(trigger) {
        if (!trigger || trigger.dataset.articleZoomBound === 'true') return;
        trigger.dataset.articleZoomBound = 'true';
        trigger.addEventListener('click', function (event) {
            activateZoom(event, trigger);
        });
    }

    function bindTriggers() {
        document.querySelectorAll('[data-article-detail-zoom]').forEach(bindTrigger);
    }

    document.addEventListener('click', function (event) {
        if (!event.target || typeof event.target.closest !== 'function') return;
        var trigger = event.target.closest('[data-article-detail-zoom]');
        if (trigger) activateZoom(event, trigger);
    }, true);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindTriggers, { once: true });
    } else {
        bindTriggers();
    }
}());
