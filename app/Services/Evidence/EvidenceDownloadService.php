<?php

namespace App\Services\Evidence;

use App\Exceptions\EvidenceIntegrityException;
use App\Models\EvidenceFile;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class EvidenceDownloadService
{
    private const CHUNK_BYTES = 8192;

    public function __construct(private readonly AuditLogger $audit) {}

    public function download(EvidenceFile $evidence): StreamedResponse
    {
        $source = null;
        $verified = null;

        try {
            $verified = tmpfile();
            if (! is_resource($verified)) {
                throw new EvidenceIntegrityException;
            }

            $source = Storage::disk('evidence')->readStream($evidence->storage_path);
            if (! is_resource($source)) {
                throw new EvidenceIntegrityException;
            }

            $hash = hash_init('sha256');
            $size = 0;

            while (! feof($source)) {
                $remaining = $evidence->size_bytes - $size;
                $chunk = fread($source, min(self::CHUNK_BYTES, $remaining + 1));
                if ($chunk === false) {
                    throw new EvidenceIntegrityException;
                }

                if ($chunk === '' && ! feof($source)) {
                    throw new EvidenceIntegrityException;
                }

                $size += strlen($chunk);
                if ($size > $evidence->size_bytes) {
                    throw new EvidenceIntegrityException;
                }

                hash_update($hash, $chunk);
                $this->writeAll($verified, $chunk);
            }

            if ($size !== $evidence->size_bytes || ! hash_equals($evidence->sha256, hash_final($hash))) {
                throw new EvidenceIntegrityException;
            }

            if (! rewind($verified)) {
                throw new EvidenceIntegrityException;
            }

            fclose($source);
            $source = null;
        } catch (Throwable $exception) {
            if (is_resource($source)) {
                fclose($source);
            }

            if (is_resource($verified)) {
                fclose($verified);
            }

            throw new EvidenceIntegrityException($this->integrityFailed($evidence));
        }

        /** @var resource $verified */
        $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($evidence->original_name)) ?: 'evidence';

        return new StreamedResponse(function () use ($verified): void {
            try {
                fpassthru($verified);
            } finally {
                fclose($verified);
            }
        }, 200, [
            'Content-Type' => $evidence->mime_type,
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @param  resource  $stream
     */
    private function writeAll($stream, string $contents): void
    {
        $offset = 0;
        $length = strlen($contents);

        while ($offset < $length) {
            $written = fwrite($stream, substr($contents, $offset));
            if ($written === false || $written === 0) {
                throw new EvidenceIntegrityException;
            }

            $offset += $written;
        }
    }

    private function integrityFailed(EvidenceFile $evidence): ?Throwable
    {
        try {
            $this->audit->record('evidence.integrity_failed', null, ['evidence_id' => $evidence->id]);

            return null;
        } catch (Throwable $exception) {
            try {
                report($exception);
            } catch (Throwable) {
            }

            return $exception;
        }
    }
}
