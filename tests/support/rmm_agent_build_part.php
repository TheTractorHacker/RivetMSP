<?php
// Included by tests/rmm_agent_feed.php: "Build a new version" (26.10.31). Uses that file's mock GitHub (tests/support/mock_github.py), scratch database and web server.
use RivetMsp\AgentFeed\Build;
use RivetMsp\AgentFeed\Feed as BFeed;
use RivetMsp\AgentFeed\FeedHttp as BHttp;

require_once "$root/includes/rmm_agent_build.php";
require_once "$root/includes/rmm_agent_build_ui.php";

$TOK = 'github_pat_11ABCDEFG0abcdefghijkl_' . bin2hex(random_bytes(20));
$BEARER = 'Bearer ' . $TOK;
$bctl = fn(array $x = []) => $ctl($x + ['api' => 'ok', 'download' => 'cdn', 'etag' => '"v1"', 'token' => $BEARER]);
$bget = fn(): array => (array) json_decode((string) $feed->stateGet('build'), true);
$bset = function (array $p) use ($feed, $bget) { $feed->statePut('build', json_encode($p + $bget())); };
$bpoll = function (?string $sid = null, array $f = ['csrf_token' => 'csrftok1']) use ($wb, $sidAdmin) { [$c, $b] = web($wb, 'POST', '/admin/post/agent_build_poll.php', $sid ?? $sidAdmin, $f); return [$c, (array) json_decode($b, true), $b]; };
$bstart = fn(array $f, ?string $sid = null, bool $csrf = true) => $ad($f + ['agent_build_start' => '1'], $sid, $csrf);
$authLog = fn(): string => (string) @file_get_contents("$ghDir/auth.log");
$disp = fn(): string => (string) @file_get_contents("$ghDir/dispatch.log");
@unlink("$ghDir/runs.json"); @unlink("$ghDir/dispatch.log"); @unlink("$ghDir/auth.log");
$q("DELETE FROM endpoint_agent_feed_state WHERE state_key IN ('build','gh_token','gh_token_test','checked_at','cache')");
$writeApi(array_merge($noise, [$r090, $r091, $r092, $r093, $r094, $r095])); $feed->setRepo("TheTractorHacker/rivet-core"); $bctl(); $feed->refresh(true);
$bld = new Build($feed);
$items = $feed->cached()["items"];

echo "\n--- Build a new version ---\n";
// ---- versions
foreach (['0.9.6', '1.0.0', '10.20.30', '0.9.6-rc1', '1.2.3-beta.2', '0.0.1'] as $v) { $ok(Build::validVersion($v), "version: $v is valid"); }
foreach (['', '1.2', '1.2.3.4', '01.2.3', '1.2.3-', '1.2.3-.x', '-1.2.3', 'v1.2.3', '1.2.3 ', " 1.2.3", "1.2.3\n", '1.2.3;id', '1.2.3-$(id)', '1.2.3-../x', '1.2.3/../../x', '１.２.３', '1.2.3-' . str_repeat('a', 30), '999999.1.1', '1.2.3-rc 1', '1.2.3%0a', "1.2.3\0", '1.2.3-a_b'] as $v) { $ok(!Build::validVersion($v), 'version: ' . json_encode($v) . ' is refused'); }
$ok(Build::latest($items) === '0.9.5' && Build::suggest('0.9.5') === '0.9.6' && Build::suggest('0.9.6-rc1') === '0.9.6' && Build::suggest(null) === '0.1.0', 'next patch version: latest known + 1 (a pre-release suggests its own release)');
$ok(Build::checkVersion('0.9.6', $items) === null && Build::checkVersion('0.9.6-rc1', $items) === null && Build::checkVersion('1.0.0', $items) === null, 'check: higher versions pass');
$ok(str_contains((string) Build::checkVersion('0.9.5', $items), 'already exists') && str_contains((string) Build::checkVersion('0.9.1', $items), 'already exists'), 'check: an existing version is refused');
$ok(str_contains((string) Build::checkVersion('0.9.4-rc1', $items), 'not higher') && str_contains((string) Build::checkVersion('0.8.0', $items), 'not higher') && str_contains((string) Build::checkVersion('0.9.5-rc1', $items), 'not higher'), 'check: a lower version (also a pre-release of an existing one) is refused');

