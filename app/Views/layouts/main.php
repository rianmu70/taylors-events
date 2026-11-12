<?php
/**
 * The frame around every page: header, navigation for the current role,
 * flash messages, footer.
 *
 * @var string $content   the rendered page
 * The page sets its title with page_title().
 */
$user = auth_user();
$path = current_path();
$links = match ($user['role'] ?? 'guest') {
    'student' => ['/events' => 'nav.browse', '/my/registrations' => 'nav.my_registrations'],
    'organizer' => ['/events' => 'nav.browse', '/organise/events' => 'nav.hosted', '/organise/events/new' => 'nav.create_event'],
    'admin' => ['/admin' => 'nav.dashboard', '/admin/approvals' => 'nav.approvals', '/admin/venues' => 'nav.venues',
                '/admin/users' => 'nav.users', '/admin/audit' => 'nav.audit', '/admin/outbox' => 'nav.outbox', '/events' => 'nav.browse'],
    default => ['/events' => 'nav.browse'],
};
$isCurrent = static function (string $href) use ($path, $links): bool {
    if ($path === '/' && $href === '/events') {
        return true;
    }
    if ($path !== $href && !str_starts_with($path, $href . '/')) {
        return false;
    }
    // The longest matching link wins, so /organise/events/new does not also light up /organise/events.
    foreach (array_keys($links) as $other) {
        if (strlen($other) > strlen($href) && ($path === $other || str_starts_with($path, $other . '/'))) {
            return false;
        }
    }
    return true;
};
$unread = $user === null ? 0 : unread_notifications();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title><?= e(page_title() !== null ? t('app.title', ['page' => page_title()]) : t('app.name')) ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= e(asset('img/favicon.svg')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <script src="<?= e(asset('js/theme.js')) ?>"></script>
</head>
<body>
<a class="skip-link" href="#main"><?= e(t('nav.skip')) ?></a>

<header class="site-header">
    <div class="container site-header__inner">
        <a class="brand" href="<?= e(url('/events')) ?>">
            <span class="brand__mark" aria-hidden="true">T</span>
            <span class="brand__name"><?= e(t('app.name')) ?></span>
        </a>

        <nav class="nav" aria-label="<?= e(t('nav.label')) ?>">
            <?php foreach ($links as $href => $key): ?>
                <a href="<?= e(url($href)) ?>"<?= $isCurrent($href) ? ' aria-current="page"' : '' ?>><?= e(t($key)) ?></a>
            <?php endforeach; ?>
        </nav>

        <div class="account">
            <button class="icon-btn" type="button" data-theme-toggle aria-label="<?= e(t('nav.theme')) ?>" title="<?= e(t('nav.theme')) ?>">
                <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M12 3a9 9 0 1 0 0 18V3z"/><circle cx="12" cy="12" r="8.25" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>
            </button>
            <?php if ($user === null): ?>
                <a class="btn btn--quiet btn--small" href="<?= e(url('/login')) ?>"><?= e(t('nav.sign_in')) ?></a>
                <a class="btn btn--primary btn--small" href="<?= e(url('/register')) ?>"><?= e(t('nav.register')) ?></a>
            <?php else: ?>
                <a class="icon-btn" href="<?= e(url('/notifications')) ?>" aria-label="<?= e(t_count('nav.notifications', $unread)) ?>" title="<?= e(t_count('nav.notifications', $unread)) ?>">
                    <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" d="M6 16V11a6 6 0 1 1 12 0v5l1.5 2h-15L6 16zM10 21h4"/></svg>
                    <?php if ($unread > 0): ?><span class="badge"><?= e($unread > 9 ? '9+' : $unread) ?></span><?php endif; ?>
                </a>
                <a class="account__name" href="<?= e(url('/profile')) ?>"<?= $path === '/profile' ? ' aria-current="page"' : '' ?>>
                    <?= e($user['full_name']) ?>
                    <span class="account__role"><?= e(t('role.' . $user['role'])) ?></span>
                </a>
                <form method="post" action="<?= e(url('/logout')) ?>">
                    <?= csrf_field() ?>
                    <button class="btn btn--quiet btn--small" type="submit"><?= e(t('nav.sign_out')) ?></button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</header>

<main id="main" class="container main">
    <?= partial('partials/flash') ?>
    <?= $content ?>
</main>

<footer class="site-footer">
    <div class="container site-footer__inner">
        <p><?= e(t('footer.line')) ?></p>
        <p><?= e(t('footer.module')) ?></p>
    </div>
</footer>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
