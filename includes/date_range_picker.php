<?php

/*
 * The shared date-range picker (RivetCore DateRange presets + a Litepicker calendar for "Custom range").
 *
 * dateRangePickerField($date_range) prints a button ("Last 30 days · Sep 6 – Oct 5") that opens a popover, plus the
 * hidden inputs the legacy filter already used: canned_date, dtf, dtt. Preset ranges submit only canned_date (dtf/dtt are
 * disabled so they stay out of the URL and saved views stay rolling); a custom range submits canned_date=custom with
 * dtf/dtt. The popover, keyboard handling and auto-submit live in js/date_range_picker.js (delegated from document, so it
 * also works inside AJAX modals). Server side, read the result with dateRangeFromRequest($_GET) (includes/date_range.php).
 *
 * $opts:
 *   default      preset id the page uses when nothing is chosen (shows a "clear" link when something else is active). 'alltime'
 *   hide_groups  preset groups to leave out, e.g. ['Upcoming'] (the current range's own preset is always kept)
 *   from_name / to_name  names of the hidden inputs (default dtf / dtt, or derived from $name: f_canned_date => f_dtf / f_dtt)
 *   autosubmit   submit the form when a range is applied (default true)
 *   id           id for the button (default random)
 */

require_once __DIR__ . '/date_range.php';

use RivetCore\Ui\DateRange;

function dateRangePickerField(DateRange $current, string $name = 'canned_date', array $opts = []): void
{
    $h = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $fromName = $opts['from_name'] ?? (preg_replace('/canned_date$/', 'dtf', $name) ?: 'dtf');
    $toName = $opts['to_name'] ?? (preg_replace('/canned_date$/', 'dtt', $name) ?: 'dtt');
    $default = DateRange::isPreset((string) ($opts['default'] ?? '')) ? (string) $opts['default'] : 'alltime';
    $hide = array_map('strval', (array) ($opts['hide_groups'] ?? []));
    $auto = ($opts['autosubmit'] ?? true) ? '1' : '0';
    $id = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($opts['id'] ?? '')) ?: 'drp_' . bin2hex(random_bytes(4));

    $isCustom = $current->preset() === 'custom';
    $defaultRange = dateRangeResolve($default);
    $isDefault = !$isCustom && $current->preset() === $default;
    $dates = dateRangeDisplayDates($current);
    $label = $isCustom ? 'Custom' : $current->label();
    $text = $label . ($dates !== '' ? ' · ' . $dates : '');

    // Group the catalog, dropping hidden groups (never the active preset's group).
    $groups = [];
    foreach (DateRange::presets() as $p) {
        if (in_array($p['group'], $hide, true) && $p['id'] !== $current->preset()) {
            continue;
        }
        $groups[$p['group']][] = $p;
    }
    ?>
    <div class="date-range-picker" data-date-range-picker data-default="<?= $h($default) ?>" data-autosubmit="<?= $auto ?>"
         data-default-label="<?= $h($defaultRange->label()) ?>">
        <input type="hidden" name="<?= $h($name) ?>" value="<?= $h($current->preset()) ?>" data-drp-preset>
        <input type="hidden" name="<?= $h($fromName) ?>" value="<?= $isCustom ? $h($current->from()) : '' ?>" data-drp-from <?= $isCustom ? '' : 'disabled' ?>>
        <input type="hidden" name="<?= $h($toName) ?>" value="<?= $isCustom ? $h($current->to()) : '' ?>" data-drp-to <?= $isCustom ? '' : 'disabled' ?>>
        <div class="drp-controls">
            <button type="button" class="drp-button" id="<?= $h($id) ?>" aria-haspopup="dialog" aria-expanded="false">
                <i class="far fa-calendar-alt drp-icon" aria-hidden="true"></i>
                <span class="drp-text"><?= $h($text) ?></span>
                <i class="fas fa-chevron-down drp-caret" aria-hidden="true"></i>
            </button>
            <button type="button" class="drp-clear" aria-label="Clear date range (back to <?= $h($defaultRange->label()) ?>)" title="Clear date range" <?= $isDefault ? 'hidden' : '' ?>>
                <i class="fas fa-times" aria-hidden="true"></i>
            </button>
        </div>
        <div class="drp-panel" role="dialog" aria-label="Choose a date range" hidden>
            <button type="button" class="drp-close" aria-label="Close date range"><span>Date range</span><i class="fas fa-times" aria-hidden="true"></i></button>
            <div class="drp-presets" role="listbox" aria-label="Preset ranges">
                <?php foreach ($groups as $group => $presets) { ?>
                    <div class="drp-group" role="group" aria-label="<?= $h($group) ?>">
                        <div class="drp-group-label" aria-hidden="true"><?= $h($group) ?></div>
                        <?php foreach ($presets as $p) {
                            $r = $p['id'] === 'custom' ? null : dateRangeResolve($p['id']);
                            $active = $p['id'] === 'custom' ? $isCustom : (!$isCustom && $current->preset() === $p['id']);
                            ?>
                            <button type="button" class="drp-option<?= $active ? ' selected' : '' ?>" role="option"
                                    aria-selected="<?= $active ? 'true' : 'false' ?>" tabindex="-1"
                                    data-preset="<?= $h($p['id']) ?>" data-label="<?= $h($p['label']) ?>"
                                    data-dates="<?= $h($r ? dateRangeDisplayDates($r) : '') ?>">
                                <span><?= $h($p['label']) ?></span>
                                <?php if ($r && !$r->isAllTime()) { ?><small><?= $h(dateRangeDisplayDates($r)) ?></small><?php } ?>
                            </button>
                        <?php } ?>
                    </div>
                <?php } ?>
            </div>
            <div class="drp-custom" hidden>
                <div class="drp-calendar" data-drp-calendar></div>
                <div class="drp-inputs">
                    <label>From <input type="date" class="form-control form-control-sm" data-drp-in-from min="1970-01-01" max="2099-12-31"></label>
                    <label>To <input type="date" class="form-control form-control-sm" data-drp-in-to min="1970-01-01" max="2099-12-31"></label>
                </div>
                <div class="drp-hint">Leave one end empty for "on or after" / "up to". Dates are in the app's timezone.</div>
                <div class="drp-actions">
                    <button type="button" class="btn btn-sm btn-light drp-cancel">Cancel</button>
                    <button type="button" class="btn btn-sm btn-primary drp-apply">Apply</button>
                </div>
            </div>
        </div>
    </div>
    <?php
}
