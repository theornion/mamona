<?php

declare(strict_types=1);

require_once __DIR__ . '/admin-auth.php';
require_once __DIR__ . '/admin-database.php';
require_once __DIR__ . '/admin-ui.php';

require_admin_login();

$error = '';
$notice = '';
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
                $operationId = prepare_article_feedback_revision(
                    $draftId,
                    (string) ($_POST['scope'] ?? ''),
                    (string) ($_POST['notes'] ?? ''),
                    (string) ($_POST['section_id'] ?? '')
                );
                $newDraft = find_article_draft_by_operation($operationId);
                header('Location: admin-proposals.php?draft=' . (int) ($newDraft['id'] ?? $draftId) . '&prepared=' . $operationId, true, 303);
                exit;
            }
            if ($action === 'execute_revision') {
                $operationId = filter_input(INPUT_POST, 'operation_id', FILTER_VALIDATE_INT) ?: 0;
                execute_generation_operation($operationId);
                $newDraft = find_article_draft_by_operation($operationId);
                header('Location: admin-proposals.php?draft=' . (int) ($newDraft['id'] ?? $draftId) . '&generated=1', true, 303);
                exit;
            }
            if ($action === 'quality_check') {
                $operationId = prepare_quality_check_operation($draftId);
                if (generation_mode() === 'api') execute_generation_operation($operationId);
                header('Location: admin-proposals.php?draft=' . $draftId . '&quality=' . $operationId, true, 303);
                exit;
            }
            if ($action === 'execute_quality') {
                execute_generation_operation((int) ($_POST['operation_id'] ?? 0));
                header('Location: admin-proposals.php?draft=' . $draftId . '&quality_done=1', true, 303);
                exit;
            }
            if ($action === 'activate') {
                $postId = activate_proposal_version($draftId);
                header('Location: admin-proposals.php?draft=' . $draftId . '&activated=1', true, 303);
                exit;
            }
            if ($draft === null) throw new RuntimeException('Nie znaleziono propozycji.');
            $postId = (int) $draft['post_id'];
            if ($action === 'approve') {
                activate_proposal_version($draftId);
                change_post_editorial_status($postId, 'review', 'Zatwierdzono do publikacji na ekranie propozycji.', 'admin');
                record_proposal_audit($postId, $draftId, 'approved_for_publication');
                $notice = 'Materiał zatwierdzono do publikacji. Nie został opublikowany.';
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
            } elseif ($action === 'image_upload') {
                upload_proposal_image((int) ($_POST['image_id'] ?? 0), $_FILES['image_file'] ?? [], $_POST);
                $notice = 'Ręczny obraz zapisano wraz z licencją i atrybucją.';
            }
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
    }
}

$proposals = list_ready_article_proposals();
if ($batchId > 0) {
    $batchDraftIds = array_values(array_filter(array_map(static fn(array $item): int => (int) ($item['draft_version_id'] ?? 0), generation_batch_item_rows($batchId))));
    $proposals = array_values(array_filter($proposals, static fn(array $item): bool => in_array((int) $item['id'], $batchDraftIds, true)));
}
if ($draftId <= 0 && $proposals !== []) $draftId = (int) $proposals[0]['id'];
$selected = $draftId > 0 ? find_proposal_draft($draftId) : null;
$post = $selected ? find_post((int) $selected['post_id'], true) : null;
$draftData = $selected ? proposal_json_decode((string) $selected['draft_json']) : [];
$versions = $post ? list_proposal_versions((int) $post['id']) : [];
$images = $post ? list_article_images((int) $post['id']) : [];
$feedback = $post ? list_proposal_feedback((int) $post['id']) : [];
$audit = $post ? list_proposal_audit((int) $post['id']) : [];
$package = $selected ? find_research_package((int) $selected['research_package_id']) : null;
$research = $package ? proposal_json_decode((string) $package['package_json']) : [];
$researchInput = $package ? proposal_json_decode((string) $package['research_input_json']) : [];
$blockers = $post ? proposal_publication_blockers((int) $post['id']) : [];
$compareId = filter_input(INPUT_GET, 'compare', FILTER_VALIDATE_INT) ?: 0;
$completedComparisons = array_values(array_filter(
    $versions,
    static fn(array $version): bool => (string) $version['status'] === 'completed' && (int) $version['id'] !== $draftId
));
$compare = $compareId > 0 ? find_proposal_draft($compareId) : ($completedComparisons[0] ?? null);
$diff = $selected && is_array($compare) ? proposal_diff($compare, $selected) : null;

