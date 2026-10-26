<?php

/*
 * Administration > RMM > Data store (RivetCore 1.0.0-rc.40/41, phase 4 of #89; docs/rmm/POSTGRES.md section 13-16). Pure rendering, the same convention as
 * includes/rmm_scale_cards.php: rivetRmmUiDataStoreCard() takes everything it prints in $ctx and returns HTML; it queries nothing. Every control posts to
 * admin/post/settings_endpoint_agent.php, which does every validation, authorization decision and audit entry through RivetCore\Rmm\Store\DataStoreSwitch /
 * RmmStoreMigrator (this file only shows their answers). The connection's password is never read back from the module, never put in a hidden field, never
 * logged: the form field is always blank and the help text says so. js/rmm_scale.js (already loaded by this page) handles "Test connection" and the
 * migration-progress poll; every control still works, just without the live answer, if JS is off.
 */

require_once __DIR__ . '/rmm_ui_render.php';

/** host:port/database as user, or "not set yet". */
function rivetRmmDsConnSummary(?array $c): string
{
    if ($c === null) {
        return 'not set yet';
    }

    return rmmH($c['host']) . ':' . rmmH($c['port']) . '/' . rmmH($c['database']) . ' as ' . rmmH($c['user']);
}

/** @param array<string,mixed> $m one row of DataStoreSwitch::status()['migration'] / ['history'] */
function rivetRmmDsRunLabel(array $m): string
{
    $state = (string) $m['state'];
    $pill = ['copying' => 'warn', 'dual_write' => 'warn', 'verified' => 'ok', 'cutover' => 'ok', 'rolled_back' => 'off', 'aborted' => 'off', 'failed' => 'crit', 'switched_only' => 'off'][$state] ?? 'off';

    return rivetRmmUiPill($pill, str_replace('_', ' ', ucfirst($state))) . ' ' . rmmH(ucfirst((string) $m['from_store'])) . ' &rarr; ' . rmmH(ucfirst((string) $m['to_store']));
}

/**
 * @param array{csrf:string,msg:?array,old:array<string,mixed>,status:array<string,mixed>,migrating:bool} $ctx
 */
