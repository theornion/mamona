<?php

declare(strict_types=1);

require_once __DIR__ . '/admin-auth.php';
require_once __DIR__ . '/admin-database.php';
require_once __DIR__ . '/admin-ui.php';

require_admin_login();

function topic_filter_enabled(mixed $value): bool
{
    return in_array((string) $value, ['1', 'true', 'on'], true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $returnFilter = trim((string) ($_POST['return_filter'] ?? 'active'));
    if (!in_array($returnFilter, ['active', 'profile-rejected', 'all'], true)) $returnFilter = 'active';
    $returnTopicId = filter_input(INPUT_POST, 'return_topic_id', FILTER_VALIDATE_INT) ?: 0;
    $returnShowReady = topic_filter_enabled($_POST['return_show_ready'] ?? '0');
    $returnShowAction = topic_filter_enabled($_POST['return_show_action'] ?? '0');
    if ($returnTopicId > 0) $_SESSION['topic_message_topic_id'] = $returnTopicId;
    $returnQuery = ['filter' => $returnFilter];
    if ($returnShowReady) $returnQuery['show_ready'] = '1';
    if ($returnShowAction) $returnQuery['show_action'] = '1';
    $returnLocation = 'admin-editorial-topics.php?' . http_build_query($returnQuery);
    if ($returnTopicId > 0) $returnLocation .= '#topic-' . $returnTopicId;
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
        } elseif ($action === 'toggle_automatic_dispatch') {
            $pause = trim((string) ($_POST['dispatcher_state'] ?? 'paused')) === 'paused';
            $dispatch = generation_set_automatic_dispatch_paused($pause, 'admin', $pause);
            $_SESSION['topic_grouping_result'] = $pause
                ? sprintf('Automatyka wstrzymana. Zatrzymano %d elementów; ręczne „Wygeneruj całość” pozostaje dostępne.', (int) $dispatch['paused_items'])
                : 'Pauza automatyki została jawnie zdjęta. Wstrzymane tematy nie zostały uruchomione automatycznie.';
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
        } elseif ($action === 'run_workflow') {
            $workflowAction=trim((string)($_POST['workflow_action']??''));$rawTopicIds=$_POST['topic_ids']??null;
            if($workflowAction==='generate_all'&&is_array($rawTopicIds)&&count($rawTopicIds)===1){
                $legacyStatus=generation_workflow_statuses([(int)$rawTopicIds[0]])[0]??[];
                if(($legacyStatus['latest_job_status']??'')==='auto_rejected'&&($legacyStatus['latest_action']??'')==='generate_all'){
                    $resume=generation_batch_resume_legacy_item((int)$legacyStatus['latest_job_id'],'admin');generation_batch_launch_worker();
                    $_SESSION['topic_grouping_result']='Wznowiono temat nowym algorytmem od checkpointu: '.(string)$resume['checkpoint'].'.';
                    header('Location: '.$returnLocation,true,303);exit;
                }
            }
            $workflow = create_generation_workflow_batch(
                $rawTopicIds,
                $workflowAction,
                trim((string) ($_POST['request_key'] ?? '')) ?: null,
                'admin'
            );
            if (is_array($workflow['batch'])) generation_batch_launch_worker();
            $_SESSION['topic_grouping_result'] = sprintf(
                'Uruchomiono %d tematów; pominięto %d.',
                (int) ($workflow['batch']['item_count'] ?? 0),
                count($workflow['skipped'])
            );
        } elseif ($action === 'resume_legacy') {
            $resume=generation_batch_resume_legacy_item(filter_input(INPUT_POST,'item_id',FILTER_VALIDATE_INT)?:0,'admin');
            generation_batch_launch_worker();
            $_SESSION['topic_grouping_result']='Wznowiono temat nowym algorytmem od checkpointu: '.(string)$resume['checkpoint'].'.';
        } elseif ($action === 'trash_topic') {
            trash_editorial_topic(filter_input(INPUT_POST, 'topic_id', FILTER_VALIDATE_INT) ?: 0, 'admin', trim((string) ($_POST['reason'] ?? '')), 'topic_card');
            $_SESSION['topic_grouping_result'] = 'Temat przeniesiono do Kosza na 10 dni.';
        } elseif ($action === 'trash_selected') {
            $rawIds = $_POST['topic_ids'] ?? [];
            if (!is_array($rawIds) || $rawIds === []) throw new InvalidArgumentException('Zaznacz co najmniej jeden temat.');
            $ids = [];
            foreach ($rawIds as $rawId) {
                $id = filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($id === false) throw new InvalidArgumentException('Lista tematów zawiera nieprawidłowy identyfikator.');
                $ids[(int) $id] = (int) $id;
            }
            $moved = 0;
            $blocked = [];
            foreach ($ids as $id) {
                try { trash_editorial_topic($id, 'admin', '', 'topics_bulk'); $moved++; }
                catch (Throwable $exception) { $blocked[] = '#' . $id . ': ' . $exception->getMessage(); }
            }
            $_SESSION['topic_grouping_result'] = sprintf('Przeniesiono do Kosza: %d.', $moved);
            if ($blocked !== []) $_SESSION['topic_grouping_error'] = implode(' ', $blocked);
        } else {
            throw new InvalidArgumentException('Nieprawidłowa akcja.');
        }
        header('Location: ' . $returnLocation . (str_contains($returnLocation, '#') ? '' : '&saved=1'), true, 303);
    } catch (Throwable $exception) {
        $_SESSION['topic_grouping_error'] = $exception->getMessage();
        header('Location: ' . $returnLocation . (str_contains($returnLocation, '#') ? '' : '&error=1'), true, 303);
    }
    exit;
}

