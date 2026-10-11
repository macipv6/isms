<?php

namespace Tests\Feature\Risks;

use App\Services\Risks\RiskAnalysisWorkflow;
use App\Services\Risks\RiskAssessmentService;

trait InteractsWithRiskTreatment
{
    use InteractsWithRiskTargets;

    protected function assessedCycle(string $frequency = 'frequent', string $impact = 'considerable'): array
    {
        [$project, $target, $actor, $context] = $this->riskTarget();
        $cycle = app(RiskAnalysisWorkflow::class)->create($project, $target, $context->projectThreats()->firstOrFail(), [
            'title' => 'Vertrauliches Szenario', 'cause' => 'Ursache geheim', 'event' => 'Ereignis geheim',
            'impact' => 'Folge geheim', 'confidentiality' => false, 'integrity' => false, 'availability' => true,
        ], [], $actor);
        app(RiskAssessmentService::class)->assessInherent($cycle, $frequency, $impact, 'Geheime Einschätzung', $actor, 0);

        return [$project, $cycle->fresh(), $actor, $target];
    }
}