function rivetRmmUiDataStoreCard(array $ctx): string
{
    $csrf = $ctx['csrf'];
    $old = $ctx['old'];
    $st = $ctx['status'];
    $kind = (string) $st['data_store'];
    $isPg = $kind === \RivetCore\Rmm\Store\RmmDataStoreKind::POSTGRES;
    $conn = $st['connection'] ?? null;
    $field = static function (string $name, string $label, string $type, $value, string $help = '', array $attrs = []) use ($old): string {
        $v = array_key_exists($name, $old) ? (string) $old[$name] : (string) $value;
        $extra = '';
        foreach ($attrs as $k => $a) {
            $extra .= ' ' . $k . '="' . rmmH((string) $a) . '"';
        }

        return '<div class="col-sm-6 col-lg-4"><label class="form-label" for="ds_' . $name . '">' . rmmH($label) . '</label><input type="' . $type . '" class="form-control" id="ds_' . $name . '" name="' . $name . '" value="' . rmmH($v) . '"' . $extra . '>'
            . ($help !== '' ? '<div class="form-text">' . $help . '</div>' : '') . '</div>';
    };
    $selSsl = static function (string $value) use ($old): string {
        $v = (string) ($old['sslmode'] ?? $value);
        $out = '<div class="col-sm-6 col-lg-4"><label class="form-label" for="ds_sslmode">SSL mode</label><select class="form-select" id="ds_sslmode" name="sslmode">';
        foreach (\RivetCore\Rmm\Store\Postgres\PostgresConfig::SSL_MODES as $m) {
            $out .= '<option value="' . rmmH($m) . '"' . ($m === $v ? ' selected' : '') . '>' . rmmH($m) . '</option>';
        }

        return $out . '</select><div class="form-text">Use verify-full (with a CA file) for a database that is not on this machine.</div></div>';
    };

    $connForm = '<form method="post" action="post.php" class="row g-3 mb-3" data-rmm-scale-test-group="ds">'
        . '<input type="hidden" name="csrf_token" value="' . rmmH($csrf) . '"><input type="hidden" name="ea_tab" value="inventory">'
        . $field('host', 'Host', 'text', $conn['host'] ?? '127.0.0.1', 'A host name, an IP address, or a unix socket directory starting with "/" (recommended for the same machine).')
        . $field('port', 'Port', 'number', $conn['port'] ?? 5432, '', ['min' => 1, 'max' => 65535])
        . $field('database', 'Database', 'text', $conn['database'] ?? '')
        . $field('user', 'Role', 'text', $conn['user'] ?? '')
        . $field('password', 'Password', 'password', '', $conn !== null && $conn['password_set'] ? 'Leave blank to keep the stored password. Changing the host, port, role or database requires typing it again.' : 'Required the first time.', ['autocomplete' => 'new-password'])
        . $selSsl('prefer')
        . $field('schema', 'Schema', 'text', $conn['schema'] ?? 'public')
        . '<div class="col-12"><div data-rmm-scale-live aria-live="polite"></div></div>'
        . '<div class="col-12 d-flex gap-2 flex-wrap">'
        . '<button type="submit" name="rmm_ds_save_connection" value="1" class="btn btn-outline-secondary"><i class="fas fa-save me-1" aria-hidden="true"></i>Save connection</button>'
        . '<button type="button" class="btn btn-outline-primary" data-rmm-scale-test="ds"><i class="fas fa-plug me-1" aria-hidden="true"></i>Test connection</button>'
        . '</div></form>';

    $statusRows = [
        ['Active store', rivetRmmUiPill($isPg ? 'ok' : 'off', ucfirst($kind))],
        ['Stored connection', rivetRmmDsConnSummary($conn)],
    ];
    if ($conn !== null && $conn['tested_at'] !== null) {
        $statusRows[] = ['Last test', ($conn['test_ok'] ? rivetRmmUiPill('ok', 'Passed') : rivetRmmUiPill('crit', 'Failed')) . ' ' . rmmH(rivetRmmTime($conn['tested_at']))
            . ($conn['server_version'] !== null ? ' &middot; PostgreSQL ' . rmmH($conn['server_version']) : '') . ($conn['storage_mode'] !== null ? ' &middot; ' . rmmH($conn['storage_mode']) . ' mode' : '')];
    }
    $lb = $st['left_behind'] ?? null;
    $leftBehindHtml = '';
    if (is_array($lb)) {
        $leftBehindHtml = '<div class="alert alert-warning py-2 mb-3" role="alert"><i class="fas fa-exclamation-triangle me-1" aria-hidden="true"></i>' . rmmH((string) $lb['message']) . ' <a href="#ds-migrate">Migrate data</a>.</div>';
    }

    $switchBlock = '<div class="d-flex flex-wrap gap-3 align-items-start mb-3">'
        . '<form method="post" action="post.php" class="d-inline"><input type="hidden" name="csrf_token" value="' . rmmH($csrf) . '"><input type="hidden" name="ea_tab" value="inventory">'
        . '<div class="form-check mb-2" id="ds_ack_holder"><input class="form-check-input" type="checkbox" id="ds_ack" name="acknowledge_no_data_move" ' . ($isPg ? 'disabled' : 'required') . '>'
        . '<label class="form-check-label small" for="ds_ack">' . rmmH(\RivetCore\Rmm\Store\DataStoreSwitch::NO_DATA_MOVE_NOTICE) . '</label></div>'
        . '<button type="submit" name="rmm_ds_switch_to" value="postgres" class="btn btn-primary" ' . ($isPg ? 'disabled' : '') . ' data-rivet-confirm="Switch the RMM data store to PostgreSQL now? Existing history stays in MariaDB and is not shown until you migrate it."><i class="fas fa-database me-1" aria-hidden="true"></i>Switch to PostgreSQL</button>'
        . '</form>'
        . '<form method="post" action="post.php" class="d-inline"><input type="hidden" name="csrf_token" value="' . rmmH($csrf) . '"><input type="hidden" name="ea_tab" value="inventory">'
        . '<button type="submit" name="rmm_ds_switch_to" value="mariadb" class="btn btn-outline-secondary" ' . (!$isPg ? 'disabled' : '') . ' data-rivet-confirm="Switch the RMM data store back to MariaDB? History written to PostgreSQL meanwhile stays there until you migrate it."><i class="fas fa-undo me-1" aria-hidden="true"></i>Switch back to MariaDB</button>'
        . '</form></div>';

    // ---------------------------------------------------------------------------------------------------- migration
    $mig = $st['migration'] ?? null;
    $migrateHtml = '<h5 id="ds-migrate" class="mt-4">Migrate data</h5><p class="small text-muted">Copies the RMM history between MariaDB and PostgreSQL, verifies it, then cuts over. Retention and the rollups are paused for the active store while a migration owns it. '
        . 'Runs as a background job (never inside this page\'s request), so this card polls its progress every few seconds.</p>';
    $active = is_array($mig) && in_array((string) $mig['state'], ['copying', 'dual_write', 'verified', 'failed'], true);
    if ($active) {
        $migrateHtml .= '<div id="ds-migrate-status" class="mb-2" data-rmm-ds-poll="1" data-rmm-ds-csrf="' . rmmH($csrf) . '">' . rivetRmmUiDsMigrationStatusHtml($mig) . '</div>'
            . '<div class="d-flex flex-wrap gap-2">'
            . rivetRmmUiDsActionForm($csrf, 'rmm_ds_migrate_resume', 'Resume', 'btn-outline-primary', (string) $mig['state'] === 'failed' ? null : 'd-none')
            . rivetRmmUiDsActionForm($csrf, 'rmm_ds_migrate_cutover', 'Cut over now', 'btn-success', (string) $mig['state'] === 'verified' ? null : 'd-none')
            . rivetRmmUiDsActionForm($csrf, 'rmm_ds_migrate_abort', 'Abort', 'btn-outline-danger', null, 'Abort the migration in progress? Rows already copied stay in the target until you purge them.')
            . '</div>';
    } else {
        $migrateHtml .= '<form method="post" action="post.php" class="d-flex flex-wrap gap-2 align-items-end mb-2">'
            . '<input type="hidden" name="csrf_token" value="' . rmmH($csrf) . '"><input type="hidden" name="ea_tab" value="inventory">'
            . '<div><label class="form-label small mb-1" for="ds_mig_to">Direction</label><select class="form-select form-select-sm" id="ds_mig_to" name="rmm_ds_migrate_to"><option value="postgres"' . ($kind === 'mariadb' ? ' selected' : '') . '>MariaDB &rarr; PostgreSQL</option><option value="mariadb"' . ($kind === 'postgres' ? ' selected' : '') . '>PostgreSQL &rarr; MariaDB</option></select></div>'
            . '<button type="submit" name="rmm_ds_migrate_dry_run" value="1" class="btn btn-outline-secondary btn-sm"><i class="fas fa-vial me-1" aria-hidden="true"></i>Dry run</button>'
            . '<button type="submit" name="rmm_ds_migrate_start" value="1" class="btn btn-primary btn-sm" data-rivet-confirm="Start migrating the RMM history now? This runs in the background and pauses retention for the store it copies into until it finishes."><i class="fas fa-play me-1" aria-hidden="true"></i>Start migration</button>'
            . '</form>';
        if ($mig !== null && (string) $mig['state'] === 'cutover' && $mig['rollback_until'] !== null) {
            $migrateHtml .= '<div class="alert alert-info py-2 mb-2">Cut over ' . rmmH(rivetRmmTime((string) $mig['cutover_at'])) . '. Roll back is available until ' . rmmH(rivetRmmTime((string) $mig['rollback_until'])) . '.</div>'
                . '<div class="d-flex flex-wrap gap-2 mb-2">'
                . rivetRmmUiDsActionForm($csrf, 'rmm_ds_migrate_rollback', 'Roll back', 'btn-outline-warning', null, 'Roll back to ' . ucfirst((string) $mig['from_store']) . '? Anything written to ' . ucfirst((string) $mig['to_store']) . ' since the cut-over is copied back first, then the setting flips.')
                . rivetRmmUiDsActionForm($csrf, 'rmm_ds_migrate_purge', 'Purge old store', 'btn-outline-danger', null, null, true)
                . '</div>';
        }
    }
    if (!empty($st['history'])) {
        $rows = '';
        foreach (array_slice($st['history'], 0, 5) as $h) {
            $rows .= '<tr><td>' . (int) $h['run_id'] . '</td><td>' . rivetRmmDsRunLabel($h) . '</td><td>' . rmmH(rivetRmmTime((string) $h['started_at'])) . '</td><td>' . rmmH($h['last_error'] ?? '') . '</td></tr>';
        }
        $migrateHtml .= '<details class="mt-2"><summary class="small text-muted">Recent runs</summary><div class="table-responsive"><table class="table table-sm mt-2"><thead><tr><th>#</th><th>Run</th><th>Started</th><th>Error</th></tr></thead><tbody>' . $rows . '</tbody></table></div></details>';
    }

    $body = ($ctx['msg'] !== null ? rivetRmmScaleMsgHtml($ctx['msg']) : '') . $leftBehindHtml
        . '<h5>Connection</h5>' . $connForm
        . '<h5>Status</h5><div class="rmm-scale-dl mb-3">' . rivetRmmUiDl($statusRows) . '</div>'
        . $switchBlock
        . $migrateHtml;

    return rivetRmmScaleCard('data-store', 'database', 'Data store', true, $body, ucfirst($kind));
}

