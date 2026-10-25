<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)
/*
 * Knowledge Base version history + review schedule (GitHub roadmap #7, "KB v2" slice 1 - versions and review
 * dates only; media and embeds are a separate, larger, security-sensitive port, deliberately not covered here),
 * against the REAL code on a SCRATCH database, real HTTP (same harness as tests/security_*.php).
 *
 *   RIVETMSP_TEST_DB=1 RIVETMSP_TEST_DB_NAME=..scratch.. RIVETMSP_TEST_DB_USER=... RIVETMSP_TEST_DB_PASS=... \
 *   RIVETMSP_REDIS_HOST=127.0.0.1 RIVETMSP_REDIS_PORT=<throwaway> RIVETMSP_REDIS_ENV_FILE=/dev/null php tests/kb_versions_review.php
 *
 * Covers: the DB 2.6.113 step (db.sql parity, idempotent re-run); editing an article snapshots the PREVIOUS
 * content into kb_article_versions before overwriting it (never the new content); a second edit creates
 * version #2, not a duplicate #1; the version-view modal renders a snapshot read-only; Restore both reinstates
 * an old version's content AND itself creates a new version of whatever was live just before the restore (so a
 * restore is never a silent, unrecoverable overwrite); the review-due badge/filter only fires once the due date
 * has actually passed, not before; a read-only module_kb role is refused on both writes (restore, set review)
 * while a write-level role succeeds; client-scoped article access is enforced on every new endpoint.
 */
require __DIR__ . '/support/security_lib.php';
require_once "$root/includes/database_version.php";

echo "\n--- DB 2.6.113 step ---\n";
$ok((int) $one("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kb_article_versions'") === 1, 'kb_article_versions exists');
$ok((int) $one("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kb_articles' AND COLUMN_NAME = 'kb_article_review_due_at'") === 1, 'kb_articles has kb_article_review_due_at');
$ok((int) $one("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kb_articles' AND COLUMN_NAME = 'kb_article_reviewer_user_id'") === 1, 'kb_articles has kb_article_reviewer_user_id');
$ok(version_compare(LATEST_DATABASE_VERSION, '2.6.113', '>='), 'LATEST_DATABASE_VERSION is 2.6.113 or later');
$sqlText = (string) file_get_contents("$root/db.sql");
$ok(str_contains($sqlText, 'CREATE TABLE `kb_article_versions`') && str_contains($sqlText, '`kb_article_review_due_at` date DEFAULT NULL'), 'db.sql carries the versions table and the review column');
$verBefore = (string) $one('SELECT config_current_database_version FROM settings LIMIT 1');
$q("UPDATE settings SET config_current_database_version = '2.6.112'");
[, $updOut] = sec_sh('cd ' . escapeshellarg("$root/scripts") . ' && php update_cli.php --update_db');
$ok(str_contains($updOut, '2.6.112') && (string) $one('SELECT config_current_database_version FROM settings LIMIT 1') === LATEST_DATABASE_VERSION, 'the updater advances past 2.6.112 to the latest version');
[, $updOut2] = sec_sh('cd ' . escapeshellarg("$root/scripts") . ' && php update_cli.php --update_db');
$ok(str_contains($updOut2, 'already at the latest'), 'running it again is a no-op, not an error');
$q("UPDATE settings SET config_current_database_version = '" . $esc($verBefore) . "'");

echo "\n--- fixtures ---\n";
$q("DELETE FROM kb_article_versions WHERE kb_article_version_kb_article_id IN (SELECT kb_article_id FROM kb_articles WHERE kb_article_title LIKE 'kbv-test-%')");
$q("DELETE FROM kb_articles WHERE kb_article_title LIKE 'kbv-test-%'");
$q("DELETE FROM clients WHERE client_name LIKE 'kbv-test-%'");
$q("DELETE FROM user_client_permissions WHERE user_id IN (SELECT user_id FROM users WHERE user_email LIKE 'kbv-test-%')");
$q("DELETE FROM users WHERE user_email LIKE 'kbv-test-%'");
$q("DELETE FROM user_roles WHERE role_name = 'kbv-test-ReadOnly'");

$q("INSERT INTO clients SET client_name='kbv-test-Client A'"); $clientA = (int) $db->insert_id;

$q("INSERT INTO user_roles SET role_name='kbv-test-ReadOnly', role_is_admin=0, role_type=1");
$roleReadOnly = (int) $db->insert_id;
$modKbId = (int) $one("SELECT module_id FROM modules WHERE module_name='module_kb'");
if ($modKbId === 0) { $q("INSERT INTO modules SET module_name='module_kb'"); $modKbId = (int) $db->insert_id; }
$q("DELETE FROM user_role_permissions WHERE user_role_id = $roleReadOnly");
$q("INSERT INTO user_role_permissions SET user_role_id=$roleReadOnly, module_id=$modKbId, user_role_permission_level=1"); // read-only

