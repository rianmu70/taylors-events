<?php
/**
 * Home page: upcoming events.
 *
 * @var list<array<string, mixed>> $events Rows from Event::upcoming()
 */
?>
<section class="page-head">
    <h1><?= e(t('home.title')) ?></h1>
    <p class="lead"><?= e(t('home.intro')) ?></p>
</section>

<?php if ($events === []): ?>
    <p class="empty"><?= e(t('home.empty')) ?></p>
<?php else: ?>
    <ul class="event-grid">
        <?php foreach ($events as $event): ?>
            <?php
            $left = max(0, (int) $event['capacity'] - (int) $event['confirmed_count']);
            $seats = match (true) {
                $left === 0 => t('event.full'),
                $left === 1 => t('event.seat_left'),
                default => t('event.seats_left', ['count' => $left]),
            };
            ?>
            <li class="event-card">
                <p class="event-card__meta">
                    <span class="dot" style="background: <?= e($event['category_color']) ?>"></span>
                    <?= e($event['category_name']) ?>
                    <?php if ($event['status'] === 'ongoing'): ?>
                        <span class="badge badge--live"><?= e(t('status.ongoing')) ?></span>
                    <?php endif; ?>
                </p>
                <h2 class="event-card__title"><?= e($event['title']) ?></h2>
                <p class="event-card__summary"><?= e($event['summary']) ?></p>
                <dl class="event-card__facts">
                    <div><dt><?= e(t('event.when')) ?></dt><dd><?= e(format_datetime($event['starts_at'])) ?></dd></div>
                    <div><dt><?= e(t('event.where')) ?></dt><dd><?= e($event['venue_name']) ?></dd></div>
                    <div><dt><?= e(t('event.host')) ?></dt><dd><?= e($event['club_name']) ?></dd></div>
                </dl>
                <p class="event-card__seats<?= $left === 0 ? ' is-full' : '' ?>"><?= e($seats) ?></p>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
