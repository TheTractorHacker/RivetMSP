<?php

/*
 * Administration > Endpoint agent > "What the agent checks": the check schedule as plain options instead of a JSON list.
 *
 * The page offers a short list of ready-made checks (low disk space, a restart waiting, important services, your own scripts) and one "how often".
 * This file turns the stored list into those options (decompose), and the posted options back into a list (compose). The list is then handed to
 * RivetCore\Rmm\Settings\ChecksValidator, which stays the one authority on what is accepted; the signed output of the module is unchanged.
 *
 * Compatibility: a stored check this form has no option for (a CPU, web address or certificate check, a disk check on one drive, a service that
 * must be stopped...) is listed under "Other checks already in your schedule" and is kept exactly as stored unless the administrator unticks it.
 * The server takes those from the STORED list by key, never from the request, so a request cannot smuggle in a definition.
 */

use RivetCore\Rmm\Settings\ChecksValidator;
use RivetCore\Rmm\Settings\RmmSettings;

final class RivetChecksSimple
{
    /** "Every ..." choices, in seconds. A value already stored is offered as one more choice, so nothing is changed silently. */
    public const EVERY = [300 => 'Every 5 minutes', 900 => 'Every 15 minutes', 1800 => 'Every 30 minutes', 3600 => 'Every hour',
        7200 => 'Every 2 hours', 21600 => 'Every 6 hours', 86400 => 'Once a day'];
    public const EVERY_MAIN = [300, 900, 1800, 3600];
    public const DISK_FREE = [5 => '5%', 10 => '10%', 15 => '15%', 20 => '20%', 25 => '25%', 30 => '30%'];
    public const SCRIPT_TIMEOUTS = [10 => '10 seconds', 30 => '30 seconds', 60 => '1 minute'];
    public const MAX_SERVICES = 30;
    public const MAX_SCRIPTS = 10;
    public const SERVICE_RE = '/^[A-Za-z0-9][A-Za-z0-9._@$ -]{0,127}$/';
    public const POST_KEYS = ['present', 'reset', 'disk_on', 'disk_free', 'reboot_on', 'svc', 'svc_extra', 'every', 'keep', 'scripts'];

    /** Services most people want to watch: value (the real service name) => [label, platform]. */
    public const SERVICES = [
        'EventLog' => ['Windows event log', 'Windows'],
        'wuauserv' => ['Windows Update', 'Windows'],
        'WinDefend' => ['Microsoft Defender antivirus', 'Windows'],
        'Spooler' => ['Print Spooler', 'Windows'],
        'TermService' => ['Remote Desktop', 'Windows'],
        'W32Time' => ['Windows time service', 'Windows'],
        'VeeamEndpointBackupSvc' => ['Veeam Agent for Windows (backup)', 'Windows'],
        'sshd' => ['SSH server', 'Linux'],
        'cron' => ['Scheduled tasks (cron)', 'Linux'],
        'chronyd' => ['Time sync (chrony)', 'Linux'],
        'rsyslog' => ['System log (rsyslog)', 'Linux'],
    ];

    /** The three presets at the top of the form (the browser fills the options from this; nothing is saved until Save). */
    public const PRESETS = [
        'recommended' => ['label' => 'Recommended', 'disk_on' => true, 'disk_free' => 15, 'reboot_on' => true, 'svc' => ['EventLog', 'WinDefend', 'W32Time'], 'every' => 900,
            'note' => 'Low disk space, a waiting restart and three core Windows services, every 15 minutes.'],
        'light' => ['label' => 'Light', 'disk_on' => true, 'disk_free' => 10, 'reboot_on' => false, 'svc' => ['EventLog'], 'every' => 3600,
            'note' => 'Only very low disk space and the event log, once an hour. Least traffic.'],
        'strict' => ['label' => 'Strict', 'disk_on' => true, 'disk_free' => 20, 'reboot_on' => true, 'svc' => ['EventLog', 'WinDefend', 'wuauserv', 'W32Time', 'TermService'], 'every' => 300,
            'note' => 'Earlier disk warning, more services, every 5 minutes.'],
    ];