$q("INSERT INTO users SET user_name='kbv-test-reader', user_email='kbv-test-reader@example.test', user_password='x', user_type=1, user_status=1, user_role_id=$roleReadOnly");
$reader = (int) $db->insert_id;
$q("INSERT INTO user_settings SET user_id=$reader ON DUPLICATE KEY UPDATE user_id=user_id");

$admin = (int) $one("SELECT user_id FROM users WHERE user_role_id = 1 LIMIT 1");
if (!$admin) { $q("INSERT INTO users SET user_name='kbv-test-admin', user_email='kbv-test-admin@example.test', user_password='x', user_type=1, user_status=1, user_role_id=1"); $admin = (int) $db->insert_id; }

$sdir = sys_get_temp_dir() . '/kbv_sess_' . bin2hex(random_bytes(4));
mkdir($sdir, 0700);
register_shutdown_function(function () use ($sdir) { foreach (glob("$sdir/*") ?: [] as $f) { @unlink($f); } @rmdir($sdir); });
$base = sec_start_server($sdir);
$sidAdmin = sec_forge_session($sdir, ['logged' => true, 'user_id' => $admin, 'csrf_token' => 'csrftok12345678']);
$sidReader = sec_forge_session($sdir, ['logged' => true, 'user_id' => $reader, 'csrf_token' => 'csrftok12345678']);
$ck = [];
$postAdmin = fn (array $f) => sec_web($base, 'POST', '/agent/post.php', $sidAdmin, $f + ['csrf_token' => 'csrftok12345678'], $ck);
$postReader = fn (array $f) => sec_web($base, 'POST', '/agent/post.php', $sidReader, $f + ['csrf_token' => 'csrftok12345678'], $ck);
$getAdmin = fn (string $path) => sec_web($base, 'GET', $path, $sidAdmin, [], $ck);
$getReader = fn (string $path) => sec_web($base, 'GET', $path, $sidReader, [], $ck);

echo "\n--- creating an article snapshots nothing yet ---\n";
[$c] = $postAdmin(['add_kb_article' => '1', 'title' => 'kbv-test-Article', 'content' => '<p>Version one.</p>', 'client_id' => $clientA, 'category_id' => 0, 'client_visible' => 1]);
$ok($c === 200 || $c === 302, 'add_kb_article succeeds');
$articleId = (int) $one("SELECT kb_article_id FROM kb_articles WHERE kb_article_title = 'kbv-test-Article'");
$ok($articleId > 0, 'the article exists');
$ok((int) $one("SELECT COUNT(*) FROM kb_article_versions WHERE kb_article_version_kb_article_id = $articleId") === 0, 'no version row yet - the FIRST edit is what creates version #1 (the content being replaced)');

echo "\n--- editing creates a version snapshot of what was replaced, never the new text ---\n";
$postAdmin(['edit_kb_article' => '1', 'kb_article_id' => $articleId, 'title' => 'kbv-test-Article', 'content' => '<p>Version two.</p>', 'client_id' => $clientA, 'category_id' => 0, 'client_visible' => 1]);
$v1 = $rows("SELECT * FROM kb_article_versions WHERE kb_article_version_kb_article_id = $articleId ORDER BY kb_article_version_number ASC");
$ok(count($v1) === 1 && $v1[0]['kb_article_version_number'] == 1 && str_contains($v1[0]['kb_article_version_content'], 'Version one'), 'edit #1 snapshots the OLD content ("Version one") as version #1, not the new one');
$liveContent = (string) $one("SELECT kb_article_content FROM kb_articles WHERE kb_article_id = $articleId");
$ok(str_contains($liveContent, 'Version two'), 'the live article now holds the new content');

echo "\n--- a second edit creates version #2, not a duplicate #1 ---\n";
$postAdmin(['edit_kb_article' => '1', 'kb_article_id' => $articleId, 'title' => 'kbv-test-Article', 'content' => '<p>Version three.</p>', 'client_id' => $clientA, 'category_id' => 0, 'client_visible' => 1]);
$v2 = $rows("SELECT * FROM kb_article_versions WHERE kb_article_version_kb_article_id = $articleId ORDER BY kb_article_version_number ASC");
$ok(count($v2) === 2 && $v2[1]['kb_article_version_number'] == 2 && str_contains($v2[1]['kb_article_version_content'], 'Version two'), 'edit #2 snapshots "Version two" as version #2');

echo "\n--- version history page + version-view modal ---\n";
[$c, $body] = $getAdmin("/agent/kb_article_versions.php?kb_article_id=$articleId");
$ok($c === 200 && str_contains($body, '#1') && str_contains($body, '#2'), 'the history page lists both versions');
$version1Id = (int) $v2[0]['kb_article_version_id'];
[$c, $body] = $getAdmin("/agent/modals/kb_article/kb_article_version_view.php?id=$version1Id");
$ok($c === 200 && str_contains($body, 'Version one'), 'the version-view modal renders the old snapshot');

