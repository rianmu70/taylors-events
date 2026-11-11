<section class="page-head page-head--split">
    <div>
        <h1><?= e(t('manage.title')) ?></h1>
        <p class="lead"><?= e(t('manage.intro')) ?></p>
    </div>
    <a class="btn btn--primary" href="<?= e(url('/organize/events/create')) ?>"><?= e(t('manage.create_title')) ?></a>
</section>

<?php if (!empty($notice)): ?><p class="notice notice--success"><?= e($notice) ?></p><?php endif; ?>
<?php if (!empty($error)): ?><p class="notice notice--error" role="alert"><?= e($error) ?></p><?php endif; ?>

<?php if ($events === []): ?>
    <p class="empty"><?= e(t('manage.empty')) ?></p>
<?php else: ?>
    <ul class="management-list">
        <?php foreach ($events as $event): ?>
            <li class="management-row">
                <div>
                    <p class="eyebrow"><?= e($event['club_name']) ?> · <?= e(t('status.' . $event['status'])) ?></p>
                    <h2><?= e($event['title']) ?></h2>
                    <p><?= e(format_datetime($event['starts_at'])) ?> · <?= e(t('event.seats_left', ['count' => max(0, (int) $event['capacity'] - (int) $event['confirmed_count'])])) ?></p>
                </div>
                <div class="management-row__actions">
                    <?php if ($event['status'] === 'draft'): ?>
                        <a class="btn btn--secondary" href="<?= e(url('/organize/events/' . $event['id'] . '/edit')) ?>"><?= e(t('action.edit')) ?></a>
                        <form method="post" action="<?= e(url('/organize/events/' . $event['id'] . '/transition')) ?>">
                            <?= csrf_field() ?><input type="hidden" name="target" value="pending_approval">
                            <button class="btn btn--primary" type="submit"><?= e(t('action.submit_for_approval')) ?></button>
                        </form>
                    <?php elseif ($event['status'] === 'pending_approval' && auth_role() === 'admin'): ?>
                        <form method="post" action="<?= e(url('/organize/events/' . $event['id'] . '/transition')) ?>">
                            <?= csrf_field() ?><input type="hidden" name="target" value="published">
                            <button class="btn btn--primary" type="submit"><?= e(t('action.approve')) ?></button>
                        </form>
                        <form class="reject-form" method="post" action="<?= e(url('/organize/events/' . $event['id'] . '/transition')) ?>">
                            <?= csrf_field() ?><input type="hidden" name="target" value="draft">
                            <label><?= e(t('manage.rejection_reason')) ?><input name="reason" minlength="5" required></label>
                            <button class="btn btn--secondary" type="submit"><?= e(t('action.reject')) ?></button>
                        </form>
                    <?php elseif ($event['status'] === 'published'): ?>
                        <?php if (auth_role() === 'admin'): ?>
                            <form method="post" action="<?= e(url('/organize/events/' . $event['id'] . '/transition')) ?>">
                                <?= csrf_field() ?><input type="hidden" name="target" value="ongoing">
                                <button class="btn btn--primary" type="submit"><?= e(t('manage.mark_ongoing')) ?></button>
                            </form>
                        <?php else: ?>
                            <form method="post" action="<?= e(url('/organize/events/' . $event['id'] . '/transition')) ?>">
                                <?= csrf_field() ?><input type="hidden" name="target" value="draft">
                                <button class="btn btn--secondary" type="submit"><?= e(t('manage.unpublish')) ?></button>
                            </form>
                        <?php endif; ?>
                    <?php elseif ($event['status'] === 'ongoing' && auth_role() === 'admin'): ?>
                        <a class="btn btn--secondary" href="<?= e(url('/organize/events/' . $event['id'] . '/check-in')) ?>"><?= e(t('checkin.title')) ?></a>
                        <form method="post" action="<?= e(url('/organize/events/' . $event['id'] . '/transition')) ?>">
                            <?= csrf_field() ?><input type="hidden" name="target" value="completed">
                            <button class="btn btn--primary" type="submit"><?= e(t('manage.mark_completed')) ?></button>
                        </form>
                    <?php elseif ($event['status'] === 'ongoing'): ?>
                        <a class="btn btn--primary" href="<?= e(url('/organize/events/' . $event['id'] . '/check-in')) ?>"><?= e(t('checkin.title')) ?></a>
                    <?php elseif ($event['status'] === 'completed' && auth_role() === 'admin'): ?>
                        <form method="post" action="<?= e(url('/organize/events/' . $event['id'] . '/transition')) ?>">
                            <?= csrf_field() ?><input type="hidden" name="target" value="archived">
                            <button class="btn btn--secondary" type="submit"><?= e(t('manage.archive')) ?></button>
                        </form>
                    <?php endif; ?>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>