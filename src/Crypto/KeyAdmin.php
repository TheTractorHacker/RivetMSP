<?php

declare(strict_types=1);

namespace RivetMSP\Crypto;

use RivetCore\Crypto\CryptoException;
use RivetCore\Crypto\KeyFile;
use RivetCore\Crypto\KeyGenerator;
use RivetCore\Crypto\KeyRing;

/**
 * Key file operations, shared by scripts/keys_cli.php and the Keys panel (Administration > Security). Nothing here ever returns key
 * material: kids, fingerprints, dates and counts only.
 *
 * Every change writes the whole file atomically (temp file in the same directory, then rename), keeps the owner and group of the file it
 * replaces (a root run must not lock the web server out of its own keys), and takes a numbered copy of the previous file next to it
 * (`keys.json.bak-<n>`, same mode) so a bad change can be undone by hand. The copies hold key material: they are as secret as the file.
 */
final class KeyAdmin
{
    /** A key older than this many months gets a rotation warning in the panel. */
    public const ROTATION_WARN_MONTHS = 12;

    /**
     * Create the key file for an install that has none. With a legacy $config_settings_enc_key the legacy key becomes kid k1 (so existing
     * ENC2 values, the backup fingerprint and every key derived from config.php carry over); $fresh ignores it and starts from a new
     * random key (the legacy values still open through config.php).
     *
     * @throws \RuntimeException
     */
    public static function generate(?string $path = null, bool $fresh = false, ?string $legacyKey = null): array
    {
        $path ??= KeyStore::path() ?? throw new \RuntimeException('No key file path is configured ($config_keyfile is empty).');
        if (file_exists($path)) {
            throw new \RuntimeException('A key file already exists at ' . $path . '; nothing was changed.');
        }
        $legacy = $legacyKey ?? KeyStore::legacyKey();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $dir = dirname($path);
        if (!is_dir($dir) && function_exists('posix_geteuid') && posix_geteuid() === 0) {
            // As root the directory is created for the operator, readable and traversable by the web server (0755); the file itself is 0640.
            if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new \RuntimeException('Could not create ' . $dir . '.');
            }
            @chmod($dir, 0755);
        }
        try {
            if ($legacy !== '' && !$fresh) {
                $ring = KeyGenerator::ringFromLegacySettingsKey($legacy);
                $ring = KeyRing::fromKeys(['k1' => $ring->key('k1')], 'k1', ['k1' => $now->format('c')]);
                $origin = 'legacy';
            } else {
                $ring = KeyGenerator::newRing($now);
                $origin = 'new';
            }
            KeyFile::write($path, $ring);
        } catch (CryptoException $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }
        KeyStore::reset();
        $fixed = self::secureNewFile($path);

