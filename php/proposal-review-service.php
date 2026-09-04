<?php

declare(strict_types=1);

const ARTICLE_FEEDBACK_SCOPES = [
    'auto', 'article', 'titles', 'lead', 'section', 'style', 'images', 'caption_alt', 'other',
];

function proposal_infer_feedback_scope(string $notes): string
{
    $value = mb_strtolower($notes);
    $image = preg_match('/(grafik|ilustrac|zdję|obra[zź]|podpis|alt|licenc|źródł)/u', $value) === 1;
    $text = preg_match('/(tekst|tytuł|wstęp|lead|akapit|sekcj|skr[oó]ć|wydłuż|styl|ton|układ)/u', $value) === 1;
    return $image && !$text ? 'images' : 'article';
}

function proposal_json_decode(?string $json, array $fallback = []): array
{
    if ($json === null || trim($json) === '') return $fallback;
    $value = json_decode($json, true);
    return is_array($value) ? $value : $fallback;
}

function record_proposal_audit(int $postId, ?int $draftId, string $action, array $details = [], string $actor = 'admin'): int
{
    $statement = bueno_database()->prepare(
        'INSERT INTO article_proposal_audit (post_id, draft_version_id, action, actor, details_json)
         VALUES (:post_id, :draft_id, :action, :actor, :details)'
    );
    $statement->execute([
        ':post_id' => $postId,
        ':draft_id' => $draftId,
        ':action' => trim($action),
        ':actor' => trim($actor) ?: 'admin',
        ':details' => generation_json($details),
    ]);
    return (int) bueno_database()->lastInsertId();
}

/** Completed or QC-frozen drafts with a completed, current QC are reviewable regardless of its result or images. */
function list_article_proposals_for_review(?int $postId = null, int $limit = 100): array
{
    $where = $postId === null ? '' : ' AND drafts.post_id = :post_id';
    $statement = bueno_database()->prepare(
        'SELECT drafts.*, posts.title AS post_title, posts.status AS post_status,
                categories.title AS category_title, topics.title AS topic_title,
                checks.final_score, checks.passed AS quality_passed,
                checks.id AS quality_check_id, checks.hard_blocks_json, checks.status AS quality_status,
                checks.model_result_json, checks.deterministic_json,
                checks.human_review_status, checks.human_review_reason, checks.human_reviewed_at,
                (SELECT COUNT(*) FROM article_images images WHERE images.post_id = drafts.post_id) AS image_count,
                (SELECT COUNT(*) FROM article_images images WHERE images.post_id = drafts.post_id AND images.status = "downloaded") AS ready_image_count,
                (SELECT COUNT(*) FROM article_images images WHERE images.post_id = drafts.post_id AND images.status IN ("missing", "manual_review", "planned")) AS warning_image_count
         FROM article_draft_versions drafts
         INNER JOIN posts ON posts.id = drafts.post_id
         INNER JOIN post_categories categories ON categories.id = posts.category_id
         INNER JOIN editorial_topics topics ON topics.id = drafts.topic_id
         LEFT JOIN quality_check_runs checks ON checks.id = (
            SELECT id FROM quality_check_runs q WHERE q.draft_version_id = drafts.id ORDER BY q.id DESC LIMIT 1
         )
         WHERE drafts.status IN ("completed", "frozen")
           AND checks.status = "completed"
           AND drafts.id = COALESCE(
                (SELECT active.id FROM article_draft_versions active
                 WHERE active.post_id = drafts.post_id AND active.is_active = 1 ORDER BY active.id DESC LIMIT 1),
                (SELECT MAX(latest.id) FROM article_draft_versions latest
                 WHERE latest.post_id = drafts.post_id AND latest.status IN ("completed", "frozen", "failed"))
           )' . $where . '
         ORDER BY drafts.updated_at DESC, drafts.id DESC LIMIT :limit'
    );
    if ($postId !== null) $statement->bindValue(':post_id', $postId, PDO::PARAM_INT);
    $statement->bindValue(':limit', max(1, min(500, $limit)), PDO::PARAM_INT);
    $statement->execute();
    $proposals = $statement->fetchAll();
    foreach ($proposals as &$proposal) {
        $coverage = article_image_coverage_state((int) $proposal['post_id'], (int) $proposal['topic_id']);
        $proposal['image_count'] = count((array) ($coverage['required_slots'] ?? []));
        $proposal['ready_image_count'] = count((array) ($coverage['filled_slots'] ?? []));
        $proposal['warning_image_count'] = count((array) ($coverage['missing_slots'] ?? []));
    }
    unset($proposal);
    return $proposals;
}

