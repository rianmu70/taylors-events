<?php
/**
 * The seat meter: the one component the whole product is built around.
 * Twelve cells fill up as seats are taken, and the count changes colour as
 * seats run out (open, filling, last, full). Thresholds: config/app.php.
 *
 * @var int $capacity
 * @var int $confirmed
 * @var int $waitlisted
 * @var bool $large      true on the event page
 * @var bool $closed     true once booking is over; shows attendance instead of urgency
 */
$large ??= false;
$closed ??= false;
$waitlisted ??= 0;
$seat = seat_state((int) $capacity, (int) $confirmed);
$state = $closed ? 'closed' : $seat['state'];
$cells = 12;
$on = (int) $confirmed >= (int) $capacity ? $cells : min($cells - 1, (int) ceil($cells * (int) $confirmed / max(1, (int) $capacity)));
$headline = match (true) {
    $closed => t_count('seats.booked', (int) $confirmed),
    $seat['left'] === 0 => t('seats.full'),
    default => t_count('seats.left', $seat['left']),
};
?>
<div class="seats seats--<?= e($state) ?><?= $large ? ' seats--large' : '' ?>">
    <p class="seats__count"><?= e($headline) ?></p>
    <div class="seats__strip" role="img" aria-label="<?= e(t('seats.aria', ['taken' => (int) $confirmed, 'capacity' => (int) $capacity])) ?>">
        <?php for ($i = 0; $i < $cells; $i++): ?><i<?= $i < $on ? ' class="on"' : '' ?>></i><?php endfor; ?>
    </div>
    <p class="seats__note">
        <?= e(t('seats.taken', ['taken' => (int) $confirmed, 'capacity' => (int) $capacity])) ?><?php if (!$closed && $waitlisted > 0): ?>,
        <strong><?= e(t_count('seats.waiting', (int) $waitlisted)) ?></strong><?php endif; ?>
    </p>
</div>
