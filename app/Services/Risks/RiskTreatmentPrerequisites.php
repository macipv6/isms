<?php

namespace App\Services\Risks;

use App\Enums\RiskAssessmentPhase;
use App\Enums\RiskTreatmentAction;
use App\Models\RiskAnalysisCycle;
use App\Models\RiskFrequencyLevel;
use App\Models\RiskImpactLevel;
use App\Models\RiskTreatment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RiskTreatmentPrerequisites
{
    public function assert(RiskAnalysisCycle $cycle, RiskTreatment $treatment, RiskFrequencyLevel $frequency, RiskImpactLevel $impact): void
    {
        if ($treatment->action === RiskTreatmentAction::Reduce) {
            $measureIds = $cycle->measureLinks()->lockForUpdate()->pluck('measure_id');
            if ($measureIds->isEmpty() || DB::table('measures')->where('project_id', $cycle->project_id)->whereIn('id', $measureIds)->where('status', 'completed')->whereNotNull('completed_at')->whereNotNull('completed_by')->count() !== $measureIds->count()) {
                throw ValidationException::withMessages(['measures' => 'Alle verknüpften Maßnahmen müssen wirksam abgeschlossen sein.']);
            }
        } elseif ($treatment->action === RiskTreatmentAction::Transfer) {
            $evidenceIds = $cycle->evidenceLinks()->lockForUpdate()->pluck('evidence_id');
            if (trim((string) $treatment->counterparty_name) === '' || $evidenceIds->isEmpty() || ! DB::table('evidence_files')->where('project_id', $cycle->project_id)->whereIn('id', $evidenceIds)->where('status', 'verified')->exists()) {
                throw ValidationException::withMessages(['evidence' => 'Übertragung erfordert einen Vertragspartner und geprüften Nachweis.']);
            }
        } elseif ($treatment->action === RiskTreatmentAction::Avoid) {
            if (trim((string) $treatment->cessation_statement) === '') {
                throw ValidationException::withMessages(['cessation_statement' => 'Eine geprüfte Beendigungsbestätigung ist erforderlich.']);
            }
        } else {
            $inherent = $cycle->assessments()->where('phase', RiskAssessmentPhase::Inherent->value)->firstOrFail();
            $initialFrequency = RiskFrequencyLevel::query()->findOrFail($inherent->frequency_level_id);
            $initialImpact = RiskImpactLevel::query()->findOrFail($inherent->impact_level_id);
            if ($frequency->sort_order < $initialFrequency->sort_order || $impact->sort_order < $initialImpact->sort_order) {
                throw ValidationException::withMessages(['treatment' => 'Akzeptanz darf Häufigkeit oder Auswirkung nicht senken.']);
            }
        }
    }
}
