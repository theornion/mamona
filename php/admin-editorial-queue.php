<?php

declare(strict_types=1);

require_once __DIR__ . '/admin-auth.php';
require_once __DIR__ . '/admin-database.php';
require_once __DIR__ . '/admin-ui.php';

require_admin_login();

$labels = editorial_status_labels();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'change_status') {
    $returnStatus = trim((string) ($_POST['return_status'] ?? ''));
    $query = $returnStatus !== '' ? '?status=' . rawurlencode($returnStatus) : '';
    if (!admin_valid_csrf()) {
        header('Location: admin-editorial-queue.php' . $query . ($query === '' ? '?' : '&') . 'error=csrf', true, 303);
        exit;
    }

    $postId = filter_input(INPUT_POST, 'post_id', FILTER_VALIDATE_INT) ?: 0;
    $newStatus = trim((string) ($_POST['new_status'] ?? ''));
    $reason = trim((string) ($_POST['reason'] ?? ''));
    try {
        change_post_editorial_status($postId, $newStatus, $reason, 'admin');
        header('Location: admin-editorial-queue.php' . $query . ($query === '' ? '?' : '&') . 'changed=1', true, 303);
    } catch (Throwable $exception) {
        $_SESSION['editorial_queue_error'] = $exception->getMessage();
        header('Location: admin-editorial-queue.php' . $query . ($query === '' ? '?' : '&') . 'error=status', true, 303);
    }
    exit;
}

$statusFilter = trim((string) ($_GET['status'] ?? ''));
if ($statusFilter !== '' && !array_key_exists($statusFilter, $labels)) {
    $statusFilter = '';
}
$materials = list_editorial_queue($statusFilter !== '' ? $statusFilter : null);
$statusCounts = [];
foreach (array_keys($labels) as $status) {
    $statusCounts[$status] = count(list_editorial_queue($status));
}
$error = (string) ($_GET['error'] ?? '');
$errorMessage = (string) ($_SESSION['editorial_queue_error'] ?? '');
unset($_SESSION['editorial_queue_error']);

