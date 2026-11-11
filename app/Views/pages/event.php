<section class="event-details">
    <a class="back-link" href="<?= e(url('/events')) ?>">&larr; <?= e(t('action.back')) ?></a>

    <?php if (!empty($bookingError)): ?>
        <p class="notice notice--error" role="alert"><?= e($bookingError) ?></p>
    <?php endif; ?>

    <div class="event-details-layout">
        <div class="event-details-main">
            <p class="event-card__meta">
                <span class="dot" style="background: <?= e($event['category_color'] ?? '#6A7488') ?>"></span>
                <span class="event-card__category"><?= e($event['category_name'] ?? '') ?></span>
                <?php if (in_array($event['status'], ['published', 'ongoing'], true)): ?>
                    <?= partial('partials/status-pill', ['status' => $event['status']]) ?>
                <?php endif; ?>
            </p>

            <h1><?= e($event['title']) ?></h1>
            <p class="event-summary"><?= e($event['summary']) ?></p>
            <h2><?= e(t('event.about')) ?></h2>
            <p><?= nl2br(e($event['description'] ?? '')) ?></p>
            <?php if (!empty($event['learning_outcomes'])): ?>
                <div class="event-learning">
                    <h2><?= e(t('event.learning_outcomes')) ?></h2>
                    <ul>
                        <?php foreach (preg_split('/\R/', $event['learning_outcomes']) as $outcome): ?>
                            <?php if (trim($outcome) !== ''): ?>
                                <li><?= e(trim($outcome)) ?></li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <h2><?= e(t('event.host')) ?></h2>
            <p><?= e($event['club_name']) ?></p>

            <h2><?= e(t('event.where')) ?></h2>
            <div class="event-venue">
                <strong><?= e($event['venue_name']) ?></strong>
                <p><?= e($event['venue_building']) ?></p>
            </div>
        </div>

        <aside class="event-booking">
            <div class="event-booking__when">
                <p class="event-booking__date"><?= e(format_datetime($event['starts_at'])) ?></p>
                <p class="event-booking__until"><?= e(t('event.until', ['time' => format_datetime($event['ends_at'], 'g:i a')])) ?></p>
            </div>

            <div>
                <?= partial('partials/seat-meter', [
                    'capacity' => $event['capacity'],
                    'confirmed' => $event['confirmed_count'],
                ]) ?>
            </div>

            <hr>

            <?php if (($registration['status'] ?? null) === 'confirmed'): ?>
                <p class="notice notice--success"><?= e(t('registration.confirmed')) ?></p>
                <a class="btn btn--primary" href="<?= e(url('/registrations/' . $registration['id'] . '/ticket')) ?>"><?= e(t('ticket.view')) ?></a>
                <form method="post" action="<?= e(url('/registrations/' . $registration['id'] . '/cancel')) ?>">
                    <?= csrf_field() ?>
                    <button class="btn btn--secondary" type="submit"><?= e(t('action.cancel_booking')) ?></button>
                </form>
            <?php elseif (($registration['status'] ?? null) === 'waitlisted'): ?>
                <p class="notice"><?= e(t('registration.waitlisted')) ?></p>
                <form method="post" action="<?= e(url('/registrations/' . $registration['id'] . '/cancel')) ?>">
                    <?= csrf_field() ?>
                    <button class="btn btn--secondary" type="submit"><?= e(t('action.cancel_booking')) ?></button>
                </form>
            <?php elseif (($registration['status'] ?? null) === 'cancelled' && $canRegister): ?>
                <form method="post" action="<?= e(url('/events/' . $event['slug'] . '/register')) ?>">
                    <?= csrf_field() ?>
                    <button class="btn btn--primary" type="submit"><?= e(t('action.register')) ?></button>
                </form>
            <?php elseif ($canRegister && $event['status'] === 'published'): ?>
                <form method="post" action="<?= e(url('/events/' . $event['slug'] . '/register')) ?>">
                    <?= csrf_field() ?>
                    <button class="btn btn--primary" type="submit"><?= e((int) $event['confirmed_count'] >= (int) $event['capacity'] ? t('action.join_waitlist') : t('action.register')) ?></button>
                </form>
            <?php elseif (!auth_user() && $event['status'] === 'published'): ?>
                <a class="btn btn--primary" href="<?= e(url('/login?next=' . rawurlencode('/events/' . $event['slug']))) ?>"><?= e(t('action.sign_in_to_register')) ?></a>
            <?php endif; ?>
        </aside>
    </div>
</section>