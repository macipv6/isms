<?php

namespace App\Services\Evidence;

use App\Exceptions\EvidenceIntegrityException;
use App\Models\EvidenceFile;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class EvidenceDownloadService
{
    private const CHUNK_BYTES = 8192;

    public function __construct(private readonly AuditLogger $audit) {}

    public function download(EvidenceFile $evidence): StreamedResponse
    {
        $stream = null;
        try {
            $stream = Storage::disk('evidence')->readStream($evidence->storage_path);
            if (! is_resource($stream)) throw new EvidenceIntegrityException;
            $hash = hash_init('sha256'); $size = 0;
            while (! feof($stream)) { $chunk = fread($stream, self::CHUNK_BYTES); if ($chunk === false) throw new EvidenceIntegrityException; $size += strlen($chunk); hash_update($hash, $chunk); }
            fclose($stream);
            $stream = null;
            if ($size !== $evidence->size_bytes || ! hash_equals($evidence->sha256, hash_final($hash))) throw new EvidenceIntegrityException;
        } catch (EvidenceIntegrityException $exception) { if (is_resource($stream)) fclose($stream); $this->integrityFailed($evidence); throw $exception; } catch (Throwable) { if (is_resource($stream)) fclose($stream); $this->integrityFailed($evidence); throw new EvidenceIntegrityException; }

        $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($evidence->original_name)) ?: 'evidence';
        return new StreamedResponse(function () use ($evidence): void { $stream = Storage::disk('evidence')->readStream($evidence->storage_path); if (! is_resource($stream)) return; try { fpassthru($stream); } finally { fclose($stream); } }, 200, ['Content-Type' => $evidence->mime_type, 'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename), 'X-Content-Type-Options' => 'nosniff']);
    }

    private function integrityFailed(EvidenceFile $evidence): void
    {
        try { $this->audit->record('evidence.integrity_failed', null, ['evidence_id' => $evidence->id]); } catch (Throwable) { }
    }
}
