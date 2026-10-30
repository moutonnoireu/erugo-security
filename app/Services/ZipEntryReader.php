<?php

namespace App\Services;

use RuntimeException;

/**
 * Minimal reader for one entry of a zip archive held in an encrypted file
 * (ZipArchive needs a plain file on disk). Supports stored and deflated
 * entries and ZIP64 archives, which is what ZipArchive writes.
 */
class ZipEntryReader
{
    private const EOCD = "PK\x05\x06";
    private const ZIP64_LOCATOR = "PK\x06\x07";
    private const ZIP64_EOCD = "PK\x06\x06";
    private const CENTRAL = "PK\x01\x02";
    private const LOCAL = "PK\x03\x04";

    private const STREAM_BLOCK = 262144;

    /** @var array<string, array{method: int, compressed: int, size: int, offset: int}>|null */
    private ?array $entries = null;

    public function __construct(private EncryptedFileReader $source)
    {
    }

    /**
     * @return array{method: int, compressed: int, size: int, offset: int}|null
     */
    public function find(string $name): ?array
    {
        return $this->entries()[$name] ?? null;
    }

    /**
     * Yield the uncompressed content of an entry.
     *
     * @param array{method: int, compressed: int, size: int, offset: int} $entry
     * @return \Generator<string>
     */
    public function stream(array $entry): \Generator
    {
        $local = $this->source->read($entry['offset'], 30);
        if (strlen($local) !== 30 || substr($local, 0, 4) !== self::LOCAL) {
            throw new RuntimeException('Invalid zip local header');
        }
        $fields = unpack('vnameLength/vextraLength', substr($local, 26, 4));
        $dataOffset = $entry['offset'] + 30 + $fields['nameLength'] + $fields['extraLength'];

        $inflate = null;
        if ($entry['method'] === 8) {
            $inflate = inflate_init(ZLIB_ENCODING_RAW);
        } elseif ($entry['method'] !== 0) {
            throw new RuntimeException('Unsupported zip compression method ' . $entry['method']);
        }

        $done = 0;
        while ($done < $entry['compressed']) {
            $block = $this->source->read($dataOffset + $done, min(self::STREAM_BLOCK, $entry['compressed'] - $done));
            if ($block === '') {
                throw new RuntimeException('Unexpected end of zip data');
            }
            $done += strlen($block);

            if ($inflate) {
                $out = inflate_add($inflate, $block, $done >= $entry['compressed'] ? ZLIB_FINISH : ZLIB_SYNC_FLUSH);
                if ($out === false) {
                    throw new RuntimeException('Corrupted zip entry');
                }
                if ($out !== '') {
                    yield $out;
                }
            } else {
                yield $block;
            }
        }
    }

    /**
     * @return array<string, array{method: int, compressed: int, size: int, offset: int}>
     */
    private function entries(): array
    {
        if ($this->entries !== null) {
            return $this->entries;
        }

        [$count, $cdSize, $cdOffset] = $this->centralDirectoryLocation();
        $directory = $this->source->read($cdOffset, $cdSize);

        $entries = [];
        $pos = 0;
        for ($i = 0; $i < $count; $i++) {
            if (substr($directory, $pos, 4) !== self::CENTRAL) {
                throw new RuntimeException('Invalid zip central directory');
            }
            $f = unpack(
                'vmethod/vtime/vdate/Vcrc/Vcompressed/Vsize/vnameLength/vextraLength/vcommentLength/vdisk/vinternal/Vexternal/Voffset',
                substr($directory, $pos + 10, 36)
            );
            $name = substr($directory, $pos + 46, $f['nameLength']);
            $extra = substr($directory, $pos + 46 + $f['nameLength'], $f['extraLength']);

            $compressed = $f['compressed'];
            $size = $f['size'];
            $offset = $f['offset'];

            // ZIP64: 0xFFFFFFFF fields are given, in order, in the 0x0001 extra field
            if ($size === 0xFFFFFFFF || $compressed === 0xFFFFFFFF || $offset === 0xFFFFFFFF) {
                $zip64 = $this->zip64Extra($extra);
                if ($size === 0xFFFFFFFF) {
                    $size = array_shift($zip64) ?? throw new RuntimeException('Invalid ZIP64 entry');
                }
                if ($compressed === 0xFFFFFFFF) {
                    $compressed = array_shift($zip64) ?? throw new RuntimeException('Invalid ZIP64 entry');
                }
                if ($offset === 0xFFFFFFFF) {
                    $offset = array_shift($zip64) ?? throw new RuntimeException('Invalid ZIP64 entry');
                }
            }

            $entries[$name] = ['method' => $f['method'], 'compressed' => $compressed, 'size' => $size, 'offset' => $offset];
            $pos += 46 + $f['nameLength'] + $f['extraLength'] + $f['commentLength'];
        }

        return $this->entries = $entries;
    }

    /** @return array{0: int, 1: int, 2: int} entry count, directory size, directory offset */
    private function centralDirectoryLocation(): array
    {
        $size = $this->source->size();
        $tailLength = min($size, 65557); // EOCD (22) + longest comment (65535)
        $tail = $this->source->read($size - $tailLength, $tailLength);

        $eocd = strrpos($tail, self::EOCD);
        if ($eocd === false) {
            throw new RuntimeException('Zip end of central directory not found');
        }
        $f = unpack('vdisk/vcdDisk/vdiskEntries/ventries/VcdSize/VcdOffset', substr($tail, $eocd + 4, 16));

        if ($f['entries'] !== 0xFFFF && $f['cdSize'] !== 0xFFFFFFFF && $f['cdOffset'] !== 0xFFFFFFFF) {
            return [$f['entries'], $f['cdSize'], $f['cdOffset']];
        }

        // ZIP64: the locator sits right before the classic EOCD
        $locator = substr($tail, $eocd - 20, 20);
        if (strlen($locator) !== 20 || substr($locator, 0, 4) !== self::ZIP64_LOCATOR) {
            throw new RuntimeException('ZIP64 locator not found');
        }
        $zip64Offset = self::le64(substr($locator, 8, 8));

        $record = $this->source->read($zip64Offset, 56);
        if (substr($record, 0, 4) !== self::ZIP64_EOCD) {
            throw new RuntimeException('Invalid ZIP64 end of central directory');
        }

        return [
            self::le64(substr($record, 32, 8)),
            self::le64(substr($record, 40, 8)),
            self::le64(substr($record, 48, 8)),
        ];
    }

    /** @return int[] */
    private function zip64Extra(string $extra): array
    {
        $pos = 0;
        while ($pos + 4 <= strlen($extra)) {
            $h = unpack('vid/vlength', substr($extra, $pos, 4));
            if ($h['id'] === 0x0001) {
                $data = substr($extra, $pos + 4, $h['length']);
                $values = [];
                for ($i = 0; $i + 8 <= strlen($data); $i += 8) {
                    $values[] = self::le64(substr($data, $i, 8));
                }
                return $values;
            }
            $pos += 4 + $h['length'];
        }

        return [];
    }

    private static function le64(string $bytes): int
    {
        return unpack('P', $bytes)[1];
    }
}
