<?php

/*
 * Visual Font Awesome icon catalog picker (RivetCore IconCatalog).
 *
 * iconPickerField('icon', $current, 'fa-filter') prints a button showing the current icon plus a hidden
 * <input name="icon"> holding the chosen class. The dropdown panel (search, category chips, icon grid and a
 * "custom class" box) is built client side by js/icon_picker.js from the catalog JSON, which is emitted ONCE
 * per page/response (static guard). Handlers must still validate with
 * \RivetCore\Ui\IconCatalog::normalize($_POST['icon'] ?? '', '<default>').
 */

require_once __DIR__ . '/../vendor/autoload.php';

function iconPickerField(string $name, string $value, string $default = 'fa-filter', ?string $id = null): void
{
    static $catalog_emitted = false;

    $value = \RivetCore\Ui\IconCatalog::normalize($value, $default);
    $default = \RivetCore\Ui\IconCatalog::normalize($default, 'fa-filter');
    $id = $id !== null && $id !== '' ? preg_replace('/[^A-Za-z0-9_-]/', '', $id) : 'icon_picker_' . bin2hex(random_bytes(4));
    $h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

    if (!$catalog_emitted) {
        $catalog_emitted = true;
        // toJson() is ASCII-safe; the extra flags keep "</script>" and "&" from ever ending the element early.
        $json = str_replace(['<', '>', '&'], ['<', '>', '&'], \RivetCore\Ui\IconCatalog::toJson());
        echo '<script type="application/json" class="icon-picker-catalog" data-version="'
            . (int) \RivetCore\Ui\IconCatalog::VERSION . '">' . $json . '</script>' . "\n";
    }
    ?>
    <div class="icon-picker" data-icon-picker data-default="<?= $h($default) ?>">
        <input type="hidden" name="<?= $h($name) ?>" id="<?= $h($id) ?>" value="<?= $h($value) ?>" data-icon-picker-input>
        <button type="button" class="icon-picker-button" aria-haspopup="dialog" aria-expanded="false" aria-label="Choose an icon (current: <?= $h($value) ?>)">
            <span class="icon-picker-preview" aria-hidden="true"><i class="fas <?= $h($value) ?>"></i></span>
            <span class="icon-picker-value"><?= $h($value) ?></span>
            <i class="fas fa-chevron-down icon-picker-caret" aria-hidden="true"></i>
        </button>
    </div>
    <?php
}