        return ['path' => $path, 'origin' => $origin, 'kid' => (string) $ring->activeKid(), 'fingerprint' => $ring->fingerprint((string) $ring->activeKid()), 'permissions' => $fixed];
    }

    /**
     * After a root run created the key file: hand it to the web server's group with mode 0640, the standard. A run as any other user leaves
     * it as it is (owned by that user, 0640) and says so. Returns what was done, for the CLI to print.
     *
     * @return list<string>
     */
    private static function secureNewFile(string $path): array
    {
        $notes = [];
        @chmod($path, 0640);
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $group = self::webGroup();
            if ($group !== null && @chgrp($path, $group)) {
                $notes[] = 'owner root, group ' . $group . ', mode 0640';
            } else {
                $notes[] = 'No web server group was found (www-data, apache, nginx, http): set the group yourself (chgrp <group> ' . $path . ') or the web server cannot read the key file';
            }
        } else {
            $notes[] = 'owned by the current user, mode 0640: move it to root:www-data when you can (sudo chown root:www-data ' . $path . ')';
        }
        foreach (self::webAccessProblems($path) as $problem) {
            $notes[] = 'WARNING: ' . $problem;
        }

        return $notes;
    }

    /** The name of the web server group on this host (config.php `$config_web_group` wins), or null. */
    public static function webGroup(): ?string
    {
        $cfg = $GLOBALS['config_web_group'] ?? null;
        if (is_string($cfg) && $cfg !== '') {
            return $cfg;
        }
        foreach (['www-data', 'apache', 'nginx', 'http'] as $g) {
            if (function_exists('posix_getgrnam') && posix_getgrnam($g) !== false) {
                return $g;
            }
        }

        return null;
    }

    public static function currentUserName(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $pw = posix_getpwuid(posix_geteuid());
            if (is_array($pw) && isset($pw['name'])) {
                return (string) $pw['name'];
            }
        }

        return 'the web server user';
    }

    /**
     * Whether the web server can reach and read the key file, judged from owner, group and mode bits (so a root run can say what www-data would
     * see). The standard is: every directory on the path traversable by the web user (the key file's own directory 0755), the file 0640
     * root:www-data. $uid and $gid describe the web user (default: the account and group named www-data/apache/..., or the current process
     * when it is not root).
     *
     * @return list<string> human-readable problems with the fix; empty when the web user can read the file
     */
    public static function webAccessProblems(string $path, ?int $uid = null, ?int $gid = null): array
    {
        if ($uid === null || $gid === null) {
            if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
                $uid = posix_geteuid();
                $gid = posix_getegid();
            } else {
                $group = self::webGroup();
                $gi = $group !== null && function_exists('posix_getgrnam') ? posix_getgrnam($group) : false;
                if ($gi === false) {
                    return [];   // nothing to judge against
                }
                $gid = (int) $gi['gid'];
                $pw = function_exists('posix_getpwnam') ? posix_getpwnam($group) : false;
                $uid = $pw !== false ? (int) $pw['uid'] : -1;
            }
        }
        $can = static function (string $p, int $bit) use ($uid, $gid): bool {
            $st = @stat($p);
            if ($st === false) {
                return false;
            }
            $mode = $st['mode'] & 0777;
            if ($st['uid'] === $uid) {
                return ($mode & ($bit << 6)) !== 0;
            }
            if ($st['gid'] === $gid) {
                return ($mode & ($bit << 3)) !== 0;
            }

            return ($mode & $bit) !== 0;
        };
        $problems = [];
        $dir = dirname($path);
        $walk = $dir;
        $blocked = null;
        while ($walk !== '' && $walk !== '/' && $walk !== '.') {
            if (is_dir($walk) && !$can($walk, 1)) {
                $blocked = $walk;
            }
            $walk = dirname($walk);
        }
        if ($blocked !== null) {
            $problems[] = 'The directory ' . $blocked . ' is not traversable by the web server user, so it cannot reach ' . $path . '. Fix: sudo chmod 0755 ' . $blocked . '.';
        }
        if (is_file($path) && !$can($path, 4)) {
            $problems[] = 'The web server user cannot read ' . $path . '. Fix: sudo chown root:' . (self::webGroup() ?? 'www-data') . ' ' . $path . ' && sudo chmod 0640 ' . $path . '.';
        }

        return $problems;
    }

    /**
     * For a NEW installation (setup wizard, setup_cli): create the key file next to the config.php key, with the owner and mode the standard
     * asks for (root:www-data 0640 when this runs as root; otherwise the running user, mode 0640, and a warning). Never throws: an install
     * that cannot write the file continues on the config.php key and is told how to create the file afterwards.
     *
     * @return array{status:'created'|'exists'|'failed'|'skipped', message:string, path:?string}
     */
    public static function createForInstall(string $legacyKey): array
    {
        $path = KeyStore::path();
        if ($path === null) {
            return ['status' => 'skipped', 'message' => 'No key file path is configured ($config_keyfile is empty); the config.php key is used.', 'path' => null];
        }
        if (file_exists($path)) {
            return ['status' => 'exists', 'message' => 'A key file already exists at ' . $path . '.', 'path' => $path];
        }
        $isRoot = function_exists('posix_geteuid') && posix_geteuid() === 0;
        $dir = dirname($path);
        $howTo = 'Create it by hand: sudo php ' . dirname(__DIR__, 2) . '/scripts/keys_cli.php generate --path=' . $path . ' (then chown root:www-data and chmod 0640).';
        try {
            if (!is_dir($dir)) {
                if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                    return ['status' => 'failed', 'message' => 'Could not create ' . $dir . ' (needs root). The config.php key is used for now. ' . $howTo, 'path' => $path];
                }
                @chmod($dir, 0755);   // mkdir() honours the umask; the web server must be able to traverse this directory
            }
            if (!is_writable($dir)) {
                return ['status' => 'failed', 'message' => $dir . ' is not writable by this user. The config.php key is used for now. ' . $howTo, 'path' => $path];
            }
            $r = self::generate($path, false, $legacyKey);
        } catch (\Throwable $e) {
            return ['status' => 'failed', 'message' => 'The key file could not be written (' . $e->getMessage() . '). ' . $howTo, 'path' => $path];
        }
        $note = '';
        if ($isRoot) {
            $group = null;
            foreach (['www-data', 'apache', 'nginx', 'http'] as $g) {
                if (function_exists('posix_getgrnam') && posix_getgrnam($g) !== false) {
                    $group = $g;
                    break;
                }
            }
            if ($group !== null) {
                @chgrp($path, $group);
                @chmod($path, 0640);
            } else {
                $note = ' No web server group was found: set the group of the key file yourself (chgrp <group> ' . $path . ').';
            }
        } else {
            $note = ' It is owned by the installing user, not root: move it to root:www-data 0640 when you can.';
        }

        return ['status' => 'created', 'message' => 'Key file created at ' . $path . ' (kid ' . $r['kid'] . ', fingerprint ' . $r['fingerprint'] . '). Back it up OFFLINE, apart from the database dump.' . $note, 'path' => $path];
    }

    /** Add a new random key. Active at once unless $activate is false. Returns its kid and fingerprint. */
    public static function addKey(bool $activate = true): array
    {
        $state = self::requireFile();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $kid = KeyGenerator::kid($now);
        $ring = $state->ring->withKey(KeyGenerator::key(), $kid, $now->format('c'), $activate);
        self::replace($state, $ring);

        return ['kid' => $kid, 'active' => $activate, 'fingerprint' => $ring->fingerprint($kid)];
    }

    public static function setActive(string $kid): array
    {
        $state = self::requireFile();
        if (!$state->ring->has($kid)) {
            throw new \RuntimeException('There is no key "' . $kid . '" in the ring.');
        }
        self::replace($state, $state->ring->withActive($kid));

        return ['kid' => $kid, 'active' => true];
    }

    /**
     * Remove a non-active key from the file. Refused while any stored value still names it unless $force (then those values become
     * unreadable: restore the key from the offline copy to read them again).
     *
     * @param array<string,int> $usage kid => number of stored values that name it (RewrapService::inventory()['totals'] mapped), or [] to skip the check
     */
    public static function retire(string $kid, array $usage, bool $force = false): array
    {
        $state = self::requireFile();
        if (!$state->ring->has($kid)) {
            throw new \RuntimeException('There is no key "' . $kid . '" in the ring.');
        }
        if ($kid === $state->ring->activeKid()) {
            throw new \RuntimeException('The active key cannot be retired; make another key active and rewrap first.');
        }
        $uses = (int) ($usage[$kid] ?? 0);
        if ($uses > 0 && !$force) {
            throw new \RuntimeException($uses . ' stored value(s) still use key "' . $kid . '"; run the rewrap until it reports 0 rewrapped, then retire it.');
        }
        self::replace($state, $state->ring->without($kid));

        return ['kid' => $kid, 'retired' => true, 'values_left_unreadable' => $uses];
    }

    /**
     * What the status screens show; no key material.
     *
     * @return array{source:string, path:?string, mode:?string, warnings:list<string>, error:?string, active:?string, writable:bool, keys:list<array{kid:string, fingerprint:string, created:?string, active:bool, age_months:?int}>, rotation_due:bool, access_problems:list<string>, legacy_key_present:bool, stage_on:bool, totp_stage_on:bool}
     */
    public static function status(): array
    {
        $state = KeyStore::load();
        $keys = [];
        $due = false;
        foreach ($state->ring->kids() as $kid) {
            $created = $state->ring->createdAt($kid);
            $age = null;
            if ($created !== null) {
                try {
                    $diff = (new \DateTimeImmutable($created))->diff(new \DateTimeImmutable('now'));
                    $age = $diff->y * 12 + $diff->m;
                } catch (\Throwable) {
                    $age = null;
                }
            }
            $isActive = $kid === $state->ring->activeKid();
            if ($isActive && $age !== null && $age >= self::ROTATION_WARN_MONTHS) {
                $due = true;
            }
            $keys[] = ['kid' => $kid, 'fingerprint' => $state->ring->fingerprint($kid), 'created' => $created, 'active' => $isActive, 'age_months' => $age];
        }
        $mode = null;
        $writable = false;
        if ($state->path !== null && is_file($state->path)) {
            $perms = @fileperms($state->path);
            $mode = $perms === false ? null : sprintf('%04o', $perms & 0777);
            $writable = is_writable(dirname($state->path)) && is_writable($state->path);
        }

        $access = [];
        if ($state->unreachable !== null) {
            $access[] = $state->unreachable;
        }
        if ($state->path !== null && ($state->source === 'file' || $state->source === 'file-error' || $state->unreachable !== null)) {
            foreach (self::webAccessProblems($state->path) as $problem) {
                if (!in_array($problem, $access, true)) {
                    $access[] = $problem;
                }
            }
        }

        return [
            'source' => $state->source, 'path' => $state->path, 'mode' => $mode, 'warnings' => $state->warnings, 'error' => $state->error, 'access_problems' => $access,
            'active' => $state->ring->activeKid(), 'writable' => $writable, 'keys' => $keys, 'rotation_due' => $due,
            'legacy_key_present' => KeyStore::legacyKey() !== '', 'stage_on' => SettingsCrypto::v3Enabled(), 'totp_stage_on' => SettingsCrypto::totpV3Enabled(),
        ];
    }

    private static function requireFile(): KeyState
    {
        $state = KeyStore::load();
        if ($state->source !== 'file') {
            throw new \RuntimeException($state->source === 'file-error' ? (string) $state->error : 'There is no key file yet; create it first (php scripts/keys_cli.php generate).');
        }
        if ($state->path === null || !is_writable(dirname($state->path))) {
            throw new \RuntimeException('The directory of the key file is not writable by this user; run scripts/keys_cli.php as the file owner (root) instead.');
        }

        return $state;
    }

    private static function replace(KeyState $state, KeyRing $ring): void
    {
        $path = (string) $state->path;
        $st = @stat($path);
        $n = 1;
        while (file_exists($path . '.bak-' . $n)) {
            $n++;
        }
        $bak = $path . '.bak-' . $n;
        $mode = $st === false ? 0640 : ($st['mode'] & 0777);
        if (!@copy($path, $bak)) {
            throw new \RuntimeException('Could not take a backup copy of the key file; nothing was changed.');
        }
        @chmod($bak, $mode);
        try {
            KeyFile::write($path, $ring, true, $mode);
        } catch (CryptoException $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }
        if ($st !== false) {
            // A root run creates the new file as root; keep the previous owner and group so the web server can still read it.
            if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
                @chown($path, $st['uid']);
                @chown($bak, $st['uid']);
            }
            @chgrp($path, $st['gid']);
            @chgrp($bak, $st['gid']);
        }
        KeyStore::reset();
    }
}
