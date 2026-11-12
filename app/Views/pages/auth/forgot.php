<?php
/**
 * @var bool $sent
 * @var string|null $error
 */
page_title($title = t('auth.forgot.title'));
?>
<section class="auth">
    <h1><?= e(t('auth.forgot.title')) ?></h1>
    <?php if ($sent): ?>
        <div class="flash flash--success" role="status"><?= e(t('auth.forgot.sent')) ?></div>
        <p class="lead"><?= e(t('auth.forgot.sent_detail', ['minutes' => (int) config('security.reset_minutes')])) ?></p>
        <p class="auth__links"><a href="<?= e(url('/login')) ?>"><?= e(t('auth.forgot.back')) ?></a></p>
    <?php else: ?>
        <p class="lead"><?= e(t('auth.forgot.lead')) ?></p>
        <?php if ($error !== null): ?><div class="flash flash--error" role="alert"><?= e($error) ?></div><?php endif; ?>
        <form class="form" method="post" action="<?= e(url('/forgot-password')) ?>">
            <?= csrf_field() ?>
            <?= partial('partials/field', ['name' => 'email', 'label' => t('auth.field.email'), 'type' => 'email',
                'attrs' => ['required' => true, 'autocomplete' => 'username', 'autofocus' => true]]) ?>
            <button class="btn btn--primary btn--block" type="submit"><?= e(t('auth.forgot.submit')) ?></button>
        </form>
        <p class="auth__links"><a href="<?= e(url('/login')) ?>"><?= e(t('auth.forgot.back')) ?></a></p>
    <?php endif; ?>
</section>
