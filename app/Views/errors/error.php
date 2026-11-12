<?php
/**
 * 403, 404, 419, 429, 500 and the rest. Wording: "error.<status>" in the language file.
 *
 * @var int $status
 * @var string $title
 * @var string $message
 * @var Throwable|null $exception only when APP_DEBUG is on
 */
page_title($title);
?>
<section class="error-page">
    <p class="error-page__code"><?= e($status) ?></p>
    <h1><?= e($title) ?></h1>
    <p class="lead"><?= e($message) ?></p>
    <p class="actions">
        <a class="btn btn--primary" href="<?= e(url('/events')) ?>"><?= e(t('error.back')) ?></a>
    </p>
    <?php if (!empty($exception)): ?>
        <pre class="debug"><?= e($exception::class . ': ' . $exception->getMessage() . "\n" . $exception->getFile() . ':' . $exception->getLine()) ?></pre>
    <?php endif; ?>
</section>
