<?php
/**
 * Main page layout. Every page is rendered inside it.
 *
 * @var string      $content Rendered page HTML (already escaped by the page)
 * @var string|null $title   Page title shown in the browser tab
 */
$pageTitle = isset($title) && $title !== '' ? $title . ' | ' . t('app.name') : t('app.name');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Schibsted+Grotesk:wght@400;500;600;700;800&amp;display=swap">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body>
    <a class="skip-link" href="#main"><?= e(t('nav.skip')) ?></a>
    <?= partial('partials/header') ?>
    <main id="main" class="container">
        <?= $content ?>
    </main>
    <?= partial('partials/footer') ?>
</body>
</html>