$topicFilter = trim((string) ($_GET['filter'] ?? 'active'));
if (!in_array($topicFilter, ['active', 'profile-rejected', 'all'], true)) {
    $topicFilter = 'active';
}
$showReady = topic_filter_enabled($_GET['show_ready'] ?? '0');
$showAction = topic_filter_enabled($_GET['show_action'] ?? '0');
$visibilityQuery = ($showReady ? '&amp;show_ready=1' : '') . ($showAction ? '&amp;show_action=1' : '');
$topics = list_editorial_topics(1000, $topicFilter);
$topicWorkflow = generation_topics_workflow_payload($topics);
$topicQueueCounts = generation_topic_queue_counts($topicWorkflow);
$automaticDispatchPaused = generation_automatic_dispatch_paused();
if ($topicFilter === 'active') $GLOBALS['adminTopicQueueCounts'] = $topicQueueCounts;
$visibleTopicCount = count(array_filter($topicWorkflow, static fn (array $topic): bool => generation_topic_queue_visible((string) ($topic['queue_state'] ?? 'work'), $showReady, $showAction)));
$suggestions = list_suggested_topic_matches();
$cleanupPreview = editorial_profile_cleanup_preview();
$cleanupRuns = list_editorial_profile_cleanup_runs(5);
$error = (string) ($_SESSION['topic_grouping_error'] ?? '');
$resultMessage = (string) ($_SESSION['topic_grouping_result'] ?? '');
$messageTopicId = (int) ($_SESSION['topic_message_topic_id'] ?? 0);
unset($_SESSION['topic_grouping_error'], $_SESSION['topic_grouping_result'], $_SESSION['topic_message_topic_id']);