admin_page_open('Gotowe propozycje', 'proposals');
?>
<section class="post admin-card proposal-review" data-feedback-storage="proposal-feedback-<?php echo (int) ($post['id'] ?? 0); ?>">
    <header class="proposal-review__header">
        <div><p class="proposal-kicker">Studio treści</p><h1>Gotowe propozycje</h1><p>Niepubliczne materiały do świadomej decyzji redakcyjnej.</p></div>
        <?php if ($batchId > 0): ?><a class="button" href="admin-content-studio.php?batch=<?php echo $batchId; ?>">Wróć do batcha #<?php echo $batchId; ?></a><?php endif; ?>
    </header>
    <?php if ($error !== ''): ?><p class="admin-notice is-error" role="alert"><?php echo escape_html($error); ?></p><?php endif; ?>
    <?php if ($notice !== ''): ?><p class="admin-notice is-success" role="status"><?php echo escape_html($notice); ?></p><?php endif; ?>

    <div class="proposal-layout">
        <aside class="proposal-list" aria-label="Lista propozycji">
            <?php if ($proposals === []): ?><p class="admin-notice">Brak gotowych materiałów<?php echo $batchId ? ' w tym batchu' : ''; ?>.</p><?php endif; ?>
            <?php foreach ($proposals as $proposal): $data = proposal_json_decode((string) $proposal['draft_json']); $warnings = proposal_json_decode((string) ($proposal['hard_blocks_json'] ?? '[]')); ?>
                <a class="proposal-card<?php echo (int) $proposal['id'] === $draftId ? ' is-active' : ''; ?>" href="admin-proposals.php?<?php echo $batchId ? 'batch=' . $batchId . '&amp;' : ''; ?>draft=<?php echo (int) $proposal['id']; ?>">
                    <span class="proposal-card__thumb" aria-hidden="true"><?php echo (int) $proposal['ready_image_count']; ?>/<?php echo (int) $proposal['image_count']; ?> zdjęć</span>
                    <strong><?php echo escape_html((string) ($data['title'] ?? $proposal['topic_title'])); ?></strong>
                    <span><?php echo escape_html((string) $proposal['category_title']); ?> · <?php echo article_draft_main_content_length($data); ?> znaków</span>
                    <span class="proposal-score">Jakość: <?php echo $proposal['final_score'] === null ? 'oczekuje' : (int) $proposal['final_score'] . '/100'; ?></span>
                    <?php if ((int) $proposal['warning_image_count'] > 0 || $warnings !== []): ?><small>⚠ Wymaga uwagi: <?php echo count($warnings) + (int) $proposal['warning_image_count']; ?></small><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </aside>

        <main class="proposal-detail">
        <?php if ($selected && $post): ?>
            <div class="proposal-statebar"><strong>NIEPUBLICZNY PODGLĄD</strong><span>Wersja <?php echo (int) $selected['version_number']; ?> · <?php echo escape_html((string) $selected['status']); ?> · <?php echo escape_html((string) $post['status']); ?></span></div>
            <div class="proposal-actions">
                <form method="post"><input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>"><input type="hidden" name="action" value="approve"><input type="hidden" name="draft_id" value="<?php echo $draftId; ?>"><button>Zatwierdź do publikacji</button></form>
                <a class="button" href="admin-post-editor.php?post=<?php echo (int) $post['id']; ?>">Edytuj ręcznie</a>
                <a class="button" href="#feedback-form">Wygeneruj poprawioną wersję</a>
            </div>

            <?php if ($blockers !== []): ?><div class="proposal-blockers"><strong>Blokady publikacji</strong><ul><?php foreach ($blockers as $blocker): ?><li><?php echo escape_html($blocker); ?></li><?php endforeach; ?></ul></div><?php endif; ?>

            <section class="proposal-metadata">
                <div><h2><?php echo escape_html((string) ($draftData['title'] ?? $post['title'])); ?></h2><p><?php echo escape_html((string) ($draftData['brief'] ?? $post['excerpt'])); ?></p></div>
                <details><summary>Alternatywne tytuły</summary><ol><?php foreach ((array) ($draftData['title_variants'] ?? []) as $variant): ?><li><?php echo escape_html((string) ($variant['title'] ?? '')); ?> <small><?php echo (int) ($variant['total_score'] ?? 0); ?>/50</small></li><?php endforeach; ?></ol></details>
            </section>

            <section class="proposal-preview"><div class="proposal-section-heading"><div><p class="proposal-kicker">Ten sam renderer co publikacja</p><h2>Pełny podgląd</h2></div><a href="admin-post-preview.php?post=<?php echo (int) $post['id']; ?>" target="_blank" rel="noopener">Otwórz osobno</a></div><iframe title="Niepubliczny podgląd artykułu" src="admin-post-preview.php?post=<?php echo (int) $post['id']; ?>"></iframe></section>

            <section class="proposal-images"><h2>Obrazy i licencje</h2><div class="proposal-image-grid">
                <?php foreach ($images as $image): ?><article class="proposal-image-card">
                    <?php if ((string) $image['local_path'] !== '' && is_file(app_path((string) $image['local_path']))): ?><img src="../<?php echo escape_html((string) $image['local_path']); ?>" alt="<?php echo escape_html((string) $image['alt']); ?>"><?php else: ?><div class="proposal-image-placeholder">Brak obrazu</div><?php endif; ?>
                    <h3><?php echo escape_html((string) $image['role']); ?> · <?php echo escape_html((string) $image['section_id']); ?></h3>
                    <p><?php echo escape_html((string) $image['caption']); ?></p><p><strong>Autor:</strong> <?php echo escape_html((string) $image['author']); ?><br><strong>Źródło:</strong> <?php echo escape_html((string) $image['source_page_url']); ?><br><strong>Licencja:</strong> <?php echo escape_html((string) $image['license']); ?> <?php if ((string) $image['license_url'] !== ''): ?><a href="<?php echo escape_html((string) $image['license_url']); ?>" rel="license noopener">link</a><?php endif; ?></p>
                    <div class="proposal-actions"><form method="post"><input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>"><input type="hidden" name="draft_id" value="<?php echo $draftId; ?>"><input type="hidden" name="image_id" value="<?php echo (int) $image['id']; ?>"><button name="action" value="image_accept">Akceptuj</button><button class="admin-danger-action" name="action" value="image_reject">Odrzuć</button></form></div>
                    <details><summary>Ręczny upload / podmiana</summary><form method="post" enctype="multipart/form-data" class="proposal-upload"><input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>"><input type="hidden" name="action" value="image_upload"><input type="hidden" name="draft_id" value="<?php echo $draftId; ?>"><input type="hidden" name="image_id" value="<?php echo (int) $image['id']; ?>"><input type="file" name="image_file" accept="image/jpeg,image/png,image/webp" required><input name="author" placeholder="Autor" required><input name="source_page_url" type="url" placeholder="URL źródła" required><input name="license" placeholder="Licencja" required><input name="license_url" type="url" placeholder="URL licencji" required><input name="attribution" placeholder="Atrybucja" required><input name="alt" placeholder="Tekst alternatywny" required><input name="caption" placeholder="Podpis"><button>Wgraj z metadanymi</button></form></details>
                </article><?php endforeach; ?>
            </div></section>

            <section class="proposal-research"><h2>Źródła twierdzeń i research</h2><ul><?php foreach ((array) ($researchInput['numbered_sources'] ?? []) as $source): ?><li><a href="<?php echo escape_html((string) ($source['url'] ?? '')); ?>" rel="noopener"><?php echo escape_html((string) ($source['title'] ?? $source['source_id'] ?? 'Źródło')); ?></a></li><?php endforeach; ?></ul><details><summary>Twierdzenia i przypisania</summary><pre><?php echo escape_html((string) json_encode($research['claims'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></pre></details></section>

            <section class="proposal-feedback" id="feedback-form"><h2>Uwagi dla Gemini</h2><p>Nowa wersja zastąpi wskazany zakres, ale obecna wersja i ręczne zmiany pozostaną w historii. Uwagi nie są zgodą na dodawanie faktów ani łamanie licencji.</p><form method="post" data-feedback-form><input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>"><input type="hidden" name="action" value="regenerate"><input type="hidden" name="draft_id" value="<?php echo $draftId; ?>"><label>Zakres<select name="scope" data-feedback-scope><option value="article">Cały artykuł</option><option value="titles">Tytuł i alternatywy</option><option value="lead">Lead</option><option value="section">Konkretna sekcja</option><option value="style">Ton / styl / długość</option><option value="images">Dobór lub pozycja grafik</option><option value="caption_alt">Podpis / alt</option><option value="other">Inny element</option></select></label><label data-section-field hidden>Identyfikator sekcji<input name="section_id" placeholder="np. why-important"></label><label>Dowolne wskazówki<textarea name="notes" rows="9" maxlength="5000" required data-feedback-notes placeholder="Np. skróć lead, zachowaj trzy ręcznie poprawione fakty i wyjaśnij mechanizm prostszym językiem…"></textarea></label><p class="proposal-draft-saved" data-feedback-saved>Zapis szkicu uwag działa lokalnie.</p><button>Utwórz nową wersję</button></form></section>

            <?php if ((string) $selected['status'] === 'prepared' || (string) $selected['generation_status'] === 'running'): ?><section class="proposal-progress" data-operation-status="<?php echo escape_html((string) $selected['generation_status']); ?>"><h2>Regeneracja w toku</h2><p>Status: <?php echo escape_html((string) $selected['generation_status']); ?>. Operacja jest zapisana — możesz wrócić po odświeżeniu.</p><?php if ((string) $selected['execution_mode'] === 'api' && (string) $selected['generation_status'] === 'prepared'): ?><form method="post"><input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>"><input type="hidden" name="action" value="execute_revision"><input type="hidden" name="draft_id" value="<?php echo $draftId; ?>"><input type="hidden" name="operation_id" value="<?php echo (int) $selected['generation_operation_id']; ?>"><button>Uruchom Gemini</button></form><?php endif; ?></section><?php endif; ?>

            <section class="proposal-versions"><h2>Historia wersji i porównanie</h2><div class="proposal-version-tabs"><?php foreach ($versions as $version): ?><a href="admin-proposals.php?draft=<?php echo $draftId; ?>&amp;compare=<?php echo (int) $version['id']; ?>">v<?php echo (int) $version['version_number']; ?> · <?php echo escape_html((string) $version['change_source']); ?><?php echo (int) $version['is_active'] === 1 ? ' · bieżąca' : ''; ?></a><?php endforeach; ?></div><?php if ($diff): ?><div class="proposal-diff"><article><h3>Starsza wersja</h3><h4><?php echo escape_html($diff['old_title']); ?></h4><p><?php echo escape_html($diff['old_lead']); ?></p><small><?php echo (int) $diff['old_length']; ?> znaków</small></article><article><h3>Nowa wersja</h3><h4><?php echo escape_html($diff['new_title']); ?></h4><p><?php echo escape_html($diff['new_lead']); ?></p><small><?php echo (int) $diff['new_length']; ?> znaków</small></article></div><p><strong>Zmienione elementy:</strong> <?php echo escape_html(implode(', ', $diff['changed_fields'])); ?></p><?php endif; ?><div class="proposal-version-list"><?php foreach ($versions as $version): ?><article><strong>v<?php echo (int) $version['version_number']; ?></strong> · <?php echo escape_html((string) $version['created_at']); ?> · <?php echo escape_html((string) $version['change_source']); ?><?php if ((string) ($version['feedback_notes'] ?? '') !== ''): ?><blockquote><?php echo escape_html((string) $version['feedback_notes']); ?></blockquote><?php endif; ?><?php if ((int) $version['id'] !== $draftId): ?><form method="post"><input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>"><input type="hidden" name="action" value="activate"><input type="hidden" name="draft_id" value="<?php echo (int) $version['id']; ?>"><button>Uznaj za bieżącą</button></form><?php endif; ?></article><?php endforeach; ?></div></section>

            <section class="proposal-quality"><h2>Kontrola jakości</h2><p>Po każdej zmianie uruchom kontrolę ponownie dla pełnego artykułu.</p><form method="post"><input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>"><input type="hidden" name="action" value="quality_check"><input type="hidden" name="draft_id" value="<?php echo $draftId; ?>"><button>Uruchom ponowną kontrolę</button></form></section>

            <section class="proposal-publish"><h2>Decyzja końcowa</h2><p>Zapis i zatwierdzenie nie publikują. Publikacja jest osobną, potwierdzoną operacją.</p><div class="proposal-publish-grid"><form method="post"><input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>"><input type="hidden" name="action" value="publish"><input type="hidden" name="draft_id" value="<?php echo $draftId; ?>"><label>Wpisz PUBLISH, aby potwierdzić<input name="confirm_publish" autocomplete="off" required></label><button class="admin-danger-action" <?php echo $blockers !== [] ? 'disabled' : ''; ?>>Opublikuj teraz</button></form><form method="post"><input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>"><input type="hidden" name="action" value="schedule"><input type="hidden" name="draft_id" value="<?php echo $draftId; ?>"><label>Termin<input type="datetime-local" name="scheduled_at" required></label><button <?php echo $blockers !== [] ? 'disabled' : ''; ?>>Zaplanuj</button></form><form method="post"><input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>"><input type="hidden" name="action" value="reject"><input type="hidden" name="draft_id" value="<?php echo $draftId; ?>"><label>Powód odrzucenia<textarea name="reason" minlength="5" required></textarea></label><button class="admin-danger-action">Odrzuć</button></form></div></section>

            <details class="proposal-audit"><summary>Audyt decyzji, feedbacku i promptów</summary><h3>Feedback</h3><pre><?php echo escape_html((string) json_encode($feedback, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></pre><h3>Decyzje</h3><pre><?php echo escape_html((string) json_encode($audit, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></pre><h3>Prompt bieżącej wersji</h3><pre><?php echo escape_html((string) $selected['prompt_text']); ?></pre></details>
        <?php endif; ?>
        </main>
    </div>
</section>
<?php admin_page_close(); ?>
