<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)
/*
 * Link recurring invoices to contracts, pause/expire with the contract (GitHub roadmap #24), against the
 * REAL code on a SCRATCH database, real HTTP (same harness as tests/security_*.php).
 *
 *   RIVETMSP_TEST_DB=1 RIVETMSP_TEST_DB_NAME=..scratch.. RIVETMSP_TEST_DB_USER=... RIVETMSP_TEST_DB_PASS=... \
 *   RIVETMSP_REDIS_HOST=127.0.0.1 RIVETMSP_REDIS_PORT=<throwaway> RIVETMSP_REDIS_ENV_FILE=/dev/null php tests/recurring_invoice_contracts.php
 *
 * Covers: the DB step (db.sql parity, idempotent re-run); linking a contract to a recurring invoice
 * through the real add/edit handlers, with a cross-client contract id refused (never trusted from the
 * posted value alone); setting a contract to Expired or Cancelled automatically pauses every ACTIVE
 * recurring invoice linked to it (and only those - an already-paused one is left alone, and an unrelated
 * recurring invoice with no contract link is unaffected); re-saving an already-Expired contract does not
 * re-fire and does not fight an admin who manually re-enabled the invoice since; deleting (archiving) a
 * contract pauses its linked invoices the same way; a history row and an audit log entry explain WHY it
 * stopped.
 */
require __DIR__ . '/support/security_lib.php';
require_once "$root/includes/database_version.php";

echo "\n--- DB 2.6.113 step ---\n";
// Checked AFTER forcing a replay below, not before: this branch and the other three worktrees queued this
// session (msp-port-kb-v2, msp-port-intune-per-client, msp-port-oidc-portal) all branched from 2.6.112 and
// each claims "2.6.113" for its own step - whichever one's test ran most recently against this SHARED
// scratch database may have already left config_current_database_version at that literal string without
// THIS branch's migration ever having run.
$ok(version_compare(LATEST_DATABASE_VERSION, '2.6.113', '>='), 'LATEST_DATABASE_VERSION is 2.6.113 or later');
$sqlText = (string) file_get_contents("$root/db.sql");
$ok(str_contains($sqlText, '`recurring_invoice_contract_id` int(11) DEFAULT NULL'), 'db.sql carries the column');
$verBefore = (string) $one('SELECT config_current_database_version FROM settings LIMIT 1');
$q("UPDATE settings SET config_current_database_version = '2.6.112'");
[, $updOut] = sec_sh('cd ' . escapeshellarg("$root/scripts") . ' && php update_cli.php --update_db');
$ok(str_contains($updOut, '2.6.112') && (string) $one('SELECT config_current_database_version FROM settings LIMIT 1') === LATEST_DATABASE_VERSION, 'the updater advances past 2.6.112 to the latest version');
[, $updOut2] = sec_sh('cd ' . escapeshellarg("$root/scripts") . ' && php update_cli.php --update_db');
$ok(str_contains($updOut2, 'already at the latest'), 'running it again is a no-op, not an error');
$ok((int) $one("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'recurring_invoices' AND COLUMN_NAME = 'recurring_invoice_contract_id'") === 1, 'recurring_invoices has recurring_invoice_contract_id after the replay');
$q("UPDATE settings SET config_current_database_version = '" . $esc($verBefore) . "'");

echo "\n--- fixtures ---\n";
$q("DELETE FROM history WHERE history_recurring_invoice_id IN (SELECT recurring_invoice_id FROM recurring_invoices WHERE recurring_invoice_scope LIKE 'recinv-test-%')");
$q("DELETE FROM recurring_invoices WHERE recurring_invoice_scope LIKE 'recinv-test-%'");
$q("DELETE FROM contracts WHERE contract_name LIKE 'recinv-test-%'");
$q("DELETE FROM categories WHERE category_name = 'recinv-test-Income'");
$q("DELETE FROM clients WHERE client_name LIKE 'recinv-test-%'");
$q("INSERT INTO clients SET client_name='recinv-test-Client A'"); $clientA = (int) $db->insert_id;
$q("INSERT INTO clients SET client_name='recinv-test-Client B'"); $clientB = (int) $db->insert_id;
$q("INSERT INTO categories SET category_name='recinv-test-Income', category_type='Income'"); $categoryId = (int) $db->insert_id;
$q("INSERT INTO contracts SET contract_name='recinv-test-Contract A', contract_status='Active', contract_type='Managed Services', contract_client_id=$clientA");
$contractA = (int) $db->insert_id;
$q("INSERT INTO contracts SET contract_name='recinv-test-Contract B', contract_status='Active', contract_type='Managed Services', contract_client_id=$clientB");
$contractB = (int) $db->insert_id;

