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
        $validated = $this->fileValidator->validate($file);
        $sha256 = hash_file('sha256', $validated->temporaryPath);

        if (! is_string($sha256)) {
            throw new RuntimeException('Die hochgeladene Datei konnte nicht verarbeitet werden.');
        }

        $directory = 'projects/'.$project->id;
        $filename = (string) Str::uuid().'.'.$validated->kind;
        $newObjectPath = Storage::disk('evidence')->putFileAs(
            $directory,
            $file,
            $filename,
            ['visibility' => 'private'],
        );

        if (! is_string($newObjectPath) || $newObjectPath === '') {
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
                ]);

                return $evidence;
            });
        } catch (Throwable $exception) {
            if (is_string($newObjectPath)) {
                $this->removeNewObject($newObjectPath);
            }

            throw $exception;
        }
    }

    private function removeNewObject(string $path): void
    {
        if (! Storage::disk('evidence')->delete($path)) {
            throw new RuntimeException('Die hochgeladene Datei konnte nicht bereinigt werden.');
        }
    }
}
