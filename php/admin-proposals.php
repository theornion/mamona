<?php

declare(strict_types=1);

require_once __DIR__ . '/admin-auth.php';
require_once __DIR__ . '/admin-database.php';
require_once __DIR__ . '/admin-ui.php';

require_admin_login();

$error = '';
$notice = '';
$proposalQueue = trim((string) ($_POST['queue'] ?? $_GET['queue'] ?? 'ready')) === 'action' ? 'action' : 'ready';
$proposalQueueQuery = $proposalQueue === 'action' ? '&queue=action' : '';
$batchId = filter_input(INPUT_GET, 'batch', FILTER_VALIDATE_INT) ?: 0;
$draftId = filter_input(INPUT_GET, 'draft', FILTER_VALIDATE_INT) ?: 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $draftId = filter_input(INPUT_POST, 'draft_id', FILTER_VALIDATE_INT) ?: 0;
    $batchId = filter_input(INPUT_POST, 'batch_id', FILTER_VALIDATE_INT) ?: 0;
    $action = trim((string) ($_POST['action'] ?? ''));
    if (!admin_valid_csrf()) {
        $error = 'Sesja formularza wygasła. Odśwież stronę i spróbuj ponownie.';
    } else {
        try {
            $draft = $draftId > 0 ? find_proposal_draft($draftId) : null;
            if ($action === 'regenerate') {
                $notes = (string) ($_POST['notes'] ?? '');
                $scope = proposal_infer_feedback_scope($notes);
                if ($scope === 'images') {
                    $newDraftId = prepare_image_feedback_revision($draftId, $notes);
                    header('Location: admin-proposals.php?draft=' . $newDraftId . '&generated=1' . $proposalQueueQuery, true, 303);
                    exit;
                }
                $operationId = prepare_article_feedback_revision($draftId, 'auto', $notes);
                if (generation_mode() === 'api') {
                    $pipeline = execute_article_feedback_pipeline($operationId);
                    $newDraft = $pipeline['draft'];
                    header('Location: admin-proposals.php?draft=' . (int) $newDraft['id'] . '&generated=1&queue=action', true, 303);
                    exit;
                }
                $newDraft = find_article_draft_by_operation($operationId);
                header('Location: admin-proposals.php?draft=' . (int) ($newDraft['id'] ?? $draftId) . '&prepared=' . $operationId . '&queue=action', true, 303);
                exit;
            }
            if ($action === 'execute_revision') {
                $operationId = filter_input(INPUT_POST, 'operation_id', FILTER_VALIDATE_INT) ?: 0;
                $pipeline = execute_article_feedback_pipeline($operationId);
                $newDraft = $pipeline['draft'];
                header('Location: admin-proposals.php?draft=' . (int) $newDraft['id'] . '&generated=1&queue=action', true, 303);
                exit;
            }
            if ($action === 'quality_check') {
                $operationId = prepare_quality_check_operation($draftId);
                if (generation_mode() === 'api') execute_generation_operation($operationId);
                header('Location: admin-proposals.php?draft=' . $draftId . '&quality=' . $operationId . $proposalQueueQuery, true, 303);
                exit;
            }
            if ($action === 'execute_quality') {
                execute_generation_operation((int) ($_POST['operation_id'] ?? 0));
                header('Location: admin-proposals.php?draft=' . $draftId . '&quality_done=1' . $proposalQueueQuery, true, 303);
                exit;
            }
            if ($action === 'activate') {
                $postId = activate_proposal_version($draftId);
                header('Location: admin-proposals.php?draft=' . $draftId . '&activated=1' . $proposalQueueQuery, true, 303);
                exit;
            }
            if ($draft === null) throw new RuntimeException('Nie znaleziono propozycji.');
            $postId = (int) $draft['post_id'];
            if ($action === 'approve') {
                $quality = proposal_latest_quality_check($draftId);
                if (!is_array($quality)) throw new RuntimeException('Nie można zatwierdzić szkicu bez ukończonej kontroli jakości.');
                if (is_array($quality) && proposal_reviewable_blocks($quality) !== [] && (string) ($quality['human_review_status'] ?? '') !== 'approved') {
                    review_quality_risk((int) $quality['id'], 'approved', trim((string) ($_POST['reason'] ?? '')));
                    $quality = proposal_latest_quality_check($draftId);
                }
                if ((int) ($quality['passed'] ?? 0) !== 1) throw new RuntimeException('Kontrola jakości nadal nie jest zaliczona. Popraw szkic lub zapisz wymaganą decyzję QC.');
                $alreadyApproved = (int) ($draft['is_active'] ?? 0) === 1 && (string) (find_post($postId, true)['status'] ?? '') === 'review';
                if (!$alreadyApproved) {
                    activate_proposal_version($draftId);
                    change_post_editorial_status($postId, 'review', 'Zatwierdzono do dalszej pracy na ekranie propozycji.', 'admin');
                    record_proposal_audit($postId, $draftId, 'approved_for_further_work');
                }
                header('Location: admin-editorial-topics.php?filter=active&approved=1#topic-' . (int) $draft['topic_id'], true, 303);
                exit;
            } elseif ($action === 'quality_review') {
                $checkId = (int) ($_POST['quality_check_id'] ?? 0);
                $decision = (string) ($_POST['decision'] ?? '');
                $reason = trim((string) ($_POST['reason'] ?? ''));
                review_quality_risk($checkId, $decision, $reason);
                record_proposal_audit($postId, $draftId, 'quality_human_review', [
                    'quality_check_id' => $checkId, 'decision' => $decision, 'reason' => $reason,
                ]);
                $notice = 'Decyzję człowieka zapisano wraz z uzasadnieniem i audytem.';
            } elseif ($action === 'reject') {
                $reason = trim((string) ($_POST['reason'] ?? ''));
                if (mb_strlen($reason) < 5) throw new InvalidArgumentException('Podaj powód odrzucenia.');
                change_post_editorial_status($postId, 'rejected', $reason, 'admin');
                record_proposal_audit($postId, $draftId, 'rejected', ['reason' => $reason]);
                $notice = 'Materiał odrzucono i zachowano w historii.';
            } elseif ($action === 'publish') {
                if ((string) ($_POST['confirm_publish'] ?? '') !== 'PUBLISH') throw new InvalidArgumentException('Publikacja wymaga osobnego potwierdzenia.');
                $blockers = proposal_publication_blockers($postId);
                if ($blockers !== []) throw new RuntimeException($blockers[0]);
                change_post_editorial_status($postId, 'published', 'Świadoma publikacja z ekranu propozycji.', 'admin');
                record_proposal_audit($postId, $draftId, 'published');
                $notice = 'Artykuł został opublikowany.';
            } elseif ($action === 'schedule') {
                $blockers = proposal_publication_blockers($postId);
                if ($blockers !== []) throw new RuntimeException($blockers[0]);
                $scheduled = editorial_datetime_to_utc((string) ($_POST['scheduled_at'] ?? ''));
                if ($scheduled === null) throw new InvalidArgumentException('Wybierz termin publikacji.');
                bueno_database()->prepare('UPDATE posts SET scheduled_at = :at WHERE id = :id')->execute([':at' => $scheduled, ':id' => $postId]);
                change_post_editorial_status($postId, 'scheduled', 'Zaplanowano z ekranu propozycji.', 'admin');
                record_proposal_audit($postId, $draftId, 'scheduled', ['scheduled_at' => $scheduled]);
                $notice = 'Publikację zaplanowano.';
            } elseif ($action === 'image_reject') {
                $imageId = (int) ($_POST['image_id'] ?? 0);
                reject_article_source_image($imageId);
                record_proposal_audit($postId, $draftId, 'image_rejected', ['image_id' => $imageId]);
                $notice = 'Obraz odrzucono; tekst szkicu pozostał bez zmian.';
            } elseif ($action === 'image_accept') {
                $imageId = (int) ($_POST['image_id'] ?? 0);
                $image = array_values(array_filter(list_article_images($postId), static fn(array $item): bool => (int) $item['id'] === $imageId))[0] ?? null;
                if (!is_array($image) || (string) $image['status'] !== 'downloaded') throw new RuntimeException('Najpierw wybierz i pobierz legalny obraz.');
                foreach (['source_page_url', 'author', 'license', 'license_url', 'attribution', 'alt'] as $field) {
                    if (trim((string) ($image[$field] ?? '')) === '') throw new RuntimeException('Obraz nie ma pełnych danych licencyjnych.');
                }
                record_proposal_audit($postId, $draftId, 'image_accepted', ['image_id' => $imageId]);
                $notice = 'Obraz zaakceptowano.';
            } elseif ($action === 'image_manual_accept_rejected') {
                $auditId = (int) ($_POST['vision_audit_id'] ?? 0);
                $imageId = article_image_manual_accept_rejected_candidate($postId, $auditId);
                record_proposal_audit($postId, $draftId, 'image_manual_accept_rejected', [
                    'image_id' => $imageId, 'vision_audit_id' => $auditId, 'acceptance_source' => 'operator_manual',
                ]);
                $notice = 'Ręczna akceptacja została zapisana z zachowaniem audytu Vision i danych praw.';
            } elseif ($action === 'image_upload') {
                upload_proposal_image((int) ($_POST['image_id'] ?? 0), $_FILES['image_file'] ?? [], $_POST);
                $notice = 'Ręczny obraz zapisano wraz z licencją i atrybucją.';
            }
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
    }
}

