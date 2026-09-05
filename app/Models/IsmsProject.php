<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use Carbon\CarbonImmutable;
use Database\Factories\IsmsProjectFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property ProjectStatus $status
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $target_date
 * @property CarbonImmutable|null $completed_at
 */
class IsmsProject extends Model
{
    /** @use HasFactory<IsmsProjectFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'name',
        'description',
        'framework',
        'approach',
        'bcm_level',
        'status',
        'scope_text',
        'started_at',
        'target_date',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'started_at' => 'immutable_date',
            'target_date' => 'immutable_date',
            'completed_at' => 'immutable_date',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasOne<ProjectAssessment, $this>
     */
    public function assessment(): HasOne
    {
        return $this->hasOne(ProjectAssessment::class, 'project_id');
    }

    /**
     * @return HasMany<EvidenceFile, $this>
     */
    public function evidenceFiles(): HasMany
    {
        return $this->hasMany(EvidenceFile::class, 'project_id');
    }

    /**
     * @return HasMany<Finding, $this>
     */
    public function findings(): HasMany
    {
        return $this->hasMany(Finding::class, 'project_id');
    }

    /**
     * @return HasMany<Measure, $this>
     */
    public function measures(): HasMany
    {
        return $this->hasMany(Measure::class, 'project_id');
    }
}
