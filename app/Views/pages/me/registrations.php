<?php
/**
 * My registrations.
 *
 * @var list<array<string,mixed>> $upcoming
 * @var list<array<string,mixed>> $past
 * @var array<int,int> $rated event id => rating already given
 */
page_title($title = t('mine.title'));
?>
<header class="page-head">
    <h1><?= e(t('mine.title')) ?></h1>
    <p class="lead"><?= e(t('mine.lead')) ?></p>
</header>

<section>
    <h2><?= e(t('mine.upcoming')) ?></h2>
    <?php if ($upcoming === []): ?>
        <div class="empty">
            <h3><?= e(t('mine.empty')) ?></h3>
            <p><?= e(t('mine.empty_hint')) ?></p>
            <a class="btn btn--primary" href="<?= e(url('/events')) ?>"><?= e(t('mine.browse')) ?></a>
        </div>
    <?php else: ?>
        <ul class="card-list">
            <?php foreach ($upcoming as $row): ?>
                <li class="card">
                    <div class="card__body">
                        <p class="card__meta">
                            <?= partial('partials/pill', ['status' => $row['checked_in_at'] !== null ? 'checked_in' : $row['status']]) ?>
                            <?php if ($row['event_status'] === 'ongoing'): ?><?= partial('partials/pill', ['status' => 'ongoing']) ?><?php endif; ?>
                        </p>
                        <h3><a href="<?= e(url('/events/' . $row['slug'])) ?>"><?= e($row['title']) ?></a></h3>
                        <p class="muted"><?= e(format_range((string) $row['starts_at'], (string) $row['ends_at'])) ?></p>
                        <p class="muted"><?= e(t('event.at_venue', ['venue' => $row['venue_name'], 'building' => $row['venue_building']])) ?></p>
                        <?php if ($row['status'] === 'waitlisted'): ?>
                            <p><strong><?= e(t('booking.state.waitlisted', ['position' => (int) $row['position']])) ?></strong></p>
                        <?php endif; ?>
                    </div>
                    <div class="card__actions">
                        <?php if ($row['status'] === 'confirmed'): ?>
                            <a class="btn btn--primary btn--small" href="<?= e(url('/my/tickets/' . $row['id'])) ?>"><?= e(t('booking.view_ticket')) ?></a>
                        <?php endif; ?>
                        <?php if ($row['event_status'] === 'published' && $row['checked_in_at'] === null): ?>
                            <form method="post" action="<?= e(url('/my/registrations/' . $row['id'] . '/cancel')) ?>" data-confirm="<?= e(t('booking.cancel_confirm')) ?>">
                                <?= csrf_field() ?>
                                <button class="btn btn--quiet btn--small" type="submit"><?= e(t($row['status'] === 'waitlisted' ? 'booking.leave_waitlist' : 'booking.cancel')) ?></button>
                            </form>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<?php if ($past !== []): ?>
<section>
    <h2><?= e(t('mine.past')) ?></h2>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th scope="col"><?= e(t('mine.col.event')) ?></th>
                    <th scope="col"><?= e(t('mine.col.date')) ?></th>
                    <th scope="col"><?= e(t('mine.col.outcome')) ?></th>
                    <th scope="col"><span class="visually-hidden"><?= e(t('table.actions')) ?></span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($past as $row): ?>
                    <?php $attended = $row['checked_in_at'] !== null; ?>
                    <tr>
                        <td><a href="<?= e(url('/events/' . $row['slug'])) ?>"><?= e($row['title']) ?></a></td>
                        <td><?= e(format_datetime((string) $row['starts_at'], 'j M Y')) ?></td>
                        <td><?= partial('partials/pill', ['status' => $row['status'] === 'cancelled' ? 'cancelled' : ($attended ? 'attended' : ($row['status'] === 'confirmed' ? 'missed' : 'waitlisted'))]) ?></td>
                        <td class="table__actions">
                            <?php if ($attended && $row['certificate_code'] !== null): ?>
                                <a class="btn btn--quiet btn--small" href="<?= e(url('/my/certificates/' . $row['id'])) ?>"><?= e(t('mine.certificate')) ?></a>
                                <?php if (!isset($rated[(int) $row['event_id']])): ?>
                                    <a class="btn btn--quiet btn--small" href="<?= e(url('/events/' . $row['slug'])) ?>"><?= e(t('mine.rate')) ?></a>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>
