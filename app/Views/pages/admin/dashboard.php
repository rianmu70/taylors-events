<?php
/**
 * Analytics dashboard.
 *
 * @var array<string,int|float|null> $headline
 * @var list<array<string,mixed>> $scarcest
 * @var list<array{name:string,events:int,bookings:int}> $categories
 * @var list<array<string,mixed>> $faculties
 * @var list<array{day:string,bookings:int}> $daily
 * @var list<array<string,mixed>> $venues
 */
page_title($title = t('dashboard.title'));
$fill = $headline['seats_offered'] > 0 ? round(100 * $headline['seats_taken'] / $headline['seats_offered']) : 0;
$maxDaily = max(1, ...array_column($daily, 'bookings'));
$maxCategory = max(1, ...array_map('intval', array_column($categories, 'bookings')));
$maxFaculty = max(1, ...array_map('intval', array_column($faculties, 'registrations')));
$maxVenue = max(1, ...array_map('floatval', array_column($venues, 'hours')));
?>
<header class="page-head">
    <h1><?= e(t('dashboard.title')) ?></h1>
    <p class="lead"><?= e(t('dashboard.lead')) ?></p>
</header>

<ul class="stats">
    <li class="stat stat--accent">
        <p class="stat__value"><?= e(number_format((int) $headline['seats_offered'] - (int) $headline['seats_taken'])) ?></p>
        <p class="stat__label"><?= e(t('dashboard.seats_left')) ?></p>
        <p class="stat__note"><?= e(t('dashboard.seats_note', ['taken' => number_format((int) $headline['seats_taken']), 'offered' => number_format((int) $headline['seats_offered']), 'percent' => $fill])) ?></p>
    </li>
    <li class="stat">
        <p class="stat__value"><?= e(number_format((int) $headline['waiting'])) ?></p>
        <p class="stat__label"><?= e(t('dashboard.waiting')) ?></p>
        <p class="stat__note"><?= e(t('dashboard.waiting_note')) ?></p>
    </li>
    <li class="stat">
        <p class="stat__value"><?= e((int) $headline['live']) ?></p>
        <p class="stat__label"><?= e(t('dashboard.live')) ?></p>
        <p class="stat__note"><a href="<?= e(url('/admin/approvals')) ?>"><?= e(t_count('dashboard.pending', (int) $headline['pending'])) ?></a></p>
    </li>
    <li class="stat">
        <p class="stat__value"><?= e($headline['show_up_rate'] === null ? t('dashboard.none') : $headline['show_up_rate'] . '%') ?></p>
        <p class="stat__label"><?= e(t('dashboard.show_up')) ?></p>
        <p class="stat__note"><?= e(t('dashboard.show_up_note', ['events' => (int) $headline['finished']])) ?></p>
    </li>
    <li class="stat">
        <p class="stat__value"><?= e($headline['average_rating'] === null ? t('dashboard.none') : number_format((float) $headline['average_rating'], 2)) ?></p>
        <p class="stat__label"><?= e(t('dashboard.rating')) ?></p>
        <p class="stat__note"><?= e(t('dashboard.rating_note')) ?></p>
    </li>
</ul>

