<?php
/** @var list<array<string,mixed>> $items */
page_title($title = t('notifications.title'));
?>
<header class="page-head">
    <h1><?= e(t('notifications.title')) ?></h1>
</header>

<?php if ($items === []): ?>
    <div class="empty">
        <h2><?= e(t('notifications.empty')) ?></h2>
        <p><?= e(t('notifications.empty_hint')) ?></p>
    </div>
<?php else: ?>
    <ul class="notice-list">
        <?php foreach ($items as $item): ?>
            <li class="notice<?= $item['read_at'] === null ? ' notice--new' : '' ?>">
                <p class="notice__title">
                    <?php if ($item['link_url'] !== null): ?>
                        <a href="<?= e(url((string) $item['link_url'])) ?>"><?= e($item['title']) ?></a>
                    <?php else: ?>
                        <?= e($item['title']) ?>
                    <?php endif; ?>
                    <?php if ($item['read_at'] === null): ?><span class="pill pill--new"><?= e(t('notifications.new')) ?></span><?php endif; ?>
                </p>
                <p><?= e($item['body']) ?></p>
                <p class="muted"><time datetime="<?= e($item['created_at']) ?>"><?= e(format_datetime((string) $item['created_at'])) ?></time></p>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
