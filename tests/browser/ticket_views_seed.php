<?php
// Seeds agents, boards, tickets and views for tests/browser/ticket_views_smoke.mjs into the scratch database named by config.php.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../../config.php';
if (!preg_match('/scratch|test/i', (string) $database)) { fwrite(STDERR, "Refusing: not a scratch database\n"); exit(2); }
$q = fn (string $s) => mysqli_query($mysqli, $s) or die(mysqli_error($mysqli) . "\n$s\n");
foreach (['tickets', 'ticket_saved_views', 'ticket_view_defaults'] as $t) { $q("DELETE FROM `$t`"); }
$q("DELETE FROM categories WHERE category_type = 'Ticket'");
$q("DELETE FROM users WHERE user_id > 1");
$q("DELETE FROM user_settings WHERE user_id > 1");
$q("UPDATE user_settings SET user_config_records_per_page=50 WHERE user_id=1");
foreach ([[2, 'Alex Agent'], [3, 'Bo Agent'], [4, 'Cy Agent']] as [$id, $name]) {
    $q("INSERT INTO users SET user_id=$id, user_name='$name', user_email='u$id@scratch.test', user_password='x', user_type=1, user_status=1, user_role_id=2");
    $q("INSERT INTO user_settings SET user_id=$id, user_config_records_per_page=50");
}
$q("INSERT INTO categories SET category_id=801, category_name='TV Board A', category_type='Ticket', category_parent=0");
$q("INSERT INTO categories SET category_id=802, category_name='TV Board B', category_type='Ticket', category_parent=0");
$q("INSERT INTO categories SET category_id=811, category_name='TV Printers', category_type='Ticket', category_parent=801");
$prio = ['Low', 'Medium', 'High'];
for ($i = 1; $i <= 24; $i++) {
    $a = [0, 2, 3, 4][$i % 4];
    $q("INSERT INTO tickets SET ticket_id=$i, ticket_prefix='TV-', ticket_number=" . (2000 + $i) . ", ticket_subject='tvT" . sprintf('%02d', $i) . "', ticket_priority='" . $prio[$i % 3] . "', ticket_status=1, ticket_assigned_to=$a, ticket_onsite=" . ($i % 2) . ", ticket_category=" . [0, 801, 811, 802][$i % 4] . ", ticket_client_id=0, ticket_created_by=1, ticket_details=''");
}
$q("INSERT INTO ticket_saved_views SET ticket_saved_view_id=1, ticket_saved_view_name='Everything open', ticket_saved_view_icon='fa-list', ticket_saved_view_query='status=Open', ticket_saved_view_user_id=0, ticket_saved_view_order=0");
$q("INSERT INTO ticket_saved_views SET ticket_saved_view_id=2, ticket_saved_view_name='Old style High', ticket_saved_view_icon='fa-fire', ticket_saved_view_query='priority=High&status=Open', ticket_saved_view_user_id=0, ticket_saved_view_order=1");
echo "seeded\n";
