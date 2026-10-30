<?php

namespace App\Services;

use RuntimeException;

/**
 * Random-access reader for files written by StorageEncryption.
 * Every chunk is authenticated before any of its bytes are returned.
 */
class EncryptedFileReader
{
    /** @var resource */
    private $handle;
    private string $header;
    private string $prefix;
    private int $chunkSize;
    private int $size;

    private ?int $cachedIndex = null;
    private string $cachedChunk = '';

    /**
     * @param callable(string): string $keyId
     */
    public function __construct(private string $path, private string $key, callable $keyId)
    {
        $handle = @fopen($path, 'rb');
        if (!$handle) {
            throw new RuntimeException('Cannot open encrypted file: ' . $path);
        }
        $this->handle = $handle;

        $header = fread($handle, StorageEncryption::HEADER_SIZE);
        if ($header === false || strlen($header) !== StorageEncryption::HEADER_SIZE || !str_starts_with($header, StorageEncryption::MAGIC)) {
            throw new RuntimeException('Not an encrypted Erugo file: ' . $path);
        }
        if (ord($header[8]) !== StorageEncryption::VERSION) {
            throw new RuntimeException('Unsupported encrypted file version');
        }
        if (!hash_equals(substr($header, 40, 8), $keyId($key))) {
            throw new RuntimeException('File was encrypted with another STORAGE_ENCRYPTION_KEY');
        }

        $this->header = $header;
        $this->chunkSize = unpack('N', substr($header, 12, 4))[1];
        $this->size = unpack('J', substr($header, 16, 8))[1];
        $this->prefix = substr($header, 24, 16);

        if ($this->chunkSize < 1) {
            throw new RuntimeException('Invalid chunk size');
        }

        $expected = StorageEncryption::HEADER_SIZE + $this->size
            + max(1, intdiv($this->size + $this->chunkSize - 1, $this->chunkSize)) * StorageEncryption::TAG_SIZE;
        clearstatcache(true, $path);
        if (filesize($path) !== $expected) {
            throw new RuntimeException('Encrypted file is truncated or corrupted');
        }
    }

    public function size(): int
    {
        return $this->size;
    }

    /**
     * Read $length plaintext bytes starting at $offset.
     */
    public function read(int $offset, int $length): string
    {
        $data = '';
        foreach ($this->chunks($offset, $length) as $part) {
            $data .= $part;
        }

        return $data;
    }

    /**
     * Yield the plaintext between $offset and $offset + $length, chunk by chunk.
     *
     * @return \Generator<string>
     */
    public function chunks(int $offset, int $length): \Generator
    {
        if ($offset < 0 || $length < 0) {
            throw new RuntimeException('Invalid range');
        }

        $end = min($this->size, $offset + $length);
        while ($offset < $end) {
            $index = intdiv($offset, $this->chunkSize);
            $chunk = $this->chunk($index);
            $start = $offset - $index * $this->chunkSize;
            $take = min(strlen($chunk) - $start, $end - $offset);
            yield substr($chunk, $start, $take);
            $offset += $take;
        }
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    private function chunk(int $index): string
    {
        if ($this->cachedIndex === $index) {
            return $this->cachedChunk;
        }

        $plainLength = min($this->chunkSize, $this->size - $index * $this->chunkSize);
        if ($plainLength < 0) {
            throw new RuntimeException('Read past the end of the file');
        }

        $position = StorageEncryption::HEADER_SIZE + $index * ($this->chunkSize + StorageEncryption::TAG_SIZE);
        fseek($this->handle, $position);
        $cipher = StorageEncryption::readExactly($this->handle, $plainLength + StorageEncryption::TAG_SIZE);

        $packed = pack('J', $index);
        $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($cipher, $this->header . $packed, $this->prefix . $packed, $this->key);
        if ($plain === false) {
            throw new RuntimeException('Encrypted file failed authentication (tampered or corrupted)');
        }

        $this->cachedIndex = $index;
        $this->cachedChunk = $plain;

        return $plain;
    }
}
