<?php

declare(strict_types=1);

require_once __DIR__ . '/admin-auth.php';
require_once __DIR__ . '/admin-database.php';
require_once __DIR__ . '/admin-ui.php';

require_admin_login();

function topic_trash_post_ids(): array
{
    $raw = $_POST['topic_ids'] ?? [];
    if (!is_array($raw)) throw new InvalidArgumentException('Nieprawidłowa lista tematów.');
    $ids = [];
    foreach ($raw as $value) {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) throw new InvalidArgumentException('Lista zawiera nieprawidłowy identyfikator.');
        $ids[(int) $id] = (int) $id;
    }
    if ($ids === []) throw new InvalidArgumentException('Zaznacz co najmniej jeden temat.');
    return array_values($ids);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!admin_valid_csrf()) {
        $_SESSION['topic_trash_error'] = 'Sesja formularza wygasła. Odśwież stronę.';
    } else {
        try {
            $action = trim((string) ($_POST['action'] ?? ''));
            $ids = isset($_POST['topic_ids']) ? topic_trash_post_ids() : [filter_input(INPUT_POST, 'topic_id', FILTER_VALIDATE_INT) ?: 0];
            $done = 0;
            $errors = [];
            if (in_array($action, ['purge', 'purge_selected'], true)) {
                $confirmed = isset($_POST['confirm_permanent']) && trim((string) ($_POST['confirmation'] ?? '')) === 'USUŃ TRWALE';
                if (!$confirmed || !admin_is_logged_in()) throw new RuntimeException('Trwałe usunięcie wymaga uprawnienia administratora, zaznaczenia potwierdzenia i wpisania „USUŃ TRWALE”.');
            }
            foreach ($ids as $id) {
                try {
                    if (in_array($action, ['restore', 'restore_selected'], true)) {
                        restore_editorial_topic($id, 'admin');
                        $done++;
                    } elseif (in_array($action, ['purge', 'purge_selected'], true)) {
                        if (permanently_purge_editorial_topic($id, 'admin', 'Ręczne trwałe usunięcie')) $done++;
                    } else {
                        throw new InvalidArgumentException('Nieprawidłowa akcja.');
                    }
                } catch (Throwable $exception) {
                    $errors[] = '#' . $id . ': ' . $exception->getMessage();
                }
            }
            $_SESSION['topic_trash_notice'] = sprintf('Zakończono operację dla %d tematów.', $done);
            if ($errors !== []) $_SESSION['topic_trash_error'] = implode(' ', $errors);
        } catch (Throwable $exception) {
            $_SESSION['topic_trash_error'] = $exception->getMessage();
        }
    }
    header('Location: admin-topic-trash.php', true, 303);
    exit;
}

