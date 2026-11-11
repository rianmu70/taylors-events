<?php /** @var string $next @var string $email @var ?string $error */ ?>
<section class="auth">
<h1><?= e(t('auth.login.title')) ?></h1>

<?php if ($error !== null): ?>
    <p class="notice notice--error" role="alert"><?= e(t($error)) ?></p>
<?php endif; ?>

<form class="auth__form" method="post" action="<?= e(url('/login')) ?>">
    <?= csrf_field() ?>
    <?php if ($next !== ''): ?>
        <input type="hidden" name="next" value="<?= e($next) ?>">
    <?php endif; ?>

    <label><?= e(t('auth.field.email')) ?>
        <input type="email" name="email" value="<?= e($email) ?>" autocomplete="username" required>
    </label>
    <label><?= e(t('auth.field.password')) ?>
        <input type="password" name="password" autocomplete="current-password" required>
    </label>
    <button class="btn btn--primary" type="submit"><?= e(t('auth.login.submit')) ?></button>
</form>

<p><a href="<?= e(url('/register')) ?>"><?= e(t('auth.login.create_account')) ?></a></p>
</section>
