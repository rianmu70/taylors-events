<?php
/** @var list<array<string,mixed>> $messages */
page_title($title = t('outbox.title'));
?>
<header class="page-head">
    <h1><?= e(t('outbox.title')) ?></h1>
    <p class="lead"><?= e(t('outbox.lead')) ?></p>
</header>

<?php if ($messages === []): ?>
    <div class="empty"><h2><?= e(t('outbox.empty')) ?></h2><p><?= e(t('outbox.empty_hint')) ?></p></div>
<?php else: ?>
    <ul class="notice-list">
        <?php foreach ($messages as $message): ?>
            <li class="notice">
                <p class="notice__title"><?= e($message['subject']) ?></p>
                <p class="muted"><?= e(t('outbox.to', ['email' => $message['to_email']])) ?>, <time datetime="<?= e($message['created_at']) ?>"><?= e(format_datetime((string) $message['created_at'])) ?></time></p>
                <pre class="snapshot snapshot--mail"><?= e($message['body_text']) ?></pre>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
