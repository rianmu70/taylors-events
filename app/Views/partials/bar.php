<?php
/**
 * One horizontal bar of a chart, drawn as SVG so no inline style is needed.
 *
 * @var int|float $value
 * @var int|float $max
 */
$width = $max > 0 ? max(0, min(100, 100 * $value / $max)) : 0;
?>
<svg class="bar" viewBox="0 0 100 8" preserveAspectRatio="none" aria-hidden="true" focusable="false">
    <rect class="bar__track" width="100" height="8"/>
    <rect class="bar__fill" width="<?= e(round($width, 2)) ?>" height="8"/>
</svg>
