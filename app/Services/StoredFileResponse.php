<?php

namespace App\Services;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Download response for a stored share file, encrypted at rest or not.
 * Plain files keep Laravel's default download response; encrypted files are
 * decrypted on the fly, with support for single byte-range requests.
 */
class StoredFileResponse
{
    public function __construct(private StorageEncryption $encryption)
    {
    }

    public function download(Request $request, string $path, string $downloadName, ?string $mimeType = null): Response
    {
        if (!StorageEncryption::isEncrypted($path)) {
            return response()->download($path, $downloadName);
        }

        $reader = $this->encryption->open($path);
        $size = $reader->size();
        [$start, $end, $status] = $this->range($request->header('Range'), $size);

        if ($status === 416) {
            $reader->close();
            return response('', 416, ['Content-Range' => 'bytes */' . $size]);
        }

        $headers = [
            'Content-Type' => $mimeType ?: 'application/octet-stream',
            'Content-Length' => (string) ($end - $start + 1),
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $downloadName,
                self::asciiFallback($downloadName)
            ),
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ];
        if ($status === 206) {
            $headers['Content-Range'] = "bytes {$start}-{$end}/{$size}";
        }
        if ($size === 0) {
            $headers['Content-Length'] = '0';
        }

        return new StreamedResponse(function () use ($reader, $start, $end, $size) {
            if ($size > 0) {
                foreach ($reader->chunks($start, $end - $start + 1) as $data) {
                    echo $data;
                    flush();
                    if (connection_aborted()) {
                        break;
                    }
                }
            }
            $reader->close();
        }, $status, $headers);
    }

    /**
     * Stream one entry of an encrypted share archive.
     *
     * @param array{method: int, compressed: int, size: int, offset: int} $entry
     */
    public function zipEntry(ZipEntryReader $zip, array $entry, string $downloadName, ?string $mimeType = null): Response
    {
        return new StreamedResponse(function () use ($zip, $entry) {
            foreach ($zip->stream($entry) as $data) {
                echo $data;
                flush();
                if (connection_aborted()) {
                    break;
                }
            }
        }, 200, [
            'Content-Type' => $mimeType ?: 'application/octet-stream',
            'Content-Length' => (string) $entry['size'],
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $downloadName,
                self::asciiFallback($downloadName)
            ),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Parse a single "bytes=" range. Multiple ranges are answered with the full file.
     *
     * @return array{0: int, 1: int, 2: int} start, end (inclusive), status
     */
    private function range(?string $header, int $size): array
    {
        $full = [0, max(0, $size - 1), 200];
        if (!$header || !preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $m) || $size === 0) {
            return $full;
        }

        if ($m[1] === '' && $m[2] === '') {
            return $full;
        }

        if ($m[1] === '') {
            // suffix range: last N bytes
            $length = (int) $m[2];
            if ($length === 0) {
                return [0, 0, 416];
            }
            return [max(0, $size - $length), $size - 1, 206];
        }

        $start = (int) $m[1];
        $end = $m[2] === '' ? $size - 1 : min((int) $m[2], $size - 1);
        if ($start >= $size || $start > $end) {
            return [0, 0, 416];
        }

        return [$start, $end, 206];
    }

    private static function asciiFallback(string $name): string
    {
        $fallback = preg_replace('/[^\x20-\x7E]/', '_', $name);
        return str_replace(['%', '/', '\\'], '_', $fallback) ?: 'download';
    }
}
