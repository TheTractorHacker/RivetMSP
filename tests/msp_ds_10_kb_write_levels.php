<?php
// MSP-DS-10: KB article/category writes need module_kb Write (delete: Full) and categories are client checked. Run: php tests/msp_ds_10_kb_write_levels.php
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$root = dirname(__DIR__);
$block = function (string $src, string $marker, int $len = 1400) { $i = strpos($src, $marker); return $i === false ? '' : substr($src, $i, $len); };
$a = file_get_contents("$root/agent/post/kb_article.php");
$ok('no module_kb check left at Read level in articles', preg_match("/enforceUserPermission\('module_kb'\)/", $a) === 0);
foreach (['add_kb_article', 'edit_kb_article'] as $h) { $ok("$h needs Write", strpos($block($a, "\$_POST['$h']", 200), "'module_kb', 2") !== false); }
$ok('delete_kb_article needs Full', strpos($block($a, "\$_GET['delete_kb_article']", 200), "'module_kb', 3") !== false);
$c = file_get_contents("$root/agent/post/kb_category.php");
$ok('no module_kb check left at Read level in categories', preg_match("/enforceUserPermission\('module_kb'\)/", $c) === 0);
$ok('add category needs Write and client access', ($b = $block($c, "\$_POST['add_kb_category']")) && strpos($b, "'module_kb', 2") !== false && strpos($b, 'enforceClientAccess($kb_category_client_id)') !== false);
$ok('edit category needs Write, checks stored and new client', ($b = $block($c, "\$_POST['edit_kb_category']")) && strpos($b, "'module_kb', 2") !== false && strpos($b, "getFieldById('kb_categories'") !== false && strpos($b, 'enforceClientAccess($existing_kb_category_client_id)') !== false && strpos($b, 'enforceClientAccess($kb_category_client_id)') !== false);
$ok('delete category needs Full and checks stored client', ($b = $block($c, "\$_GET['delete_kb_category']")) && strpos($b, "'module_kb', 3") !== false && strpos($b, 'enforceClientAccess($existing_kb_category_client_id)') !== false);
exit($fail ? 1 : 0);
