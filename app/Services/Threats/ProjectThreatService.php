<?php

namespace App\Services\Threats;

use App\Models\IsmsProject;
use App\Models\ProjectRiskContext;
use App\Models\ProjectThreat;
use App\Models\RiskAnalysis;
use App\Models\RiskTargetThreatReview;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\ProtectionNeeds\ProtectionNeedAccess;
use App\Services\Risks\RiskChangeInvalidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ProjectThreatService
{
    public function __construct(private readonly ProtectionNeedAccess $access, private readonly ThreatOverviewService $overview, private readonly AuditLogger $audit, private readonly RiskChangeInvalidator $risks) {}

    /** @param array<string, mixed> $data */
    public function createCustom(IsmsProject $project, array $data, User $actor): ProjectThreat
    {
        $fields = $this->validated($data);

        return DB::transaction(function () use ($project, $fields, $actor): ProjectThreat {
            $actor = $this->access->writer($actor);
            $project = $this->access->project($project);
            $context = ProjectRiskContext::query()->where('project_id', $project->id)->first();
            if (! $context) {
                throw ValidationException::withMessages(['context' => 'Der Risikokontext muss zuerst initialisiert werden.']);
            }
            $this->uniqueCode($project, $context, $fields['code']);
            $nextOrder = (int) ProjectThreat::query()->where('project_id', $project->id)->max('sort_order') + 1;
            $threat = ProjectThreat::query()->create([...$fields, 'project_id' => $project->id, 'project_risk_context_id' => $context->id, 'catalog_version_id' => $context->catalog_version_id, 'sort_order' => $nextOrder, 'created_by' => $actor->id]);
            $this->overview->ensureForThreat($project, $threat->id);
            $this->audit->record('project_threat.created', $actor, ['project_id' => $project->id, 'target_id' => $threat->id], $project->organization_id);

            return $threat;
        });
    }

    /** @param array<string, mixed> $data */
    public function updateCustom(ProjectThreat $threat, array $data, User $actor): ProjectThreat
    {
        $fields = $this->validated($data);

        return DB::transaction(function () use ($threat, $fields, $actor): ProjectThreat {
            $actor = $this->access->writer($actor);
            $locked = ProjectThreat::query()->lockForUpdate()->findOrFail($threat->id);
            $project = $this->access->project(IsmsProject::query()->findOrFail($locked->project_id));
            $context = ProjectRiskContext::query()->where('project_id', $project->id)->firstOrFail();
            if ($locked->threat_catalog_entry_id !== null || ! $locked->is_active) {
                throw ValidationException::withMessages(['threat' => 'Nur aktive projektspezifische Gefährdungen können geändert werden.']);
            }
            $this->uniqueCode($project, $context, $fields['code'], $locked->id);
            if (! RiskTargetThreatReview::query()->where('project_threat_id', $locked->id)->exists() && ! RiskAnalysis::query()->where('project_threat_id', $locked->id)->exists()) {
                $locked->update($fields);
                $this->audit->record('project_threat.updated', $actor, ['project_id' => $project->id, 'target_id' => $locked->id, 'changed_fields' => array_keys($fields)], $project->organization_id);

                return $locked;
            }
            $locked->update(['is_active' => false, 'retired_at' => now()]);
            $this->risks->invalidateForThreat($project, $locked->id, 'threat_replacement', $actor);
            $replacement = ProjectThreat::query()->create([...$fields, 'project_id' => $project->id, 'project_risk_context_id' => $context->id, 'catalog_version_id' => $context->catalog_version_id, 'sort_order' => $locked->sort_order, 'created_by' => $actor->id]);
            $this->overview->ensureForThreat($project, $replacement->id);
            $this->audit->record('project_threat.replaced', $actor, ['project_id' => $project->id, 'source_id' => $locked->id, 'target_id' => $replacement->id], $project->organization_id);

            return $replacement;
        });
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function validated(array $data): array
    {
        $fields = Validator::make($data, [
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9][A-Za-z0-9._ -]*$/'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:10000'],
            'addition_rationale' => ['required', 'string', 'max:10000'],
            'confidentiality' => ['required', 'boolean'],
            'integrity' => ['required', 'boolean'],
            'availability' => ['required', 'boolean'],
            'responsible_name' => ['required', 'string', 'max:160'],
            'responsible_email' => ['nullable', 'email:rfc', 'max:255'],
        ], [
            'required' => 'Dieses Feld ist erforderlich.',
            'string' => 'Dieses Feld muss Text enthalten.',
            'max' => 'Dieses Feld ist zu lang.',
            'regex' => 'Der Gefährdungscode enthält unzulässige Zeichen.',
            'boolean' => 'Dieses Schutzziel muss wahr oder falsch sein.',
            'email' => 'Die E-Mail-Adresse ist ungültig.',
        ])->validate();
        foreach (['code', 'title', 'description', 'addition_rationale', 'responsible_name'] as $field) {
            $fields[$field] = trim((string) $fields[$field]);
            if ($fields[$field] === '') {
                throw ValidationException::withMessages([$field => 'Dieses Feld darf nicht leer sein.']);
            }
        }
        if (! $fields['confidentiality'] && ! $fields['integrity'] && ! $fields['availability']) {
            throw ValidationException::withMessages(['objectives' => 'Mindestens ein Schutzziel ist erforderlich.']);
        }

        return $fields;
    }

    private function uniqueCode(IsmsProject $project, ProjectRiskContext $context, string $code, ?string $exceptId = null): void
    {
        $query = ProjectThreat::query()->where('project_id', $project->id)->where('is_active', true)->where(fn ($q) => $q->where('catalog_version_id', $context->catalog_version_id)->orWhereNull('threat_catalog_entry_id'))->whereRaw('lower(code) = lower(?)', [$code]);
        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages(['code' => 'Dieser Gefährdungscode ist im Projekt bereits vergeben.']);
        }
    }
}
