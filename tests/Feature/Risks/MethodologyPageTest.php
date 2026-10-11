<?php

namespace Tests\Feature\Risks;

use App\Enums\UserRole;
use App\Models\RiskMethodologyVersion;
use App\Services\RiskMethodology\RiskMethodologyPublisher;
use App\Services\Risks\RiskVersionPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MethodologyPageTest extends TestCase
{
    use InteractsWithRiskTargets;
    use RefreshDatabase;

    public function test_consultant_reads_exact_published_matrix_and_catalog_but_only_admin_sees_drafts_and_can_edit_them(): void
    {
        [, , $actor] = $this->riskTarget();
        $actor->update(['role' => UserRole::Admin]);
        $source = RiskMethodologyVersion::query()->sole();
        $draft = app(RiskMethodologyPublisher::class)->clonePublished($source, 'Neue Matrix', $actor);
        $this->actingAs($actor)->get('/risk-methodologies')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('risk-methodologies/Index')->where('capabilities.publish', true)->has('versions', 2));
        $matrix = app(RiskVersionPresenter::class)->methodology($draft);
        $this->actingAs($actor)->put("/risk-methodologies/{$draft->id}/draft", ['levels' => $matrix['levels'], 'cells' => $matrix['cells']])->assertSessionHasNoErrors();
        $actor->update(['role' => UserRole::Consultant]);
        $this->actingAs($actor)->get('/risk-methodologies')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('capabilities.publish', false)->has('versions', 1)
            ->where('versions.0.id', $source->id)->has('versions.0.cells', 16)
            ->has('versions.0.levels.frequency', 4)->has('versions.0.levels.impact', 4)
            ->where('versions.0.status', 'published')->has('versions.0.sourceReference'));
        $this->actingAs($actor)->get('/threat-catalogs')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('threat-catalogs/Index')->where('capabilities.publish', false)
            ->where('versions.0.status', 'published')->has('versions.0.entries')->has('versions.0.threatCount'));
        $this->actingAs($actor)->post("/risk-methodologies/{$draft->id}/publish")->assertForbidden();
    }
}
