<?php
/**
 * Browse events.
 *
 * @var list<array<string,mixed>> $events
 * @var int $total
 * @var int $page
 * @var int $perPage
 * @var array{q:string,category:string,when:string} $filters
 * @var list<array{id:int,name:string,slug:string}> $categories
 */
page_title($title = t('browse.title'));
$filtered = $filters['q'] !== '' || $filters['category'] !== '' || $filters['when'] !== '';
?>
<header class="page-head">
    <h1><?= e(t('browse.heading')) ?></h1>
    <p class="lead"><?= e(t('browse.lead')) ?></p>
</header>

<form class="filters" method="get" action="<?= e(url('/events')) ?>" role="search">
    <div class="field filters__search">
        <label for="q"><?= e(t('browse.search')) ?></label>
        <input id="q" name="q" type="search" value="<?= e($filters['q']) ?>" placeholder="<?= e(t('browse.search_placeholder')) ?>" maxlength="80">
    </div>
    <div class="field">
        <label for="category"><?= e(t('browse.category')) ?></label>
        <select id="category" name="category">
            <option value=""><?= e(t('browse.category_all')) ?></option>
            <?php foreach ($categories as $category): ?>
                <option value="<?= e($category['slug']) ?>"<?= $category['slug'] === $filters['category'] ? ' selected' : '' ?>><?= e($category['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="when"><?= e(t('browse.when')) ?></label>
        <select id="when" name="when">
            <?php foreach (['' => 'browse.when_any', 'today' => 'browse.when_today', 'week' => 'browse.when_week', 'month' => 'browse.when_month'] as $value => $key): ?>
                <option value="<?= e($value) ?>"<?= $value === $filters['when'] ? ' selected' : '' ?>><?= e(t($key)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filters__actions">
        <button class="btn btn--primary" type="submit"><?= e(t('browse.apply')) ?></button>
        <?php if ($filtered): ?><a class="btn btn--quiet" href="<?= e(url('/events')) ?>"><?= e(t('browse.clear')) ?></a><?php endif; ?>
    </div>
</form>

<p class="result-count" aria-live="polite"><?= e(t_count('browse.count', $total)) ?></p>

<?php if ($events === []): ?>
    <div class="empty">
        <h2><?= e(t($filtered ? 'browse.empty_filtered' : 'browse.empty')) ?></h2>
        <p><?= e(t($filtered ? 'browse.empty_filtered_hint' : 'browse.empty_hint')) ?></p>
        <?php if ($filtered): ?><a class="btn btn--quiet" href="<?= e(url('/events')) ?>"><?= e(t('browse.clear')) ?></a><?php endif; ?>
    </div>
<?php else: ?>
    <ul class="event-list">
        <?php foreach ($events as $event): ?>
            <?= partial('partials/event-row', ['event' => $event]) ?>
        <?php endforeach; ?>
    </ul>
    <?= partial('partials/pagination', ['page' => $page, 'perPage' => $perPage, 'total' => $total]) ?>
<?php endif; ?>
