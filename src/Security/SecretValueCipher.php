<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Security;

use RuntimeException;

use function base64_decode;
use function base64_encode;
use function hash;
use function random_bytes;
use function sodium_crypto_secretbox;
use function sodium_crypto_secretbox_open;
use function str_starts_with;
use function strlen;
use function substr;

use const SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;

/**
 * Encrypts secret environment variable values at rest (sodium secretbox).
 *
 * Plaintext values without the {@see self::PREFIX} are returned unchanged (BC for existing rows).
 */
final class SecretValueCipher
{
    public const PREFIX = 'nowo_as_enc_v1:';

    private readonly string $key;

    public function __construct(string $encryptionKeyMaterial)
    {
        if ($encryptionKeyMaterial === '') {
            throw new RuntimeException('Api Studio secrets encryption key material must not be empty.');
        }

        $this->key = hash('sha256', $encryptionKeyMaterial, true);
    }

    public function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }

    public function encrypt(string $plaintext): string
    {
        if ($this->isEncrypted($plaintext)) {
            return $plaintext;
        }

        $nonce   = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher  = sodium_crypto_secretbox($plaintext, $nonce, $this->key);
        $payload = base64_encode($nonce . $cipher);

        return self::PREFIX . $payload;
    }

    public function decrypt(string $stored): string
    {
        if (!$this->isEncrypted($stored)) {
            return $stored;
        }

        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('Invalid Api Studio encrypted secret payload.');
        }

        $nonce  = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain  = sodium_crypto_secretbox_open($cipher, $nonce, $this->key);
        if ($plain === false) {
            throw new RuntimeException('Failed to decrypt Api Studio secret value (wrong key or corrupt data).');
        }

        return $plain;
    }
}
