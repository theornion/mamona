<?php

declare(strict_types=1);

const THUMBNAIL_MAX_SOURCE_BYTES = 26214400;
const THUMBNAIL_PUBLIC_WIDTH = 1280;
const THUMBNAIL_PUBLIC_HEIGHT = 720;

function thumbnail_validate_alt(string $alt): string
{
    $alt = trim($alt);
    if ($alt === '' || mb_strlen($alt) > 250) {
        throw new InvalidArgumentException('Alt miniatury musi mieć od 1 do 250 znaków.');
    }
    if (preg_match('/^(obraz|zdjęcie|grafika|ilustracja)\s+(przedstawia|pokazuje)\b/iu', $alt) === 1) {
        throw new InvalidArgumentException('Alt powinien opisywać zawartość bez wstępu „obraz przedstawia”.');
    }

    return $alt;
}

function thumbnail_quality_check_for_draft(int $draftVersionId): array
{
    $draft = find_quality_draft_context($draftVersionId);
    if ($draft === null || $draft['status'] !== 'completed') {
        throw new RuntimeException('Miniaturę można przygotować wyłącznie dla ukończonego szkicu.');
    }
    $statement = bueno_database()->prepare(
        'SELECT * FROM quality_check_runs
         WHERE draft_version_id = :draft_id AND status = "completed"
         ORDER BY id DESC LIMIT 1'
    );
    $statement->execute([':draft_id' => $draftVersionId]);
    $check = $statement->fetch();
    if (!is_array($check) || (int) $check['passed'] !== 1 || quality_active_hard_blocks($check) !== []) {
        throw new RuntimeException('Szkic nie ma zaliczonej kontroli jakości bez twardych blokad.');
    }

    return [$draft, $check];
}

function list_thumbnail_eligible_drafts(int $limit = 500): array
{
    $eligible = [];
    foreach (list_completed_article_drafts($limit) as $draft) {
        try {
            thumbnail_quality_check_for_draft((int) $draft['id']);
            $eligible[] = $draft;
        } catch (Throwable) {
            continue;
        }
    }

    return $eligible;
}

function build_thumbnail_prompt(array $draft, array $research): string
{
    $title = trim((string) ($draft['title'] ?? ''));
    $summary = trim((string) ($research['event_summary']['text'] ?? ''));
    $claims = array_values(array_filter(array_map(
        static fn (array $claim): string => trim((string) ($claim['claim'] ?? '')),
        array_slice((array) ($research['claims'] ?? []), 0, 5)
    )));

    return "Stwórz reprezentatywną, realistyczną ilustrację redakcyjną do artykułu popularnonaukowego.\n"
        . "Tytuł artykułu: {$title}\n"
        . "Potwierdzone wydarzenie: {$summary}\n"
        . "Potwierdzone fakty wizualne i kontekst: " . implode(' | ', $claims) . "\n"
        . "Kompozycja: pozioma 16:9, najważniejszy element w centralnych 60% kadru, "
        . "bez istotnych detali przy krawędziach, czytelna jako mała miniatura na telefonie.\n"
        . "Styl: wiarygodna fotografia lub dopracowana ilustracja naukowa, naturalne światło, "
        . "jeden wyraźny temat, bez sensacyjnej przesady.\n"
        . "Bezwzględne ograniczenia: bez tekstu i liter na obrazie, bez znaków wodnych, "
        . "bez logotypów sugerujących oficjalną grafikę, bez fałszywego interfejsu lub zrzutu ekranu, "
        . "bez wizerunku rozpoznawalnej prawdziwej osoby, bez przedstawiania niepotwierdzonego produktu "
        . "jako istniejącego. Nie dodawaj elementów, których nie uzasadniają przekazane fakty.";
}

