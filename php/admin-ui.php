<?php

declare(strict_types=1);

function admin_asset_url(string $path): string
{
    return '../' . ltrim($path, '/');
}

function admin_page_open(string $title, string $active): void
{
    ?><!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=no">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title><?php echo escape_html($title); ?> | CMS</title>
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="stylesheet" href="../assets/css/main.css?v=bueno-release-20260721c">
</head>
<body class="is-preload admin-page">
    <div id="wrapper" class="fade-in">
        <main id="main" class="admin-main">
        <?php $adminActive = $active; require __DIR__ . '/admin-nav.php'; ?>
    <?php
}

function admin_page_close(): void
{
    ?></main>
    </div>
    <script src="../assets/js/jquery.min.js?v=bueno-release-20260721c"></script>
    <script src="../assets/js/breakpoints.min.js?v=bueno-release-20260721c"></script>
    <script src="../assets/js/browser.min.js?v=bueno-release-20260721c"></script>
    <script src="../assets/js/util.js?v=bueno-release-20260721c"></script>
    <script src="../assets/js/main.js?v=bueno-release-20260721c"></script>
    <script src="../assets/js/parallax.js?v=bueno-release-20260721c"></script>
    <script src="../assets/js/admin-post-editor.js?v=bueno-release-20260721c"></script>
    <script src="../assets/js/admin-gallery-crop.js?v=bueno-release-20260721c"></script>
    <script src="../assets/js/admin-panel.js?v=bueno-release-20260721c"></script>
    <script src="../assets/js/admin-sortable.js?v=bueno-release-20260721c"></script>
</body>
</html>
    <?php
}
