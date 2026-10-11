<?php

namespace Tests\Feature\Risks;

use App\Models\EvidenceFile;
use App\Services\Risks\RiskAnalysisWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RiskPageContractTest extends TestCase
{
    use InteractsWithRiskTargets;
    use RefreshDatabase;

    public function test_risk_page_contains_bounded_authorized_scenario_but_no_private_file_metadata_or_query_poison(): void
    {
        [$project, $target, $actor, $context] = $this->riskTarget();
        $threat = $context->projectThreats()->firstOrFail();
        $cycle = app(RiskAnalysisWorkflow::class)->create($project, $target, $threat, [
            'title' => 'Standortausfall', 'cause' => 'Interne Ursache', 'event' => 'Netz getrennt', 'impact' => 'Lieferung verzögert',
            'confidentiality' => false, 'integrity' => false, 'availability' => true,
        ], [], $actor);
        EvidenceFile::factory()->for($project, 'project')->create(['storage_path' => 'private/secret-evidence.pdf', 'sha256' => str_repeat('a', 64)]);
        $url = "/organizations/{$project->organization_id}/projects/{$project->id}/risks";
        $response = $this->actingAs($actor)->get($url.'?status=draft&scenario=hidden-secret&owner_email=owner%40example.test&storage_path=private');
        $response->assertOk()->assertInertia(fn (Assert $page): Assert => $page
            ->component('risks/Index', false)
            ->where('capabilities.write', true)
            ->where('cycles.data.0.id', $cycle->id)
            ->where('cycles.data.0.cause', 'Interne Ursache')
            ->missing('cycles.data.0.storage_path')
            ->missing('cycles.data.0.sha256'));
        $body = $response->getContent();
        $this->assertStringNotContainsString('private/secret-evidence.pdf', $body);
        $this->assertStringNotContainsString(str_repeat('a', 64), $body);
        $this->assertStringNotContainsString('owner@example.test', $body);
        $this->assertStringNotContainsString('hidden-secret', $body);
    }
}
