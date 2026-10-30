<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Security;

use Nowo\ApiStudioBundle\Security\SecretValueCipher;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function base64_encode;

final class SecretValueCipherTest extends TestCase
{
    public function testEncryptDecryptRoundTrip(): void
    {
        $cipher = new SecretValueCipher('test-key-material');
        $enc    = $cipher->encrypt('super-secret-token');

        self::assertTrue($cipher->isEncrypted($enc));
        self::assertStringStartsWith(SecretValueCipher::PREFIX, $enc);
        self::assertSame('super-secret-token', $cipher->decrypt($enc));
    }

    public function testDecryptLeavesPlaintextUnchanged(): void
    {
        $cipher = new SecretValueCipher('test-key-material');
        self::assertSame('legacy-plain', $cipher->decrypt('legacy-plain'));
    }

    public function testEncryptIsIdempotentOnAlreadyEncryptedPayload(): void
    {
        $cipher = new SecretValueCipher('test-key-material');
        $enc    = $cipher->encrypt('once');
        self::assertSame($enc, $cipher->encrypt($enc));
    }

    public function testWrongKeyFailsDecrypt(): void
    {
        $a   = new SecretValueCipher('key-a');
        $b   = new SecretValueCipher('key-b');
        $enc = $a->encrypt('token');

        $this->expectException(RuntimeException::class);
        $b->decrypt($enc);
    }

    public function testEmptyKeyMaterialFails(): void
    {
        $this->expectException(RuntimeException::class);
        new SecretValueCipher('');
    }

    public function testInvalidEncryptedPayloadFails(): void
    {
        $cipher = new SecretValueCipher('test-key-material');
        $this->expectException(RuntimeException::class);
        $cipher->decrypt(SecretValueCipher::PREFIX . '@@@');
    }

    public function testTruncatedEncryptedPayloadFails(): void
    {
        $cipher = new SecretValueCipher('test-key-material');
        $this->expectException(RuntimeException::class);
        $cipher->decrypt(SecretValueCipher::PREFIX . base64_encode('short'));
    }

    public function testValueThatOnlyLooksEncryptedIsEncrypted(): void
    {
        // BW audit L4: user input starting with the prefix is not trusted as ciphertext.
        $cipher  = new SecretValueCipher('test-key-material');
        $payload = $cipher->encrypt('nowo_as_enc_v1:not-really-encrypted');

        self::assertNotSame('nowo_as_enc_v1:not-really-encrypted', $payload);
        self::assertSame('nowo_as_enc_v1:not-really-encrypted', $cipher->decrypt($payload));
        self::assertSame($payload, $cipher->encrypt($payload), 'A real payload of this key is not encrypted twice.');
    }

    public function testTryDecryptReturnsNullForForeignPayloads(): void
    {
        $cipher = new SecretValueCipher('test-key-material');

        self::assertNull($cipher->tryDecrypt('nowo_as_enc_v1:garbage'));
        self::assertNull($cipher->tryDecrypt((new SecretValueCipher('other-key'))->encrypt('x')));
        self::assertSame('plain', $cipher->tryDecrypt('plain'));
    }
}
