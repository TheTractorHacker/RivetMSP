<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Static guards for bugs the browser smoke suite found: duplicate seed rows, inline handlers the CSP blocks, schema version drift. */
final class InstallArtifactsTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    public function testDbSqlSeedsEachSavedTicketViewOnce(): void
    {
        $sql = (string) file_get_contents(self::root() . '/db.sql');
        preg_match_all("/INSERT INTO `ticket_saved_views` VALUES \\(\\d+,'((?:[^'\\\\]|\\\\.)*)','[^']*','((?:[^'\\\\]|\\\\.)*)',(\\d+),/", $sql, $m, PREG_SET_ORDER);
        $this->assertNotEmpty($m, 'saved view seed rows not found');
        $seen = [];
        foreach ($m as $row) {
            $key = $row[1] . '|' . $row[2] . '|' . $row[3];
            $this->assertArrayNotHasKey($key, $seen, "saved view '{$row[1]}' is seeded twice in db.sql");
            $seen[$key] = true;
        }
    }

    public function testLatestDatabaseVersionHasAMigrationStep(): void
    {
        $version = (string) preg_replace('/^.*LATEST_DATABASE_VERSION"\s*,\s*"([0-9.]+)".*$/s', '$1', (string) file_get_contents(self::root() . '/includes/database_version.php'));
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $version);
        $updates = (string) file_get_contents(self::root() . '/admin/database_updates.php');
        $this->assertStringContainsString("config_current_database_version` = '$version'", $updates, 'no migration step ends at the latest version');
    }

    public function testSavedViewDedupeMigrationOnlyRemovesExactDuplicates(): void
    {
        $updates = (string) file_get_contents(self::root() . '/admin/database_updates.php');
        $this->assertSame(1, preg_match("/== '2\\.6\\.74'\\) \\{(.*?)'2\\.6\\.75'/s", $updates, $m));
        foreach (['name', 'icon', 'query', 'user_id', 'order'] as $col) {
            $this->assertStringContainsString("k.`ticket_saved_view_$col` = d.`ticket_saved_view_$col`", $m[1]);
        }
        $this->assertStringContainsString('k.`ticket_saved_view_id` < d.`ticket_saved_view_id`', $m[1], 'keeps the lowest id');
        $this->assertStringContainsString('<=>', $m[1], 'archive state must match too');
    }

    /** script-src has no 'unsafe-inline': an on*="..." attribute in a template never runs. */
    public function testNoInlineEventHandlerAttributesInTemplates(): void
    {
        $offenders = [];
        foreach (['agent', 'admin', 'client', 'includes', 'modals', 'guest', 'setup'] as $dir) {
            $path = self::root() . '/' . $dir;
            if (!is_dir($path)) {
                continue;
            }
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->getExtension() !== 'php') {
                    continue;
                }
                foreach (file($f->getPathname()) as $n => $line) {
                    if (preg_match('/<[a-z][^>]*\son(?:click|change|submit|focusout|focus|blur|keyup|keydown|input|load|error|mouseover|mouseout)\s*=\s*["\']/i', $line)
                        || str_contains($line, 'href=\'javascript:') || str_contains($line, 'href="javascript:')) {
                        $offenders[] = substr($f->getPathname(), strlen(self::root()) + 1) . ':' . ($n + 1);
                    }
                }
            }
        }
        $this->assertSame([], $offenders, 'inline handlers are blocked by the Content-Security-Policy; use data-* attributes + js/app.js delegation');
    }
}
