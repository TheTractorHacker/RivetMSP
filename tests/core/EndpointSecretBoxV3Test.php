<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/Core/Adapter/Endpoint/EndpointSecretBox.php';

/**
 * The RMM signing key and the Mesh login key are read through EndpointSecretBox. Once RivetMSP's settings move to the RivetCore envelope those values
 * start with "v3:": the box must hand them to decryptSetting() like ENC2:/ENC:, and still refuse anything that is not a ciphertext (a cleartext key must
 * never be taken as a signing key). Ported from RivetIT 26.10.37 (the box returned '' for every v3: value after the settings rewrap there). RivetMSP's own
 * decryptSetting() does not know "v3:" yet and would hand the text back unchanged: that must read as "not available", never as a key.
 */
final class EndpointSecretBoxV3Test extends TestCase
{
    public function testEveryCiphertextPrefixIsHandedToTheDecryptor(): void
    {
        $seen = [];
        $box = new \RivetMSP\Core\Adapter\Endpoint\EndpointSecretBox(null, static function (string $c) use (&$seen): string {
            $seen[] = $c;
            return 'plain';
        }, static fn (): bool => true);
        foreach (['v3:k1:AAAA', 'ENC2:AAAA', 'ENC:AAAA'] as $c) {
            $this->assertSame('plain', $box->decrypt($c), $c);
        }
        $this->assertCount(3, $seen);
    }

    public function testClearTextAndGarbageAreNeverTreatedAsKeys(): void
    {
        $called = 0;
        $box = new \RivetMSP\Core\Adapter\Endpoint\EndpointSecretBox(null, static function (string $c) use (&$called): string {
            $called++;
            return 'plain';
        }, static fn (): bool => true);
        foreach (['', 'plaintextkey', 'V3:k1:AAAA', ' v3:k1:AAAA', 'enc2:AAAA'] as $c) {
            $this->assertSame('', $box->decrypt($c), var_export($c, true));
        }
        $this->assertSame(0, $called);
    }

    public function testADecryptorThatThrowsMeansNotAvailable(): void
    {
        $box = new \RivetMSP\Core\Adapter\Endpoint\EndpointSecretBox(null, static function (string $c): string {
            throw new \RuntimeException('no key');
        }, static fn (): bool => true);
        $this->assertSame('', $box->decrypt('v3:k1:AAAA'));
    }

    public function testADecryptorThatReturnsItsInputIsNotAKey(): void
    {
        // decryptSetting() of this edition returns an unknown prefix as it is (legacy plaintext): a "v3:" value would otherwise become the signing key text
        $box = new \RivetMSP\Core\Adapter\Endpoint\EndpointSecretBox(null, static fn (string $c): string => $c, static fn (): bool => true);
        foreach (['v3:k1:AAAA', 'ENC2:AAAA', 'ENC:AAAA'] as $c) {
            $this->assertSame('', $box->decrypt($c), $c);
        }
    }

    public function testWithoutAKeyNothingIsOpenedEvenWithAV3Prefix(): void
    {
        $box = new \RivetMSP\Core\Adapter\Endpoint\EndpointSecretBox(null, static fn (string $c): string => 'plain', static fn (): bool => false);
        $this->assertSame('', $box->decrypt('v3:k1:AAAA'));
    }

    public function testAV3CiphertextFromTheEncryptorIsAccepted(): void
    {
        $box = new \RivetMSP\Core\Adapter\Endpoint\EndpointSecretBox(static fn (string $p): string => 'v3:k1:' . base64_encode($p), static fn (string $c): string => base64_decode(substr($c, 6)), static fn (): bool => true);
        $this->assertSame('signing', $box->decrypt($box->encrypt('signing')));
    }
}
