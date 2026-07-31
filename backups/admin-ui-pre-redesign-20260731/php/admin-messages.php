<?php

declare(strict_types=1);

require_once __DIR__ . '/admin-auth.php';
require_once __DIR__ . '/admin-database.php';
require_once __DIR__ . '/admin-mailer.php';
require_once __DIR__ . '/admin-ui.php';

require_admin_login();

function message_folder(string $folder): string
{
    return in_array($folder, ['new', 'replied', 'trash'], true) ? $folder : 'new';
}

function redirect_to_message_folder(string $folder, string $notice, int $messageId = 0): never
{
    $query = 'folder=' . rawurlencode($folder);

    if ($notice !== '') {
        $query .= '&notice=' . rawurlencode($notice);
    }

    if ($messageId > 0) {
        $query .= '&message=' . $messageId;
    }

    header('Location: admin-messages.php?' . $query, true, 303);
    exit;
}

function message_belongs_to_folder(array $message, string $folder): bool
{
    return $message['status'] === $folder;
}

function message_read_filters(): array
{
    return [
        'unread' => (string) ($_GET['unread'] ?? '0') === '1',
        'read' => (string) ($_GET['read'] ?? '0') === '1',
    ];
}

function message_important_filter(): bool
{
    return (string) ($_GET['important'] ?? '0') === '1';
}

function new_messages_query(bool $showUnread, bool $showRead, int $messageId = 0, bool $importantOnly = false): string
{
    $query = 'folder=new&unread=' . (int) $showUnread . '&read=' . (int) $showRead . '&important=' . (int) $importantOnly;

    if ($messageId > 0) {
        $query .= '&message=' . $messageId;
    }

    return $query;
}

function message_move_folder(string $folder): ?string
{
    return in_array($folder, ['unread', 'read', 'replied', 'trash'], true) ? $folder : null;
}

function message_action_notice(int $count, string $action): string
{
    $suffixes = match ($action) {
        'trash' => ['przeniesiona do kosza.', 'przeniesione do kosza.', 'przeniesionych do kosza.'],
        'delete' => ['trwale usunięta.', 'trwale usunięte.', 'trwale usuniętych.'],
        default => ['przeniesiona.', 'przeniesione.', 'przeniesionych.'],
    };

    if ($count === 1) {
        return 'Wiadomość została ' . $suffixes[0];
    }

    if ($count >= 2 && $count <= 4) {
        return $count . ' wiadomości zostały ' . $suffixes[1];
    }

    return $count . ' wiadomości zostało ' . $suffixes[2];
}

function submitted_message_ids(): array
{
    $ids = [];
    $submittedIds = $_POST['message_ids'] ?? [];

    if (is_array($submittedIds)) {
        foreach ($submittedIds as $submittedId) {
            $id = filter_var($submittedId, FILTER_VALIDATE_INT);

            if (is_int($id) && $id > 0) {
                $ids[$id] = $id;
            }
        }
    }

    $targetId = filter_input(INPUT_POST, 'message_id', FILTER_VALIDATE_INT);

    if (is_int($targetId) && $targetId > 0 && $ids === []) {
        $ids[$targetId] = $targetId;
    }

    return array_values($ids);
}