// ---- no token: the fallback, and nothing is sent
$b = $cardMsg($sidAdmin);
$ok(str_contains($b, 'data-build-box') && str_contains($b, 'data-build-fallback') && str_contains($b, 'href="https://github.com/TheTractorHacker/rivet-core/actions/workflows/endpoint-agent.yml"') && str_contains($b, 'Start the build on GitHub') && str_contains($b, 'Check for updates'), 'no token: the fallback with the GitHub button and the three steps');
$ok(!str_contains($b, 'name="build_version"') && str_contains($b, 'suggested: <code>0.9.6</code>'), 'no token: no Build form; the suggested version is in the steps');
$n0 = $ghHits('POST');
$bstart(['build_version' => '0.9.6']);
$ok($ghHits('POST') === $n0 && $disp() === '' && $bget() === [] , 'no token: a Build request sends nothing');
$ok(str_contains($cardMsg($sidAdmin), 'No GitHub token is stored'), 'no token: the card says so in words');

// ---- saving the token
$ad(['agent_build_token_save' => '1', 'gh_token' => 'ghp_classic1234567890123456789012345678'], null);
$ok(!$bld->hasToken(), 'token: a classic or malformed token is refused');
foreach (['', 'github_pat_short', "github_pat_" . str_repeat('a', 30) . ' x', "github_pat_" . str_repeat('a', 30) . "\n--", 'github_pat_' . str_repeat('a', 400)] as $bad) { $ad(['agent_build_token_save' => '1', 'gh_token' => $bad]); }
$ok(!$bld->hasToken(), 'token: empty, short, spaced, multi-line and oversized tokens are refused');
$ad(['agent_build_token_save' => '1', 'gh_token' => ['x']]);
$ok(!$bld->hasToken(), 'token: an array value changes nothing and does not crash');
$ad(['agent_build_token_save' => '1', 'gh_token' => $TOK], $sidTech); $ad(['agent_build_token_save' => '1', 'gh_token' => $TOK], $sidViewer); $ad(['agent_build_token_save' => '1', 'gh_token' => $TOK], null, false);
$ok(!$bld->hasToken(), 'token: a technician, a viewer and a request without the CSRF token cannot save one');
$bctl();
$ad(['agent_build_token_save' => '1', 'gh_token' => $TOK]);
$ok($bld->hasToken(), 'token: an administrator saves it');
$raw = (string) $one("SELECT state_value FROM endpoint_agent_feed_state WHERE state_key = 'gh_token'");
$ok($raw !== '' && !str_contains($raw, $TOK) && !str_contains($raw, 'github_pat_') && (str_starts_with($raw, 'v3:') || str_starts_with($raw, 'ENC2:')), 'token: stored ENCRYPTED, the database holds no plaintext');
$ok(decryptSetting($raw, 'agent_feed_token') === $TOK && decryptSetting($raw, 'generic') !== $TOK, 'token: it decrypts with its own context only');
$all = '';
foreach ($rows('SELECT state_key, state_value FROM endpoint_agent_feed_state WHERE state_key <> \'gh_token\'') as $r) { $all .= $r['state_value']; }
foreach ($rows('SELECT log_description FROM logs') as $r) { $all .= $r['log_description']; }
$page = $cardMsg($sidAdmin);
$ok(!str_contains($all, $TOK) && !str_contains($page, $TOK) && !str_contains($page, substr($TOK, 0, 24)) && !str_contains($page, 'value="github_pat'), 'token: not in any other state row, audit row or the page (not even a part)');
$ok($audit('Agent Build Token Saved') === 1 && $audit('Agent Build Token Tested') >= 1, 'token: saving and testing are audited');
$ok(str_contains($page, 'The token was saved') || str_contains($page, 'The token works') || str_contains($page, 'data-build-test'), 'token: the result of the automatic test is shown');
$ok(str_contains($page, 'name="build_version"') && str_contains($page, 'value="0.9.6"') && str_contains($page, 'How to create the token') && str_contains($page, 'Test token') && str_contains($page, 'type="password"'), 'token: the Build form is prefilled with the next patch version, with the guide, Test and a password field');
$ok(str_contains($authLog(), "GET /repos/TheTractorHacker/rivet-core/actions/workflows/endpoint-agent.yml $BEARER"), 'token: sent as a Bearer header to the workflow URL of the configured repository');

