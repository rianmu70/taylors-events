<?php
/**
 * @var string $token
 * @var bool $usable       false when the link is used, expired or wrong
 * @var string|null $error
 */
page_title($title = t('auth.reset.title'));
?>
<section class="auth">
    <h1><?= e(t('auth.reset.title')) ?></h1>
    <?php if (!$usable): ?>
        <div class="flash flash--error" role="alert"><?= e(t('auth.reset.invalid')) ?></div>
        <p class="auth__links"><a href="<?= e(url('/forgot-password')) ?>"><?= e(t('auth.reset.again')) ?></a></p>
    <?php else: ?>
        <p class="lead"><?= e(t('auth.reset.lead')) ?></p>
        <?php if ($error !== null): ?><div class="flash flash--error" role="alert"><?= e($error) ?></div><?php endif; ?>
        <form class="form" method="post" action="<?= e(url('/reset-password/' . $token)) ?>">
            <?= csrf_field() ?>
            <?= partial('partials/field', ['name' => 'password', 'label' => t('auth.field.new_password'), 'type' => 'password',
                'hint' => t('auth.field.password_hint', ['min' => (int) config('security.password_min')]),
                'attrs' => ['required' => true, 'autocomplete' => 'new-password', 'autofocus' => true]]) ?>
            <?= partial('partials/field', ['name' => 'password_confirm', 'label' => t('auth.field.password_confirm'), 'type' => 'password',
                'attrs' => ['required' => true, 'autocomplete' => 'new-password']]) ?>
            <button class="btn btn--primary btn--block" type="submit"><?= e(t('auth.reset.submit')) ?></button>
        </form>
    <?php endif; ?>
</section>