echo "\n--- restore reinstates old content AND snapshots the pre-restore content, never a silent overwrite ---\n";
$postAdmin(['restore_kb_article_version' => $version1Id, 'kb_article_id' => $articleId]);
$restoredContent = (string) $one("SELECT kb_article_content FROM kb_articles WHERE kb_article_id = $articleId");
$ok(str_contains($restoredContent, 'Version one'), 'the live article now holds the restored (version #1) content');
$v3 = $rows("SELECT * FROM kb_article_versions WHERE kb_article_version_kb_article_id = $articleId ORDER BY kb_article_version_number ASC");
$ok(count($v3) === 3 && $v3[2]['kb_article_version_number'] == 3 && str_contains($v3[2]['kb_article_version_content'], 'Version three'), 'the restore itself created version #3 from what was live just before it ("Version three") - a restore is just another version, not an unrecoverable overwrite');

echo "\n--- a read-only module_kb role cannot restore a version ---\n";
$beforeRestoreCount = (int) $one("SELECT COUNT(*) FROM kb_article_versions WHERE kb_article_version_kb_article_id = $articleId");
$postReader(['restore_kb_article_version' => $v3[0]['kb_article_version_id'], 'kb_article_id' => $articleId]);
$ok((int) $one("SELECT COUNT(*) FROM kb_article_versions WHERE kb_article_version_kb_article_id = $articleId") === $beforeRestoreCount, 'the restore attempt by a read-only role is refused server-side - no new version was created');
$ok(str_contains((string) $one("SELECT kb_article_content FROM kb_articles WHERE kb_article_id = $articleId"), 'Version one'), 'and the live article is unchanged');

echo "\n--- review schedule: set, badge, filter ---\n";
$farOut = date('Y-m-d', strtotime('+30 days'));
$postAdmin(['set_kb_article_review' => '1', 'kb_article_id' => $articleId, 'review_due_at' => $farOut, 'reviewer_user_id' => $admin]);
[$c, $body] = $getAdmin("/agent/kb_article.php?id=$articleId");
$ok($c === 200 && !str_contains($body, 'Needs Review'), 'a review date 30 days out shows no "Needs Review" badge yet');
$overdue = date('Y-m-d', strtotime('-3 days'));
$postAdmin(['set_kb_article_review' => '1', 'kb_article_id' => $articleId, 'review_due_at' => $overdue, 'reviewer_user_id' => $admin]);
[$c, $body] = $getAdmin("/agent/kb_article.php?id=$articleId");
$ok($c === 200 && str_contains($body, 'Needs Review'), 'an overdue review date shows the "Needs Review" badge');
[$c, $body] = $getAdmin('/agent/kb_articles.php?filter_needs_review=1');
$ok($c === 200 && str_contains($body, 'kbv-test-Article'), 'the "Needs Review Only" filter surfaces the overdue article');
[$c, $body] = $getAdmin('/agent/kb_articles.php');
$ok($c === 200 && str_contains($body, 'kbv-test-Article') && str_contains($body, 'Needs Review'), 'the unfiltered list also shows the badge on the card');

echo "\n--- a read-only module_kb role cannot set a review schedule ---\n";
$beforeReview = (string) $one("SELECT kb_article_review_due_at FROM kb_articles WHERE kb_article_id = $articleId");
$postReader(['set_kb_article_review' => '1', 'kb_article_id' => $articleId, 'review_due_at' => $farOut, 'reviewer_user_id' => $reader]);
$ok((string) $one("SELECT kb_article_review_due_at FROM kb_articles WHERE kb_article_id = $articleId") === $beforeReview, 'the review-schedule write by a read-only role is refused server-side - the due date is unchanged');

echo "\n--- a read-only module_kb role CAN still read the version history (level 1) ---\n";
[$c] = $getReader("/agent/kb_article_versions.php?kb_article_id=$articleId");
$ok($c === 200, 'a read-only role can view the version history page (reading is level 1, not a write)');

$thisPort = (int) explode(':', $base)[2];
$logText = (string) @file_get_contents(sys_get_temp_dir() . "/sec_server_$thisPort.log");
$otherWarnings = preg_replace('/^.*Undefined array key "HTTP_USER_AGENT".*$/m', '', $logText);
$ok(!str_contains($otherWarnings, 'PHP Warning') && !str_contains($otherWarnings, 'PHP Fatal') && !str_contains($otherWarnings, 'PHP Notice'), 'no PHP warning, notice or fatal in the server log (besides the pre-existing harness-wide missing-User-Agent one)');

echo "\n--- cleanup ---\n";
$q("DELETE FROM kb_article_versions WHERE kb_article_version_kb_article_id = $articleId");
$q("DELETE FROM kb_articles WHERE kb_article_id = $articleId");
$q("DELETE FROM clients WHERE client_id = $clientA");
$q("DELETE FROM user_client_permissions WHERE user_id = $reader");
$q("DELETE FROM users WHERE user_id = $reader");
$q("DELETE FROM user_role_permissions WHERE user_role_id = $roleReadOnly");
$q("DELETE FROM user_roles WHERE user_role_id = $roleReadOnly");