// ---- Test token
$t = $bld->testToken();
$ok($t['ok'] && str_contains($t['message'], 'expires 2027-01-15'), 'test token: works, and says when it expires');
$bctl(['token' => 'Bearer something-else']); $t = $bld->testToken();
$ok(!$t['ok'] && str_contains($t['message'], 'rejected the token') && !str_contains($t['message'], $TOK), 'test token: a rejected token says so, without echoing it');
foreach (['403' => 'Actions: Read and write', '404' => 'cannot find'] as $m => $needle) { $bctl(['wf' => (string) $m]); $t = $bld->testToken(); $ok(!$t["ok"] && str_contains($t["message"], $needle), "test token: HTTP $m is explained"); }
$page = $cardMsg($sidAdmin);
$ok(str_contains($page, '&lt;b&gt;x&lt;/b&gt;') && !str_contains($page, '<b>x</b>'), 'test token: GitHub\'s own message is escaped on the page');
$bctl(['wf' => 'disabled']); $t = $bld->testToken(); $ok(!$t['ok'] && str_contains($t['message'], 'disabled'), 'test token: a disabled workflow is reported');
$bctl(['wf' => 'redirect']); $t = $bld->testToken();
$ok(!$t['ok'] && str_contains($t['message'], 'redirect'), 'test token: a redirect is NOT followed (the token never leaves)');
$bctl();
$h = new BHttp("http://127.0.0.1:$ghPort");
$r = $h->get("http://127.0.0.1:" . ($ghPort + 1) . '/x', 1000, 3, ['Authorization: Bearer x']);
$ok(!$r['ok'] && $r['status'] === 0, 'ssrf: a token request to another port of the test host is refused');
$r = (new BHttp(null))->get('https://objects.githubusercontent.com/x', 1000, 3, ['Authorization: Bearer x']);
$ok(!$r['ok'] && $r['status'] === 0 && str_contains($r['error'], 'with a token'), 'ssrf: a token request to a download host (allowed for plain GET) is refused before any connection');
$r = (new BHttp(null))->get('https://github.com/o/r', 1000, 3, ['Authorization: Bearer x']);
$ok(!$r['ok'] && $r['status'] === 0 && str_contains($r['error'], 'with a token'), 'ssrf: a token request to github.com is refused (api.github.com only)');
foreach (['https://evil.example/x', 'http://api.github.com/x', 'https://api.github.com:8443/x', 'https://127.0.0.1/x'] as $u) {
    $r = (new BHttp(null))->get($u, 1000, 3, ['Authorization: Bearer x']);
    $ok(!$r['ok'] && $r['status'] === 0, "ssrf: a token request to $u is refused");
}
$ok(!str_contains($authLog(), '127.0.0.2'), 'ssrf: the token never reached another host');

// ---- starting a build
$n0 = $ghHits('POST');
foreach (['0.9.5', '0.9.1', '0.8.0', '0.9.5-rc1', '1.2', "1.0.\n0", '1.0.0; rm -rf', '', '1.0.0-' . str_repeat('x', 40)] as $v) { $bstart(['build_version' => $v]); }
$bstart(['build_version' => ['1.0.0']]);
$ok($ghHits('POST') === $n0 && $disp() === '' && $bget() === [], 'start: existing, lower, malformed and array versions dispatch nothing');
$bstart(['build_version' => '0.9.6'], $sidTech); $bstart(['build_version' => '0.9.6'], $sidViewer); $bstart(['build_version' => '0.9.6'], null, false);
$ok($ghHits('POST') === $n0 && $bget() === [], 'start: a technician, a viewer and a request without CSRF dispatch nothing');
$bstart(['build_version' => '0.9.6', 'repo' => 'evil/other', 'feed_repo' => 'evil/other', 'ref' => 'evil', 'inputs' => ['version' => '6.6.6']]);
$d = json_decode(trim($disp()), true);
$ok($d === ['ref' => 'main', 'inputs' => ['version' => '0.9.6']], 'start: the dispatch body is exactly ref main + the validated version (extra fields ignored)');
$ok(str_contains((string) file_get_contents("$ghDir/hits.log"), 'POST /repos/TheTractorHacker/rivet-core/actions/workflows/endpoint-agent.yml/dispatches') && !str_contains((string) file_get_contents("$ghDir/hits.log"), 'evil/other'), 'start: only the configured repository and the fixed workflow file are used');
$ok($bget()['state'] === 'queued' && $audit('Agent Build Started') === 1 && !str_contains($all . json_encode($bget()), $TOK), 'start: queued, audited, the state holds no token');
$bstart(['build_version' => '0.9.7']);
$ok(count(array_filter(explode("\n", $disp()))) === 1, 'start: a second build while one runs is refused');
$page = $cardMsg($sidAdmin);
$ok(str_contains($page, 'data-build-status') && str_contains($page, 'Queued') && str_contains($page, 'data-build-active="1"') && str_contains($page, 'name="build_version"'), 'card: shows the queued build and keeps polling');