/** Backwards-compatible name; publication readiness is evaluated separately. */
function list_ready_article_proposals(?int $postId = null, int $limit = 100): array
{
    return array_values(array_filter(
        list_article_proposals_for_review($postId, $limit),
        static fn (array $proposal): bool => proposal_queue_state($proposal) === 'ready'
    ));
}

function proposal_queue_state(array $proposal): string
{
    $topicId = (int) ($proposal['topic_id'] ?? 0);
    if ($topicId <= 0) return 'action';
    $status = generation_workflow_statuses([$topicId])[0] ?? [];
    $state = generation_workflow_queue_state($status);
    return $state === 'ready' ? 'ready' : 'action';
}

function list_action_required_proposals(?int $postId = null, int $limit = 100): array
{
    return array_values(array_filter(
        list_article_proposals_for_review($postId, $limit),
        static fn (array $proposal): bool => proposal_queue_state($proposal) === 'action'
    ));
}

function proposal_latest_quality_check(int $draftId): ?array
{
    $statement = bueno_database()->prepare(
        'SELECT q.* FROM quality_check_runs q
         LEFT JOIN generation_operations o ON o.id=q.generation_operation_id
         WHERE q.draft_version_id = :draft_id AND q.status = "completed"
         ORDER BY CASE WHEN q.execution_mode="api" AND o.live_request_count>0
                            AND o.provider_response_id<>""
                            AND o.provider_response_id NOT LIKE "deterministic-%"
                            AND o.provider_response_id NOT LIKE "resp_local_mock%" THEN 1 ELSE 0 END DESC,
                  q.id DESC LIMIT 1'
    );
    $statement->execute([':draft_id' => $draftId]);
    $check = $statement->fetch();
    return is_array($check) ? $check : null;
}

function proposal_reviewable_blocks(array $check): array
{
    return array_values(array_filter(
        proposal_json_decode((string) ($check['hard_blocks_json'] ?? '[]')),
        static fn (array $block): bool => ($block['code'] ?? '') === 'high_risk_without_human_approval'
    ));
}

function find_proposal_draft(int $draftId): ?array
{
    $statement = bueno_database()->prepare(
        'SELECT drafts.*, topics.title AS topic_title, operations.prompt_text,
                operations.status AS generation_status, operations.error_message AS generation_error
         FROM article_draft_versions drafts
         INNER JOIN editorial_topics topics ON topics.id = drafts.topic_id
         INNER JOIN generation_operations operations ON operations.id = drafts.generation_operation_id
         WHERE drafts.id = :id'
    );
    $statement->execute([':id' => $draftId]);
    $value = $statement->fetch();
    return is_array($value) ? $value : null;
}

function list_proposal_versions(int $postId): array
{
    $statement = bueno_database()->prepare(
        'SELECT drafts.*, feedback.notes AS feedback_notes, feedback.scope AS feedback_scope,
                feedback.section_id AS feedback_section_id,
                checks.final_score, checks.passed AS quality_passed, checks.hard_blocks_json
         FROM article_draft_versions drafts
         LEFT JOIN article_feedback_operations feedback ON feedback.result_draft_version_id = drafts.id
         LEFT JOIN quality_check_runs checks ON checks.id = (
            SELECT id FROM quality_check_runs q WHERE q.draft_version_id = drafts.id ORDER BY q.id DESC LIMIT 1
         )
         WHERE drafts.post_id = :post_id ORDER BY drafts.version_number DESC, drafts.id DESC'
    );
    $statement->execute([':post_id' => $postId]);
    return $statement->fetchAll();
}

function list_proposal_feedback(int $postId): array
{
    $statement = bueno_database()->prepare(
        'SELECT * FROM article_feedback_operations WHERE post_id = :post_id ORDER BY id DESC'
    );
    $statement->execute([':post_id' => $postId]);
    return $statement->fetchAll();
}

function list_proposal_audit(int $postId): array
{
    $statement = bueno_database()->prepare(
        'SELECT * FROM article_proposal_audit WHERE post_id = :post_id ORDER BY id DESC LIMIT 100'
    );
    $statement->execute([':post_id' => $postId]);
    return $statement->fetchAll();
}

