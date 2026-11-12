<?php
/**
 * Hosted events: everything the organiser's clubs are running.
 *
 * @var list<array<string,mixed>> $events
 * @var list<array{id:int,name:string}> $clubs
 */
page_title($title = t('hosted.title'));
?>
<header class="page-head page-head--with-action">
    <div>
        <h1><?= e(t('hosted.title')) ?></h1>
        <p class="lead"><?= e(t('hosted.lead', ['clubs' => implode(', ', array_column($clubs, 'name'))])) ?></p>
    </div>
    <a class="btn btn--primary" href="<?= e(url('/organise/events/new')) ?>"><?= e(t('hosted.create')) ?></a>
</header>

<?php if ($events === []): ?>
    <div class="empty">
        <h2><?= e(t('hosted.empty')) ?></h2>
        <p><?= e(t('hosted.empty_hint')) ?></p>
        <a class="btn btn--primary" href="<?= e(url('/organise/events/new')) ?>"><?= e(t('hosted.create')) ?></a>
    </div>
<?php else: ?>
    <ul class="card-list">
        <?php foreach ($events as $event): ?>
            <?php $status = (string) $event['status']; ?>
            <li class="card">
                <div class="card__body">
                    <p class="card__meta">
                        <?= partial('partials/pill', ['status' => $status]) ?>
                        <span><?= e($event['club_name']) ?></span>
                    </p>
                    <h2 class="card__title">
                        <?php if ($status === 'draft'): ?>
                            <a href="<?= e(url('/organise/events/' . $event['id'] . '/edit')) ?>"><?= e($event['title']) ?></a>
                        <?php else: ?>
                            <a href="<?= e(url('/events/' . $event['slug'])) ?>"><?= e($event['title']) ?></a>
                        <?php endif; ?>
                    </h2>
                    <p class="muted"><?= e(format_range((string) $event['starts_at'], (string) $event['ends_at'])) ?></p>
                    <p class="muted"><?= e(t('event.at_venue', ['venue' => $event['venue_name'], 'building' => $event['venue_building']])) ?></p>
                    <?php if ($status === 'draft' && $event['rejection_reason'] !== null): ?>
                        <p class="callout callout--error"><strong><?= e(t('hosted.rejected')) ?></strong> <?= e($event['rejection_reason']) ?></p>
                    <?php endif; ?>
                </div>
                <?php if (!in_array($status, ['draft', 'pending_approval'], true)): ?>
                    <?= partial('partials/seats', [
                        'capacity' => (int) $event['capacity'],
                        'confirmed' => (int) $event['confirmed_count'],
                        'waitlisted' => (int) $event['waitlisted_count'],
                        'closed' => $status !== 'published',
                    ]) ?>
                <?php endif; ?>
                <div class="card__actions">
                    <?php if ($status === 'draft'): ?>
                        <a class="btn btn--quiet btn--small" href="<?= e(url('/organise/events/' . $event['id'] . '/edit')) ?>"><?= e(t('hosted.edit')) ?></a>
                        <form method="post" action="<?= e(url('/organise/events/' . $event['id'] . '/submit')) ?>">
                            <?= csrf_field() ?>
                            <button class="btn btn--primary btn--small" type="submit"><?= e(t('hosted.submit')) ?></button>
                        </form>
                        <form method="post" action="<?= e(url('/organise/events/' . $event['id'] . '/delete')) ?>" data-confirm="<?= e(t('hosted.delete_confirm')) ?>">
                            <?= csrf_field() ?>
                            <button class="btn btn--danger btn--small" type="submit"><?= e(t('hosted.delete')) ?></button>
                        </form>
                    <?php elseif ($status === 'pending_approval'): ?>
                        <span class="muted"><?= e(t('hosted.waiting')) ?></span>
                    <?php else: ?>
                        <?php if (in_array($status, ['published', 'ongoing'], true)): ?>
                            <a class="btn btn--primary btn--small" href="<?= e(url('/organise/events/' . $event['id'] . '/check-in')) ?>"><?= e(t('hosted.check_in')) ?></a>
                        <?php endif; ?>
                        <a class="btn btn--quiet btn--small" href="<?= e(url('/organise/events/' . $event['id'] . '/attendees.csv')) ?>"><?= e(t('hosted.export')) ?></a>
                        <?php if ($status === 'completed'): ?>
                            <form method="post" action="<?= e(url('/organise/events/' . $event['id'] . '/archive')) ?>">
                                <?= csrf_field() ?>
                                <button class="btn btn--quiet btn--small" type="submit"><?= e(t('hosted.archive')) ?></button>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