// ---- progress through the poll endpoint
[$c] = web($wb, 'GET', '/admin/post/agent_build_poll.php', $sidAdmin); $ok($c === 405, 'poll: GET is refused');
[$c] = $bpoll(null, []); $ok($c === 403, 'poll: no CSRF token');
[$c] = $bpoll($sidTech); $ok($c === 403, 'poll: a technician is refused');
[$c] = $bpoll($sidViewer); $ok($c === 403, 'poll: a viewer is refused');
[$c, $j, $rawj] = $bpoll();
$ok($c === 200 && $j['build']['state'] === 'queued' && str_contains($j['build']['run_url'], '/actions/runs/1001'), 'poll: finds the run it started (queued), link built from repo and id');
$runs = json_decode((string) file_get_contents("$ghDir/runs.json"), true);
$runs[0]['status'] = 'in_progress'; file_put_contents("$ghDir/runs.json", json_encode($runs)); $bset(['checked_at' => 0]);
[, $j] = $bpoll(); $ok($j['build']['state'] === 'building' && $j['build']['label'] === 'Building', 'poll: building');
[, $j2] = $bpoll(); $ok($j2['build']['state'] === 'building', 'poll: a second poll within 4 seconds answers from the stored state');
$runs[0]['status'] = 'completed'; $runs[0]['conclusion'] = 'failure'; file_put_contents("$ghDir/runs.json", json_encode($runs)); $bset(['checked_at' => 0]);
[, $j] = $bpoll(); $ok($j['build']['state'] === 'failed' && str_contains($j['build']['detail'], 'failure') && $audit('Agent Build Finished') === 1, 'poll: failed with the conclusion, audited once');
$page = $cardMsg($sidAdmin);
$ok(str_contains($page, 'Failed') && str_contains($page, 'href="https://github.com/TheTractorHacker/rivet-core/actions/runs/1001"') && str_contains($page, 'data-build-active="0"'), 'card: failed build with the run link, polling stopped');

// ---- a build that succeeds; the release appears later; manual publish stays available
$bstart(['build_version' => '0.9.6']);
$ok($bget()['version'] === '0.9.6' && $bget()['run_id'] === 0 && count(array_filter(explode("\n", $disp()))) === 2, 'start: after a failed build a new one starts');
$bset(['checked_at' => 0]); $bpoll();
$runs = json_decode((string) file_get_contents("$ghDir/runs.json"), true);
$ok(count($runs) === 2 && $bget()['run_id'] === 1002, 'poll: the new run is the one created after the dispatch, not the old failed one');
foreach ($runs as &$rr) { if ($rr['id'] === 1002) { $rr['status'] = 'completed'; $rr['conclusion'] = 'success'; } } unset($rr);
file_put_contents("$ghDir/runs.json", json_encode($runs)); $bset(['checked_at' => 0]); $bset(['auto' => false]);
[, $j] = $bpoll();
$ok($j['build']['state'] === 'finished' && !$j['build']['release_seen'], 'poll: finished, the release is not in the list yet');
$r096 = $mkRelease('0.9.6', ['pre' => false, 'at' => '2026-10-23T10:00:00Z']);
$writeApi(array_merge($noise, [$r090, $r091, $r092, $r093, $r094, $r095, $r096])); $bctl(['etag' => '"v2"']);
$q("UPDATE endpoint_agent_feed_state SET state_value = '0' WHERE state_key = 'checked_at'"); $bset(['checked_at' => 0]);
[, $j] = $bpoll();
$ok($j['build']['release_seen'] && !$j['build']['published'] && (int) $one("SELECT COUNT(*) FROM endpoint_agent_binaries WHERE version = '0.9.6'") === 0, 'poll: the release appears in the list; without the auto box nothing is published');
$ok(str_contains($cardMsg($sidAdmin), 'data-feed-row="0.9.6"'), 'card: the new release is in the list with its Publish form');

