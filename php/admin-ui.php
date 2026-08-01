<?php

declare(strict_types=1);

require_once __DIR__ . '/app-config.php';

function admin_asset_url(string $path): string
{
    return '../' . ltrim($path, '/');
}

function admin_page_open(string $title, string $active): void
{
    $pageLabels = [
        'studio' => 'Studio redakcyjne', 'topics' => 'Tematy', 'generation' => 'Generowanie',
        'action-required' => 'Wymagające akcji', 'proposals' => 'Gotowe propozycje', 'editorial' => 'Procesy / Historia', 'posts' => 'Posty',
        'gallery' => 'Galerie', 'sources' => 'Źródła techniczne', 'messages' => 'Wiadomości',
        'styles' => 'Wygląd strony', 'contact' => 'Dane kontaktowe', 'social' => 'Social media',
        'trash' => 'Kosz treści', 'topic-trash' => 'Kosz tematów', 'profile' => 'Profil',
    ];
    $nextSteps = [
        'studio' => ['Tematy', 'admin-editorial-topics.php'],
        'topics' => ['Przejdź do propozycji', 'admin-proposals.php'],
        'action-required' => ['Przejdź do gotowych', 'admin-proposals.php'],
        'generation' => ['Wróć do tematów', 'admin-editorial-topics.php'],
        'proposals' => ['Zarządzaj postami', 'admin-posts.php'],
        'editorial' => ['Wróć do tematów', 'admin-editorial-topics.php'],
        'topic-trash' => ['Wróć do tematów', 'admin-editorial-topics.php'],
    ];
    $pageLabel = $pageLabels[$active] ?? $title;
    $nextStep = $nextSteps[$active] ?? null;
    ?><!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title><?php echo escape_html($title); ?> | CMS</title>
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="stylesheet" href="../assets/css/main.css?v=bueno-release-20260721c">
    <link rel="stylesheet" href="../assets/css/admin.css?v=admin-redesign-20260731a">
</head>
<body class="is-preload admin-page admin-page-<?php echo escape_html($active); ?>">
    <a class="admin-skip-link" href="#admin-content">Przejdź do treści</a>
    <div id="wrapper" class="fade-in">
        <main id="main" class="admin-main">
        <?php $adminActive = $active; require __DIR__ . '/admin-nav.php'; ?>
        <div class="admin-context" id="admin-content">
            <nav class="admin-breadcrumb" aria-label="Okruszki"><a href="admin-content-studio.php">Panel</a><span aria-hidden="true">/</span><span aria-current="page"><?php echo escape_html($pageLabel); ?></span></nav>
            <?php if ($nextStep !== null): ?><a class="admin-next-step" href="<?php echo escape_html($nextStep[1]); ?>"><span>Następny krok</span><?php echo escape_html($nextStep[0]); ?> <span aria-hidden="true">→</span></a><?php endif; ?>
        </div>
        <?php
        $configIssues = app_config_issues();
        if (function_exists('trust_configuration_issues')) {
            $configIssues = array_merge($configIssues, trust_configuration_issues());
        }
        if ($configIssues !== []):
        ?>
            <aside class="admin-config-reminder" data-admin-config-reminder aria-labelledby="admin-config-reminder-title">
                <div class="admin-config-reminder-header">
                    <div>
                        <p class="admin-config-reminder-kicker">Przed publikacją</p>
                        <h2 id="admin-config-reminder-title">Uzupełnij konfigurację produkcyjną</h2>
                    </div>
                    <button class="admin-config-reminder-close" type="button" data-admin-config-reminder-close aria-label="Zamknij przypomnienie">×</button>
                </div>
                <div class="admin-config-reminder-body">
                <?php foreach ($configIssues as $configIssue): ?>
                    <p class="admin-notice <?php echo $configIssue['level'] === 'error' ? 'is-error' : ''; ?>" role="status">
                        <?php echo escape_html((string) $configIssue['message']); ?>
                    </p>
                <?php endforeach; ?>
                </div>
            </aside>
        <?php endif; ?>
    <?php
}

function admin_page_close(): void
{
    ?>  <button class="admin-nav-backdrop" type="button" aria-label="Zamknij menu" tabindex="-1"></button>
        </main>
    </div>
    <script src="../assets/js/performance-mode.js?v=cms-perf-20260731"></script>
    <script src="../assets/js/jquery.min.js?v=bueno-release-20260721c"></script>
    <script src="../assets/js/breakpoints.min.js?v=bueno-release-20260721c"></script>
    <script src="../assets/js/browser.min.js?v=bueno-release-20260721c"></script>
    <script src="../assets/js/util.js?v=bueno-release-20260721c"></script>
    <script src="../assets/js/main.js?v=bueno-release-20260721c"></script>
    <script src="../assets/js/admin-post-editor.js?v=bueno-release-20260721c"></script>
    <script src="../assets/js/admin-gallery-crop.js?v=bueno-release-20260721c"></script>
    <script src="../assets/js/admin-panel.js?v=bueno-release-20260721c"></script>
    <script src="../assets/js/admin-sortable.js?v=bueno-release-20260721c"></script>
    <script src="../assets/js/admin-generation.js?v=bueno-release-20260724a"></script>
    <script src="../assets/js/admin-content-studio.js?v=bueno-release-20260731b"></script>
    <script src="../assets/js/topic-filter-state.js?v=topics-filter-20260801a"></script>
    <script src="../assets/js/admin-editorial-topics.js?v=topics-filter-20260801a"></script>
</body>
</html>
    <?php
}
