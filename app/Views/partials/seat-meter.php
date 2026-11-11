<?php
/**
 * How full an event is: a count and a bar.
 *
 * partial('partials/seat-meter', ['capacity' => 40, 'confirmed' => 39]);
 *
 * @var int $capacity
 * @var int $confirmed
 */
$capacity = max(0, (int) ($capacity ?? 0));
$confirmed = max(0, (int) ($confirmed ?? 0));
$left = max(0, $capacity - $confirmed);
$taken = $capacity > 0 ? min(100, (int) round(100 * $confirmed / $capacity)) : 0;

// "Tight" once a tenth of the room is left, so the warning arrives before it is full.
$state = match (true) {
    $left === 0 => 'full',
    $capacity > 0 && $left <= max(1, (int) ceil($capacity * 0.1)) => 'tight',
    default => 'open',
};

$label = match (true) {
    $left === 0 => t('event.full'),
    $left === 1 => t('event.seat_left'),
    default => t('event.seats_left', ['count' => $left]),
};
?>
<div class="seats seats--<?= e($state) ?>">
    <span class="seats__count"><?= e($label) ?></span>
    <span class="seats__of"><?= e(t('event.of_capacity', ['taken' => $confirmed, 'total' => $capacity])) ?></span>
</div>
<div class="meter meter--<?= e($state) ?>" role="img" aria-label="<?= e(t('event.of_capacity', ['taken' => $confirmed, 'total' => $capacity])) ?>">
    <span class="meter__fill" style="width: <?= e($taken) ?>%"></span>
</div>