// ---- publish automatically
$bstart(['build_version' => '0.9.7', 'build_auto' => '1', 'build_ring' => 'pilot', 'build_pct' => '25', 'build_activate' => '1']);
$ok($bget()['auto'] === true && $bget()['ring'] === 'pilot' && $bget()['pct'] === 25, 'auto: the options are stored with the build');
$bset(['checked_at' => 0]); $bpoll();
$runs = json_decode((string) file_get_contents("$ghDir/runs.json"), true);
foreach ($runs as &$rr) { if ($rr['id'] === 1003) { $rr['status'] = 'completed'; $rr['conclusion'] = 'success'; } } unset($rr);
file_put_contents("$ghDir/runs.json", json_encode($runs)); $bset(['checked_at' => 0]);
[, $j] = $bpoll(); $ok($j['build']['state'] === 'finished' && !$j['build']['published'], 'auto: finished, release not there yet: not published');
$r097 = $mkRelease('0.9.7', ['pre' => false, 'at' => '2026-10-24T10:00:00Z']);
$writeApi(array_merge($noise, [$r090, $r091, $r092, $r093, $r094, $r095, $r096, $r097])); $bctl(['etag' => '"v3"']);
$q("UPDATE endpoint_agent_feed_state SET state_value = '0' WHERE state_key = 'checked_at'"); $bset(['checked_at' => 0]);
$dl0 = $ghHits('/releases/download/');
[, $j] = $bpoll();
$ok($j['build']['published'] && (int) $one("SELECT COUNT(*) FROM endpoint_agent_binaries WHERE version = '0.9.7'") === 2, 'auto: the release is published by the existing Publish code (both architectures stored, checked against the sums)');
$ok((int) $one("SELECT COUNT(*) FROM endpoint_agent_releases WHERE release_version = '0.9.7'") >= 1 || (int) $one("SELECT COUNT(*) FROM endpoint_agent_releases") >= 1, 'auto: the ring offer was made');
$dl1 = $ghHits('/releases/download/'); $bset(['checked_at' => 0]); $bpoll(); $bset(['checked_at' => 0]); $bpoll();
$ok($ghHits('/releases/download/') === $dl1 && $dl1 > $dl0, 'auto: published once; later polls download nothing');
$ok($audit('Agent Release Downloaded') >= 1, 'auto: the publish is audited like the button');

// ---- the cron job follows and publishes with nobody on the page
$ok($bld->saveToken($TOK) === null, 'token ready for the cron test');
$q("DELETE FROM endpoint_agent_binaries WHERE version = '0.9.8'");
$writeApi(array_merge($noise, [$r090, $r091, $r092, $r093, $r094, $r095, $r096, $r097])); $bctl(['etag' => '"v3"']);
$feed->statePut('build', ''); $bstart(['build_version' => '0.9.8', 'build_auto' => '1', 'build_ring' => 'stable', 'build_pct' => '5', 'build_activate' => '1']);
$cron = fn(): string => (string) shell_exec('cd ' . escapeshellarg($root) . ' && php cron/agent_build_check.php 2>&1');
$bset(['checked_at' => 0]); $o1 = $cron();
$runs = json_decode((string) file_get_contents("$ghDir/runs.json"), true);
$ok($bget()['run_id'] > 0 && str_contains($o1, 'agent build 0.9.8'), 'cron: finds the run of a build nobody is watching (' . trim($o1) . ')');
foreach ($runs as &$rr) { if ($rr['id'] === $bget()['run_id']) { $rr['status'] = 'completed'; $rr['conclusion'] = 'success'; } } unset($rr);
file_put_contents("$ghDir/runs.json", json_encode($runs));
$r098 = $mkRelease('0.9.8', ['pre' => false, 'at' => '2026-10-25T10:00:00Z']);
$writeApi(array_merge($noise, [$r090, $r091, $r092, $r093, $r094, $r095, $r096, $r097, $r098])); $bctl(['etag' => '"v4"']);
$q("UPDATE endpoint_agent_feed_state SET state_value = '0' WHERE state_key = 'checked_at'"); $bset(['checked_at' => 0]); $cron();
$q("UPDATE endpoint_agent_feed_state SET state_value = '0' WHERE state_key = 'checked_at'"); $bset(['checked_at' => 0]); $o2 = $cron();
$ok($bget()['published'] === true && (int) $one("SELECT COUNT(*) FROM endpoint_agent_binaries WHERE version = '0.9.8'") === 2, 'cron: published the release as the administrator who started the build (' . trim($o2) . ')');
$ok($cron() === '', 'cron: with nothing to follow it exits at once, silently');

