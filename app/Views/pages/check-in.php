<section class="page-head">
    <a href="<?= e(url('/organize/events')) ?>">&larr; <?= e(t('manage.title')) ?></a>
    <h1><?= e(t('checkin.title')) ?></h1>
    <p class="lead"><?= e($event['title']) ?></p>
</section>

<?php if (!empty($error)): ?><p class="notice notice--error" role="alert"><?= e($error) ?></p><?php endif; ?>
<?php if ($result !== null): ?>
    <p class="notice <?= $result['result'] === 'admitted' ? 'notice--success' : 'notice--error' ?>" role="status">
        <?= e(t('checkin.result.' . $result['result'])) ?><?= $result['attendee'] !== null ? ' · ' . e($result['attendee']) : '' ?>
    </p>
<?php endif; ?>

<form class="checkin-form" method="post" action="<?= e(url('/organize/events/' . $event['id'] . '/check-in')) ?>">
    <?= csrf_field() ?>
    <label><?= e(t('checkin.ticket_code')) ?><textarea name="ticket" rows="3" required autocomplete="off" autofocus></textarea></label>
    <div class="checkin-camera">
        <button class="btn btn--secondary" id="checkin-camera-start" type="button" data-stop-label="<?= e(t('checkin.stop_camera')) ?>"><?= e(t('checkin.use_camera')) ?></button>
        <video id="checkin-camera-video" playsinline hidden></video>
        <p id="checkin-camera-message" class="notice" data-error="<?= e(t('checkin.camera_error')) ?>" role="status" hidden></p>
    </div>
    <button class="btn btn--primary" type="submit"><?= e(t('action.check_in')) ?></button>
</form>
<script defer src="<?= e(asset('js/checkin.js')) ?>"></script>