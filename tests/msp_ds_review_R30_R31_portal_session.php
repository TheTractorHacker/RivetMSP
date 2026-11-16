<?php
// Review R30 (portal session idle 8h / absolute 24h) and R31 (portal_pw_fp cleared at every portal sign-in). Pure functions + source wiring, no DB.
// Run: php tests/msp_ds_review_R30_R31_portal_session.php
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$root = dirname(__DIR__);
require_once $root . '/includes/security_sessions.php';
$t0 = 1_800_000_000; $H = 3600;
$s = []; secPortalSessionBegin($s, $t0);
$ok('fresh session is alive and the clocks are set', secPortalSessionAlive($s, $t0 + 60) && $s['portal_started_at'] === $t0);
$s = []; secPortalSessionBegin($s, $t0);
$ok('idle for 7h59m is still alive', secPortalSessionAlive($s, $t0 + 8 * $H - 60));
$s = []; secPortalSessionBegin($s, $t0);
$ok('idle for 8h01m is ended', !secPortalSessionAlive($s, $t0 + 8 * $H + 60));
// activity extends idle but never the absolute limit
$s = []; secPortalSessionBegin($s, $t0);
$alive = true; for ($h = 1; $h <= 23; $h++) { $alive = $alive && secPortalSessionAlive($s, $t0 + $h * $H); }
$ok('a user active every hour stays signed in for 23h', $alive);
$ok('... but is ended after 24h in total even though active a minute ago', secPortalSessionAlive($s, $t0 + 24 * $H - 60) && !secPortalSessionAlive($s, $t0 + 24 * $H + 60));
// background polls (SSE / keepalive) must not keep it alive
$s = []; secPortalSessionBegin($s, $t0);
$bgAlive = true; for ($h = 1; $h <= 7; $h++) { $bgAlive = $bgAlive && secPortalSessionAlive($s, $t0 + $h * $H, true); }
$ok('background polls do not extend the idle timer', $bgAlive && !secPortalSessionAlive($s, $t0 + 8 * $H + 60, true));
// a session from before this check starts its clocks on the first request instead of being killed
$s = ['client_logged_in' => true]; $ok('a pre-existing session (no clocks) starts them on its first request', secPortalSessionAlive($s, $t0) && $s['portal_started_at'] === $t0);
// R31
$s = ['portal_pw_fp' => hash('sha256', 'contact A hash'), 'client_id' => 1]; secPortalSessionBegin($s, $t0);
$ok('R31: begin() drops the previous sign-in\'s password fingerprint', !array_key_exists('portal_pw_fp', $s));
// wiring
$cl = file_get_contents("$root/client/includes/check_login.php");
$ok('check_login enforces secPortalSessionAlive and ends the session', str_contains($cl, 'secPortalSessionAlive($_SESSION') && strpos($cl, 'secPortalSessionAlive') < strpos($cl, 'portal_pw_fp'));
$ok('check_login treats SSE/_bg requests as background', str_contains($cl, 'text/event-stream') && str_contains($cl, "'_bg'"));
foreach (['login.php', 'client/login_microsoft.php', 'client/login_oidc.php'] as $f) {
    $src = file_get_contents("$root/$f");
    $i = strpos($src, "\$_SESSION['client_logged_in'] = true;"); $r = strrpos(substr($src, 0, $i), 'session_regenerate_id(true)');
    $b = strpos($src, 'secPortalSessionBegin($_SESSION)');
    $ok("$f: a portal sign-in begins the portal session after session_regenerate_id", $r !== false && $b !== false && $b > $r && str_contains($src, 'security_sessions.php'));
}
exit($fail ? 1 : 0);