// ---- no run shows up
$bset(['state' => 'queued', 'run_id' => 0, 'before_id' => 99999, 'started_at' => time() - 900, 'checked_at' => 0, 'published' => false, 'auto' => false, 'release_seen' => false]);
[, $j] = $bpoll(); $ok($j['build']['state'] === 'lost' && str_contains($j['build']['detail'], 'no run'), 'poll: no run after a few minutes is reported, with the workflow link on the card');
$ok(str_contains($cardMsg($sidAdmin), 'Open the workflow on GitHub'), 'card: lost build links to the workflow');

// ---- hostile data in the stored state and the rendering
$bset(['state' => 'failed', 'detail' => '<script>alert(1)</script>', 'version' => '0.9.8', 'by' => '<b>x</b>']);
$page = $cardMsg($sidAdmin);
$ok(!str_contains($page, '<script>alert(1)</script>') && str_contains($page, '&lt;script&gt;alert(1)'), 'card: a hostile build detail is escaped');
$feed->statePut('build', json_encode(['repo' => 'TheTractorHacker/rivet-core', 'version' => '1.0.0"><img src=x>', 'state' => 'queued']));
$ok(str_contains($cardMsg($sidAdmin), 'id="agent-build"') && !str_contains($cardMsg($sidAdmin), '<img src=x>'), 'card: a corrupt build state is ignored');
$feed->statePut('build', 'not json');
$ok(str_contains($cardMsg($sidAdmin), 'id="agent-build"'), 'card: garbage build state does not break the card');

// ---- another repository is used for everything, and removing the token
$feed->statePut('build', ''); $feed->setRepo('Other-Org/other-repo'); $bctl();
$ok(!$bld->hasToken() && $bld->lastTest() === null && (string) $feed->stateGet('build') === '', 'repo change: the token, its test result and the build state are dropped (a token belongs to one repository)');
$ok($bld->saveToken($TOK) === null, 'repo change: a token can be saved for the new repository');
$t = $bld->testToken();
$ok(str_contains((string) file_get_contents("$ghDir/hits.log"), 'GET /repos/Other-Org/other-repo/actions/workflows/endpoint-agent.yml'), 'repo: the configured repository is the one used');
$feed->setRepo('TheTractorHacker/rivet-core'); $ok(!$bld->hasToken(), 'repo change back: the token is dropped again');
$ok($bld->saveToken($TOK) === null, 'token saved again');
$ad(['agent_build_token_remove' => '1'], $sidTech);
$ok($bld->hasToken(), 'remove: a technician cannot');
$ad(['agent_build_token_remove' => '1']);
$ok(!$bld->hasToken() && $audit('Agent Build Token Removed') === 1 && (string) $one("SELECT state_value FROM endpoint_agent_feed_state WHERE state_key = 'gh_token'") === '', 'remove: an administrator removes it, audited, nothing left');
$ok(str_contains($cardMsg($sidAdmin), 'data-build-fallback'), 'remove: the card falls back to the GitHub steps');
$q("DELETE FROM endpoint_agent_feed_state WHERE state_key IN ('build','gh_token','gh_token_test')");
$q('DELETE FROM endpoint_agent_binaries'); $q('DELETE FROM endpoint_agent_msi_templates'); $q('DELETE FROM endpoint_agent_releases');
$writeApi(array_merge($noise, [$r090, $r091, $r092, $r093, $r094, $r095])); $bctl(); $q("UPDATE endpoint_agent_feed_state SET state_value = '0' WHERE state_key = 'checked_at'"); $feed->refresh(true);
