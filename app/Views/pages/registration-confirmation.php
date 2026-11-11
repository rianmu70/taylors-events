<section class="confirmation-page">
    <p class="eyebrow"><?= e(t('registration.confirmation_label')) ?></p>
    <h1><?= e(t('registration.' . $registration['status'])) ?></h1>
    <p class="lead"><?= e($registration['title']) ?></p>
    <dl class="event-card__facts">
        <div><dt><?= e(t('event.when')) ?></dt><dd><?= e(format_datetime($registration['starts_at'])) ?></dd></div>
        <div><dt><?= e(t('event.where')) ?></dt><dd><?= e($registration['venue_name']) ?>, <?= e($registration['venue_building']) ?></dd></div>
    </dl>
    <div class="confirmation-page__actions">
        <?php if ($registration['status'] === 'confirmed'): ?>
            <a class="btn btn--primary" href="<?= e(url('/registrations/' . $registration['id'] . '/ticket')) ?>"><?= e(t('ticket.view')) ?></a>
        <?php endif; ?>
        <a class="btn btn--secondary" href="<?= e(url('/my-registrations')) ?>"><?= e(t('registration.my_title')) ?></a>
    </div>
</section>