<?php
/*
 * Which ticket category (and priority) a ticket made from an RMM alert gets (DB 2.6.101).
 *
 * Unconfigured (no default category, no routes) this changes nothing: the ticket has no category, as before.
 * Resolution order, most specific first: check family (disk, service, patch...), then the alert's source (which RMM),
 * then severity, then the default category. Inside one kind the lowest `sort` wins.
 * RivetMSP has no ticket boards or queues, so there is no board here (the route table keeps a board_id column for parity, always NULL).
 *
 * The resolver itself is pure (no database), so tests can feed it routes directly.
 */

/** Check families an admin can route on: key => [label, key prefixes of the endpoint agent's check keys]. */
function rmmAlertCheckFamilies(): array
{
    return [
        'disk'          => ['Disk space',            ['disk.', 'disk_', 'disk:']],
        'service'       => ['Service stopped',       ['service.', 'services.', 'service_', 'svc.']],
        'patch'         => ['Patching',              ['patch.', 'patches.', 'patch_']],
        'reboot'        => ['Reboot needed',         ['reboot.', 'reboot_']],
        'agent_update'  => ['Agent update',          ['agent_update', 'agent.update', 'agent.ring']],
        'log'           => ['Log rule',              ['log.']],
        'change'        => ['Change monitor',        ['change.', 'change_', 'fim.']],
    ];
}

function rmmAlertSeverities(): array
{
    return ['critical' => 'Critical', 'error' => 'Error', 'warning' => 'Warning', 'info' => 'Info'];
}

function rmmAlertSources(): array
{
    return ['rivetit_agent' => 'RivetIT endpoint agent', 'tactical_rmm' => 'Tactical RMM', 'level' => 'Level.io', 'action1' => 'Action1', 'sophos_central' => 'Sophos Central'];
}

function rmmAlertPriorities(): array
{
    return ['Low', 'Medium', 'High'];
}

/** The check key of an alert ('disk.utilization', 'log.rule_4'...), or ''. Endpoint-agent alerts carry it in raw_data_json and in their key (agent:<device>:<check>:<episode>). */
function rmmAlertCheckKey(array $alert): string
{
    $raw = $alert['raw_data_json'] ?? null;
    if (is_string($raw) && $raw !== '') {
        $j = json_decode($raw, true);
        if (is_array($j) && isset($j['check']) && is_string($j['check'])) {
            return strtolower(substr($j['check'], 0, 120));
        }
    }
    $key = (string) ($alert['tactical_alert_id'] ?? '');
    if (str_starts_with($key, 'agent:') && preg_match('/^agent:[^:]*:(.+):\d+$/', $key, $m)) {
        return strtolower(substr($m[1], 0, 120));
    }

    return '';
}

/** The family key of a check key, or '' when none matches. */
function rmmAlertCheckFamily(string $checkKey): string
{
    $checkKey = strtolower($checkKey);
    if ($checkKey === '') {
        return '';
    }
    foreach (rmmAlertCheckFamilies() as $fam => [, $prefixes]) {
        foreach ($prefixes as $p) {
            if (str_starts_with($checkKey, $p)) {
                return $fam;
            }
        }
    }

    return '';
}

/**
 * Pure resolver.
 * @param array $alert  an rmm_alerts row (severity, raw_data_json, tactical_alert_id)
 * @param string $source integration type of the alert ('rivetit_agent', 'tactical_rmm'...), '' when unknown
 * @param array $routes rows of rmm_alert_ticket_routes (match_type, match_value, category_id, priority, sort)
 * @param int $defaultCategory settings.config_rmm_alert_ticket_category_id (0 = none)
 * @return array{category_id:int,priority:?string,board_id:?int,matched:string}
 */
function rmmAlertResolveRoute(array $alert, string $source, array $routes, int $defaultCategory): array
{
    $family   = rmmAlertCheckFamily(rmmAlertCheckKey($alert));
    $severity = strtolower((string) ($alert['severity'] ?? ''));
    $wanted   = ['check_prefix' => $family, 'source' => strtolower($source), 'severity' => $severity];
    usort($routes, fn($a, $b) => [(int) ($a['sort'] ?? 0), (int) ($a['id'] ?? 0)] <=> [(int) ($b['sort'] ?? 0), (int) ($b['id'] ?? 0)]);
    foreach (['check_prefix', 'source', 'severity'] as $type) {
        if ($wanted[$type] === '') {
            continue;
        }
        foreach ($routes as $r) {
            if (($r['match_type'] ?? '') === $type && strtolower((string) $r['match_value']) === $wanted[$type]) {
                $prio = $r['priority'] ?? null;
                return [
                    'category_id' => (int) $r['category_id'],
                    'priority'    => in_array($prio, rmmAlertPriorities(), true) ? $prio : null,
                    'board_id'    => null,
                    'matched'     => "$type:" . $r['match_value'],
                ];
            }
        }
    }

    return ['category_id' => max(0, $defaultCategory), 'priority' => null, 'board_id' => null, 'matched' => $defaultCategory > 0 ? 'default' : 'none'];
}

/** Ticket categories that can be chosen: id => name (active only). */
function rmmAlertTicketCategories(mysqli $mysqli): array
{
    $out = [];
    $q = mysqli_query($mysqli, "SELECT category_id, category_name FROM categories WHERE category_type = 'Ticket' AND category_archived_at IS NULL ORDER BY category_name ASC");
    while ($q && ($r = mysqli_fetch_assoc($q))) {
        $out[(int) $r['category_id']] = $r['category_name'];
    }

    return $out;
}

