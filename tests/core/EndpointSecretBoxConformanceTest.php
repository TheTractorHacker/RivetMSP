<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)

require_once __DIR__ . '/EndpointKit.php';

use RivetCore\Rmm\Contracts\SecretBoxInterface;
use RivetCore\Testing\SecretBoxConformanceTestCase;

/**
 * EndpointSecretBox over the REAL encryptSetting()/decryptSetting() of functions.php ("ENC2:" AES-256-GCM, legacy "ENC:" AES-128-CBC read-only, with $config_settings_enc_key). The file needs
 * a whole app bootstrap, so the two functions' source is extracted and defined under other names here (the stand-ins of the other test files use a
 * non-encrypting format). The box must also refuse to store a key in plaintext when the install has no encryption key.
 */
final class EndpointSecretBoxConformanceTest extends SecretBoxConformanceTestCase
{
    public static function setUpBeforeClass(): void
    {
        if (function_exists('ea_conf_encrypt')) {
            return;
        }
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/functions.php');
        foreach (['encryptSetting' => 'ea_conf_encrypt', 'decryptSetting' => 'ea_conf_decrypt'] as $from => $to) {
            if (preg_match('/^function ' . $from . '\(.*?^}/ms', $src, $m) !== 1) {
                self::fail("$from not found in functions.php");
            }
            eval(str_replace('function ' . $from . '(', 'function ' . $to . '(', $m[0]));
        }
        $GLOBALS['config_settings_enc_key'] = bin2hex(random_bytes(32));
    }

    protected function box(): SecretBoxInterface
    {
        return new \RivetMSP\Core\Adapter\Endpoint\EndpointSecretBox(static fn (string $p): string => ea_conf_encrypt($p), static fn (string $c): string => ea_conf_decrypt($c), static fn (): bool => true);
    }

    public function testTheApplicationCiphertextFormatIsUsedAndOtherTextIsNotAKey(): void
    {
        $box = $this->box();
        $this->assertStringStartsWith('ENC2:', $box->encrypt('signing-key-bytes'));
        $this->assertSame('signing-key-bytes', $box->decrypt($box->encrypt('signing-key-bytes')));
        // decryptSetting() hands unprefixed text back as it is (legacy plaintext); a signing key must never be taken from that.
        $this->assertSame('', $box->decrypt('plain legacy text without a prefix'));
        $this->assertSame('', $box->decrypt(''));
    }

    public function testWithoutAnEncryptionKeyItRefusesToStoreAndCannotDecrypt(): void
    {
        $noKey = new \RivetMSP\Core\Adapter\Endpoint\EndpointSecretBox(static fn (string $p): string => $p, static fn (string $c): string => $c, static fn (): bool => false);
        try {
            $noKey->encrypt('private-key');
            $this->fail('A private key must never be stored without the settings encryption key.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('config_settings_enc_key', $e->getMessage());
        }
        $this->assertSame('', $noKey->decrypt($this->box()->encrypt('x')), 'a ciphertext cannot be read without the key');
    }

    public function testAnEncryptionThatReturnsPlaintextIsRejected(): void
    {
        $leaky = new \RivetMSP\Core\Adapter\Endpoint\EndpointSecretBox(static fn (string $p): string => $p, null, static fn (): bool => true);
        $this->expectException(\RuntimeException::class);
        $leaky->encrypt('private-key');
    }

    public function testTheRealFunctionsPathChecksTheGlobalKey(): void
    {
        $saved = $GLOBALS['config_settings_enc_key'] ?? null;
        $GLOBALS['config_settings_enc_key'] = '';
        try {
            $this->assertFalse(\RivetMSP\Core\Adapter\Endpoint\EndpointSecretBox::keyConfigured());
            $GLOBALS['config_settings_enc_key'] = 'k';
            $this->assertTrue(\RivetMSP\Core\Adapter\Endpoint\EndpointSecretBox::keyConfigured());
        } finally {
            $GLOBALS['config_settings_enc_key'] = $saved;
        }
    }
}
