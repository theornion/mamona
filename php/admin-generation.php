<?php

declare(strict_types=1);

require_once __DIR__ . '/admin-auth.php';
require_once __DIR__ . '/admin-database.php';
require_once __DIR__ . '/admin-ui.php';

require_admin_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_valid_csrf()) {
        $_SESSION['generation_error'] = 'Sesja formularza wygasła. Odśwież stronę.';
        header('Location: admin-generation.php?error=1', true, 303);
        exit;
    }
    try {
        $action = trim((string) ($_POST['action'] ?? ''));
        if ($action === 'change_mode') {
            update_generation_mode((string) ($_POST['generation_mode'] ?? ''));
        } elseif ($action === 'prepare_operation') {
            $operationId = prepare_research_package_operation(
                filter_input(INPUT_POST, 'topic_id', FILTER_VALIDATE_INT) ?: 0
            );
            header('Location: admin-generation.php?prepared=' . $operationId, true, 303);
            exit;
        } elseif ($action === 'approve_research') {
            approve_research_package(
                filter_input(INPUT_POST, 'research_package_id', FILTER_VALIDATE_INT) ?: 0
            );
        } elseif ($action === 'prepare_draft') {
            $operationId = prepare_article_draft_operation(
                filter_input(INPUT_POST, 'research_package_id', FILTER_VALIDATE_INT) ?: 0,
                trim((string) ($_POST['composition_mode'] ?? ''))
            );
            header('Location: admin-generation.php?prepared=' . $operationId, true, 303);
            exit;
        } elseif ($action === 'prepare_quality_check') {
            $operationId = prepare_quality_check_operation(
                filter_input(INPUT_POST, 'draft_version_id', FILTER_VALIDATE_INT) ?: 0
            );
            header('Location: admin-generation.php?prepared=' . $operationId, true, 303);
            exit;
        } elseif ($action === 'review_quality_risk') {
            review_quality_risk(
                filter_input(INPUT_POST, 'quality_check_id', FILTER_VALIDATE_INT) ?: 0,
                trim((string) ($_POST['review_decision'] ?? '')),
                trim((string) ($_POST['review_reason'] ?? ''))
            );
        } elseif ($action === 'prepare_thumbnail') {
            $thumbnailId = prepare_thumbnail_version(
                filter_input(INPUT_POST, 'draft_version_id', FILTER_VALIDATE_INT) ?: 0
            );
            if (generation_mode() === 'api') {
                execute_thumbnail_api($thumbnailId);
            }
            header('Location: admin-generation.php?thumbnail=' . $thumbnailId, true, 303);
            exit;
        } elseif ($action === 'upload_thumbnail') {
            complete_manual_thumbnail_upload(
                filter_input(INPUT_POST, 'thumbnail_id', FILTER_VALIDATE_INT) ?: 0,
                $_FILES['thumbnail_file'] ?? [],
                trim((string) ($_POST['image_model'] ?? ''))
            );
        } elseif ($action === 'execute_thumbnail_api') {
            execute_thumbnail_api(
                filter_input(INPUT_POST, 'thumbnail_id', FILTER_VALIDATE_INT) ?: 0
            );
        } elseif ($action === 'reject_thumbnail') {
            reject_thumbnail_version(
                filter_input(INPUT_POST, 'thumbnail_id', FILTER_VALIDATE_INT) ?: 0,
                trim((string) ($_POST['rejection_reason'] ?? ''))
            );
        } elseif ($action === 'import_manual') {
            $operationId = filter_input(INPUT_POST, 'operation_id', FILTER_VALIDATE_INT) ?: 0;
            $response = trim((string) ($_POST['response_json'] ?? ''));
            if (isset($_FILES['response_file']) && (int) $_FILES['response_file']['error'] !== UPLOAD_ERR_NO_FILE) {
                if ((int) $_FILES['response_file']['error'] !== UPLOAD_ERR_OK) {
                    throw new RuntimeException('Nie udało się przesłać pliku odpowiedzi.');
                }
                if ((int) $_FILES['response_file']['size'] > GENERATION_RESPONSE_MAX_BYTES) {
                    throw new InvalidArgumentException('Plik odpowiedzi przekracza limit 2 MB.');
                }
                $uploaded = file_get_contents((string) $_FILES['response_file']['tmp_name']);
                if (!is_string($uploaded)) {
                    throw new RuntimeException('Nie można odczytać przesłanego pliku.');
                }
                $response = $uploaded;
            }
            if ($response === '') {
                throw new InvalidArgumentException('Wklej odpowiedź JSON albo wybierz plik.');
            }
            import_manual_generation_response($operationId, $response);
        } elseif ($action === 'execute_api') {
            execute_generation_operation(
                filter_input(INPUT_POST, 'operation_id', FILTER_VALIDATE_INT) ?: 0
            );
        } else {
            throw new InvalidArgumentException('Nieprawidłowa akcja.');
        }
        header('Location: admin-generation.php?saved=1', true, 303);
    } catch (Throwable $exception) {
        $_SESSION['generation_error'] = $exception->getMessage();
        header('Location: admin-generation.php?error=1', true, 303);
    }
    exit;
}

