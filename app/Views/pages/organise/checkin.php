<?php
/**
 * Door check-in: camera scanner, manual code, live count, guest list.
 *
 * @var array<string,mixed> $event
 * @var bool $open                              is the door open yet
 * @var array{confirmed:int,checked_in:int} $counts
 * @var list<array<string,mixed>> $attendees
 * @var list<array<string,mixed>> $scans
 */
page_title($title = t('checkin.title'));
?>
<header class="page-head">
    <h1><?= e(t('checkin.title')) ?></h1>
    <p class="lead"><?= e($event['title']) ?>, <?= e(format_range((string) $event['starts_at'], (string) $event['ends_at'])) ?></p>
</header>

<?php if (!$open): ?>
    <div class="flash flash--info" role="status"><?= e(t('checkin.not_open', ['minutes' => (int) config('tickets.checkin_opens_minutes')])) ?></div>
<?php endif; ?>

<div class="split split--door">
    <section class="panel scanner"
             data-scanner
             data-endpoint="<?= e(url('/organise/events/' . $event['id'] . '/check-in')) ?>"
             data-csrf="<?= e(App\Security\Csrf::token()) ?>"
             data-text-starting="<?= e(t('checkin.camera_starting')) ?>"
             data-text-ready="<?= e(t('checkin.camera_ready')) ?>"
             data-text-denied="<?= e(t('checkin.camera_denied')) ?>"
             data-text-offline="<?= e(t('checkin.offline')) ?>">
        <h2><?= e(t('checkin.scan_heading')) ?></h2>

        <p class="door-count" aria-live="polite">
            <span class="door-count__num" data-count-in><?= e($counts['checked_in']) ?></span>
            <span class="door-count__of"><?= e(t('checkin.of')) ?> <span data-count-total><?= e($counts['confirmed']) ?></span> <?= e(t('checkin.arrived')) ?></span>
        </p>

        <div class="scanner__result" data-result role="status" aria-live="assertive" hidden></div>

        <div class="scanner__view" data-view hidden>
            <video data-video playsinline muted></video>
            <canvas data-canvas hidden></canvas>
        </div>
        <p class="muted" data-status></p>
        <p class="actions">
            <button class="btn btn--primary" type="button" data-start><?= e(t('checkin.start_camera')) ?></button>
            <button class="btn btn--quiet" type="button" data-stop hidden><?= e(t('checkin.stop_camera')) ?></button>
        </p>

        <form class="form scanner__manual" data-manual>
            <?= partial('partials/field', ['name' => 'code', 'label' => t('checkin.manual_label'), 'hint' => t('checkin.manual_hint'),
                'attrs' => ['required' => true, 'maxlength' => 9, 'autocomplete' => 'off', 'autocapitalize' => 'characters', 'placeholder' => 'A1B2-C3D4']]) ?>
            <button class="btn btn--quiet" type="submit"><?= e(t('checkin.manual_submit')) ?></button>
        </form>
    </section>

    <section class="panel">
        <h2><?= e(t('checkin.list_heading')) ?></h2>
        <?php if ($attendees === []): ?>
            <p class="muted"><?= e(t('checkin.list_empty')) ?></p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr>
                        <th scope="col"><?= e(t('export.name')) ?></th>
                        <th scope="col"><?= e(t('export.door_code')) ?></th>
                        <th scope="col"><?= e(t('export.status')) ?></th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($attendees as $row): ?>
                            <tr>
                                <td><?= e($row['full_name']) ?><br><span class="muted"><?= e($row['student_number'] ?? $row['email']) ?></span></td>
                                <td class="code"><?= e($row['status'] === 'confirmed' ? App\Services\CheckInService::doorCode((string) $row['ticket_nonce']) : '') ?></td>
                                <td><?= partial('partials/pill', ['status' => $row['checked_in_at'] !== null ? 'checked_in' : $row['status']]) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php if ($scans !== []): ?>
            <h3><?= e(t('checkin.log_heading')) ?></h3>
            <ul class="plain-list">
                <?php foreach ($scans as $scan): ?>
                    <li><span class="muted"><?= e(format_datetime((string) $scan['created_at'], 'g:i:s a')) ?></span> <?= e(t('scan.log.' . $scan['result'])) ?><?php if ($scan['full_name'] !== null): ?>, <?= e($scan['full_name']) ?><?php endif; ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>

<script src="<?= e(asset('js/vendor/jsqr.min.js')) ?>" defer></script>
<script src="<?= e(asset('js/checkin.js')) ?>" defer></script>