/** One button in its own tiny form (so each posts only its own action), optionally hidden by a class and/or behind a RivetDialog confirm. $typed: true asks for a typed confirmation phrase via RivetDialog (purge only). */
function rivetRmmUiDsActionForm(string $csrf, string $name, string $label, string $btnClass, ?string $hideClass, ?string $confirm = null, bool $typed = false): string
{
    $attrs = '';
    if ($confirm !== null) {
        $attrs .= ' data-rivet-confirm="' . rmmH($confirm) . '"';
    }
    if ($typed) {
        $attrs .= ' data-rmm-ds-typed-confirm="purge"';
    }

    return '<form method="post" action="post.php" class="d-inline ' . ($hideClass ?? '') . '"><input type="hidden" name="csrf_token" value="' . rmmH($csrf) . '"><input type="hidden" name="ea_tab" value="inventory">'
        . '<button type="submit" name="' . rmmH($name) . '" value="1" class="btn btn-sm ' . rmmH($btnClass) . '"' . $attrs . '>' . rmmH($label) . '</button></form>';
}

/** @param array<string,mixed> $m DataStoreSwitch::status()['migration'] (or RmmStoreMigrator::status()) */
function rivetRmmUiDsMigrationStatusHtml(array $m): string
{
    $phase = $m['phase'] ?? null;
    return '<div class="alert alert-' . ((string) $m['state'] === 'failed' ? 'danger' : 'info') . ' py-2 mb-0">' . rivetRmmDsRunLabel($m)
        . ($phase !== null ? ' &middot; phase: ' . rmmH((string) $phase) : '') . ' &middot; updated ' . rmmH(rivetRmmTime((string) $m['updated_at']))
        . ((int) ($m['dual_faults'] ?? 0) > 0 ? ' &middot; ' . (int) $m['dual_faults'] . ' dual-write fault(s)' : '')
        . (!empty($m['last_error']) ? '<div class="small mt-1">' . rmmH((string) $m['last_error']) . '</div>' : '') . '</div>';
}

