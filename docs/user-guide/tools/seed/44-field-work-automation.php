<?php
/*
 * Field work, part 5: two ticket automation rules used by the dispatch section of page 08 (auto-dispatch and add worksheet).
 * Idempotent; relative to "now" in America/Chicago. Depends on seeds 00 and 01 (and uses the categories / labor types seed 40 creates).
 */
if (php_sapi_name() !== 'cli') { exit("Run this from the command line.\n"); }
$app = getenv('RIVETIT_APP_DIR') ?: getenv('RIVETMSP_APP_DIR') ?: '/tmp/rivetmsp-user-guide-demo';
if (!is_file($app . '/config.php')) { fwrite(STDERR, "no config.php in '$app'. Set RIVETIT_APP_DIR.\n"); exit(1); }
chdir($app . '/scripts');
require_once '../config.php';
require_once '../functions.php';
date_default_timezone_set('America/Chicago');
function q($sql) { global $mysqli; if (!mysqli_query($mysqli, $sql)) { fwrite(STDERR, "40-field-work-billing: " . mysqli_error($mysqli) . "\n  " . substr($sql, 0, 300) . "\n"); exit(1); } return true; }
function one($sql) { global $mysqli; $r = mysqli_query($mysqli, $sql); $row = $r ? mysqli_fetch_row($r) : null; return $row ? $row[0] : null; }
function e($s) { global $mysqli; return mysqli_real_escape_string($mysqli, (string) $s); }
function dt($ts) { return date('Y-m-d H:i:s', $ts); }
function at($daysAgo, $hour, $min = 0) { return mktime($hour, $min, 0, (int) date('n'), (int) date('j') - $daysAgo, (int) date('Y')); }
function uid($name) { $v = one("SELECT user_id FROM users WHERE user_name = '" . e($name) . "' LIMIT 1"); return $v ? (int) $v : 0; }
function cid($name) { $v = one("SELECT client_id FROM clients WHERE client_name = '" . e($name) . "' LIMIT 1"); if (!$v) { fwrite(STDERR, "40-field-work-billing: client '$name' missing (seed 00)\n"); exit(1); } return (int) $v; }
function contact_id($client_id, $name) { if ($name === '') return 0; $v = one("SELECT contact_id FROM contacts WHERE contact_client_id = " . (int) $client_id . " AND contact_name = '" . e($name) . "' LIMIT 1"); return $v ? (int) $v : 0; }

function ensure_category($name, $color, $extra = '') {
    $id = one("SELECT category_id FROM categories WHERE category_type = 'Ticket' AND category_name = '" . e($name) . "' AND category_archived_at IS NULL LIMIT 1");
    if ($id) return (int) $id;
    q("INSERT INTO categories SET category_name = '" . e($name) . "', category_type = 'Ticket', category_color = '" . e($color) . "', category_parent = 0 $extra");
    return (int) mysqli_insert_id($GLOBALS['mysqli']);
}
function ensure_tag($name, $type, $color) {
    $id = one("SELECT tag_id FROM tags WHERE tag_name = '" . e($name) . "' AND tag_type = " . (int) $type . " LIMIT 1");
    if ($id) return (int) $id;
    q("INSERT INTO tags SET tag_name = '" . e($name) . "', tag_type = " . (int) $type . ", tag_color = '" . e($color) . "'");
    return (int) mysqli_insert_id($GLOBALS['mysqli']);
}
function next_ticket_number() {
    q("UPDATE settings SET config_ticket_next_number = LAST_INSERT_ID(config_ticket_next_number), config_ticket_next_number = config_ticket_next_number + 1 WHERE company_id = 1");
    return (int) mysqli_insert_id($GLOBALS['mysqli']);
}


$lt=[]; $r=mysqli_query($mysqli,'SELECT labor_type_id, labor_type_name FROM labor_types'); while($x=mysqli_fetch_assoc($r)){ $lt[$x['labor_type_name']]=(int)$x['labor_type_id']; }

$onsite = (int) one("SELECT category_id FROM categories WHERE category_type='Ticket' AND category_name='On-Site' LIMIT 1");
$tmpl = (int) one("SELECT worksheet_template_id FROM worksheet_templates WHERE worksheet_template_name = 'On-site visit sign-off' LIMIT 1");
if (!$onsite || !$tmpl) { fwrite(STDERR, "44: run seed 40 first\n"); exit(1); }
$rules = [
  ['Auto-dispatch new On-Site tickets', 'ticket_created', [['field' => 'category', 'op' => 'equals', 'value' => (string) $onsite]], [['action' => 'dispatch_assign', 'value' => '']], 10],
  ['Add the visit sign-off worksheet to On-Site tickets', 'ticket_created', [['field' => 'category', 'op' => 'equals', 'value' => (string) $onsite]], [['action' => 'add_worksheet', 'value' => (string) $tmpl]], 20],
];
foreach ($rules as [$name, $trig, $conds, $acts, $order]) {
    if (one("SELECT 1 FROM ticket_automation_rules WHERE rule_name = '" . e($name) . "'")) continue;
    q("INSERT INTO ticket_automation_rules SET rule_name = '" . e($name) . "', rule_enabled = 1, rule_trigger = '$trig', rule_cond_field = '{$conds[0]['field']}', rule_cond_op = '{$conds[0]['op']}',
        rule_cond_value = '" . e($conds[0]['value']) . "', rule_conditions_json = '" . e(json_encode($conds)) . "', rule_action = '{$acts[0]['action']}', rule_action_value = '" . e($acts[0]['value']) . "',
        rule_actions_json = '" . e(json_encode($acts)) . "', rule_order = $order");
}
echo "44-field-work-automation: done\n";
