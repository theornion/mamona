(function () {
    'use strict';

    const storageKey = 'mamona-effects-mode';
    const root = document.documentElement;
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    const coarsePointer = window.matchMedia('(hover: none), (pointer: coarse)');

    function readPreference() {
        try {
            const stored = window.localStorage.getItem(storageKey);
            return stored === 'full' || stored === 'reduced' ? stored : 'auto';
        } catch (error) {
            return 'auto';
        }
    }

    function automaticMode() {
        const lowCoreCount = Number.isFinite(navigator.hardwareConcurrency)
            && navigator.hardwareConcurrency > 0 && navigator.hardwareConcurrency <= 4;
        const lowMemory = Number.isFinite(navigator.deviceMemory)
            && navigator.deviceMemory > 0 && navigator.deviceMemory <= 4;
        return reducedMotion.matches || coarsePointer.matches || lowCoreCount || lowMemory
            ? 'reduced' : 'full';
    }

    function applyMode(preference) {
        const mode = preference === 'auto' ? automaticMode() : preference;
        root.dataset.effectsPreference = preference;
        root.dataset.effectsMode = mode;
        root.classList.toggle('reduced-effects', mode === 'reduced');
        document.dispatchEvent(new CustomEvent('mamona:effects-change', {
            detail: { mode: mode, preference: preference }
        }));
        return mode;
    }

    function savePreference(preference) {
        try { window.localStorage.setItem(storageKey, preference); } catch (error) {}
        applyMode(preference);
    }

    function shouldReduceEffects() {
        return root.dataset.effectsMode === 'reduced';
    }

    function updateButton(button) {
        const reduced = shouldReduceEffects();
        button.setAttribute('aria-pressed', reduced ? 'true' : 'false');
        button.textContent = reduced ? 'Pełne efekty' : 'Ogranicz efekty';
        button.title = reduced ? 'Włącz pełne tła i animacje' : 'Włącz lżejszy rendering i natywne przewijanie';
    }

    function mountToggle() {
        if (document.querySelector('[data-effects-toggle]')) return;
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'effects-toggle';
        button.dataset.effectsToggle = '';
        updateButton(button);
        button.addEventListener('click', function () {
            savePreference(shouldReduceEffects() ? 'full' : 'reduced');
            updateButton(button);
            window.location.reload();
        });
        document.body.appendChild(button);
    }

    const initialPreference = readPreference();
    applyMode(initialPreference);
    window.MamonaPerformance = {
        mode: function () { return root.dataset.effectsMode || automaticMode(); },
        preference: function () { return root.dataset.effectsPreference || initialPreference; },
        shouldReduceEffects: shouldReduceEffects,
        setPreference: savePreference,
        storageKey: storageKey
    };

    reducedMotion.addEventListener('change', function () {
        if (readPreference() === 'auto') applyMode('auto');
    });
    coarsePointer.addEventListener('change', function () {
        if (readPreference() === 'auto') applyMode('auto');
    });
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mountToggle, { once: true });
    else mountToggle();
}());
