<?php
defined('FROM_API') || die();

/**
 * Mirrors the web app's enforceUserPermission() for the token-based API context, where
 * $_SESSION isn't populated. Ends the request with a 403 if the signed-in API user's role
 * lacks at least $min_level access to $module_name. Admin roles always pass.
 */
function api_require_module_permission(mysqli $mysqli, int $api_user_id, string $module_name, int $min_level = 1): void {
    if (!api_module_permission_ok($mysqli, $api_user_id, $module_name, $min_level)) {
        api_error(403, 'Insufficient permissions');
    }
}

/**
 * Non-terminating form of api_require_module_permission(): true when the API user's role grants at least $min_level on $module_name
 * (admin roles always pass). MSP-DS-84: lets one endpoint answer per section instead of refusing the whole request.
 */
function api_module_permission_ok(mysqli $mysqli, int $api_user_id, string $module_name, int $min_level = 1): bool {
    $role = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT u.user_role_id, r.role_is_admin
         FROM users u LEFT JOIN user_roles r ON r.role_id = u.user_role_id
         WHERE u.user_id = $api_user_id LIMIT 1"
    ));
    if ($role && $role['role_is_admin']) return true;

    $module_esc = mysqli_real_escape_string($mysqli, $module_name);
    $has_perm = $role ? mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT urp.user_role_permission_level
         FROM user_role_permissions urp
         JOIN modules m ON m.module_id = urp.module_id
         WHERE urp.user_role_id = {$role['user_role_id']}
           AND m.module_name = '$module_esc'
           AND urp.user_role_permission_level >= $min_level
         LIMIT 1"
    )) : null;

    return (bool) $has_perm;
}

/**
 * Builds the client-scope SQL boolean expression for the current API caller,
 * for use in a list query's WHERE clause. Combines two independent
 * restrictions:
 *  - the resolved user's own user_client_permissions rows (fail-open to "all
 *    clients" when the user has none, matching enforceClientAccess()'s
 *    behavior for the classic web app)
 *  - if authenticated via a legacy API key scoped to one client
 *    ($api_key_client_id, set in index.php), that key's own restriction -
 *    previously never enforced by any endpoint regardless of what an admin
 *    picked in the key's "Client Access" setting.
 *
 * $client_id_column must be a trusted SQL column reference (e.g.
 * 't.ticket_client_id'), never raw user input.
 */
function api_client_scope_sql(string $client_id_column): string {
    global $mysqli, $api_user_id, $api_key_client_id;
    $uid = intval($api_user_id);
    $sql = "(
        NOT EXISTS (SELECT 1 FROM user_client_permissions WHERE user_id = $uid LIMIT 1)
        OR EXISTS (SELECT 1 FROM user_client_permissions ucp WHERE ucp.user_id = $uid AND ucp.client_id = $client_id_column LIMIT 1)
    )";
    if (!empty($api_key_client_id)) {
        $restrict = intval($api_key_client_id);
        $sql = "($sql AND $client_id_column = $restrict)";
    }
    return $sql;
}

/**
 * Same restriction as api_client_scope_sql(), but for a single already-known
 * client_id value rather than a SQL column reference - used by per-record
 * ownership checks (e.g. a ticket/worksheet/outtake resolved to one client_id)
 * rather than list-query WHERE clauses.
 */
function api_client_scope_ok(int $client_id): bool {
    global $mysqli, $api_user_id, $api_key_client_id;
    $uid = intval($api_user_id);
    if (!empty($api_key_client_id) && intval($api_key_client_id) !== $client_id) {
        return false;
    }
    return (bool) mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT 1 AS ok WHERE
            NOT EXISTS (SELECT 1 FROM user_client_permissions WHERE user_id = $uid LIMIT 1)
            OR EXISTS (SELECT 1 FROM user_client_permissions WHERE user_id = $uid AND client_id = $client_id LIMIT 1)"
    ));
}

// Decrypt a credential field using the token's stored master key
if (!function_exists('_api_decrypt')) {
function _api_decrypt(string $ciphertext, string $raw_token, array $token_row, $credential_id = null, string $field = 'password'): string {
    if (!$ciphertext) return '';
    // v3 field (credential vault v3): opened with the data key wrapped into this token at sign-in (api_tokens.token_enc_dek).
    if (\RivetMSP\Crypto\VaultV3::isV3Field($ciphertext)) {
        $dek = \RivetMSP\Crypto\VaultV3::unwrapFromToken($token_row['token_enc_dek'] ?? null, $raw_token);
        if ($dek === null || $credential_id === null) return '';
        $plain = \RivetMSP\Crypto\VaultV3::openField($ciphertext, $dek, $credential_id, $field);
        return $plain === false ? '' : $plain;
    }
    // MSP-DS-80: legacy OTP seeds are stored as 'enc:' . iv . ciphertext; an unprefixed value is a legacy plaintext seed.
    if ($field === 'otp') {
        if (!str_starts_with($ciphertext, 'enc:')) return $ciphertext;
        $ciphertext = substr($ciphertext, 4);
    }
    if (empty($token_row['token_enc_master_key'])) return '';
    $enc_key    = substr(hash('sha256', $raw_token . 'itflow_enc', true), 0, 16);
    $enc_iv     = hex2bin($token_row['token_enc_master_iv']);
    $master_key = openssl_decrypt($token_row['token_enc_master_key'], 'aes-128-cbc', $enc_key, 0, $enc_iv);
    if (!$master_key) return '';
    $cred_iv    = substr($ciphertext, 0, 16);
    $cred_ct    = substr($ciphertext, 16);
    return openssl_decrypt($cred_ct, 'aes-128-cbc', $master_key, 0, $cred_iv) ?: '';
}
}
