<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectThreat extends Model
{
    use HasUuids;

    /**
     * @param  Builder<ProjectThreat>  $query
     * @return Builder<ProjectThreat>
     */
    public function scopeCurrentForCatalog(Builder $query, ?string $catalogVersionId): Builder
    {
        return $query->where('project_threats.is_active', true)
            ->where(fn (Builder $threat) => $threat->where('project_threats.catalog_version_id', $catalogVersionId)
                ->orWhereNull('project_threats.threat_catalog_entry_id'));
    }

    protected $fillable = ['project_id', 'project_risk_context_id', 'catalog_version_id', 'threat_catalog_entry_id', 'code', 'title', 'description', 'confidentiality', 'integrity', 'availability', 'sort_order', 'source_edition', 'source_reference', 'addition_rationale', 'responsible_name', 'responsible_email', 'is_active', 'retired_at', 'created_by'];

    protected function casts(): array
    {
        return ['confidentiality' => 'boolean', 'integrity' => 'boolean', 'availability' => 'boolean', 'is_active' => 'boolean', 'retired_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<ProjectRiskContext, $this> */
    public function context(): BelongsTo
    {
        return $this->belongsTo(ProjectRiskContext::class, 'project_risk_context_id');
    }

    /** @return BelongsTo<ThreatCatalogEntry, $this> */
    public function catalogEntry(): BelongsTo
    {
        return $this->belongsTo(ThreatCatalogEntry::class, 'threat_catalog_entry_id');
    }
}
