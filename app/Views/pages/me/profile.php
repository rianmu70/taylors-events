<?php
/**
 * @var array<string,mixed> $profile
 * @var list<array{id:int,code:string,name:string}> $faculties
 * @var list<array{id:int,name:string}> $clubs clubs this user runs
 * @var array<string,string> $errors
 * @var array<string,mixed> $old
 */
page_title($title = t('profile.title'));
?>
<header class="page-head">
    <h1><?= e(t('profile.title')) ?></h1>
    <p class="lead"><?= e($profile['email']) ?>, <?= e(t('role.' . $profile['role'])) ?><?php if ($profile['student_number'] !== null): ?>, <?= e($profile['student_number']) ?><?php endif; ?></p>
</header>

<div class="split">
    <section class="panel">
        <h2><?= e(t('profile.details')) ?></h2>
        <form class="form" method="post" action="<?= e(url('/profile')) ?>">
            <?= csrf_field() ?>
            <?= partial('partials/field', ['name' => 'full_name', 'label' => t('auth.field.full_name'), 'value' => $old['full_name'] ?? $profile['full_name'], 'errors' => $errors,
                'attrs' => ['required' => true, 'maxlength' => 100, 'autocomplete' => 'name']]) ?>
            <?= partial('partials/field', ['name' => 'faculty_id', 'label' => t('auth.field.faculty'), 'type' => 'select', 'value' => $old['faculty_id'] ?? $profile['faculty_id'], 'errors' => $errors,
                'options' => array_column($faculties, 'name', 'id')]) ?>
            <?= partial('partials/field', ['name' => 'bio', 'label' => t('profile.bio'), 'type' => 'textarea', 'value' => $old['bio'] ?? $profile['bio'], 'errors' => $errors,
                'hint' => t('profile.bio_hint'), 'attrs' => ['maxlength' => 500, 'rows' => 4]]) ?>
            <button class="btn btn--primary" type="submit"><?= e(t('profile.save')) ?></button>
        </form>
        <?php if ($clubs !== []): ?>
            <h3><?= e(t('profile.clubs')) ?></h3>
            <ul class="plain-list"><?php foreach ($clubs as $club): ?><li><?= e($club['name']) ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
    </section>

    <section class="panel">
        <h2><?= e(t('profile.password')) ?></h2>
        <form class="form" method="post" action="<?= e(url('/profile/password')) ?>">
            <?= csrf_field() ?>
            <?= partial('partials/field', ['name' => 'current_password', 'label' => t('profile.current_password'), 'type' => 'password', 'errors' => $errors,
                'attrs' => ['required' => true, 'autocomplete' => 'current-password']]) ?>
            <?= partial('partials/field', ['name' => 'new_password', 'label' => t('auth.field.new_password'), 'type' => 'password', 'errors' => $errors,
                'hint' => t('auth.field.password_hint', ['min' => (int) config('security.password_min')]),
                'attrs' => ['required' => true, 'autocomplete' => 'new-password']]) ?>
            <?= partial('partials/field', ['name' => 'new_password_confirm', 'label' => t('auth.field.password_confirm'), 'type' => 'password', 'errors' => $errors,
                'attrs' => ['required' => true, 'autocomplete' => 'new-password']]) ?>
            <button class="btn btn--primary" type="submit"><?= e(t('profile.change_password')) ?></button>
        </form>
        <p class="muted"><?= e(t('profile.password_note')) ?></p>
    </section>
</div>
