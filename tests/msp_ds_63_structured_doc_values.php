<?php
// MSP-DS-63: structured doc text is stored with one level of escaping and secrets are encrypted as typed. Run:
//   SCRATCH_TABLES="structured_doc_fields structured_document_values" bash tests/lib/with_scratch_db.sh php tests/msp_ds_63_structured_doc_values.php
require __DIR__ . '/lib/ds_src.php';
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { echo "scratch DB only\n"; exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$mysqli->query("SET SESSION sql_mode=''"); $mysqli->query("SET FOREIGN_KEY_CHECKS=0");
function sanitizeInput($i) { global $mysqli; return mysqli_real_escape_string($mysqli, trim(strip_tags((string) $i))); }
function encryptSetting($v) { return 'ENC:' . base64_encode($v); }
function rivetAudit(...$a) {}
define('FROM_POST_HANDLER', 1);
$src = ds_src('agent/post/structured_doc.php');
$i = strpos($src, 'function structuredDocSaveValues'); $j = strpos($src, "\n}\n", $i) + 2;
eval(substr($src, $i, $j - $i));
$mysqli->query("INSERT INTO structured_doc_fields (field_id, field_type_id, field_key, field_kind, field_label) VALUES (1, 1, 'n', 'text', 'n'), (2, 1, 'p', 'password', 'p')");
$pw = "Pa\"ss<1>w'd\\x ";
structuredDocSaveValues($mysqli, 5, 1, ['field_1' => "O'Brien \\ <b>x</b>", 'field_2' => $pw], 1);
$t = $mysqli->query("SELECT value_text FROM structured_document_values WHERE field_id = 1")->fetch_row()[0];
ds_t("text stored once-escaped: [$t]", $t === "O'Brien \\ x");
$e = $mysqli->query("SELECT value_enc FROM structured_document_values WHERE field_id = 2")->fetch_row()[0];
ds_t('password stored exactly as typed', $e === 'ENC:' . base64_encode($pw));
ds_done();