function prepare_thumbnail_version(int $draftVersionId): int
{
    [$context, $check] = thumbnail_quality_check_for_draft($draftVersionId);
    $draft = json_decode((string) $context['draft_json'], true, 128, JSON_THROW_ON_ERROR);
    $research = json_decode((string) $context['research_json'], true, 128, JSON_THROW_ON_ERROR);
    $alt = thumbnail_validate_alt((string) ($draft['image_alt'] ?? ''));
    $mode = generation_mode();
    $database = bueno_database();
    $database->beginTransaction();
    try {
        $numberStatement = $database->prepare(
            'SELECT COALESCE(MAX(version_number), 0) + 1
             FROM thumbnail_versions WHERE draft_version_id = :draft_id'
        );
        $numberStatement->execute([':draft_id' => $draftVersionId]);
        $versionNumber = (int) $numberStatement->fetchColumn();
        $database->prepare(
            'INSERT INTO thumbnail_versions (
                draft_version_id, quality_check_id, post_id, version_number,
                execution_mode, prompt_text, model, alt_text
             ) VALUES (
                :draft_id, :check_id, :post_id, :version_number,
                :execution_mode, :prompt_text, :model, :alt_text
             )'
        )->execute([
            ':draft_id' => $draftVersionId,
            ':check_id' => (int) $check['id'],
            ':post_id' => (int) $context['post_id'],
            ':version_number' => $versionNumber,
            ':execution_mode' => $mode,
            ':prompt_text' => build_thumbnail_prompt($draft, $research),
            ':model' => $mode === 'api' ? (string) app_config('openai_image_model') : '',
            ':alt_text' => $alt,
        ]);
        $thumbnailId = (int) $database->lastInsertId();
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        throw $exception;
    }

    return $thumbnailId;
}

function find_thumbnail_version(int $thumbnailId): ?array
{
    $statement = bueno_database()->prepare('SELECT * FROM thumbnail_versions WHERE id = :id');
    $statement->execute([':id' => $thumbnailId]);
    $thumbnail = $statement->fetch();

    return is_array($thumbnail) ? $thumbnail : null;
}

function list_thumbnail_versions(int $limit = 500): array
{
    $statement = bueno_database()->prepare(
        'SELECT thumbnails.*, drafts.version_number AS draft_version_number,
                drafts.composition_mode, topics.title AS topic_title
         FROM thumbnail_versions AS thumbnails
         INNER JOIN article_draft_versions AS drafts ON drafts.id = thumbnails.draft_version_id
         INNER JOIN editorial_topics AS topics ON topics.id = drafts.topic_id
         ORDER BY thumbnails.id DESC LIMIT :limit'
    );
    $statement->bindValue(':limit', max(1, min(500, $limit)), PDO::PARAM_INT);
    $statement->execute();

    return $statement->fetchAll();
}

