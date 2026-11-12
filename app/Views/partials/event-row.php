<?php
/**
 * One event in a list: date, what and where, and the seat meter.
 *
 * @var array<string,mixed> $event a row from EventRepository
 */
$start = new DateTimeImmutable((string) $event['starts_at']);
$live = $event['status'] === 'ongoing';
?>
<li class="event-row<?= $live ? ' event-row--live' : '' ?>">
    <div class="datebox" aria-hidden="true">
        <span class="datebox__day"><?= e($start->format('D')) ?></span>
        <span class="datebox__num"><?= e($start->format('j')) ?></span>
        <span class="datebox__month"><?= e($start->format('M')) ?></span>
    </div>
    <div class="event-row__body">
        <p class="event-row__meta">
            <?php if ($live): ?><span class="pill pill--ongoing"><?= e(t('status.ongoing')) ?></span><?php endif; ?>
            <span><?= e($event['category_name']) ?></span>
            <span><?= e($event['club_name']) ?></span>
        </p>
        <h2 class="event-row__title"><a href="<?= e(url('/events/' . $event['slug'])) ?>"><?= e($event['title']) ?></a></h2>
        <p class="event-row__where">
            <time datetime="<?= e($start->format('c')) ?>"><?= e(format_range((string) $event['starts_at'], (string) $event['ends_at'])) ?></time>
            <span><?= e(t('event.at_venue', ['venue' => $event['venue_name'], 'building' => $event['venue_building']])) ?></span>
        </p>
    </div>
    <?= partial('partials/seats', [
        'capacity' => (int) $event['capacity'],
        'confirmed' => (int) $event['confirmed_count'],
        'waitlisted' => (int) $event['waitlisted_count'],
        'closed' => $live,
    ]) ?>
</li>