// ------------------------------------------------------------------------------------------------------------------------ background jobs (migrate/rollback/purge)

/** Where the background migration job's combined stdout/stderr (one JSON object per line, docs/rmm/POSTGRES.md section 14) is appended. */
function rivetRmmDsJobLog(): string
{
    return dirname(__DIR__) . '/backups/rmm-store-migrate.log';
}

/** The CLI PHP binary to spawn a job with — the same resolution src/Recovery/RestoreDrill.php::spawn() already uses for its own detached CLI job. */
function rivetRmmDsPhpBinary(): ?string
{
    foreach ([PHP_BINDIR . '/php', '/usr/bin/php', '/usr/local/bin/php'] as $p) {
        if (is_executable($p)) {
            return $p;
        }
    }

    return null;
}

/**
 * Starts vendor/rivet/rivet-core/scripts/rmm-store-migrate.php DETACHED from this web request (docs/rmm/POSTGRES.md section 14's own warning: never
 * run the migration inside a web request). `setsid` gives the job its own session, so it is not killed by a signal sent to this web worker's
 * process group (e.g. `systemctl restart php-fpm`). Detachment itself comes from NEVER calling proc_close()/waiting on the returned resource —
 * verified empirically: proc_close() blocks until the child exits (minutes, for a real migration) even though setsid already gave it its own
 * session; letting the resource go out of scope instead does not wait and returns immediately, exactly like RestoreDrill::spawn() above relies on.
 *
 * @param list<string> $flags e.g. ['--to=postgres', '--dry-run'] or ['--rollback'] or ['--purge-old', '--yes']
 */
function rivetRmmDsLaunchJob(array $flags): bool
{
    $php = rivetRmmDsPhpBinary();
    if ($php === null || !function_exists('proc_open')) {
        return false;
    }
    $root = dirname(__DIR__);
    $log = rivetRmmDsJobLog();
    @touch($log);
    @chmod($log, 0640);
    // Literal argv array (tests/no_unsafe_shell.php): proc_open runs the program directly, no shell, regardless of what the spread flags contain.
    $proc = @proc_open(['setsid', $php, $root . '/vendor/rivet/rivet-core/scripts/rmm-store-migrate.php', '--bootstrap=' . $root . '/scripts/rmm_store_bootstrap.php', ...$flags],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $root);

    return is_resource($proc);   // deliberately never proc_close()'d / waited on - see the function comment
}

/** Last N lines of the job log, oldest first (for the page's progress panel). */
function rivetRmmDsJobLogTail(int $lines = 30): string
{
    $log = rivetRmmDsJobLog();
    if (!is_file($log)) {
        return '';
    }
    $all = @file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

    return implode("\n", array_slice($all, -$lines));
}
