<?php

namespace App\Models;

use App\Enums\EvidenceReviewStatus;
use Database\Factories\EvidenceFileFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property EvidenceReviewStatus $status
 * @property int $size_bytes
 */
class EvidenceFile extends Model
{
    /** @use HasFactory<EvidenceFileFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'project_id',
        'storage_path',
        'original_name',
        'mime_type',
        'file_kind',
        'size_bytes',
        'sha256',
        'status',
        'uploaded_by',
        'uploaded_at',
        'reviewed_by',
        'reviewed_at',
        'review_note',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'status' => EvidenceReviewStatus::class,
            'uploaded_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<IsmsProject, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(IsmsProject::class);
    }

    /**
     * @return BelongsTo<IsmsProject, $this>
     */
    public function ismsProject(): BelongsTo
    {
        return $this->project();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @return BelongsToMany<AssessmentQuestion, $this, EvidenceQuestionLink>
     */
    public function questions(): BelongsToMany
    {
        return $this->belongsToMany(AssessmentQuestion::class, 'evidence_question_links')
            ->using(EvidenceQuestionLink::class)
            ->withPivot('project_id', 'project_assessment_id')
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<Finding, $this, EvidenceFindingLink>
     */
    public function findings(): BelongsToMany
    {
        return $this->belongsToMany(Finding::class, 'evidence_finding_links')
            ->using(EvidenceFindingLink::class)
            ->withPivot('project_id', 'project_assessment_id')
            ->withTimestamps();
    }
}
