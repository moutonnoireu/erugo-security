<?php

namespace App\Services;

use RuntimeException;

/**
 * Optional encryption at rest of shared files (libsodium XChaCha20-Poly1305).
 *
 * The key is never stored by Erugo: it comes from the STORAGE_ENCRYPTION_KEY
 * environment variable (32 random bytes, base64), so a copy of the storage
 * volume or of a backup is useless without it. Losing the key means losing
 * the encrypted files.
 *
 * File format (all integers big-endian):
 *   header  "ERUGOENC" | version u8 (1) | 3 reserved bytes | chunk size u32
 *           | plaintext size u64 | nonce prefix (16) | key id (8)       = 48 bytes
 *   chunk i AEAD(plaintext[i], nonce = prefix || u64 i, aad = header || u64 i)
 *
 * The header is authenticated with every chunk, the chunk index prevents
 * reordering, and the plaintext size in the header fixes the chunk count, so
 * truncation is detected. Chunks are independent, which allows random access
 * (HTTP range requests, reading one file out of a share archive).
 */
class StorageEncryption
{
    public const SETTING_ENABLED = 'storage_encryption_enabled';
    public const ENV_KEY = 'STORAGE_ENCRYPTION_KEY';

    public const MAGIC = 'ERUGOENC';
    public const VERSION = 1;
    public const HEADER_SIZE = 48;
    public const CHUNK_SIZE = 1048576; // 1 MiB of plaintext per chunk
    public const TAG_SIZE = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES;

    private ?string $key = null;

    public function __construct(private SettingsService $settings)
    {
    }

    /** A valid key is configured in the environment. */
    public function hasKey(): bool
    {
        return $this->key() !== null;
    }

    /** The admin setting is on but no valid key is configured. */
    public function isMisconfigured(): bool
    {
        return !$this->hasKey() && $this->settings->get(self::SETTING_ENABLED) === true;
    }

    /** New shares are encrypted (admin setting, and a key is available). */
    public function isEnabled(): bool
    {
        return $this->hasKey() && $this->settings->get(self::SETTING_ENABLED) === true;
    }

    public static function isEncrypted(string $path): bool
    {
        if (!is_file($path)) {
            return false;
        }

        $handle = @fopen($path, 'rb');
        if (!$handle) {
            return false;
        }
        $magic = fread($handle, strlen(self::MAGIC));
        fclose($handle);

        return $magic === self::MAGIC;
    }

    /**
     * Encrypt a file in place (written to a temporary file, then renamed).
     * Already encrypted files are left alone.
     */
    public function encryptFile(string $path): void
    {
        if (self::isEncrypted($path)) {
            return;
        }

        $key = $this->requireKey();
        clearstatcache(true, $path);
        $size = filesize($path);
        if ($size === false) {
            throw new RuntimeException('Cannot read file size: ' . $path);
        }

        $header = self::MAGIC
            . chr(self::VERSION) . "\0\0\0"
            . pack('N', self::CHUNK_SIZE)
            . pack('J', $size)
            . random_bytes(16)
            . $this->keyId($key);
        $prefix = substr($header, 24, 16);

        $tmp = $path . '.enc-tmp';
        $in = fopen($path, 'rb');
        $out = fopen($tmp, 'wb');
        if (!$in || !$out) {
            throw new RuntimeException('Cannot open file for encryption: ' . $path);
        }

        try {
            fwrite($out, $header);
            $chunks = self::chunkCount($size);
            for ($i = 0; $i < $chunks; $i++) {
                $plain = self::readExactly($in, min(self::CHUNK_SIZE, $size - $i * self::CHUNK_SIZE));
                $index = pack('J', $i);
                $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plain, $header . $index, $prefix . $index, $key);
                if (fwrite($out, $cipher) !== strlen($cipher)) {
                    throw new RuntimeException('Write failed while encrypting ' . $path);
                }
            }
            fflush($out);
        } catch (\Throwable $e) {
            fclose($in);
            fclose($out);
            @unlink($tmp);
            throw $e;
        }

        fclose($in);
        fclose($out);

        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Cannot replace file with its encrypted version: ' . $path);
        }
    }

    /**
     * Decrypt a file in place (used to turn encryption off).
     */
    public function decryptFile(string $path): void
    {
        if (!self::isEncrypted($path)) {
            return;
        }

        $reader = $this->open($path);
        $tmp = $path . '.dec-tmp';
        $out = fopen($tmp, 'wb');
        try {
            foreach ($reader->chunks(0, $reader->size()) as $data) {
                fwrite($out, $data);
            }
        } catch (\Throwable $e) {
            fclose($out);
            @unlink($tmp);
            throw $e;
        }
        fclose($out);
        $reader->close();

        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Cannot replace file with its decrypted version: ' . $path);
        }
    }

    public function open(string $path): EncryptedFileReader
    {
        return new EncryptedFileReader($path, $this->requireKey(), fn (string $key) => $this->keyId($key));
    }

    public static function chunkCount(int $size): int
    {
        // An empty file still has one (empty) authenticated chunk.
        return max(1, intdiv($size + self::CHUNK_SIZE - 1, self::CHUNK_SIZE));
    }

    public static function encryptedSize(int $size): int
    {
        return self::HEADER_SIZE + $size + self::chunkCount($size) * self::TAG_SIZE;
    }

    /**
     * Generate a key suitable for STORAGE_ENCRYPTION_KEY.
     */
    public static function generateKey(): string
    {
        return base64_encode(random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES));
    }

    private function key(): ?string
    {
        if ($this->key !== null) {
            return $this->key;
        }

        $raw = (string) config('app.storage_encryption_key', '');
        if (str_starts_with($raw, 'base64:')) {
            $raw = substr($raw, 7);
        }

        $decoded = base64_decode(trim($raw), true);
        if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            return null;
        }

        // Use a sub-key dedicated to file encryption.
        return $this->key = sodium_crypto_kdf_derive_from_key(
            SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES,
            1,
            'erugofil',
            $decoded
        );
    }

    private function requireKey(): string
    {
        $key = $this->key();
        if ($key === null) {
            throw new RuntimeException('STORAGE_ENCRYPTION_KEY is missing or invalid (expected 32 bytes, base64).');
        }

        return $key;
    }

    private function keyId(string $key): string
    {
        return substr(hash('sha256', 'erugo-key-id' . $key, true), 0, 8);
    }

    /** @param resource $handle */
    public static function readExactly($handle, int $length): string
    {
        $data = '';
        while (strlen($data) < $length && !feof($handle)) {
            $part = fread($handle, $length - strlen($data));
            if ($part === false || $part === '') {
                break;
            }
            $data .= $part;
        }

        if (strlen($data) !== $length) {
            throw new RuntimeException('Unexpected end of file');
        }

        return $data;
    }
}
