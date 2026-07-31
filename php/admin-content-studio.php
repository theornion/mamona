<?php

declare(strict_types=1);

require_once __DIR__ . '/admin-auth.php';
require_once __DIR__ . '/admin-database.php';
require_once __DIR__ . '/admin-ui.php';

require_admin_login();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!admin_valid_csrf()) {
        $_SESSION['content_studio_fallback_error'] = 'Sesja formularza wygasła. Odśwież Studio i spróbuj ponownie.';
    } else {
        $fallbackJob = null;
        try {
            $action = trim((string) ($_POST['action'] ?? ''));
            if ($action !== 'start') {
                throw new InvalidArgumentException('Ta akcja wymaga działającego interfejsu Studio. Odśwież stronę i spróbuj ponownie.');
            }
            $fallbackJob = content_studio_create_job('admin');
            content_studio_launch_worker((int) $fallbackJob['id']);
            $_SESSION['content_studio_fallback_notice'] = 'Pobieranie RSS zostało uruchomione. Postęp pojawi się poniżej.';
        } catch (Throwable $exception) {
            if (is_array($fallbackJob) && ($fallbackJob['status'] ?? '') === 'queued') {
                content_studio_update_job((int) $fallbackJob['id'], [
                    'status' => 'failed',
                    'stage' => 'failed',
                    'error_message' => mb_substr($exception->getMessage(), 0, 2000),
                    'finished_at' => gmdate('Y-m-d H:i:s'),
                ]);
            }
            $_SESSION['content_studio_fallback_error'] = $exception->getMessage();
        }
    }
    header('Location: admin-content-studio.php', true, 303);
    exit;
}

$job = content_studio_job_payload(content_studio_latest_job());
$activeSources = content_studio_active_rss_count();
$lastSuccess = bueno_database()->query(
    'SELECT finished_at FROM editorial_ingestion_jobs
     WHERE status IN ("success", "partial_success") ORDER BY id DESC LIMIT 1'
)->fetchColumn();
$fallbackNotice = (string) ($_SESSION['content_studio_fallback_notice'] ?? '');
$fallbackError = (string) ($_SESSION['content_studio_fallback_error'] ?? '');
unset($_SESSION['content_studio_fallback_notice'], $_SESSION['content_studio_fallback_error']);

admin_page_open('Studio redakcyjne', 'studio');
?>
<section class="post admin-card content-studio" data-api="admin-content-studio-api.php" data-batch-limit="<?php echo CONTENT_STUDIO_BATCH_LIMIT; ?>">
    <header class="content-studio-hero">
        <div>
            <p class="admin-kicker">Codzienny workflow</p>
            <h1>Studio redakcyjne</h1>
            <p>Pobieranie RSS, grupowanie i punktacja. Wybór oraz generowanie materiałów odbywa się w zakładce <a href="admin-editorial-topics.php">Tematy</a>.</p>
        </div>
        <form id="studio-start-form" method="post" action="admin-content-studio.php">
            <input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>">
            <input type="hidden" name="action" value="start">
            <button type="submit" class="content-studio-cta"<?php echo $job !== null && !$job['terminal'] ? ' disabled' : ''; ?>>Pobierz nowe dane z RSS</button>
        </form>
    </header>

    <?php if ($fallbackNotice !== ''): ?><p class="admin-notice is-success" role="status"><?php echo escape_html($fallbackNotice); ?></p><?php endif; ?>
    <?php if ($fallbackError !== ''): ?><p class="admin-notice is-error" role="alert"><?php echo escape_html($fallbackError); ?></p><?php endif; ?>

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

    <p class="content-studio-handoff"><a class="button" href="admin-editorial-topics.php">Przejdź do wyboru tematów i generowania</a> <a href="admin-editorial-queue.php">Procesy / Historia</a></p>
    <script type="application/json" id="studio-initial-job"><?php echo json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG); ?></script>
</section>
<?php admin_page_close(); ?>