$proposals = $proposalQueue === 'action' ? list_action_required_proposals() : list_ready_article_proposals();
if ($batchId > 0) {
    $batchDraftIds = array_values(array_filter(array_map(static fn(array $item): int => (int) ($item['draft_version_id'] ?? 0), generation_batch_item_rows($batchId))));
    $proposals = array_values(array_filter($proposals, static fn(array $item): bool => in_array((int) $item['id'], $batchDraftIds, true)));
}
if ($draftId <= 0 && $proposals !== []) $draftId = (int) $proposals[0]['id'];
$queueDraftIds = array_map(static fn (array $proposal): int => (int) $proposal['id'], $proposals);
if ($draftId > 0 && !in_array($draftId, $queueDraftIds, true)) $draftId = $proposals === [] ? 0 : (int) $proposals[0]['id'];
$proposalGroups = [$proposalQueue === 'action' ? 'Wymagające akcji' : 'Gotowe propozycje' => $proposals];
$selected = $draftId > 0 ? find_proposal_draft($draftId) : null;
if ($selected) refresh_article_image_rendering((int) $selected['post_id']);
$post = $selected ? find_post((int) $selected['post_id'], true) : null;
$draftData = $selected ? proposal_json_decode((string) $selected['draft_json']) : [];
$versions = $post ? list_proposal_versions((int) $post['id']) : [];
$displayVersions = array_values(array_filter($versions, static function (array $version): bool {
    if (!in_array((string) $version['status'], ['completed', 'frozen'], true)) return false;
    return article_draft_main_content_length(proposal_json_decode((string) ($version['draft_json'] ?? ''))) > 0;
}));
$images = $post ? article_image_required_records((int) $post['id']) : [];
$rejectedImageReview = $post ? article_image_rejected_review_candidates((int) $post['id']) : ['items' => [], 'reviewable_count' => 0, 'hard_rejected_count' => 0];
$proposalLayoutAudit = [];
$proposalAdConfig = $post
    ? advertising_article_render_config((int) $post['id'], true, [
        'allowed_placements' => ['article-inline'],
    ])
    : null;
