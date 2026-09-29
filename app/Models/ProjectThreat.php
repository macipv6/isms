<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectThreat extends Model
{
    use HasUuids;

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