    private const OTHER_NAMES = ['service' => 'Service', 'disk' => 'Disk space', 'pending_reboot' => 'Waiting for a restart', 'script' => 'Script', 'cpu' => 'CPU use', 'memory' => 'Memory use',
        'process' => 'Program running', 'port' => 'Network port', 'ping' => 'Ping', 'http' => 'Web address', 'cert' => 'Certificate expiry', 'ntp' => 'Clock accuracy',
        'smart' => 'Disk health', 'av' => 'Antivirus', 'systemd_failed' => 'Failed Linux services', 'inodes' => 'Disk file slots'];


    /**
     * The own settings of the four original check types, in the same field format the policy editor uses for the newer types (kind int/enum/str/text/bool, min, max, default,
     * values, max_length, required). Names are what the agent reads (service: name, expected, startup; disk: mount and the free-space limits; script: script, timeout_s).
     *
     * @return array<string,array<string,array<string,mixed>>>
     */
    public static function legacyFields(): array
    {
        return [
            'service' => [
                'name' => ['kind' => 'str', 'required' => true, 'max_length' => 128, 'label' => 'Service name', 'help' => 'The service name, not the display name.'],
                'expected' => ['kind' => 'enum', 'values' => ['running', 'stopped'], 'default' => 'running', 'label' => 'It should be', 'help' => 'The state that counts as healthy.'],
                'startup' => ['kind' => 'enum', 'values' => ['automatic', 'manual', 'disabled'], 'default' => '', 'label' => 'Start type must be', 'help' => 'Optional. A different start type gives a warning.'],
            ],
            'disk' => [
                'mount' => ['kind' => 'str', 'max_length' => 200, 'label' => 'Drive or folder', 'help' => 'Optional. Empty means every fixed disk, for example C: or /.'],
                'warn_free_pct' => ['kind' => 'int', 'min' => 1, 'max' => 99, 'default' => 20, 'label' => 'Warn below (% free)', 'help' => 'Warning when free space falls below this.'],
                'fail_free_pct' => ['kind' => 'int', 'min' => 1, 'max' => 99, 'default' => 10, 'label' => 'Critical below (% free)', 'help' => 'Critical when free space falls below this.'],
                'warn_free_gb' => ['kind' => 'int', 'min' => 1, 'max' => 100000, 'default' => 0, 'label' => 'Warn below (GB free)', 'help' => 'Optional, in addition to the percentage.'],
                'fail_free_gb' => ['kind' => 'int', 'min' => 1, 'max' => 100000, 'default' => 0, 'label' => 'Critical below (GB free)', 'help' => 'Optional, in addition to the percentage.'],
            ],
            'script' => [
                'script' => ['kind' => 'text', 'required' => true, 'max_length' => ChecksValidator::MAX_SCRIPT_BYTES, 'label' => 'Script (runs as code on the computer)', 'help' => 'At most 8 KiB. Exit 0 = OK, 1 = warning, anything else = failure.'],
                'timeout_s' => ['kind' => 'int', 'min' => 1, 'max' => ChecksValidator::SCRIPT_TIMEOUT_MAX_S, 'default' => ChecksValidator::SCRIPT_TIMEOUT_DEFAULT_S, 'label' => 'Stop it after (seconds, 1 to 60)', 'help' => 'The script is stopped after this long.'],
            ],
            'pending_reboot' => [],
        ];
    }

    /**
     * Check posted own settings of a legacy type against {@see legacyFields()} (the policy path has no server-side schema for them). Returns plain-word problems.
     *
     * @param array<string,mixed> $params
     * @return list<string>
     */
    public static function legacyProblems(string $type, array $params): array
    {
        $spec = self::legacyFields()[$type] ?? null;
        if ($spec === null) {
            return [];
        }
        $out = [];
        foreach ($spec as $name => $f) {
            $v = $params[$name] ?? null;
            if (!empty($f['required']) && ($v === null || trim((string) $v) === '')) {
                $out[] = $f['label'] . ' is needed.';
                continue;
            }
            if ($v === null) {
                continue;
            }
            if ($f['kind'] === 'enum' && !in_array($v, $f['values'], true)) {
                $out[] = $f['label'] . ': choose from the list.';
            } elseif (in_array($f['kind'], ['str', 'text'], true) && (!is_string($v) || strlen($v) > $f['max_length'])) {
                $out[] = $f['label'] . ' is too long (at most ' . $f['max_length'] . ' characters).';
            } elseif ($name === 'name' && is_string($v) && preg_match(self::SERVICE_RE, $v) !== 1) {
                $out[] = $f['label'] . ' may use letters, digits and . _ @ $ - only.';
            }
        }
        if (isset($params['warn_free_pct'], $params['fail_free_pct']) && $params['fail_free_pct'] > $params['warn_free_pct']) {
            $out[] = 'The critical percentage must not be above the warning percentage.';
        }

        return $out;
    }

