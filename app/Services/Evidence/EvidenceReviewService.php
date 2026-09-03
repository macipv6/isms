<?php

namespace App\Services\Evidence;

use App\Enums\EvidenceReviewStatus;
use App\Models\EvidenceFile;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EvidenceReviewService
{
    public function __construct(private readonly EvidenceLinkService $links, private readonly AuditLogger $audit) {}

    public function review(EvidenceFile $evidence, EvidenceReviewStatus $status, ?string $note, User $actor): EvidenceFile
    {
        if (! in_array($status, [EvidenceReviewStatus::Verified, EvidenceReviewStatus::Rejected], true)) {
            throw ValidationException::withMessages(['status' => 'Dieser Prüfstatus ist nicht zulässig.']);
        }

        if ($status === EvidenceReviewStatus::Rejected && blank($note)) {
            throw ValidationException::withMessages(['review_note' => 'Für eine Ablehnung ist eine Begründung erforderlich.']);
        }

        return DB::transaction(function () use ($evidence, $status, $note, $actor): EvidenceFile {
            $this->links->assertWritableProject($evidence->project, $actor);
            $locked = EvidenceFile::query()->whereKey($evidence->id)->where('project_id', $evidence->project_id)->lockForUpdate()->firstOrFail();
            if ($locked->status === $status) return $locked;
            $old = $locked->status;
            $locked->update(['status' => $status, 'review_note' => $note, 'reviewed_by' => $actor->id, 'reviewed_at' => now()]);
            $this->audit->record('evidence.reviewed', $actor, ['project_id' => $locked->project_id, 'evidence_id' => $locked->id, 'old_status' => $old->value, 'new_status' => $status->value]);
            return $locked;
        });
    }
}