function thumbnail_detect_mime(string $bytes): array
{
    if ($bytes === '' || strlen($bytes) > THUMBNAIL_MAX_SOURCE_BYTES) {
        throw new InvalidArgumentException('Obraz jest pusty albo przekracza limit 25 MB.');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->buffer($bytes);
    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    if (!isset($extensions[$mime])) {
        throw new InvalidArgumentException('Dozwolone są wyłącznie obrazy JPEG, PNG i WebP.');
    }

    return [$mime, $extensions[$mime]];
}

function thumbnail_process_with_gd(string $sourcePath, string $targetPath): array
{
    $bytes = file_get_contents($sourcePath);
    $source = is_string($bytes) && function_exists('imagecreatefromstring') ? @imagecreatefromstring($bytes) : false;
    if ($source === false || !function_exists('imagewebp')) {
        throw new RuntimeException('PHP GD nie obsługuje przetwarzania obrazu do WebP.');
    }
    $width = imagesx($source);
    $height = imagesy($source);
    if ($width < THUMBNAIL_PUBLIC_WIDTH || $height < THUMBNAIL_PUBLIC_HEIGHT) {
        imagedestroy($source);
        throw new InvalidArgumentException('Oryginał musi mieć co najmniej 1280×720 px.');
    }
    $targetRatio = 16 / 9;
    $sourceRatio = $width / $height;
    if ($sourceRatio > $targetRatio) {
        $cropHeight = $height;
        $cropWidth = (int) round($height * $targetRatio);
        $sourceX = (int) floor(($width - $cropWidth) / 2);
        $sourceY = 0;
    } else {
        $cropWidth = $width;
        $cropHeight = (int) round($width / $targetRatio);
        $sourceX = 0;
        $sourceY = (int) floor(($height - $cropHeight) / 2);
    }
    $target = imagecreatetruecolor(THUMBNAIL_PUBLIC_WIDTH, THUMBNAIL_PUBLIC_HEIGHT);
    imagecopyresampled(
        $target,
        $source,
        0,
        0,
        $sourceX,
        $sourceY,
        THUMBNAIL_PUBLIC_WIDTH,
        THUMBNAIL_PUBLIC_HEIGHT,
        $cropWidth,
        $cropHeight
    );
    $success = imagewebp($target, $targetPath, 85);
    imagedestroy($target);
    imagedestroy($source);
    if (!$success) {
        throw new RuntimeException('Nie udało się zapisać wariantu WebP.');
    }

    return [
        'original_width' => $width,
        'original_height' => $height,
        'public_width' => THUMBNAIL_PUBLIC_WIDTH,
        'public_height' => THUMBNAIL_PUBLIC_HEIGHT,
    ];
}

function thumbnail_process_image(string $sourcePath, string $targetPath): array
{
    if (extension_loaded('gd') && function_exists('imagewebp')) {
        return thumbnail_process_with_gd($sourcePath, $targetPath);
    }
    $python = trim((string) app_config('image_processor_python'));
    if ($python === '' || !is_file($python)) {
        throw new RuntimeException(
            'Brakuje procesora obrazów. Włącz PHP GD z WebP albo ustaw CMS_IMAGE_PROCESSOR_PYTHON na Python z Pillow.'
        );
    }
    $command = [$python, app_path('scripts/process-thumbnail.py'), $sourcePath, $targetPath];
    $pipes = [];
    $process = proc_open($command, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, app_project_root(), null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('Nie można uruchomić procesora miniatur.');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    $result = json_decode((string) $stdout, true);
    if ($exitCode !== 0 || !is_array($result) || ($result['ok'] ?? false) !== true) {
        $message = is_array($result) ? (string) ($result['error'] ?? '') : trim((string) $stderr);
        throw new InvalidArgumentException($message !== '' ? $message : 'Procesor odrzucił obraz.');
    }

    return $result;
}

function complete_thumbnail_from_bytes(
    int $thumbnailId,
    string $bytes,
    string $model = '',
    array $providerMetadata = []
): array {
    $thumbnail = find_thumbnail_version($thumbnailId);
    if ($thumbnail === null || !in_array($thumbnail['status'], ['prepared', 'running', 'skipped'], true)) {
        throw new RuntimeException('Miniatura nie jest gotowa na przyjęcie obrazu.');
    }
    [$mime, $extension] = thumbnail_detect_mime($bytes);
    $originalDirectory = app_path('data/thumbnails/originals');
    $publicDirectory = app_post_image_path('thumbnails');
    foreach ([$originalDirectory, $publicDirectory] as $directory) {
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Nie można utworzyć katalogu miniatur.');
        }
    }
    $token = bin2hex(random_bytes(8));
    $originalRelative = 'data/thumbnails/originals/thumbnail-' . $thumbnailId . '-' . $token . '.' . $extension;
    $publicRelative = app_post_image_directory() . '/thumbnails/thumbnail-'
        . (int) $thumbnail['post_id'] . '-' . $thumbnailId . '-' . $token . '.webp';
    $originalPath = app_path($originalRelative);
    $publicPath = app_path($publicRelative);
    $temporaryOriginal = $originalPath . '.tmp';
    $temporaryPublic = $publicPath . '.tmp';
    try {
        if (file_put_contents($temporaryOriginal, $bytes, LOCK_EX) !== strlen($bytes)
            || !rename($temporaryOriginal, $originalPath)) {
            throw new RuntimeException('Nie udało się zapisać oryginału miniatury.');
        }
        $dimensions = thumbnail_process_image($originalPath, $temporaryPublic);
        $publicInfo = @getimagesize($temporaryPublic);
        if (
            !is_array($publicInfo)
            || (int) $publicInfo[0] < 1200
            || abs(((int) $publicInfo[0] / (int) $publicInfo[1]) - (16 / 9)) > 0.01
            || (string) ($publicInfo['mime'] ?? '') !== 'image/webp'
        ) {
            throw new RuntimeException('Publiczny wariant nie spełnia wymagań 16:9, 1200 px i WebP.');
        }
        if (!rename($temporaryPublic, $publicPath)) {
            throw new RuntimeException('Nie udało się aktywować publicznego wariantu miniatury.');
        }
        $post = find_post((int) $thumbnail['post_id'], true);
        if ($post === null) {
            throw new RuntimeException('Nie znaleziono artykułu miniatury.');
        }
        $database = bueno_database();
        $database->beginTransaction();
        try {
            $database->prepare(
                'UPDATE thumbnail_versions SET is_active = 0
                 WHERE post_id = :post_id AND is_active = 1'
            )->execute([':post_id' => (int) $thumbnail['post_id']]);
            $database->prepare(
                'UPDATE thumbnail_versions
                 SET status = "completed", is_active = 1, model = :model,
                     previous_image_path = :previous_image_path,
                     previous_alt_text = :previous_alt_text,
                     original_path = :original_path, public_path = :public_path,
                     original_mime = :original_mime,
                     original_width = :original_width, original_height = :original_height,
                     public_width = :public_width, public_height = :public_height,
                     provider_response_id = :response_id, usage_json = :usage_json,
                     error_message = "", generated_at = CURRENT_TIMESTAMP,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            )->execute([
                ':model' => mb_substr(trim($model), 0, 100),
                ':previous_image_path' => (string) ($post['image_path'] ?? ''),
                ':previous_alt_text' => (string) ($post['image_alt'] ?? ''),
                ':original_path' => $originalRelative,
                ':public_path' => $publicRelative,
                ':original_mime' => $mime,
                ':original_width' => (int) $dimensions['original_width'],
                ':original_height' => (int) $dimensions['original_height'],
                ':public_width' => (int) $publicInfo[0],
                ':public_height' => (int) $publicInfo[1],
                ':response_id' => mb_substr(trim((string) ($providerMetadata['response_id'] ?? '')), 0, 200),
                ':usage_json' => generation_json(is_array($providerMetadata['usage'] ?? null) ? $providerMetadata['usage'] : []),
                ':id' => $thumbnailId,
            ]);
            $database->prepare(
                'UPDATE posts SET image_path = :image_path, image_alt = :image_alt,
                 updated_at = CURRENT_TIMESTAMP WHERE id = :post_id'
            )->execute([
                ':image_path' => $publicRelative,
                ':image_alt' => (string) $thumbnail['alt_text'],
                ':post_id' => (int) $thumbnail['post_id'],
            ]);
            $database->commit();
        } catch (Throwable $exception) {
            if ($database->inTransaction()) {
                $database->rollBack();
            }
            throw $exception;
        }
    } catch (Throwable $exception) {
        foreach ([$temporaryOriginal, $temporaryPublic, $originalPath, $publicPath] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        throw $exception;
    }

    return find_thumbnail_version($thumbnailId) ?? throw new RuntimeException('Nie zapisano miniatury.');
}

function complete_manual_thumbnail_upload(int $thumbnailId, array $upload, string $model = ''): array
{
    $thumbnail = find_thumbnail_version($thumbnailId);
    if ($thumbnail === null
        || ($thumbnail['execution_mode'] !== 'manual'
            && !(!(bool) app_config('ai_image_generation_enabled')
                && in_array((string) $thumbnail['status'], ['prepared', 'skipped'], true)))) {
        throw new InvalidArgumentException('Ta miniatura nie została przygotowana w trybie manual.');
    }
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
        || !is_uploaded_file((string) ($upload['tmp_name'] ?? ''))) {
        throw new InvalidArgumentException('Wybierz poprawnie przesłany plik obrazu.');
    }
    $size = (int) ($upload['size'] ?? 0);
    if ($size <= 0 || $size > THUMBNAIL_MAX_SOURCE_BYTES) {
        throw new InvalidArgumentException('Obraz jest pusty albo przekracza limit 25 MB.');
    }
    $bytes = file_get_contents((string) $upload['tmp_name']);
    if (!is_string($bytes)) {
        throw new RuntimeException('Nie można odczytać przesłanego obrazu.');
    }

    return complete_thumbnail_from_bytes($thumbnailId, $bytes, $model);
}

function openai_image_curl_transport(array $payload, string $apiKey, string $operationKey): array
{
    $baseUrl = rtrim((string) app_config('openai_api_base_url'), '/');
    if ($baseUrl === '' || !str_starts_with($baseUrl, 'https://')) {
        throw new RuntimeException('OPENAI_API_BASE_URL musi być poprawnym adresem HTTPS.');
    }
    $body = '';
    $headers = [];
    $curl = curl_init($baseUrl . '/images/generations');
    if ($curl === false) {
        throw new RuntimeException('Nie można uruchomić klienta OpenAI Images API.');
    }
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => max(60, (int) app_config('openai_timeout_seconds')),
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
            'Idempotency-Key: ' . $operationKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers): int {
            $position = strpos($line, ':');
            if ($position !== false) {
                $headers[strtolower(trim(substr($line, 0, $position)))] = trim(substr($line, $position + 1));
            }
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
            if (strlen($body) + strlen($chunk) > 36000000) {
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        },
    ]);
    $caBundle = trim((string) app_config('openai_ca_bundle'));
    if ($caBundle !== '') {
        if (!is_file($caBundle)) {
            curl_close($curl);
            throw new RuntimeException('OPENAI_CA_BUNDLE nie wskazuje istniejącego pliku.');
        }
        curl_setopt($curl, CURLOPT_CAINFO, $caBundle);
    }
    $success = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    return [
        'status' => $status,
        'body' => $body,
        'headers' => $headers,
        'network_error' => $success === false ? ($error !== '' ? $error : 'Błąd sieci Images API.') : '',
    ];
}

