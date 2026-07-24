<?php

declare(strict_types=1);

require_once __DIR__ . '/admin-auth.php';
require_once __DIR__ . '/admin-database.php';
require_once __DIR__ . '/admin-ui.php';

require_admin_login();

$type = (string) ($_GET['type'] ?? '');
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$record = null;
$title = 'Podgląd elementu';
$body = '';

if ($type === 'post') {
    $record = $id > 0 ? find_post($id, true) : null;
    if (is_array($record)) {
        $title = (string) $record['title'];
        $body = '<p class="admin-kicker">Niepubliczny post</p><h1>' . escape_html($record['title']) . '</h1><p>' . escape_html($record['excerpt']) . '</p><div class="post-page-body">' . nl2br(escape_html($record['content'])) . '</div>';
    }
} elseif ($type === 'category') {
    $record = $id > 0 ? find_post_category($id, true) : null;
    if (is_array($record)) {
        $title = (string) $record['title'];
        $body = '<p class="admin-kicker">Niepubliczna kategoria</p><h1>' . escape_html($record['title']) . '</h1><p>' . escape_html($record['description']) . '</p>';
        foreach (list_posts($id, false, true) as $post) $body .= '<hr><h2>' . escape_html($post['title']) . '</h2><p>' . escape_html($post['excerpt']) . '</p>';
    }
} elseif ($type === 'gallery') {
    $record = $id > 0 ? find_gallery($id, true) : null;
    if (is_array($record)) {
        $title = (string) $record['title'];
        $body = '<p class="admin-kicker">Niepubliczna galeria</p><h1>' . escape_html($record['title']) . '</h1><p>' . escape_html($record['description']) . '</p><div class="admin-cat-grid">';
        foreach (list_gallery_items($id, true) as $item) $body .= '<figure class="admin-cat-thumb"><img src="' . escape_html(admin_asset_url($item['image_path'])) . '" alt=""><figcaption>' . escape_html($item['name']) . '</figcaption></figure>';
        $body .= '</div>';
    }
} elseif ($type === 'gallery_item') {
    $statement = bueno_database()->prepare('SELECT * FROM gallery_items WHERE id = :id');
    $statement->execute([':id' => $id]);
    $record = $statement->fetch();
    if (is_array($record)) {
        $title = (string) $record['name'];
        $body = '<p class="admin-kicker">Niepubliczne zdjęcie galerii</p><h1>' . escape_html($record['name']) . '</h1><img class="admin-trash-preview-image" src="' . escape_html(admin_asset_url($record['image_path'])) . '" alt=""><p>' . escape_html($record['description']) . '</p>';
    }
} elseif ($type === 'cat') {
    $record = $id > 0 ? find_cat($id, true) : null;
    if (is_array($record)) $body = '<p class="admin-kicker">Niepubliczny element galerii Nasze koty</p><h1>' . escape_html($record['name']) . '</h1><img class="admin-trash-preview-image" src="' . escape_html(admin_asset_url($record['image_path'])) . '" alt=""><p>' . escape_html($record['description']) . '</p>';
} elseif ($type === 'message') {
    $record = $id > 0 ? find_message($id) : null;
    if (is_array($record)) $body = '<p class="admin-kicker">Wiadomość w koszu</p><h1>' . escape_html($record['subject'] !== '' ? $record['subject'] : $record['name']) . '</h1><p>' . escape_html($record['message']) . '</p>';
}

admin_page_open($title, 'trash');
?>
<section class="post admin-card admin-trash-preview">
    <?php if ($body === ''): ?>
        <h1>Nie znaleziono elementu</h1>
        <p>Element mógł zostać już trwale usunięty.</p>
    <?php else: ?>
        <?php echo $body; ?>
    <?php endif; ?>
    <ul class="actions"><li><a class="button" href="admin-trash.php">Wróć do kosza</a></li></ul>
</section>
<?php admin_page_close(); ?>