$proposalPreviewHtml = $selected && $post
    ? render_article_blocks_with_layout_and_advertising(
        article_draft_content_blocks($draftData),
        $images,
        article_layout_plan_for_post((int) $post['id'], $proposalLayoutAudit),
        article_related_context_blocks_for_post((int) $post['id']),
        $proposalAdConfig,
        null,
        $proposalLayoutAudit
    )
    : '';
$feedback = $post ? list_proposal_feedback((int) $post['id']) : [];
$audit = $post ? list_proposal_audit((int) $post['id']) : [];
$package = $selected ? find_research_package((int) $selected['research_package_id']) : null;
$research = $package ? proposal_json_decode((string) $package['package_json']) : [];
$researchInput = $package ? proposal_json_decode((string) $package['research_input_json']) : [];
$researchPolicy = $package ? proposal_json_decode((string) ($package['policy_json'] ?? '{}')) : [];
$blockers = $post ? proposal_publication_blockers((int) $post['id']) : [];
$qualityCheck = $selected ? proposal_latest_quality_check((int) $selected['id']) : null;
$qualityBlocks = $qualityCheck ? proposal_json_decode((string) $qualityCheck['hard_blocks_json']) : [];
$reviewableBlocks = $qualityCheck ? proposal_reviewable_blocks($qualityCheck) : [];
$automationReport = $selected ? repair_report_for_draft((int) $selected['id']) : ['events' => [], 'unresolved' => []];
$compareId = filter_input(INPUT_GET, 'compare', FILTER_VALIDATE_INT) ?: 0;
$completedComparisons = array_values(array_filter(
    $displayVersions,
    static fn(array $version): bool => in_array((string) $version['status'], ['completed', 'frozen'], true) && (int) $version['id'] !== $draftId
));
$compare = $compareId > 0 ? find_proposal_draft($compareId) : ($completedComparisons[0] ?? null);
$diff = $selected && is_array($compare) ? proposal_diff($compare, $selected) : null;
$proposalProcesses = array_values(array_filter(
    list_generation_process_history(30),
    static fn (array $item): bool => in_array((string) $item['status'], ['waiting_review', 'failed', 'rate_limited'], true)
));
$actionTopics = $proposalQueue === 'action' ? list_action_required_topic_payload() : [];

