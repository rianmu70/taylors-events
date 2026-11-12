<?php
/**
 * Previous / next links. Shown only when there is more than one page.
 *
 * @var int $page
 * @var int $perPage
 * @var int $total
 */
$pages = (int) max(1, ceil($total / max(1, $perPage)));
if ($pages <= 1) {
    return;
}
?>
<nav class="pagination" aria-label="<?= e(t('pagination.label')) ?>">
    <?php if ($page > 1): ?>
        <a class="btn btn--quiet btn--small" rel="prev" href="<?= e(query_url(['page' => $page - 1])) ?>"><?= e(t('pagination.previous')) ?></a>
    <?php endif; ?>
    <span class="pagination__status"><?= e(t('pagination.status', ['page' => $page, 'pages' => $pages])) ?></span>
    <?php if ($page < $pages): ?>
        <a class="btn btn--quiet btn--small" rel="next" href="<?= e(query_url(['page' => $page + 1])) ?>"><?= e(t('pagination.next')) ?></a>
    <?php endif; ?>
</nav>
