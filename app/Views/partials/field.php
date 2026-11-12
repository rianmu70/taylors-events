<?php
/**
 * One labelled form control with its hint and its error.
 *
 * @var string $name
 * @var string $label                 already translated
 * @var string $type                  text, email, password, number, datetime-local, textarea, select
 * @var mixed  $value
 * @var array<string,string> $errors  field => message
 * @var string|null $hint
 * @var array<int|string,string> $options  for select: value => label
 * @var array<string,string|int|bool> $attrs extra attributes, e.g. ['required' => true, 'maxlength' => 150]
 */
$type ??= 'text';
$value ??= '';
$errors ??= [];
$hint ??= null;
$options ??= [];
$attrs ??= [];
$id = 'f-' . $name;
$error = $errors[$name] ?? null;
$describedBy = trim(($hint !== null ? "$id-hint " : '') . ($error !== null ? "$id-error" : ''));
$extra = '';
foreach ($attrs as $attr => $attrValue) {
    if ($attrValue === false) {
        continue;
    }
    $extra .= ' ' . e($attr) . ($attrValue === true ? '' : '="' . e($attrValue) . '"');
}
if ($describedBy !== '') {
    $extra .= ' aria-describedby="' . e($describedBy) . '"';
}
if ($error !== null) {
    $extra .= ' aria-invalid="true"';
}
?>
<div class="field<?= $error !== null ? ' field--error' : '' ?>">
    <label for="<?= e($id) ?>"><?= e($label) ?></label>
    <?php if ($type === 'textarea'): ?>
        <textarea id="<?= e($id) ?>" name="<?= e($name) ?>"<?= $extra ?>><?= e($value) ?></textarea>
    <?php elseif ($type === 'select'): ?>
        <select id="<?= e($id) ?>" name="<?= e($name) ?>"<?= $extra ?>>
            <option value=""><?= e(t('form.choose')) ?></option>
            <?php foreach ($options as $optionValue => $optionLabel): ?>
                <option value="<?= e($optionValue) ?>"<?= (string) $optionValue === (string) $value ? ' selected' : '' ?>><?= e($optionLabel) ?></option>
            <?php endforeach; ?>
        </select>
    <?php else: ?>
        <input id="<?= e($id) ?>" name="<?= e($name) ?>" type="<?= e($type) ?>"<?= $type === 'password' ? '' : ' value="' . e($value) . '"' ?><?= $extra ?>>
    <?php endif; ?>
    <?php if ($hint !== null): ?><p class="field__hint" id="<?= e($id) ?>-hint"><?= e($hint) ?></p><?php endif; ?>
    <?php if ($error !== null): ?><p class="field__error" id="<?= e($id) ?>-error"><?= e($error) ?></p><?php endif; ?>
</div>
