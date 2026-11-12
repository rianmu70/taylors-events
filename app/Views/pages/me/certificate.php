<?php
/**
 * Attendance certificate. The same template serves the holder (with a print
 * button) and the public verification page.
 *
 * @var array<string,mixed> $registration
 * @var bool $public
 */
page_title($title = t('certificate.title'));
$hours = max(0.5, round((strtotime((string) $registration['ends_at']) - strtotime((string) $registration['starts_at'])) / 3600, 1));
?>
<?php if ($public): ?>
    <div class="flash flash--success" role="status"><?= e(t('certificate.verified')) ?></div>
<?php endif; ?>

<article class="certificate">
    <p class="certificate__issuer"><?= e(t('certificate.issuer')) ?></p>
    <h1><?= e(t('certificate.heading')) ?></h1>
    <p><?= e(t('certificate.certifies')) ?></p>
    <p class="certificate__name"><?= e($registration['full_name']) ?></p>
    <?php if (!$public && $registration['student_number'] !== null): ?>
        <p class="muted"><?= e(t('certificate.student_number', ['number' => $registration['student_number']])) ?></p>
    <?php endif; ?>
    <p><?= e(t('certificate.attended')) ?></p>
    <p class="certificate__event"><?= e($registration['title']) ?></p>
    <p><?= e(t('certificate.detail', [
        'club' => $registration['club_name'],
        'date' => format_datetime((string) $registration['starts_at'], 'j F Y'),
        'hours' => rtrim(rtrim(number_format($hours, 1), '0'), '.'),
    ])) ?></p>
    <dl class="certificate__foot">
        <div><dt><?= e(t('certificate.code')) ?></dt><dd><?= e($registration['certificate_code']) ?></dd></div>
        <div><dt><?= e(t('certificate.checked_in')) ?></dt><dd><?= e(format_datetime((string) $registration['checked_in_at'])) ?></dd></div>
        <div><dt><?= e(t('certificate.verify')) ?></dt><dd><?= e(absolute_url('/verify/' . $registration['certificate_code'])) ?></dd></div>
    </dl>
</article>

<?php if (!$public): ?>
<p class="actions no-print">
    <button class="btn btn--primary" type="button" data-print><?= e(t('certificate.print')) ?></button>
    <a class="btn btn--quiet" href="<?= e(url('/my/registrations')) ?>"><?= e(t('ticket.back')) ?></a>
</p>
<?php endif; ?>