$search = trim((string) ($_GET['search'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));
if (!in_array($status, ['', 'draft', 'review', 'approved', 'scheduled', 'published', 'rejected'], true)) $status = '';
$sort = trim((string) ($_GET['sort'] ?? 'deadline'));
if (!in_array($sort, ['deadline', 'title', 'score', 'trashed'], true)) $sort = 'deadline';
$topics = list_trashed_editorial_topics($search, $status, $sort);
$notice = (string) ($_SESSION['topic_trash_notice'] ?? '');
$error = (string) ($_SESSION['topic_trash_error'] ?? '');
unset($_SESSION['topic_trash_notice'], $_SESSION['topic_trash_error']);

admin_page_open('Kosz tematów', 'topic-trash');
?>
<section class="post admin-card topic-trash-page">
    <header class="major admin-heading">
        <p class="admin-kicker">Retencja 10 dni</p>
        <h1>Kosz tematów</h1>
        <p>Tematy można przywrócić do dnia wskazanego na karcie. Trwałe usunięcie tworzy nieodwracalny tombstone, ale zachowuje publikacje, źródła, licencje i historię operacji.</p>
    </header>
    <?php if ($notice !== ''): ?><p class="admin-notice is-success" role="status"><?php echo escape_html($notice); ?></p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="admin-notice is-error" role="alert"><?php echo escape_html($error); ?></p><?php endif; ?>

    <form class="topic-trash-filters" method="get">
        <label for="trash-search">Szukaj</label><input id="trash-search" type="search" name="search" value="<?php echo escape_html($search); ?>" placeholder="Tytuł lub źródło">
        <label for="trash-status">Status sprzed usunięcia</label><select id="trash-status" name="status"><option value="">Wszystkie</option><?php foreach (['draft', 'review', 'approved', 'scheduled', 'published', 'rejected'] as $value): ?><option value="<?php echo $value; ?>"<?php echo $status === $value ? ' selected' : ''; ?>><?php echo escape_html($value); ?></option><?php endforeach; ?></select>
        <label for="trash-sort">Sortowanie</label><select id="trash-sort" name="sort"><option value="deadline"<?php echo $sort === 'deadline' ? ' selected' : ''; ?>>Najbliższy termin</option><option value="trashed"<?php echo $sort === 'trashed' ? ' selected' : ''; ?>>Ostatnio usunięte</option><option value="score"<?php echo $sort === 'score' ? ' selected' : ''; ?>>Najwyższy score</option><option value="title"<?php echo $sort === 'title' ? ' selected' : ''; ?>>Tytuł</option></select>
        <button type="submit">Filtruj</button>
    </form>

    <?php if ($topics === []): ?>
        <div class="topic-trash-empty"><span aria-hidden="true">♲</span><h2>Kosz jest pusty</h2><p>Usunięte tematy pojawią się tutaj na 10 dni.</p><a class="button" href="admin-editorial-topics.php">Wróć do tematów</a></div>
    <?php else: ?>
        <form id="topic-trash-bulk" class="topic-trash-bulk" method="post">
            <input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>">
            <strong><span data-trash-selected>0</span> zaznaczonych</strong>
            <button name="action" value="restore_selected" type="submit">Przywróć zaznaczone</button>
            <label><input type="checkbox" name="confirm_permanent" value="1"> Potwierdzam nieodwracalną operację</label>
            <label for="bulk-confirmation">Wpisz <code>USUŃ TRWALE</code></label><input id="bulk-confirmation" name="confirmation" autocomplete="off">
            <button name="action" value="purge_selected" type="submit" class="admin-danger-action">Usuń trwale zaznaczone</button>
        </form>
        <div class="topic-trash-list">
        <?php foreach ($topics as $topic): $days = topic_retention_days_remaining((string) $topic['trashed_at']); ?>
            <article class="technical-source-card topic-trash-card">
                <input class="topic-trash-select" form="topic-trash-bulk" type="checkbox" name="topic_ids[]" value="<?php echo (int) $topic['id']; ?>" aria-label="Wybierz temat: <?php echo escape_html((string) $topic['title']); ?>">
                <div class="topic-trash-card__body">
                    <header><div><span class="editorial-status">#<?php echo (int) $topic['id']; ?> · <?php echo escape_html((string) $topic['post_status']); ?></span><h2><?php echo escape_html((string) $topic['title']); ?></h2></div><strong class="topic-trash-deadline"><?php echo $days > 0 ? $days . ' dni do usunięcia' : 'Termin usunięcia osiągnięty'; ?></strong></header>
                    <p><strong>Score sprzed usunięcia: <?php echo $topic['score_before_trash'] === null ? '—' : (int) $topic['score_before_trash'] . '/100'; ?></strong> · źródła: <?php echo escape_html((string) ($topic['source_names'] ?: '—')); ?></p>
                    <p>Przeniesiono: <time datetime="<?php echo escape_html((string) $topic['trashed_at']); ?>Z"><?php echo escape_html((string) $topic['trashed_at']); ?> UTC</time> przez <?php echo escape_html((string) ($topic['trashed_by'] ?: 'system')); ?>. Termin: <?php echo escape_html((string) $topic['purge_due_at']); ?> UTC.</p>
                    <p>Powiązania: <?php echo (int) $topic['item_count']; ?> wpisów RSS · <?php echo (int) $topic['operation_count']; ?> operacji · <?php echo (int) $topic['research_count']; ?> research · <?php echo (int) $topic['draft_count']; ?> wersji<?php echo (int) $topic['publication_count'] > 0 ? ' · publikacja chroniona' : ''; ?>.</p>
                    <?php if ((string) $topic['trash_reason'] !== ''): ?><p>Powód: <?php echo escape_html((string) $topic['trash_reason']); ?></p><?php endif; ?>
                    <div class="editorial-action-row">
                        <form method="post"><input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>"><input type="hidden" name="action" value="restore"><input type="hidden" name="topic_id" value="<?php echo (int) $topic['id']; ?>"><button type="submit">Przywróć</button></form>
                        <details class="topic-purge-confirm"><summary>Usuń trwale teraz</summary><form method="post"><input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>"><input type="hidden" name="action" value="purge"><input type="hidden" name="topic_id" value="<?php echo (int) $topic['id']; ?>"><label><input type="checkbox" name="confirm_permanent" value="1" required> Rozumiem, że tematu nie będzie można przywrócić</label><label>Wpisz <code>USUŃ TRWALE</code><input name="confirmation" required autocomplete="off"></label><button type="submit" class="admin-danger-action">Utwórz tombstone</button></form></details>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<script>document.querySelectorAll('.topic-trash-select').forEach(function (box) { box.addEventListener('change', function () { document.querySelector('[data-trash-selected]').textContent = document.querySelectorAll('.topic-trash-select:checked').length; }); });</script>
<?php admin_page_close(); ?>
