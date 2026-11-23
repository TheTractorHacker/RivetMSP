<?php
// MSP-DS-38: Credential Restore never runs uploaded SQL that is not plain CREATE/INSERT data.
define('FROM_POST_HANDLER', 1);
require __DIR__ . '/../admin/post/credential_restore.php';
$fail = 0;
function t($n, $c) { global $fail; echo ($c ? 'ok' : 'FAIL') . " - $n\n"; if (!$c) $fail = 1; }

$good = "INSERT INTO `credentials` (`credential_id`, `credential_name`, `credential_password`) VALUES ('1', 'a\\'b ''x'' \\n', NULL), (2, 'z', 0x41);";
$lines = [$good,
  "INSERT INTO `credentials` (credential_name) SELECT user_email FROM users;",
  "INSERT INTO `credentials` VALUES ('a', (SELECT 1));",
  "INSERT INTO `credentials` VALUES ('a'); DROP TABLE users;",
  "INSERT INTO `credentials` VALUES ('a') ON DUPLICATE KEY UPDATE x=1;",
  "INSERT INTO `credentials` VALUES (CONCAT('a','b'));",
  "INSERT INTO `credentials` VALUES ('a\\');",
];
[$c, $ins] = credentialRestoreExtractTable($lines, 'credentials');
t('only the plain VALUES insert is kept', $ins === [$good]);

$ok = ["CREATE TABLE `credentials` (", "  `credential_id` int(11) NOT NULL AUTO_INCREMENT,", "  `n` varchar(200) DEFAULT 'a(b',", "  PRIMARY KEY (`credential_id`),", "  KEY `k` (`n`(10))", ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;"];
t('normal create accepted', credentialRestoreBuildStagingCreate($ok, 'credentials', 'stg') !== '');
$bad1 = $ok; $bad1[5] = ") ENGINE=InnoDB SELECT user_email FROM users;";
t('CREATE ... ENGINE ... SELECT refused', credentialRestoreBuildStagingCreate($bad1, 'credentials', 'stg') === '');
$bad2 = $ok; $bad2[4] = "  `x` int) SELECT user_password FROM users -- ";
t('body that closes the paren refused', credentialRestoreBuildStagingCreate($bad2, 'credentials', 'stg') === '');
$bad3 = $ok; $bad3[1] = "  `a` int DEFAULT (SELECT 1),";
t('sub-select in body refused', credentialRestoreBuildStagingCreate($bad3, 'credentials', 'stg') === '');
$bad4 = $ok; $bad4[0] = "CREATE TABLE `credentials` (`a` int) SELECT 1;";
t('first line with tail refused', credentialRestoreBuildStagingCreate($bad4, 'credentials', 'stg') === '');
$dbsql = explode("\n", (string) file_get_contents(__DIR__ . '/../db.sql'));
foreach (['credentials', 'clients'] as $tb) {
    [$cr] = credentialRestoreExtractTable($dbsql, $tb);
    t("real db.sql `$tb` definition still accepted", $cr && credentialRestoreBuildStagingCreate($cr, $tb, 'stg') !== '');
}
exit($fail);
