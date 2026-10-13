<?php

namespace App\Models;

use App\Enums\FindingOrigin;
use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use Carbon\CarbonImmutable;
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
 * @property CarbonImmutable $proposed_at
 * @property FindingOrigin $origin_type
 * @property-read AssessmentQuestion|null $question
 * @property-read BcmExerciseResult|null $exerciseResult
 */
class Finding extends Model
{
    /** @use HasFactory<FindingFactory> */
    use HasFactory;

    use HasUuids;

    // Preserve absolute instants when PostgreSQL and application timezones differ.
    protected $dateFormat = 'Y-m-d H:i:sP';

    protected $fillable = [
        'project_id',
        'project_assessment_id',
        'assessment_question_id',
        'origin_type',
        'bcm_exercise_result_id',
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
            'origin_type' => FindingOrigin::class,
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

    /** @return BelongsTo<BcmExerciseResult, $this> */
    public function exerciseResult(): BelongsTo
    {
        return $this->belongsTo(BcmExerciseResult::class, 'bcm_exercise_result_id');
    }

    /** @return array{type: string, id: string, label: string} */
    public function originReference(): array
    {
        if ($this->origin_type === FindingOrigin::BcmExerciseResult) {
            $result = $this->exerciseResult;
            assert($result instanceof BcmExerciseResult);

            return ['type' => $this->origin_type->value, 'id' => $result->id, 'label' => 'BCM-Übung: '.$result->criterion->expected_result];
        }

        $question = $this->question;
        assert($question instanceof AssessmentQuestion);

        return ['type' => $this->origin_type->value, 'id' => $question->id, 'label' => $question->question_key.' · '.$question->title];
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
