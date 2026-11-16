<?php
// MSP-DS-29: KB list is scoped to global + own clients for restricted agents; the TinyMCE upload is Write-level and same-origin only. Run: php tests/msp_ds_29_kb_list_scope.php
require dirname(__DIR__) . '/includes/search_scope.php';
require dirname(__DIR__) . '/includes/request_origin.php';
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$ok('restricted: global + own clients', searchClientScope('4,7', false, 'kb_article_client_id', true) === 'AND (kb_article_client_id = 0 OR kb_article_client_id IN (4,7))');
$ok('restricted without global keeps plain IN', searchClientScope('4', false, 'c') === 'AND c IN (4)');
$ok('admin unscoped', searchClientScope('4,7', true, 'kb_article_client_id', true) === '');
$ok('unrestricted unscoped', searchClientScope('', false, 'kb_article_client_id', true) === '');
$ok('same-origin fetch metadata accepted', requestIsSameOrigin(['HTTP_SEC_FETCH_SITE' => 'same-origin']));
$ok('cross-site fetch metadata refused', !requestIsSameOrigin(['HTTP_SEC_FETCH_SITE' => 'cross-site']) && !requestIsSameOrigin(['HTTP_SEC_FETCH_SITE' => 'same-site']));
$ok('foreign Origin refused', !requestIsSameOrigin(['HTTP_ORIGIN' => 'https://evil.example', 'HTTP_HOST' => 'msp.example']));
$ok('matching Origin accepted', requestIsSameOrigin(['HTTP_ORIGIN' => 'https://msp.example', 'HTTP_HOST' => 'msp.example']));
$ok('Origin with port matches Host with port', requestIsSameOrigin(['HTTP_ORIGIN' => 'https://msp.example:8443', 'HTTP_HOST' => 'msp.example:8443']));
$ok('Origin "null" refused', !requestIsSameOrigin(['HTTP_ORIGIN' => 'null', 'HTTP_HOST' => 'msp.example']));
$ok('no browser headers at all: not a cross-site browser request', requestIsSameOrigin(['HTTP_HOST' => 'msp.example']));
$root = dirname(__DIR__);
$k = file_get_contents("$root/agent/kb_articles.php");
$ok('kb_articles applies the scope to the list query', strpos($k, "searchClientScope((string) \$client_access_string, (bool) \$session_is_admin, 'kb_article_client_id', true)") !== false && strpos($k, '$kb_scope_query .=') < strpos($k, 'SELECT SQL_CALC_FOUND_ROWS'));
$u = file_get_contents("$root/agent/kb_article_upload.php");
$ok('upload needs module_kb Write', strpos($u, "enforceUserPermission('module_kb', 2)") !== false);
$ok('upload checks same origin before anything is written', strpos($u, 'requestIsSameOrigin($_SERVER)') < strpos($u, 'move_uploaded_file'));
exit($fail ? 1 : 0);