function rmmAlertRoutesLoad(mysqli $mysqli): array
{
    $out = [];
    $q = @mysqli_query($mysqli, "SELECT id, match_type, match_value, category_id, board_id, priority, sort FROM rmm_alert_ticket_routes ORDER BY sort, id");
    while ($q && ($r = mysqli_fetch_assoc($q))) {
        $out[] = $r;
    }

    return $out;
}

function rmmAlertSourceOf(mysqli $mysqli, array $alert): string
{
    $iid = (int) ($alert['integration_id'] ?? 0);
    if ($iid <= 0) {
        return '';
    }
    $r = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT type FROM rmm_integrations WHERE id = $iid"));

    return (string) ($r['type'] ?? '');
}

/**
 * The category and priority to use for a new ticket from $alert, read from the settings and route table. Never returns a category that no longer exists
 * or is archived (that falls back to none, i.e. the old behaviour). Safe before DB 2.6.101 (returns no category).
 * @return array{category_id:int,priority:?string,matched:string}
 */
function rmmAlertTicketPlacement(mysqli $mysqli, array $alert): array
{
    $none = ['category_id' => 0, 'priority' => null, 'matched' => 'none'];
    $s = @mysqli_query($mysqli, "SELECT config_rmm_alert_ticket_category_id AS c FROM settings WHERE company_id = 1");
    if (!$s) {
        return $none;
    }
    $default = (int) (mysqli_fetch_assoc($s)['c'] ?? 0);
    $routes  = rmmAlertRoutesLoad($mysqli);
    if ($default <= 0 && $routes === []) {
        return $none;
    }
    $res = rmmAlertResolveRoute($alert, rmmAlertSourceOf($mysqli, $alert), $routes, $default);
    if ($res['category_id'] > 0) {
        $ok = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT category_id FROM categories WHERE category_id = " . (int) $res['category_id'] . " AND category_type = 'Ticket' AND category_archived_at IS NULL"));
        if (!$ok) {
            $res['category_id'] = 0;
        }
    }

    return ['category_id' => $res['category_id'], 'priority' => $res['priority'], 'matched' => $res['matched']];
}

/**
 * Validate a posted settings form. $post: default_category, route_type[], route_value[], route_category[], route_priority[].
 * @return array{default:int,routes:array,errors:array}
 */
function rmmAlertValidateSettings(array $post, array $validCategories): array
{
    $errors = [];
    $default = (int) ($post['default_category'] ?? 0);
    if ($default !== 0 && !isset($validCategories[$default])) {
        $errors[] = 'The chosen default category does not exist.';
        $default = 0;
    }
    $valid = [
        'check_prefix' => array_keys(rmmAlertCheckFamilies()),
        'source'       => array_keys(rmmAlertSources()),
        'severity'     => array_keys(rmmAlertSeverities()),
    ];
    $routes = [];
    $seen = [];
    $types = is_array($post['route_type'] ?? null) ? $post['route_type'] : [];
    if (count($types) > 50) {
        $errors[] = 'Too many rules (the limit is 50).';
        $types = array_slice($types, 0, 50, true);
    }
    foreach ($types as $i => $type) {
        $type  = is_string($type) ? $type : '';
        $value = $post['route_value'][$i] ?? '';
        $cat   = $post['route_category'][$i] ?? '';
        $prio  = $post['route_priority'][$i] ?? '';
        if ($type === '' && $value === '' && $cat === '') {
            continue; // an empty row
        }
        if (!is_string($value) || !is_string($cat) || !is_string($prio) || !isset($valid[$type]) || !in_array($value, $valid[$type], true)) {
            $errors[] = 'Rule ' . ((int) $i + 1) . ' has a "when" choice that is not allowed.';
            continue;
        }
        $cat = (int) $cat;
        if (!isset($validCategories[$cat])) {
            $errors[] = 'Rule ' . ((int) $i + 1) . ' points at a category that does not exist.';
            continue;
        }
        if ($prio !== '' && !in_array($prio, rmmAlertPriorities(), true)) {
            $errors[] = 'Rule ' . ((int) $i + 1) . ' has a priority that is not allowed.';
            continue;
        }
        if (isset($seen["$type:$value"])) {
            $errors[] = 'Rule ' . ((int) $i + 1) . ' repeats an earlier rule for the same "when" choice.';
            continue;
        }
        $seen["$type:$value"] = true;
        $routes[] = ['match_type' => $type, 'match_value' => $value, 'category_id' => $cat, 'priority' => $prio === '' ? null : $prio];
    }

    return ['default' => $default, 'routes' => $routes, 'errors' => $errors];
}

/** Plain-language sentence for one rule or the default; names are escaped by the caller. */
function rmmAlertDescribe(string $type, string $value): string
{
    if ($type === 'check_prefix') { return 'a "' . (rmmAlertCheckFamilies()[$value][0] ?? $value) . '" check fails'; }
    if ($type === 'source')       { return 'an alert comes from ' . (rmmAlertSources()[$value] ?? $value); }
    return 'an alert is ' . strtolower(rmmAlertSeverities()[$value] ?? $value) . ' severity';
}
