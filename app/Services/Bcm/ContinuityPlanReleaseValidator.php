<?php

namespace App\Services\Bcm;

use App\Models\Asset;
use App\Models\BiaRevision;
use App\Models\BusinessProcess;
use App\Models\ContinuityPlanRevision;
use App\Models\EvidenceFile;
use App\Models\RiskAnalysis;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ContinuityPlanReleaseValidator
{
    public function __construct(private readonly BcmFreshness $freshness, private readonly BcmSourceSnapshot $sources) {}

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function draftData(array $data): array
    {
        foreach (['process_ids', 'evidence_ids', 'risk_analysis_ids', 'steps', 'roles'] as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            if (! is_array($data[$key]) || ! array_is_list($data[$key])) {
                throw ValidationException::withMessages([$key => 'Bitte geben Sie eine Liste an.']);
            }
            if ($key === 'steps' || $key === 'roles') {
                foreach ($data[$key] as $index => $row) {
                    if (! is_array($row)) {
                        throw ValidationException::withMessages([$key.'.'.$index => 'Bitte geben Sie einen gültigen Eintrag an.']);
                    }
                }
            }
        }
        $rules = ['title' => ['sometimes', 'nullable', 'string', 'max:255']];
        foreach (['scenario', 'activation_conditions', 'strategy', 'minimum_operation', 'normal_operation_return'] as $field) {
            $rules[$field] = ['sometimes', 'nullable', 'string', 'max:10000'];
        }
        foreach (['process_ids', 'evidence_ids', 'risk_analysis_ids'] as $field) {
            $rules[$field] = ['sometimes', 'array', 'list'];
            $rules[$field.'.*'] = ['required', 'uuid', 'distinct'];
        }
        $rules += [
            'steps' => ['sometimes', 'array', 'list'],
            'steps.*' => ['array:instruction,owner_name,expected_minutes'],
            'steps.*.instruction' => ['nullable', 'string', 'max:10000'],
            'steps.*.owner_name' => ['nullable', 'string', 'max:160'],
            'steps.*.expected_minutes' => ['nullable', function ($attribute, $value, $fail) {
                if (! is_int($value) || $value < 0) {
                    $fail('Die Dauer muss eine nichtnegative ganze Minutenzahl sein.');
                }
            }],
            'roles' => ['sometimes', 'array', 'list'],
            'roles.*' => ['array:role,name,email,phone,deputy_name'],
            'roles.*.role' => ['nullable', 'string', 'max:160'],
            'roles.*.name' => ['nullable', 'string', 'max:160'],
            'roles.*.deputy_name' => ['nullable', 'string', 'max:160'],
            'roles.*.email' => ['nullable', 'string', 'email', 'max:255'],
            'roles.*.phone' => ['nullable', 'string', 'max:160'],
        ];
        $validated = Validator::make($data, $rules)->validate();
        foreach ($data['steps'] ?? [] as $index => $step) {
            if (array_key_exists('expected_minutes', $step) && $step['expected_minutes'] === '') {
                throw ValidationException::withMessages(['steps.'.$index.'.expected_minutes' => 'Bitte geben Sie eine ganze Minutenzahl oder null an.']);
            }
        }

        return $validated;
    }

    /** Check all stored references afresh, including omitted partial-save fields. */
    public function validateReferences(ContinuityPlanRevision $revision): void
    {
        foreach ($revision->processes()->orderBy('business_process_id')->get() as $link) {
            $process = BusinessProcess::query()->whereKey($link->business_process_id)->where('project_id', $revision->project_id)->lockForUpdate()->firstOrFail();
            if (! $process->is_active) {
                throw ValidationException::withMessages(['process_ids' => 'Verknüpfte Prozesse müssen aktiv sein.']);
            }
        }
        foreach (['evidenceLinks' => [EvidenceFile::class, 'evidence_id'], 'riskLinks' => [RiskAnalysis::class, 'risk_analysis_id']] as $relation => [$model, $column]) {
            foreach ($revision->{$relation}()->orderBy($column)->get() as $link) {
                $model::query()->whereKey($link->getAttribute($column))->where('project_id', $revision->project_id)->lockForUpdate()->firstOrFail();
            }
        }
    }

    /** @return array<string, mixed> */
    public function validate(ContinuityPlanRevision $revision): array
    {
        $this->validateReferences($revision);
        foreach (['title', 'scenario', 'activation_conditions', 'strategy', 'minimum_operation', 'normal_operation_return'] as $field) {
            if (trim((string) $revision->getAttribute($field)) === '') {
                throw ValidationException::withMessages([$field => 'Diese Angabe ist für die Freigabe erforderlich.']);
            }
        }
        if (! $revision->processes()->exists()) {
            throw ValidationException::withMessages(['process_ids' => 'Mindestens ein aktiver Prozess ist erforderlich.']);
        }
        if (! $revision->steps()->exists() || $revision->steps()->get()->contains(fn ($step) => trim((string) $step->instruction) === '' || trim((string) $step->owner_name) === '')) {
            throw ValidationException::withMessages(['steps' => 'Mindestens ein vollständiger Schritt mit Verantwortlichem ist erforderlich.']);
        }
        if (! $revision->roles()->exists() || $revision->roles()->get()->contains(fn ($role) => trim((string) $role->role) === '' || trim((string) $role->name) === '' || trim((string) $role->deputy_name) === '')) {
            throw ValidationException::withMessages(['roles' => 'Verantwortliche Rollen benötigen Namen und Stellvertretung.']);
        }
        $snapshot = $this->sources->plan($revision);
        foreach ($snapshot['processes'] as $process) {
            $bia = BiaRevision::query()->whereKey($process['revision_id'])->where('project_id', $revision->project_id)->first();
            if (! $bia || ! $this->freshness->bia($bia)['current']) {
                throw ValidationException::withMessages(['process_ids' => 'Alle Prozesse benötigen eine aktuelle freigegebene BIA.']);
            }
        }
        foreach ($snapshot['assets'] as $asset) {
            if ($asset['revision_id'] === null || ! Asset::query()->whereKey($asset['asset_id'])->where('project_id', $revision->project_id)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['process_ids' => 'Verwendete Assetziele müssen aktiv und freigegeben sein.']);
            }
        }
        foreach ($snapshot['evidence_ids'] as $id) {
            $evidence = EvidenceFile::query()->whereKey($id)->where('project_id', $revision->project_id)->lockForUpdate()->firstOrFail();
            if ($evidence->status->value !== 'verified' || $evidence->reviewed_by === null || $evidence->reviewed_at === null) {
                throw ValidationException::withMessages(['evidence_ids' => 'Alle verknüpften Nachweise müssen verifiziert sein.']);
            }
        }

        return $snapshot;
    }
}
