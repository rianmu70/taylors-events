<?php
/** @var list<array<string,mixed>> $venues */
page_title($title = t('venue.title'));
?>
<header class="page-head page-head--with-action">
    <div>
        <h1><?= e(t('venue.title')) ?></h1>
        <p class="lead"><?= e(t('venue.lead')) ?></p>
    </div>
    <a class="btn btn--primary" href="<?= e(url('/admin/venues/new')) ?>"><?= e(t('venue.add')) ?></a>
</header>

<div class="table-wrap">
    <table class="table">
        <thead><tr>
            <th scope="col"><?= e(t('venue.col.name')) ?></th>
            <th scope="col"><?= e(t('venue.col.location')) ?></th>
            <th scope="col"><?= e(t('venue.col.type')) ?></th>
            <th scope="col" class="num"><?= e(t('venue.col.capacity')) ?></th>
            <th scope="col" class="num"><?= e(t('venue.col.upcoming')) ?></th>
            <th scope="col"><?= e(t('export.status')) ?></th>
            <th scope="col"><span class="visually-hidden"><?= e(t('table.actions')) ?></span></th>
        </tr></thead>
        <tbody>
            <?php foreach ($venues as $venue): ?>
                <tr>
                    <td><strong><?= e($venue['name']) ?></strong><br><span class="muted code"><?= e($venue['code']) ?></span></td>
                    <td><?= e($venue['building']) ?><?php if ($venue['floor_label'] !== null): ?>, <?= e(t('venue.floor', ['floor' => $venue['floor_label']])) ?><?php endif; ?></td>
                    <td><?= e(t('venue.type.' . $venue['venue_type'])) ?></td>
                    <td class="num"><?= e(number_format((int) $venue['capacity'])) ?></td>
                    <td class="num"><?= e($venue['upcoming']) ?></td>
                    <td><?= partial('partials/pill', ['status' => (int) $venue['is_active'] === 1 ? 'open' : 'closed']) ?></td>
                    <td class="table__actions">
                        <a class="btn btn--quiet btn--small" href="<?= e(url('/admin/venues/' . $venue['id'] . '/edit')) ?>"><?= e(t('venue.edit')) ?></a>
                        <form method="post" action="<?= e(url('/admin/venues/' . $venue['id'] . '/toggle')) ?>">
                            <?= csrf_field() ?>
                            <button class="btn btn--quiet btn--small" type="submit"><?= e(t((int) $venue['is_active'] === 1 ? 'venue.close' : 'venue.reopen')) ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