$proposalPageTitle = $proposalQueue === 'action' ? 'Wymagające akcji' : 'Gotowe propozycje';
admin_page_open($proposalPageTitle, $proposalQueue === 'action' ? 'action-required' : 'proposals');
?>
<section class="post admin-card proposal-review" data-feedback-storage="proposal-feedback-<?php echo (int) ($post['id'] ?? 0); ?>">
    <header class="proposal-review__header">
        <div><p class="proposal-kicker">Studio treści</p><h1><?php echo escape_html($proposalPageTitle); ?></h1><p><?php echo $proposalQueue === 'action' ? 'Materiały oczekujące na decyzję, poprawkę albo uzupełnienie etapu.' : 'Materiały, które zaliczyły wszystkie wymagane etapy.'; ?></p></div>
        <?php if ($batchId > 0): ?><a class="button" href="admin-content-studio.php?batch=<?php echo $batchId; ?>">Wróć do batcha #<?php echo $batchId; ?></a><?php endif; ?>
    </header>
    <?php if ($error !== ''): ?><p class="admin-notice is-error" role="alert"><?php echo escape_html($error); ?></p><?php endif; ?>
    <?php if ($notice !== ''): ?><p class="admin-notice is-success" role="status"><?php echo escape_html($notice); ?></p><?php endif; ?>
    <?php if ($proposalProcesses !== []): ?><details class="proposal-process-summary"><summary>Procesy wymagające uwagi (<?php echo count($proposalProcesses); ?>)</summary><ul><?php foreach ($proposalProcesses as $process): ?><li><strong><?php echo escape_html((string) $process['topic_title']); ?></strong> — <?php echo escape_html((string) $process['status']); ?>, <?php echo (int) $process['progress_percent']; ?>%<?php if ((string) ($process['wait_reason'] ?: $process['error_message']) !== ''): ?>: <?php echo escape_html((string) ($process['wait_reason'] ?: $process['error_message'])); ?><?php endif; ?></li><?php endforeach; ?></ul><a href="admin-editorial-queue.php">Pełna historia procesów</a></details><?php endif; ?>
    <?php if ($proposalQueue === 'action'): ?><section class="proposal-action-topics"><h2>Wszystkie tematy wymagające akcji (<?php echo count($actionTopics); ?>)</h2><div class="technical-source-list"><?php foreach ($actionTopics as $actionTopic): ?><article class="technical-source-card"><h3>#<?php echo (int) $actionTopic['id']; ?> — <?php echo escape_html((string) $actionTopic['title']); ?></h3><p><?php echo escape_html((string) (($actionTopic['job']['reason'] ?? '') ?: $actionTopic['unavailable_reason'])); ?></p><a class="button" href="<?php echo escape_html((string) ($actionTopic['proposal_url'] ?: 'admin-editorial-topics.php?filter=active#topic-' . (int) $actionTopic['id'])); ?>"><?php echo $actionTopic['proposal_url'] ? 'Otwórz propozycję' : 'Otwórz temat'; ?></a></article><?php endforeach; ?></div></section><?php endif; ?>

    <div class="proposal-layout">
        <aside class="proposal-list" aria-label="Lista propozycji">
            <?php if ($proposals === []): ?><p class="admin-notice"><?php echo $proposalQueue === 'action' ? 'Brak materiałów wymagających akcji.' : 'Brak gotowych materiałów.'; ?></p><?php endif; ?>
            <?php foreach ($proposalGroups as $groupLabel => $groupProposals): if ($groupProposals === []) continue; ?><h2><?php echo escape_html($groupLabel); ?></h2>
            <?php foreach ($groupProposals as $proposal): $data = proposal_json_decode((string) $proposal['draft_json']); $warnings = proposal_json_decode((string) ($proposal['hard_blocks_json'] ?? '[]')); ?>
                <a class="proposal-card<?php echo (int) $proposal['id'] === $draftId ? ' is-active' : ''; ?>" href="admin-proposals.php?draft=<?php echo (int) $proposal['id']; ?><?php echo $proposalQueue === 'action' ? '&amp;queue=action' : ''; ?><?php echo $batchId ? '&amp;batch=' . $batchId : ''; ?>">
                    <span class="proposal-card__thumb" aria-hidden="true"><?php echo (int) $proposal['ready_image_count']; ?>/<?php echo (int) $proposal['image_count']; ?> zdjęć</span>
                    <strong><?php echo escape_html((string) ($data['title'] ?? $proposal['topic_title'])); ?></strong>
                    <span><?php echo escape_html((string) $proposal['category_title']); ?> · <?php echo article_draft_main_content_length($data); ?> znaków</span>
                    <span class="proposal-score">Jakość: <?php echo $proposal['final_score'] === null ? 'oczekuje' : (int) $proposal['final_score'] . '/100'; ?></span>
                    <?php if ((int) $proposal['warning_image_count'] > 0 || $warnings !== []): ?><small>⚠ Wymaga uwagi: <?php echo count($warnings) + (int) $proposal['warning_image_count']; ?></small><?php endif; ?>
                </a>
            <?php endforeach; endforeach; ?>
        </aside>

        <main class="proposal-detail">
        <?php if ($selected && $post): ?>
            <div class="proposal-statebar"><strong>NIEPUBLICZNY PODGLĄD</strong><span>Wersja <?php echo (int) $selected['version_number']; ?> · <?php echo escape_html((string) $selected['status']); ?> · <?php echo escape_html((string) $post['status']); ?></span></div>
            <div class="proposal-actions">
                <form method="post"><input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>"><input type="hidden" name="action" value="approve"><input type="hidden" name="draft_id" value="<?php echo $draftId; ?>"><?php if ($reviewableBlocks !== [] && (string) ($qualityCheck['human_review_status'] ?? '') !== 'approved'): ?><label>Uzasadnienie decyzji QC<textarea name="reason" minlength="10" maxlength="1000" required></textarea></label><?php endif; ?><button>Zatwierdź do dalszej pracy</button></form>
                <a class="button" href="admin-post-editor.php?post=<?php echo (int) $post['id']; ?>">Edytuj ręcznie</a>
                <a class="button" href="#feedback-form">Wygeneruj poprawioną wersję</a>
            </div>

            <section class="proposal-quality"><h2>Automatyczne decyzje i wątpliwości</h2>
                <?php if ($automationReport['events'] === []): ?><p>Pipeline nie zapisał automatycznych odstępstw ani strategii naprawczych dla tej wersji.</p><?php else: ?><ol><?php foreach ($automationReport['events'] as $event): ?><li><strong><?php echo escape_html((string) ($event['gate'] ?? 'pipeline')); ?> — <?php echo escape_html((string) ($event['strategy'] ?? '')); ?></strong><pre><?php echo escape_html(generation_json((array) ($event['details'] ?? []))); ?></pre></li><?php endforeach; ?></ol><?php endif; ?>
                <?php if ($automationReport['unresolved'] !== []): ?><h3>Nierozstrzygnięte notatki</h3><ul><?php foreach ($automationReport['unresolved'] as $note): ?><li><?php echo escape_html((string) $note); ?></li><?php endforeach; ?></ul><?php endif; ?>
                <p>Raport jest informacyjny; pakiet pozostaje niepubliczny, a publikacja wymaga osobnej ręcznej akcji.</p>
            </section>

            <?php if ($qualityCheck): ?><section class="proposal-quality"><h2>Kontrola jakości</h2>
                <p><strong>Wynik:</strong> <?php echo (int) $qualityCheck['final_score']; ?>/100 · <?php echo (int) $qualityCheck['passed'] === 1 ? 'zaliczona' : 'niezaliczona'; ?></p>
                <?php $qualityModel = proposal_json_decode((string) $qualityCheck['model_result_json']); ?>
                <p><strong>Uzasadnienie:</strong> <?php echo escape_html((string) ($qualityModel['justification'] ?? 'Brak uzasadnienia modelu.')); ?></p>
                <?php if ($qualityBlocks !== []): ?><h3>Ostrzeżenia i twarde blokady</h3><ul><?php foreach ($qualityBlocks as $block): ?><li><strong><?php echo escape_html((string) ($block['code'] ?? 'block')); ?>:</strong> <?php echo escape_html((string) ($block['message'] ?? '')); ?></li><?php endforeach; ?></ul><?php endif; ?>
                <?php if ($reviewableBlocks !== []): ?><form method="post" class="proposal-human-review"><input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>"><input type="hidden" name="action" value="quality_review"><input type="hidden" name="draft_id" value="<?php echo $draftId; ?>"><input type="hidden" name="quality_check_id" value="<?php echo (int) $qualityCheck['id']; ?>"><label>Uzasadnienie decyzji człowieka<textarea name="reason" minlength="10" maxlength="1000" required></textarea></label><button name="decision" value="approved">Zatwierdź ryzyko do dalszej pracy</button><button class="admin-danger-action" name="decision" value="rejected">Odrzuć ryzyko</button></form>
                <?php elseif ($qualityBlocks !== []): ?><p><strong>Ta blokada nie podlega ręcznemu obejściu.</strong> Wprowadź poprawki i uruchom ponowną kontrolę jakości.</p><?php endif; ?>
                <?php if (trim((string) ($qualityCheck['human_review_status'] ?? '')) !== ''): ?><p>Decyzja człowieka: <strong><?php echo escape_html((string) $qualityCheck['human_review_status']); ?></strong> — <?php echo escape_html((string) $qualityCheck['human_review_reason']); ?></p><?php endif; ?>
            </section><?php endif; ?>

            <?php if ($blockers !== []): ?><div class="proposal-blockers"><strong>Blokady publikacji</strong><ul><?php foreach ($blockers as $blocker): ?><li><?php echo escape_html($blocker); ?></li><?php endforeach; ?></ul></div><?php endif; ?>

            <section class="proposal-metadata">
                <div><h2><?php echo escape_html((string) ($draftData['title'] ?? $post['title'])); ?></h2><p><?php echo escape_html((string) ($draftData['brief'] ?? $post['excerpt'])); ?></p></div>
                <details><summary>Alternatywne tytuły</summary><ol><?php foreach ((array) ($draftData['title_variants'] ?? []) as $variant): ?><li><?php echo escape_html((string) ($variant['title'] ?? '')); ?> <small><?php echo (int) ($variant['total_score'] ?? 0); ?>/50</small></li><?php endforeach; ?></ol></details>
            </section>

            <section class="proposal-preview"><div class="proposal-section-heading"><div><p class="proposal-kicker">Wybrany niepubliczny szkic</p><h2>Pełny artykuł</h2></div><a href="admin-post-preview.php?post=<?php echo (int) $post['id']; ?>&amp;draft=<?php echo (int) $selected['id']; ?>" target="_blank" rel="noopener">Otwórz aktywną wersję osobno</a></div>
                <article class="proposal-draft-content"><h1><?php echo escape_html((string) ($draftData['title'] ?? '')); ?></h1><?php echo $proposalPreviewHtml; ?></article>
            </section>

            <section class="proposal-images"><h2>Obrazy i licencje</h2><div class="proposal-image-grid">
                <?php foreach ($images as $image): ?><article class="proposal-image-card">
                    <?php $imageStatus = (string) $image['status']; $imageManifest = image_rights_manifest_from_record($image); $imageContextNote = article_image_context_note($image); $imageVerified = $imageStatus === 'downloaded' && $imageManifest !== null; ?>
                    <?php if ($imageVerified && (string) $image['local_path'] !== '' && is_file(app_path((string) $image['local_path']))): ?><img src="../<?php echo escape_html((string) $image['local_path']); ?>" alt="<?php echo escape_html((string) $image['alt']); ?>"><?php else: ?><div class="proposal-image-placeholder" role="img" aria-label="Brak zweryfikowanej ilustracji">Miejsce na ilustrację</div><?php endif; ?>
                    <?php if ($imageContextNote !== ''): ?><small class="proposal-image-context-note"><?php echo escape_html($imageContextNote); ?></small><?php endif; ?>
                    <?php if ($imageManifest !== null): ?><details><summary>Audyt praw assetu</summary><pre><?php echo escape_html(generation_json($imageManifest)); ?></pre></details><?php endif; ?>
                    <p class="proposal-image-status is-<?php echo escape_html($imageStatus); ?>"><strong><?php echo $imageVerified ? 'Zweryfikowana — użyta automatycznie' : ($imageStatus === 'manual_review' ? 'Kandydat wymaga uwagi — niedopuszczony do publikacji' : 'Brak grafiki — placeholder, wymaga uwagi'); ?></strong></p>
                    <h3><?php echo escape_html((string) $image['role']); ?> · <?php echo escape_html((string) $image['section_id']); ?></h3>
                    <p><strong>Relacja:</strong> <?php echo escape_html((string) ($image['relationship'] ?? 'exact_subject')); ?></p>
                    <p><?php echo escape_html((string) $image['caption']); ?></p><p><strong>Autor:</strong> <?php echo escape_html((string) $image['author']); ?><br><strong>Źródło:</strong> <?php echo escape_html((string) $image['source_page_url']); ?><br><strong>Licencja:</strong> <?php echo escape_html((string) $image['license']); ?> <?php if ((string) $image['license_url'] !== ''): ?><a href="<?php echo escape_html((string) $image['license_url']); ?>" rel="license noopener">link</a><?php endif; ?></p>
                    <div class="proposal-actions"><form method="post"><input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>"><input type="hidden" name="draft_id" value="<?php echo $draftId; ?>"><input type="hidden" name="image_id" value="<?php echo (int) $image['id']; ?>"><button class="admin-danger-action" name="action" value="image_reject"><?php echo $imageVerified ? 'Wymień / odrzuć' : 'Odrzuć kandydata'; ?></button></form></div>
                    <details><summary>Ręczny upload / podmiana</summary><form method="post" enctype="multipart/form-data" class="proposal-upload"><input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>"><input type="hidden" name="action" value="image_upload"><input type="hidden" name="draft_id" value="<?php echo $draftId; ?>"><input type="hidden" name="image_id" value="<?php echo (int) $image['id']; ?>"><input type="file" name="image_file" accept="image/jpeg,image/png,image/webp" required><input name="author" placeholder="Autor" required><input name="source_page_url" type="url" placeholder="URL źródła" required><input name="license" placeholder="Licencja" required><input name="license_url" type="url" placeholder="URL licencji" required><input name="attribution" placeholder="Atrybucja" required><input name="alt" placeholder="Tekst alternatywny" required><input name="caption" placeholder="Podpis"><button>Wgraj z metadanymi</button></form></details>
                </article><?php endforeach; ?>
            </div></section>

            <section class="proposal-images"><h2>Odrzucone kandydatury Vision</h2>
                <p>Widoczne są wyłącznie kandydatury z zachowanym źródłem i prawami. Blokady prawne, techniczne oraz obrazy mylące nie mają ręcznego obejścia.</p>
                <?php if ((int) $rejectedImageReview['hard_rejected_count'] > 0): ?><p><strong>Odrzucone technicznie lub prawnie:</strong> <?php echo (int) $rejectedImageReview['hard_rejected_count']; ?></p><?php endif; ?>
                <?php if ($rejectedImageReview['items'] === []): ?><p>Brak legalnych kandydatur odrzuconych przez Vision do przeglądu.</p><?php else: ?><div class="proposal-image-grid">
                    <?php foreach ($rejectedImageReview['items'] as $review): $candidate = (array) $review['candidate']; $assessment = (array) $review['assessment']; $slot = (array) $review['slot']; ?>
                    <article class="proposal-image-card">
                        <?php if (str_starts_with((string) ($candidate['source_file_url'] ?? ''), 'https://')): ?><img src="<?php echo escape_html((string) $candidate['source_file_url']); ?>" alt="<?php echo escape_html((string) ($candidate['title'] ?? 'Odrzucona kandydatura')); ?>"><?php else: ?><div class="proposal-image-placeholder">Podgląd źródła niedostępny</div><?php endif; ?>
                        <h3><?php echo escape_html((string) ($slot['slot_id'] ?? '')); ?> · <?php echo escape_html((string) ($slot['role'] ?? '')); ?></h3>
                        <p><?php echo escape_html((string) ($assessment['reason'] ?? 'Odrzucone przez Vision.')); ?></p>
                        <p><strong>Relacja:</strong> <?php echo escape_html((string) ($assessment['relationship_level'] ?? 'unrelated')); ?><br><strong>Źródło tematu:</strong> <?php echo escape_html((string) ($candidate['provider'] ?? '')); ?><br><strong>Autor:</strong> <?php echo escape_html((string) ($candidate['author'] ?? '')); ?><br><strong>Licencja:</strong> <?php echo escape_html((string) ($candidate['license'] ?? '')); ?></p>
                        <p><a href="<?php echo escape_html((string) ($candidate['source_page_url'] ?? '')); ?>" rel="noopener">Strona źródłowa</a></p>
                        <?php if (!empty($review['manual_eligible'])): ?><form method="post"><input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>"><input type="hidden" name="action" value="image_manual_accept_rejected"><input type="hidden" name="draft_id" value="<?php echo $draftId; ?>"><input type="hidden" name="vision_audit_id" value="<?php echo (int) $review['audit']['id']; ?>"><button>Zaakceptuj ręcznie</button></form><?php else: ?><p><strong>Ręczna akceptacja niedostępna.</strong> Kandydat nie jest bezpośrednio związany z wymaganym slotem albo ma blokadę semantyczną.</p><?php endif; ?>
                    </article><?php endforeach; ?>
                </div><?php endif; ?>
            </section>

            <section class="proposal-research"><h2>Źródła twierdzeń i research</h2><p><strong>Decyzja polityki:</strong> <?php echo escape_html((string) ($researchPolicy['decision'] ?? 'brak')); ?> — <?php echo escape_html((string) ($researchPolicy['reason'] ?? 'Brak oceny.')); ?></p><?php if (!empty($researchPolicy['manual_single_source_allowed'])): ?><p>Dozwolone: jawne „kontynuuj z jednym zweryfikowanym źródłem”. Decyzja zostanie zapisana w audycie.</p><?php endif; ?><ul><?php foreach ((array) ($researchInput['numbered_sources'] ?? []) as $source): ?><li><a href="<?php echo escape_html((string) ($source['url'] ?? '')); ?>" rel="noopener"><?php echo escape_html((string) ($source['title'] ?? $source['source_id'] ?? 'Źródło')); ?></a> (<?php echo escape_html((string) ($source['source_kind'] ?? 'discovery')); ?><?php echo empty($source['peer_reviewed']) ? ', bez potwierdzonego peer review' : ', peer reviewed'; ?>)</li><?php endforeach; ?></ul><details><summary>Twierdzenia i przypisania</summary><pre><?php echo escape_html((string) json_encode($research['claims'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></pre></details></section>

            <section class="proposal-feedback" id="feedback-form"><h2>Uwagi do zmian</h2><p>Opisz w jednym miejscu poprawki tekstu, tytułu, układu i grafik. Zmienimy tylko wskazane elementy. Uwaga wyłącznie do grafiki nie uruchamia ponownego generowania tekstu.</p><form method="post" data-feedback-form><input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>"><input type="hidden" name="action" value="regenerate"><input type="hidden" name="draft_id" value="<?php echo $draftId; ?>"><label>Uwagi do zmian<textarea name="notes" rows="9" maxlength="5000" required data-feedback-notes placeholder="Np. zmień drugą grafikę na zdjęcie teleskopu, skróć wstęp"></textarea></label><p class="proposal-draft-saved" data-feedback-saved>Zapis szkicu uwag działa lokalnie.</p><button>Wygeneruj poprawioną wersję</button></form></section>

            <?php if ((string) $selected['status'] === 'prepared' || (string) $selected['generation_status'] === 'running'): ?><section class="proposal-progress" data-operation-status="<?php echo escape_html((string) $selected['generation_status']); ?>"><h2>Regeneracja w toku</h2><p>Status: <?php echo escape_html((string) $selected['generation_status']); ?>. Operacja jest zapisana — możesz wrócić po odświeżeniu.</p><?php if ((string) $selected['execution_mode'] === 'api' && (string) $selected['generation_status'] === 'prepared'): ?><form method="post"><input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>"><input type="hidden" name="action" value="execute_revision"><input type="hidden" name="draft_id" value="<?php echo $draftId; ?>"><input type="hidden" name="operation_id" value="<?php echo (int) $selected['generation_operation_id']; ?>"><button>Uruchom Gemini</button></form><?php endif; ?></section><?php endif; ?>

            <section class="proposal-versions"><h2>Historia wersji i porównanie</h2><div class="proposal-version-tabs"><?php foreach ($displayVersions as $version): ?><a href="admin-proposals.php?draft=<?php echo $draftId; ?>&amp;compare=<?php echo (int) $version['id']; ?>&amp;queue=<?php echo escape_html($proposalQueue); ?>">v<?php echo (int) $version['version_number']; ?> · <?php echo escape_html((string) $version['change_source']); ?><?php echo (int) $version['is_active'] === 1 ? ' · bieżąca' : ''; ?></a><?php endforeach; ?></div><?php if ($diff): ?><div class="proposal-diff"><article><h3>Starsza wersja</h3><h4><?php echo escape_html($diff['old_title']); ?></h4><p><?php echo escape_html($diff['old_lead']); ?></p><small><?php echo (int) $diff['old_length']; ?> znaków</small></article><article><h3>Nowa wersja</h3><h4><?php echo escape_html($diff['new_title']); ?></h4><p><?php echo escape_html($diff['new_lead']); ?></p><small><?php echo (int) $diff['new_length']; ?> znaków</small></article></div><p><strong>Zmienione elementy:</strong> <?php echo escape_html(implode(', ', $diff['changed_fields'])); ?></p><?php endif; ?><div class="proposal-version-list"><?php foreach ($displayVersions as $version): ?><article><strong>v<?php echo (int) $version['version_number']; ?></strong> · <?php echo escape_html((string) $version['created_at']); ?> · <?php echo escape_html((string) $version['change_source']); ?><?php if ((string) ($version['feedback_notes'] ?? '') !== ''): ?><blockquote><?php echo escape_html((string) $version['feedback_notes']); ?></blockquote><?php endif; ?><?php if ((int) $version['id'] !== $draftId): ?><form method="post"><input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>"><input type="hidden" name="action" value="activate"><input type="hidden" name="draft_id" value="<?php echo (int) $version['id']; ?>"><button>Uznaj za bieżącą</button></form><?php endif; ?></article><?php endforeach; ?></div></section>

            <section class="proposal-quality"><h2>Kontrola jakości</h2><p>Po każdej zmianie uruchom kontrolę ponownie dla pełnego artykułu.</p><form method="post"><input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>"><input type="hidden" name="action" value="quality_check"><input type="hidden" name="draft_id" value="<?php echo $draftId; ?>"><button>Uruchom ponowną kontrolę</button></form></section>

            <section class="proposal-publish"><h2>Decyzja końcowa</h2><p>Zapis i zatwierdzenie nie publikują. Publikacja jest osobną, potwierdzoną operacją.</p><div class="proposal-publish-grid"><form method="post"><input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>"><input type="hidden" name="action" value="publish"><input type="hidden" name="draft_id" value="<?php echo $draftId; ?>"><label>Wpisz PUBLISH, aby potwierdzić<input name="confirm_publish" autocomplete="off" required></label><button class="admin-danger-action" <?php echo $blockers !== [] ? 'disabled' : ''; ?>>Opublikuj teraz</button></form><form method="post"><input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>"><input type="hidden" name="action" value="schedule"><input type="hidden" name="draft_id" value="<?php echo $draftId; ?>"><label>Termin<input type="datetime-local" name="scheduled_at" required></label><button <?php echo $blockers !== [] ? 'disabled' : ''; ?>>Zaplanuj</button></form><form method="post"><input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>"><input type="hidden" name="action" value="reject"><input type="hidden" name="draft_id" value="<?php echo $draftId; ?>"><label>Powód odrzucenia<textarea name="reason" minlength="5" required></textarea></label><button class="admin-danger-action">Odrzuć</button></form></div></section>

            <details class="proposal-audit"><summary>Audyt decyzji, feedbacku i promptów</summary><h3>Feedback</h3><pre><?php echo escape_html((string) json_encode($feedback, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></pre><h3>Decyzje</h3><pre><?php echo escape_html((string) json_encode($audit, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></pre><h3>Prompt bieżącej wersji</h3><pre><?php echo escape_html((string) $selected['prompt_text']); ?></pre></details>
        <?php endif; ?>
        </main>
    </div>
</section>
<?php admin_page_close(); ?>