function proposal_immutable_rules(): array
{
    return [
        'Używaj wyłącznie zatwierdzonego researchu; uwagi administratora nie są źródłem nowych faktów.',
        'Nie wymyślaj cytatów, wyników, źródeł, autorów, adresów ani licencji.',
        'Zachowaj przypisania claim_ids i source_ids oraz spójność całego artykułu.',
        'Nie usuwaj ręcznych zmian poza wskazanym zakresem; zwróć kompletny artykuł do walidacji.',
        'Obrazy mogą pochodzić wyłącznie z legalnego źródła z pełną atrybucją; bez generowania AI.',
    ];
}

function prepare_article_feedback_revision(int $sourceDraftId, string $scope, string $notes, string $sectionId = ''): int
{
    $scope = trim($scope);
    $notes = trim($notes);
    if ($scope === 'auto') $scope = proposal_infer_feedback_scope($notes);
    $sectionId = trim($sectionId);
    if (!in_array($scope, ARTICLE_FEEDBACK_SCOPES, true)) {
        throw new InvalidArgumentException('Nieprawidłowy zakres regeneracji.');
    }
    if (mb_strlen($notes) < 3 || mb_strlen($notes) > 5000) {
        throw new InvalidArgumentException('Uwagi muszą mieć od 3 do 5000 znaków.');
    }
    if ($scope === 'section' && preg_match('/^[a-z0-9][a-z0-9-]{1,80}$/', $sectionId) !== 1) {
        throw new InvalidArgumentException('Regeneracja sekcji wymaga jej identyfikatora.');
    }
    $source = find_proposal_draft($sourceDraftId);
    if ($source === null || (string) $source['status'] !== 'completed') {
        throw new RuntimeException('Regeneracja wymaga ukończonej wersji źródłowej.');
    }
    $package = find_research_package((int) $source['research_package_id']);
    if ($package === null || (string) $package['status'] !== 'approved') {
        throw new RuntimeException('Regeneracja wymaga nadal zatwierdzonego researchu.');
    }
    $research = proposal_json_decode((string) $package['package_json']);
    $researchInput = proposal_json_decode((string) $package['research_input_json']);
    $currentDraft = proposal_json_decode((string) $source['draft_json']);
    $sourceIds = array_values(array_filter(array_map(
        static fn(array $item): string => (string) ($item['source_id'] ?? ''),
        (array) ($researchInput['numbered_sources'] ?? [])
    )));
    $claimIds = array_values(array_filter(array_map(
        static fn(array $item): string => (string) ($item['claim_id'] ?? ''),
        (array) ($research['claims'] ?? [])
    )));
    $rules = proposal_immutable_rules();
    $input = [
        'revision_of_draft_version_id' => $sourceDraftId,
        'composition_mode' => (string) $source['composition_mode'],
        'research_package' => $research,
        'numbered_sources' => $researchInput['numbered_sources'] ?? [],
        'current_version' => $currentDraft,
        'administrator_feedback' => ['notes' => $notes, 'scope' => $scope, 'section_id' => $sectionId],
        'immutable_requirements' => $rules,
        'revision_instruction' => 'Zmień wyłącznie wskazany zakres, zachowaj pozostałe ręcznie zaakceptowane elementy i zwróć kompletny, spójny artykuł.',
    ];
    $database = bueno_database();
    $database->beginTransaction();
    try {
        $versionStatement = $database->prepare('SELECT COALESCE(MAX(version_number), 0) + 1 FROM article_draft_versions WHERE research_package_id = :id');
        $versionStatement->execute([':id' => (int) $source['research_package_id']]);
        $versionNumber = (int) $versionStatement->fetchColumn();
        $operationId = prepare_generation_operation(
            'article_draft',
            $input,
            article_draft_schema($sourceIds, $claimIds, (string) $source['composition_mode']),
            (int) $source['post_id'],
            (int) $source['topic_id']
        );
        $database->prepare(
            'INSERT INTO article_draft_versions (
                research_package_id, topic_id, post_id, generation_operation_id, version_number,
                composition_mode, execution_mode, parent_version_id, change_source, is_active
             ) VALUES (:package, :topic, :post, :operation, :version, :mode, :execution, :parent, "gemini_feedback", 0)'
        )->execute([
            ':package' => (int) $source['research_package_id'], ':topic' => (int) $source['topic_id'],
            ':post' => (int) $source['post_id'], ':operation' => $operationId, ':version' => $versionNumber,
            ':mode' => (string) $source['composition_mode'], ':execution' => generation_mode(), ':parent' => $sourceDraftId,
        ]);
        $resultDraftId = (int) $database->lastInsertId();
        $database->prepare(
            'INSERT INTO article_feedback_operations (
                post_id, source_draft_version_id, result_draft_version_id, generation_operation_id,
                scope, section_id, notes, immutable_rules_json
             ) VALUES (:post, :source, :result, :operation, :scope, :section, :notes, :rules)'
        )->execute([
            ':post' => (int) $source['post_id'], ':source' => $sourceDraftId, ':result' => $resultDraftId,
            ':operation' => $operationId, ':scope' => $scope, ':section' => $sectionId,
            ':notes' => $notes, ':rules' => generation_json($rules),
        ]);
        $database->commit();
        return $operationId;
    } catch (Throwable $exception) {
        if ($database->inTransaction()) $database->rollBack();
        throw $exception;
    }
}

