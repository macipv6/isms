<?php

namespace App\Services\Risks;

use App\Data\Dependencies\DependencyNode;
use App\Enums\DependencyNodeType;
use App\Enums\RiskCycleStatus;
use App\Models\IsmsProject;
use App\Models\ProjectThreat;
use App\Models\RiskAnalysis;
use App\Models\RiskAnalysisCycle;
use App\Models\RiskAnalysisTrigger;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\ProtectionNeeds\ProtectionNeedAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RiskAnalysisWorkflow
{
    public function __construct(private readonly ProtectionNeedAccess $access, private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $scenario
     * @param  list<string>  $triggerIds
     */
    public function create(IsmsProject $project, DependencyNode $target, ProjectThreat $threat, array $scenario, array $triggerIds, User $actor): RiskAnalysisCycle
    {
        $fields = $this->scenario($scenario);

        return DB::transaction(function () use ($project, $target, $threat, $fields, $triggerIds, $actor): RiskAnalysisCycle {
            $actor = $this->access->writer($actor);
            $project = $this->access->project($project);
            $this->access->target($project, $target);
            $context = DB::table('project_risk_contexts')->where('project_id', $project->id)->first();
            $threat = ProjectThreat::query()->whereKey($threat->id)->where('project_id', $project->id)->where('is_active', true)->where(fn ($q) => $q->where('catalog_version_id', $context?->catalog_version_id)->orWhereNull('threat_catalog_entry_id'))->first();
            if (! $context || ! $threat) {
                throw ValidationException::withMessages(['threat' => 'Eine aktuelle Gefährdung im Projekt ist erforderlich.']);
            }
            $column = $this->targetColumn($target);
            $identity = mb_strtolower(trim($fields['title']));
            if (RiskAnalysis::query()->where('project_id', $project->id)->where($column, $target->id)->where('project_threat_id', $threat->id)->where('scenario_identity', $identity)->exists()) {
                throw ValidationException::withMessages(['title' => 'Dieses Szenario ist für Ziel und Gefährdung bereits vorhanden.']);
            }
            if (count($triggerIds) !== count(array_unique($triggerIds))) {
                throw ValidationException::withMessages(['triggers' => 'Auslöser dürfen nicht mehrfach verknüpft werden.']);
            }
            foreach ($triggerIds as $id) {
                if (! RiskAnalysisTrigger::query()->whereKey($id)->where('project_id', $project->id)->where($column, $target->id)->whereIn('status', ['open', 'covered'])->exists()) {
                    throw ValidationException::withMessages(['triggers' => 'Ein Auslöser gehört nicht zum aktuellen Projektziel.']);
                }
            }
            $analysis = RiskAnalysis::query()->create(['project_id' => $project->id, $column => $target->id, 'project_threat_id' => $threat->id, 'scenario_identity' => $identity]);
            $cycle = $analysis->cycles()->create(['project_id' => $project->id, 'cycle_number' => 1, ...$fields, 'status' => RiskCycleStatus::Draft, 'is_current' => true, 'needs_review' => false, 'lock_version' => 0, 'created_by' => $actor->id, 'updated_by' => $actor->id]);
            foreach ($triggerIds as $id) {
                $cycle->triggerLinks()->create(['project_id' => $project->id, 'risk_analysis_trigger_id' => $id]);
            }
            $this->audit->record('risk_analysis.created', $actor, ['project_id' => $project->id, 'target_type' => $target->type->value, 'target_id' => $target->id, 'source_id' => $threat->id], $project->organization_id);

            return $cycle;
        });
    }

    public function startNextCycle(RiskAnalysis $analysis, User $actor): RiskAnalysisCycle
    {
        return DB::transaction(function () use ($analysis, $actor): RiskAnalysisCycle {
            $actor = $this->access->writer($actor);
            $current = RiskAnalysis::query()->findOrFail($analysis->id);
            $project = $this->access->project(IsmsProject::query()->findOrFail($current->project_id));
            $analysis = RiskAnalysis::query()->where('project_id', $project->id)->lockForUpdate()->findOrFail($analysis->id);
            $target = $this->target($analysis);
            $this->access->target($project, $target);
            $prior = $analysis->cycles()->orderByDesc('cycle_number')->lockForUpdate()->firstOrFail();
            if ($prior->status !== RiskCycleStatus::Completed) {
                throw ValidationException::withMessages(['cycle' => 'Ein neuer Zyklus erfordert einen abgeschlossenen Vorgänger.']);
            }
            $currentCatalog = DB::table('project_risk_contexts')->where('project_id', $project->id)->value('catalog_version_id');
            $threat = ProjectThreat::query()->whereKey($analysis->project_threat_id)->where('project_id', $project->id)->where('is_active', true)
                ->where(fn ($query) => $query->where('catalog_version_id', $currentCatalog)->orWhereNull('threat_catalog_entry_id'))->first();
            if (! $threat) {
                throw ValidationException::withMessages(['threat' => 'Die Gefährdung ist nicht mehr aktiv.']);
            }
            $prior->update(['is_current' => false]);
            $cycle = $analysis->cycles()->create([
                'project_id' => $project->id,
                'cycle_number' => $prior->cycle_number + 1,
                'title' => $prior->title, 'cause' => $prior->cause, 'event' => $prior->event, 'impact' => $prior->impact,
                'confidentiality' => $prior->confidentiality, 'integrity' => $prior->integrity, 'availability' => $prior->availability,
                'status' => RiskCycleStatus::Draft, 'is_current' => true, 'needs_review' => false, 'lock_version' => 0, 'created_by' => $actor->id, 'updated_by' => $actor->id,
            ]);
            $this->audit->record('risk_analysis.cycle_started', $actor, ['project_id' => $project->id, 'source_id' => $prior->id, 'target_id' => $cycle->id], $project->organization_id);

            return $cycle;
        });
    }

    /** @param array<string, mixed> $scenario
     * @return array<string, mixed>
     */
    private function scenario(array $scenario): array
    {
        $fields = Validator::make($scenario, [
            'title' => ['required', 'string', 'max:255'],
            'cause' => ['required', 'string', 'max:10000'],
            'event' => ['required', 'string', 'max:10000'],
            'impact' => ['required', 'string', 'max:10000'],
            'confidentiality' => ['required', 'boolean'],
            'integrity' => ['required', 'boolean'],
            'availability' => ['required', 'boolean'],
        ])->validate();
        foreach (['title', 'cause', 'event', 'impact'] as $field) {
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

    private function targetColumn(DependencyNode $target): string
    {
        return $target->type === DependencyNodeType::Process ? 'business_process_id' : 'asset_id';
    }

    private function target(RiskAnalysis $analysis): DependencyNode
    {
        if ($analysis->business_process_id !== null) {
            return new DependencyNode(DependencyNodeType::Process, $analysis->business_process_id, $analysis->project_id, '');
        }

        return new DependencyNode(DependencyNodeType::Asset, $analysis->asset_id, $analysis->project_id, '');
    }
}
