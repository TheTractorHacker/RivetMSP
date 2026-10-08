<?php

declare(strict_types=1);

namespace RivetMSP\Core\Adapter\Endpoint;

use RivetCore\Rmm\Contracts\SecretBoxInterface;

/**
 * RivetMSP's settings encryption (encryptSetting / decryptSetting in functions.php, "ENC:" AES-128-CBC with the key
 * $config_settings_enc_key from config.php) for the RMM module's Ed25519 signing key and MeshCentral login key.
 *
 * Two differences from calling those functions directly, both because these are signing keys and not an SMTP password:
 *  - encrypt() REFUSES to run without $config_settings_enc_key. encryptSetting() silently returns the plaintext in that case, which would
 *    store the private signing key readable in the database.
 *  - decrypt() returns '' (not available) for anything that is not an "ENC:" ciphertext, and when no key is configured. decryptSetting()
 *    hands such text back as it is (legacy plaintext), and a key must never be taken from text that is not a ciphertext.
 */
final class EndpointSecretBox implements SecretBoxInterface
{
    /**
     * @param (\Closure(string):string)|null $encrypt defaults to encryptSetting(); a seam for the conformance test
     * @param (\Closure(string):string)|null $decrypt defaults to decryptSetting()
     * @param (\Closure():bool)|null $keyConfigured defaults to "$config_settings_enc_key is not empty"
     */
    public function __construct(private ?\Closure $encrypt = null, private ?\Closure $decrypt = null, private ?\Closure $keyConfigured = null)
    {
    }

    /** True when the install has a settings encryption key (the RMM module cannot be switched on without one). */
    public static function keyConfigured(): bool
    {
        return !empty($GLOBALS['config_settings_enc_key']);
    }

    public function encrypt(string $plaintext): string
    {
        if (!$this->hasKey()) {
            throw new \RuntimeException('The settings encryption key ($config_settings_enc_key in config.php) is not set, so the RMM signing key cannot be stored safely.');
        }
        $out = $this->encrypt !== null ? ($this->encrypt)($plaintext) : encryptSetting($plaintext);
        if ($plaintext !== '' && !str_starts_with($out, 'ENC:')) {
            throw new \RuntimeException('The settings encryption did not produce a ciphertext.');
        }

        return $out;
    }

    public function decrypt(string $ciphertext): string
    {
        if (!str_starts_with($ciphertext, 'ENC:') || !$this->hasKey()) {
            return '';
        }
        try {
            return $this->decrypt !== null ? ($this->decrypt)($ciphertext) : decryptSetting($ciphertext);
        } catch (\Throwable) {
            return '';
        }
    }

    private function hasKey(): bool
    {
        return $this->keyConfigured !== null ? ($this->keyConfigured)() : self::keyConfigured();
    }
}