/** Completes a feedback revision, makes the validated draft current, then continues through QC and legal images. */
function execute_article_feedback_pipeline(int $operationId, ?callable $transport = null): array
{
    $draftBefore = find_article_draft_by_operation($operationId);
    if (!is_array($draftBefore) || $draftBefore['parent_version_id'] === null) {
        throw new RuntimeException('Operacja nie jest poprawioną wersją artykułu.');
    }
    execute_generation_operation($operationId, $transport);
    $draft = find_article_draft_by_operation($operationId);
    $draftJson = is_array($draft) ? proposal_json_decode((string) ($draft['draft_json'] ?? '')) : [];
    if (!is_array($draft) || (string) $draft['status'] !== 'completed' || article_draft_main_content_length($draftJson) <= 0) {
        throw new RuntimeException('Gemini nie zwróciło kompletnej poprawionej wersji; poprzednia wersja pozostaje bieżąca.');
    }

    $postId = activate_proposal_version((int) $draft['id'], 'feedback_pipeline');
    $qualityOperationId = prepare_quality_check_operation((int) $draft['id']);
    execute_generation_operation($qualityOperationId, $transport);
    $quality = find_quality_check_by_operation($qualityOperationId);
    $qualityPassed = is_array($quality) && (int) $quality['passed'] === 1 && quality_active_hard_blocks($quality) === [];
    $imageSummary = null;
    if ($qualityPassed) {
        $imageSummary = (bool) app_config('source_image_mock')
            ? fulfill_article_source_images($postId, static fn (string $query): array => [], static fn (array $image): array => $image)
            : fulfill_article_source_images($postId);
    }
    record_proposal_audit($postId, (int) $draft['id'], 'feedback_pipeline_completed', [
        'quality_check_id' => is_array($quality) ? (int) $quality['id'] : null,
        'quality_passed' => $qualityPassed,
        'images_started' => $qualityPassed,
    ]);
    return ['draft' => $draft, 'quality' => $quality, 'quality_passed' => $qualityPassed, 'images' => $imageSummary];
}

