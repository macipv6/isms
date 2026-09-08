<?php

namespace App\Services\Evidence;

use App\Models\AssessmentQuestion;
use App\Models\EvidenceFile;
use App\Models\IsmsProject;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class EvidenceUploadService
{
    private const SNAPSHOT_CHUNK_BYTES = 8192;

    private const MAX_SNAPSHOT_BYTES = (50 * 1024 * 1024) + 1;

    public function __construct(
        private readonly EvidenceFileValidator $fileValidator,
        private readonly EvidenceLinkService $linkService,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function uploadForQuestion(
        IsmsProject $project,
        AssessmentQuestion $question,
        UploadedFile $file,
        User $actor,
    ): EvidenceFile {
        $this->linkService->assertWritableProject($project, $actor);
        $this->linkService->applicableQuestion($project, $question);
        $snapshot = $this->snapshot($file);

        try {
            $snapshotMetadata = stream_get_meta_data($snapshot);
            $snapshotPath = $snapshotMetadata['uri'] ?? null;
            if (! is_string($snapshotPath)) {
                throw new RuntimeException('Die hochgeladene Datei konnte nicht verarbeitet werden.');
            }

            $controlledFile = new UploadedFile(
                $snapshotPath,
                $file->getClientOriginalName(),
                null,
                null,
                true,
            );
            $validated = $this->fileValidator->validate($controlledFile);
            $sha256 = hash_file('sha256', $validated->temporaryPath);

            if (! is_string($sha256) || ! rewind($snapshot)) {
                throw new RuntimeException('Die hochgeladene Datei konnte nicht verarbeitet werden.');
            }

            $newObjectPath = 'projects/'.$project->id.'/'.Str::uuid().'.'.$validated->kind;
            $stored = Storage::disk('evidence')->put(
                $newObjectPath,
                $snapshot,
                ['visibility' => 'private'],
            );

            if (! $stored) {
                throw new RuntimeException('Die hochgeladene Datei konnte nicht gespeichert werden.');
            }

            try {
                return DB::transaction(function () use (
                    $project,
                    $question,
                    $actor,
                    $validated,
                    $sha256,
                    &$newObjectPath,
                ): EvidenceFile {
                    $this->linkService->assertWritableProject($project, $actor);
                    $existing = EvidenceFile::query()
                        ->where('project_id', $project->id)
                        ->where('sha256', $sha256)
                        ->lockForUpdate()
                        ->first();

                    if ($existing instanceof EvidenceFile) {
                        $this->removeNewObject($newObjectPath);
                        $newObjectPath = null;

                        return $this->linkService->linkToQuestion($project, $existing, $question, $actor);
                    }

                    $evidence = EvidenceFile::query()->create([
                        'project_id' => $project->id,
                        'storage_path' => $newObjectPath,
                        'original_name' => $validated->originalName,
                        'mime_type' => $validated->mimeType,
                        'file_kind' => $validated->kind,
                        'size_bytes' => $validated->sizeBytes,
                        'sha256' => $sha256,
                        'uploaded_by' => $actor->id,
                        'uploaded_at' => now(),
                    ]);

                    $this->linkService->linkToQuestion($project, $evidence, $question, $actor, false);
                    $this->auditLogger->record('evidence.uploaded', $actor, [
                        'project_id' => $project->id,
                        'evidence_id' => $evidence->id,
                    ], $project->organization_id);

                    return $evidence;
                });
            } catch (Throwable $exception) {
                if (is_string($newObjectPath)) {
                    $this->removeNewObject($newObjectPath);
                }

                throw $exception;
            }
        } finally {
            fclose($snapshot);
        }
    }

    /** @return resource */
    private function snapshot(UploadedFile $file)
    {
        if (! $file->isValid()) {
            throw new RuntimeException('Die hochgeladene Datei konnte nicht verarbeitet werden.');
        }

        $source = fopen($file->getPathname(), 'rb');
        $snapshot = tmpfile();
        if (! is_resource($source) || ! is_resource($snapshot)) {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($snapshot)) {
                fclose($snapshot);
            }

            throw new RuntimeException('Die hochgeladene Datei konnte nicht verarbeitet werden.');
        }

        try {
            $copied = 0;
            while (! feof($source) && $copied < self::MAX_SNAPSHOT_BYTES) {
                $chunk = fread($source, min(self::SNAPSHOT_CHUNK_BYTES, self::MAX_SNAPSHOT_BYTES - $copied));
                if ($chunk === false || ($chunk === '' && ! feof($source))) {
                    throw new RuntimeException('Die hochgeladene Datei konnte nicht verarbeitet werden.');
                }

                $written = fwrite($snapshot, $chunk);
                if ($written === false || $written !== strlen($chunk)) {
                    throw new RuntimeException('Die hochgeladene Datei konnte nicht verarbeitet werden.');
                }

                $copied += $written;
            }

            if (! rewind($snapshot)) {
                throw new RuntimeException('Die hochgeladene Datei konnte nicht verarbeitet werden.');
            }

            return $snapshot;
        } catch (Throwable $exception) {
            fclose($snapshot);
            throw $exception;
        } finally {
            fclose($source);
        }
    }

    private function removeNewObject(string $path): void
    {
        if (! Storage::disk('evidence')->delete($path)) {
            throw new RuntimeException('Die hochgeladene Datei konnte nicht bereinigt werden.');
        }
    }
}
