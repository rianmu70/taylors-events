<?php
$user = auth_user();
$role = $user['role'] ?? null;
$links = [['path' => '/events', 'label' => t('nav.browse')]];
if ($role === 'student') {
    $links[] = ['path' => '/my-registrations', 'label' => t('nav.my_events')];
}
if (in_array($role, ['organizer', 'admin'], true)) {
    $links[] = ['path' => '/organize/events', 'label' => t('nav.organize')];
}
if ($role === 'admin') {
    $links[] = ['path' => '/admin/events/pending', 'label' => t('nav.admin')];
}
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
            <?php if ($user !== null): ?>
                <form method="post" action="<?= e(url('/logout')) ?>">
                    <?= csrf_field() ?>
                    <button class="site-nav__logout" type="submit"><?= e(t('nav.sign_out')) ?></button>
                </form>
            <?php else: ?>
                <a class="site-nav__link" href="<?= e(url('/login')) ?>"><?= e(t('nav.sign_in')) ?></a>
                <a class="site-nav__link" href="<?= e(url('/register')) ?>"><?= e(t('nav.create_account')) ?></a>
            <?php endif; ?>
        </nav>
    </div>
</header>