admin_page_open('Kolejka redakcyjna', 'editorial');
?>
<section class="post admin-card editorial-queue">
    <header class="major admin-heading">
        <p class="admin-kicker">Workflow</p>
        <h1>Kolejka redakcyjna</h1>
        <p>Materiały od pomysłu do publikacji. Zmiana statusu nie usuwa treści, a każda operacja jest zapisywana w historii.</p>
    </header>

    <?php if (isset($_GET['changed'])): ?>
        <p class="admin-notice is-success" role="status">Status materiału został zmieniony.</p>
    <?php elseif ($error !== ''): ?>
        <p class="admin-notice is-error" role="alert"><?php echo escape_html($errorMessage !== '' ? $errorMessage : ($error === 'csrf' ? 'Sesja formularza wygasła. Odśwież stronę.' : 'Nie udało się zmienić statusu.')); ?></p>
    <?php endif; ?>

    <nav class="editorial-filters" aria-label="Filtr statusu">
        <a href="admin-editorial-queue.php"<?php echo $statusFilter === '' ? ' class="is-active" aria-current="page"' : ''; ?>>Wszystkie <span><?php echo array_sum($statusCounts); ?></span></a>
        <?php foreach ($labels as $status => $label): ?>
            <a href="admin-editorial-queue.php?status=<?php echo rawurlencode($status); ?>"<?php echo $statusFilter === $status ? ' class="is-active" aria-current="page"' : ''; ?>>
                <?php echo escape_html($label); ?> <span><?php echo $statusCounts[$status]; ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="editorial-queue-list">
        <?php if ($materials === []): ?>
            <p class="admin-notice">Brak materiałów dla wybranego statusu.</p>
        <?php endif; ?>
        <?php foreach ($materials as $material): ?>
            <?php
            $status = (string) $material['status'];
            [, $detectedAt] = post_display_datetime((string) $material['created_at']);
            [, $scheduledAt] = post_display_datetime((string) ($material['scheduled_at'] ?? ''));
            $history = array_slice(list_post_status_history((int) $material['id']), 0, 5);
            ?>
            <article class="editorial-item" data-status="<?php echo escape_html($status); ?>">
                <header>
                    <div>
                        <span class="editorial-status status-<?php echo escape_html($status); ?>"><?php echo escape_html($labels[$status] ?? $status); ?></span>
                        <h2><?php echo escape_html((string) $material['title']); ?></h2>
                    </div>
                    <a class="button admin-preview-action" href="admin-post-preview.php?post=<?php echo (int) $material['id']; ?>" target="_blank" rel="noopener">Podgląd</a>
                </header>

                <dl class="editorial-meta">
                    <div><dt>Kategoria</dt><dd><?php echo escape_html((string) $material['category_title']); ?></dd></div>
                    <div><dt>Źródła</dt><dd><?php echo (int) $material['source_count']; ?><?php echo trim((string) $material['source_labels']) !== '' ? ' — ' . escape_html((string) $material['source_labels']) : ''; ?></dd></div>
                    <div><dt>Wykryto / utworzono</dt><dd><?php echo escape_html($detectedAt !== '' ? $detectedAt : 'Brak daty'); ?></dd></div>
                    <div><dt>Jakość</dt><dd><?php echo $material['quality_score'] !== null ? (int) $material['quality_score'] . '/100' : 'Nie oceniono'; ?></dd></div>
                    <div><dt>Pochodzenie</dt><dd><?php echo $material['editorial_origin'] === 'automatic' ? 'Automatyczne' : 'Ręczne'; ?></dd></div>
                    <div><dt>Publikacja planowana</dt><dd><?php echo escape_html($scheduledAt !== '' ? $scheduledAt : 'Nie zaplanowano'); ?></dd></div>
                </dl>

                <?php if (trim((string) $material['last_generation_error']) !== ''): ?>
                    <p class="editorial-error"><strong>Ostatni błąd automatyzacji:</strong> <?php echo escape_html((string) $material['last_generation_error']); ?></p>
                <?php endif; ?>
                <?php if ($status === 'rejected' && trim((string) $material['rejection_reason']) !== ''): ?>
                    <p class="editorial-rejection"><strong>Przyczyna odrzucenia:</strong> <?php echo escape_html((string) $material['rejection_reason']); ?></p>
                <?php endif; ?>

                <div class="editorial-actions">
                    <form method="post" action="admin-editorial-queue.php">
                        <input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>">
                        <input type="hidden" name="action" value="change_status">
                        <input type="hidden" name="post_id" value="<?php echo (int) $material['id']; ?>">
                        <input type="hidden" name="return_status" value="<?php echo escape_html($statusFilter); ?>">
                        <label for="status-<?php echo (int) $material['id']; ?>">Zmień status</label>
                        <div class="editorial-action-row">
                            <select id="status-<?php echo (int) $material['id']; ?>" name="new_status" required>
                                <?php foreach ($labels as $newStatus => $label): ?>
                                    <?php if ($newStatus === $status || $newStatus === 'rejected') continue; ?>
                                    <option value="<?php echo escape_html($newStatus); ?>"><?php echo escape_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit">Zapisz status</button>
                        </div>
                    </form>
                    <?php if ($status !== 'rejected'): ?>
                        <form method="post" action="admin-editorial-queue.php" class="editorial-reject-form">
                            <input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>">
                            <input type="hidden" name="action" value="change_status">
                            <input type="hidden" name="post_id" value="<?php echo (int) $material['id']; ?>">
                            <input type="hidden" name="new_status" value="rejected">
                            <input type="hidden" name="return_status" value="<?php echo escape_html($statusFilter); ?>">
                            <label for="reason-<?php echo (int) $material['id']; ?>">Przyczyna odrzucenia</label>
                            <textarea id="reason-<?php echo (int) $material['id']; ?>" name="reason" maxlength="1000" required></textarea>
                            <button type="submit" class="admin-danger-action">Odrzuć materiał</button>
                        </form>
                    <?php endif; ?>
                </div>

                <details class="editorial-history">
                    <summary>Ostatnie zmiany statusu (<?php echo count(list_post_status_history((int) $material['id'])); ?>)</summary>
                    <ol>
                        <?php foreach ($history as $entry): ?>
                            <li>
                                <strong><?php echo escape_html($labels[$entry['new_status']] ?? (string) $entry['new_status']); ?></strong>
                                <?php echo trim((string) $entry['reason']) !== '' ? ' — ' . escape_html((string) $entry['reason']) : ''; ?>
                                <small><?php echo escape_html((string) $entry['created_at']); ?>, <?php echo escape_html((string) $entry['actor']); ?></small>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                </details>
            </article>
        <?php endforeach; ?>
    </div>
</section>
<?php admin_page_close(); ?>