    /** Names in a stored legacy check this editor has no field for (they are dropped on save and the form says so). */
    public static function legacyUnknown(string $type, array $own): array
    {
        return array_values(array_diff(array_keys($own), array_keys(self::legacyFields()[$type] ?? [])));
    }

    /** What the form shows for the built-in defaults (no list stored yet). */
    public static function recommendedState(): array
    {
        $p = self::PRESETS['recommended'];

        return ['disk' => ['on' => $p['disk_on'], 'free' => $p['disk_free'], 'key' => 'disk_free'], 'reboot' => ['on' => $p['reboot_on'], 'key' => 'pending_reboot'],
            'services' => array_map(static fn (string $n): array => ['name' => $n, 'key' => ''], $p['svc']), 'scripts' => [], 'every' => $p['every'], 'others' => []];
    }

    /**
     * The stored list as options.
     *
     * @param list<array<string,mixed>> $list
     * @return array{disk:array,reboot:array,services:list<array>,scripts:list<array>,every:int,others:list<array>}
     */
    public static function decompose(array $list): array
    {
        $st = ['disk' => ['on' => false, 'free' => 15, 'key' => 'disk_free'], 'reboot' => ['on' => false, 'key' => 'pending_reboot'], 'services' => [], 'scripts' => [], 'every' => 0, 'others' => []];
        $intervals = [];
        $haveDisk = $haveReboot = false;
        foreach ($list as $c) {
            if (!is_array($c)) {
                continue;
            }
            $type = (string) ($c['type'] ?? '');
            $key = (string) ($c['key'] ?? '');
            $iv = (int) ($c['interval_s'] ?? 0);
            $p = ChecksValidator::paramsOf($c);
            $keys = array_keys($p);
            if ($type === 'disk' && !$haveDisk && array_diff($keys, ['warn_free_pct', 'fail_free_pct']) === [] && isset($p['warn_free_pct']) && isset(self::DISK_FREE[$p['warn_free_pct']])
                && (!isset($p['fail_free_pct']) || $p['fail_free_pct'] === intdiv((int) $p['warn_free_pct'], 2))) {
                $haveDisk = true;
                $st['disk'] = ['on' => true, 'free' => (int) $p['warn_free_pct'], 'key' => $key];
                $intervals[] = $iv;
            } elseif ($type === 'pending_reboot' && !$haveReboot && $keys === []) {
                $haveReboot = true;
                $st['reboot'] = ['on' => true, 'key' => $key];
                $intervals[] = $iv;
            } elseif ($type === 'service' && isset($p['name']) && is_string($p['name']) && preg_match(self::SERVICE_RE, $p['name']) === 1
                && array_diff($keys, ['name', 'expected']) === [] && (($p['expected'] ?? 'running') === 'running') && !self::hasService($st['services'], $p['name'])) {
                $st['services'][] = ['name' => $p['name'], 'key' => $key];
                $intervals[] = $iv;
            } elseif ($type === 'script' && isset($p['script']) && is_string($p['script']) && array_diff($keys, ['script', 'timeout_s']) === [] && count($st['scripts']) < self::MAX_SCRIPTS) {
                $st['scripts'][] = ['name' => $key, 'body' => $p['script'], 'timeout' => (int) ($p['timeout_s'] ?? ChecksValidator::SCRIPT_TIMEOUT_DEFAULT_S), 'key' => $key];
                $intervals[] = $iv;
            } else {
                $st['others'][] = ['key' => $key, 'type' => $type, 'interval_s' => $iv, 'text' => self::describe($c)];
            }
        }
        // One interval for the recognised checks: the most common stored one.
        if ($intervals !== []) {
            $counts = array_count_values($intervals);
            arsort($counts);
            $st['every'] = (int) array_key_first($counts);
        } else {
            $st['every'] = 900;
        }

        return $st;
    }

