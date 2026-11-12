<?php
/**
 * @var list<array{id:int,code:string,name:string}> $faculties
 * @var array<string,mixed> $old
 * @var array<string,string> $errors
 */
page_title($title = t('auth.register.title'));
?>
<section class="auth">
    <h1><?= e(t('auth.register.title')) ?></h1>
    <p class="lead"><?= e(t('auth.register.lead')) ?></p>

    <?php if (isset($errors['form'])): ?><div class="flash flash--error" role="alert"><?= e($errors['form']) ?></div><?php endif; ?>

    <form class="form" method="post" action="<?= e(url('/register')) ?>">
        <?= csrf_field() ?>
        <?= partial('partials/field', ['name' => 'full_name', 'label' => t('auth.field.full_name'), 'value' => $old['full_name'] ?? '', 'errors' => $errors,
            'attrs' => ['required' => true, 'autocomplete' => 'name', 'maxlength' => 100]]) ?>
        <?= partial('partials/field', ['name' => 'email', 'label' => t('auth.field.email'), 'type' => 'email', 'value' => $old['email'] ?? '', 'errors' => $errors,
            'hint' => t('auth.field.email_hint'), 'attrs' => ['required' => true, 'autocomplete' => 'username']]) ?>
        <?= partial('partials/field', ['name' => 'student_number', 'label' => t('auth.field.student_number'), 'value' => $old['student_number'] ?? '', 'errors' => $errors,
            'hint' => t('auth.field.student_number_hint'), 'attrs' => ['inputmode' => 'numeric', 'maxlength' => 12]]) ?>
        <?= partial('partials/field', ['name' => 'faculty_id', 'label' => t('auth.field.faculty'), 'type' => 'select', 'value' => $old['faculty_id'] ?? '', 'errors' => $errors,
            'options' => array_column($faculties, 'name', 'id'), 'attrs' => ['required' => true]]) ?>
        <?= partial('partials/field', ['name' => 'password', 'label' => t('auth.field.password'), 'type' => 'password', 'errors' => $errors,
            'hint' => t('auth.field.password_hint', ['min' => (int) config('security.password_min')]),
            'attrs' => ['required' => true, 'autocomplete' => 'new-password', 'minlength' => (int) config('security.password_min')]]) ?>
        <button class="btn btn--primary btn--block" type="submit"><?= e(t('auth.register.submit')) ?></button>
    </form>

    <p class="auth__links"><a href="<?= e(url('/login')) ?>"><?= e(t('auth.register.have_account')) ?></a></p>
</section>