<div class="split">
    <section class="panel">
        <h2><?= e(t('dashboard.scarcest')) ?></h2>
        <?php if ($scarcest === []): ?>
            <p class="muted"><?= e(t('dashboard.no_data')) ?></p>
        <?php else: ?>
            <ul class="scarce-list">
                <?php foreach ($scarcest as $event): ?>
                    <li>
                        <div>
                            <a href="<?= e(url('/events/' . $event['slug'])) ?>"><?= e($event['title']) ?></a>
                            <p class="muted"><?= e($event['club_name']) ?>, <?= e(format_datetime((string) $event['starts_at'], 'D j M')) ?></p>
                        </div>
                        <?= partial('partials/seats', ['capacity' => (int) $event['capacity'], 'confirmed' => (int) $event['confirmed_count'], 'waitlisted' => (int) $event['waitlisted_count']]) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="panel">
        <h2><?= e(t('dashboard.daily')) ?></h2>
        <svg class="columns" viewBox="0 0 <?= count($daily) * 20 ?> 64" role="img" aria-label="<?= e(t('dashboard.daily_aria', ['total' => array_sum(array_column($daily, 'bookings')), 'days' => count($daily)])) ?>">
            <?php foreach ($daily as $i => $point): ?>
                <?php $h = round(56 * $point['bookings'] / $maxDaily, 1); ?>
                <rect class="columns__bar" x="<?= $i * 20 + 3 ?>" y="<?= 60 - $h ?>" width="14" height="<?= max(0.5, $h) ?>" rx="1.5"><title><?= e(format_datetime($point['day'], 'j M') . ': ' . $point['bookings']) ?></title></rect>
            <?php endforeach; ?>
            <line class="columns__axis" x1="0" y1="60.5" x2="<?= count($daily) * 20 ?>" y2="60.5"/>
        </svg>
        <p class="columns__range"><span><?= e(format_datetime($daily[0]['day'], 'j M')) ?></span><span><?= e(t('dashboard.today')) ?></span></p>
        <div class="table-wrap">
            <table class="table table--compact">
                <caption class="visually-hidden"><?= e(t('dashboard.daily')) ?></caption>
                <thead><tr><th scope="col"><?= e(t('dashboard.col.day')) ?></th><th scope="col" class="num"><?= e(t('dashboard.col.bookings')) ?></th></tr></thead>
                <tbody>
                    <?php foreach (array_slice(array_reverse($daily), 0, 5) as $point): ?>
                        <tr><td><?= e(format_datetime($point['day'], 'D j M')) ?></td><td class="num"><?= e($point['bookings']) ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<div class="split">
    <section class="panel">
        <h2><?= e(t('dashboard.by_category')) ?></h2>
        <table class="table table--chart">
            <thead><tr><th scope="col"><?= e(t('browse.category')) ?></th><th scope="col" class="num"><?= e(t('dashboard.col.events')) ?></th><th scope="col" class="num"><?= e(t('dashboard.col.bookings')) ?></th><th scope="col"><span class="visually-hidden"><?= e(t('dashboard.col.share')) ?></span></th></tr></thead>
            <tbody>
                <?php foreach ($categories as $row): ?>
                    <tr><th scope="row"><?= e($row['name']) ?></th><td class="num"><?= e($row['events']) ?></td><td class="num"><?= e(number_format((int) $row['bookings'])) ?></td>
                        <td class="table__bar"><?= partial('partials/bar', ['value' => (int) $row['bookings'], 'max' => $maxCategory]) ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <section class="panel">
        <h2><?= e(t('dashboard.by_venue')) ?></h2>
        <table class="table table--chart">
            <thead><tr><th scope="col"><?= e(t('event.where')) ?></th><th scope="col" class="num"><?= e(t('dashboard.col.events')) ?></th><th scope="col" class="num"><?= e(t('dashboard.col.hours')) ?></th><th scope="col"><span class="visually-hidden"><?= e(t('dashboard.col.share')) ?></span></th></tr></thead>
            <tbody>
                <?php foreach ($venues as $row): ?>
                    <tr><th scope="row"><?= e($row['name']) ?></th><td class="num"><?= e($row['events']) ?></td><td class="num"><?= e($row['hours']) ?></td>
                        <td class="table__bar"><?= partial('partials/bar', ['value' => (float) $row['hours'], 'max' => $maxVenue]) ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
</div>

<section class="panel">
    <h2><?= e(t('dashboard.by_faculty')) ?></h2>
    <div class="table-wrap">
        <table class="table table--chart">
            <thead><tr>
                <th scope="col"><?= e(t('auth.field.faculty')) ?></th>
                <th scope="col" class="num"><?= e(t('dashboard.col.students')) ?></th>
                <th scope="col" class="num"><?= e(t('dashboard.col.bookings')) ?></th>
                <th scope="col" class="num"><?= e(t('dashboard.col.attended')) ?></th>
                <th scope="col"><span class="visually-hidden"><?= e(t('dashboard.col.share')) ?></span></th>
            </tr></thead>
            <tbody>
                <?php foreach ($faculties as $row): ?>
                    <tr><th scope="row"><?= e($row['name']) ?></th><td class="num"><?= e($row['students']) ?></td><td class="num"><?= e($row['registrations']) ?></td><td class="num"><?= e($row['attendances']) ?></td>
                        <td class="table__bar"><?= partial('partials/bar', ['value' => (int) $row['registrations'], 'max' => $maxFaculty]) ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
