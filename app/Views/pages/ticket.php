<section class="ticket-page">
    <a class="back-link" href="<?= e(url('/my-registrations')) ?>">&larr; <?= e(t('registration.my_title')) ?></a>
    <h1><?= e(t('ticket.title')) ?></h1>
    <h2><?= e($registration['title']) ?></h2>
    <p><?= e(format_datetime($registration['starts_at'])) ?>, <?= e($registration['venue_name']) ?></p>

    <canvas id="ticket-qr" data-ticket-payload="<?= e($payload) ?>" aria-label="<?= e(t('ticket.qr_label')) ?>"></canvas>
    <p id="ticket-qr-error" class="notice notice--error" hidden><?= e(t('ticket.qr_unavailable')) ?></p>

    <details class="ticket-manual">
        <summary><?= e(t('ticket.manual_code')) ?></summary>
        <p class="ticket-code"><?= e($payload) ?></p>
    </details>
</section>
<!-- Bundled with the app: the ticket still works when the venue has no internet. -->
<script defer src="<?= e(asset('js/vendor/qrcode.min.js')) ?>"></script>
<script defer src="<?= e(asset('js/ticket.js')) ?>"></script>