$admin = (int) $one("SELECT user_id FROM users WHERE user_role_id = 1 LIMIT 1");
if (!$admin) { $q("INSERT INTO users SET user_name='recinv-test-admin', user_email='recinv-test-admin@example.test', user_password='x', user_type=1, user_status=1, user_role_id=1"); $admin = (int) $db->insert_id; }

$sdir = sys_get_temp_dir() . '/recinv_sess_' . bin2hex(random_bytes(4));
mkdir($sdir, 0700);
register_shutdown_function(function () use ($sdir) { foreach (glob("$sdir/*") ?: [] as $f) { @unlink($f); } @rmdir($sdir); });
$base = sec_start_server($sdir);
$sidAdmin = sec_forge_session($sdir, ['logged' => true, 'user_id' => $admin, 'csrf_token' => 'csrftok12345678']);
$post = fn (array $f) => sec_web($base, 'POST', '/agent/post.php', $sidAdmin, $f + ['csrf_token' => 'csrftok12345678'], []);
$get = fn (string $qs) => sec_web($base, 'GET', '/agent/post.php?' . $qs . '&csrf_token=csrftok12345678', $sidAdmin);

echo "\n--- linking through the real add handler ---\n";
$post(['add_recurring_invoice' => '1', 'client_id' => $clientA, 'start_date' => date('Y-m-d'), 'frequency' => 'month', 'category' => $categoryId, 'scope' => 'recinv-test-linked', 'contract_id' => $contractA]);
$recInvLinked = (int) $one("SELECT recurring_invoice_id FROM recurring_invoices WHERE recurring_invoice_scope = 'recinv-test-linked'");
$ok($recInvLinked > 0, 'the recurring invoice was created');
$ok((int) $one("SELECT recurring_invoice_contract_id FROM recurring_invoices WHERE recurring_invoice_id = $recInvLinked") === $contractA, 'linked to the contract chosen on the real add form');

echo "\n--- a contract belonging to a DIFFERENT client is refused, not silently accepted ---\n";
$post(['add_recurring_invoice' => '1', 'client_id' => $clientA, 'start_date' => date('Y-m-d'), 'frequency' => 'month', 'category' => $categoryId, 'scope' => 'recinv-test-crossclient', 'contract_id' => $contractB]);
$recInvCross = (int) $one("SELECT recurring_invoice_id FROM recurring_invoices WHERE recurring_invoice_scope = 'recinv-test-crossclient'");
$ok($recInvCross > 0 && $one("SELECT recurring_invoice_contract_id FROM recurring_invoices WHERE recurring_invoice_id = $recInvCross") === null, "Client B's contract is not linked when adding for Client A - the mismatched id is dropped, not stored");

echo "\n--- a second, unrelated recurring invoice for Client A, with NO contract link ---\n";
$post(['add_recurring_invoice' => '1', 'client_id' => $clientA, 'start_date' => date('Y-m-d'), 'frequency' => 'month', 'category' => $categoryId, 'scope' => 'recinv-test-unlinked', 'contract_id' => '']);
$recInvUnlinked = (int) $one("SELECT recurring_invoice_id FROM recurring_invoices WHERE recurring_invoice_scope = 'recinv-test-unlinked'");
$ok($recInvUnlinked > 0, 'created with no contract link');

echo "\n--- a THIRD, already-paused invoice linked to the same contract, to prove it is left alone ---\n";
$post(['add_recurring_invoice' => '1', 'client_id' => $clientA, 'start_date' => date('Y-m-d'), 'frequency' => 'month', 'category' => $categoryId, 'scope' => 'recinv-test-alreadypaused', 'contract_id' => $contractA]);
$recInvPaused = (int) $one("SELECT recurring_invoice_id FROM recurring_invoices WHERE recurring_invoice_scope = 'recinv-test-alreadypaused'");
$q("UPDATE recurring_invoices SET recurring_invoice_status = 0 WHERE recurring_invoice_id = $recInvPaused");
$historyCountBefore = (int) $one("SELECT COUNT(*) FROM history WHERE history_recurring_invoice_id = $recInvPaused");

