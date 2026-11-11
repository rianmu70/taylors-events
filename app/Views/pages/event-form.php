<section class="page-head">
    <a href="<?= e(url('/organize/events')) ?>">&larr; <?= e(t('manage.title')) ?></a>
    <h1><?= e(t($eventId === null ? 'manage.create_title' : 'manage.edit_title')) ?></h1>
</section>

<?php if (!empty($errors['form'])): ?><p class="notice notice--error" role="alert"><?= e($errors['form']) ?></p><?php endif; ?>

<form class="event-form" method="post" action="<?= e(url($eventId === null ? '/organize/events/create' : '/organize/events/' . $eventId . '/edit')) ?>">
    <?= csrf_field() ?>
    <label><?= e(t('manage.field.title')) ?><input name="title" maxlength="150" required value="<?= e($old['title'] ?? '') ?>"></label>
    <label><?= e(t('manage.field.summary')) ?><input name="summary" maxlength="300" required value="<?= e($old['summary'] ?? '') ?>"></label>
    <label><?= e(t('manage.field.description')) ?><textarea name="description" rows="6" required><?= e($old['description'] ?? '') ?></textarea></label>
    <label><?= e(t('manage.field.outcomes')) ?><textarea name="learning_outcomes" rows="3"><?= e($old['learning_outcomes'] ?? '') ?></textarea></label>
    <div class="form-grid">
        <label><?= e(t('manage.field.club')) ?><select name="club_id" required>
            <option value=""><?= e(t('manage.choose')) ?></option>
            <?php foreach ($clubs as $club): ?><option value="<?= e($club['id']) ?>"<?= (string) ($old['club_id'] ?? '') === (string) $club['id'] ? ' selected' : '' ?>><?= e($club['name']) ?></option><?php endforeach; ?>
        </select></label>
        <label><?= e(t('manage.field.category')) ?><select name="category_id" required>
            <option value=""><?= e(t('manage.choose')) ?></option>
            <?php foreach ($categories as $category): ?><option value="<?= e($category['id']) ?>"<?= (string) ($old['category_id'] ?? '') === (string) $category['id'] ? ' selected' : '' ?>><?= e($category['name']) ?></option><?php endforeach; ?>
        </select></label>
        <label><?= e(t('manage.field.venue')) ?><select name="venue_id" required>
            <option value=""><?= e(t('manage.choose')) ?></option>
            <?php foreach ($venues as $venue): ?><option value="<?= e($venue['id']) ?>"<?= (string) ($old['venue_id'] ?? '') === (string) $venue['id'] ? ' selected' : '' ?>><?= e($venue['name']) ?> · <?= e($venue['building']) ?> (<?= e($venue['capacity']) ?>)</option><?php endforeach; ?>
        </select></label>
        <label><?= e(t('manage.field.capacity')) ?><input type="number" name="capacity" min="1" max="65535" required value="<?= e($old['capacity'] ?? '') ?>"></label>
        <label><?= e(t('manage.field.starts')) ?><input type="datetime-local" name="starts_at" required value="<?= e(isset($old['starts_at']) ? date('Y-m-d\TH:i', strtotime((string) $old['starts_at'])) : '') ?>"></label>
        <label><?= e(t('manage.field.ends')) ?><input type="datetime-local" name="ends_at" required value="<?= e(isset($old['ends_at']) ? date('Y-m-d\TH:i', strtotime((string) $old['ends_at'])) : '') ?>"></label>
        <label><?= e(t('manage.field.closes')) ?><input type="datetime-local" name="registration_closes_at" value="<?= e(!empty($old['registration_closes_at']) ? date('Y-m-d\TH:i', strtotime((string) $old['registration_closes_at'])) : '') ?>"></label>
    </div>
    <button class="btn btn--primary" type="submit"><?= e(t('action.save')) ?></button>
</form>