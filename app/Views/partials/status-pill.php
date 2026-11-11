<?php
/**
 * One badge for every state in the system, so a status looks the same
 * wherever it appears.
 *
 * partial('partials/status-pill', ['status' => 'published']);
 * partial('partials/status-pill', ['status' => 'waitlisted', 'group' => 'registration']);
 *
 * @var string $status Event status, or registration status with $group
 * @var string $group  'status' (events) or 'registration'
 */
$status = (string) ($status ?? '');
$group = (string) ($group ?? 'status');

$tone = match ($status) {
    'ongoing', 'confirmed', 'published' => 'live',
    'pending_approval', 'waitlisted' => 'waiting',
    'draft' => 'quiet',
    'completed', 'archived', 'cancelled' => 'quiet',
    default => 'quiet',
};

// An event open for booking is the one state students act on, so it carries
// the brand colour rather than the neutral "live" green.
if ($status === 'published') {
    $tone = 'open';
}
?>
<span class="pill pill--<?= e($tone) ?>"><?= e(t($group . '.' . $status)) ?></span>
