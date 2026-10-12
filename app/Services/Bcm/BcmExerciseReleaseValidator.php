<?php

namespace App\Services\Bcm;

use App\Enums\BcmExerciseOutcome;
use App\Enums\BcmRevisionStatus;
use App\Models\BcmExercise;
use App\Models\ContinuityPlanRevision;
use App\Models\EvidenceFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BcmExerciseReleaseValidator
{
    public function __construct(private readonly BcmFreshness $freshness, private readonly BcmSourceSnapshot $sources) {}

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function draftData(array $data): array
    {
        $this->lists($data, ['criteria', 'evidence_ids']);
        $this->rows($data, 'criteria');

        return Validator::make($data, [
            'scenario' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'exercise_type' => ['sometimes', 'nullable', Rule::in(['tabletop', 'recovery_test'])],
            'planned_at' => ['sometimes', 'nullable', 'date'],
            'leader_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'participants' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'criteria' => ['sometimes', 'array', 'list'],
            'criteria.*' => ['array:expected_result,expected_minutes'],
            'criteria.*.expected_result' => ['nullable', 'string', 'max:10000'],
            'criteria.*.expected_minutes' => ['nullable', $this->minutes()],
            'evidence_ids' => ['sometimes', 'array', 'list'],
            'evidence_ids.*' => ['required', 'uuid', 'distinct'],
        ])->validate();
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function resultData(array $data): array
    {
        $this->lists($data, ['results', 'evidence_ids']);
        $this->rows($data, 'results');

        return Validator::make($data, [
            'actual_at' => ['sometimes', 'nullable', 'date'],
            'source_change_reason' => ['sometimes', 'required', 'string', 'max:10000'],
            'results' => ['sometimes', 'array', 'list'],
            'results.*' => ['array:criterion_id,outcome,observations,reason,actual_minutes'],
            'results.*.criterion_id' => ['required', 'uuid', 'distinct'],
            'results.*.outcome' => ['nullable', Rule::enum(BcmExerciseOutcome::class)],
            'results.*.observations' => ['nullable', 'string', 'max:10000'],
            'results.*.reason' => ['nullable', 'string', 'max:10000'],
            'results.*.actual_minutes' => ['nullable', $this->minutes()],
            'evidence_ids' => ['sometimes', 'array', 'list'],
            'evidence_ids.*' => ['required', 'uuid', 'distinct'],
        ])->validate();
    }

    public function approvedPlan(BcmExercise $exercise): ContinuityPlanRevision
    {
        $plan = ContinuityPlanRevision::query()->whereKey($exercise->continuity_plan_revision_id)->where('project_id', $exercise->project_id)->lockForUpdate()->firstOrFail();
        if ($plan->status !== BcmRevisionStatus::Approved) {
            $this->reject('plan', 'Die Übung benötigt eine freigegebene Planrevision.');
        }

        return $plan;
    }

    public function currentPlan(BcmExercise $exercise): ContinuityPlanRevision
    {
        $plan = $this->approvedPlan($exercise);
        if (! $this->freshness->plan($plan)['current']) {
            $this->reject('plan', 'Die Planquellen müssen vor Planung und Durchführung aktuell sein.');
        }

        return $plan;
    }

    public function planning(BcmExercise $exercise): ContinuityPlanRevision
    {
        $plan = $this->currentPlan($exercise);
        foreach (['scenario', 'exercise_type', 'planned_at', 'leader_name', 'participants'] as $field) {
            if (trim((string) $exercise->getAttribute($field)) === '') {
                $this->reject($field, 'Diese Angabe ist für die Planung erforderlich.');
            }
        }
        if (! $exercise->criteria()->exists() || $exercise->criteria()->get()->contains(fn ($criterion) => trim((string) $criterion->expected_result) === '')) {
            $this->reject('criteria', 'Mindestens ein vollständiges Kriterium ist erforderlich.');
        }

        return $plan;
    }

    public function complete(BcmExercise $exercise): void
    {
        $plan = $this->approvedPlan($exercise);
        if (($exercise->source_snapshot['plan_revision_id'] ?? null) !== $plan->id || ($exercise->source_snapshot['plan_sources'] ?? null) != $plan->source_snapshot) {
            $this->reject('plan', 'Die ursprünglichen freigegebenen Planquellen fehlen.');
        }
        if (! $this->freshness->plan($plan)['current']) {
            if ($exercise->getAttribute('source_change_acknowledged_by') === null || $exercise->getAttribute('source_change_acknowledged_at') === null || trim((string) $exercise->source_change_reason) === '' || ($exercise->source_snapshot['acknowledgement_sources'] ?? null) != $this->sources->exercise($exercise)) {
                $this->reject('source_change_reason', 'Geänderte Quellen benötigen eine erneute begründete Bestätigung für die dokumentierte Durchführung.');
            }
        }
        if ($exercise->actual_at === null) {
            $this->reject('actual_at', 'Der tatsächliche Übungstermin ist erforderlich.');
        }
        $criteria = $exercise->criteria()->orderBy('position')->lockForUpdate()->get();
        if ($criteria->isEmpty()) {
            $this->reject('results', 'Vollständige Ergebnisse für alle Kriterien sind erforderlich.');
        }
        foreach ($criteria as $criterion) {
            $result = $exercise->results()->where('bcm_exercise_criterion_id', $criterion->id)->lockForUpdate()->first();
            if (! $result || $result->outcome === null || trim((string) $result->observations) === '' || trim((string) $result->reason) === '') {
                $this->reject('results', 'Jedes Kriterium benötigt Ergebnis, Beobachtungen und Begründung.');
            }
            if ($result->outcome !== BcmExerciseOutcome::Passed && ! $result->findings()->where('project_id', $exercise->project_id)->exists() && ($result->finding_waived_by === null || $result->finding_waived_at === null || trim((string) $result->finding_waiver_reason) === '')) {
                $this->reject('results', 'Jede Abweichung benötigt einen Feststellungsvorschlag oder einen begründeten Verzicht.');
            }
        }
        foreach ($exercise->evidenceLinks()->orderBy('evidence_id')->get() as $link) {
            $proof = EvidenceFile::query()->whereKey($link->evidence_id)->where('project_id', $exercise->project_id)->lockForUpdate()->firstOrFail();
            if ($proof->status->value !== 'verified' || $proof->reviewed_by === null || $proof->reviewed_at === null) {
                $this->reject('evidence_ids', 'Alle verknüpften Nachweise müssen verifiziert sein.');
            }
        }
    }

    public function reason(string $reason, string $field): string
    {
        Validator::make([$field => $reason], [$field => ['required', 'string', 'max:10000']])->validate();

        return $reason;
    }

    /** @param array<string, mixed> $data
     * @param  list<string>  $keys
     */
    private function lists(array $data, array $keys): void
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && (! is_array($data[$key]) || ! array_is_list($data[$key]))) {
                $this->reject($key, 'Bitte geben Sie eine Liste an.');
            }
        }
    }

    /** @param array<string, mixed> $data */
    private function rows(array $data, string $key): void
    {
        foreach ($data[$key] ?? [] as $index => $row) {
            if (! is_array($row)) {
                $this->reject($key.'.'.$index, 'Bitte geben Sie einen gültigen Eintrag an.');
            }
            foreach (['expected_minutes', 'actual_minutes'] as $field) {
                if (array_key_exists($field, $row) && $row[$field] === '') {
                    $this->reject($key.'.'.$index.'.'.$field, 'Bitte geben Sie eine ganze Minutenzahl oder null an.');
                }
            }
        }
    }

    private function minutes(): \Closure
    {
        return function ($attribute, $value, $fail): void {
            if (! is_int($value) || $value < 0) {
                $fail('Die Dauer muss eine nichtnegative ganze Minutenzahl sein.');
            }
        };
    }

    private function reject(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