/** Creates an image-only version without asking the text model to rewrite the article. */
function prepare_image_feedback_revision(int $sourceDraftId, string $notes): int
{
    $source = find_proposal_draft($sourceDraftId);
    if ($source === null || (string) $source['status'] !== 'completed') {
        throw new RuntimeException('Zmiana grafiki wymaga ukończonej wersji źródłowej.');
    }
    $notes = trim($notes);
    if (mb_strlen($notes) < 3 || mb_strlen($notes) > 5000) {
        throw new InvalidArgumentException('Uwagi muszą mieć od 3 do 5000 znaków.');
    }
    $draft = proposal_json_decode((string) $source['draft_json']);
    $operationId = prepare_generation_operation(
        'article_draft',
        ['revision_of_draft_version_id' => $sourceDraftId, 'administrator_feedback' => ['scope' => 'images', 'notes' => $notes], 'text_regenerated' => false],
        ['type' => 'object'],
        (int) $source['post_id'],
        (int) $source['topic_id']
    );
    $database = bueno_database();
    $database->beginTransaction();
    try {
        $version = $database->prepare('SELECT COALESCE(MAX(version_number), 0) + 1 FROM article_draft_versions WHERE research_package_id = :id');
        $version->execute([':id' => (int) $source['research_package_id']]);
        $database->prepare(
            'UPDATE generation_operations SET status = "completed", output_json = :output,
             provider = "local", model = "image-only-revision", completed_at = CURRENT_TIMESTAMP,
             updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        )->execute([':output' => generation_json($draft), ':id' => $operationId]);
        $database->prepare(
            'INSERT INTO article_draft_versions (
                research_package_id, topic_id, post_id, generation_operation_id, version_number,
                composition_mode, execution_mode, status, draft_json, parent_version_id,
                change_source, is_active, completed_at
             ) VALUES (:package, :topic, :post, :operation, :version, :mode, "local", "completed",
                :draft, :parent, "image_feedback", 0, CURRENT_TIMESTAMP)'
        )->execute([
            ':package' => (int) $source['research_package_id'], ':topic' => (int) $source['topic_id'],
            ':post' => (int) $source['post_id'], ':operation' => $operationId,
            ':version' => (int) $version->fetchColumn(), ':mode' => (string) $source['composition_mode'],
            ':draft' => generation_json($draft), ':parent' => $sourceDraftId,
        ]);
        $resultDraftId = (int) $database->lastInsertId();
        $database->prepare(
            'INSERT INTO article_feedback_operations (
                post_id, source_draft_version_id, result_draft_version_id, generation_operation_id,
                scope, notes, immutable_rules_json, completed_at
             ) VALUES (:post, :source, :result, :operation, "images", :notes, :rules, CURRENT_TIMESTAMP)'
        )->execute([
            ':post' => (int) $source['post_id'], ':source' => $sourceDraftId, ':result' => $resultDraftId,
            ':operation' => $operationId, ':notes' => $notes, ':rules' => generation_json(proposal_immutable_rules()),
        ]);
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) $database->rollBack();
        throw $exception;
    }

    $images = list_article_images((int) $source['post_id']);
    $targets = $images;
    if (preg_match('/(?:drug|2\.)/u', mb_strtolower($notes)) === 1 && isset($images[1])) $targets = [$images[1]];
    elseif (preg_match('/(?:pierwsz|1\.)/u', mb_strtolower($notes)) === 1 && isset($images[0])) $targets = [$images[0]];
    foreach ($targets as $image) reject_article_source_image((int) $image['id']);
    fulfill_article_source_images((int) $source['post_id']);
    record_proposal_audit((int) $source['post_id'], $resultDraftId, 'image_feedback_applied', [
        'notes' => $notes, 'image_ids' => array_map(static fn(array $image): int => (int) $image['id'], $targets),
        'text_regenerated' => false,
    ]);
    return $resultDraftId;
}

function activate_proposal_version(int $draftId, string $actor = 'admin'): int
{
    $draft = find_proposal_draft($draftId);
    if ($draft === null || (string) $draft['status'] !== 'completed') {
        throw new RuntimeException('Bieżącą może zostać tylko ukończona wersja.');
    }
    promote_article_draft_to_post($draftId);
    $database = bueno_database();
    $database->prepare('UPDATE article_draft_versions SET is_active = 0 WHERE post_id = :post')->execute([':post' => (int) $draft['post_id']]);
    $database->prepare('UPDATE article_draft_versions SET is_active = 1, updated_at = CURRENT_TIMESTAMP WHERE id = :id')->execute([':id' => $draftId]);
    record_proposal_audit((int) $draft['post_id'], $draftId, 'version_activated', ['version_number' => (int) $draft['version_number']], $actor);
    return (int) $draft['post_id'];
}

