(function () {
    'use strict';

    const params = new URLSearchParams(window.location.search);
    const duration = Math.max(1200, Number(params.get('duration')) || 3200);
    const settle = Math.max(100, Number(params.get('settle')) || 500);
    const status = document.querySelector('#qa-performance-status');
    const frameTimes = [];
    const longTasks = [];
    const layoutShifts = [];
    const events = [];
    let lcp = 0;
    let scrollEvents = 0;
    let backgroundMutations = 0;
    let previousFrame = 0;
    let collecting = false;

    function percentile(values, fraction) {
        if (!values.length) return 0;
        const sorted = values.slice().sort(function (a, b) { return a - b; });
        return sorted[Math.min(sorted.length - 1, Math.floor(sorted.length * fraction))];
    }

    function observe(type, sink) {
        try {
            const observer = new PerformanceObserver(function (list) {
                list.getEntries().forEach(sink);
            });
            observer.observe({ type: type, buffered: true });
            return observer;
        } catch (error) {
            return null;
        }
    }

    const observers = [
        observe('longtask', function (entry) { if (collecting) longTasks.push(entry.duration); }),
        observe('layout-shift', function (entry) { if (!entry.hadRecentInput) layoutShifts.push(entry.value); }),
        observe('largest-contentful-paint', function (entry) { lcp = entry.startTime; }),
        observe('event', function (entry) { if (entry.duration) events.push(entry.duration); })
    ].filter(Boolean);

    function frame(now) {
        if (!collecting) return;
        if (previousFrame) frameTimes.push(now - previousFrame);
        previousFrame = now;
        requestAnimationFrame(frame);
    }

    function getScroller() {
        const main = document.querySelector('#main');
        if (main && main.scrollHeight > main.clientHeight + 2) return main;
        return document.scrollingElement || document.documentElement;
    }

    function scrollRange(scroller) {
        return Math.max(0, scroller.scrollHeight - scroller.clientHeight);
    }

    function setScroll(scroller, value) {
        if (scroller === document.documentElement || scroller === document.body) {
            window.scrollTo(0, value);
        } else {
            scroller.scrollTop = value;
        }
    }

    function animate(scroller, from, to, milliseconds) {
        return new Promise(function (resolve) {
            const started = performance.now();
            function step(now) {
                const progress = Math.min(1, (now - started) / milliseconds);
                setScroll(scroller, from + (to - from) * progress);
                if (progress < 1) requestAnimationFrame(step);
                else resolve();
            }
            requestAnimationFrame(step);
        });
    }

    function bytes(entries, property) {
        return entries.reduce(function (sum, entry) { return sum + (Number(entry[property]) || 0); }, 0);
    }

    async function run() {
        if (status) status.textContent = 'QA measuring';
        const scroller = getScroller();
        const range = scrollRange(scroller);
        const bg = document.querySelector('#wrapper > .bg');
        let mutationObserver = null;

        if (bg && 'MutationObserver' in window) {
            mutationObserver = new MutationObserver(function (records) {
                backgroundMutations += records.length;
            });
            mutationObserver.observe(bg, { attributes: true, attributeFilter: ['style', 'class'] });
        }

        scroller.addEventListener('scroll', function () { if (collecting) scrollEvents += 1; }, { passive: true });
        setScroll(scroller, 0);
        await new Promise(function (resolve) { setTimeout(resolve, settle); });
        collecting = true;
        previousFrame = 0;
        requestAnimationFrame(frame);
        await animate(scroller, 0, range, duration);
        await animate(scroller, range, 0, duration);
        collecting = false;
        if (mutationObserver) mutationObserver.disconnect();
        observers.forEach(function (observer) { observer.disconnect(); });

        const resources = performance.getEntriesByType('resource');
        const cssAudit = Array.from(document.querySelectorAll('body *')).reduce(function (audit, element) {
            const style = getComputedStyle(element);
            if (style.backdropFilter && style.backdropFilter !== 'none') audit.backdropFilters += 1;
            if (style.filter && style.filter !== 'none') audit.filters += 1;
            if (style.position === 'fixed') audit.fixed += 1;
            if (style.position === 'sticky') audit.sticky += 1;
            if (style.willChange && style.willChange !== 'auto') audit.willChange += 1;
            return audit;
        }, { backdropFilters: 0, filters: 0, fixed: 0, sticky: 0, willChange: 0 });

        const result = {
            version: 1,
            target: params.get('target') || location.pathname,
            viewport: { width: innerWidth, height: innerHeight, dpr: devicePixelRatio },
            mode: document.documentElement.dataset.effectsMode || 'legacy',
            scroller: scroller.id || scroller.tagName.toLowerCase(),
            scrollRange: Math.round(range),
            samples: frameTimes.length,
            frameTimeMs: {
                median: Number(percentile(frameTimes, 0.5).toFixed(2)),
                p95: Number(percentile(frameTimes, 0.95).toFixed(2)),
                max: Number(Math.max(0, ...frameTimes).toFixed(2))
            },
            slowFrames: {
                over16_7ms: frameTimes.filter(function (value) { return value > 16.7; }).length,
                over33_3ms: frameTimes.filter(function (value) { return value > 33.3; }).length,
                over50ms: frameTimes.filter(function (value) { return value > 50; }).length
            },
            longTasks: {
                count: longTasks.length,
                totalMs: Number(longTasks.reduce(function (sum, value) { return sum + value; }, 0).toFixed(2)),
                maxMs: Number(Math.max(0, ...longTasks).toFixed(2))
            },
            lcpMs: Number(lcp.toFixed(2)),
            cls: Number(layoutShifts.reduce(function (sum, value) { return sum + value; }, 0).toFixed(4)),
            maxEventDurationMs: Number(Math.max(0, ...events).toFixed(2)),
            scrollEvents: scrollEvents,
            backgroundStyleMutations: backgroundMutations,
            resources: {
                count: resources.length,
                transferBytes: bytes(resources, 'transferSize'),
                decodedBytes: bytes(resources, 'decodedBodySize'),
                imageDecodedBytes: bytes(resources.filter(function (entry) { return entry.initiatorType === 'img'; }), 'decodedBodySize')
            },
            memory: performance.memory ? {
                usedJsHeapBytes: performance.memory.usedJSHeapSize,
                totalJsHeapBytes: performance.memory.totalJSHeapSize
            } : null,
            cssAudit: cssAudit,
            timestamp: new Date().toISOString()
        };

        window.__MAMONA_QA_PERFORMANCE_RESULT__ = result;
        if (status) {
            status.textContent = 'QA complete';
            status.dataset.result = JSON.stringify(result);
        }
        document.documentElement.dataset.qaPerformance = 'complete';
    }

    if (document.readyState === 'complete') setTimeout(run, settle);
    else window.addEventListener('load', function () { setTimeout(run, settle); }, { once: true });
}());