    private static function hasService(array $services, string $name): bool
    {
        foreach ($services as $s) {
            if (strcasecmp($s['name'], $name) === 0) {
                return true;
            }
        }

        return false;
    }

    /** One plain sentence for a check this form cannot edit. */
    public static function describe(array $c): string
    {
        $type = (string) ($c['type'] ?? '');
        $label = self::OTHER_NAMES[$type] ?? 'Check';
        $p = ChecksValidator::paramsOf($c);
        $what = '';
        foreach (['name', 'mount', 'host', 'url', 'server'] as $f) {
            if (isset($p[$f]) && is_scalar($p[$f]) && (string) $p[$f] !== '') {
                $what = ' (' . mb_substr((string) $p[$f], 0, 80) . ')';
                break;
            }
        }

        return $label . $what . ', ' . self::everyWords((int) ($c['interval_s'] ?? 0));
    }

    public static function everyWords(int $s): string
    {
        if (isset(self::EVERY[$s])) {
            return strtolower(self::EVERY[$s]);
        }
        if ($s % 3600 === 0) {
            return 'every ' . ($s / 3600) . ' hours';
        }
        if ($s % 60 === 0) {
            return 'every ' . ($s / 60) . ' minutes';
        }

        return 'every ' . $s . ' seconds';
    }

    /** "5 checks every 15 minutes". */
    public static function summary(array $st): string
    {
        $n = ($st['disk']['on'] ? 1 : 0) + ($st['reboot']['on'] ? 1 : 0) + count($st['services']) + count($st['scripts']);
        $others = count(array_filter($st['others'], static fn (array $o): bool => !isset($o['keep']) || $o['keep']));
        $s = $n . ' ' . ($n === 1 ? 'check' : 'checks') . ' ' . self::everyWords((int) $st['every']);

        return $s . ($others > 0 ? ', plus ' . $others . ' other ' . ($others === 1 ? 'check' : 'checks') . ' kept as they are' : '');
    }

