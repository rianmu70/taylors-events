<?php
/**
 * @var list<array<string,mixed>> $users
 * @var int $total
 * @var int $page
 * @var int $perPage
 * @var string $term
 * @var string $role
 * @var list<array{id:int,name:string}> $clubs
 */
page_title($title = t('user.title'));
$me = auth_user();
?>
<header class="page-head">
    <h1><?= e(t('user.title')) ?></h1>
    <p class="lead"><?= e(t('user.lead')) ?></p>
</header>

<form class="filters" method="get" action="<?= e(url('/admin/users')) ?>" role="search">
    <div class="field filters__search">
        <label for="q"><?= e(t('user.search')) ?></label>
        <input id="q" name="q" type="search" value="<?= e($term) ?>" maxlength="80">
    </div>
    <div class="field">
        <label for="role"><?= e(t('user.col.role')) ?></label>
        <select id="role" name="role">
            <option value=""><?= e(t('user.role_all')) ?></option>
            <?php foreach (App\Auth\Auth::ROLES as $option): ?>
                <option value="<?= e($option) ?>"<?= $option === $role ? ' selected' : '' ?>><?= e(t('role.' . $option)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filters__actions"><button class="btn btn--primary" type="submit"><?= e(t('browse.apply')) ?></button></div>
</form>

<p class="result-count"><?= e(t_count('user.count', $total)) ?></p>

<div class="table-wrap">
    <table class="table">
        <thead><tr>
            <th scope="col"><?= e(t('user.col.person')) ?></th>
            <th scope="col"><?= e(t('user.col.school')) ?></th>
            <th scope="col"><?= e(t('user.col.role')) ?></th>
            <th scope="col"><?= e(t('user.col.change')) ?></th>
            <th scope="col"><?= e(t('user.col.access')) ?></th>
        </tr></thead>
        <tbody>
            <?php foreach ($users as $user): ?>
                <?php $self = (int) $user['id'] === $me['id']; ?>
                <tr>
                    <td><strong><?= e($user['full_name']) ?></strong><br><span class="muted"><?= e($user['email']) ?></span></td>
                    <td><?= e($user['faculty_code'] ?? '') ?></td>
                    <td>
                        <?= partial('partials/pill', ['status' => 'role_' . $user['role']]) ?>
                        <?php if ($user['clubs'] !== null): ?><br><span class="muted"><?= e($user['clubs']) ?></span><?php endif; ?>
                    </td>
                    <td>
                        <?php if ($self): ?>
                            <span class="muted"><?= e(t('user.you')) ?></span>
                        <?php else: ?>
                            <form class="inline-form" method="post" action="<?= e(url('/admin/users/' . $user['id'] . '/role')) ?>">
                                <?= csrf_field() ?>
                                <label class="visually-hidden" for="role-<?= e($user['id']) ?>"><?= e(t('user.role_for', ['name' => $user['full_name']])) ?></label>
                                <select id="role-<?= e($user['id']) ?>" name="role">
                                    <?php foreach (App\Auth\Auth::ROLES as $option): ?>
                                        <option value="<?= e($option) ?>"<?= $option === $user['role'] ? ' selected' : '' ?>><?= e(t('role.' . $option)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <label class="visually-hidden" for="club-<?= e($user['id']) ?>"><?= e(t('user.club_for', ['name' => $user['full_name']])) ?></label>
                                <select id="club-<?= e($user['id']) ?>" name="club_id">
                                    <option value=""><?= e(t('user.club_none')) ?></option>
                                    <?php foreach ($clubs as $club): ?>
                                        <option value="<?= e($club['id']) ?>"><?= e($club['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button class="btn btn--quiet btn--small" type="submit"><?= e(t('user.save_role')) ?></button>
                            </form>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?= partial('partials/pill', ['status' => (int) $user['is_active'] === 1 ? 'active' : 'inactive']) ?>
                        <?php if (!$self): ?>
                            <form method="post" action="<?= e(url('/admin/users/' . $user['id'] . '/toggle')) ?>">
                                <?= csrf_field() ?>
                                <button class="btn btn--quiet btn--small" type="submit"><?= e(t((int) $user['is_active'] === 1 ? 'user.deactivate' : 'user.activate')) ?></button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?= partial('partials/pagination', ['page' => $page, 'perPage' => $perPage, 'total' => $total]) ?>
