<?php
/**
 * Audit log viewer. Read only: the table itself refuses UPDATE and DELETE.
 *
 * @var list<array<string,mixed>> $entries
 * @var int $total
 * @var int $page
 * @var int $perPage
 * @var array{action:string,entity:string,actor:string} $filters
 */
page_title($title = t('audit.title'));
$pretty = static function (?string $json): string {
    if ($json === null) {
        return '';
    }
    $data = json_decode($json, true);
    if (!is_array($data)) {
        return $json;
    }
    $parts = [];
    foreach ($data as $key => $value) {
        $parts[] = $key . ': ' . (is_scalar($value) ? (string) $value : ($value === null ? 'null' : json_encode($value)));
    }
    return implode("\n", $parts);
};
?>
<header class="page-head">
    <h1><?= e(t('audit.title')) ?></h1>
    <p class="lead"><?= e(t('audit.lead')) ?></p>
</header>

<form class="filters" method="get" action="<?= e(url('/admin/audit')) ?>" role="search">
    <div class="field">
        <label for="action"><?= e(t('audit.filter.action')) ?></label>
        <input id="action" name="action" value="<?= e($filters['action']) ?>" placeholder="<?= e(t('audit.filter.action_placeholder')) ?>" maxlength="40">
    </div>
    <div class="field">
        <label for="entity"><?= e(t('audit.filter.entity')) ?></label>
        <select id="entity" name="entity">
            <option value=""><?= e(t('audit.filter.entity_all')) ?></option>
            <?php foreach (['event', 'registration', 'user'] as $entity): ?>
                <option value="<?= e($entity) ?>"<?= $entity === $filters['entity'] ? ' selected' : '' ?>><?= e(t('audit.entity.' . $entity)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="actor"><?= e(t('audit.filter.actor')) ?></label>
        <input id="actor" name="actor" value="<?= e($filters['actor']) ?>" maxlength="80">
    </div>
    <div class="filters__actions">
        <button class="btn btn--primary" type="submit"><?= e(t('browse.apply')) ?></button>
        <a class="btn btn--quiet" href="<?= e(url('/admin/audit')) ?>"><?= e(t('browse.clear')) ?></a>
    </div>
</form>

<p class="result-count"><?= e(t_count('audit.count', $total)) ?></p>

<?php if ($entries === []): ?>
    <div class="empty"><h2><?= e(t('audit.empty')) ?></h2><p><?= e(t('audit.empty_hint')) ?></p></div>
<?php else: ?>
<div class="table-wrap">
    <table class="table table--audit">
        <thead><tr>
            <th scope="col"><?= e(t('audit.col.when')) ?></th>
            <th scope="col"><?= e(t('audit.col.who')) ?></th>
            <th scope="col"><?= e(t('audit.col.action')) ?></th>
            <th scope="col"><?= e(t('audit.col.target')) ?></th>
            <th scope="col"><?= e(t('audit.col.before')) ?></th>
            <th scope="col"><?= e(t('audit.col.after')) ?></th>
        </tr></thead>
        <tbody>
            <?php foreach ($entries as $entry): ?>
                <tr>
                    <td><time datetime="<?= e($entry['created_at']) ?>"><?= e(format_datetime((string) $entry['created_at'], 'j M Y')) ?><br><span class="muted"><?= e(format_datetime((string) $entry['created_at'], 'H:i:s')) ?></span></time></td>
                    <td>
                        <?php if ($entry['actor_id'] === null): ?>
                            <span class="muted"><?= e(t('audit.system')) ?></span>
                        <?php else: ?>
                            <?= e($entry['actor_name'] ?? t('audit.removed_user', ['id' => $entry['actor_id']])) ?>
                        <?php endif; ?>
                        <?php if ($entry['ip_address'] !== null): ?><br><span class="muted code"><?= e($entry['ip_address']) ?></span><?php endif; ?>
                    </td>
                    <td class="code"><?= e($entry['action']) ?></td>
                    <td><?= e(t('audit.target', ['type' => $entry['entity_type'], 'id' => $entry['entity_id'] ?? ''])) ?></td>
                    <td><pre class="snapshot"><?= e($pretty($entry['before_data'])) ?></pre></td>
                    <td><pre class="snapshot"><?= e($pretty($entry['after_data'])) ?></pre></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?= partial('partials/pagination', ['page' => $page, 'perPage' => $perPage, 'total' => $total]) ?>
<?php endif; ?>
