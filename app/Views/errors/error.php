<?php
/**
 * One template for every error status.
 *
 * @var int             $status
 * @var string          $title
 * @var string          $message
 * @var \Throwable|null $exception Only set when APP_DEBUG=true
 */
?>
<section class="error-page">
    <p class="error-page__code"><?= e(t('error.code', ['status' => $status])) ?></p>
    <h1><?= e($title) ?></h1>
    <p class="lead"><?= e($message) ?></p>
    <a class="btn btn--primary" href="<?= e(url('/')) ?>"><?= e(t('error.home')) ?></a>

    <?php if ($exception !== null): ?>
        <details class="error-page__debug" open>
            <summary><?= e(t('error.details')) ?></summary>
            <p><strong><?= e($exception::class) ?></strong>: <?= e($exception->getMessage()) ?></p>
            <p><?= e($exception->getFile()) ?>:<?= e($exception->getLine()) ?></p>
            <pre><?= e($exception->getTraceAsString()) ?></pre>
        </details>
    <?php endif; ?>
</section>
