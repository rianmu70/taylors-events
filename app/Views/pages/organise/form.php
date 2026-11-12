<?php
/**
 * Create event and edit event share this form.
 *
 * @var array<string,mixed>|null $event   null when creating
 * @var array<string,mixed> $old          what was just submitted, after a validation error
 * @var array<string,string> $errors
 * @var list<array{id:int,name:string}> $clubs
 * @var list<array<string,mixed>> $venues
 * @var list<array{id:int,name:string,slug:string}> $categories
 * @var array<string,mixed>|null $clash   an event already holding this room at this time
 */
$editing = $event !== null;
$locked = $editing && $event['status'] !== 'draft';
page_title($title = t($editing ? 'event.edit_title' : 'event.create_title'));
$value = static function (string $key) use ($old, $event): mixed {
    if (array_key_exists($key, $old)) {
        return $old[$key];
    }
    $current = $event[$key] ?? '';
    return in_array($key, ['starts_at', 'ends_at'], true) && $current !== ''
        ? (new DateTimeImmutable((string) $current))->format('Y-m-d\TH:i')
        : $current;
};
$venueOptions = [];
foreach ($venues as $venue) {
    $venueOptions[$venue['id']] = t('event.venue_option', ['name' => $venue['name'], 'building' => $venue['building'], 'capacity' => $venue['capacity']]);
}
$disabled = ['disabled' => $locked];
?>
<header class="page-head">
    <h1><?= e($title) ?></h1>
    <p class="lead"><?= e(t($editing ? 'event.edit_lead' : 'event.create_lead')) ?></p>
    <?php if ($editing): ?><p><?= partial('partials/pill', ['status' => $event['status']]) ?></p><?php endif; ?>
</header>

<?php if (isset($errors['form'])): ?><div class="flash flash--error" role="alert"><?= e($errors['form']) ?></div><?php endif; ?>
<?php if ($locked): ?><div class="flash flash--info" role="status"><?= e(t('event.locked')) ?></div><?php endif; ?>
<?php if ($editing && $event['rejection_reason'] !== null && !$locked): ?>
    <div class="callout callout--error"><strong><?= e(t('hosted.rejected')) ?></strong> <?= e($event['rejection_reason']) ?></div>
<?php endif; ?>
<?php if ($clash !== null): ?>
    <div class="callout callout--error" role="alert">
        <strong><?= e(t('event.clash_heading')) ?></strong>
        <?= e(t('rule.VENUE_CLASH_WITH', ['event' => $clash['title'], 'club' => $clash['club_name'], 'when' => format_range((string) $clash['starts_at'], (string) $clash['ends_at'])])) ?>
    </div>
<?php endif; ?>

<form class="form form--wide panel" method="post" action="<?= e(url($editing ? '/organise/events/' . $event['id'] : '/organise/events')) ?>">
    <?= csrf_field() ?>
    <?= partial('partials/field', ['name' => 'title', 'label' => t('event.field.title'), 'value' => $value('title'), 'errors' => $errors,
        'attrs' => ['required' => true, 'maxlength' => 150] + $disabled]) ?>
    <?= partial('partials/field', ['name' => 'summary', 'label' => t('event.field.summary'), 'value' => $value('summary'), 'errors' => $errors,
        'hint' => t('event.field.summary_hint'), 'attrs' => ['required' => true, 'maxlength' => 300] + $disabled]) ?>
    <?= partial('partials/field', ['name' => 'description', 'label' => t('event.field.description'), 'type' => 'textarea', 'value' => $value('description'), 'errors' => $errors,
        'attrs' => ['required' => true, 'rows' => 7, 'maxlength' => 5000] + $disabled]) ?>

    <div class="form-row">
        <?= partial('partials/field', ['name' => 'club_id', 'label' => t('event.field.club'), 'type' => 'select', 'value' => $value('club_id'), 'errors' => $errors,
            'options' => array_column($clubs, 'name', 'id'), 'hint' => t('event.field.club_hint'), 'attrs' => ['required' => true] + $disabled]) ?>
        <?= partial('partials/field', ['name' => 'category_id', 'label' => t('event.field.category'), 'type' => 'select', 'value' => $value('category_id'), 'errors' => $errors,
            'options' => array_column($categories, 'name', 'id'), 'attrs' => ['required' => true] + $disabled]) ?>
    </div>

    <div class="form-row">
        <?= partial('partials/field', ['name' => 'starts_at', 'label' => t('event.field.starts'), 'type' => 'datetime-local', 'value' => $value('starts_at'), 'errors' => $errors,
            'attrs' => ['required' => true] + $disabled]) ?>
        <?= partial('partials/field', ['name' => 'ends_at', 'label' => t('event.field.ends'), 'type' => 'datetime-local', 'value' => $value('ends_at'), 'errors' => $errors,
            'hint' => t('event.field.ends_hint', ['hours' => (int) config('events.max_hours')]), 'attrs' => ['required' => true] + $disabled]) ?>
    </div>

    <div class="form-row">
        <?= partial('partials/field', ['name' => 'venue_id', 'label' => t('event.field.venue'), 'type' => 'select', 'value' => $value('venue_id'), 'errors' => $errors,
            'options' => $venueOptions, 'attrs' => ['required' => true] + $disabled]) ?>
        <?= partial('partials/field', ['name' => 'capacity', 'label' => t('event.field.capacity'), 'type' => 'number', 'value' => $value('capacity'), 'errors' => $errors,
            'hint' => t('event.field.capacity_hint'), 'attrs' => ['required' => true, 'min' => 1, 'max' => 5000, 'inputmode' => 'numeric'] + $disabled]) ?>
    </div>

    <?php if (!$locked): ?>
        <div class="actions">
            <button class="btn btn--primary" type="submit"><?= e(t('event.save_draft')) ?></button>
            <a class="btn btn--quiet" href="<?= e(url('/organise/events')) ?>"><?= e(t('form.cancel')) ?></a>
        </div>
    <?php endif; ?>
</form>

<?php if ($editing && !$locked): ?>
    <section class="panel">
        <h2><?= e(t('event.submit_heading')) ?></h2>
        <p><?= e(t('event.submit_explained')) ?></p>
        <form method="post" action="<?= e(url('/organise/events/' . $event['id'] . '/submit')) ?>">
            <?= csrf_field() ?>
            <button class="btn btn--primary" type="submit"><?= e(t('hosted.submit')) ?></button>
        </form>
    </section>
<?php endif; ?>
