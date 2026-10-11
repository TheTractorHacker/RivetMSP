<?php

declare(strict_types=1);

namespace RivetMSP\Crypto;

/**
 * Every column that holds a value written by encryptSetting() (or the TOTP seed form), as one list for the rewrap tool, the Keys panel
 * and the tests. Adding a column that stores encryptSetting() output means adding it here, or a key rotation will not reach it.
 *
 * A column the install does not have (an older schema, a module that was never set up) is skipped at run time, not an error.
 * The vault's own columns (credentials, shares, mobile tokens) are NOT here: see {@see VaultV3}.
 */
final class ColumnRegistry
{
    /**
     * "table.column" => true for every column whose readers ALL go through decryptSetting() (includes/security_crypto.php secStragglerColumns(),
     * the list DB update 2.6.79 and the lazy re-wrap use): a cleartext value in one of them is wrapped by a rewrap. A column in
     * secDeferredColumns() (a raw reader still exists) is registered but its cleartext is left alone.
     *
     * @return array<string,true>
     */
    private static function wrappable(): array
    {
        if (!function_exists('secStragglerColumns')) {
            require_once dirname(__DIR__, 2) . '/includes/security_crypto.php';
        }
        $set = [];
        foreach (secStragglerColumns() as $table => [$pk, $cols]) {
            foreach ($cols as $c) {
                $set[$table . '.' . $c] = true;
            }
        }

        return $set;
    }

    /** @return list<ColumnSpec> */
    public static function all(): array
    {
        $w = self::wrappable();
        $wrap = static fn (string $t, string $c): bool => isset($w[$t . '.' . $c]);
        $specs = [];
        // settings (single row, company_id = 1).
        foreach ([
            'config_login_key_secret', 'config_whitelabel_key', 'config_smtp_password', 'config_azure_client_secret',
            'config_mail_oauth_client_secret', 'config_mail_oauth_refresh_token', 'config_mail_oauth_access_token',
            'config_outlook_cal_client_secret', 'config_imap_password', 'config_backup_s3_secret_key', 'config_backup_passphrase',
            'config_comet_admin_pass', 'config_comet_totp_secret', 'config_comet_webhook_secret', 'config_redis_password',
        ] as $c) {
            $specs[] = new ColumnSpec('settings', 'company_id', $c, 'settings', $wrap('settings', $c));
        }
        $specs[] = new ColumnSpec('settings', 'company_id', 'config_vault_canonical_key', 'canonical', $wrap('settings', 'config_vault_canonical_key'));

        foreach ([
            ['webhooks', 'webhook_id', ['webhook_url', 'webhook_secret', 'webhook_auth_enc']],
            ['payment_providers', 'payment_provider_id', ['payment_provider_private_key', 'payment_provider_webhook_secret']],
            ['ai_providers', 'ai_provider_id', ['ai_provider_api_key']],
            ['rmm_integrations', 'id', ['api_key_enc']],
            ['unifi_integrations', 'id', ['api_key_enc']],
            ['microsoft_integrations', 'microsoft_integration_id', ['client_secret_enc']],
            ['accounting_integrations', 'accounting_id', ['accounting_client_secret', 'accounting_access_token', 'accounting_refresh_token']],
            ['mailboxes', 'mailbox_id', ['mailbox_imap_password_enc', 'mailbox_oauth_refresh_token_enc', 'mailbox_oauth_access_token_enc']],
            // The RMM module seals these through EndpointSecretBox (encryptSetting, generic context): the instance signing key and the
            // Mesh login key, and (Phase 2) the secret parameters of a queued job and the values of secret custom fields. A cleartext value
            // there is never a ciphertext the module would accept, so a rewrap never wraps one.
            ['endpoint_agent_settings', 'id', ['signing_private_key_enc', 'mesh_login_key_enc']],
        ] as [$table, $pk, $cols]) {
            foreach ($cols as $c) {
                $specs[] = new ColumnSpec($table, $pk, $c, 'settings', $wrap($table, $c));
            }
        }
        $specs[] = new ColumnSpec('rmm_job_extra', 'job_id', 'secret_params_enc', 'settings', false, false);
        $specs[] = new ColumnSpec('rmm_custom_field_values', 'field_id', 'value_enc', 'settings', false, true, null, 'scope_id');
        // The license keys were cleartext on old installs; every reader goes through decryptSetting().
        $specs[] = new ColumnSpec('software', 'software_id', 'software_key', 'settings', $wrap('software', 'software_key'));
        $specs[] = new ColumnSpec('software_keys', 'software_key_id', 'software_key', 'settings', $wrap('software_keys', 'software_key'));
        $specs[] = new ColumnSpec('recovery_settings', 'setting_key', 'setting_value', 'settings', false, false);
        // TOTP seeds: bound to the user, totp purpose. Cleartext seeds are wrapped.
        $specs[] = new ColumnSpec('users', 'user_id', 'user_token', 'totp', $wrap('users', 'user_token'));

        return $specs;
    }

    /**
     * recovery_settings holds many unrelated keys in one value column; only 'drill_db_pass' is a secret, so that source is filtered
     * (see {@see MysqliRewrapSource}).
     */
    public static function rowFilter(ColumnSpec $spec): ?string
    {
        return $spec->table === 'recovery_settings' ? "`setting_key` = 'drill_db_pass'" : null;
    }

    /** @return array<string, ColumnSpec> name => spec */
    public static function byName(): array
    {
        $out = [];
        foreach (self::all() as $s) {
            $out[$s->name()] = $s;
        }

        return $out;
    }
}
