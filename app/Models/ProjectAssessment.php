<?php

namespace App\Models;

use App\Enums\AssessmentStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectAssessment extends Model
{
    use HasUuids;

    protected $fillable = [
        'project_id',
        'catalog_version_id',
        'framework_key',
        'catalog_version',
        'status',
        'started_by',
        'started_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => AssessmentStatus::class,
            'started_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<IsmsProject, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(IsmsProject::class, 'project_id');
    }

    /**
     * @return BelongsTo<CatalogVersion, $this>
     */
    public function catalogVersion(): BelongsTo
    {
        return $this->belongsTo(CatalogVersion::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    /**
     * @return HasMany<AssessmentQuestion, $this>
     */
    public function questions(): HasMany
    {
        return $this->hasMany(AssessmentQuestion::class)
            ->orderBy('category_sort_order')
            ->orderBy('question_sort_order');
    }

    /**
     * @return HasMany<ProjectAnswer, $this>
     */
    public function answers(): HasMany
    {
        return $this->hasMany(ProjectAnswer::class, 'project_assessment_id');
    }
}
