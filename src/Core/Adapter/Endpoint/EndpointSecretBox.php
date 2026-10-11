<?php

declare(strict_types=1);

namespace RivetMSP\Core\Adapter\Endpoint;

use RivetCore\Rmm\Contracts\SecretBoxInterface;

/**
 * RivetMSP's settings encryption (encryptSetting / decryptSetting in functions.php: "v3:" RivetCore envelope once the key file exists, else "ENC2:" AES-256-GCM; legacy "ENC:"
 * AES-128-CBC read-only; keys from the key file and/or $config_settings_enc_key in config.php) for the RMM module's Ed25519 signing key and MeshCentral login key.
 *
 * Two differences from calling those functions directly, both because these are signing keys and not an SMTP password:
 *  - encrypt() REFUSES to run without $config_settings_enc_key (encryptSetting() now fails closed too; this guard keeps the clearer message
 *    and the unit-test seam).
 *  - decrypt() returns '' (not available) for anything that is not a "v3:" (the RivetCore envelope RivetMSP will adopt for settings), "ENC2:" or "ENC:"
 *    ciphertext, and when no key is configured. decryptSetting() hands such text back as it is (legacy plaintext), and a key must never be taken from
 *    text that is not a ciphertext. For the same reason a decryptor that gives back exactly what it was given (decryptSetting() does for a "v3:" value
 *    until RivetMSP's settings move to the envelope) is "not available", never a key.
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
        return !empty($GLOBALS['config_settings_enc_key'])
            || (class_exists(\RivetMSP\Crypto\KeyStore::class) && \RivetMSP\Crypto\KeyStore::load()->usable());
    }

    public function encrypt(string $plaintext): string
    {
        if (!$this->hasKey()) {
            throw new \RuntimeException('The settings encryption key ($config_settings_enc_key in config.php) is not set, so the RMM signing key cannot be stored safely.');
        }
        $out = $this->encrypt !== null ? ($this->encrypt)($plaintext) : encryptSetting($plaintext);
        if ($plaintext !== '' && !self::isCiphertext($out)) {
            throw new \RuntimeException('The settings encryption did not produce a ciphertext.');
        }

        return $out;
    }

    public function decrypt(string $ciphertext): string
    {
        if (!self::isCiphertext($ciphertext) || !$this->hasKey()) {
            return '';
        }
        try {
            $plain = $this->decrypt !== null ? ($this->decrypt)($ciphertext) : decryptSetting($ciphertext);

            return $plain === $ciphertext ? '' : $plain;
        } catch (\Throwable) {
            return '';
        }
    }

    private static function isCiphertext(string $value): bool
    {
        return str_starts_with($value, 'v3:') || str_starts_with($value, 'ENC2:') || str_starts_with($value, 'ENC:');
    }

    private function hasKey(): bool
    {
        return $this->keyConfigured !== null ? ($this->keyConfigured)() : self::keyConfigured();
    }
}
