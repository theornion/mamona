<?php

declare(strict_types=1);

require_once __DIR__ . '/admin-auth.php';
require_once __DIR__ . '/admin-database.php';
require_once __DIR__ . '/admin-ui.php';

require_admin_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_valid_csrf()) {
        $_SESSION['topic_grouping_error'] = 'Sesja formularza wygasła. Odśwież stronę.';
        header('Location: admin-editorial-topics.php?error=1', true, 303);
        exit;
    }
    try {
        $action = trim((string) ($_POST['action'] ?? ''));
        if ($action === 'merge_topics') {
            manual_merge_topics(
                filter_input(INPUT_POST, 'source_topic_id', FILTER_VALIDATE_INT) ?: 0,
                filter_input(INPUT_POST, 'target_topic_id', FILTER_VALIDATE_INT) ?: 0
            );
        } elseif ($action === 'split_item') {
            manual_split_feed_item(filter_input(INPUT_POST, 'feed_item_id', FILTER_VALIDATE_INT) ?: 0);
        } elseif ($action === 'accept_candidate') {
            accept_topic_candidate(filter_input(INPUT_POST, 'candidate_id', FILTER_VALIDATE_INT) ?: 0);
        } elseif ($action === 'reject_candidate') {
            reject_topic_candidate(filter_input(INPUT_POST, 'candidate_id', FILTER_VALIDATE_INT) ?: 0);
        } elseif ($action === 'run_grouping') {
            $result = run_topic_grouping();
            $_SESSION['topic_grouping_result'] = sprintf(
                'Przetworzono: %d, połączono: %d, sugestie: %d, błędy: %d.',
                $result['processed'],
                $result['merged'],
                $result['suggested'],
                $result['failed']
            );
        } elseif ($action === 'run_scoring') {
            $result = run_topic_scoring();
            $_SESSION['topic_grouping_result'] = sprintf(
                'Oceniono: %d, wysokie ryzyko: %d, błędy: %d.',
                $result['processed'],
                $result['high_risk'],
                $result['failed']
            );
        } elseif ($action === 'cleanup_profile') {
            $confirmed = isset($_POST['confirm_cleanup'])
                && trim((string) ($_POST['cleanup_confirmation'] ?? '')) === 'ZMIEŃ PROFIL';
            $result = execute_editorial_profile_cleanup($confirmed, 'admin');
            $_SESSION['topic_grouping_result'] = sprintf(
                'Operacja #%d: odrzucono %d z %d zaplanowanych tematów.',
                $result['run_id'],
                $result['affected_count'],
                $result['preview_count']
            );
        } else {
            throw new InvalidArgumentException('Nieprawidłowa akcja.');
        }
        header('Location: admin-editorial-topics.php?saved=1', true, 303);
    } catch (Throwable $exception) {
        $_SESSION['topic_grouping_error'] = $exception->getMessage();
        header('Location: admin-editorial-topics.php?error=1', true, 303);
    }
    exit;
}

$topicFilter = trim((string) ($_GET['filter'] ?? 'active'));
if (!in_array($topicFilter, ['active', 'profile-rejected', 'all'], true)) {
    $topicFilter = 'active';
}
$topics = list_editorial_topics(200, $topicFilter);
$suggestions = list_suggested_topic_matches();
$cleanupPreview = editorial_profile_cleanup_preview();
$cleanupRuns = list_editorial_profile_cleanup_runs(5);
$error = (string) ($_SESSION['topic_grouping_error'] ?? '');
$resultMessage = (string) ($_SESSION['topic_grouping_result'] ?? '');
unset($_SESSION['topic_grouping_error'], $_SESSION['topic_grouping_result']);