    /**
     * The posted options as a check list.
     *
     * @param array<string,mixed> $post the `cs` array of $_POST
     * @param list<array<string,mixed>> $stored the list currently in effect (stored, or the built-in defaults)
     * @return array{0:?list<array<string,mixed>>,1:list<string>,2:array<string,mixed>,3:bool} [list or null, plain-word errors, the options as typed (to put back in the form), reset]
     */
    public static function compose(array $post, array $stored): array
    {
        $errors = [];
        foreach (array_keys($post) as $k) {
            if (!in_array($k, self::POST_KEYS, true)) {
                $errors[] = 'The form sent an option this page does not know ("' . mb_substr((string) $k, 0, 30) . '"). Reload the page and try again.';
            }
        }
        $base = self::decompose($stored);
        $reset = ($post['reset'] ?? '') === '1';
        if ($reset) {
            return [[], [], $base, true];   // Restore defaults: nothing custom is stored, the built-in list applies
        }
        $str = static fn ($v): string => is_string($v) ? $v : '';
        $st = ['disk' => ['on' => ($post['disk_on'] ?? '') === '1', 'free' => 15, 'key' => $base['disk']['key']], 'reboot' => ['on' => ($post['reboot_on'] ?? '') === '1', 'key' => $base['reboot']['key']],
            'services' => [], 'scripts' => [], 'every' => $base['every'], 'others' => []];
        // how often
        $every = $str($post['every'] ?? '');
        $allowed = array_merge(array_keys(self::EVERY), array_map(static fn (array $c): int => (int) $c['interval_s'], array_merge($base['others'], [])), [$base['every']]);
        if (!ctype_digit($every) || !in_array((int) $every, $allowed, true)) {
            $errors[] = 'Choose how often to check from the list.';
        } else {
            $st['every'] = (int) $every;
        }
        // disk
        $free = $str($post['disk_free'] ?? '15');
        if (!ctype_digit($free) || !isset(self::DISK_FREE[(int) $free])) {
            $errors[] = 'Low disk space: choose one of 5, 10, 15, 20, 25 or 30 percent.';
        } else {
            $st['disk']['free'] = (int) $free;
        }
        // services: ticked ones from the checklist, then the extra box (one name per line)
        $keyOf = [];
        foreach ($base['services'] as $s) {
            $keyOf[strtolower($s['name'])] = $s['key'];
        }
        $names = [];
        $ticked = is_array($post['svc'] ?? null) ? $post['svc'] : [];
        foreach ($ticked as $n) {
            if (!is_string($n) || !isset(self::SERVICES[$n])) {
                $errors[] = 'Important services: one ticked service is not in the list. Use the "add another service" box for other names.';
                continue;
            }
            $names[$n] = true;
        }
        foreach (preg_split('/\R/', $str($post['svc_extra'] ?? '')) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match(self::SERVICE_RE, $line) !== 1) {
                $errors[] = 'Important services: "' . mb_substr($line, 0, 40) . '" is not a service name (letters, digits and . _ @ - only, up to 128 characters).';
                continue;
            }
            if (!isset(array_change_key_case($names)[strtolower($line)])) {
                $names[$line] = true;   // the same service typed twice (or in another case) is one check
            }
        }
        if (count($names) > self::MAX_SERVICES) {
            $errors[] = 'Important services: watch at most ' . self::MAX_SERVICES . ' services.';
        }
        foreach (array_keys($names) as $n) {
            $n = (string) $n;
            $st['services'][] = ['name' => $n, 'key' => $keyOf[strtolower($n)] ?? ''];
        }
        // scripts
        $rows = is_array($post['scripts'] ?? null) ? $post['scripts'] : [];
        $oldScripts = [];
        foreach ($base['scripts'] as $s) {
            $oldScripts[$s['key']] = $s;
        }
        if (count($rows) > self::MAX_SCRIPTS) {
            $errors[] = 'Your own scripts: at most ' . self::MAX_SCRIPTS . '.';
            $rows = array_slice($rows, 0, self::MAX_SCRIPTS);
        }
        $n = 0;
        foreach ($rows as $row) {
            $n++;
            if (!is_array($row)) {
                $errors[] = "Script $n: the form sent something unexpected.";
                continue;
            }
            foreach (array_keys($row) as $k) {
                if (!in_array($k, ['name', 'body', 'timeout', 'on'], true)) {
                    $errors[] = "Script $n: the form sent an option this page does not know.";
                }
            }
            $name = trim($str($row['name'] ?? ''));
            $body = $str($row['body'] ?? '');
            $to = $str($row['timeout'] ?? '30');
            if ($name === '' && trim($body) === '') {
                continue;   // an untouched blank row
            }
            $key = preg_replace('/[^A-Za-z0-9_.:-]+/', '_', $name);
            $key = trim((string) $key, '_');
            if ($key === '' || preg_match('/^[A-Za-z0-9_.:-]{1,100}$/', $key) !== 1) {
                $errors[] = "Script $n: give it a short name (letters, digits and . _ : - ).";
                $key = '';
            }
            if (trim($body) === '') {
                $errors[] = "Script $n: the script is empty.";
            } elseif (strlen($body) > ChecksValidator::MAX_SCRIPT_BYTES) {
                $errors[] = "Script $n: the script is " . strlen($body) . ' bytes; the limit is ' . ChecksValidator::MAX_SCRIPT_BYTES . ' (8 KiB).';
            }
            $allowedTo = array_keys(self::SCRIPT_TIMEOUTS);
            if (isset($oldScripts[$key])) {
                $allowedTo[] = $oldScripts[$key]['timeout'];
            }
            if (!ctype_digit($to) || !in_array((int) $to, $allowedTo, true)) {
                $errors[] = "Script $n: choose how long it may run (10 seconds, 30 seconds or 1 minute).";
                $to = '30';
            }
            $st['scripts'][] = ['name' => $name, 'body' => $body, 'timeout' => (int) $to, 'key' => $key];
        }
        // other checks: keep the ones that are ticked; the definition always comes from the stored list
        $keep = is_array($post['keep'] ?? null) ? $post['keep'] : null;
        $keepSet = [];
        foreach (($keep ?? []) as $k) {
            if (!is_string($k)) {
                continue;
            }
            $keepSet[$k] = true;
        }
        $storedOthers = array_column($base['others'], null, 'key');
        foreach (array_keys($keepSet) as $k) {
            if (!isset($storedOthers[$k])) {
                $errors[] = 'One of the "other checks" ticked is no longer in your schedule. Reload the page and try again.';
            }
        }
        foreach ($base['others'] as $o) {
            $o['keep'] = isset($keepSet[$o['key']]);
            $st['others'][] = $o;
        }
        $typed = $st;
        $typed['extra_raw'] = mb_substr($str($post['svc_extra'] ?? ''), 0, 4000);   // what was typed, to put back when a save is refused
        if ($errors !== []) {
            return [null, array_values(array_unique($errors)), $typed, $reset];
        }
        // build the list
        $out = [];
        $usedKeys = [];
        // reserve the kept checks' keys first, so a generated key never collides with one that must stay
        foreach ($base['others'] as $o) {
            if (isset($keepSet[$o['key']])) {
                $usedKeys[$o['key']] = true;
            }
        }
        $iv = $st['every'];
        if ($st['disk']['on']) {
            $out[] = ['key' => self::freeKey($st['disk']['key'], 'disk_free', $usedKeys), 'type' => 'disk', 'params' => ['warn_free_pct' => $st['disk']['free'], 'fail_free_pct' => intdiv($st['disk']['free'], 2)], 'interval_s' => $iv];
        }
        if ($st['reboot']['on']) {
            $out[] = ['key' => self::freeKey($st['reboot']['key'], 'pending_reboot', $usedKeys), 'type' => 'pending_reboot', 'params' => [], 'interval_s' => $iv];
        }
        foreach ($st['services'] as $s) {
            $slug = 'svc_' . strtolower(trim((string) preg_replace('/[^A-Za-z0-9_.:-]+/', '_', $s['name']), '_'));
            $out[] = ['key' => self::freeKey($s['key'], $slug, $usedKeys), 'type' => 'service', 'params' => ['name' => $s['name'], 'expected' => 'running'], 'interval_s' => $iv];
        }
        foreach ($st['scripts'] as $s) {
            $out[] = ['key' => self::freeKey($s['key'], 'script_' . $s['key'], $usedKeys), 'type' => 'script', 'params' => ['script' => $s['body'], 'timeout_s' => $s['timeout']], 'interval_s' => $iv];
        }
        foreach ($stored as $c) {
            if (is_array($c) && isset($keepSet[(string) ($c['key'] ?? '')]) && isset($storedOthers[(string) $c['key']])) {
                $out[] = ['key' => (string) $c['key'], 'type' => (string) $c['type'], 'params' => ChecksValidator::paramsOf($c), 'interval_s' => (int) $c['interval_s']];
            }
        }
        if ($out === [] && !$reset) {
            return [null, ['Switch on at least one check, or press Restore defaults.'], $typed, $reset];
        }
        if ($out !== []) {
            [$clean, $err] = ChecksValidator::validate((string) json_encode(ChecksValidator::toWire($out)));
            if ($clean === null) {
                return [null, [(string) $err], $typed, $reset];
            }
            $out = $clean;
        }

