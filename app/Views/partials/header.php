<?php
/**
 * Site header and main menu.
 * Phase 2 adds the signed-in links (My events, Organize, Admin, Sign out)
 * based on the user's role; the labels already exist in lang/en.php.
 */
$links = [
    ['path' => '/', 'label' => t('nav.browse')],
];
$here = current_path();
?>
<header class="site-header">
    <div class="container site-header__inner">
        <a class="brand" href="<?= e(url('/')) ?>">
            <svg class="brand__mark" width="30" height="30" viewBox="0 0 30 30" aria-hidden="true">
                <rect width="30" height="30" rx="7" fill="currentColor"></rect>
                <path d="M8 10h14M15 10v12" stroke="#FFFFFF" stroke-width="3" stroke-linecap="round"></path>
            </svg>
            <span class="brand__name"><?= e(t('app.name')) ?></span>
        </a>
        <nav class="site-nav" aria-label="<?= e(t('nav.main')) ?>">
            <?php foreach ($links as $link): ?>
                <a class="site-nav__link" href="<?= e(url($link['path'])) ?>"<?= $here === $link['path'] ? ' aria-current="page"' : '' ?>><?= e($link['label']) ?></a>
            <?php endforeach; ?>
        </nav>
    </div>
</header>
