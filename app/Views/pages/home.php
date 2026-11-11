<?php
/**
 * Browse events.
 *
 * @var list<array<string, mixed>> $events Rows from Event::upcoming() or Event::browse()
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
            <li class="event-card">
                <a class="event-card__link" href="<?= e(url('/events/' . $event['slug'])) ?>">
                    <p class="event-card__meta">
                        <span class="dot" style="background: <?= e($event['category_color']) ?>"></span>
                        <span class="event-card__category"><?= e($event['category_name']) ?></span>
                        <?php if ($event['status'] === 'ongoing'): ?>
                            <?= partial('partials/status-pill', ['status' => 'ongoing']) ?>
                        <?php endif; ?>
                    </p>
                    <h2 class="event-card__title"><?= e($event['title']) ?></h2>
                    <p class="event-card__summary"><?= e($event['summary']) ?></p>
                    <dl class="event-card__facts">
                        <div><dt><?= e(t('event.when')) ?></dt><dd><?= e(format_datetime($event['starts_at'])) ?></dd></div>
                        <div><dt><?= e(t('event.where')) ?></dt><dd><?= e($event['venue_name']) ?></dd></div>
                        <div><dt><?= e(t('event.host')) ?></dt><dd><?= e($event['club_name']) ?></dd></div>
                    </dl>
                    <div class="event-card__seats">
                        <?= partial('partials/seat-meter', [
                            'capacity' => $event['capacity'],
                            'confirmed' => $event['confirmed_count'],
                        ]) ?>
                    </div>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
