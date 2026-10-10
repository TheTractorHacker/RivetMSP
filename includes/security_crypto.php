<?php

/*
 * Wave 1 security - encryption of secrets that used to be stored in plaintext.
 *
 * The cipher is encryptSetting() / decryptSetting() in functions.php (ENC2: AES-256-GCM, ENC: legacy CBC, unprefixed legacy plaintext
 * that stays readable). This file adds what the stragglers need:
 *   - secIsWrapped()         does a stored value already carry an ENC:/ENC2: prefix
 *   - secWrapIfPlain()       wrap a value for writing; never double-wraps
 *   - secRewrapColumn()      wrap every plaintext row of one column (used by DB update 2.6.78, update_cli --rewrap_secrets and the lazy re-wrap)
 *   - secRewrapAll()         wrap every column in secRewrapTargets() (the whole list), idempotent
 *   - secKeyMissingNotice()  the text of the admin banner shown while $config_settings_enc_key is missing
 *   - secLazyRewrapSettings  on a settings read: wrap the columns below that are still plaintext (a writer that has not been updated yet,
 *                            such as a cron job that stores a refreshed OAuth token, may still write plaintext)
 *   - secUserTotpSecret()    read a users.user_token value in either form
 *   - secUserTotpStore()     the value to write to users.user_token
 *
 * Nothing here ever wraps when $config_settings_enc_key is empty: encryptSetting() would throw, and a migration must not
 * "encrypt" with no key. Callers treat the empty-key case as "leave the row as it is".
 */

