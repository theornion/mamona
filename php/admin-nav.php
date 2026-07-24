<?php

declare(strict_types=1);

$adminActive = $adminActive ?? '';

function admin_nav_class(string $page, string $active): string
{
    return $page === $active ? ' class="is-active"' : '';
}
?>
<nav id="nav" class="admin-nav" aria-label="Panel administratora">
    <a class="admin-nav-brand" href="admin-posts.php">CMS <span>Panel</span></a>
    <ul class="links admin-nav-links">
        <li><a href="admin-posts.php"<?php echo admin_nav_class('posts', $adminActive); ?>>Posty</a></li>
        <li><a href="admin-gallery.php"<?php echo admin_nav_class('gallery', $adminActive); ?>>Galerie</a></li>
        <li><a href="admin-messages.php"<?php echo admin_nav_class('messages', $adminActive); ?>>Wiadomości</a></li>
        <li><a href="admin-styles.php"<?php echo admin_nav_class('styles', $adminActive); ?>>Wygląd strony</a></li>
        <li class="admin-nav-dropdown<?php echo in_array($adminActive, ['contact', 'social'], true) ? ' is-active' : ''; ?>">
            <a href="admin-contact.php"<?php echo admin_nav_class('contact', $adminActive); ?>>Dane kontaktowe</a>
            <ul class="admin-nav-dropdown-menu">
                <li><a href="admin-contact.php"<?php echo admin_nav_class('contact', $adminActive); ?>>Dane podstawowe</a></li>
                <li><a href="admin-social.php"<?php echo admin_nav_class('social', $adminActive); ?>>Social media</a></li>
            </ul>
        </li>
        <li><a href="admin-trash.php"<?php echo admin_nav_class('trash', $adminActive); ?>>Kosz</a></li>
        <li><a href="admin-profile.php"<?php echo admin_nav_class('profile', $adminActive); ?>>Profil</a></li>
    </ul>
</nav>
