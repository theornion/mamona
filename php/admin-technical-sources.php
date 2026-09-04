<?php

declare(strict_types=1);

require_once __DIR__ . '/admin-auth.php';
require_once __DIR__ . '/admin-database.php';
require_once __DIR__ . '/admin-ui.php';

require_admin_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_valid_csrf()) {
        $_SESSION['technical_source_error'] = 'Sesja formularza wygasła. Odśwież stronę.';
        header('Location: admin-technical-sources.php?error=1', true, 303);
        exit;
    }
    try {
        $action = (string) ($_POST['action'] ?? '');
        $sourceId = filter_input(INPUT_POST, 'source_id', FILTER_VALIDATE_INT) ?: 0;
        $redirectQuery = 'saved=1';
        $notice = 'Źródło zostało zapisane.';
        if ($action === 'save_source') {
            save_technical_source($_POST, $sourceId);
        } elseif ($action === 'toggle_source') {
            set_technical_source_active($sourceId, (string) ($_POST['active'] ?? '') === '1');
        } elseif ($action === 'delete_source') {
            $deletedSource = delete_technical_source($sourceId);
            $redirectQuery = 'deleted=1';
            $notice = sprintf(
                'Usunięto źródło „%s”. Powiązanych wpisów discovered_feed_items: %d.',
                (string) $deletedSource['source_name'],
                (int) $deletedSource['discovered_feed_item_count']
            );
        } else {
            throw new InvalidArgumentException('Nieprawidłowa akcja.');
        }
        $_SESSION['technical_source_notice'] = $notice;
        header('Location: admin-technical-sources.php?' . $redirectQuery, true, 303);
    } catch (Throwable $exception) {
        $_SESSION['technical_source_error'] = $exception->getMessage();
        header('Location: admin-technical-sources.php?error=1', true, 303);
    }
    exit;
}

$sources = list_technical_sources();
$notice = (string) ($_SESSION['technical_source_notice'] ?? '');
$error = (string) ($_SESSION['technical_source_error'] ?? '');
unset($_SESSION['technical_source_notice']);
unset($_SESSION['technical_source_error']);

function technical_source_form(array $source = []): void
{
    $profileCategories = list_editorial_profile_categories();
    $sourceId = (int) ($source['id'] ?? 0);
    $categoryListId = 'editorial-profile-categories-' . $sourceId;
    ?>
    <form method="post" action="admin-technical-sources.php" class="technical-source-form">
        <input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>">
        <input type="hidden" name="action" value="save_source">
        <input type="hidden" name="source_id" value="<?php echo $sourceId; ?>">
        <div class="technical-source-grid">
            <div><label>Nazwa</label><input name="name" maxlength="150" required value="<?php echo escape_html((string) ($source['name'] ?? '')); ?>"></div>
            <div><label>URL strony</label><input type="url" name="website_url" required placeholder="https://…" value="<?php echo escape_html((string) ($source['website_url'] ?? '')); ?>"></div>
            <div><label>URL RSS/API</label><input type="url" name="feed_url" required placeholder="https://…" value="<?php echo escape_html((string) ($source['feed_url'] ?? '')); ?>"></div>
            <div><label>Typ</label><select name="source_type"><option value="rss"<?php echo ($source['source_type'] ?? 'rss') === 'rss' ? ' selected' : ''; ?>>RSS/Atom</option><option value="api"<?php echo ($source['source_type'] ?? '') === 'api' ? ' selected' : ''; ?>>API</option></select></div>
            <div>
                <label>Kategoria</label>
                <input name="topic_category" list="<?php echo escape_html($categoryListId); ?>" pattern="[a-z0-9_-]{2,50}" required value="<?php echo escape_html((string) ($source['topic_category'] ?? 'new-technologies')); ?>">
                <datalist id="<?php echo escape_html($categoryListId); ?>">
                    <?php foreach ($profileCategories as $category): ?>
                        <option value="<?php echo escape_html((string) $category['slug']); ?>"><?php echo escape_html((string) $category['label']); ?></option>
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div><label>Język</label><input name="language" pattern="[a-zA-Z]{2}(-[a-zA-Z]{2})?" maxlength="5" required value="<?php echo escape_html((string) ($source['language'] ?? 'en')); ?>"></div>
            <div><label>Wiarygodność</label><select name="credibility_level"><?php for ($level = 5; $level >= 1; $level--): ?><option value="<?php echo $level; ?>"<?php echo (int) ($source['credibility_level'] ?? 5) === $level ? ' selected' : ''; ?>><?php echo $level; ?>/5</option><?php endfor; ?></select></div>
            <div><label>Timeout połączenia (s)</label><input type="number" min="2" max="20" name="feed_connect_timeout_seconds" placeholder="domyślny" value="<?php echo escape_html((string)($source['feed_connect_timeout_seconds'] ?? '')); ?>"></div>
            <div><label>Timeout transferu (s)</label><input type="number" min="10" max="90" name="feed_transfer_timeout_seconds" placeholder="domyślny" value="<?php echo escape_html((string)($source['feed_transfer_timeout_seconds'] ?? '')); ?>"></div>
            <div><label>Low speed: bajty/s</label><input type="number" min="1" max="65536" name="feed_low_speed_limit" placeholder="domyślny" value="<?php echo escape_html((string)($source['feed_low_speed_limit'] ?? '')); ?>"></div>
            <div><label>Low speed: czas (s)</label><input type="number" min="5" max="60" name="feed_low_speed_time_seconds" placeholder="domyślny" value="<?php echo escape_html((string)($source['feed_low_speed_time_seconds'] ?? '')); ?>"></div>
            <div><label>Maks. prób</label><input type="number" min="1" max="4" name="feed_max_attempts" placeholder="domyślny" value="<?php echo escape_html((string)($source['feed_max_attempts'] ?? '')); ?>"></div>
            <div><label>Budżet źródła (s)</label><input type="number" min="30" max="600" name="feed_job_budget_seconds" placeholder="domyślny" value="<?php echo escape_html((string)($source['feed_job_budget_seconds'] ?? '')); ?>"></div>
        </div>
        <div class="technical-source-flags">
            <label><input type="checkbox" name="is_primary" value="1"<?php echo !isset($source['is_primary']) || (int) $source['is_primary'] === 1 ? ' checked' : ''; ?>> Źródło pierwotne</label>
            <label><input type="checkbox" name="is_active" value="1"<?php echo !isset($source['is_active']) || (int) $source['is_active'] === 1 ? ' checked' : ''; ?>> Aktywne</label>
        </div>
        <button type="submit"><?php echo $sourceId > 0 ? 'Zapisz źródło' : 'Dodaj źródło'; ?></button>
    </form>
    <?php
}

