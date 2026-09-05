<?php

namespace App\Models;

use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use Database\Factories\FindingFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property FindingSeverity $severity
 * @property FindingStatus $status
 */
class Finding extends Model
{
    /** @use HasFactory<FindingFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'project_id',
        'project_assessment_id',
        'assessment_question_id',
        'title',
        'description',
        'severity',
        'status',
        'proposed_by',
        'proposed_at',
        'decided_by',
        'decided_at',
        'decision_note',
        'closed_by',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'severity' => FindingSeverity::class,
            'status' => FindingStatus::class,
            'proposed_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
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
     * @return BelongsTo<ProjectAssessment, $this>
     */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(ProjectAssessment::class, 'project_assessment_id');
    }

    /**
     * @return BelongsTo<AssessmentQuestion, $this>
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(AssessmentQuestion::class, 'assessment_question_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function proposer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decisionMaker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * @return HasMany<Measure, $this>
     */
    public function measures(): HasMany
    {
        return $this->hasMany(Measure::class);
    }

    /**
     * @return BelongsToMany<EvidenceFile, $this, EvidenceFindingLink>
     */
    public function evidenceFiles(): BelongsToMany
    {
        return $this->belongsToMany(EvidenceFile::class, 'evidence_finding_links')
            ->using(EvidenceFindingLink::class)
            ->withPivot('project_id', 'project_assessment_id')
            ->withTimestamps();
    }
}
