<?php

declare(strict_types=1);

require_once __DIR__ . '/admin-auth.php';
require_once __DIR__ . '/admin-database.php';
require_once __DIR__ . '/admin-ui.php';

require_admin_login();

$job = content_studio_job_payload(content_studio_latest_job());
$topics = content_studio_topics();
$activeSources = content_studio_active_rss_count();
$lastSuccess = bueno_database()->query(
    'SELECT finished_at FROM editorial_ingestion_jobs
     WHERE status IN ("success", "partial_success") ORDER BY id DESC LIMIT 1'
)->fetchColumn();
$batches = list_generation_batches(5);

admin_page_open('Studio redakcyjne', 'studio');
?>
<section class="post admin-card content-studio" data-api="admin-content-studio-api.php" data-batch-limit="<?php echo CONTENT_STUDIO_BATCH_LIMIT; ?>">
    <header class="content-studio-hero">
        <div>
            <p class="admin-kicker">Codzienny workflow</p>
            <h1>Studio redakcyjne</h1>
            <p>Jedno miejsce do pobrania RSS, grupowania, punktacji i wyboru tematów.</p>
        </div>
        <form id="studio-start-form" method="post" action="admin-content-studio-api.php">
            <input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>">
            <input type="hidden" name="action" value="start">
            <button type="submit" class="content-studio-cta"<?php echo $job !== null && !$job['terminal'] ? ' disabled' : ''; ?>>Pobierz nowe dane z RSS</button>
        </form>
    </header>

    <div class="content-studio-summary" aria-label="Podsumowanie pobierania">
        <div><span>Ostatnie uruchomienie</span><strong id="studio-last-run"><?php echo escape_html((string) ($job['started_at'] ?? $job['created_at'] ?? '—')); ?></strong></div>
        <div><span>Ostatni sukces</span><strong id="studio-last-success"><?php echo escape_html((string) ($lastSuccess ?: '—')); ?></strong></div>
        <div><span>Aktywne źródła</span><strong id="studio-active-sources"><?php echo $activeSources; ?></strong></div>
        <div><span>Nowe / pominięte / błędy</span><strong id="studio-counts"><?php echo (int) ($job['created'] ?? 0); ?> / <?php echo (int) ($job['duplicates'] ?? 0); ?> / <?php echo (int) ($job['failed_sources'] ?? 0); ?></strong></div>
    </div>

    <section class="content-studio-progress" aria-labelledby="studio-progress-heading">
        <div class="content-studio-progress-heading">
            <h2 id="studio-progress-heading">Postęp procesu</h2>
            <strong id="studio-progress-count"><?php echo (int) ($job['processed'] ?? 0); ?>/<?php echo (int) ($job['total'] ?? ($activeSources + 2)); ?></strong>
        </div>
        <progress id="studio-progress" max="100" value="<?php echo (int) ($job['percent'] ?? 0); ?>"><?php echo (int) ($job['percent'] ?? 0); ?>%</progress>
        <p id="studio-live-status" class="content-studio-live" aria-live="polite" tabindex="-1">Gotowe do pracy.</p>
        <ol class="content-studio-stages" aria-label="Etapy procesu">
            <li data-stage="rss">RSS</li><li data-stage="grouping">Grupowanie</li><li data-stage="scoring">Punktacja</li>
        </ol>
        <div id="studio-source-errors" class="content-studio-errors"></div>
    </section>

    <section class="content-studio-topics" aria-labelledby="studio-topics-heading">
        <header>
            <div><p class="admin-kicker">Propozycje</p><h2 id="studio-topics-heading">Tematy według wyniku</h2></div>
            <p><strong id="studio-selected-count">0</strong>/<?php echo CONTENT_STUDIO_BATCH_LIMIT; ?> zaznaczonych</p>
        </header>
        <div class="content-studio-filters">
            <label for="studio-search">Szukaj</label>
            <input id="studio-search" type="search" placeholder="Tytuł lub kategoria">
            <label for="studio-risk">Ryzyko</label>
            <select id="studio-risk"><option value="">Wszystkie</option><option value="low">Niskie</option><option value="medium">Średnie</option><option value="high">Wysokie</option></select>
            <label for="studio-min-score">Wynik od</label>
            <select id="studio-min-score"><option value="0">0</option><option value="40">40</option><option value="60">60</option><option value="80">80</option></select>
        </div>
        <form class="content-studio-selection-bar" method="post" action="admin-content-studio-api.php" id="studio-generation-form">
            <input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>">
            <input type="hidden" name="action" value="prepare_generation">
            <span id="studio-generation-topic-ids"></span>
            <label><input id="studio-select-visible" type="checkbox"> Zaznacz wszystko widoczne</label>
            <button id="studio-generate" type="submit" disabled aria-describedby="studio-generation-note">Generuj zaznaczone</button>
            <span id="studio-generation-note">Research → walidacja → szkic → kontrola jakości → plan legalnych ilustracji. Bez automatycznej publikacji.</span>
        </form>
        <div id="studio-topic-list" class="content-studio-topic-list"></div>
        <p id="studio-empty" class="admin-notice" hidden>Brak tematów spełniających filtry.</p>
    </section>
    <section class="content-studio-progress" aria-labelledby="studio-batches-heading">
        <div class="content-studio-progress-heading"><h2 id="studio-batches-heading">Generowanie artykułów</h2><strong id="studio-batch-summary">—</strong></div>
        <div id="studio-batches" aria-live="polite"></div>
        <div class="content-studio-batch-links">
            <?php foreach ($batches as $batch): ?>
                <article class="content-studio-topic">
                    <div><h3>Batch #<?php echo (int) $batch['id']; ?></h3><p><?php echo escape_html((string) $batch['status']); ?> · <?php echo (int) $batch['completed_count']; ?>/<?php echo (int) $batch['item_count']; ?> zakończonych · <?php echo (int) $batch['ready_count']; ?> gotowych</p></div>
                    <a class="button" href="admin-proposals.php?batch=<?php echo (int) $batch['id']; ?>">Gotowe propozycje</a>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
    <script type="application/json" id="studio-initial-job"><?php echo json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG); ?></script>
    <script type="application/json" id="studio-initial-topics"><?php echo json_encode($topics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG); ?></script>
    <script type="application/json" id="studio-initial-batches"><?php echo json_encode($batches, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG); ?></script>
</section>
<?php admin_page_close(); ?>
