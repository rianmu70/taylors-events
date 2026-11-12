<?php
/**
 * @var list<array<string,mixed>> $pending  each with 'clash'
 * @var list<array<string,mixed>> $decided
 */
page_title($title = t('approval.title'));
?>
<header class="page-head">
    <h1><?= e(t('approval.title')) ?></h1>
    <p class="lead"><?= e(t_count('approval.count', count($pending))) ?></p>
</header>

<?php if ($pending === []): ?>
    <div class="empty">
        <h2><?= e(t('approval.empty')) ?></h2>
        <p><?= e(t('approval.empty_hint')) ?></p>
    </div>
<?php else: ?>
    <ul class="card-list">
        <?php foreach ($pending as $event): ?>
            <li class="card card--stacked">
                <div class="card__body">
                    <p class="card__meta"><?= partial('partials/pill', ['status' => 'pending_approval']) ?> <span><?= e($event['category_name']) ?></span></p>
                    <h2 class="card__title"><a href="<?= e(url('/events/' . $event['slug'])) ?>"><?= e($event['title']) ?></a></h2>
                    <p><?= e($event['summary']) ?></p>
                    <dl class="facts">
                        <div><dt><?= e(t('event.host')) ?></dt><dd><?= e($event['club_name']) ?><br><span class="muted"><?= e(t('approval.submitted_by', ['name' => $event['organizer_name']])) ?></span></dd></div>
                        <div><dt><?= e(t('event.when')) ?></dt><dd><?= e(format_range((string) $event['starts_at'], (string) $event['ends_at'])) ?></dd></div>
                        <div><dt><?= e(t('event.where')) ?></dt><dd><?= e($event['venue_name']) ?></dd></div>
                        <div><dt><?= e(t('event.field.capacity')) ?></dt><dd><?= e(t('approval.capacity', ['capacity' => $event['capacity'], 'room' => $event['venue_capacity']])) ?></dd></div>
                    </dl>
                    <?php if ($event['clash'] !== null): ?>
                        <p class="callout callout--error"><?= e(t('rule.VENUE_CLASH_WITH', ['event' => $event['clash']['title'], 'club' => $event['clash']['club_name'], 'when' => format_range((string) $event['clash']['starts_at'], (string) $event['clash']['ends_at'])])) ?></p>
                    <?php endif; ?>
                </div>
                <div class="decision">
                    <form method="post" action="<?= e(url('/admin/approvals/' . $event['id'] . '/approve')) ?>">
                        <?= csrf_field() ?>
                        <button class="btn btn--primary" type="submit"><?= e(t('approval.approve')) ?></button>
                    </form>
                    <form class="decision__reject" method="post" action="<?= e(url('/admin/approvals/' . $event['id'] . '/reject')) ?>">
                        <?= csrf_field() ?>
                        <div class="field">
                            <label for="reason-<?= e($event['id']) ?>"><?= e(t('approval.reason')) ?></label>
                            <textarea id="reason-<?= e($event['id']) ?>" name="reason" rows="2" minlength="10" maxlength="500" required aria-describedby="reason-hint-<?= e($event['id']) ?>"></textarea>
                            <p class="field__hint" id="reason-hint-<?= e($event['id']) ?>"><?= e(t('approval.reason_hint')) ?></p>
                        </div>
                        <button class="btn btn--danger" type="submit"><?= e(t('approval.reject')) ?></button>
                    </form>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php if ($decided !== []): ?>
<section>
    <h2><?= e(t('approval.recent')) ?></h2>
    <div class="table-wrap">
        <table class="table">
            <thead><tr>
                <th scope="col"><?= e(t('mine.col.event')) ?></th>
                <th scope="col"><?= e(t('event.host')) ?></th>
                <th scope="col"><?= e(t('approval.approved_on')) ?></th>
                <th scope="col"><?= e(t('export.status')) ?></th>
            </tr></thead>
            <tbody>
                <?php foreach ($decided as $event): ?>
                    <tr>
                        <td><a href="<?= e(url('/events/' . $event['slug'])) ?>"><?= e($event['title']) ?></a></td>
                        <td><?= e($event['club_name']) ?></td>
                        <td><?= e(format_datetime((string) $event['approved_at'], 'j M Y, g:i a')) ?></td>
                        <td><?= partial('partials/pill', ['status' => $event['status']]) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>
