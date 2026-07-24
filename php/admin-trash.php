<?php

declare(strict_types=1);

require_once __DIR__ . '/admin-auth.php';
require_once __DIR__ . '/admin-database.php';
require_once __DIR__ . '/admin-ui.php';

require_admin_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_valid_csrf()) {
        header('Location: admin-trash.php?error=csrf', true, 303);
        exit;
    }

    $action = (string) ($_POST['action'] ?? '');
    $keys = is_array($_POST['items'] ?? null) ? $_POST['items'] : [];
    $items = [];
    foreach ($keys as $key) {
        if (!is_string($key) || !preg_match('/^([a-z_]+):(\d+)$/', $key, $match)) {
            continue;
        }
        $items[] = [$match[1], (int) $match[2]];
    }

    if ($action === 'empty') {
        foreach (trash_selectable_items() as $item) {
            permanently_delete_trash_item((string) $item['type'], (int) $item['id']);
        }
        header('Location: admin-trash.php?notice=empty', true, 303);
        exit;
    }

    foreach ($items as [$type, $id]) {
        if ($action === 'restore') {
            restore_trash_item($type, $id);
        } elseif ($action === 'delete') {
            permanently_delete_trash_item($type, $id);
        }
    }

    header('Location: admin-trash.php?notice=' . rawurlencode($action === 'restore' ? 'restore' : 'delete'), true, 303);
    exit;
}

$items = list_trash_items();
$renderTrashNode = static function (array $item, bool $nested = false) use (&$renderTrashNode): void {
    $selectable = (bool) ($item['selectable'] ?? true) && (string) ($item['key'] ?? '') !== '';
    $class = 'admin-trash-item' . ($nested ? ' is-nested' : '') . (!empty($item['children']) ? ' has-children' : '');
    echo '<article class="' . escape_html($class) . '">';
    echo '<div class="admin-trash-item-main">';
    if ($selectable) {
        echo '<label><input type="checkbox" name="items[]" value="' . escape_html((string) $item['key']) . '"> <span>';
    } else {
        echo '<span class="admin-trash-group-title">';
    }
    echo '<strong>' . escape_html((string) $item['title']) . '</strong>';
    echo '<small>' . escape_html((string) $item['meta']) . ' - W koszu od ' . escape_html((string) $item['deleted_at']) . '</small>';
    echo $selectable ? '</span></label>' : '</span>';
    echo '</div>';
    if ($selectable) {
        echo '<a class="button" href="' . escape_html((string) $item['preview_url']) . '">Podejrzyj</a>';
    }
    if (!empty($item['children'])) {
        echo '<div class="admin-trash-children">';
        foreach ($item['children'] as $child) {
            $renderTrashNode($child, true);
        }
        echo '</div>';
    }
    echo '</article>';
};
$notice = (string) ($_GET['notice'] ?? '');
$error = (string) ($_GET['error'] ?? '');

admin_page_open('Kosz', 'trash');
?>
<section class="post admin-card admin-trash-page">
    <header class="major admin-heading">
        <p class="admin-kicker">Panel administratora</p>
        <h1>Kosz</h1>
        <p>Elementy przeniesione tutaj są niepubliczne. Po 15 dniach od przeniesienia do kosza zostaną usunięte na stałe i nie będzie można ich odzyskać.</p>
    </header>

    <?php if ($notice !== ''): ?>
        <p class="admin-notice is-success" role="status">
            <?php echo $notice === 'empty' ? 'Kosz został opróżniony.' : ($notice === 'restore' ? 'Zaznaczone elementy zostały przywrócone.' : 'Zaznaczone elementy zostały usunięte na stałe.'); ?>
        </p>
    <?php elseif ($error !== ''): ?>
        <p class="admin-notice is-error" role="alert">Sesja formularza wygasła. Odśwież stronę i spróbuj ponownie.</p>
    <?php endif; ?>

    <?php if ($items === []): ?>
        <p class="admin-placeholder">Kosz jest pusty.</p>
    <?php else: ?>
        <form method="post" action="admin-trash.php" class="admin-trash-selection" onsubmit="return confirm('Wykonać wybraną akcję?');">
            <input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>">
            <div class="admin-trash-toolbar">
                <label class="admin-trash-select-all"><input type="checkbox" data-select-all> Zaznacz wszystko</label>
                <button type="submit" name="action" value="restore">Przywróć zaznaczone</button>
                <button class="admin-danger-action" type="submit" name="action" value="delete">Usuń zaznaczone na stałe</button>
                <button class="admin-danger-action" type="submit" name="action" value="empty">Opróżnij kosz</button>
            </div>
            <div class="admin-trash-list">
                <?php foreach ($items as $item) { $renderTrashNode($item); } ?>
            </div>
        </form>
    <?php endif; ?>
</section>
<?php admin_page_close(); ?>