function upload_proposal_image(int $imageId, array $upload, array $metadata): int
{
    $statement = bueno_database()->prepare('SELECT * FROM article_images WHERE id = :id');
    $statement->execute([':id' => $imageId]);
    $image = $statement->fetch();
    if (!is_array($image)) throw new RuntimeException('Nie znaleziono obrazu.');
    foreach (['author', 'license', 'license_url', 'source_page_url', 'attribution', 'alt'] as $field) {
        if (trim((string) ($metadata[$field] ?? '')) === '') {
            throw new InvalidArgumentException('Ręczny obraz wymaga autora, źródła, licencji, linku licencyjnego, atrybucji i altu.');
        }
    }
    foreach (['license_url', 'source_page_url'] as $field) {
        if (!filter_var((string) $metadata[$field], FILTER_VALIDATE_URL)) throw new InvalidArgumentException('Podano nieprawidłowy adres URL.');
    }
    if ((int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || (int) ($upload['size'] ?? 0) > 25 * 1024 * 1024) {
        throw new InvalidArgumentException('Wybierz poprawny plik obrazu do 25 MB.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string) $upload['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($extensions[$mime])) throw new InvalidArgumentException('Dozwolone są pliki JPEG, PNG i WebP.');
    $directory = app_post_image_path('sources');
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) throw new RuntimeException('Nie można utworzyć katalogu obrazów.');
    $filename = 'manual-' . bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
    $absolute = $directory . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file((string) $upload['tmp_name'], $absolute)) throw new RuntimeException('Nie udało się zapisać obrazu.');
    $size = @getimagesize($absolute);
    if (!is_array($size)) { @unlink($absolute); throw new InvalidArgumentException('Plik nie jest poprawnym obrazem.'); }
    $localPath = 'images/posts/sources/' . $filename;
    bueno_database()->prepare(
        'UPDATE article_images SET local_path = :path, source_page_url = :source,
         source_file_url = :source, author = :author, license = :license,
         license_url = :license_url, attribution = :attribution, alt = :alt,
         caption = :caption, status = "downloaded", width = :width, height = :height,
         downloaded_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
    )->execute([
        ':path' => $localPath, ':source' => trim((string) $metadata['source_page_url']),
        ':author' => trim((string) $metadata['author']), ':license' => trim((string) $metadata['license']),
        ':license_url' => trim((string) $metadata['license_url']), ':attribution' => trim((string) $metadata['attribution']),
        ':alt' => trim((string) $metadata['alt']), ':caption' => trim((string) ($metadata['caption'] ?? '')),
        ':width' => (int) $size[0], ':height' => (int) $size[1], ':id' => $imageId,
    ]);
    refresh_article_image_rendering((int) $image['post_id']);
    record_proposal_audit((int) $image['post_id'], null, 'image_uploaded', ['image_id' => $imageId, 'license' => $metadata['license']]);
    return (int) $image['post_id'];
}

function proposal_image_blockers(int $postId): array
{
    $blockers = [];
    foreach (list_article_images($postId) as $image) {
        if ((string) $image['status'] !== 'downloaded') {
            $blockers[] = 'Slot „' . (string) $image['section_id'] . '”: brak zweryfikowanej grafiki (' . (string) $image['status'] . '). Uzupełnij lub wymień ją przed publikacją.';
            continue;
        }
        if (!article_image_license_is_auto_safe((string) ($image['license'] ?? ''))) {
            $blockers[] = 'Slot „' . (string) $image['section_id'] . '”: licencja „' . (string) ($image['license'] ?? '') . '” nie pozwala na automatyczne dopuszczenie. Publikacja jest zablokowana do czasu weryfikacji lub wymiany.';
            continue;
        }
        foreach (['source_page_url', 'author', 'license', 'license_url', 'attribution', 'alt'] as $field) {
            if (trim((string) ($image[$field] ?? '')) === '') {
                $blockers[] = 'Obraz „' . (string) $image['section_id'] . '” nie ma kompletu danych licencyjnych.';
                break;
            }
        }
    }
    return array_values(array_unique($blockers));
}

function proposal_publication_blockers(int $postId): array
{
    $blockers = proposal_image_blockers($postId);
    try { assert_post_quality_allows_publication($postId); }
    catch (Throwable $exception) { $blockers[] = $exception->getMessage(); }
    return array_values(array_unique($blockers));
}

function proposal_diff(array $older, array $newer): array
{
    $old = proposal_json_decode((string) ($older['draft_json'] ?? ''));
    $new = proposal_json_decode((string) ($newer['draft_json'] ?? ''));
    $changes = [];
    foreach (['title', 'brief', 'lead', 'why_important', 'key_facts', 'comparison_context', 'unknowns', 'narrative', 'practical_takeaway', 'illustration_plan'] as $field) {
        if (($old[$field] ?? null) !== ($new[$field] ?? null)) $changes[] = $field;
    }
    return [
        'old_title' => (string) ($old['title'] ?? ''), 'new_title' => (string) ($new['title'] ?? ''),
        'old_lead' => (string) (($old['lead']['text'] ?? '')), 'new_lead' => (string) (($new['lead']['text'] ?? '')),
        'old_length' => article_draft_main_content_length($old), 'new_length' => article_draft_main_content_length($new),
        'changed_fields' => $changes,
    ];
}
