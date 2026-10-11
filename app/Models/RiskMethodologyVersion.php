<?php

namespace App\Models;

use App\Enums\PublicationStatus;
use Carbon\CarbonImmutable;
use Database\Factories\RiskMethodologyVersionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property PublicationStatus $status
 * @property CarbonImmutable|null $published_at
 */
class RiskMethodologyVersion extends Model
{
    /** @use HasFactory<RiskMethodologyVersionFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = ['methodology_id', 'version', 'status', 'description', 'source_reference', 'published_at', 'published_by'];

    protected function casts(): array
    {
        return ['status' => PublicationStatus::class, 'published_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<RiskMethodology, $this> */
    public function methodology(): BelongsTo
    {
        return $this->belongsTo(RiskMethodology::class, 'methodology_id');
    }

    /** @return HasMany<RiskFrequencyLevel, $this> */
    public function frequencies(): HasMany
    {
        return $this->hasMany(RiskFrequencyLevel::class, 'version_id')->orderBy('sort_order');
    }

    /** @return HasMany<RiskImpactLevel, $this> */
    public function impacts(): HasMany
    {
        return $this->hasMany(RiskImpactLevel::class, 'version_id')->orderBy('sort_order');
    }

    /** @return HasMany<RiskLevel, $this> */
    public function riskLevels(): HasMany
    {
        return $this->hasMany(RiskLevel::class, 'version_id')->orderBy('sort_order');
    }

    /** @return HasMany<RiskMatrixCell, $this> */
    public function matrixCells(): HasMany
    {
        return $this->hasMany(RiskMatrixCell::class, 'version_id');
    }
}
