<?php

/*
 * Searchable event picker, in the shape of Microsoft's "Request API permissions" dialog: a search box, expandable groups (with a
 * per-group select-all and an "n selected" count), every event with a name, its id and a one-line description, and the selection
 * shown as removable chips with a total count.
 *
 * eventPickerField('webhook_events[]', $selected) prints a container plus hidden <input name="webhook_events[]"> elements holding the
 * chosen ids / patterns. The UI itself is built client side by js/event_picker.js from the RivetCore EventCatalog JSON, which is
 * emitted ONCE per page/response (static guard, like the icon catalog), so pickers inside AJAX-loaded modals work through delegation.
 * Stored values may be patterns: "*" (all events) and "ticket.*" (a whole family) are written when everything in them is selected.
 * Handlers must validate what they receive (admin/includes/webhook_form_lib.php: rivetWebhookCleanEvents()).
 *
 * Options: mode 'multi' (default) | 'single' (one event, e.g. the trigger of an event rule), id, other (list of event ids seen on
 * this server but not in the catalog), label (accessible name of the search box).
 */

require_once __DIR__ . '/../vendor/autoload.php';

/** The catalog in the compact shape the picker script reads. */
function eventPickerCatalogJson(): string
{
    $groups = [];
    foreach (\RivetCore\Webhooks\EventCatalog::groups() as $key => $g) {
        $groups[] = ['k' => $key, 'l' => $g['label']];
    }
    $events = [];
    foreach (\RivetCore\Webhooks\EventCatalog::all() as $e) {
        $row = ['i' => $e->id, 'g' => $e->group, 'l' => $e->label, 'd' => $e->description, 's' => $e->severity];
        if ($e->tags) {
            $row['t'] = implode(' ', $e->tags);
        }
        if ($e->since === 'planned') {
            $row['p'] = 1;
        }
        $events[] = $row;
    }

    return (string) json_encode(['groups' => $groups, 'events' => $events], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
}

/**
 * @param list<string> $selected stored values (ids and/or patterns)
 * @param array{mode?:string,id?:string,other?:list<string>,label?:string} $options
 */
function eventPickerField(string $name, array $selected, array $options = []): void
{
    static $catalog_emitted = false;

    $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $mode = ($options['mode'] ?? 'multi') === 'single' ? 'single' : 'multi';
    $id = isset($options['id']) && $options['id'] !== '' ? preg_replace('/[^A-Za-z0-9_-]/', '', $options['id']) : 'event_picker_' . bin2hex(random_bytes(4));
    $other = array_values(array_filter(array_map('strval', $options['other'] ?? []), static fn (string $v): bool => preg_match('/^[a-z0-9_.]{1,150}$/', $v) === 1));
    $selected = array_values(array_filter(array_map('strval', $selected), static fn (string $v): bool => preg_match('/^[a-z0-9_.*-]{1,150}$/', $v) === 1));
    if ($mode === 'single') {
        $selected = array_slice($selected, 0, 1);
    }

    if (!$catalog_emitted) {
        $catalog_emitted = true;
        echo '<script type="application/json" class="event-picker-catalog">' . eventPickerCatalogJson() . '</script>' . "\n";
    }
    ?>
    <div class="event-picker" id="<?= $h($id) ?>" data-event-picker data-mode="<?= $mode ?>" data-field="<?= $h($name) ?>" data-label="<?= $h($options['label'] ?? 'Search events') ?>">
        <script type="application/json" class="event-picker-other"><?= json_encode($other, JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
        <div class="event-picker-values">
            <?php foreach ($selected as $v) { ?><input type="hidden" name="<?= $h($name) ?>" value="<?= $h($v) ?>"><?php } ?>
        </div>
        <div class="event-picker-ui" data-event-picker-ui>
            <div class="text-muted small">Loading events&hellip; <?= $selected ? '(' . $h(implode(', ', array_slice($selected, 0, 12))) . ($selected && count($selected) > 12 ? ', &hellip;' : '') . ')' : '' ?></div>
        </div>
    </div>
    <?php
}