$error = '';
$notice = (string) ($_GET['notice'] ?? '');
$folder = message_folder((string) ($_GET['folder'] ?? 'new'));
$readFilters = message_read_filters();
$importantOnly = message_important_filter();
$requestedSelectedId = filter_input(INPUT_GET, 'message', FILTER_VALIDATE_INT) ?: 0;
$selectedId = $requestedSelectedId;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $folder === 'new' && $requestedSelectedId > 0) {
    $openedMessage = find_message($requestedSelectedId);

    if ($openedMessage !== null && $openedMessage['status'] === 'new' && (int) $openedMessage['is_read'] === 0) {
        mark_message_as_read($requestedSelectedId);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $flagId = filter_input(INPUT_POST, 'flag_id', FILTER_VALIDATE_INT) ?: 0;
    $selectedId = filter_input(INPUT_POST, 'message_id', FILTER_VALIDATE_INT) ?: $flagId;
    $selectedIds = submitted_message_ids();
    $folder = message_folder((string) ($_POST['folder'] ?? $folder));

    if ($action === '' && $selectedId > 0) {
        $action = $flagId > 0 ? 'toggle_important' : ($folder === 'trash' ? 'bulk_delete_permanently' : 'bulk_trash');
    }

    $message = $selectedId > 0 ? find_message($selectedId) : null;

    if (!admin_valid_csrf()) {
        $error = 'Sesja formularza wygasła. Odśwież stronę i spróbuj ponownie.';
    } elseif ($action === 'toggle_important' && $message !== null) {
        toggle_message_important($selectedId);
        redirect_to_message_folder($folder, 'Zmieniono oznaczenie ważnej wiadomości.');
    } elseif ($action === 'bulk_trash') {
        $movedCount = 0;

        foreach ($selectedIds as $messageId) {
            $selectedMessage = find_message($messageId);

            if ($selectedMessage !== null && $selectedMessage['status'] !== 'trash') {
                move_message_to_trash($messageId);
                $movedCount++;
            }
        }

        if ($movedCount === 0) {
            $error = 'Zaznacz co najmniej jedną wiadomość do przeniesienia do kosza.';
        } else {
            redirect_to_message_folder('trash', message_action_notice($movedCount, 'trash'));
        }
    } elseif ($action === 'bulk_delete_permanently') {
        $deletedCount = 0;

        foreach ($selectedIds as $messageId) {
            $selectedMessage = find_message($messageId);

            if ($selectedMessage !== null && $selectedMessage['status'] === 'trash') {
                permanently_delete_message($messageId);
                $deletedCount++;
            }
        }

        if ($deletedCount === 0) {
            $error = 'Zaznacz co najmniej jedną wiadomość z kosza do trwałego usunięcia.';
        } else {
            redirect_to_message_folder('trash', message_action_notice($deletedCount, 'delete'));
        }
    } elseif ($action === 'bulk_move') {
        $targetFolder = message_move_folder((string) ($_POST['target_folder'] ?? ''));
        $movedCount = 0;

        foreach ($selectedIds as $messageId) {
            $selectedMessage = find_message($messageId);

            if ($selectedMessage !== null && $targetFolder !== null) {
                move_message_to_folder($messageId, $targetFolder);
                $movedCount++;
            }
        }

        if ($selectedIds === []) {
            $error = 'Zaznacz co najmniej jedną wiadomość do przeniesienia.';
        } elseif ($movedCount === 0) {
            $error = 'Wybierz kategorię, do której chcesz przenieść wiadomości.';
        } else {
            redirect_to_message_folder(in_array($targetFolder, ['unread', 'read'], true) ? 'new' : $targetFolder, message_action_notice($movedCount, 'move'));
        }
    } elseif ($message === null) {
        $error = 'Nie znaleziono wybranej wiadomości.';
    } elseif ($action === 'reply') {
        $reply = trim((string) ($_POST['reply_body'] ?? ''));

        if ($message['status'] === 'trash') {
            $error = 'Najpierw przywróć wiadomość z kosza.';
        } elseif (strlen($reply) < 2 || strlen($reply) > 5000) {
            $error = 'Odpowiedź musi mieć od 2 do 5000 znaków.';
        } else {
            try {
                send_admin_reply($message['email'], $message['name'], (string) $message['subject'], $reply);
                save_message_reply($selectedId, $reply);
                redirect_to_message_folder('replied', 'Odpowiedź została wysłana i zapisana.', $selectedId);
            } catch (Throwable $exception) {
                error_log('Admin reply failed: ' . $exception->getMessage());
                $error = admin_reply_error_message($exception);
            }
        }
    } elseif ($action === 'trash') {
        move_message_to_trash($selectedId);
        redirect_to_message_folder('trash', 'Wiadomość została przeniesiona do kosza.', $selectedId);
    } elseif ($action === 'restore' && $message['status'] === 'trash') {
        $restoredFolder = !empty($message['reply_body']) ? 'replied' : 'new';
        restore_message_from_trash($selectedId);
        redirect_to_message_folder($restoredFolder, 'Wiadomość została przywrócona.', $selectedId);
    } elseif ($action === 'delete_permanently' && $message['status'] === 'trash') {
        permanently_delete_message($selectedId);
        redirect_to_message_folder('trash', 'Wiadomość została trwale usunięta.');
    } else {
        $error = 'Ta operacja nie jest dostępna dla wybranej wiadomości.';
    }
}

$messages = list_messages($folder, $readFilters['unread'], $readFilters['read'], $importantOnly);

$selectedMessage = $selectedId > 0 ? find_message($selectedId) : null;

if (
    $selectedMessage !== null
    && !message_belongs_to_folder($selectedMessage, $folder)
) {
    $selectedMessage = null;
}

$closeMessageQuery = $folder === 'new'
    ? new_messages_query($readFilters['unread'], $readFilters['read'], 0, $importantOnly)
    : 'folder=' . $folder . '&important=' . (int) $importantOnly;

$tabLabels = [
    'new' => 'Nowe',
    'replied' => 'Odpisane',
    'trash' => 'Kosz',
];

$moveFolderLabels = [
    'unread' => 'Nieprzeczytane',
    'read' => 'Przeczytane',
    'replied' => 'Odpisane',
    'trash' => 'Kosz',
];

admin_page_open('Wiadomości', 'messages');
?>
<section class="post admin-card">
    <header class="major admin-heading">
        <p class="admin-kicker">Skrzynka odbiorcza</p>
        <h1>Wiadomości</h1>
        <p>Wiadomości z formularza kontaktowego są zapisywane tutaj oraz nadal wysyłane na Gmaila.</p>
    </header>

    <?php if ($notice !== ''): ?>
        <p class="admin-notice is-success" role="status"><?php echo escape_html($notice); ?></p>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
            <p class="admin-notice is-error" role="alert"><?php echo escape_html($error); ?></p>
    <?php endif; ?>

    <div class="admin-message-layout">
        <div class="admin-message-sidebar">
            <nav class="admin-message-tabs" aria-label="Foldery wiadomości">
                <?php foreach ($tabLabels as $tab => $label): ?>
                    <a href="admin-messages.php?folder=<?php echo $tab; ?>&important=<?php echo (int) $importantOnly; ?>" class="<?php echo $folder === $tab ? 'is-active' : ''; ?>"><?php echo $label; ?></a>
                <?php endforeach; ?>
            </nav>

            <?php if ($folder === 'new'): ?>
                <nav class="admin-message-filters" aria-label="Filtr wiadomości w folderze Nowe">
                    <span>Filtruj w kategorii:</span>
                    <a href="admin-messages.php?<?php echo new_messages_query(!$readFilters['unread'], false, 0, $importantOnly); ?>" class="<?php echo $readFilters['unread'] ? 'is-active' : ''; ?>" aria-pressed="<?php echo $readFilters['unread'] ? 'true' : 'false'; ?>">
                        <i class="icon solid fa-envelope" aria-hidden="true"></i> Nieprzeczytane
                    </a>
                    <a href="admin-messages.php?<?php echo new_messages_query(false, !$readFilters['read'], 0, $importantOnly); ?>" class="<?php echo $readFilters['read'] ? 'is-active' : ''; ?>" aria-pressed="<?php echo $readFilters['read'] ? 'true' : 'false'; ?>">
                        <i class="icon solid fa-envelope-open" aria-hidden="true"></i> Przeczytane
                    </a>
                    <a href="admin-messages.php?<?php echo new_messages_query($readFilters['unread'], $readFilters['read'], 0, !$importantOnly); ?>" class="is-important-filter <?php echo $importantOnly ? 'is-active' : ''; ?>" aria-pressed="<?php echo $importantOnly ? 'true' : 'false'; ?>">
                        <i class="icon solid fa-exclamation" aria-hidden="true"></i> Ważne
                    </a>
                </nav>
            <?php else: ?>
                <nav class="admin-message-filters" aria-label="Filtr ważnych wiadomości">
                    <span>Filtruj w kategorii:</span>
                    <a href="admin-messages.php?folder=<?php echo $folder; ?>&important=<?php echo (int) !$importantOnly; ?>" class="is-important-filter <?php echo $importantOnly ? 'is-active' : ''; ?>" aria-pressed="<?php echo $importantOnly ? 'true' : 'false'; ?>">
                        <i class="icon solid fa-exclamation" aria-hidden="true"></i> Ważne
                    </a>
                </nav>
            <?php endif; ?>

            <?php if ($messages === []): ?>
                <div class="admin-placeholder">
                    <h2>Brak wiadomości</h2>
                    <p><?php echo $folder === 'trash' ? 'Kosz jest pusty.' : 'W tym folderze nie ma jeszcze wiadomości.'; ?></p>
                </div>
            <?php else: ?>
                <form method="post" action="admin-messages.php" class="admin-message-selection" id="admin-message-selection" onsubmit="return confirmMessageSelectionAction(this, event);">
                    <input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>">
                    <input type="hidden" name="folder" value="<?php echo $folder; ?>">
                    <div class="admin-message-bulk-controls">
                        <label class="admin-message-select-all">
                            <input type="checkbox">
                            <span aria-hidden="true"></span>
                            Zaznacz wszystkie
                        </label>
                        <label class="admin-message-move">
                            <span>Przenieś do</span>
                            <select name="target_folder" aria-label="Przenieś zaznaczone wiadomości do kategorii">
                                <?php foreach ($moveFolderLabels as $tab => $label): ?>
                                    <option value="<?php echo $tab; ?>"><?php echo $label; ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" name="action" value="bulk_move">Przenieś</button>
                        </label>
                    </div>
                    <aside class="admin-message-list" aria-label="Lista wiadomości">
                        <?php foreach ($messages as $message): ?>
                            <?php
                            $isUnread = $message['status'] === 'new' && (int) $message['is_read'] === 0;
                            $isImportant = (int) $message['is_important'] === 1;
                            $itemState = ($folder === 'trash' ? 'is-trash' : ($isUnread ? 'is-unread' : ($folder === 'replied' ? 'is-replied' : 'is-read'))) . ($isImportant ? ' is-important' : '');
                            ?>
                            <div class="admin-message-list-item <?php echo $itemState; ?>">
                                <label class="admin-message-select" aria-label="Zaznacz wiadomość od <?php echo escape_html($message['name']); ?>">
                                    <input type="checkbox" name="message_ids[]" value="<?php echo (int) $message['id']; ?>">
                                    <span aria-hidden="true"></span>
                                </label>
                                <a href="admin-messages.php?<?php echo $folder === 'new' ? new_messages_query($readFilters['unread'], $readFilters['read'], (int) $message['id'], $importantOnly) : 'folder=' . $folder . '&important=' . (int) $importantOnly . '&message=' . (int) $message['id']; ?>" class="<?php echo (int) $message['id'] === $selectedId ? 'is-selected' : ''; ?>">
                                    <strong><?php echo escape_html($message['subject'] !== '' ? (string) $message['subject'] : 'Bez tematu'); ?></strong>
                                    <span class="admin-message-sender"><?php echo escape_html($message['name']); ?></span>
                                    <span><?php echo escape_html($message['email']); ?></span>
                                    <small><?php echo escape_html($folder === 'trash' ? (string) $message['deleted_at'] : (string) $message['created_at']); ?></small>
                                </a>
                                <span class="admin-message-state" title="<?php echo $isUnread ? 'Nieprzeczytana wiadomość' : ($folder === 'trash' ? 'W koszu' : 'Przeczytana wiadomość'); ?>" aria-hidden="true">
                                    <i class="icon solid <?php echo $isUnread ? 'fa-envelope' : 'fa-envelope-open'; ?>"></i>
                                </span>
                                <button class="admin-message-important <?php echo $isImportant ? 'is-active' : ''; ?>" type="submit" name="flag_id" value="<?php echo (int) $message['id']; ?>" title="<?php echo $isImportant ? 'Usuń oznaczenie ważnej wiadomości' : 'Oznacz jako ważną'; ?>" aria-label="<?php echo $isImportant ? 'Usuń oznaczenie ważnej wiadomości' : 'Oznacz jako ważną'; ?>">
                                    <img src="../images/icons/wykrzyknik.svg" alt="" aria-hidden="true">
                                </button>
                                <button class="admin-message-quick-trash" type="submit" name="message_id" value="<?php echo (int) $message['id']; ?>" title="<?php echo $folder === 'trash' ? 'Usuń trwale' : 'Przenieś do kosza'; ?>" aria-label="<?php echo $folder === 'trash' ? 'Usuń wiadomość trwale' : 'Przenieś wiadomość do kosza'; ?>">
                                    <img src="<?php echo escape_html(admin_asset_url('images/icons/kosz.svg')); ?>" alt="" aria-hidden="true">
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </aside>
                </form>
            <?php endif; ?>
        </div>

        <?php if ($selectedMessage !== null): ?>
                <article class="admin-message-detail">
                    <a class="admin-message-detail-close" href="admin-messages.php?<?php echo $closeMessageQuery; ?>" aria-label="Zamknij wyświetlaną wiadomość" title="Zamknij wiadomość">&times;</a>
                    <h2><?php echo escape_html($selectedMessage['name']); ?></h2>
                    <p><strong>Temat: <?php echo escape_html($selectedMessage['subject'] !== '' ? (string) $selectedMessage['subject'] : 'Bez tematu'); ?></strong></p>
                    <p><a href="mailto:<?php echo escape_html($selectedMessage['email']); ?>"><?php echo escape_html($selectedMessage['email']); ?></a></p>
                    <p class="admin-message-date">Otrzymano: <?php echo escape_html($selectedMessage['created_at']); ?></p>
                    <?php if ($folder === 'trash'): ?>
                        <p class="admin-message-date">W koszu od: <?php echo escape_html((string) $selectedMessage['deleted_at']); ?>. Automatyczne usunięcie nastąpi po 15 dniach.</p>
                    <?php endif; ?>
                    <div class="admin-message-body"><?php echo nl2br(escape_html($selectedMessage['message'])); ?></div>

                    <?php if (!empty($selectedMessage['reply_body'])): ?>
                        <div class="admin-previous-reply">
                            <h3>Wysłana odpowiedź</h3>
                            <p><?php echo nl2br(escape_html($selectedMessage['reply_body'])); ?></p>
                        </div>
                    <?php endif; ?>

                    <?php if ($folder !== 'trash'): ?>
                        <form id="admin-reply-form" method="post" action="admin-messages.php" class="admin-form admin-reply-form">
                            <input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>">
                            <input type="hidden" name="message_id" value="<?php echo (int) $selectedMessage['id']; ?>">
                            <input type="hidden" name="folder" value="<?php echo $folder; ?>">
                            <label for="reply_body">Odpowiedz na wiadomość</label>
                            <textarea name="reply_body" id="reply_body" rows="6" required></textarea>
                            <ul class="actions">
                                <li><button type="submit" name="action" value="reply">Wyślij odpowiedź</button></li>
                            </ul>
                        </form>
                        <form method="post" action="admin-messages.php" class="admin-message-actions" onsubmit="return confirm('Przenieść wiadomość do kosza?');">
                            <input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>">
                            <input type="hidden" name="message_id" value="<?php echo (int) $selectedMessage['id']; ?>">
                            <input type="hidden" name="folder" value="<?php echo $folder; ?>">
                            <button type="submit" name="action" value="trash">Przenieś do kosza</button>
                        </form>
                        <div class="admin-message-action-row">
                            <button class="admin-message-reply-button" type="submit" form="admin-reply-form" name="action" value="reply">Wyślij odpowiedź</button>
                            <form method="post" action="admin-messages.php" onsubmit="return confirm('Przenieść wiadomość do kosza?');">
                                <input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>"><input type="hidden" name="message_id" value="<?php echo (int) $selectedMessage['id']; ?>"><input type="hidden" name="folder" value="<?php echo $folder; ?>">
                                <button class="admin-message-trash-button" type="submit" name="action" value="trash">Przenieś do kosza</button>
                            </form>
                            <form method="post" action="admin-messages.php">
                                <input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>"><input type="hidden" name="message_id" value="<?php echo (int) $selectedMessage['id']; ?>"><input type="hidden" name="folder" value="<?php echo $folder; ?>">
                                <button class="admin-message-important-button" type="submit" name="flag_id" value="<?php echo (int) $selectedMessage['id']; ?>">Oznacz jako ważne</button>
                            </form>
                        </div>
                    <?php else: ?>
                        <div class="admin-message-actions">
                            <form method="post" action="admin-messages.php">
                                <input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>">
                                <input type="hidden" name="message_id" value="<?php echo (int) $selectedMessage['id']; ?>">
                                <input type="hidden" name="folder" value="trash">
                                <button type="submit" name="action" value="restore">Przywróć wiadomość</button>
                            </form>
                            <form method="post" action="admin-messages.php" onsubmit="return confirm('Czy chcesz trwale usunąć wiadomość?');">
                                <input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>">
                                <input type="hidden" name="message_id" value="<?php echo (int) $selectedMessage['id']; ?>">
                                <input type="hidden" name="folder" value="trash">
                                <button class="admin-danger-action" type="submit" name="action" value="delete_permanently">Usuń trwale</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </article>
        <?php endif; ?>
    </div>
</section>
<script>
function confirmMessageSelectionAction(form, event) {
    if (event.submitter && (event.submitter.value === 'bulk_move' || event.submitter.name === 'flag_id')) {
        return true;
    }

    if (form.querySelector('input[name="folder"]').value !== 'trash') {
        return confirm('Przenieść zaznaczone wiadomości do kosza?');
    }

    return confirmPermanentMessageRemoval(form, event);
}

function confirmPermanentMessageRemoval(form, event) {
    if (event.submitter && event.submitter.value === 'bulk_move') {
        return true;
    }

    const selectedCount = form.querySelectorAll('input[name="message_ids[]"]:checked').length;
    const count = selectedCount || (event.submitter && event.submitter.name === 'message_id' ? 1 : 0);

    if (count < 1) {
        return false;
    }

    return confirm('Czy chcesz trwale usunąć ' + (count === 1 ? 'wiadomość' : count + ' wiadomości') + '?');
}
</script>
<?php admin_page_close(); ?>
