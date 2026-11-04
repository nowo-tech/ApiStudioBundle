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

use const SODIUM_CRYPTO_SECRETBOX_MACBYTES;
use const SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;

/**
 * Encrypts secret environment variable values at rest (sodium secretbox).
 *
 * Plaintext values without the {@see self::PREFIX} are returned unchanged (BC for existing rows).
 */
final class SecretValueCipher
{
    public const PREFIX = 'nowo_as_enc_v1:';

    /**
     * Marks, inside the encrypted plaintext, a value that itself looks like a payload (1.11): it reads back as that text
     * instead of being taken for a pre-1.10.1 double encryption.
     */
    public const RAW_MARKER = "\0nowo_as_raw:";

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

    /**
     * True when a decrypted value is itself a well-formed payload (prefix, base64, at least a nonce and a MAC): what
     * releases before 1.10.1 stored when they encrypted an undecryptable payload a second time. A value a user typed
     * that merely starts with the prefix is not one.
     */
    public function isWrappedPayload(string $value): bool
    {
        if (!$this->isEncrypted($value)) {
            return false;
        }

        $raw = base64_decode(substr($value, strlen(self::PREFIX)), true);

        return $raw !== false && strlen($raw) >= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES;
    }

    /**
     * Always a fresh encryption (1.11): never a pass-through, so a stored payload only ever comes from this method. A
     * value that starts with the payload prefix (or the marker) is marked, so {@see self::openStored()} returns it as
     * text.
     */
    public function encryptValue(string $plaintext): string
    {
        if ($this->isEncrypted($plaintext) || str_starts_with($plaintext, self::RAW_MARKER)) {
            $plaintext = self::RAW_MARKER . $plaintext;
        }

        return $this->seal($plaintext);
    }

    /**
     * The value behind a stored column: as is when it is not a payload; the decrypted text (marker removed) when it
     * is; the inner text of a pre-1.10.1 double encryption when the key opens it; null when the key cannot decrypt it
     * (the variable needs re-entry).
     */
    public function openStored(string $stored): ?string
    {
        if (!$this->isEncrypted($stored)) {
            return $stored;
        }

        $plain = $this->tryDecrypt($stored);
        if ($plain === null) {
            return null;
        }
        if (str_starts_with($plain, self::RAW_MARKER)) {
            return substr($plain, strlen(self::RAW_MARKER));
        }

        return $this->isWrappedPayload($plain) ? $this->tryDecrypt($plain) : $plain;
    }

    /**
     * Encrypts a value. A value that already is a payload of this key (the entity value between the flush hooks) is
     * returned as is; any other value — including user input that merely starts with the prefix — is encrypted, so
     * nothing is ever stored in clear and every stored value decrypts.
     */
    public function encrypt(string $plaintext): string
    {
        if ($this->isEncrypted($plaintext) && $this->tryDecrypt($plaintext) !== null) {
            return $plaintext;
        }

        return $this->seal($plaintext);
    }

    private function seal(string $plaintext): string
    {
        $nonce   = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher  = sodium_crypto_secretbox($plaintext, $nonce, $this->key);
        $payload = base64_encode($nonce . $cipher);

        return self::PREFIX . $payload;
    }

    /**
     * Decrypted value, null when the value is not a valid payload of this key.
     */
    public function tryDecrypt(string $stored): ?string
    {
        try {
            return $this->decrypt($stored);
        } catch (RuntimeException) {
            return null;
        }
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