admin_page_open('Źródła techniczne', 'sources');
?>
<section class="post admin-card technical-sources-page">
    <header class="major admin-heading">
        <p class="admin-kicker">Research</p>
        <h1>Źródła techniczne</h1>
        <p>Kontrolowana lista oficjalnych kanałów RSS i API. Nie zapisuj tutaj endpointów zawierających klucze lub tokeny.</p>
    </header>
    <?php if (isset($_GET['saved'])): ?><p class="admin-notice is-success" role="status">Źródło zostało zapisane.</p><?php endif; ?>
    <?php if ($notice !== ''): ?><p class="admin-notice is-success" role="status"><?php echo escape_html($notice); ?></p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="admin-notice is-error" role="alert"><?php echo escape_html($error); ?></p><?php endif; ?>

    <details class="technical-source-add">
        <summary>Dodaj nowe źródło</summary>
        <?php technical_source_form(); ?>
    </details>

    <div class="technical-source-list">
        <?php foreach ($sources as $source): ?>
            <?php $sourceId = (int) ($source['id'] ?? 0); ?>
            <article class="technical-source-card<?php echo (int) $source['is_active'] === 1 ? '' : ' is-disabled'; ?>">
                <header>
                    <div>
                        <span class="editorial-status"><?php echo escape_html(strtoupper((string) $source['source_type'])); ?></span>
                        <h2><?php echo escape_html((string) $source['name']); ?></h2>
                    </div>
                    <form method="post" action="admin-technical-sources.php">
                        <input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>">
                        <input type="hidden" name="action" value="toggle_source">
                        <input type="hidden" name="source_id" value="<?php echo (int) $source['id']; ?>">
                        <input type="hidden" name="active" value="<?php echo (int) $source['is_active'] === 1 ? '0' : '1'; ?>">
                        <button type="submit"><?php echo (int) $source['is_active'] === 1 ? 'Wyłącz' : 'Włącz'; ?></button>
                    </form>
                </header>
                <p><a href="<?php echo escape_html((string) $source['website_url']); ?>" target="_blank" rel="noopener noreferrer">Strona wydawcy</a> · <a href="<?php echo escape_html((string) $source['feed_url']); ?>" target="_blank" rel="noopener noreferrer">Kanał</a></p>
                <dl class="editorial-meta">
                    <div><dt>Kategoria</dt><dd><?php echo escape_html((string) $source['topic_category']); ?></dd></div>
                    <div><dt>Język</dt><dd><?php echo escape_html((string) $source['language']); ?></dd></div>
                    <div><dt>Wiarygodność</dt><dd><?php echo (int) $source['credibility_level']; ?>/5</dd></div>
                    <div><dt>Charakter</dt><dd><?php echo (int) $source['is_primary'] === 1 ? 'Pierwotne' : 'Wtórne'; ?></dd></div>
                    <div><dt>Ostatni sukces</dt><dd><?php echo escape_html((string) ($source['last_success_at'] ?? 'Brak')); ?></dd></div>
                    <div><dt>Ostatnia kontrola</dt><dd><?php echo escape_html((string) ($source['last_checked_at'] ?? 'Brak')); ?></dd></div>
                </dl>
                <?php if (trim((string) $source['last_error']) !== ''): ?><p class="editorial-error"><strong>Ostatni błąd:</strong> <?php echo escape_html((string) $source['last_error']); ?></p><?php endif; ?>
                <details><summary>Edytuj konfigurację</summary><?php technical_source_form($source); ?></details>
                <?php if ($sourceId > 0): ?>
                    <?php $deleteWarning = 'Czy na pewno chcesz usunąć źródło „' . (string) $source['name'] . '”? Tej operacji nie można łatwo cofnąć.'; ?>
                    <form
                        method="post"
                        action="admin-technical-sources.php"
                        class="technical-source-delete"
                        onsubmit="return confirm(<?php echo json_encode($deleteWarning, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>);"
                    >
                        <input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>">
                        <input type="hidden" name="action" value="delete_source">
                        <input type="hidden" name="source_id" value="<?php echo $sourceId; ?>">
                        <p class="technical-source-delete-warning">Ta operacja trwale usuwa rekord źródła. Nie ma cofnięcia jednym kliknięciem.</p>
                        <button class="admin-danger-action" type="submit">Usuń źródło</button>
                    </form>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
</section>
<?php admin_page_close(); ?>
