<?php

declare(strict_types=1);

/*
 * The edition bootstrap file RivetCore\Rmm\Store\Cli\CliBootstrap::load() expects (docs/rmm/POSTGRES.md
 * section 14): the control database, the clock, the SecretBox (to unseal the PostgreSQL password the data
 * store setting stored) and the audit sink, so vendor/rivet/rivet-core/scripts/rmm-store-migrate.php and
 * rmm-store-verify.php can run against this install from the command line or a background job — never
 * inside a web request (POSTGRES.md section 14's own warning).
 *
 *   php vendor/rivet/rivet-core/scripts/rmm-store-migrate.php --bootstrap=scripts/rmm_store_bootstrap.php --from=mariadb --to=postgres --dry-run
 *   php vendor/rivet/rivet-core/scripts/rmm-store-verify.php  --bootstrap=scripts/rmm_store_bootstrap.php --json
 *
 * includes/rmm_data_store_ui.php's "Migrate data" background job runs the same script the same way (via
 * proc_open, detached from the web request). No PostgreSQL connection is passed here: the module and these
 * scripts read it from the connection RivetCore\Rmm\Store\DataStoreSwitch stored (Administration > RMM >
 * Data store), exactly as includes/rmm_bootstrap.php's rivetRmmModule() does for the web app.
 *
 * This file is `require`d, so it is code, not data — like config.php, which it requires, keep it readable
 * by the service account (www-data / the cron user) only.
 */

$appDir = dirname(__DIR__);
require $appDir . '/config.php';
require $appDir . '/functions.php';
require $appDir . '/includes/rmm_bootstrap.php';

use RivetMSP\Core\Adapter\Endpoint\EndpointAudit;
use RivetMSP\Core\Adapter\Endpoint\EndpointSecretBox;

if (!isset($mysqli) || !($mysqli instanceof \mysqli)) {
    throw new \RuntimeException('config.php did not produce a database connection.');
}

// RivetCore\Rmm\Store\Cli\CliBootstrap::load() requires this file from inside its own closure, so the variables config.php
// defines above (config_settings_enc_key, mysqli, session_*) are local to THAT closure, not true PHP globals. EndpointSecretBox
// (unseals the stored PostgreSQL password) and logAction() (the audit trail: `global $mysqli, $session_user_id, ...`) both read
// $GLOBALS directly, so without this they silently fail here even though the exact same config.php works for the web app and
// every other *_cli.php script (which require it at a real top level, never inside a closure) - found by a real end-to-end run
// of this bootstrap against a throwaway PostgreSQL container, not by inspection.
//
// config_keyfile, config_crypto_v3_settings and config_crypto_v3_totp belong in this list for the SAME reason and were found the
// SAME way (RivetMSP\Crypto\KeyStore::load() and SettingsCrypto::v3Enabled()/totpV3Enabled() read them straight from $GLOBALS,
// never a local variable): without exporting config_keyfile, an install whose config.php points $config_keyfile anywhere other
// than KeyStore::DEFAULT_PATH (/etc/rivetmsp/keys.json) has this CLI tooling silently read the WRONG key file - on a shared host
// where that default path happens to exist for an unrelated reason, decryption fails with "a v3 value could not be read
// (DecryptionFailed)" instead of the real one; on a host where it does not exist, the ring falls back to the legacy
// config_settings_enc_key cipher instead, which cannot open a v3:-sealed value at all. Either way the stored PostgreSQL password
// (or any other v3:-sealed secret) becomes unreadable by rmm-store-migrate.php / rmm-store-verify.php even though the exact same
// value reads back fine from the web app, which uses the real $config_keyfile at a true top-level scope.
foreach (['mysqli', 'config_settings_enc_key', 'config_keyfile', 'config_crypto_v3_settings', 'config_crypto_v3_totp', 'session_user_id', 'session_user_agent', 'session_ip', 'installation_id', 'config_base_url'] as $__g) {
    if (isset($$__g)) {
        $GLOBALS[$__g] = $$__g;
    }
}
$GLOBALS['session_user_id'] ??= 0;
$GLOBALS['session_ip'] ??= '127.0.0.1';
$GLOBALS['session_user_agent'] ??= 'rmm-store-migrate CLI';

return [
    'database' => rivetCoreDb($mysqli),
    'clock' => new \RivetCore\Support\SystemClock(),
    'secretBox' => new EndpointSecretBox(),
    'audit' => new EndpointAudit(),
];