if (!function_exists('secIsWrapped')) {

    /** True when the stored value already carries one of the encryptSetting() prefixes. */
    function secIsWrapped(?string $stored): bool
    {
        return $stored !== null && (str_starts_with($stored, 'ENC2:') || str_starts_with($stored, 'ENC:'));
    }

    /** True when a settings key is configured, so wrapping is possible. */
    function secSettingsKeyAvailable(): bool
    {
        return !empty($GLOBALS['config_settings_enc_key']);
    }

    /**
     * The value to store for a secret: '' stays '', an already wrapped value is returned unchanged, anything else is wrapped.
     * Throws (like encryptSetting) when there is no settings key.
     */
    function secWrapIfPlain(string $value): string
    {
        if ($value === '' || secIsWrapped($value)) {
            return $value;
        }

        return encryptSetting($value);
    }

    /**
     * The secret columns that were written in plaintext on an install that never had $config_settings_enc_key (every MSP install until now),
     * as table => [primary key, [columns]]. One list for the migration, the CLI and the lazy re-wrap, so they cannot drift apart.
     * Every reader of every column below goes through decryptSetting(); a column whose reader still reads the raw value belongs in
     * secDeferredColumns() instead.
     *
     * @return array<string, array{0:string, 1:string[]}>
     */
    function secStragglerColumns(): array
    {
        return [
            'settings'      => ['company_id', [
                'config_vault_canonical_key',
                'config_imap_password',
                'config_smtp_password',
                'config_azure_client_secret',
                'config_outlook_cal_client_secret',
                'config_comet_admin_pass',
                'config_comet_totp_secret',
                'config_comet_webhook_secret',
                'config_backup_s3_secret_key',
                'config_redis_password',
                'config_login_key_secret',
                'config_whitelabel_key',
            ]],
            'payment_providers'       => ['payment_provider_id', ['payment_provider_private_key', 'payment_provider_webhook_secret']],
            'ai_providers'            => ['ai_provider_id', ['ai_provider_api_key']],
            'webhooks'                => ['webhook_id', ['webhook_secret']],
            'rmm_integrations'        => ['id', ['api_key_enc']],
            'unifi_integrations'      => ['id', ['api_key_enc']],
            'microsoft_integrations'  => ['microsoft_integration_id', ['client_secret_enc']],
            'mailboxes'               => ['mailbox_id', ['mailbox_imap_password_enc', 'mailbox_oauth_refresh_token_enc', 'mailbox_oauth_access_token_enc']],
            'accounting_integrations' => ['accounting_id', ['accounting_client_secret', 'accounting_refresh_token', 'accounting_access_token']],
            'software'      => ['software_id', ['software_key']],
            'software_keys' => ['software_key_id', ['software_key']],
            'users'         => ['user_id', ['user_token']],
        ];
    }

    /**
     * Columns that are written by encryptSetting() on a save but are NOT re-wrapped yet, because cron/mail_queue.php (the RivetMSP mail
     * queue) still reads them from the row without decryptSetting(). Wrapping them now would break outgoing mail over OAuth. The mail
     * intake work moves that reader to decryptSetting(); this list then folds into secStragglerColumns(). Until then they stay
     * legacy plaintext, which decryptSetting() reads transparently (a new save already wraps them).
     *
     * @return array<string, array{0:string, 1:string[]}>
     */
    function secDeferredColumns(): array
    {
        return [
            'settings' => ['company_id', [
                'config_mail_oauth_client_secret',
                'config_mail_oauth_refresh_token',
                'config_mail_oauth_access_token',
            ]],
        ];
    }

    /** The whole list (what the migration and secRewrapAll walk). */
    function secRewrapTargets(): array
    {
        return secStragglerColumns();
    }

    /**
     * Wrap every plaintext secret column that is not deferred. Idempotent. Never wraps without a key.
     *
     * @return array{wrapped:int, skipped_too_long:int, no_key:bool, columns:int}
     */
    function secRewrapAll(mysqli $mysqli): array
    {
        $out = ['wrapped' => 0, 'skipped_too_long' => 0, 'no_key' => !secSettingsKeyAvailable(), 'columns' => 0];
        if ($out['no_key']) {
            return $out;
        }
        foreach (secRewrapTargets() as $table => $spec) {
            foreach ($spec[1] as $col) {
                $r = secRewrapColumn($mysqli, $table, $spec[0], $col);
                if (!$r['missing']) {
                    $out['columns']++;
                }
                $out['wrapped'] += $r['wrapped'];
                $out['skipped_too_long'] += $r['skipped_too_long'];
            }
        }

        return $out;
    }

    /** Text of the administrator warning banner while the settings key is missing (null when the key is set). */
    function secKeyMissingNotice(): ?string
    {
        if (secSettingsKeyAvailable()) {
            return null;
        }

        return 'The settings encryption key is missing from config.php, so stored secrets (mail and API passwords, the credential vault key, TOTP seeds) '
            . 'are not encrypted and new ones cannot be saved. Add a line  $config_settings_enc_key = \'<64 hex characters>\';  to config.php '
            . '(generate the value with: php -r "echo bin2hex(random_bytes(32));"), keep it with your config.php backups, then run: php scripts/update_cli.php --rewrap_secrets';
    }

    /**
     * Wrap every plaintext, non-empty row of one column. Idempotent (a wrapped row is skipped). Skips, loudly, a value whose wrapped form
     * would not fit a varchar column, because MySQL would truncate it and destroy the secret.
     *
     * @return array{wrapped:int, skipped_too_long:int, missing:bool}
     */
    function secRewrapColumn(mysqli $mysqli, string $table, string $pk, string $col): array
    {
        $result = ['wrapped' => 0, 'skipped_too_long' => 0, 'missing' => false];
        if (!secSettingsKeyAvailable()) {
            return $result;
        }

        $lenRow = mysqli_fetch_assoc(mysqli_query(
            $mysqli,
            "SELECT CHARACTER_MAXIMUM_LENGTH AS max_len, DATA_TYPE AS dtype FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . mysqli_real_escape_string($mysqli, $table) . "'
               AND COLUMN_NAME = '" . mysqli_real_escape_string($mysqli, $col) . "'"
        ));
        if (!$lenRow) {
            $result['missing'] = true; // table or column not on this install

            return $result;
        }
        $isVarchar = in_array(strtolower((string) $lenRow['dtype']), ['varchar', 'char'], true);
        $maxLen    = $isVarchar ? (int) $lenRow['max_len'] : 0;

        $rows = mysqli_query(
            $mysqli,
            "SELECT `$pk` AS pk, `$col` AS val FROM `$table`
             WHERE `$col` IS NOT NULL AND `$col` <> '' AND `$col` NOT LIKE 'ENC:%' AND `$col` NOT LIKE 'ENC2:%'"
        );
        if (!$rows) {
            return $result;
        }

        while ($row = mysqli_fetch_assoc($rows)) {
            $wrapped = encryptSetting((string) $row['val']);
            if ($maxLen > 0 && strlen($wrapped) > $maxLen) {
                $result['skipped_too_long']++;
                if (function_exists('logApp')) {
                    logApp('Database', 'error', "Left $table.$col row {$row['pk']} in cleartext: the wrapped value needs " . strlen($wrapped) . " chars but the column holds $maxLen. Re-save this secret after widening the column.");
                }
                continue;
            }
            $esc = mysqli_real_escape_string($mysqli, $wrapped);
            mysqli_query($mysqli, "UPDATE `$table` SET `$col` = '$esc' WHERE `$pk` = " . (int) $row['pk'] . " AND `$col` = '" . mysqli_real_escape_string($mysqli, (string) $row['val']) . "'");
            $result['wrapped'] += max(0, mysqli_affected_rows($mysqli));
        }

        return $result;
    }

    /**
     * Lazy re-wrap of the straggler columns of the settings row just read ($row is that row, from SELECT * FROM settings).
     * Cheap: string prefix tests only; a database write happens only when a plaintext secret is found. Does nothing without a key.
     */
    function secLazyRewrapSettings(mysqli $mysqli, array $row): void
    {
        if (!secSettingsKeyAvailable()) {
            return;
        }
        $spec = secStragglerColumns()['settings'];
        $set  = [];
        foreach ($spec[1] as $col) {
            $v = $row[$col] ?? null;
            if (is_string($v) && $v !== '' && !secIsWrapped($v)) {
                $set[] = $col;
            }
        }
        if (!$set) {
            return;
        }
        // A value too long for its column is skipped by secRewrapColumn; do not retry it on every request, once an hour is enough.
        $marker = sys_get_temp_dir() . '/rivetit_rewrap_skip_' . md5((string) ($GLOBALS['database'] ?? 'db') . '|' . implode(',', $set));
        if (is_file($marker) && time() - (int) @filemtime($marker) < 3600) {
            return;
        }
        try {
            $skipped = 0;
            foreach ($set as $col) {
                $skipped += secRewrapColumn($mysqli, 'settings', $spec[0], $col)['skipped_too_long'];
            }
            if ($skipped > 0) {
                @touch($marker);
            }
        } catch (\Throwable $e) {
            // Never break a page load over a re-wrap; the next read tries again.
            error_log('settings lazy re-wrap failed: ' . $e->getMessage());
        }
    }

    /** users.user_token (the TOTP seed) in either stored form: wrapped or legacy plaintext. */
    function secUserTotpSecret(?string $stored): string
    {
        return $stored === null || $stored === '' ? '' : decryptSetting($stored);
    }

    /** The value to write to users.user_token for a new seed. Throws without a settings key (never silently plaintext). */
    function secUserTotpStore(string $secret): string
    {
        return encryptSetting($secret);
    }

    /** Lazily wrap one user's TOTP seed after a successful read of a legacy plaintext one. */
    function secUserTotpRewrap(mysqli $mysqli, int $userId, ?string $stored): void
    {
        if ($stored === null || $stored === '' || secIsWrapped($stored) || !secSettingsKeyAvailable()) {
            return;
        }
        try {
            $esc  = mysqli_real_escape_string($mysqli, encryptSetting($stored));
            $orig = mysqli_real_escape_string($mysqli, $stored);
            mysqli_query($mysqli, "UPDATE users SET user_token = '$esc' WHERE user_id = $userId AND user_token = '$orig'");
        } catch (\Throwable $e) {
            error_log('TOTP seed re-wrap failed: ' . $e->getMessage());
        }
    }
}
