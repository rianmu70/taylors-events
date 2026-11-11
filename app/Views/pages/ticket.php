<section class="ticket-page">
    <a href="<?= e(url('/my-registrations')) ?>">&larr; <?= e(t('registration.my_title')) ?></a>
    <h1><?= e(t('ticket.title')) ?></h1>
    <h2><?= e($registration['title']) ?></h2>
    <p><?= e(format_datetime($registration['starts_at'])) ?> · <?= e($registration['venue_name']) ?></p>
    <div class="ticket-code">
        <canvas id="ticket-qr" data-ticket-payload="<?= e($payload) ?>" aria-label="<?= e(t('ticket.qr_label')) ?>"></canvas>
        <p id="ticket-qr-error" class="notice" hidden><?= e(t('ticket.qr_unavailable')) ?></p>
        <details>
            <summary><?= e(t('ticket.manual_code')) ?></summary>
            <code><?= e($payload) ?></code>
        </details>
    </div>
</section>
<script defer src="https://cdn.jsdelivr.net/npm/qrcode@1.5.4/build/qrcode.min.js"></script>
<script defer src="<?= e(asset('js/ticket.js')) ?>"></script>