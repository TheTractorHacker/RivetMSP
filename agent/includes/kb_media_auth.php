<?php
/**
 * Authorization primitive for agent/kb_media.php and agent/kb_embed.php.
 *
 * WHY THIS IS A RE-IMPLEMENTATION RATHER THAN A CALL. The rule below already
 * exists as functions.php::enforceClientAccess() - but that function answers a
 * permission denial by flash_alert() + redirect('clients.php'), which is the
 * right answer for an HTML page and the wrong answer for a binary <img> fetch or
 * an iframe `src`. A 302 to an HTML login/alert page in response to an image
 * request just renders as a broken image - which is an acceptable *side effect*
 * for "you are not logged in" (the session check upstream already relies on
 * exactly that), but is the WRONG primary mechanism for "you are logged in, but
 * this article belongs to a department you cannot see": that case must produce a
 * clean 403/404 from the media endpoint itself, not a redirect loop.
 *
 * So this is a bool-returning twin, kept deliberately in lock-step with
 * enforceClientAccess() (functions.php). If that rule ever changes, change this
 * one with it.
 */

defined('FROM_KB_MEDIA') || defined('FROM_KB_EMBED') || die("Direct file access is not allowed");

/**
 * Same rule as enforceClientAccess(), restated as a bool:
 *   - client_id <= 0 is company-wide/global and always readable (the caller is
 *     responsible for only calling this with client_id > 0, same convention
 *     every other call site in this codebase already uses).
 *   - an admin role bypasses everything.
 *   - a user with ZERO user_client_permissions rows is allowed ALL departments.
 *     That is a fail-OPEN default and it looks wrong in isolation, but it is the
 *     app-wide meaning of "no restriction configured" (functions.php's own
 *     enforceClientAccess() applies the identical default), and being stricter
 *     than the page that links to this endpoint would break working installs
 *     while fixing nothing.
 *   - otherwise the (user, client) row must exist.
 */
function kbMediaClientAccessOk(int $user_id, int $client_id): bool
{
    global $mysqli, $session_is_admin;

    if ($client_id <= 0) {
        return true;
    }

    if ($user_id < 1) {
        return false;
    }

    if (isset($session_is_admin) && $session_is_admin === true) {
        return true;
    }

    $user_id = (int) $user_id;

    $has_any_restriction = mysqli_fetch_assoc(mysqli_query(
        $mysqli,
        "SELECT 1 FROM user_client_permissions WHERE user_id = $user_id LIMIT 1"
    ));

    if (!$has_any_restriction) {
        return true;
    }

    return (bool) mysqli_fetch_assoc(mysqli_query(
        $mysqli,
        "SELECT 1 FROM user_client_permissions WHERE user_id = $user_id AND client_id = $client_id LIMIT 1"
    ));
}
