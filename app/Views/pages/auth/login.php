<?php
/**
 * @var string $next   where to go after signing in
 * @var string $email
 * @var string|null $error
 */
page_title($title = t('auth.login.title'));
?>
<section class="auth">
    <h1><?= e(t('auth.login.title')) ?></h1>
    <p class="lead"><?= e(t('auth.login.lead')) ?></p>

    <?php if ($error !== null): ?><div class="flash flash--error" role="alert"><?= e($error) ?></div><?php endif; ?>

    <form class="form" method="post" action="<?= e(url('/login')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="next" value="<?= e($next) ?>">
        <?= partial('partials/field', ['name' => 'email', 'label' => t('auth.field.email'), 'type' => 'email', 'value' => $email,
            'attrs' => ['required' => true, 'autocomplete' => 'username', 'autofocus' => true]]) ?>
        <?= partial('partials/field', ['name' => 'password', 'label' => t('auth.field.password'), 'type' => 'password',
            'attrs' => ['required' => true, 'autocomplete' => 'current-password']]) ?>
        <button class="btn btn--primary btn--block" type="submit"><?= e(t('auth.login.submit')) ?></button>
    </form>

    <p class="auth__links">
        <a href="<?= e(url('/forgot-password')) ?>"><?= e(t('auth.login.forgot')) ?></a>
        <a href="<?= e(url('/register')) ?>"><?= e(t('auth.login.register')) ?></a>
    </p>
</section>
