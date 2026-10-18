<?php

namespace App\Services\Bcm;

use App\Enums\BiaImpactArea;
use App\Models\AssetRecoveryObjectiveRevision;
use App\Models\BcmConflictAcceptance;
use App\Models\BiaRevision;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class BiaReleaseValidator
{
    public function __construct(private readonly BcmConsistencyChecker $checker) {}

    public function validate(BiaRevision $revision): void
    {
        $this->validateRecovery($revision);
        $errors = [];
        if (! is_string($revision->owner_name) || trim($revision->owner_name) === '') {
            $errors['owner_name'] = 'Bitte geben Sie einen Verantwortlichen an.';
        }
        if ($revision->mtpd_minutes === null || $revision->rto_minutes >= $revision->mtpd_minutes) {
            $errors['mtpd_minutes'] = 'MTPD muss positiv und größer als RTO sein.';
        }
        if ($revision->project->bia_configuration_version_id !== $revision->bia_configuration_version_id) {
            $errors['bia_configuration_version_id'] = 'Die BIA-Konfiguration wurde geändert. Bitte erstellen Sie einen Folgeentwurf.';
        }
        $impacts = $revision->impacts()->get();
        foreach (BiaImpactArea::cases() as $area) {
            foreach ($revision->configuration->minutes as $minute) {
                $impact = $impacts->first(fn ($impact) => $impact->area === $area && $impact->minutes === $minute);
                if (! $impact || $impact->level === null || ! is_string($impact->reason) || trim($impact->reason) === '') {
                    $errors['impacts'] = 'Alle Bereiche und Zeitstufen benötigen eine Bewertung und Begründung.';
                }
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $accepted = BcmConflictAcceptance::query()->where('project_id', $revision->project_id)->where('bia_revision_id', $revision->id)->pluck('fingerprint')->all();
        foreach ($this->checker->warnings($revision) as $warning) {
            if ($warning->blocking && ! in_array($warning->fingerprint, $accepted, true)) {
                throw ValidationException::withMessages(['conflicts' => 'Bitte beheben Sie die blockierenden Warnungen oder akzeptieren Sie diese mit Begründung.']);
            }
        }
    }

    public function validateRecovery(BiaRevision|AssetRecoveryObjectiveRevision $revision): void
    {
        $errors = [];
        if ($revision->rto_minutes === null || $revision->rto_minutes <= 0) {
            $errors['rto_minutes'] = 'Bitte geben Sie eine positive RTO in Minuten an.';
        }
        if (! is_string($revision->target_reason) || trim($revision->target_reason) === '') {
            $errors['target_reason'] = 'Bitte begründen Sie die Ziele.';
        }
        if ($revision->rpo_not_applicable) {
            if (! is_string($revision->rpo_reason) || trim($revision->rpo_reason) === '') {
                $errors['rpo_reason'] = 'Bitte begründen Sie die nicht anwendbare RPO.';
            }
        } elseif ($revision->rpo_minutes === null || $revision->rpo_minutes < 0) {
            $errors['rpo_minutes'] = 'Bitte geben Sie eine nichtnegative RPO an.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function draftData(array $data, bool $bia = false): array
    {
        $positive = static function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_int($value) || $value < ($attribute === 'rpo_minutes' ? 0 : 1)) {
                $fail('Bitte geben Sie einen gültigen ganzzahligen Minutenwert an.');
            }
        };
        $rules = [
            'rto_minutes' => ['sometimes', 'nullable', $positive],
            'rpo_minutes' => ['sometimes', 'nullable', $positive],
            'rpo_not_applicable' => ['sometimes', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_bool($value)) {
                    $fail('Bitte geben Sie einen Wahrheitswert an.');
                }
            }],
            'rpo_reason' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'target_reason' => ['sometimes', 'nullable', 'string', 'max:10000'],
        ];
        if ($bia) {
            $rules['mtpd_minutes'] = ['sometimes', 'nullable', $positive];
            $rules['owner_name'] = ['sometimes', 'nullable', 'string', 'max:160'];
            $rules['impacts'] = ['sometimes', 'array', 'list'];
            $rules['impacts.*'] = ['array:area,minutes,level,reason'];
            $rules['impacts.*.area'] = ['required', 'string', 'in:financial,operational,legal,reputation'];
            $rules['impacts.*.minutes'] = ['required', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_int($value) || $value < 1 || $value > 525600) {
                    $fail('Ungültige Zeitstufe.');
                }
            }];
            $rules['impacts.*.level'] = ['sometimes', 'nullable', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_int($value) || $value < 1 || $value > 4) {
                    $fail('Ungültige Auswirkungsstufe.');
                }
            }];
            $rules['impacts.*.reason'] = ['sometimes', 'nullable', 'string', 'max:10000'];
        }

        $validator = Validator::make($data, $rules);
        // Laravel skips nonimplicit callback rules for empty strings. Numeric and
        // boolean service inputs must still be native values (or explicit null).
        $validator->after(function ($validator) use ($data, $bia): void {
            foreach (['rto_minutes', 'rpo_minutes', 'rpo_not_applicable', ...($bia ? ['mtpd_minutes'] : [])] as $field) {
                if (array_key_exists($field, $data) && $data[$field] === '') {
                    $validator->errors()->add($field, 'Bitte geben Sie einen gültigen Wert oder ausdrücklich null an.');
                }
            }
            if ($bia && isset($data['impacts']) && is_array($data['impacts'])) {
                foreach ($data['impacts'] as $index => $impact) {
                    if (is_array($impact) && array_key_exists('level', $impact) && $impact['level'] === '') {
                        $validator->errors()->add('impacts.'.$index.'.level', 'Bitte geben Sie eine Auswirkungsstufe oder ausdrücklich null an.');
                    }
                }
            }
        });

        return $validator->validate();
    }
}