admin_page_open('Grupowanie tematów', 'topics');
?>
<section class="post admin-card technical-sources-page">
    <header class="major admin-heading">
        <p class="admin-kicker">Deduplikacja</p>
        <h1>Tematy redakcyjne</h1>
        <p>Łącz kilka doniesień o tym samym wydarzeniu w jeden pomysł. Automatyczne dopasowanie wymaga wysokiej pewności i wspólnych słów opisujących zdarzenie — sama nazwa firmy nie wystarcza.</p>
    </header>

    <?php if ($error !== ''): ?><p class="admin-notice is-error" role="alert"><?php echo escape_html($error); ?></p><?php endif; ?>
    <?php if ($resultMessage !== ''): ?><p class="admin-notice is-success" role="status"><?php echo escape_html($resultMessage); ?></p><?php endif; ?>
    <?php if (isset($_GET['saved']) && $resultMessage === ''): ?><p class="admin-notice is-success" role="status">Zmiana grupowania została zapisana.</p><?php endif; ?>

    <form method="post" action="admin-editorial-topics.php">
        <input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>">
        <input type="hidden" name="action" value="run_grouping">
        <button type="submit">Przelicz grupowanie</button>
    </form>
    <form method="post" action="admin-editorial-topics.php">
        <input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>">
        <input type="hidden" name="action" value="run_scoring">
        <button type="submit">Przelicz punktację</button>
    </form>

    <section class="technical-source-card">
        <h2>Porządkowanie starego profilu</h2>
        <p>Operacja obejmuje wyłącznie nieprzetworzone pomysły należące w całości do wyłączonych źródeł deweloperskich. Nie usuwa źródeł, historii ani opublikowanych artykułów.</p>
        <p><strong>Materiały w podglądzie: <?php echo count($cleanupPreview); ?></strong></p>
        <?php if ($cleanupPreview !== []): ?>
            <details>
                <summary>Pokaż listę materiałów</summary>
                <ul>
                    <?php foreach ($cleanupPreview as $previewItem): ?>
                        <li>#<?php echo (int) $previewItem['post_id']; ?> — <?php echo escape_html((string) $previewItem['title']); ?> (<?php echo escape_html((string) $previewItem['source_names']); ?>)</li>
                    <?php endforeach; ?>
                </ul>
            </details>
            <form method="post" action="admin-editorial-topics.php">
                <input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>">
                <input type="hidden" name="action" value="cleanup_profile">
                <label><input type="checkbox" name="confirm_cleanup" value="1" required> Potwierdzam odrzucenie pokazanych nieprzetworzonych pomysłów.</label>
                <label for="cleanup-confirmation">Wpisz <code>ZMIEŃ PROFIL</code></label>
                <input id="cleanup-confirmation" name="cleanup_confirmation" required autocomplete="off">
                <button type="submit" class="admin-danger-action">Zmień profil kolejki</button>
            </form>
        <?php else: ?>
            <p class="admin-notice">Brak starych pomysłów oczekujących na uporządkowanie.</p>
        <?php endif; ?>
        <?php if ($cleanupRuns !== []): ?>
            <details>
                <summary>Historia operacji</summary>
                <ul>
                    <?php foreach ($cleanupRuns as $run): ?>
                        <li>#<?php echo (int) $run['id']; ?> — <?php echo escape_html((string) $run['reason']); ?>: <?php echo (int) $run['affected_count']; ?>/<?php echo (int) $run['preview_count']; ?>, <?php echo escape_html((string) $run['created_at']); ?></li>
                    <?php endforeach; ?>
                </ul>
            </details>
        <?php endif; ?>
    </section>

    <h2>Sugestie wymagające decyzji (<?php echo count($suggestions); ?>)</h2>
    <div class="technical-source-list">
        <?php if ($suggestions === []): ?><p class="admin-notice">Brak niepewnych dopasowań.</p><?php endif; ?>
        <?php foreach ($suggestions as $suggestion): ?>
            <article class="technical-source-card">
                <h3><?php echo escape_html((string) $suggestion['item_title']); ?></h3>
                <p>Proponowany temat: <strong><?php echo escape_html((string) $suggestion['topic_title']); ?></strong></p>
                <p>Pewność: <?php echo number_format((float) $suggestion['confidence'] * 100, 1, ',', ''); ?>% · <?php echo escape_html((string) $suggestion['explanation']); ?></p>
                <div class="editorial-action-row">
                    <form method="post" action="admin-editorial-topics.php">
                        <input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>">
                        <input type="hidden" name="action" value="accept_candidate">
                        <input type="hidden" name="candidate_id" value="<?php echo (int) $suggestion['id']; ?>">
                        <button type="submit">Połącz</button>
                    </form>
                    <form method="post" action="admin-editorial-topics.php">
                        <input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>">
                        <input type="hidden" name="action" value="reject_candidate">
                        <input type="hidden" name="candidate_id" value="<?php echo (int) $suggestion['id']; ?>">
                        <button type="submit" class="admin-danger-action">Odrzuć sugestię</button>
                    </form>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

    <nav class="editorial-filters" aria-label="Filtr tematów">
        <a href="admin-editorial-topics.php?filter=active"<?php echo $topicFilter === 'active' ? ' class="is-active" aria-current="page"' : ''; ?>>Aktywne</a>
        <a href="admin-editorial-topics.php?filter=profile-rejected"<?php echo $topicFilter === 'profile-rejected' ? ' class="is-active" aria-current="page"' : ''; ?>>Odrzucone przy zmianie profilu</a>
        <a href="admin-editorial-topics.php?filter=all"<?php echo $topicFilter === 'all' ? ' class="is-active" aria-current="page"' : ''; ?>>Wszystkie</a>
    </nav>

    <h2>Tematy (<?php echo count($topics); ?>)</h2>
    <div class="technical-source-list">
        <?php foreach ($topics as $topic): ?>
            <?php
            $items = topic_feed_items((int) $topic['id']);
            $breakdown = topic_score_breakdown($topic);
            ?>
            <article class="technical-source-card">
                <header>
                    <div>
                        <span class="editorial-status"><?php echo (int) $topic['item_count']; ?> wpisów / <?php echo (int) $topic['source_count']; ?> źródeł</span>
                        <h3>#<?php echo (int) $topic['id']; ?> — <?php echo escape_html((string) $topic['title']); ?></h3>
                    </div>
                    <a class="button admin-preview-action" href="admin-post-preview.php?post=<?php echo (int) $topic['primary_post_id']; ?>" target="_blank" rel="noopener">Podgląd</a>
                </header>
                <p>
                    <strong>Wynik: <?php echo $topic['score'] === null ? 'nie oceniono' : (int) $topic['score'] . '/100'; ?></strong>
                    · ryzyko: <?php echo escape_html((string) $topic['risk_level']); ?>
                    · automatyzacja: <?php echo (int) $topic['automatic_eligible'] === 1 ? 'dozwolona' : 'zablokowana'; ?>
                </p>
                <p><?php echo escape_html((string) $topic['source_names']); ?> · status: <?php echo escape_html((string) $topic['status']); ?><?php echo (int) $topic['grouping_locked'] === 1 ? ' · grupowanie ręcznie zablokowane' : ''; ?></p>
                <?php if ($breakdown !== []): ?>
                    <details>
                        <summary>Składowe punktacji i uzasadnienie</summary>
                        <dl class="editorial-meta">
                            <?php foreach ($breakdown as $component): ?>
                                <div>
                                    <dt><?php echo escape_html((string) $component['reason']); ?></dt>
                                    <dd>
                                        <?php echo (int) $component['points'] >= 0 ? '+' : ''; ?><?php echo (int) $component['points']; ?>
                                        <?php echo (int) $component['maximum'] > 0 ? ' / ' . (int) $component['maximum'] : ''; ?>
                                    </dd>
                                </div>
                            <?php endforeach; ?>
                        </dl>
                    </details>
                <?php endif; ?>
                <details>
                    <summary>Źródła i ręczne rozdzielanie</summary>
                    <ul>
                        <?php foreach ($items as $item): ?>
                            <li>
                                <a href="<?php echo escape_html((string) $item['source_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo escape_html((string) $item['title']); ?></a>
                                — <?php echo escape_html((string) $item['source_name']); ?>,
                                pewność <?php echo number_format((float) $item['confidence'] * 100, 1, ',', ''); ?>%
                                <?php if (count($items) > 1): ?>
                                    <form method="post" action="admin-editorial-topics.php">
                                        <input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>">
                                        <input type="hidden" name="action" value="split_item">
                                        <input type="hidden" name="feed_item_id" value="<?php echo (int) $item['id']; ?>">
                                        <button type="submit">Rozdziel</button>
                                    </form>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </details>
                <form method="post" action="admin-editorial-topics.php">
                    <input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>">
                    <input type="hidden" name="action" value="merge_topics">
                    <input type="hidden" name="source_topic_id" value="<?php echo (int) $topic['id']; ?>">
                    <label for="target-<?php echo (int) $topic['id']; ?>">Połącz cały temat z ID:</label>
                    <div class="editorial-action-row">
                        <input id="target-<?php echo (int) $topic['id']; ?>" type="number" name="target_topic_id" min="1" required>
                        <button type="submit">Połącz ręcznie</button>
                    </div>
                </form>
            </article>
        <?php endforeach; ?>
    </div>
</section>
<?php admin_page_close(); ?>
