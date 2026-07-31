(function () {
    'use strict';

    const root = document.querySelector('.content-studio');
    if (!root) return;

    const api = root.dataset.api;
    const limit = Number(root.dataset.batchLimit || 10);
    const selected = new Set();
    let topics = JSON.parse(document.getElementById('studio-initial-topics').textContent || '[]');
    let job = JSON.parse(document.getElementById('studio-initial-job').textContent || 'null');
    let batches = JSON.parse(document.getElementById('studio-initial-batches').textContent || '[]');
    let pollTimer = null;
    let terminalJobId = job && job.terminal ? job.id : null;

    const startForm = document.getElementById('studio-start-form');
    const startButton = startForm.querySelector('button');
    const list = document.getElementById('studio-topic-list');
    const empty = document.getElementById('studio-empty');
    const search = document.getElementById('studio-search');
    const risk = document.getElementById('studio-risk');
    const minScore = document.getElementById('studio-min-score');
    const selectVisible = document.getElementById('studio-select-visible');
    const selectedCount = document.getElementById('studio-selected-count');
    const live = document.getElementById('studio-live-status');
    const generationForm = document.getElementById('studio-generation-form');
    const generateButton = document.getElementById('studio-generate');
    const batchesRoot = document.getElementById('studio-batches');
    document.getElementById('studio-generation-note').textContent = 'Research → walidacja → szkic → kontrola jakości → plan legalnych ilustracji. Bez automatycznej publikacji.';

    function text(element, value) { element.textContent = value; }
    function formatDate(value) { return value ? String(value) : '—'; }
    function riskLabel(value) { return ({ low: 'niskie', medium: 'średnie', high: 'wysokie' })[value] || value || '—'; }
    function freshness(hours) {
        if (hours < 1) return 'przed chwilą';
        if (hours < 24) return hours + ' godz. temu';
        return Math.floor(hours / 24) + ' dni temu';
    }
    function visibleTopics() {
        const phrase = search.value.trim().toLocaleLowerCase('pl');
        const threshold = Number(minScore.value || 0);
        return topics.filter(function (topic) {
            const haystack = (topic.title + ' ' + topic.category).toLocaleLowerCase('pl');
            return (!phrase || haystack.includes(phrase))
                && (!risk.value || topic.risk === risk.value)
                && (topic.score === null ? threshold === 0 : topic.score >= threshold);
        });
    }
    function updateSelectionState() {
        text(selectedCount, selected.size);
        generateButton.disabled = selected.size < 1 || selected.size > limit;
        const hiddenIds = document.getElementById('studio-generation-topic-ids');
        hiddenIds.replaceChildren.apply(hiddenIds, Array.from(selected).map(function (id) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'topic_ids[]';
            input.value = String(id);
            return input;
        }));
        const visible = visibleTopics();
        const checkedVisible = visible.filter(function (topic) { return selected.has(topic.id); }).length;
        selectVisible.checked = visible.length > 0 && checkedVisible === visible.length;
        selectVisible.indeterminate = checkedVisible > 0 && checkedVisible < visible.length;
    }
    function topicCard(topic) {
        const article = document.createElement('article');
        article.className = 'content-studio-topic';
        article.dataset.topicId = String(topic.id);

        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.id = 'studio-topic-' + topic.id;
        checkbox.checked = selected.has(topic.id);
        checkbox.setAttribute('aria-label', 'Wybierz temat: ' + topic.title);
        checkbox.addEventListener('change', function () {
            if (checkbox.checked && selected.size >= limit) {
                checkbox.checked = false;
                live.textContent = 'Możesz wybrać maksymalnie ' + limit + ' tematów.';
                live.focus();
                return;
            }
            checkbox.checked ? selected.add(topic.id) : selected.delete(topic.id);
            updateSelectionState();
        });

        const body = document.createElement('div');
        const title = document.createElement('h3');
        const label = document.createElement('label');
        label.htmlFor = checkbox.id;
        label.textContent = topic.title;
        title.appendChild(label);
        const meta = document.createElement('p');
        meta.className = 'content-studio-topic-meta';
        meta.textContent = topic.category + ' · ' + freshness(topic.freshness_hours)
            + ' · ' + topic.source_count + ' źr. · ryzyko: ' + riskLabel(topic.risk)
            + ' · grafika: ' + topic.visual_potential + '/6';
        const links = document.createElement('p');
        const preview = document.createElement('a');
        preview.href = 'admin-post-preview.php?post=' + topic.post_id;
        preview.target = '_blank';
        preview.rel = 'noopener';
        preview.textContent = 'Podgląd';
        const details = document.createElement('a');
        details.href = 'admin-editorial-topics.php#topic-' + topic.id;
        details.textContent = 'Źródła i szczegóły';
        links.append(preview, document.createTextNode(' · '), details);
        body.append(title, meta, links);

        const score = document.createElement('strong');
        score.className = 'content-studio-score';
        score.textContent = topic.score === null ? '—' : topic.score + '/100';
        article.append(checkbox, body, score);
        return article;
    }
    function renderTopics() {
        const visible = visibleTopics();
        list.replaceChildren.apply(list, visible.map(topicCard));
        empty.hidden = visible.length !== 0;
        updateSelectionState();
    }
    function batchStatusLabel(value) {
        return ({ queued: 'oczekuje', research: 'research', waiting_review: 'wymaga decyzji', draft: 'szkic', quality_check: 'kontrola jakości', images: 'ilustracje', ready: 'gotowe', failed: 'błąd', cancelled: 'anulowane', rate_limited: 'oczekuje na limit API' })[value] || value;
    }
    function actionButton(label, action, idName, id) {
        const button = document.createElement('button');
        button.type = 'button'; button.textContent = label;
        button.addEventListener('click', async function () {
            button.disabled = true;
            const data = new FormData();
            data.set('csrf', startForm.elements.csrf.value); data.set('action', action); data.set(idName, String(id));
            try {
                const response = await fetch(api, { method: 'POST', body: data, credentials: 'same-origin' });
                const payload = await response.json();
                if (!response.ok) throw new Error(payload.error || 'Nie udało się wykonać akcji.');
                batches = payload.batches || batches; renderBatches(); schedulePoll();
            } catch (error) { text(live, error.message); button.disabled = false; }
        });
        return button;
    }
    function renderBatches() {
        batchesRoot.replaceChildren();
        const latest = batches[0];
        text(document.getElementById('studio-batch-summary'), latest ? latest.ready_count + '/' + latest.item_count + ' gotowych · ' + latest.completed_count + ' zakończonych' : 'brak batchy');
        batches.forEach(function (batch) {
            const section = document.createElement('section'); section.className = 'technical-source-card';
            const heading = document.createElement('h3'); heading.textContent = 'Batch #' + batch.id + ' · ' + batch.completed_count + '/' + batch.item_count; section.appendChild(heading);
            batch.items.forEach(function (item) {
                const row = document.createElement('p'); const title = document.createElement('strong'); title.textContent = item.topic_title + ': ';
                row.append(title, document.createTextNode(batchStatusLabel(item.status) + ' · ' + item.progress_percent + '%'));
                if (item.wait_reason) row.append(document.createTextNode(' · ' + item.wait_reason));
                if (item.error_message) row.append(document.createTextNode(' · ' + item.error_message));
                if (item.review_url && (item.status === 'ready' || item.status === 'waiting_review')) { const link = document.createElement('a'); link.href = item.review_url; link.textContent = ' Przejdź do propozycji'; row.appendChild(link); }
                if (item.status === 'queued' || item.status === 'rate_limited') row.appendChild(actionButton('Anuluj', 'cancel_batch_item', 'item_id', item.id));
                if (item.status === 'failed' || item.status === 'rate_limited' || item.status === 'cancelled') row.appendChild(actionButton('Ponów', 'retry_batch_item', 'item_id', item.id));
                section.appendChild(row);
            });
            if (batch.items.some(function (item) { return item.status === 'failed' || item.status === 'rate_limited' || item.status === 'cancelled'; })) section.appendChild(actionButton('Ponów nieukończone', 'retry_batch', 'batch_id', batch.id));
            if (batch.ready_count > 0) { const link = document.createElement('a'); link.href = 'admin-proposals.php?batch=' + batch.id; link.textContent = 'Przejdź do gotowych propozycji'; section.appendChild(link); }
            batchesRoot.appendChild(section);
        });
    }
    function stageMessage(current) {
        if (!current) return 'Gotowe do pracy.';
        if (current.status === 'queued') return 'Zadanie czeka na uruchomienie workera.';
        if (current.status === 'running' && current.stage === 'rss') {
            return current.current_source ? 'Pobieranie: ' + current.current_source : 'Zakończono pobieranie źródła.';
        }
        if (current.status === 'running' && current.stage === 'grouping') return 'Grupowanie powiązanych doniesień…';
        if (current.status === 'running' && current.stage === 'scoring') return 'Punktacja propozycji…';
        if (current.status === 'partial_success') return 'Proces zakończony. Część źródeł zwróciła błędy.';
        if (current.status === 'success') return 'Proces RSS → grupowanie → punktacja zakończony.';
        if (current.status === 'interrupted') return current.error || 'Proces przerwany; możesz go ponowić.';
        if (current.status === 'failed') return 'Proces nie został ukończony: ' + (current.error || 'nieznany błąd');
        return 'Gotowe do pracy.';
    }
    function updateJob(current) {
        job = current;
        const active = current && !current.terminal;
        startButton.disabled = Boolean(active);
        text(document.getElementById('studio-last-run'), formatDate(current && (current.started_at || current.created_at)));
        text(document.getElementById('studio-counts'), current ? current.created + ' / ' + current.duplicates + ' / ' + current.failed_sources : '0 / 0 / 0');
        const progress = document.getElementById('studio-progress');
        progress.value = current ? current.percent : 0;
        text(document.getElementById('studio-progress-count'), current ? current.processed + '/' + current.total : '0/0');
        text(live, stageMessage(current));

        document.querySelectorAll('.content-studio-stages li').forEach(function (item) {
            const order = { rss: 0, grouping: 1, scoring: 2, completed: 3 };
            const currentOrder = current ? (order[current.stage] ?? -1) : -1;
            const itemOrder = order[item.dataset.stage];
            item.classList.toggle('is-active', active && itemOrder === currentOrder);
            item.classList.toggle('is-done', currentOrder > itemOrder || (current && current.stage === 'completed'));
        });

        const errors = document.getElementById('studio-source-errors');
        errors.replaceChildren();
        (current ? current.source_results : []).filter(function (result) { return result.error; }).forEach(function (result) {
            const note = document.createElement('p');
            note.className = 'admin-notice is-error';
            note.textContent = result.name + ': ' + result.error;
            errors.appendChild(note);
        });

        if (current && current.terminal && terminalJobId !== current.id) {
            terminalJobId = current.id;
            live.focus();
        }
    }
    async function refresh() {
        try {
            const response = await fetch(api + '?action=status', { credentials: 'same-origin', cache: 'no-store' });
            const data = await response.json();
            if (!response.ok) throw new Error(data.error || 'Nie udało się odświeżyć stanu.');
            topics = data.topics;
            batches = data.batches || [];
            updateJob(data.job);
            renderTopics();
            renderBatches();
            text(document.getElementById('studio-active-sources'), data.active_sources);
            text(document.getElementById('studio-last-success'), formatDate(data.last_success_at));
            if ((data.job && !data.job.terminal) || batches.some(function (batch) { return !batch.terminal; })) schedulePoll();
        } catch (error) {
            text(live, error.message);
            schedulePoll();
        }
    }
    function schedulePoll() {
        window.clearTimeout(pollTimer);
        pollTimer = window.setTimeout(refresh, 1500);
    }

    startForm.addEventListener('submit', async function (event) {
        event.preventDefault();
        startButton.disabled = true;
        text(live, 'Uruchamianie zadania…');
        try {
            const response = await fetch(api, { method: 'POST', body: new FormData(startForm), credentials: 'same-origin' });
            const data = await response.json();
            if (!response.ok) throw new Error(data.error || 'Nie udało się uruchomić zadania.');
            terminalJobId = null;
            updateJob(data.job);
            schedulePoll();
        } catch (error) {
            startButton.disabled = false;
            text(live, error.message);
            live.focus();
        }
    });
    generationForm.addEventListener('submit', async function (event) {
        event.preventDefault();
        if (selected.size < 1 || selected.size > limit) return;
        if (!window.confirm('Uruchomić generowanie dla ' + selected.size + ' tematów? Etapy: research, walidacja, szkic, kontrola jakości i plan legalnych ilustracji.')) return;
        generateButton.disabled = true;
        const data = new FormData(generationForm);
        data.set('request_key', window.crypto && crypto.randomUUID ? crypto.randomUUID() : String(Date.now()) + '-' + String(Math.random()).slice(2));
        try {
            const response = await fetch(api, { method: 'POST', body: data, credentials: 'same-origin' });
            const payload = await response.json();
            if (!response.ok) throw new Error(payload.error || 'Nie udało się uruchomić batcha.');
            batches.unshift(payload.batch); selected.clear(); renderTopics(); renderBatches(); schedulePoll();
        } catch (error) { text(live, error.message); updateSelectionState(); }
    });
    [search, risk, minScore].forEach(function (control) { control.addEventListener('input', renderTopics); });
    selectVisible.addEventListener('change', function () {
        const visible = visibleTopics();
        if (selectVisible.checked) {
            for (const topic of visible) {
                if (selected.size >= limit) break;
                selected.add(topic.id);
            }
            if (visible.length > limit) {
                text(live, 'Zaznaczono pierwsze ' + limit + ' widocznych tematów — to maksymalny rozmiar partii.');
            }
        } else {
            visible.forEach(function (topic) { selected.delete(topic.id); });
        }
        renderTopics();
    });

    renderTopics();
    updateJob(job);
    renderBatches();
    if ((job && !job.terminal) || batches.some(function (batch) { return !batch.terminal; })) schedulePoll();
}());