$mode = generation_mode();
$operations = list_generation_operations();
$topics = list_editorial_topics(500);
$researchPackages = [];
foreach (list_research_packages(500) as $researchPackage) {
    $researchPackages[(int) $researchPackage['generation_operation_id']] = $researchPackage;
}
$approvedResearchPackages = list_approved_research_packages();
$draftVersions = [];
foreach (list_article_draft_versions(500) as $draftVersion) {
    $draftVersions[(int) $draftVersion['generation_operation_id']] = $draftVersion;
}
$completedDrafts = list_completed_article_drafts();
$qualityChecks = [];
foreach (list_quality_checks(500) as $qualityCheck) {
    $qualityChecks[(int) $qualityCheck['generation_operation_id']] = $qualityCheck;
}
$thumbnailEligibleDrafts = list_thumbnail_eligible_drafts();
$thumbnailVersions = list_thumbnail_versions();
$error = (string) ($_SESSION['generation_error'] ?? '');
unset($_SESSION['generation_error']);

admin_page_open('Generowanie manualne i API', 'generation');
?>
<section class="post admin-card technical-sources-page">
    <header class="major admin-heading">
        <p class="admin-kicker">Gemini API / import ręczny</p>
        <h1>Generowanie treści</h1>
        <p>Jeden rygorystycznie walidowany format JSON dla pracy ręcznej i Gemini API Free Tier.</p>
    </header>

    <?php if ($error !== ''): ?><p class="admin-notice is-error" role="alert"><?php echo escape_html($error); ?></p><?php endif; ?>
    <?php if (isset($_GET['saved'])): ?><p class="admin-notice is-success" role="status">Operacja została zapisana.</p><?php endif; ?>
    <?php if (isset($_GET['prepared'])): ?><p class="admin-notice is-success" role="status">Przygotowano operację #<?php echo (int) $_GET['prepared']; ?>.</p><?php endif; ?>

    <section class="technical-source-card">
        <h2>Centralny tryb generowania</h2>
        <form method="post" action="admin-generation.php">
            <input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>">
            <input type="hidden" name="action" value="change_mode">
            <label for="generation-mode">Tryb dla nowych operacji</label>
            <select id="generation-mode" name="generation_mode">
                <option value="manual"<?php echo $mode === 'manual' ? ' selected' : ''; ?>>manual — kopiowanie do ChatGPT Plus</option>
                <option value="api"<?php echo $mode === 'api' ? ' selected' : ''; ?>>api — <?php echo escape_html((string) app_config('generation_provider')); ?> API</option>
            </select>
            <button type="submit">Zapisz tryb</button>
        </form>
        <p><strong>Aktualnie: <?php echo escape_html($mode); ?></strong></p>
        <?php if (app_config('generation_provider') === 'gemini'): ?>
            <p><strong>Model aktywny:</strong> <code><?php echo escape_html((string) app_config('gemini_model')); ?></code>
                · fallback: <code><?php echo escape_html(implode(', ', (array) app_config('gemini_model_fallbacks')) ?: 'brak'); ?></code>
                · limiter: <?php echo (int) app_config('gemini_rpm_target'); ?> RPM, concurrency 1.</p>
        <?php endif; ?>
        <p>W trybie manual prompt i odpowiedź JSON trzeba przenieść ręcznie. Brak klucza API nie blokuje tego trybu.</p>
        <?php $apiKeyName = app_config('generation_provider') === 'gemini' ? 'GEMINI_API_KEY' : 'OPENAI_API_KEY'; ?>
        <?php $apiMock = (bool) app_config(app_config('generation_provider') === 'gemini' ? 'gemini_mock' : 'openai_mock'); ?>
        <?php if ($mode === 'api' && app_environment_value($apiKeyName) === null && !$apiMock): ?>
            <p class="admin-notice is-error">Tryb API jest wybrany, ale brakuje <code><?php echo escape_html($apiKeyName); ?></code> w środowisku serwera.</p>
        <?php endif; ?>
        <?php if ($apiMock): ?><p class="admin-notice">Aktywna jest lokalna atrapa API — nie zostaną naliczone koszty.</p><?php endif; ?>
        <?php if (!(bool) app_config('ai_image_generation_enabled')): ?><p class="admin-notice">Generowanie obrazów AI jest wyłączone. System zapisuje pominięcie; użyj legalnej grafiki źródłowej albo ręcznego uploadu.</p><?php endif; ?>
    </section>

    <section class="technical-source-card">
        <h2>Przygotuj paczkę researchową</h2>
        <p>Źródła tematu zostaną ponumerowane, a wynik sprawdzony pod kątem przypisania faktów, dosłownych dowodów, sprzeczności i jakości materiału.</p>
        <form method="post" action="admin-generation.php">
            <input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>">
            <input type="hidden" name="action" value="prepare_operation">
            <label for="topic-id">Temat</label>
            <select id="topic-id" name="topic_id" required>
                <?php foreach ($topics as $topic): ?>
                    <option value="<?php echo (int) $topic['id']; ?>">
                        <?php echo (int) ($topic['score'] ?? 0); ?>/100 — <?php echo escape_html((string) $topic['title']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit">Przygotuj research</button>
        </form>
    </section>

    <section class="technical-source-card">
        <h2>Przygotuj wersję szkicu</h2>
        <p>Szkic może powstać wyłącznie z ukończonej paczki, którą administrator zatwierdził poniżej. Nowa operacja tworzy kolejną wersję i nie zmienia treści ani statusu publikacyjnego artykułu.</p>
        <?php if ($approvedResearchPackages === []): ?>
            <p class="admin-notice">Brak zatwierdzonego researchu z rekomendacją kontynuacji.</p>
        <?php else: ?>
            <form method="post" action="admin-generation.php">
                <input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>">
                <input type="hidden" name="action" value="prepare_draft">
                <label for="research-package-id">Zatwierdzona paczka</label>
                <select id="research-package-id" name="research_package_id" required>
                    <?php foreach ($approvedResearchPackages as $package): ?>
                        <option value="<?php echo (int) $package['id']; ?>">
                            Research #<?php echo (int) $package['id']; ?> — <?php echo escape_html((string) $package['topic_title']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <label for="composition-mode">Tryb kompozycji</label>
                <select id="composition-mode" name="composition_mode" required>
                    <option value="informational">informational — prostszy temat, 3000–7000 znaków</option>
                    <option value="problem_discovery_return">problem_discovery_return — szersza analiza z tematem B, 4000–7000 znaków</option>
                </select>
                <button type="submit">Przygotuj szkic</button>
            </form>
        <?php endif; ?>
    </section>

    <section class="technical-source-card">
        <h2>Uruchom kontrolę jakości</h2>
        <p>Każde uruchomienie zapisuje osobny wynik. Prompt zawiera szkic, zatwierdzony research i źródła; blokady aplikacyjne są liczone niezależnie od odpowiedzi modelu.</p>
        <?php if ($completedDrafts === []): ?>
            <p class="admin-notice">Brak ukończonego szkicu do sprawdzenia.</p>
        <?php else: ?>
            <form method="post" action="admin-generation.php">
                <input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>">
                <input type="hidden" name="action" value="prepare_quality_check">
                <label for="quality-draft-id">Wersja szkicu</label>
                <select id="quality-draft-id" name="draft_version_id" required>
                    <?php foreach ($completedDrafts as $draft): ?>
                        <option value="<?php echo (int) $draft['id']; ?>">
                            Szkic v<?php echo (int) $draft['version_number']; ?> ·
                            <?php echo escape_html((string) $draft['composition_mode']); ?> ·
                            kontrole: <?php echo (int) $draft['check_count']; ?> —
                            <?php echo escape_html((string) $draft['topic_title']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit">Przygotuj kontrolę jakości</button>
            </form>
        <?php endif; ?>
    </section>

    <section class="technical-source-card">
        <h2>Przygotuj miniaturę źródłową</h2>
        <p>Miniatura może powstać wyłącznie dla szkicu z zaliczoną kontrolą. Generowanie AI jest domyślnie wyłączone; wybierz legalny obraz źródłowy albo wgraj własny.</p>
        <?php if ($thumbnailEligibleDrafts === []): ?>
            <p class="admin-notice">Brak szkicu spełniającego warunki generowania obrazu.</p>
        <?php else: ?>
            <form method="post" action="admin-generation.php">
                <input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>">
                <input type="hidden" name="action" value="prepare_thumbnail">
                <label for="thumbnail-draft-id">Zaliczone wersje szkiców</label>
                <select id="thumbnail-draft-id" name="draft_version_id" required>
                    <?php foreach ($thumbnailEligibleDrafts as $draft): ?>
                        <option value="<?php echo (int) $draft['id']; ?>">
                            Szkic v<?php echo (int) $draft['version_number']; ?> ·
                            <?php echo escape_html((string) $draft['composition_mode']); ?> —
                            <?php echo escape_html((string) $draft['topic_title']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit">Przygotuj miejsce na miniaturę</button>
            </form>
        <?php endif; ?>
    </section>

    <h2>Wersje miniatur (<?php echo count($thumbnailVersions); ?>)</h2>
    <div class="technical-source-list">
        <?php if ($thumbnailVersions === []): ?><p class="admin-notice">Nie przygotowano jeszcze żadnej miniatury.</p><?php endif; ?>
        <?php foreach ($thumbnailVersions as $thumbnail): ?>
            <article class="technical-source-card"<?php echo (int) ($_GET['thumbnail'] ?? 0) === (int) $thumbnail['id'] ? ' id="prepared-thumbnail"' : ''; ?>>
                <header>
                    <div>
                        <span class="editorial-status"><?php echo escape_html((string) $thumbnail['execution_mode']); ?> · <?php echo escape_html((string) $thumbnail['status']); ?></span>
                        <h3>Miniatura v<?php echo (int) $thumbnail['version_number']; ?> · szkic v<?php echo (int) $thumbnail['draft_version_number']; ?></h3>
                    </div>
                </header>
                <p><?php echo escape_html((string) $thumbnail['topic_title']); ?> · model: <?php echo escape_html((string) ($thumbnail['model'] ?: 'podawany przy uploadzie')); ?></p>
                <p><strong>Alt:</strong> <?php echo escape_html((string) $thumbnail['alt_text']); ?></p>
                <?php if ((string) $thumbnail['error_message'] !== ''): ?><p class="editorial-error"><?php echo escape_html((string) $thumbnail['error_message']); ?></p><?php endif; ?>
                <details<?php echo (int) ($_GET['thumbnail'] ?? 0) === (int) $thumbnail['id'] ? ' open' : ''; ?>>
                    <summary>Prompt do generatora</summary>
                    <textarea id="thumbnail-prompt-<?php echo (int) $thumbnail['id']; ?>" rows="14" readonly><?php echo escape_html((string) $thumbnail['prompt_text']); ?></textarea>
                    <button type="button" data-copy-target="thumbnail-prompt-<?php echo (int) $thumbnail['id']; ?>">Kopiuj prompt</button>
                </details>

                <?php if (in_array((string) $thumbnail['status'], ['prepared', 'skipped'], true)
                    && ($thumbnail['execution_mode'] === 'manual' || !(bool) app_config('ai_image_generation_enabled'))): ?>
                    <form method="post" action="admin-generation.php" enctype="multipart/form-data">
                        <input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>">
                        <input type="hidden" name="action" value="upload_thumbnail">
                        <input type="hidden" name="thumbnail_id" value="<?php echo (int) $thumbnail['id']; ?>">
                        <label>Model użyty w ChatGPT/generatorze (opcjonalnie)
                            <input type="text" name="image_model" maxlength="100" placeholder="np. GPT Image">
                        </label>
                        <label>Oryginalny obraz JPEG, PNG lub WebP, maks. 25 MB
                            <input type="file" name="thumbnail_file" accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp" required>
                        </label>
                        <button type="submit">Zweryfikuj, wykadruj i zapisz WebP</button>
                    </form>
                <?php elseif ($thumbnail['status'] === 'prepared' && $thumbnail['execution_mode'] === 'api'
                    && (bool) app_config('ai_image_generation_enabled')): ?>
                    <form method="post" action="admin-generation.php">
                        <input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>">
                        <input type="hidden" name="action" value="execute_thumbnail_api">
                        <input type="hidden" name="thumbnail_id" value="<?php echo (int) $thumbnail['id']; ?>">
                        <button type="submit">Ponów Images API</button>
                    </form>
                <?php endif; ?>

                <?php if ($thumbnail['status'] === 'completed'): ?>
                    <figure>
                        <img src="../<?php echo escape_html((string) $thumbnail['public_path']); ?>" alt="<?php echo escape_html((string) $thumbnail['alt_text']); ?>" loading="lazy">
                        <figcaption>
                            <?php echo (int) $thumbnail['public_width']; ?>×<?php echo (int) $thumbnail['public_height']; ?> WebP ·
                            oryginał <?php echo (int) $thumbnail['original_width']; ?>×<?php echo (int) $thumbnail['original_height']; ?> ·
                            <?php echo escape_html((string) $thumbnail['generated_at']); ?>
                        </figcaption>
                    </figure>
                    <form method="post" action="admin-generation.php">
                        <input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>">
                        <input type="hidden" name="action" value="reject_thumbnail">
                        <input type="hidden" name="thumbnail_id" value="<?php echo (int) $thumbnail['id']; ?>">
                        <label>Powód odrzucenia
                            <textarea name="rejection_reason" rows="3" minlength="5" maxlength="1000" required></textarea>
                        </label>
                        <button type="submit" class="admin-danger-action">Odrzuć tę wersję</button>
                    </form>
                <?php elseif ($thumbnail['status'] === 'rejected'): ?>
                    <p><strong>Powód odrzucenia:</strong> <?php echo escape_html((string) $thumbnail['rejection_reason']); ?></p>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>

    <h2>Operacje (<?php echo count($operations); ?>)</h2>
    <div class="technical-source-list">
        <?php if ($operations === []): ?><p class="admin-notice">Nie przygotowano jeszcze żadnej operacji.</p><?php endif; ?>
        <?php foreach ($operations as $operation): ?>
            <?php $researchPackage = $researchPackages[(int) $operation['id']] ?? null; ?>
            <?php $draftVersion = $draftVersions[(int) $operation['id']] ?? null; ?>
            <?php $qualityCheck = $qualityChecks[(int) $operation['id']] ?? null; ?>
            <article class="technical-source-card">
                <header>
                    <div>
                        <span class="editorial-status"><?php echo escape_html((string) $operation['execution_mode']); ?> · <?php echo escape_html((string) $operation['status']); ?></span>
                        <h3>#<?php echo (int) $operation['id']; ?> — <?php echo escape_html((string) $operation['operation_type']); ?></h3>
                    </div>
                </header>
                <p><?php echo escape_html((string) ($operation['topic_title'] ?? 'Bez tematu')); ?> · model: <?php echo escape_html((string) ($operation['model'] ?: 'ChatGPT ręcznie')); ?> · próby: <?php echo (int) $operation['attempt_count']; ?></p>
                <?php if (is_array($researchPackage)): ?>
                    <p><strong>Osobna paczka researchowa:</strong> <?php echo escape_html((string) $researchPackage['status']); ?></p>
                    <?php if ($researchPackage['status'] === 'completed'): ?>
                        <form method="post" action="admin-generation.php">
                            <input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>">
                            <input type="hidden" name="action" value="approve_research">
                            <input type="hidden" name="research_package_id" value="<?php echo (int) $researchPackage['id']; ?>">
                            <button type="submit">Zatwierdź research do szkicu</button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if (is_array($draftVersion)): ?>
                    <p><strong>Szkic v<?php echo (int) $draftVersion['version_number']; ?>:</strong>
                        <?php echo escape_html((string) $draftVersion['composition_mode']); ?> ·
                        <?php echo escape_html((string) $draftVersion['status']); ?>
                    </p>
                <?php endif; ?>
                <?php if (is_array($qualityCheck)): ?>
                    <p><strong>Kontrola #<?php echo (int) $qualityCheck['check_number']; ?>
                        szkicu v<?php echo (int) $qualityCheck['draft_version_number']; ?>:</strong>
                        <?php echo $qualityCheck['final_score'] !== null ? (int) $qualityCheck['final_score'] . '/100' : escape_html((string) $qualityCheck['status']); ?> ·
                        <?php echo (int) $qualityCheck['passed'] === 1 ? 'zaliczona' : 'niezaliczona'; ?> ·
                        <?php echo escape_html((string) $qualityCheck['execution_mode']); ?>
                    </p>
                <?php endif; ?>
                <?php if (trim((string) $operation['error_message']) !== ''): ?><p class="editorial-error"><?php echo escape_html((string) $operation['error_message']); ?></p><?php endif; ?>

                <details<?php echo (int) ($_GET['prepared'] ?? 0) === (int) $operation['id'] ? ' open' : ''; ?>>
                    <summary>Prompt i eksport</summary>
                    <textarea id="generation-prompt-<?php echo (int) $operation['id']; ?>" rows="16" readonly><?php echo escape_html((string) $operation['prompt_text']); ?></textarea>
                    <div class="editorial-action-row">
                        <button type="button" data-copy-target="generation-prompt-<?php echo (int) $operation['id']; ?>">Kopiuj prompt</button>
                        <a class="button" href="admin-generation-export.php?operation=<?php echo (int) $operation['id']; ?>&amp;format=txt">Eksport TXT</a>
                        <a class="button" href="admin-generation-export.php?operation=<?php echo (int) $operation['id']; ?>&amp;format=json">Eksport JSON</a>
                    </div>
                </details>

                <?php if ($operation['status'] !== 'completed'
                    && ($operation['execution_mode'] === 'manual' || $operation['status'] === 'failed')): ?>
                    <form method="post" action="admin-generation.php" enctype="multipart/form-data">
                        <input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>">
                        <input type="hidden" name="action" value="import_manual">
                        <input type="hidden" name="operation_id" value="<?php echo (int) $operation['id']; ?>">
                        <label for="response-<?php echo (int) $operation['id']; ?>">Odpowiedź JSON z narzędzia wybranego ręcznie</label>
                        <textarea id="response-<?php echo (int) $operation['id']; ?>" name="response_json" rows="8"></textarea>
                        <label>Albo import pliku JSON/TXT (maks. 2 MB)<input type="file" name="response_file" accept=".json,.txt,application/json,text/plain"></label>
                        <button type="submit">Waliduj i zapisz odpowiedź ręczną</button>
                    </form>
                <?php elseif ($operation['status'] !== 'completed' && $operation['execution_mode'] === 'api'): ?>
                    <form method="post" action="admin-generation.php">
                        <input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>">
                        <input type="hidden" name="action" value="execute_api">
                        <input type="hidden" name="operation_id" value="<?php echo (int) $operation['id']; ?>">
                        <button type="submit">Wykonaj przez <?php echo escape_html((string) $operation['provider']); ?> API</button>
                    </form>
                <?php endif; ?>

                <?php if ($operation['status'] === 'completed'): ?>
                    <details>
                        <summary>Zwalidowany wynik i użycie</summary>
                        <pre><?php echo escape_html((string) json_encode(
                            json_decode((string) $operation['output_json'], true),
                            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                        )); ?></pre>
                        <p>Response ID: <?php echo escape_html((string) ($operation['provider_response_id'] ?: 'manual')); ?></p>
                        <pre><?php echo escape_html((string) $operation['usage_json']); ?></pre>
                    </details>
                <?php endif; ?>

                <?php if (is_array($draftVersion) && $draftVersion['status'] === 'completed'): ?>
                    <?php $sourceResearch = find_research_package((int) $draftVersion['research_package_id']); ?>
                    <details>
                        <summary>Porównaj szkic v<?php echo (int) $draftVersion['version_number']; ?> z paczką faktów</summary>
                        <h4>Szkic</h4>
                        <pre><?php echo escape_html((string) json_encode(
                            json_decode((string) $draftVersion['draft_json'], true),
                            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                        )); ?></pre>
                        <h4>Zatwierdzony research #<?php echo (int) $draftVersion['research_package_id']; ?></h4>
                        <pre><?php echo escape_html((string) json_encode(
                            json_decode((string) ($sourceResearch['package_json'] ?? '{}'), true),
                            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                        )); ?></pre>
                    </details>
                <?php endif; ?>

                <?php if (is_array($qualityCheck) && $qualityCheck['status'] === 'completed'): ?>
                    <?php $activeBlocks = quality_active_hard_blocks($qualityCheck); ?>
                    <details open>
                        <summary>Wynik kontroli jakości i blokady</summary>
                        <p><strong>Wynik końcowy: <?php echo (int) $qualityCheck['final_score']; ?>/100.</strong></p>
                        <?php if ($activeBlocks === []): ?>
                            <p class="admin-notice is-success">Brak aktywnych twardych blokad.</p>
                        <?php else: ?>
                            <div class="admin-notice is-error">
                                <strong>Aktywne blokady publikacji:</strong>
                                <ul>
                                    <?php foreach ($activeBlocks as $block): ?>
                                        <li><?php echo escape_html((string) $block['message']); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                        <pre><?php echo escape_html((string) json_encode(
                            json_decode((string) $qualityCheck['validation_json'], true),
                            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                        )); ?></pre>
                    </details>
                    <?php
                    $reviewableRisk = array_filter(
                        json_decode((string) $qualityCheck['hard_blocks_json'], true) ?: [],
                        static fn (array $block): bool => ($block['code'] ?? '') === 'high_risk_without_human_approval'
                    );
                    ?>
                    <?php if ($reviewableRisk !== []): ?>
                        <form method="post" action="admin-generation.php">
                            <input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>">
                            <input type="hidden" name="action" value="review_quality_risk">
                            <input type="hidden" name="quality_check_id" value="<?php echo (int) $qualityCheck['id']; ?>">
                            <label for="review-decision-<?php echo (int) $qualityCheck['id']; ?>">Decyzja człowieka</label>
                            <select id="review-decision-<?php echo (int) $qualityCheck['id']; ?>" name="review_decision" required>
                                <option value="approved">Zatwierdź ryzyko po weryfikacji</option>
                                <option value="rejected">Odrzuć materiał</option>
                            </select>
                            <label for="review-reason-<?php echo (int) $qualityCheck['id']; ?>">Obowiązkowe uzasadnienie</label>
                            <textarea id="review-reason-<?php echo (int) $qualityCheck['id']; ?>" name="review_reason" rows="4" minlength="10" maxlength="1000" required></textarea>
                            <button type="submit">Zapisz decyzję z uzasadnieniem</button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
</section>
<?php admin_page_close(); ?>
