<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP
/*
 * scripts/vendor-fixups/sync-rmm-postgres-cli-scripts.sh (deploy/update.sh's sync_rmm_postgres_cli_scripts(), run right after every
 * `composer install`/update there): rivet/rivet-core's dist install strips 5 RMM PostgreSQL operator scripts via its own .gitattributes
 * export-ignore rule (github.com/TheTractorHacker/rivet-core#90) - this proves the recovery script actually restores them, by first
 * deleting them (simulating what a fresh `composer install` does) and then running the sync script for real, against the real GitHub
 * remote (no mock: there is nothing meaningful to mock here - either it can reach GitHub and pull the right commit, or it can't).
 * The repository's own committed copies are restored afterward, whatever the test downloads.
 *
 *   php tests/rmm_postgres_cli_scripts_sync.php
 *
 * Needs network access to github.com/TheTractorHacker/rivet-core (the same access composer itself already needs for this dependency).
 */
$root = dirname(__DIR__);
$dir = "$root/vendor/rivet/rivet-core/scripts";
$files = ['rmm-postgres.php', 'rmm-store-migrate.php', 'rmm-store-verify.php', 'rmm-postgres-backup.sh', 'rmm-postgres-restore.sh'];

$fails = 0; $passes = 0;
$ok = function (bool $c, string $l) use (&$fails, &$passes): void { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; $c ? $passes++ : $fails++; };
function sh(string $cmd): array { exec($cmd . ' 2>&1', $out, $code); return [$code, implode("\n", $out)]; }

if (!is_dir($dir) || !is_file("$root/composer.lock")) {
    fwrite(STDERR, "run this from a checkout with vendor/ already installed (composer install first).\n");
    exit(2);
}

// Back up the real, committed copies (content AND mode - rmm-postgres.php is committed 755, rmm-store-migrate.php/-verify.php
// 644, despite all three having a #!/usr/bin/env php shebang; an upstream inconsistency, not this test's to fix) so it can put
// them back exactly as they were, regardless of what the sync script downloads.
$backup = sys_get_temp_dir() . '/rmm_pg_scripts_backup_' . bin2hex(random_bytes(4));
mkdir($backup, 0700);
$modes = [];
register_shutdown_function(function () use ($backup, $dir, $files, &$modes): void {
    foreach ($files as $f) {
        if (is_file("$backup/$f")) {
            copy("$backup/$f", "$dir/$f");
            if (isset($modes[$f])) {
                @chmod("$dir/$f", $modes[$f]);
            }
        }
    }
    foreach (glob("$backup/*") ?: [] as $f) { @unlink($f); }
    @rmdir($backup);
});
$hadAll = true;
foreach ($files as $f) {
    if (is_file("$dir/$f")) {
        copy("$dir/$f", "$backup/$f");
        $modes[$f] = fileperms("$dir/$f") & 0777;
    } else {
        $hadAll = false;
    }
}
$ok($hadAll, 'precondition: this checkout already has all 5 scripts (Part B of the data-store-phase-4 work recovered them) - the test can restore them exactly afterward');

// Simulate what a fresh `composer install` does: delete the 5 files the export-ignore rule strips.
foreach ($files as $f) {
    @unlink("$dir/$f");
}
$ok(!is_file("$dir/{$files[0]}"), 'the 5 scripts were removed (simulating a fresh composer dist install)');

[$rc, $out] = sh('bash ' . escapeshellarg("$root/scripts/vendor-fixups/sync-rmm-postgres-cli-scripts.sh"));
$ok($rc === 0, 'sync-rmm-postgres-cli-scripts.sh exits 0 (' . $out . ')');

$allPresent = true;
$allNonEmpty = true;
foreach ($files as $f) {
    $path = "$dir/$f";
    if (!is_file($path)) {
        $allPresent = false;
        continue;
    }
    if (filesize($path) === 0) {
        $allNonEmpty = false;
    }
}
$ok($allPresent, 'all 5 scripts are present after the sync (' . implode(', ', $files) . ')');
$ok($allNonEmpty, 'none of the restored scripts are empty');

[$rcPhp1, ] = sh('php -l ' . escapeshellarg("$dir/rmm-postgres.php"));
[$rcPhp2, ] = sh('php -l ' . escapeshellarg("$dir/rmm-store-migrate.php"));
[$rcPhp3, ] = sh('php -l ' . escapeshellarg("$dir/rmm-store-verify.php"));
$ok($rcPhp1 === 0 && $rcPhp2 === 0 && $rcPhp3 === 0, 'the 3 restored PHP scripts are syntactically valid');
[$rcSh1, ] = sh('bash -n ' . escapeshellarg("$dir/rmm-postgres-backup.sh"));
[$rcSh2, ] = sh('bash -n ' . escapeshellarg("$dir/rmm-postgres-restore.sh"));
$ok($rcSh1 === 0 && $rcSh2 === 0, 'the 2 restored shell scripts are syntactically valid');

// Running it again (nothing missing this time) must still succeed and leave the same 5 files in place - idempotent.
[$rc2, $out2] = sh('bash ' . escapeshellarg("$root/scripts/vendor-fixups/sync-rmm-postgres-cli-scripts.sh"));
$ok($rc2 === 0, 'running the sync script again (nothing missing) is still a clean success, not an error (' . $out2 . ')');

echo "\n$passes passed, $fails failed\n";
exit($fails > 0 ? 1 : 0);