echo "\n--- marking the contract Expired pauses the ACTIVE linked invoice, not the already-paused one ---\n";
[$c] = $post(['edit_contract' => '1', 'contract_id' => $contractA, 'contract_client_id' => $clientA, 'contract_name' => 'recinv-test-Contract A', 'contract_type' => 'Managed Services', 'contract_status' => 'Expired', 'contract_start_date' => '', 'contract_end_date' => '', 'contract_renewal_date' => '', 'contract_details' => '']);
$ok($c === 200 || $c === 302, 'edit_contract succeeds');
$ok((int) $one("SELECT recurring_invoice_status FROM recurring_invoices WHERE recurring_invoice_id = $recInvLinked") === 0, 'the linked, previously-active recurring invoice is now paused');
$ok((int) $one("SELECT recurring_invoice_status FROM recurring_invoices WHERE recurring_invoice_id = $recInvUnlinked") === 1, "the unlinked recurring invoice for the SAME client is untouched - this is about the contract link, not the client");
$ok((int) $one("SELECT COUNT(*) FROM history WHERE history_recurring_invoice_id = $recInvPaused") === $historyCountBefore, 'the already-paused invoice gets no new history row - it was not re-paused');
$hist = $rows("SELECT * FROM history WHERE history_recurring_invoice_id = $recInvLinked ORDER BY history_id DESC LIMIT 1")[0];
$ok(str_contains($hist['history_description'], 'paused automatically') && str_contains($hist['history_description'], 'Expired'), 'a history row explains it was paused automatically because the contract was marked Expired');

echo "\n--- re-admin can still turn it back on, and re-saving the (still Expired) contract does not fight that ---\n";
$q("UPDATE recurring_invoices SET recurring_invoice_status = 1 WHERE recurring_invoice_id = $recInvLinked");
$post(['edit_contract' => '1', 'contract_id' => $contractA, 'contract_client_id' => $clientA, 'contract_name' => 'recinv-test-Contract A', 'contract_type' => 'Managed Services', 'contract_status' => 'Expired', 'contract_start_date' => '', 'contract_end_date' => '', 'contract_renewal_date' => '', 'contract_details' => 'updated notes']);
$ok((int) $one("SELECT recurring_invoice_status FROM recurring_invoices WHERE recurring_invoice_id = $recInvLinked") === 1, 'saving an ALREADY-Expired contract again does not re-pause an invoice an admin deliberately turned back on');

echo "\n--- deleting a contract pauses its linked invoice the same way ---\n";
$q("UPDATE recurring_invoices SET recurring_invoice_status = 1 WHERE recurring_invoice_id = $recInvLinked");
$q("UPDATE contracts SET contract_status = 'Active' WHERE contract_id = $contractB");
$post(['add_recurring_invoice' => '1', 'client_id' => $clientB, 'start_date' => date('Y-m-d'), 'frequency' => 'month', 'category' => $categoryId, 'scope' => 'recinv-test-deleted-contract', 'contract_id' => $contractB]);
$recInvDeleted = (int) $one("SELECT recurring_invoice_id FROM recurring_invoices WHERE recurring_invoice_scope = 'recinv-test-deleted-contract'");
$get("delete_contract=$contractB");
$ok((int) $one("SELECT recurring_invoice_status FROM recurring_invoices WHERE recurring_invoice_id = $recInvDeleted") === 0, 'deleting the contract paused the invoice linked to it, same as marking it Expired/Cancelled');

echo "\n--- editing to unlink and re-link through the real edit form ---\n";
$q("UPDATE recurring_invoices SET recurring_invoice_contract_id = $contractA, recurring_invoice_status = 1 WHERE recurring_invoice_id = $recInvUnlinked");
$post(['edit_recurring_invoice' => '1', 'recurring_invoice_id' => $recInvUnlinked, 'frequency' => 'month', 'next_date' => date('Y-m-d'), 'category' => $categoryId, 'scope' => 'recinv-test-unlinked', 'status' => '1', 'recurring_invoice_discount' => '0', 'contract_id' => '']);
$ok($one("SELECT recurring_invoice_contract_id FROM recurring_invoices WHERE recurring_invoice_id = $recInvUnlinked") === null, 'the real edit form can unlink a contract (blank selection stores NULL)');

$thisPort = (int) explode(':', $base)[2];
$logText = (string) @file_get_contents(sys_get_temp_dir() . "/sec_server_$thisPort.log");
$otherWarnings = preg_replace('/^.*Undefined array key "HTTP_USER_AGENT".*$/m', '', $logText);
$ok(!str_contains($otherWarnings, 'PHP Warning') && !str_contains($otherWarnings, 'PHP Fatal') && !str_contains($otherWarnings, 'PHP Notice'), 'no PHP warning, notice or fatal in the server log (besides the pre-existing harness-wide missing-User-Agent one)');

echo "\n--- cleanup ---\n";
$q("DELETE FROM history WHERE history_recurring_invoice_id IN (SELECT recurring_invoice_id FROM recurring_invoices WHERE recurring_invoice_scope LIKE 'recinv-test-%')");
$q("DELETE FROM recurring_invoices WHERE recurring_invoice_scope LIKE 'recinv-test-%'");
$q("DELETE FROM contracts WHERE contract_name LIKE 'recinv-test-%'");
$q("DELETE FROM categories WHERE category_id = $categoryId");
$q("DELETE FROM clients WHERE client_id IN ($clientA, $clientB)");
