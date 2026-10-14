<?php
/*
 * Older address of the script library. The library is the Library tab of the Scripts page now (agent/rmm_scripts.php); this keeps bookmarks, links and the Android/docs references
 * working: it redirects there and keeps the query string (script_id, new, from, to, view, q, language, tag, retired, page).
 */

$qs = [];
parse_str((string) ($_SERVER['QUERY_STRING'] ?? ''), $qs);
unset($qs['tab']);
header('Location: /agent/rmm_scripts.php?' . http_build_query(['tab' => 'library'] + $qs), true, 302);
exit;
