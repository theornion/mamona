<?php

declare(strict_types=1);

function admin_ui_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$css = (string) file_get_contents($root . '/assets/css/admin.css');
$layout = (string) file_get_contents($root . '/php/admin-ui.php');
$login = (string) file_get_contents($root . '/php/admin-login.php');
$nav = (string) file_get_contents($root . '/php/admin-nav.php');
$panelJs = (string) file_get_contents($root . '/assets/js/admin-panel.js');

admin_ui_assert(str_contains($layout, '<html lang="pl" class="admin-ui">'), 'Admin layout does not enable compact UI scaling.');
admin_ui_assert(str_contains($login, '<html lang="pl" class="admin-ui">'), 'Admin login does not enable compact UI scaling.');
admin_ui_assert(str_contains($css, 'html.admin-ui') && str_contains($css, 'font-size:90%'), 'Admin UI scale is not 90%.');
admin_ui_assert(str_contains($layout, 'admin.css?v=admin-density-20260904a') && str_contains($login, 'admin.css?v=admin-density-20260904a'), 'Admin layouts do not share the current CSS cache version.');

admin_ui_assert(str_contains($layout, 'assets/css/admin.css'), 'Layout admina nie ładuje osobnego arkusza.');
admin_ui_assert(str_contains($login, 'assets/css/admin.css'), 'Logowanie nie ładuje osobnego arkusza.');
admin_ui_assert(str_contains($css, 'width:100%!important') && str_contains($css, 'max-width:none!important'), 'Workspace admina nie wykorzystuje pełnej szerokości.');
admin_ui_assert(str_contains($css, 'grid-template-columns:var(--admin-sidebar) minmax(0,1fr)'), 'Brak desktopowego układu sidebar + workspace.');
admin_ui_assert(str_contains($css, 'grid-template-rows:auto auto') && str_contains($css, 'align-content:start'), 'Workspace ucina długą treść do wysokości viewportu.');
admin_ui_assert(str_contains($css, 'digital_rain.webp') && str_contains($css, 'digital_rain-mobile.webp'), 'Usunięto matrixowe tło.');
admin_ui_assert(str_contains($css, '@media (prefers-reduced-motion:reduce)'), 'Brak reduced-motion.');
admin_ui_assert(str_contains($css, ':focus-visible'), 'Brak wyraźnego focus-visible.');
admin_ui_assert(str_contains($css, 'button.content-studio-cta') && str_contains($css, '-webkit-text-fill-color:#eafff7!important'), 'CTA pobierania RSS ma nieczytelny tekst w stanie spoczynkowym.');
admin_ui_assert(str_contains($layout, 'admin-breadcrumb') && str_contains($layout, 'aria-current="page"'), 'Brak kontekstu/breadcrumba.');
admin_ui_assert(str_contains($nav, 'aria-current="page"'), 'Aktywny element nawigacji nie ma semantyki.');
admin_ui_assert(str_contains($panelJs, "event.key === 'Escape'") && str_contains($panelJs, "aria-expanded"), 'Menu mobilne nie obsługuje klawiatury/ARIA.');
admin_ui_assert(str_contains($layout, 'data-admin-config-reminder-close'), 'Przypomnienie konfiguracyjne nie ma przycisku zamknięcia.');
admin_ui_assert(str_contains($panelJs, "sessionStorage.setItem('mamona-admin-config-reminder-dismissed'"), 'Zamknięcie przypomnienia nie jest pamiętane w sesji karty.');

foreach (['admin-content-studio.php', 'admin-editorial-topics.php', 'admin-proposals.php', 'admin-topic-trash.php', 'admin-monetization.php', 'admin-posts.php', 'admin-gallery.php', 'admin-technical-sources.php', 'admin-messages.php', 'admin-styles.php', 'admin-contact.php', 'admin-social.php', 'admin-profile.php', 'admin-trash.php'] as $route) {
    admin_ui_assert(str_contains($nav, 'href="' . $route . '"'), 'Nawigacja zgubiła funkcję: ' . $route);
}
foreach (['admin-generation.php', 'admin-editorial-queue.php'] as $hiddenRoute) {
    admin_ui_assert(!str_contains($nav, 'href="' . $hiddenRoute . '"'), 'Nawigacja nadal pokazuje widok diagnostyczny: ' . $hiddenRoute);
}
admin_ui_assert(str_contains($css, '.technical-source-grid') && str_contains($css, 'grid-template-columns:repeat(2,minmax(0,1fr))!important'), 'Formularz źródła nie ma bezpiecznego układu dwukolumnowego.');
admin_ui_assert(str_contains($css, '@media (max-width:736px)') && str_contains($css, '.technical-source-delete'), 'Brak responsywnego formularza źródła lub stylu akcji usuwania.');

foreach (array_merge([$root . '/index.html'], glob($root . '/pages/*.html') ?: []) as $publicPage) {
    $html = (string) file_get_contents($publicPage);
    admin_ui_assert(!str_contains($html, 'assets/css/admin.css'), 'Admin CSS przecieka do strony publicznej: ' . basename($publicPage));
    admin_ui_assert(!str_contains($html, 'class="admin-main"'), 'Admin layout przecieka do strony publicznej: ' . basename($publicPage));
}

$backup = $root . '/backups/admin-ui-pre-redesign-20260731';
admin_ui_assert(is_file($backup . '/manifest.json'), 'Brak manifestu backupu.');
admin_ui_assert(is_file($backup . '/README.md'), 'Brak instrukcji rollbacku.');
admin_ui_assert(count(glob($backup . '/screenshots/before/*.png') ?: []) >= 10, 'Backup nie zawiera referencyjnych screenshotów.');
$manifest = json_decode(ltrim((string) file_get_contents($backup . '/manifest.json'), "\xEF\xBB\xBF"), true);
admin_ui_assert(is_array($manifest) && count($manifest) >= 40, 'Manifest backupu jest niekompletny.');

foreach (glob($root . '/php/admin-*.php') ?: [] as $adminFile) {
    $source = (string) file_get_contents($adminFile);
    if (str_contains($source, '<form')) {
        admin_ui_assert(preg_match('/<form\b[^>]*method="(?:post|get)"/i', $source) === 1, 'Formularz bez jawnej metody: ' . basename($adminFile));
    }
}

echo "ADMIN_UI_REDESIGN_SMOKE_OK\n";
