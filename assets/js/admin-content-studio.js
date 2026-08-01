(function () {
    'use strict';

    const root = document.querySelector('.content-studio');
    if (!root) return;
    const api = root.dataset.api;
    let job = JSON.parse(document.getElementById('studio-initial-job').textContent || 'null');
    let pollTimer = null;
    let terminalJobId = job && job.terminal ? job.id : null;
    const startForm = document.getElementById('studio-start-form');
    const startButton = startForm.querySelector('button');
    const live = document.getElementById('studio-live-status');

    function text(element, value) { if (element) element.textContent = value; }
    function stageMessage(current) {
        if (!current) return 'Gotowe do pracy.';
        if (current.status === 'queued') return 'Zadanie czeka na uruchomienie workera.';
        if (current.status === 'running' && current.stage === 'rss') return current.current_source ? 'Pobieranie: ' + current.current_source : 'Pobieranie RSS…';
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
        text(document.getElementById('studio-last-run'), current && (current.started_at || current.created_at) || '—');
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
        (current ? current.source_results : []).forEach(function (result) {
            const note = document.createElement('p');
            note.className = 'admin-notice ' + (result.status === 'failed' ? 'is-error' : 'is-success');
            const attempt = 'próba ' + (result.attempts || 1);
            const timing = Math.round((result.duration_ms || 0) / 100) / 10 + ' s, ' + (result.bytes || 0) + ' B';
            const failure = [result.advice, result.error].filter(Boolean).filter(function (value, index, values) { return values.indexOf(value) === index; }).join(' Szczegóły: ');
            const outcome = result.status === 'not_modified' ? 'bez nowych danych' : (result.status === 'succeeded' ? 'sukces' : (failure || 'błąd'));
            const retry = result.retry_in_ms ? ' · ponowienie za ' + Math.ceil(result.retry_in_ms / 1000) + ' s' : '';
            note.textContent = result.name + ': ' + outcome + ' · ' + attempt + ' · ' + timing + retry;
            errors.appendChild(note);
        });
        if (current && current.terminal && terminalJobId !== current.id) { terminalJobId = current.id; live.focus(); }
    }
    function schedulePoll() { window.clearTimeout(pollTimer); pollTimer = window.setTimeout(refresh, 1500); }
    async function refresh() {
        try {
            const response = await fetch(api + '?action=status', { credentials: 'same-origin', cache: 'no-store' });
            const data = await response.json();
            if (!response.ok) throw new Error(data.error || 'Nie udało się odświeżyć stanu.');
            updateJob(data.job);
            text(document.getElementById('studio-active-sources'), data.active_sources);
            text(document.getElementById('studio-last-success'), data.last_success_at || '—');
            if (data.job && !data.job.terminal) schedulePoll();
        } catch (error) { text(live, error.message); schedulePoll(); }
    }
    startForm.addEventListener('submit', async function (event) {
        event.preventDefault(); startButton.disabled = true; text(live, 'Uruchamianie zadania…');
        try {
            const response = await fetch(api, { method: 'POST', body: new FormData(startForm), credentials: 'same-origin' });
            const data = await response.json();
            if (!response.ok) throw new Error(data.error || 'Nie udało się uruchomić zadania.');
            terminalJobId = null; updateJob(data.job); schedulePoll();
        } catch (error) { startButton.disabled = false; text(live, error.message); live.focus(); }
    });
    updateJob(job);
    if (job && !job.terminal) schedulePoll();
}());
