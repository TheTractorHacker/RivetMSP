<?php

/*
 * RMM change monitoring (RivetCore 1.0.0-rc.22), 26.10.38: the MINIMAL, READ-ONLY "Changes" tab of the asset page. The full interface (watch presets, custom watches, rules, the search page)
 * comes in a later release; until then the switch (Administration > Endpoint agent > Inventory and logs) and what is recorded are all there is.
 *
 * The tab is drawn only while the module AND the `change_monitor` sub-switch are on and the viewer holds rmm.change.view for the device's client (a change record can hold configuration
 * text, so it is like running a saved script). The data is RmmReadModel::changeTimeline(); every recorded text (a registry value, a task command line, an actor name) is attacker-controlled
 * and printed through rmmH(), shortened, never as HTML.
 */

use RivetCore\Rmm\Authz\RmmAbility;

/** One cell of text: shortened to $max characters and escaped. */
function rivetRmmChangeText(string $s, int $max = 160): string
{
    $s = trim((string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', $s));
    if (mb_strlen($s) > $max) {
        $s = mb_substr($s, 0, $max - 1) . "\u{2026}";
    }

    return rmmH($s);
}

/**
 * The tab's content, or '' while there is nothing to show (module or switch off, no ability, tables missing).
 *
 * @param array<string,mixed> $vm rivetRmmUiPanel()
 */
function rivetRmmChangePanelSection(array $vm, \mysqli $mysqli, string $csrf): string
{
    if (!rivetRmmEnabled($mysqli) || !rivetRmmFeatureOn('change_monitor', $mysqli)) {
        return '';
    }
    try {
        $rmm = rivetRmmModule($mysqli);
        if (!$rmm->authorizer()->allowed((int) $vm['user_id'], RmmAbility::CHANGE_VIEW, (int) $vm['client_id'])) {
            return '';
        }
        $t = $rmm->readModel()->changeTimeline((int) $vm['device_id'], [], 100);
    } catch (\Throwable $e) {
        error_log('RMM changes tab: ' . get_class($e));

        return '';
    }
    $o = '<div id="rmm-changes"><p class="small text-muted">What changed on this computer in the last 30 days, newest first: configuration files and folders, registry values, local administrators and scheduled tasks that are being watched, and software installed or removed. '
        . 'This list is read-only. Choosing what is watched comes in a later release.</p>';
    if ($t['items'] === []) {
        return $o . '<p class="text-muted mb-0" data-rmm-changes-empty>Nothing has been recorded for this computer yet. Changes arrive after the agent has been given the watches and has made its first reading.</p></div>';
    }
    $rows = '';
    foreach ($t['items'] as $c) {
        $what = trim((string) $c['kind'] . ' ' . (string) $c['target'] . ((string) $c['item'] !== '' ? ' / ' . (string) $c['item'] : ''));
        $detail = (string) $c['summary'] !== '' ? (string) $c['summary'] : ((string) $c['old_value'] !== '' || (string) $c['new_value'] !== '' ? (string) $c['old_value'] . ' -> ' . (string) $c['new_value'] : '');
        $rows .= '<tr><td class="text-nowrap">' . rivetRmmUiTime((string) $c['occurred_at']) . '</td><td>' . rmmH((string) $c['change']) . '</td><td class="rmm-wrap">' . rivetRmmChangeText($what, 200)
            . '</td><td class="rmm-wrap">' . rivetRmmChangeText($detail, 240) . (!empty($c['redacted']) ? ' <span class="badge bg-secondary-lt text-muted">secrets hidden</span>' : '')
            . '</td><td>' . rivetRmmChangeText((string) $c['actor'], 60) . '</td></tr>';
    }
    $o .= '<div class="table-responsive"><table class="table table-sm mb-0"><caption class="visually-hidden">Recorded changes on this device, newest first</caption>'
        . rivetRmmUiThead(['When', 'Change', 'What', 'Details', 'Who']) . '<tbody>' . $rows . '</tbody></table></div>'
        . (!empty($t['truncated']) ? '<p class="small text-muted mt-2 mb-0">Only the newest entries are shown.</p>' : '') . '</div>';

    return $o;
}
