<?php
/**
 * The QR ticket.
 *
 * @var array<string,mixed> $registration
 * @var string $payload   the signed text the QR code carries
 * @var string $doorCode  short code for manual entry
 */
page_title($title = t('ticket.title'));
?>
<header class="page-head">
    <h1><?= e(t('ticket.title')) ?></h1>
    <p class="lead"><?= e(t('ticket.lead')) ?></p>
</header>

<article class="ticket">
    <div class="ticket__main">
        <p class="ticket__club"><?= e($registration['club_name']) ?></p>
        <h2><?= e($registration['title']) ?></h2>
        <dl class="facts facts--stacked">
            <div><dt><?= e(t('event.when')) ?></dt><dd><?= e(format_range((string) $registration['starts_at'], (string) $registration['ends_at'])) ?></dd></div>
            <div><dt><?= e(t('event.where')) ?></dt><dd><?= e(t('event.at_venue', ['venue' => $registration['venue_name'], 'building' => $registration['venue_building']])) ?></dd></div>
            <div><dt><?= e(t('ticket.holder')) ?></dt><dd><?= e($registration['full_name']) ?><?php if ($registration['student_number'] !== null): ?> <span class="muted"><?= e($registration['student_number']) ?></span><?php endif; ?></dd></div>
        </dl>
        <?php if ($registration['checked_in_at'] !== null): ?>
            <p class="booking__state booking__state--ok"><?= e(t('ticket.used', ['time' => format_datetime((string) $registration['checked_in_at'], 'g:i a')])) ?></p>
        <?php endif; ?>
    </div>
    <div class="ticket__stub">
        <div class="ticket__qr" data-qr="<?= e($payload) ?>" role="img" aria-label="<?= e(t('ticket.qr_label')) ?>"></div>
        <p class="ticket__code-label"><?= e(t('ticket.door_code')) ?></p>
        <p class="ticket__code"><?= e($doorCode) ?></p>
    </div>
</article>

<p class="actions no-print">
    <button class="btn btn--quiet" type="button" data-print><?= e(t('ticket.print')) ?></button>
    <a class="btn btn--quiet" href="<?= e(url('/my/registrations')) ?>"><?= e(t('ticket.back')) ?></a>
</p>
<p class="muted no-print"><?= e(t('ticket.warning')) ?></p>

<script src="<?= e(asset('js/vendor/qrcode.min.js')) ?>" defer></script>
<script src="<?= e(asset('js/ticket.js')) ?>" defer></script>
