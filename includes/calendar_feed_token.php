<?php
// MSP-DS-49: per-user calendar feed token. The token is 256 random bits; only its sha256 is stored (users.user_calendar_token_hash), so a database
// read cannot be turned into a working feed URL, and Regenerate replaces it (the old link stops working). Before this change the token was
// HMAC(user_id, installation_id); those links keep working until their owner generates a new link or users.user_calendar_legacy_until passes.

/** Create (or replace) the user's feed token. Returns the plain token, which is shown once and never stored. */
function calendarFeedTokenRotate(mysqli $mysqli, int $user_id): string
{
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    mysqli_query($mysqli, "UPDATE users SET user_calendar_token_hash = '$hash', user_calendar_token_rotated_at = NOW(), user_calendar_legacy_until = NULL WHERE user_id = $user_id AND user_type = 1");

    return $token;
}

/** Returns [user_id, user_name] of the active staff user the token belongs to, or null. */
function calendarFeedTokenResolve(mysqli $mysqli, string $token, string $installation_id): ?array
{
    if (strlen($token) < 32) {
        return null;
    }
    $hash = hash('sha256', $token);
    $res = mysqli_query($mysqli, "SELECT user_id, user_name, user_calendar_token_hash FROM users
        WHERE user_calendar_token_hash = '$hash' AND user_status = 1 AND user_archived_at IS NULL AND user_type = 1 LIMIT 1");
    $u = $res ? mysqli_fetch_assoc($res) : null;
    if ($u && hash_equals((string) $u['user_calendar_token_hash'], $hash)) {
        return ['user_id' => (int) $u['user_id'], 'user_name' => (string) $u['user_name']];
    }

    // Legacy HMAC links, only for users who have not rotated and whose grace period has not passed.
    $res = mysqli_query($mysqli, "SELECT user_id, user_name FROM users
        WHERE user_status = 1 AND user_archived_at IS NULL AND user_type = 1 AND user_calendar_legacy_until IS NOT NULL AND user_calendar_legacy_until > NOW()");
    while ($res && ($u = mysqli_fetch_assoc($res))) {
        if (hash_equals(hash_hmac('sha256', (string) intval($u['user_id']), $installation_id), $token)) {
            return ['user_id' => (int) $u['user_id'], 'user_name' => (string) $u['user_name']];
        }
    }

    return null;
}
