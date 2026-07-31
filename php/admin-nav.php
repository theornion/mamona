<?php

declare(strict_types=1);

$adminActive = $adminActive ?? '';

function admin_nav_class(string $page, string $active): string
{
    return $page === $active ? ' class="is-active" aria-current="page"' : '';
}

$adminNavCounts = ['topics' => 0, 'proposals' => 0, 'topic-trash' => 0];
try {
    if (function_exists('bueno_database')) {
        $adminNavCounts['topics'] = (int) bueno_database()->query(
            'SELECT COUNT(*) FROM editorial_topics AS topics INNER JOIN posts ON posts.id = topics.primary_post_id
             WHERE topics.trashed_at IS NULL AND topics.purged_at IS NULL AND posts.status != "rejected"'
        )->fetchColumn();
        $adminNavCounts['topic-trash'] = function_exists('count_trashed_editorial_topics') ? count_trashed_editorial_topics() : 0;
        $adminNavCounts['proposals'] = function_exists('list_ready_article_proposals') ? count(list_ready_article_proposals()) : 0;
    }
} catch (Throwable) {
    // Nawigacja pozostaje dostępna także podczas awaryjnej migracji bazy.
}
?>
<nav id="nav" class="admin-nav" aria-label="Panel administratora">
    <div class="admin-nav-header">
        <a class="admin-nav-brand" href="admin-content-studio.php"><span class="admin-nav-mark" aria-hidden="true">M</span><span>Mamona<small>Panel redakcyjny</small></span></a>
        <button class="admin-nav-close" type="button" aria-label="Zamknij menu"><span aria-hidden="true">×</span></button>
    </div>
    <p class="admin-nav-workflow">RSS <span>→</span> tematy <span>→</span> review <span>→</span> publikacja</p>
    <div class="admin-nav-scroll">
        <section class="admin-nav-group" aria-labelledby="nav-editorial"><h2 id="nav-editorial">Praca redakcyjna</h2><ul class="admin-nav-links">
            <li><a href="admin-content-studio.php"<?php echo admin_nav_class('studio', $adminActive); ?>><span aria-hidden="true">01</span>Studio / RSS</a></li>
            <li><a href="admin-editorial-topics.php"<?php echo admin_nav_class('topics', $adminActive); ?>><span aria-hidden="true">02</span>Tematy <strong class="admin-nav-count"><?php echo $adminNavCounts['topics']; ?></strong></a></li>
            <li><a href="admin-proposals.php"<?php echo admin_nav_class('proposals', $adminActive); ?>><span aria-hidden="true">03</span>Gotowe propozycje <strong class="admin-nav-count"><?php echo $adminNavCounts['proposals']; ?></strong></a></li>
            <li><a href="admin-topic-trash.php"<?php echo admin_nav_class('topic-trash', $adminActive); ?>><span aria-hidden="true">04</span>Kosz <strong class="admin-nav-count"><?php echo $adminNavCounts['topic-trash']; ?></strong></a></li>
        </ul></section>
        <section class="admin-nav-group" aria-labelledby="nav-tools"><h2 id="nav-tools">Narzędzia zaawansowane</h2><ul class="admin-nav-links">
            <li><a href="admin-generation.php"<?php echo admin_nav_class('generation', $adminActive); ?>>Operacje API / Diagnostyka</a></li>
            <li><a href="admin-editorial-queue.php"<?php echo admin_nav_class('editorial', $adminActive); ?>>Procesy / Historia</a></li>
        </ul></section>
        <section class="admin-nav-group" aria-labelledby="nav-content"><h2 id="nav-content">Treści</h2><ul class="admin-nav-links">
            <li><a href="admin-posts.php"<?php echo admin_nav_class('posts', $adminActive); ?>>Posty i kategorie</a></li>
            <li><a href="admin-gallery.php"<?php echo admin_nav_class('gallery', $adminActive); ?>>Galerie</a></li>
        </ul></section>
        <section class="admin-nav-group" aria-labelledby="nav-automation"><h2 id="nav-automation">Źródła i automatyzacja</h2><ul class="admin-nav-links">
            <li><a href="admin-technical-sources.php"<?php echo admin_nav_class('sources', $adminActive); ?>>Źródła techniczne</a></li>
        </ul></section>
        <section class="admin-nav-group" aria-labelledby="nav-admin"><h2 id="nav-admin">Ustawienia i administracja</h2><ul class="admin-nav-links">
            <li><a href="admin-messages.php"<?php echo admin_nav_class('messages', $adminActive); ?>>Wiadomości</a></li>
            <li><a href="admin-styles.php"<?php echo admin_nav_class('styles', $adminActive); ?>>Wygląd strony</a></li>
            <li><a href="admin-contact.php"<?php echo admin_nav_class('contact', $adminActive); ?>>Dane kontaktowe</a></li>
            <li><a href="admin-social.php"<?php echo admin_nav_class('social', $adminActive); ?>>Social media</a></li>
            <li><a href="admin-profile.php"<?php echo admin_nav_class('profile', $adminActive); ?>>Profil</a></li>
            <li><a href="admin-trash.php"<?php echo admin_nav_class('trash', $adminActive); ?>>Kosz treści i wiadomości</a></li>
        </ul></section>
    </div>
</nav>
<button class="admin-nav-toggle" type="button" aria-controls="nav" aria-expanded="false"><span aria-hidden="true">☰</span><span>Menu</span></button>