        return [$out, [], $typed, $reset];
    }

    /** The key of a recognised check is kept when it is free; otherwise a fresh one is made. */
    private static function freeKey(string $keep, string $fallback, array &$used): string
    {
        $want = $keep !== '' && !isset($used[$keep]) ? $keep : $fallback;
        $want = substr($want, 0, 100);
        $try = $want;
        $i = 2;
        while (isset($used[$try])) {
            $try = substr($want, 0, 95) . '_' . $i++;
        }
        $used[$try] = true;

        return $try;
    }

    /** The list in effect: the stored list, or the built-in defaults while none is stored. */
    public static function effective(RmmSettings $settings): array
    {
        return $settings->checks();
    }

    /** True while the module serves its built-in defaults. */
    public static function isDefault(RmmSettings $settings): bool
    {
        return trim((string) ($settings->get()['checks_json'] ?? '')) === '';
    }

    /**
     * The stored settings of a check policy from the plain fields of the form. Only the fields of the chosen type are read; anything else in the request is
     * refused, and every value is checked against the form it takes. Returns [JSON text, null] or [null, problem in plain words].
     *
     * @param array<mixed> $pp
     * @return array{0:?string,1:?string}
     */
    public static function policyParams(string $type, array $pp): array
    {
        $allowed = ['diskspace' => ['disk'], 'ping' => ['ip', 'failures'], 'winsvc' => ['svc_name', 'restart_if_stopped'],
            'eventlog' => ['log_name', 'event_type', 'event_id', 'fail_count', 'search_last_days'], 'cpuload' => [], 'memory' => []];
        if (!isset($allowed[$type])) {
            return [null, 'Choose a check type from the list.'];
        }
        foreach ($pp as $k => $val) {
            if (!in_array($k, $allowed[$type], true) || !is_string($val)) {
                return [null, 'The form sent a setting that does not belong to this check type.'];
            }
        }
        $v = static fn (string $k): string => isset($pp[$k]) && is_string($pp[$k]) ? trim($pp[$k]) : '';
        $int = static function (string $k, int $min, int $max, string $label, ?string &$err) use ($v): ?int {
            $x = $v($k);
            if ($x === '') {
                return null;
            }
            if (preg_match('/^[0-9]{1,6}$/', $x) !== 1 || (int) $x < $min || (int) $x > $max) {
                $err = "$label must be a whole number from $min to $max.";
                return null;
            }
            return (int) $x;
        };
        $err = null;
        $out = [];
        switch ($type) {
            case 'diskspace':
                $d = $v('disk');
                if ($d !== '') {
                    if (preg_match('~^([A-Za-z]:?|/)$~', $d) !== 1) {
                        return [null, 'Drive letter: type one letter such as C, or / on Linux and macOS.'];
                    }
                    $out['disk'] = $d === '/' ? '/' : strtoupper($d[0]);
                }
                break;
            case 'ping':
                $ip = $v('ip');
                if ($ip !== '') {
                    if (preg_match('/^([A-Za-z0-9]([A-Za-z0-9.-]{0,251}[A-Za-z0-9])?|[0-9A-Fa-f:]{2,45})$/', $ip) !== 1) {
                        return [null, 'Address to ping: type a host name or an IP address.'];
                    }
                    $out['ip'] = $ip;
                }
                $f = $int('failures', 1, 100, 'Failed pings in a row', $err);
                if ($f !== null) {
                    $out['failures'] = $f;
                }
                break;
            case 'winsvc':
                $n = $v('svc_name');
                if ($n === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9._@$ -]{0,127}$/', $n) !== 1) {
                    return [null, 'Service name: type the name of the service (letters, digits and . _ @ $ - only).'];
                }
                $out['svc_name'] = $n;
                $out['restart_if_stopped'] = $v('restart_if_stopped') === '1';
                break;
            case 'eventlog':
                $log = $v('log_name');
                if ($log !== '') {
                    if (!in_array($log, ['Application', 'System', 'Security'], true)) {
                        return [null, 'Look in: choose Application, System or Security.'];
                    }
                    $out['log_name'] = $log;
                }
                $et = $v('event_type');
                if ($et !== '') {
                    if (!in_array($et, ['ERROR', 'WARNING', 'INFO', 'AUDIT_SUCCESS', 'AUDIT_FAILURE'], true)) {
                        return [null, 'Kind of event: choose one from the list.'];
                    }
                    $out['event_type'] = $et;
                }
                foreach (['event_id' => [0, 65535, 'Event number'], 'fail_count' => [1, 1000, 'Alert at this many'], 'search_last_days' => [1, 365, 'Look back (days)']] as $k => [$lo, $hi, $label]) {
                    $n = $int($k, $lo, $hi, $label, $err);
                    if ($n !== null) {
                        $out[$k] = $n;
                    }
                }
                break;
        }
        if ($err !== null) {
            return [null, $err];
        }

        return [$out === [] ? '{}' : (string) json_encode($out, JSON_UNESCAPED_SLASHES), null];
    }
}