function thumbnail_mock_png_chunk(string $type, string $data): string
{
    return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
}

function thumbnail_mock_image_bytes(): string
{
    $width = 1600;
    $height = 900;
    $pixel = chr(28) . chr(92) . chr(148);
    $row = "\x00" . str_repeat($pixel, $width);
    $raw = str_repeat($row, $height);

    return "\x89PNG\r\n\x1a\n"
        . thumbnail_mock_png_chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
        . thumbnail_mock_png_chunk('IDAT', gzcompress($raw, 9))
        . thumbnail_mock_png_chunk('IEND', '');
}

function execute_thumbnail_api(
    int $thumbnailId,
    ?callable $transport = null,
    ?string $apiKey = null
): array {
    $thumbnail = find_thumbnail_version($thumbnailId);
    if ($thumbnail === null || $thumbnail['execution_mode'] !== 'api' || $thumbnail['status'] !== 'prepared') {
        throw new RuntimeException('Miniatura API nie jest gotowa do wygenerowania.');
    }
    if (!(bool) app_config('ai_image_generation_enabled')) {
        $message = 'Generowanie obrazów AI pominięto: CMS_AI_IMAGE_GENERATION_ENABLED=false. Użyj obrazu źródłowego lub uploadu ręcznego.';
        bueno_database()->prepare(
            'UPDATE thumbnail_versions
             SET status = "skipped", error_message = :message, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        )->execute([':message' => $message, ':id' => $thumbnailId]);

        return find_thumbnail_version($thumbnailId) ?? throw new RuntimeException($message);
    }
    $useBuiltInMock = (bool) app_config('openai_mock') && $transport === null;
    $apiKey = $apiKey ?? app_environment_value('OPENAI_API_KEY');
    if (!$useBuiltInMock && $transport === null && $apiKey === null) {
        throw new RuntimeException('Brakuje OPENAI_API_KEY dla OpenAI Images API.');
    }
    if ($useBuiltInMock) {
        $mockBytes = thumbnail_mock_image_bytes();
        $transport = static fn (): array => [
            'status' => 200,
            'body' => generation_json([
                'id' => 'img_local_mock',
                'data' => [['b64_json' => base64_encode($mockBytes)]],
                'usage' => ['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0],
            ]),
            'headers' => ['x-request-id' => 'img_local_mock'],
            'network_error' => '',
        ];
        $apiKey = 'local-mock-not-a-secret';
    }
    $transport ??= 'openai_image_curl_transport';
    $payload = [
        'model' => (string) $thumbnail['model'],
        'prompt' => (string) $thumbnail['prompt_text'],
        'size' => '2048x1152',
        'quality' => 'medium',
        'output_format' => 'webp',
        'output_compression' => 90,
        'background' => 'opaque',
        'n' => 1,
    ];
    $operationKey = 'thumbnail-' . $thumbnailId . '-' . hash('sha256', (string) $thumbnail['prompt_text']);
    $lastError = 'Nieznany błąd OpenAI Images API.';
    bueno_database()->prepare(
        'UPDATE thumbnail_versions SET status = "running", updated_at = CURRENT_TIMESTAMP WHERE id = :id'
    )->execute([':id' => $thumbnailId]);
    for ($attempt = 1; $attempt <= (int) app_config('openai_max_attempts'); $attempt++) {
        try {
            $response = $transport($payload, (string) $apiKey, $operationKey);
        } catch (Throwable $exception) {
            $response = [
                'status' => 0,
                'body' => '',
                'headers' => [],
                'network_error' => $exception->getMessage(),
            ];
        }
        $status = (int) ($response['status'] ?? 0);
        if ($status >= 200 && $status < 300) {
            try {
                $decoded = json_decode((string) $response['body'], true, 128, JSON_THROW_ON_ERROR);
                $base64 = (string) ($decoded['data'][0]['b64_json'] ?? '');
                $bytes = base64_decode($base64, true);
                if (!is_string($bytes) || $bytes === '') {
                    throw new RuntimeException('Images API nie zwróciło poprawnego obrazu base64.');
                }
                return complete_thumbnail_from_bytes(
                    $thumbnailId,
                    $bytes,
                    (string) $thumbnail['model'],
                    [
                        'response_id' => (string) (($response['headers']['x-request-id'] ?? '') ?: ($decoded['id'] ?? '')),
                        'usage' => is_array($decoded['usage'] ?? null) ? $decoded['usage'] : [],
                    ]
                );
            } catch (Throwable $exception) {
                $lastError = 'Nieprawidłowa odpowiedź Images API: ' . $exception->getMessage();
                break;
            }
        }
        $details = openai_error_details($response);
        $lastError = trim((string) ($response['network_error'] ?? ''));
        if ($lastError === '') {
            $lastError = $details['message'] !== '' ? $details['message'] : 'Images API zwróciło HTTP ' . $status . '.';
        }
        $transient = $status === 0 || in_array($status, [408, 409, 429, 500, 502, 503, 504], true);
        if (!$transient || $attempt >= (int) app_config('openai_max_attempts')) {
            break;
        }
        usleep(250000 * $attempt);
    }
    bueno_database()->prepare(
        'UPDATE thumbnail_versions SET status = "failed", error_message = :error,
         updated_at = CURRENT_TIMESTAMP WHERE id = :id'
    )->execute([':error' => mb_substr($lastError, 0, 2000), ':id' => $thumbnailId]);
    throw new RuntimeException($lastError);
}

function reject_thumbnail_version(int $thumbnailId, string $reason): void
{
    $reason = trim($reason);
    if (mb_strlen($reason) < 5 || mb_strlen($reason) > 1000) {
        throw new InvalidArgumentException('Odrzucenie miniatury wymaga uzasadnienia od 5 do 1000 znaków.');
    }
    $thumbnail = find_thumbnail_version($thumbnailId);
    if ($thumbnail === null || $thumbnail['status'] !== 'completed') {
        throw new RuntimeException('Można odrzucić wyłącznie ukończoną miniaturę.');
    }
    $database = bueno_database();
    $database->beginTransaction();
    try {
        $database->prepare(
            'UPDATE thumbnail_versions
             SET status = "rejected", is_active = 0, rejection_reason = :reason,
                 rejected_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        )->execute([':reason' => $reason, ':id' => $thumbnailId]);
        $previousStatement = $database->prepare(
            'SELECT * FROM thumbnail_versions
             WHERE post_id = :post_id AND status = "completed" AND id != :id
             ORDER BY id DESC LIMIT 1'
        );
        $previousStatement->execute([':post_id' => (int) $thumbnail['post_id'], ':id' => $thumbnailId]);
        $previous = $previousStatement->fetch();
        $imagePath = is_array($previous)
            ? (string) $previous['public_path']
            : (string) $thumbnail['previous_image_path'];
        $altText = is_array($previous)
            ? (string) $previous['alt_text']
            : (string) $thumbnail['previous_alt_text'];
        if (is_array($previous)) {
            $database->prepare('UPDATE thumbnail_versions SET is_active = 1 WHERE id = :id')
                ->execute([':id' => (int) $previous['id']]);
        }
        $database->prepare(
            'UPDATE posts SET image_path = :image_path, image_alt = :image_alt,
             updated_at = CURRENT_TIMESTAMP WHERE id = :post_id'
        )->execute([
            ':image_path' => $imagePath,
            ':image_alt' => $altText,
            ':post_id' => (int) $thumbnail['post_id'],
        ]);
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        throw $exception;
    }
}
