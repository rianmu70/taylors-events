<?php /** @var array<string,string> $old @var array<string,string> $errors @var list<array{id:int|string,name:string}> $faculties */ ?>
<section class="auth">
<h1><?= e(t('auth.register.title')) ?></h1>

<?php if (isset($errors['form'])): ?>
    <p class="notice notice--error" role="alert"><?= e(t($errors['form'])) ?></p>
<?php endif; ?>

<form class="auth__form" method="post" action="<?= e(url('/register')) ?>">
    <?= csrf_field() ?>

    <label><?= e(t('auth.field.name')) ?>
        <input name="name" value="<?= e($old['name'] ?? '') ?>" autocomplete="name" required>
    </label>
    <?php if (isset($errors['name'])): ?><p class="notice notice--error" role="alert"><?= e(t($errors['name'])) ?></p><?php endif; ?>

    <label><?= e(t('auth.field.email')) ?>
        <input type="email" name="email" value="<?= e($old['email'] ?? '') ?>" autocomplete="username" required>
    </label>
    <?php if (isset($errors['email'])): ?><p class="notice notice--error" role="alert"><?= e(t($errors['email'])) ?></p><?php endif; ?>

    <label><?= e(t('auth.field.password')) ?> <small><?= e(t('auth.field.password_hint')) ?></small>
        <input type="password" name="password" autocomplete="new-password" required>
    </label>
    <?php if (isset($errors['password'])): ?><p class="notice notice--error" role="alert"><?= e(t($errors['password'])) ?></p><?php endif; ?>

    <label><?= e(t('auth.field.faculty')) ?>
        <select name="faculty_id">
            <option value=""><?= e(t('auth.field.faculty_choose')) ?></option>
            <?php foreach ($faculties as $f): ?>
                <option value="<?= e((string) $f['id']) ?>"><?= e($f['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <?php if (isset($errors['faculty'])): ?><p class="notice notice--error" role="alert"><?= e(t($errors['faculty'])) ?></p><?php endif; ?>

    <button class="btn btn--primary" type="submit"><?= e(t('auth.register.submit')) ?></button>
</form>

<p><a href="<?= e(url('/login')) ?>"><?= e(t('auth.register.have_account')) ?></a></p>
</section>
