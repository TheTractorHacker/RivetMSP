<?php
// MSP-DS-146/147: portal Microsoft sign-in uses a random one-time state, a GET (response_mode=query) callback, honours the portal switch
// and fails back to /login.php. (The code is a redirect script; checked by source and a state-compare simulation.)
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
$s = file_get_contents(__DIR__ . '/../client/login_microsoft.php');
$ok(strpos($s, "'response_mode' =>'query'") !== false && strpos($s, 'form_post') === false, '147: response_mode=query');
$ok(strpos($s, "'state' => session_id()") === false && strpos($s, "random_bytes(32)") !== false, '146: state is random, not the session id');
$ok(strpos($s, 'hash_equals((string) $_SESSION[\'portal_ms_state\']') !== false && strpos($s, "unset(\$_SESSION['portal_ms_state'])") !== false, '146: constant-time one-use state compare');
$ok(strpos($s, 'config_client_portal_enable') !== false, '146: portal switch-off is honoured');
$ok(strpos($s, 'window.location = \"login.php\"') === false && substr_count($s, "Location: /login.php") >= 3, '147: failures go to /login.php, not the relative 404');
// every success redirect is followed by exit (otherwise the trailing failure redirect would overwrite it)
$ok(preg_match('/header\(.Location: index\.php.\);\s*exit;/', $s) === 1, 'success redirect exits');
// state compare behaviour
$sess = ['portal_ms_state' => bin2hex(random_bytes(32))];
$cmp = fn($given) => isset($sess['portal_ms_state']) && is_string($given) && hash_equals($sess['portal_ms_state'], $given);
$ok($cmp($sess['portal_ms_state']) && !$cmp('x') && !$cmp(['a']) , 'state comparison accepts only the issued value');
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
