<?php

declare(strict_types=1);

require_once __DIR__ . '/admin-auth.php';
require_once __DIR__ . '/admin-database.php';
require_once __DIR__ . '/admin-ui.php';

require_admin_login();

$filter = trim((string) ($_GET['status'] ?? 'attention'));
if (!in_array($filter, ['attention', 'active', 'waiting_review', 'failed', 'recent'], true)) $filter = 'attention';
$all = list_generation_process_history(100);
$activeStatuses = ['queued', 'research', 'draft', 'quality_check', 'images', 'rate_limited'];
$processes = array_values(array_filter($all, static function (array $item) use ($filter, $activeStatuses): bool {
    return match ($filter) {
        'active' => in_array((string) $item['status'], $activeStatuses, true),
        'waiting_review' => (string) $item['status'] === 'waiting_review',
        'failed' => (string) $item['status'] === 'failed',
        'recent' => in_array((string) $item['status'], ['ready', 'cancelled'], true),
        default => in_array((string) $item['status'], [...$activeStatuses, 'waiting_review', 'failed'], true),
    };
}));
$counts = [
    'active' => count(array_filter($all, static fn (array $item): bool => in_array((string) $item['status'], $activeStatuses, true))),
    'waiting_review' => count(array_filter($all, static fn (array $item): bool => (string) $item['status'] === 'waiting_review')),
    'failed' => count(array_filter($all, static fn (array $item): bool => (string) $item['status'] === 'failed')),
    'recent' => count(array_filter($all, static fn (array $item): bool => in_array((string) $item['status'], ['ready', 'cancelled'], true))),
];
$counts['attention'] = $counts['active'] + $counts['waiting_review'] + $counts['failed'];
$ingestionJobs = bueno_database()->query('SELECT * FROM editorial_ingestion_jobs ORDER BY id DESC LIMIT 10')->fetchAll();

admin_page_open('Procesy i historia', 'editorial');
?>
<section class="post admin-card process-history-page">
    <header class="major admin-heading"><p class="admin-kicker">Monitoring i diagnostyka</p><h1>Procesy / Historia</h1><p>Widok drugorzędny: aktywne, oczekujące, błędne i ostatnio zakończone operacje. Wybór tematów i uruchamianie workflow pozostają wyłącznie w <a href="admin-editorial-topics.php">Tematach</a>.</p></header>
    <nav class="editorial-filters" aria-label="Filtr procesów">
        <?php foreach (['attention' => 'Wymagające uwagi', 'active' => 'Aktywne', 'waiting_review' => 'Oczekujące review', 'failed' => 'Błędy', 'recent' => 'Ostatnio zakończone'] as $value => $label): ?><a href="admin-editorial-queue.php?status=<?php echo $value; ?>"<?php echo $filter === $value ? ' class="is-active" aria-current="page"' : ''; ?>><?php echo escape_html($label); ?> <span><?php echo $counts[$value]; ?></span></a><?php endforeach; ?>
    </nav>
    <div class="process-history-list">
        <?php if ($processes === []): ?><p class="admin-notice">Brak procesów w tym filtrze.</p><?php endif; ?>
        <?php foreach ($processes as $item): ?>
            <article class="technical-source-card process-history-item">
                <header><div><span class="editorial-status">Batch #<?php echo (int) $item['batch_id']; ?> · <?php echo escape_html((string) $item['batch_action']); ?></span><h2><?php echo escape_html((string) $item['topic_title']); ?></h2></div><strong><?php echo escape_html((string) $item['status']); ?></strong></header>
                <progress max="100" value="<?php echo (int) $item['progress_percent']; ?>"><?php echo (int) $item['progress_percent']; ?>%</progress>
                <p>Etap: <?php echo escape_html((string) $item['stage']); ?> · postęp <?php echo (int) $item['progress_percent']; ?>% · aktualizacja <?php echo escape_html((string) $item['updated_at']); ?> UTC.</p>
                <?php if ((string) ($item['wait_reason'] ?: $item['error_message']) !== ''): ?><p class="admin-notice<?php echo $item['status'] === 'failed' ? ' is-error' : ''; ?>"><?php echo escape_html((string) ($item['wait_reason'] ?: $item['error_message'])); ?></p><?php endif; ?>
                <p><a href="admin-editorial-topics.php#topic-<?php echo (int) $item['topic_id']; ?>">Temat</a><?php if ((int) ($item['draft_version_id'] ?? 0) > 0): ?> · <a href="admin-proposals.php?batch=<?php echo (int) $item['batch_id']; ?>&amp;draft=<?php echo (int) $item['draft_version_id']; ?>">Propozycja / review</a><?php endif; ?> · <a href="admin-generation.php">JSON, prompty i diagnostyka API</a></p>
            </article>
        <?php endforeach; ?>
    </div>
    <details class="process-ingestion-history"><summary>Historia procesów RSS (<?php echo count($ingestionJobs); ?> ostatnich)</summary><ul><?php foreach ($ingestionJobs as $job): ?><li>#<?php echo (int) $job['id']; ?> · <?php echo escape_html((string) $job['status']); ?> / <?php echo escape_html((string) $job['stage']); ?> · <?php echo escape_html((string) $job['created_at']); ?> UTC<?php if ((string) $job['error_message'] !== ''): ?> · <?php echo escape_html((string) $job['error_message']); ?><?php endif; ?></li><?php endforeach; ?></ul></details>
</section>
<?php admin_page_close(); ?>
