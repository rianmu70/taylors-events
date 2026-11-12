<?php /** Messages queued with flash() by the previous request. */ ?>
<?php foreach (take_flashes() as $message): ?>
    <div class="flash flash--<?= e($message['type']) ?>" role="<?= $message['type'] === 'error' ? 'alert' : 'status' ?>">
        <?= e($message['text']) ?>
    </div>
<?php endforeach; ?>
