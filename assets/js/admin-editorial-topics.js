(function () {
    'use strict';
    var toolbar = document.getElementById('topic-bulk-toolbar');
    if (!toolbar) return;

    var api = toolbar.dataset.api;
    var limit = Number(toolbar.dataset.limit || 10);
    var selected = new Set();
    var topics = JSON.parse((document.getElementById('topic-workflow-data') || {}).textContent || '[]');
    var cards = Array.from(document.querySelectorAll('.topic-control-card'));
    var search = document.getElementById('topic-search');
    var showReady = document.getElementById('topic-show-ready');
    var showAction = document.getElementById('topic-show-action');
    if (!showReady || !showAction || !window.topicFilterState) return;
    var selectVisible = document.getElementById('topic-select-visible');
    var selectTop = document.getElementById('topic-select-top');
    var clearSelection = document.getElementById('topic-clear-selection');
    var selectedCount = document.getElementById('topic-selected-count');
    var visibleCount = document.getElementById('topic-visible-count');
    var scope = document.getElementById('topic-bulk-scope');
    var live = document.getElementById('topic-live');
    var hiddenIds = document.getElementById('topic-hidden-ids');
    var trashForm = document.getElementById('topic-trash-bulk-form');
    var trashHiddenIds = document.getElementById('topic-trash-hidden-ids');
    var trashSelected = document.getElementById('topic-trash-selected');
    var csrf = toolbar.querySelector('[name="csrf"]').value;
    var pollTimer = null;
    var countdownTimer = null;
    var serverOffset = 0;
    var networkFailures = 0;
    var lastAnnouncement = '';
    var activeStatuses = ['queued', 'research', 'draft', 'auto_repair', 'quality_check', 'images', 'rate_limited', 'auto_retry_scheduled'];
    var jobSignatures = new Map(topics.filter(function (topic) { return topic.job; }).map(function (topic) { return [topic.id, topic.job.status + '|' + topic.job.stage + '|' + topic.job.reason]; }));

    function topicById(id) { return topics.find(function (topic) { return topic.id === Number(id); }); }
    function visibleCards() { return cards.filter(function (card) { return !card.hidden; }); }
    function key() { return window.crypto && crypto.randomUUID ? crypto.randomUUID() : Date.now() + '-' + Math.random().toString(16).slice(2); }
    function announce(message) { if (message !== lastAnnouncement) { live.textContent = message; lastAnnouncement = message; } }
    function cardMessage(topicId, message, isError) {
        var card = document.querySelector('[data-topic-id="' + Number(topicId) + '"]');
        if (!card) return;
        var box = card.querySelector('.topic-card-message');
        if (!box) { box = document.createElement('p'); box.className = 'topic-card-message'; box.setAttribute('role', 'status'); box.setAttribute('aria-live', 'polite'); card.querySelector('.topic-card-actions').before(box); }
        box.textContent = message; box.hidden = !message; box.classList.toggle('is-error', Boolean(isError));
    }
    function cardMessages(ids, message, isError) { ids.forEach(function (id) { cardMessage(id, message, isError); }); }
    function hasActiveJobs() { return topics.some(function (topic) { return topic.job && activeStatuses.includes(topic.job.status); }); }
    function secondsUntil(job) { var canonical = job && (job.next_retry_at || job.available_at); return canonical ? Math.max(0, Math.ceil((Date.parse(canonical) - (Date.now() + serverOffset)) / 1000)) : null; }
    function jobPresentation(job) {
        var remaining = secondsUntil(job);
        var labels = { queued: 'W kolejce', research: 'Ponowny research', draft: 'Aktywne przetwarzanie', auto_repair: 'Automatyczna poprawka', quality_check: 'Aktywne przetwarzanie', images: 'Aktywne przetwarzanie', rate_limited: 'Limit API', auto_retry_scheduled: 'Automatyczne wznowienie zaplanowane', ready_for_preview: 'Gotowe do podglądu', ready_with_notes: 'Gotowe z notatkami', auto_rejected: 'Automatycznie odrzucony', waiting_review: 'Wymaga decyzji', manual_review: 'Wymaga decyzji', failed: job.retryable ? 'Błąd ponawialny' : 'Błąd trwały', completed: 'Ukończono', ready: 'Gotowe', cancelled: 'Anulowano' };
        labels.paused_by_operator = 'Wstrzymany — uruchom ręcznie';
        var reconciledLimit = job.action === 'generate_all' && job.outcome === 'auto_repair_limit' && job.status === 'waiting_review';
        if (reconciledLimit) labels.waiting_review = 'Wznawiam automatyczne przygotowanie';
        var recoveringSource = job.reason === 'Ponawiam weryfikację źródła.';
        var repairMessage = job.repair_scope === 'titles' ? ['Niepoparte: ' + ((job.repair.unsupported_elements || []).join(', ') || job.reason), 'Tekst artykułu został zachowany.', job.repair.new_title ? 'Nowy tytuł: ' + job.repair.new_title : 'Naprawa tytułu oczekuje na próbę.'].join(' ') : '';
        var budgetMessage = job.status === 'auto_repair' ? 'Ulepszanie ' + Number(job.gemini_calls_used || 0) + '/' + Number(job.gemini_call_budget || 15) + ' · strategia: ' + (job.outcome || 'repair_router') + '.' : '';
        var action = reconciledLimit ? 'Wznawiam router naprawczy i bezpieczny kompozytor.' : repairMessage || ((job.status === 'rate_limited' || job.status === 'auto_retry_scheduled') ? (remaining > 0 ? 'Automatyczne wznowienie za ' + remaining + ' s.' : 'Wznawianie automatyczne…') : job.status === 'queued' ? 'Zadanie uruchomi się automatycznie po zwolnieniu workera.' : (job.status === 'waiting_review' || job.status === 'manual_review') ? (recoveringSource ? '' : 'Otwórz propozycję i podejmij decyzję redakcyjną.') : job.status === 'failed' ? (job.retryable ? 'Spróbuj ponownie przyciskiem poniżej.' : 'Sprawdź szczegóły; ten błąd wymaga poprawy danych lub konfiguracji.') : '');
        if (job.status === 'paused_by_operator') action = 'Kliknij „Wygeneruj całość” przy wybranym temacie.';
        var reason = reconciledLimit ? 'Poprzedni limit korekt został wycofany.' : job.reason;
        return { label: labels[job.status] || job.status, message: [budgetMessage, reason, action].filter(Boolean).join(' ') };
    }

    function filterCards() {
        var phrase = search.value.trim().toLocaleLowerCase('pl');
        cards.forEach(function (card) {
            var stateMatch = window.topicFilterState.matches(card.dataset.queueState || 'work', showReady.checked, showAction.checked);
            card.hidden = !stateMatch || (phrase && !card.dataset.search.includes(phrase));
        });
        visibleCount.textContent = String(visibleCards().length);
        if (toolbar.dataset.filter === 'active') {
            var queueCounts = window.topicFilterState.counts(topics.map(function (topic) { return topic.queue_state || 'work'; }));
            document.querySelectorAll('[data-nav-queue-count]').forEach(function (counter) {
                counter.textContent = String(queueCounts[counter.dataset.navQueueCount] || 0);
            });
        }
        updateSelection();
    }

    function updateSelection() {
        var visible = visibleCards().filter(function (card) { return card.dataset.selectable === '1'; });
        cards.forEach(function (card) {
            var checkbox = card.querySelector('.topic-checkbox');
            var isSelected = selected.has(Number(card.dataset.topicId));
            checkbox.checked = isSelected;
            card.classList.toggle('is-selected', isSelected);
            card.dataset.selected = isSelected ? 'true' : 'false';
        });
        selectedCount.textContent = String(selected.size);
        toolbar.querySelectorAll('.topic-action-toolbar button').forEach(function (button) { button.disabled = selected.size === 0; });
        hiddenIds.replaceChildren.apply(hiddenIds, Array.from(selected).map(function (id) {
            var input = document.createElement('input'); input.type = 'hidden'; input.name = 'topic_ids[]'; input.value = String(id); return input;
        }));
        trashHiddenIds.replaceChildren.apply(trashHiddenIds, Array.from(selected).map(function (id) {
            var input = document.createElement('input'); input.type = 'hidden'; input.name = 'topic_ids[]'; input.value = String(id); return input;
        }));
        trashSelected.disabled = selected.size === 0;
        var checkedVisible = visible.filter(function (card) { return selected.has(Number(card.dataset.topicId)); }).length;
        var hiddenSelected = selected.size - checkedVisible;
        selectVisible.checked = visible.length > 0 && checkedVisible === visible.length && visible.length <= limit;
        selectVisible.indeterminate = checkedVisible > 0 && checkedVisible < visible.length;
        scope.textContent = selected.size ? 'Zaznaczono ' + selected.size + ' tematów' + (hiddenSelected ? ', w tym ' + hiddenSelected + ' ukrytych przez bieżący filtr' : '') + '; akcja obejmie dokładnie ten wybór i jawnie zaraportuje pominięcia.' : 'Wybierz tematy. Bezpieczny limit partii: ' + limit + '.';
    }

    function selectCard(event) {
        var checkbox = event.currentTarget;
        var id = Number(checkbox.value);
        if (checkbox.checked && selected.size >= limit) { checkbox.checked = false; announce('Limit partii to ' + limit + ' tematów.', true); return; }
        checkbox.checked ? selected.add(id) : selected.delete(id);
        updateSelection();
    }

    function workflowLabel(topic) {
        function mark(step, version) { return step.done ? (version ? 'v' + version + ' ✓' : '✓') : '—'; }
        return ['Research ' + mark(topic.workflow.research), 'Szkic ' + mark(topic.workflow.draft, topic.workflow.draft.version), 'QC ' + mark(topic.workflow.quality), 'Grafiki ' + mark(topic.workflow.images), 'Gotowe ' + (topic.workflow.ready ? '✓' : '—')];
    }

    function applyTopicState(topic) {
        var card = document.querySelector('[data-topic-id="' + topic.id + '"]');
        if (!card) return;
        card.dataset.ready = topic.workflow.ready ? '1' : '0';
        card.dataset.requiresAction = topic.requires_action ? '1' : '0';
        card.dataset.queueState = topic.queue_state || 'work';
        card.dataset.selectable = topic.selectable ? '1' : '0';
        card.classList.toggle('is-ready', topic.workflow.ready);
        var checkbox = card.querySelector('.topic-checkbox'); checkbox.disabled = !topic.selectable;
        var badge = card.querySelector('.topic-ready-badge');
        if (topic.workflow.ready && !badge) { badge = document.createElement('span'); badge.className = 'topic-ready-badge'; badge.textContent = 'Gotowe'; card.querySelector('.topic-card-selector').appendChild(badge); }
        if (!topic.workflow.ready && badge) badge.remove();
        var labels = workflowLabel(topic);
        card.querySelectorAll('.topic-workflow-path li').forEach(function (item, index) { item.textContent = labels[index]; item.classList.toggle('is-done', labels[index].includes('✓')); });
        card.querySelectorAll('.topic-card-actions button').forEach(function (button) {
            var state = topic.actions[button.value]; button.disabled = !state.enabled; button.title = state.enabled ? button.textContent : state.reason;
        });
        var jobBox = card.querySelector('.topic-job-status');
        if (topic.job && !jobBox) {
            jobBox = document.createElement('div'); jobBox.className = 'topic-job-status';
            jobBox.innerHTML = '<strong></strong><progress max="100"></progress><span></span>';
            card.querySelector('.topic-card-actions').before(jobBox);
        }
        if (topic.job && jobBox) {
            var presentation = jobPresentation(topic.job);
            jobBox.querySelector('strong').textContent = presentation.label + ' · ' + topic.job.stage + ' · ' + topic.job.progress + '%';
            jobBox.querySelector('progress').value = topic.job.progress;
            jobBox.querySelector('span').textContent = presentation.message;
            jobBox.classList.toggle('is-error', topic.job.status === 'failed');
            var retryButton = jobBox.querySelector('.topic-retry');
            if (topic.job.technical_error && topic.job.technical_error !== topic.job.reason && !jobBox.querySelector('details')) { const details = document.createElement('details'); const summary = document.createElement('summary'); summary.textContent = 'Szczegóły techniczne'; const code = document.createElement('code'); code.textContent = topic.job.technical_error; details.append(summary, code); jobBox.appendChild(details); }
            if (topic.job.retryable && !retryButton) { retryButton = document.createElement('button'); retryButton.type = 'button'; retryButton.className = 'topic-retry'; jobBox.appendChild(retryButton); }
            if (retryButton) { retryButton.textContent = topic.job.repair_scope === 'titles' ? 'Popraw tytuł' : 'Ponów etap'; retryButton.dataset.retryItem = String(topic.job.id); retryButton.hidden = !topic.job.retryable; }
            var resumeButton = jobBox.querySelector('.topic-resume-legacy');
            if (topic.can_resume_legacy && !resumeButton) {
                resumeButton = document.createElement('button'); resumeButton.type = 'button';
                resumeButton.className = 'topic-resume-legacy'; resumeButton.textContent = 'Wznów nowym algorytmem';
                resumeButton.addEventListener('click', function () { run('generate_all', [Number(topic.id)], [resumeButton]); });
                jobBox.appendChild(resumeButton);
            }
            if (resumeButton) resumeButton.hidden = !topic.can_resume_legacy;
        }
        var proposal = card.querySelector('.topic-proposal-link');
        if (topic.proposal_url && !proposal) { proposal = document.createElement('a'); proposal.className = 'topic-proposal-link'; proposal.textContent = 'Otwórz gotową propozycję →'; card.querySelector('.topic-card-actions').after(proposal); }
        if (proposal) { proposal.hidden = !topic.proposal_url; if (topic.proposal_url) proposal.href = topic.proposal_url; }
        if (!topic.selectable) selected.delete(topic.id);
    }

    async function refresh() {
        if (document.hidden) { schedulePoll(); return; }
        try {
            var response = await fetch(api + '?action=status&filter=' + encodeURIComponent(toolbar.dataset.filter), { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' });
            var payload = await response.json();
            if (!response.ok) throw new Error(payload.error || 'Nie udało się odświeżyć tematów.');
            if (payload.server_time) serverOffset = Date.parse(payload.server_time) - Date.now();
            if (typeof payload.automatic_dispatch_paused === 'boolean') toolbar.dataset.dispatchPaused = payload.automatic_dispatch_paused ? '1' : '0';
            networkFailures = 0; topics = payload.topics || topics;
            var changes = [];
            topics.forEach(function (topic) {
                if (!topic.job) return;
                var signature = topic.job.status + '|' + topic.job.stage + '|' + topic.job.reason;
                if (jobSignatures.has(topic.id) && jobSignatures.get(topic.id) !== signature) changes.push({ id: topic.id, message: jobPresentation(topic.job).label + '. ' + (topic.job.reason || ''), error: topic.job.status === 'failed' });
                jobSignatures.set(topic.id, signature);
            });
            topics.forEach(applyTopicState); filterCards();
            changes.forEach(function (change) { cardMessage(change.id, change.message, change.error); });
            schedulePoll();
        } catch (error) { networkFailures++; var affected = topics.filter(function (topic) { return topic.job && activeStatuses.includes(topic.job.status); }).map(function (topic) { return topic.id; }); cardMessages(affected, 'Utracono synchronizację. Ponawiam automatycznie; stan tego tematu może być nieaktualny.', true); schedulePoll(); }
    }

    function schedulePoll() { window.clearTimeout(pollTimer); var delay = hasActiveJobs() ? 3000 : 15000; if (networkFailures) delay = Math.min(30000, 2000 * Math.pow(2, Math.min(networkFailures, 4))); pollTimer = window.setTimeout(refresh, delay); }
    function updateCountdowns() { if (document.hidden) return; topics.filter(function (topic) { return topic.job && (topic.job.status === 'rate_limited' || topic.job.status === 'auto_retry_scheduled'); }).forEach(applyTopicState); }

    async function run(action, ids, buttons) {
        if (!ids.length) return;
        buttons.forEach(function (button) { button.disabled = true; button.setAttribute('aria-busy', 'true'); });
        var data = new FormData(); data.set('csrf', csrf); data.set('action', 'run_workflow'); data.set('workflow_action', action); data.set('request_key', key());
        ids.forEach(function (id) { data.append('topic_ids[]', String(id)); });
        try {
            var response = await fetch(api, { method: 'POST', body: data, credentials: 'same-origin' });
            var payload = await response.json();
            if (!response.ok) throw new Error(payload.error || 'Nie udało się uruchomić operacji.');
            var started = payload.batch ? payload.batch.item_count : 0;
            var skipped = payload.skipped || [];
            var report = skipped.map(function (item) { return '#' + item.topic_id + ': ' + item.reason; }).join(' | ');
            ids.forEach(function (id) { var skippedItem = skipped.find(function (item) { return Number(item.topic_id) === Number(id); }); cardMessage(id, skippedItem ? skippedItem.reason : 'Uruchomiono wybrany etap. Aktualny stan pojawi się przy pasku postępu.', Boolean(skippedItem)); });
            selected.clear(); updateSelection(); schedulePoll();
        } catch (error) { cardMessages(ids, error.message, true); }
        finally { buttons.forEach(function (button) { button.removeAttribute('aria-busy'); }); updateSelection(); }
    }

    cards.forEach(function (card) {
        card.querySelector('.topic-checkbox').addEventListener('change', selectCard);
        var form = card.querySelector('.topic-card-actions');
        form.addEventListener('submit', function (event) {
            event.preventDefault(); var submitter = event.submitter; if (!submitter) return;
            run(submitter.value, [Number(card.dataset.topicId)], Array.from(form.querySelectorAll('button')));
        });
    });
    toolbar.addEventListener('submit', function (event) { event.preventDefault(); if (event.submitter) run(event.submitter.value, Array.from(selected), Array.from(toolbar.querySelectorAll('.topic-action-toolbar button'))); });
    trashForm.addEventListener('submit', function (event) {
        if (!selected.size || !window.confirm('Przenieść ' + selected.size + ' zaznaczonych tematów do Kosza na 10 dni?')) event.preventDefault();
    });
    selectVisible.addEventListener('change', function () {
        var visible = visibleCards().filter(function (card) { return card.dataset.selectable === '1'; });
        if (selectVisible.checked) visible.slice(0, limit).forEach(function (card) { selected.add(Number(card.dataset.topicId)); });
        else visible.forEach(function (card) { selected.delete(Number(card.dataset.topicId)); });
        if (visible.length > limit) announce('Zaznaczono pierwsze ' + limit + ' widocznych tematów.', false);
        updateSelection();
    });
    selectTop.addEventListener('click', function () {
        selected.clear(); visibleCards().filter(function (card) { return card.dataset.selectable === '1'; }).slice(0, limit).forEach(function (card) { selected.add(Number(card.dataset.topicId)); });
        announce('Zaznaczono top ' + selected.size + ' kwalifikujących się tematów.', false); updateSelection();
    });
    clearSelection.addEventListener('click', function () {
        selected.clear(); announce('Wyczyszczono wybór.', false); updateSelection();
    });
    function persistVisibility() {
        var url = new URL(window.location.href);
        showReady.checked ? url.searchParams.set('show_ready', '1') : url.searchParams.delete('show_ready');
        showAction.checked ? url.searchParams.set('show_action', '1') : url.searchParams.delete('show_action');
        window.history.replaceState(null, '', url.pathname + (url.searchParams.toString() ? '?' + url.searchParams.toString() : '') + url.hash);
        document.querySelectorAll('[name="return_show_ready"]').forEach(function (input) { input.value = showReady.checked ? '1' : '0'; });
        document.querySelectorAll('[name="return_show_action"]').forEach(function (input) { input.value = showAction.checked ? '1' : '0'; });
        filterCards();
    }
    search.addEventListener('input', filterCards);
    showReady.addEventListener('change', persistVisibility);
    showAction.addEventListener('change', persistVisibility);
    document.addEventListener('click', async function (event) {
        var retry = event.target.closest('[data-retry-item]'); if (!retry) return;
        retry.disabled = true; var data = new FormData(); data.set('csrf', csrf); data.set('action', 'retry_item'); data.set('item_id', retry.dataset.retryItem);
        var retryCard = retry.closest('.topic-control-card'); var retryTopicId = Number(retryCard.dataset.topicId);
        try { var response = await fetch(api, { method: 'POST', body: data, credentials: 'same-origin' }); var payload = await response.json(); if (!response.ok) throw new Error(payload.error); cardMessage(retryTopicId, 'Ponowiono etap. Aktualny stan pojawi się przy pasku postępu.', false); schedulePoll(); } catch (error) { cardMessage(retryTopicId, error.message, true); retry.disabled = false; }
    });
    document.querySelectorAll('.topic-trash-form').forEach(function (form) {
        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            var card = form.closest('.topic-control-card');
            var title = card.querySelector('h3').textContent.trim();
            if (!window.confirm('Przenieść temat „' + title + '” do Kosza na 10 dni?')) return;
            var button = form.querySelector('.topic-trash'); button.disabled = true;
            var data = new FormData(form);
            try {
                var response = await fetch(api, { method: 'POST', body: data, credentials: 'same-origin' });
                var payload = await response.json(); if (!response.ok) throw new Error(payload.error || 'Nie udało się przenieść tematu do Kosza.');
                selected.delete(Number(card.dataset.topicId)); card.remove(); cards = cards.filter(function (item) { return item !== card; });
                filterCards();
            } catch (error) { cardMessage(Number(card.dataset.topicId), error.message, true); button.disabled = false; }
        });
    });
    filterCards();
    schedulePoll();
    countdownTimer = window.setInterval(updateCountdowns, 1000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) { window.clearTimeout(pollTimer); refresh(); } });
    window.addEventListener('online', function () { networkFailures = 0; refresh(); });
}());