admin_page_open('Grupowanie tematów', 'topics');
?>
<section class="post admin-card technical-sources-page">
    <header class="major admin-heading">
        <p class="admin-kicker">Deduplikacja</p>
        <h1>Tematy redakcyjne</h1>
        <p>Łącz kilka doniesień o tym samym wydarzeniu w jeden pomysł. Automatyczne dopasowanie wymaga wysokiej pewności i wspólnych słów opisujących zdarzenie — sama nazwa firmy nie wystarcza.</p>
    </header>

    <?php if ($error !== '' && $messageTopicId <= 0): ?><p class="admin-notice is-error" role="alert"><?php echo escape_html($error); ?></p><?php endif; ?>
    <?php if ($resultMessage !== '' && $messageTopicId <= 0): ?><p class="admin-notice is-success" role="status"><?php echo escape_html($resultMessage); ?></p><?php endif; ?>
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
        <a href="admin-editorial-topics.php?filter=active<?php echo $visibilityQuery; ?>"<?php echo $topicFilter === 'active' ? ' class="is-active" aria-current="page"' : ''; ?>>Aktywne</a>
        <a href="admin-editorial-topics.php?filter=profile-rejected<?php echo $visibilityQuery; ?>"<?php echo $topicFilter === 'profile-rejected' ? ' class="is-active" aria-current="page"' : ''; ?>>Odrzucone przy zmianie profilu</a>
        <a href="admin-editorial-topics.php?filter=all<?php echo $visibilityQuery; ?>"<?php echo $topicFilter === 'all' ? ' class="is-active" aria-current="page"' : ''; ?>>Wszystkie</a>
        <label for="topic-search">Szukaj</label><input id="topic-search" type="search" placeholder="ID, tytuł lub źródło">
        <div class="topic-visibility-filters" role="group" aria-label="Widoczność kolejek tematów">
            <input class="topic-filter-checkbox" id="topic-show-ready" type="checkbox"<?php echo $showReady ? ' checked' : ''; ?>><label for="topic-show-ready">Pokaż gotowe</label>
            <input class="topic-filter-checkbox" id="topic-show-action" type="checkbox"<?php echo $showAction ? ' checked' : ''; ?>><label for="topic-show-action">Pokaż wymagające akcji</label>
        </div>
    </nav>

    <form class="automatic-dispatch-toggle" method="post" action="admin-editorial-topics.php">
        <input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>">
        <input type="hidden" name="action" value="toggle_automatic_dispatch">
        <input type="hidden" name="dispatcher_state" value="<?php echo $automaticDispatchPaused ? 'running' : 'paused'; ?>">
        <strong><?php echo $automaticDispatchPaused ? 'Automatyczny dispatcher: PAUZA' : 'Automatyczny dispatcher: aktywny'; ?></strong>
        <span><?php echo $automaticDispatchPaused ? 'Wstrzymane tematy nie ruszą bez ręcznego kliknięcia.' : 'Scheduler i automatyczne retry mogą uruchamiać zadania.'; ?></span>
        <button type="submit" class="<?php echo $automaticDispatchPaused ? 'dispatcher-play' : 'dispatcher-pause'; ?>"><?php echo $automaticDispatchPaused ? '▶ Wznów automatykę' : '⏸ Wstrzymaj automatykę'; ?></button>
    </form>

    <form class="topic-bulk-toolbar" id="topic-bulk-toolbar" method="post" action="admin-editorial-topics.php" data-api="admin-editorial-topics-api.php" data-filter="<?php echo escape_html($topicFilter); ?>" data-limit="<?php echo CONTENT_STUDIO_BATCH_LIMIT; ?>" data-dispatch-paused="<?php echo $automaticDispatchPaused ? '1' : '0'; ?>">
        <input type="hidden" name="return_filter" value="<?php echo escape_html($topicFilter); ?>"><input type="hidden" name="return_show_ready" value="<?php echo $showReady ? '1' : '0'; ?>"><input type="hidden" name="return_show_action" value="<?php echo $showAction ? '1' : '0'; ?>">
        <input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>">
        <input type="hidden" name="action" value="run_workflow">
        <input type="hidden" name="request_key" value="fallback-<?php echo escape_html(bin2hex(random_bytes(8))); ?>">
        <div class="topic-bulk-select"><label><input id="topic-select-visible" type="checkbox"> Zaznacz wszystkie widoczne</label><button type="button" id="topic-select-top">Zaznacz <?php echo CONTENT_STUDIO_BATCH_LIMIT; ?></button><button type="button" id="topic-clear-selection">Wyczyść wybór</button><strong class="topic-selected-counter" aria-live="polite">Zaznaczono: <span id="topic-selected-count">0</span></strong></div>
        <div class="topic-action-toolbar" role="group" aria-label="Akcje dla zaznaczonych tematów">
            <button name="workflow_action" value="research" disabled>Research</button><button name="workflow_action" value="draft" disabled>Szkic</button><button name="workflow_action" value="quality" disabled>Kontrola jakości</button><button name="workflow_action" value="images" disabled>Grafiki</button><button name="workflow_action" value="generate_all" class="topic-generate-all" disabled>Wygeneruj całość</button>
            <button type="submit" form="topic-trash-bulk-form" id="topic-trash-selected" class="topic-trash-selected admin-danger-action" title="Przenieś zaznaczone tematy do Kosza na 10 dni" aria-label="Przenieś zaznaczone tematy do Kosza na 10 dni" disabled><span aria-hidden="true">🗑</span><span>Do kosza</span></button>
            <a class="topic-trash-link" href="admin-topic-trash.php">Otwórz Kosz</a>
        </div>
        <p id="topic-bulk-scope">Wybierz tematy. Bezpieczny limit partii: <?php echo CONTENT_STUDIO_BATCH_LIMIT; ?>.</p><div id="topic-hidden-ids"></div>
    </form>
    <form id="topic-trash-bulk-form" class="topic-trash-bulk-form" method="post" action="admin-editorial-topics.php">
        <input type="hidden" name="return_filter" value="<?php echo escape_html($topicFilter); ?>"><input type="hidden" name="return_show_ready" value="<?php echo $showReady ? '1' : '0'; ?>"><input type="hidden" name="return_show_action" value="<?php echo $showAction ? '1' : '0'; ?>"><input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>"><input type="hidden" name="action" value="trash_selected">
        <div id="topic-trash-hidden-ids"></div>
    </form>

    <div class="topic-list-heading"><h2>Tematy (<span id="topic-visible-count"><?php echo $visibleTopicCount; ?></span>)</h2><span>Malejąco według punktacji</span></div>
    <p id="topic-live" class="topic-live" aria-live="polite" tabindex="-1"></p>
    <div class="technical-source-list topic-control-list" id="topic-control-list">
        <?php foreach ($topics as $index => $topic): ?>
            <?php
            $items = topic_feed_items((int) $topic['id']);
            $breakdown = topic_score_breakdown($topic);
            $state = $topicWorkflow[$index];
            $workflow = $state['workflow'];
            ?>
            <article class="technical-source-card topic-control-card<?php echo $workflow['ready'] ? ' is-ready' : ''; ?>"<?php echo generation_topic_queue_visible((string) $state['queue_state'], $showReady, $showAction) ? '' : ' hidden'; ?> id="topic-<?php echo (int) $topic['id']; ?>" data-topic-id="<?php echo (int) $topic['id']; ?>" data-search="<?php echo escape_html(mb_strtolower('#' . (int) $topic['id'] . ' ' . (string) $topic['title'] . ' ' . (string) $topic['source_names'])); ?>" data-queue-state="<?php echo escape_html((string) $state['queue_state']); ?>" data-ready="<?php echo $workflow['ready'] ? '1' : '0'; ?>" data-requires-action="<?php echo !empty($state['requires_action']) ? '1' : '0'; ?>" data-selectable="<?php echo $state['selectable'] ? '1' : '0'; ?>" data-selected="false">
                <header>
                    <div class="topic-card-heading">
                        <div class="topic-card-selector">
                            <label class="topic-checkbox-hitbox" for="topic-check-<?php echo (int) $topic['id']; ?>"><input class="topic-checkbox" id="topic-check-<?php echo (int) $topic['id']; ?>" type="checkbox" value="<?php echo (int) $topic['id']; ?>"<?php echo $state['selectable'] ? '' : ' disabled'; ?> aria-describedby="topic-title-<?php echo (int) $topic['id']; ?>"><span>Zaznacz temat</span></label>
                            <span class="topic-selected-badge" aria-hidden="true">✓ Zaznaczony</span>
                            <span class="editorial-status"><?php echo (int) $topic['item_count']; ?> wpisów / <?php echo (int) $topic['source_count']; ?> źródeł</span>
                            <?php if ($workflow['ready']): ?><span class="topic-ready-badge">Gotowe</span><?php endif; ?>
                        </div>
                        <h3 id="topic-title-<?php echo (int) $topic['id']; ?>">#<?php echo (int) $topic['id']; ?> — <?php echo escape_html((string) $topic['title']); ?></h3>
                    </div>
                    <a class="button admin-preview-action" href="admin-post-preview.php?post=<?php echo (int) $topic['primary_post_id']; ?>" target="_blank" rel="noopener">Podgląd</a>
                </header>
                <?php if (!$state['selectable'] && $state['unavailable_reason'] !== ''): ?><p class="topic-unavailable">Niedostępny: <?php echo escape_html($state['unavailable_reason']); ?></p><?php endif; ?>
                <p>
                    <strong>Wynik: <?php echo $topic['score'] === null ? 'nie oceniono' : (int) $topic['score'] . '/100'; ?></strong>
                    · ryzyko: <?php echo escape_html((string) $topic['risk_level']); ?>
                    · automatyzacja: <?php echo (int) $topic['automatic_eligible'] === 1 ? 'dozwolona' : 'zablokowana'; ?>
                </p>
                <p><?php echo escape_html((string) $topic['source_names']); ?> · status: <?php echo escape_html((string) $topic['status']); ?><?php echo (int) $topic['grouping_locked'] === 1 ? ' · grupowanie ręcznie zablokowane' : ''; ?></p>
                <ol class="topic-workflow-path" aria-label="Stan workflow">
                    <li class="<?php echo $workflow['research']['done'] ? 'is-done' : ''; ?>">Research <?php echo $workflow['research']['done'] ? '✓' : '—'; ?></li>
                    <li class="<?php echo $workflow['draft']['done'] ? 'is-done' : ''; ?>">Szkic <?php echo $workflow['draft']['done'] ? 'v' . (int) $workflow['draft']['version'] . ' ✓' : '—'; ?></li>
                    <li class="<?php echo $workflow['quality']['done'] ? 'is-done' : ''; ?>">QC <?php echo $workflow['quality']['done'] ? '✓' : '—'; ?></li>
                    <li class="<?php echo $workflow['images']['done'] ? 'is-done' : ''; ?>">Grafiki <?php echo $workflow['images']['done'] ? '✓' : '—'; ?></li>
                    <li class="<?php echo $workflow['ready'] ? 'is-done' : ''; ?>">Gotowe <?php echo $workflow['ready'] ? '✓' : '—'; ?></li>
                </ol>
                <?php if (is_array($state['job'])): ?>
                    <div class="topic-job-status<?php echo $state['job']['status'] === 'failed' ? ' is-error' : ''; ?>">
                        <strong><?php echo escape_html((string) $state['job']['stage']); ?> · <?php echo (int) $state['job']['progress']; ?>%</strong>
                        <progress max="100" value="<?php echo (int) $state['job']['progress']; ?>"><?php echo (int) $state['job']['progress']; ?>%</progress>
                        <?php if ($state['job']['reason'] !== ''): ?><span><?php echo escape_html((string) $state['job']['reason']); ?></span><?php endif; ?>
                        <?php if (($state['job']['technical_error'] ?? '') !== '' && $state['job']['technical_error'] !== $state['job']['reason']): ?><details><summary>Szczegóły techniczne</summary><code><?php echo escape_html((string) $state['job']['technical_error']); ?></code></details><?php endif; ?>
                        <?php if ($state['job']['retryable']): ?><button type="button" class="topic-retry" data-retry-item="<?php echo (int) $state['job']['id']; ?>"><?php echo ($state['job']['repair_scope'] ?? '') === 'titles' ? 'Popraw tytuł' : 'Ponów etap'; ?></button><?php endif; ?>
                        <?php if (!empty($state['can_resume_legacy'])): ?><form method="post"><input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>"><input type="hidden" name="action" value="resume_legacy"><input type="hidden" name="item_id" value="<?php echo (int)$state['job']['id']; ?>"><input type="hidden" name="return_topic_id" value="<?php echo (int)$topic['id']; ?>"><button type="submit" class="topic-resume-legacy">Wznów nowym algorytmem</button></form><?php endif; ?>
                    </div>
                <?php endif; ?>
                <p class="topic-card-message<?php echo $messageTopicId === (int) $topic['id'] && $error !== '' ? ' is-error' : ''; ?>" role="status" aria-live="polite"<?php echo $messageTopicId === (int) $topic['id'] && ($error !== '' || $resultMessage !== '') ? '' : ' hidden'; ?>><?php if ($messageTopicId === (int) $topic['id']): ?><?php echo escape_html($error !== '' ? $error : $resultMessage); ?><?php endif; ?></p>
                <form class="topic-card-actions" method="post" action="admin-editorial-topics.php" aria-label="Generowanie dla tematu <?php echo escape_html((string) $topic['title']); ?>">
                    <input type="hidden" name="return_filter" value="<?php echo escape_html($topicFilter); ?>"><input type="hidden" name="return_topic_id" value="<?php echo (int) $topic['id']; ?>"><input type="hidden" name="return_show_ready" value="<?php echo $showReady ? '1' : '0'; ?>"><input type="hidden" name="return_show_action" value="<?php echo $showAction ? '1' : '0'; ?>">
                    <input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>"><input type="hidden" name="action" value="run_workflow"><input type="hidden" name="topic_ids[]" value="<?php echo (int) $topic['id']; ?>"><input type="hidden" name="request_key" value="topic-<?php echo (int) $topic['id']; ?>-<?php echo escape_html(bin2hex(random_bytes(5))); ?>">
                    <?php foreach (['research' => 'Research', 'draft' => 'Szkic', 'quality' => 'Kontrola jakości', 'images' => 'Grafiki', 'generate_all' => 'Wygeneruj całość'] as $actionKey => $actionLabel): $actionState = $state['actions'][$actionKey]; ?>
                        <button name="workflow_action" value="<?php echo $actionKey; ?>" data-workflow-action="1"<?php echo $actionState['enabled'] ? '' : ' disabled'; ?> title="<?php echo escape_html($actionState['enabled'] ? $actionLabel : $actionState['reason']); ?>"<?php echo $actionKey === 'generate_all' ? ' class="topic-generate-all"' : ''; ?>><?php echo escape_html($actionLabel); ?></button>
                    <?php endforeach; ?>
                    <?php $jobStatus = (string) ($state['job']['status'] ?? ''); ?>
                    <button type="button" class="topic-item-control topic-pause-item" data-pause-item="<?php echo (int) ($state['job']['id'] ?? 0); ?>" title="Wstrzymaj generowanie tego tematu" aria-label="Wstrzymaj generowanie tego tematu"<?php echo in_array($jobStatus, GENERATION_BATCH_ACTIVE_STATUSES, true) ? '' : ' hidden'; ?>><span aria-hidden="true">⏸️</span></button>
                    <button type="button" class="topic-item-control topic-resume-item" data-resume-item="<?php echo (int) ($state['job']['id'] ?? 0); ?>" title="Wznów generowanie tego tematu" aria-label="Wznów generowanie tego tematu"<?php echo $jobStatus === 'paused_by_operator' ? '' : ' hidden'; ?>><span aria-hidden="true">▶️</span></button>
                    <button type="button" class="topic-item-control topic-reset-fresh" data-reset-topic="<?php echo (int) $topic['id']; ?>" title="Zapisz backup i wyzeruj artykuł do ponownego wygenerowania" aria-label="Zapisz backup i wyzeruj artykuł do ponownego wygenerowania"><span aria-hidden="true">♻️</span></button>
                </form>
                <?php if ($state['proposal_url']): ?><a class="topic-proposal-link" href="<?php echo escape_html((string) $state['proposal_url']); ?>">Otwórz propozycję do przeglądu →</a><?php endif; ?>
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
                <details class="topic-source-tools">
                    <summary>Źródła, łączenie i ręczne rozdzielanie</summary>
                    <div class="topic-source-tools__content">
                    <ul>
                        <?php foreach ($items as $item): ?>
                            <li>
                                <a href="<?php echo escape_html((string) $item['source_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo escape_html((string) $item['title']); ?></a>
                                — <?php echo escape_html((string) $item['source_name']); ?>,
                                pewność <?php echo number_format((float) $item['confidence'] * 100, 1, ',', ''); ?>%
                                <?php if (count($items) > 1): ?>
                                    <form method="post" action="admin-editorial-topics.php">
                                        <input type="hidden" name="return_filter" value="<?php echo escape_html($topicFilter); ?>"><input type="hidden" name="return_topic_id" value="<?php echo (int) $topic['id']; ?>"><input type="hidden" name="return_show_ready" value="<?php echo $showReady ? '1' : '0'; ?>"><input type="hidden" name="return_show_action" value="<?php echo $showAction ? '1' : '0'; ?>">
                                        <input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>">
                                        <input type="hidden" name="action" value="split_item">
                                        <input type="hidden" name="feed_item_id" value="<?php echo (int) $item['id']; ?>">
                                        <button type="submit">Rozdziel</button>
                                    </form>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <form class="topic-merge-form" method="post" action="admin-editorial-topics.php">
                        <input type="hidden" name="return_filter" value="<?php echo escape_html($topicFilter); ?>"><input type="hidden" name="return_topic_id" value="<?php echo (int) $topic['id']; ?>"><input type="hidden" name="return_show_ready" value="<?php echo $showReady ? '1' : '0'; ?>"><input type="hidden" name="return_show_action" value="<?php echo $showAction ? '1' : '0'; ?>">
                        <input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>">
                        <input type="hidden" name="action" value="merge_topics">
                        <input type="hidden" name="source_topic_id" value="<?php echo (int) $topic['id']; ?>">
                        <label for="target-<?php echo (int) $topic['id']; ?>">Połącz cały temat z ID:</label>
                        <div class="editorial-action-row">
                            <input id="target-<?php echo (int) $topic['id']; ?>" type="number" name="target_topic_id" min="1" required>
                            <button type="submit">Połącz ręcznie</button>
                        </div>
                    </form>
                    </div>
                </details>
                <form class="topic-trash-form" method="post" action="admin-editorial-topics.php">
                    <input type="hidden" name="return_filter" value="<?php echo escape_html($topicFilter); ?>"><input type="hidden" name="return_topic_id" value="<?php echo (int) $topic['id']; ?>"><input type="hidden" name="return_show_ready" value="<?php echo $showReady ? '1' : '0'; ?>"><input type="hidden" name="return_show_action" value="<?php echo $showAction ? '1' : '0'; ?>">
                    <input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>"><input type="hidden" name="action" value="trash_topic"><input type="hidden" name="topic_id" value="<?php echo (int) $topic['id']; ?>">
                    <button type="submit" class="topic-trash" title="Przenieś do Kosza na 10 dni" aria-label="Przenieś temat do Kosza: <?php echo escape_html((string) $topic['title']); ?>">🗑</button>
                </form>
            </article>
        <?php endforeach; ?>
    </div>
    <script type="application/json" id="topic-workflow-data"><?php echo json_encode($topicWorkflow, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG); ?></script>
</section>
<?php admin_page_close(); ?>
