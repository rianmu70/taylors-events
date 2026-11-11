<section class="page-head">
    <h1><?= e(t('registration.my_title')) ?></h1>
    <?php if (!empty($_GET['cancelled'])): ?>
        <p class="notice notice--success"><?= e(t('booking.cancelled')) ?></p>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <p class="notice notice--error" role="alert"><?= e($error) ?></p>
    <?php endif; ?>
</section>

<?php if ($registrations === []): ?>
    <p class="empty"><?= e(t('registration.empty')) ?></p>
<?php else: ?>
    <ul class="registration-list">
        <?php foreach ($registrations as $registration): ?>
            <li class="registration-row">
                <div>
                    <p class="registration-row__status"><?= e(t('registration.' . $registration['registration_status'])) ?></p>
                    <h2><a href="<?= e(url('/events/' . $registration['slug'])) ?>"><?= e($registration['title']) ?></a></h2>
                    <p><?= e(format_datetime($registration['starts_at'])) ?> · <?= e($registration['venue_name']) ?></p>
                </div>
                <div class="registration-row__actions">
                    <?php if ($registration['registration_status'] === 'confirmed'): ?>
                        <a class="btn btn--secondary" href="<?= e(url('/registrations/' . $registration['registration_id'] . '/ticket')) ?>"><?= e(t('ticket.view')) ?></a>
                    <?php elseif ($registration['registration_status'] === 'waitlisted'): ?>
                        <span><?= e(t('registration.queue_since', ['date' => format_datetime($registration['queued_at'], 'j M, g:i a')])) ?></span>
                    <?php endif; ?>
                    <?php if (in_array($registration['registration_status'], ['confirmed', 'waitlisted'], true)): ?>
                        <form method="post" action="<?= e(url('/registrations/' . $registration['registration_id'] . '/cancel')) ?>">
                            <?= csrf_field() ?>
                            <button class="btn btn--secondary" type="submit"><?= e(t('action.cancel_booking')) ?></button>
                        </form>
                    <?php endif; ?>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>