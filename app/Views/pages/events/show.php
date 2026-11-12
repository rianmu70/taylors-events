<?php
/**
 * One event, with the booking panel.
 *
 * @var array<string,mixed> $event
 * @var array<string,mixed>|null $registration the signed-in user's own booking, if any
 * @var int|null $position                     place on the waitlist
 * @var list<array<string,mixed>> $ratings
 * @var array{average:?float,total:int} $ratingSummary
 * @var bool $canRate
 * @var string $idempotencyKey
 */
page_title($title = (string) $event['title']);
$user = auth_user();
$seat = seat_state((int) $event['capacity'], (int) $event['confirmed_count']);
$bookable = $event['status'] === 'published';
$over = in_array($event['status'], ['ongoing', 'completed', 'archived'], true);
?>
<article class="event">
    <header class="event__head">
        <p class="event__meta">
            <?= partial('partials/pill', ['status' => $event['status']]) ?>
            <span><?= e($event['category_name']) ?></span>
        </p>
        <h1><?= e($event['title']) ?></h1>
        <p class="lead"><?= e($event['summary']) ?></p>
    </header>

    <div class="event__layout">
        <div class="event__main">
            <dl class="facts">
                <div><dt><?= e(t('event.when')) ?></dt><dd><?= e(format_range((string) $event['starts_at'], (string) $event['ends_at'])) ?></dd></div>
                <div><dt><?= e(t('event.where')) ?></dt><dd><?= e($event['venue_name']) ?><br><span class="muted"><?= e(t('event.venue_detail', ['building' => $event['venue_building'], 'floor' => $event['venue_floor'] ?? t('event.floor_ground')])) ?></span></dd></div>
                <div><dt><?= e(t('event.host')) ?></dt><dd><?= e($event['club_name']) ?></dd></div>
                <div><dt><?= e(t('event.access')) ?></dt><dd><?= e(t((int) $event['venue_accessible'] === 1 ? 'event.access_yes' : 'event.access_no')) ?></dd></div>
            </dl>

            <h2><?= e(t('event.about')) ?></h2>
            <div class="prose"><?= nl2br(e($event['description'])) ?></div>

            <?php if ($over && ($ratingSummary['total'] > 0 || $canRate)): ?>
                <section class="ratings">
                    <h2><?= e(t('rating.heading')) ?></h2>
                    <?php if ($ratingSummary['total'] > 0): ?>
                        <p class="ratings__summary"><strong><?= e(number_format((float) $ratingSummary['average'], 1)) ?></strong> <?= e(t_count('rating.out_of', $ratingSummary['total'])) ?></p>
                    <?php endif; ?>
                    <?php if ($canRate): ?>
                        <form class="form panel" method="post" action="<?= e(url('/events/' . $event['slug'] . '/rate')) ?>">
                            <?= csrf_field() ?>
                            <fieldset class="stars">
                                <legend><?= e(t('rating.yours')) ?></legend>
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <label><input type="radio" name="rating" value="<?= $i ?>" required> <?= e(t_count('rating.stars', $i)) ?></label>
                                <?php endfor; ?>
                            </fieldset>
                            <?= partial('partials/field', ['name' => 'comment', 'label' => t('rating.comment'), 'type' => 'textarea', 'attrs' => ['maxlength' => 1000, 'rows' => 3]]) ?>
                            <button class="btn btn--primary" type="submit"><?= e(t('rating.submit')) ?></button>
                        </form>
                    <?php endif; ?>
                    <ul class="ratings__list">
                        <?php foreach ($ratings as $rating): ?>
                            <li>
                                <p class="ratings__who"><strong><?= e($rating['full_name']) ?></strong> <span class="muted"><?= e(t_count('rating.stars', (int) $rating['rating'])) ?></span></p>
                                <?php if ($rating['comment'] !== null): ?><p><?= e($rating['comment']) ?></p><?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endif; ?>
        </div>

        <aside class="event__side" aria-label="<?= e(t('booking.panel')) ?>">
            <div class="booking">
                <?= partial('partials/seats', [
                    'capacity' => (int) $event['capacity'],
                    'confirmed' => (int) $event['confirmed_count'],
                    'waitlisted' => (int) $event['waitlisted_count'],
                    'large' => true,
                    'closed' => !$bookable,
                ]) ?>

                <?php if ($registration !== null && $registration['status'] === 'confirmed'): ?>
                    <p class="booking__state booking__state--ok"><?= e(t($registration['checked_in_at'] !== null ? 'booking.state.checked_in' : 'booking.state.confirmed')) ?></p>
                    <?php if (!in_array($event['status'], ['completed', 'archived'], true)): ?>
                        <a class="btn btn--primary btn--block" href="<?= e(url('/my/tickets/' . $registration['id'])) ?>"><?= e(t('booking.view_ticket')) ?></a>
                    <?php endif; ?>
                    <?php if ($bookable): ?>
                        <form method="post" action="<?= e(url('/my/registrations/' . $registration['id'] . '/cancel')) ?>" data-confirm="<?= e(t('booking.cancel_confirm')) ?>">
                            <?= csrf_field() ?>
                            <button class="btn btn--quiet btn--block" type="submit"><?= e(t('booking.cancel')) ?></button>
                        </form>
                    <?php endif; ?>

                <?php elseif ($registration !== null && $registration['status'] === 'waitlisted'): ?>
                    <p class="booking__state"><?= e(t('booking.state.waitlisted', ['position' => (int) $position])) ?></p>
                    <p class="muted"><?= e(t('booking.waitlist_explained')) ?></p>
                    <?php if ($bookable): ?>
                        <form method="post" action="<?= e(url('/my/registrations/' . $registration['id'] . '/cancel')) ?>">
                            <?= csrf_field() ?>
                            <button class="btn btn--quiet btn--block" type="submit"><?= e(t('booking.leave_waitlist')) ?></button>
                        </form>
                    <?php endif; ?>

                <?php elseif (!$bookable): ?>
                    <p class="booking__state"><?= e(t('booking.closed.' . $event['status'])) ?></p>

                <?php elseif ($user === null): ?>
                    <a class="btn btn--primary btn--block" href="<?= e(url('/login?next=' . rawurlencode('/events/' . $event['slug']))) ?>"><?= e(t('booking.sign_in')) ?></a>
                    <p class="muted"><?= e(t('booking.sign_in_hint')) ?></p>

                <?php elseif ($user['role'] !== 'student'): ?>
                    <p class="muted"><?= e(t('booking.students_only')) ?></p>

                <?php else: ?>
                    <form method="post" action="<?= e(url('/events/' . $event['slug'] . '/book')) ?>" data-once>
                        <?= csrf_field() ?>
                        <input type="hidden" name="idempotency_key" value="<?= e($idempotencyKey) ?>">
                        <button class="btn btn--primary btn--block" type="submit"><?= e(t($seat['left'] > 0 ? 'booking.book' : 'booking.join_waitlist')) ?></button>
                    </form>
                    <p class="muted"><?= e(t($seat['left'] > 0 ? 'booking.book_hint' : 'booking.waitlist_explained')) ?></p>
                <?php endif; ?>
            </div>
        </aside>
    </div>
</article>
