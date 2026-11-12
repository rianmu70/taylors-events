<?php
/**
 * A status label. Wording comes from "status.<value>" in the language file.
 *
 * @var string $status an event status or a registration status
 */
?>
<span class="pill pill--<?= e($status) ?>"><?= e(t('status.' . $status)) ?></span>
