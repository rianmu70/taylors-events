<?php
/**
 * @var array<string,mixed>|null $venue null when adding
 * @var array<string,mixed> $old
 * @var array<string,string> $errors
 */
$editing = $venue !== null;
page_title($title = t($editing ? 'venue.edit_title' : 'venue.add_title'));
$value = static fn (string $key): mixed => $old[$key] ?? ($venue[$key] ?? '');
$checked = static function (string $key, bool $default) use ($old, $venue): bool {
    if ($old !== []) {
        return isset($old[$key]);
    }
    return $venue === null ? $default : (int) $venue[$key] === 1;
};
$types = [];
foreach (App\Repositories\VenueRepository::TYPES as $type) {
    $types[$type] = t('venue.type.' . $type);
}
?>
<header class="page-head">
    <h1><?= e($title) ?></h1>
</header>

<form class="form form--wide panel" method="post" action="<?= e(url($editing ? '/admin/venues/' . $venue['id'] : '/admin/venues')) ?>">
    <?= csrf_field() ?>
    <div class="form-row">
        <?= partial('partials/field', ['name' => 'name', 'label' => t('venue.col.name'), 'value' => $value('name'), 'errors' => $errors, 'attrs' => ['required' => true, 'maxlength' => 120]]) ?>
        <?= partial('partials/field', ['name' => 'code', 'label' => t('venue.field.code'), 'value' => $value('code'), 'errors' => $errors, 'hint' => t('venue.field.code_hint'), 'attrs' => ['required' => true, 'maxlength' => 20]]) ?>
    </div>
    <div class="form-row">
        <?= partial('partials/field', ['name' => 'building', 'label' => t('venue.field.building'), 'value' => $value('building'), 'errors' => $errors, 'attrs' => ['required' => true, 'maxlength' => 80]]) ?>
        <?= partial('partials/field', ['name' => 'floor_label', 'label' => t('venue.field.floor'), 'value' => $value('floor_label'), 'errors' => $errors, 'attrs' => ['maxlength' => 10]]) ?>
    </div>
    <div class="form-row">
        <?= partial('partials/field', ['name' => 'venue_type', 'label' => t('venue.col.type'), 'type' => 'select', 'value' => $value('venue_type'), 'errors' => $errors, 'options' => $types, 'attrs' => ['required' => true]]) ?>
        <?= partial('partials/field', ['name' => 'capacity', 'label' => t('venue.col.capacity'), 'type' => 'number', 'value' => $value('capacity'), 'errors' => $errors, 'hint' => t('venue.field.capacity_hint'), 'attrs' => ['required' => true, 'min' => 1, 'max' => 5000]]) ?>
    </div>
    <fieldset class="checks">
        <legend><?= e(t('venue.field.features')) ?></legend>
        <label><input type="checkbox" name="has_projector" value="1"<?= $checked('has_projector', true) ? ' checked' : '' ?>> <?= e(t('venue.field.projector')) ?></label>
        <label><input type="checkbox" name="is_accessible" value="1"<?= $checked('is_accessible', true) ? ' checked' : '' ?>> <?= e(t('venue.field.accessible')) ?></label>
    </fieldset>
    <div class="actions">
        <button class="btn btn--primary" type="submit"><?= e(t('venue.save')) ?></button>
        <a class="btn btn--quiet" href="<?= e(url('/admin/venues')) ?>"><?= e(t('form.cancel')) ?></a>
    </div>
</form>
