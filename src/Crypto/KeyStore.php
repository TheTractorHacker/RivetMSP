<?php

declare(strict_types=1);

namespace RivetMSP\Crypto;

use RivetCore\Crypto\CryptoException;
use RivetCore\Crypto\KeyGenerator;
use RivetCore\Crypto\KeyRing;
use RivetCore\Crypto\KeyRingLoader;

/**
 * Where RivetMSP finds its key ring (ADR-011): the key file when one exists, otherwise the legacy
 * $config_settings_enc_key from config.php as kid "k1", otherwise nothing.
 *
 * Key file path, first match wins:
 *   1. $config_keyfile in config.php (a string; '' switches the file lookup off)
 *   2. the RIVETMSP_KEYFILE constant or environment variable
 *   3. /etc/rivetmsp/keys.json
 *
 * Nothing here ever creates a file. An existing key file that cannot be used is an ERROR state, never silently replaced by the legacy key
 * (that would hide a broken deployment); reads of values that the legacy key alone can open keep working, everything else fails closed.
 *
 * The result is cached per process, keyed on the file's mtime and size and on the legacy key, so a key file written by a CLI run, or a
 * test that switches the configuration, is seen by the next call without a restart.
 */
final class KeyStore
{
    public const DEFAULT_PATH = '/etc/rivetmsp/keys.json';

    /** @var array{sig:string, state:KeyState}|null */
    private static ?array $cache = null;

    /** The key file path in effect, or null when the file lookup is switched off. */
    public static function path(): ?string
    {
        if (array_key_exists('config_keyfile', $GLOBALS)) {
            $p = $GLOBALS['config_keyfile'];

            return is_string($p) && $p !== '' ? $p : null;
        }
        if (defined('RIVETMSP_KEYFILE') && is_string(RIVETMSP_KEYFILE) && RIVETMSP_KEYFILE !== '') {
            return RIVETMSP_KEYFILE;
        }
        $env = getenv('RIVETMSP_KEYFILE');
        if (is_string($env) && $env !== '') {
            return $env;
        }

        return self::DEFAULT_PATH;
    }

    /** $config_settings_enc_key from config.php ('' when absent). */
    public static function legacyKey(): string
    {
        $k = $GLOBALS['config_settings_enc_key'] ?? '';

        return is_string($k) ? $k : '';
    }

    public static function reset(): void
    {
        self::$cache = null;
    }

    public static function load(): KeyState
    {
        $path = self::path();
        $legacy = self::legacyKey();
        clearstatcache();
        $exists = $path !== null && is_file($path);
        $sig = ($path ?? '-') . '|' . ($exists ? (string) @filemtime($path) . ':' . (string) @filesize($path) : 'absent') . '|' . hash('sha256', $legacy);
        if (self::$cache !== null && self::$cache['sig'] === $sig) {
            return self::$cache['state'];
        }
        $state = self::build($path, $exists, $legacy);
        if (!$exists && $path !== null) {
            $why = self::unreachableReason($path);
            if ($why !== null) {
                // The directory exists but this user cannot traverse it, so a key file in it cannot be seen. Not an error (an install on the
                // config.php key alone is fine), but never silent: the status screens say it, and a v3 value then reads as unavailable.
                $state = new KeyState($state->ring, $state->source, $state->path, $state->warnings, $state->error, $why);
            }
        }
        self::$cache = ['sig' => $sig, 'state' => $state];

        return $state;
    }

    /** Why a key file at $path cannot be seen by this process even though its directory exists (null when it can, or the directory is absent). */
    private static function unreachableReason(string $path): ?string
    {
        $dir = dirname($path);
        if (!@is_dir($dir) || @is_executable($dir)) {
            return null;
        }

        return 'The directory ' . $dir . ' exists but this user (' . KeyAdmin::currentUserName() . ') cannot traverse it, so a key file there cannot be seen. '
            . 'Make the directory 0755 (sudo chmod 0755 ' . $dir . ') and the file root:www-data 0640.';
    }

    private static function build(?string $path, bool $exists, string $legacy): KeyState
    {
        if ($exists && $path !== null) {
            try {
                $res = (new KeyRingLoader(null, false, dirname(__DIR__, 2)))->fromFile($path);

                return new KeyState($res->ring, 'file', $path, $res->warnings, null);
            } catch (CryptoException $e) {
                $hint = @is_readable($path) ? '' : ' This user (' . KeyAdmin::currentUserName() . ') has no read permission on it: sudo chown root:www-data ' . $path . ' && sudo chmod 0640 ' . $path . '.';

                return new KeyState(KeyRing::empty(), 'file-error', $path, [], 'The key file ' . $path . ' cannot be used: ' . $e->getMessage() . $hint);
            } catch (\Throwable $e) {
                $hint = @is_readable($path) ? '' : ' This user (' . KeyAdmin::currentUserName() . ') has no read permission on it: sudo chown root:www-data ' . $path . ' && sudo chmod 0640 ' . $path . '.';

                return new KeyState(KeyRing::empty(), 'file-error', $path, [], 'The key file ' . $path . ' cannot be read (' . get_class($e) . ').' . $hint);
            }
        }
        if ($legacy !== '') {
            try {
                return new KeyState(KeyGenerator::ringFromLegacySettingsKey($legacy), 'legacy', $path, [], null);
            } catch (CryptoException $e) {
                return new KeyState(KeyRing::empty(), 'legacy-error', $path, [], 'config.php $config_settings_enc_key is not usable: ' . $e->getMessage());
            }
        }

        return new KeyState(KeyRing::empty(), 'none', $path, [], null);
    }
}
