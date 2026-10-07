<?php

namespace App\Services\Threats;

use App\Enums\ProjectStatus;
use App\Enums\PublicationStatus;
use App\Enums\UserRole;
use App\Models\IsmsProject;
use App\Models\ProjectRiskContext;
use App\Models\RiskMethodologyVersion;
use App\Models\ThreatCatalogVersion;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\ProtectionNeeds\ProtectionNeedPropagator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProjectRiskContextService
{
    public function __construct(private readonly AuditLogger $audit, private readonly ProtectionNeedPropagator $propagator, private readonly ThreatOverviewService $overview) {}

    public function initialize(IsmsProject $project, RiskMethodologyVersion $methodology, ThreatCatalogVersion $catalog, User $actor): ProjectRiskContext
    {
        return DB::transaction(function () use ($project, $methodology, $catalog, $actor): ProjectRiskContext {
            $actor = $this->requireWriter($actor);
            $project = IsmsProject::query()->lockForUpdate()->findOrFail($project->id);
            $this->requireWritableProject($project);
            $methodology = $this->publishedMethodology($methodology);
            $catalog = $this->publishedCatalog($catalog);
            $existing = ProjectRiskContext::query()->where('project_id', $project->id)->first();
            if ($existing) {
                if ($existing->methodology_version_id !== $methodology->id || $existing->catalog_version_id !== $catalog->id) {
                    throw ValidationException::withMessages(['context' => 'Für dieses Projekt ist bereits ein anderer Risikokontext initialisiert. Verwenden Sie die Migration.']);
                }

                return $existing;
            }
            $context = ProjectRiskContext::query()->create([
                'project_id' => $project->id,
                'methodology_version_id' => $methodology->id,
                'catalog_version_id' => $catalog->id,
                'initialized_by' => $actor->id,
                'initialized_at' => now(),
            ]);
            $this->copyCatalog($context, $catalog, $actor);
            $this->propagator->recalculate($project, [], $actor);
            $this->ensureThreatReviews($context, $catalog, $project);
            $this->recordChange($context, 'initialized', null, $methodology, null, $catalog, $actor);
            $this->audit->record('risk_context.initialized', $actor, [
                'project_id' => $project->id,
                'target_id' => $context->id,
                'methodology_version_id' => $methodology->id,
                'threat_catalog_version_id' => $catalog->id,
            ], $project->organization_id);

            return $context->refresh();
        });
    }

    public function migrate(ProjectRiskContext $context, RiskMethodologyVersion $methodology, ThreatCatalogVersion $catalog, User $actor): ProjectRiskContext
    {
        return DB::transaction(function () use ($context, $methodology, $catalog, $actor): ProjectRiskContext {
            $actor = $this->requireWriter($actor);
            $context = ProjectRiskContext::query()->lockForUpdate()->findOrFail($context->id);
            $project = IsmsProject::query()->lockForUpdate()->findOrFail($context->project_id);
            $this->requireWritableProject($project);
            $methodology = $this->publishedMethodology($methodology);
            $catalog = $this->publishedCatalog($catalog);
            if ($context->methodology_version_id === $methodology->id && $context->catalog_version_id === $catalog->id) {
                return $context;
            }
            $oldMethodology = RiskMethodologyVersion::query()->findOrFail($context->methodology_version_id);
            $oldCatalog = ThreatCatalogVersion::query()->findOrFail($context->catalog_version_id);
            if ($oldMethodology->methodology_id !== $methodology->methodology_id || $methodology->version < $oldMethodology->version || $oldCatalog->catalog_id !== $catalog->catalog_id || $catalog->version < $oldCatalog->version) {
                throw ValidationException::withMessages(['versions' => 'Für die Migration sind Versionen derselben Familie erforderlich, die nicht älter als die aktuellen Versionen sind.']);
            }
            if ($oldCatalog->id !== $catalog->id) {
                $this->copyCatalog($context, $catalog, $actor);
            }
            $context->update(['methodology_version_id' => $methodology->id, 'catalog_version_id' => $catalog->id]);
            $this->propagator->recalculate($project, [], $actor);
            if ($oldCatalog->id !== $catalog->id) {
                $this->ensureThreatReviews($context, $catalog, $project);
            }
            $this->recordChange($context, 'migrated', $oldMethodology, $methodology, $oldCatalog, $catalog, $actor);
            $this->audit->record('risk_context.migrated', $actor, [
                'project_id' => $project->id,
                'target_id' => $context->id,
                'old_methodology_version_id' => $oldMethodology->id,
                'new_methodology_version_id' => $methodology->id,
                'old_threat_catalog_version_id' => $oldCatalog->id,
                'new_threat_catalog_version_id' => $catalog->id,
            ], $project->organization_id);

            return $context->refresh();
        });
    }

    private function requireWriter(User $actor): User
    {
        $current = User::query()->lockForUpdate()->find($actor->id);
        $organization = $current?->organization()->lockForUpdate()->first();
        if (! $current || ! $current->is_active || ! in_array($current->role, [UserRole::Admin, UserRole::Consultant], true) || ! $organization || ! $organization->is_active || $organization->organization_type !== 'internal') {
            throw ValidationException::withMessages(['actor' => 'Ein aktiver interner Administrator oder Berater ist erforderlich.']);
        }

        return $current;
    }

    private function requireWritableProject(IsmsProject $project): void
    {
        $organization = $project->organization()->lockForUpdate()->firstOrFail();
        if (! $organization->is_active || $organization->organization_type !== 'customer' || ! in_array($project->status, [ProjectStatus::Draft, ProjectStatus::Active], true)) {
            throw ValidationException::withMessages(['project' => 'Ein bearbeitbares Kundenprojekt ist erforderlich.']);
        }
    }

    private function publishedMethodology(RiskMethodologyVersion $version): RiskMethodologyVersion
    {
        $current = RiskMethodologyVersion::query()->findOrFail($version->id);
        if ($current->status !== PublicationStatus::Published) {
            throw ValidationException::withMessages(['methodology' => 'Eine veröffentlichte Risikomethodik ist erforderlich.']);
        }

        return $current;
    }

    private function publishedCatalog(ThreatCatalogVersion $version): ThreatCatalogVersion
    {
        $current = ThreatCatalogVersion::query()->findOrFail($version->id);
        if ($current->status !== PublicationStatus::Published) {
            throw ValidationException::withMessages(['catalog' => 'Ein veröffentlichter Gefährdungskatalog ist erforderlich.']);
        }

        return $current;
    }

    private function copyCatalog(ProjectRiskContext $context, ThreatCatalogVersion $catalog, User $actor): void
    {
        foreach ($catalog->entries as $entry) {
            $context->projectThreats()->create([
                'project_id' => $context->project_id,
                'catalog_version_id' => $catalog->id,
                'threat_catalog_entry_id' => $entry->id,
                'code' => $entry->code,
                'title' => $entry->title,
                'description' => $entry->description,
                'confidentiality' => $entry->confidentiality,
                'integrity' => $entry->integrity,
                'availability' => $entry->availability,
                'sort_order' => $entry->sort_order,
                'source_edition' => $entry->source_edition,
                'source_reference' => $entry->source_reference,
                'created_by' => $actor->id,
            ]);
        }
    }

    private function recordChange(ProjectRiskContext $context, string $action, ?RiskMethodologyVersion $oldMethodology, RiskMethodologyVersion $newMethodology, ?ThreatCatalogVersion $oldCatalog, ThreatCatalogVersion $newCatalog, User $actor): void
    {
        $context->changes()->create([
            'project_id' => $context->project_id,
            'action' => $action,
            'old_methodology_version_id' => $oldMethodology?->id,
            'new_methodology_version_id' => $newMethodology->id,
            'old_catalog_version_id' => $oldCatalog?->id,
            'new_catalog_version_id' => $newCatalog->id,
            'actor_user_id' => $actor->id,
            'changed_at' => now(),
        ]);
    }

    private function ensureThreatReviews(ProjectRiskContext $context, ThreatCatalogVersion $catalog, IsmsProject $project): void
    {
        foreach ($context->projectThreats()->where('catalog_version_id', $catalog->id)->pluck('id') as $threatId) {
            $this->overview->ensureForThreat($project, $threatId);
        }
    }
}
