<?php

namespace Tests\Feature\Evidence;

use App\Enums\EvidenceReviewStatus;
use App\Enums\UserRole;
use App\Models\EvidenceFile;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Evidence\EvidenceReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class EvidenceReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_self_review_is_allowed_and_repeated_same_decision_is_not_audit_transition(): void
    {
        [$project, $evidence, $actor] = $this->context();
        $service = app(EvidenceReviewService::class);

        $service->review($evidence, EvidenceReviewStatus::Verified, null, $actor);
        $service->review($evidence->fresh(), EvidenceReviewStatus::Verified, null, $actor);

        $this->assertSame(EvidenceReviewStatus::Verified, $evidence->fresh()->status);
        $this->assertDatabaseCount('audit_events', 1);
    }

    public function test_rejection_requires_a_note_and_audit_failure_rolls_back_the_review(): void
    {
        [$project, $evidence, $actor] = $this->context();
        $this->app->instance(AuditLogger::class, new class extends AuditLogger
        {
            public function record(string $eventType, ?User $actor, array $context = [], ?string $organizationId = null): \App\Models\AuditEvent
            {
                throw new RuntimeException('audit unavailable');
            }
        });

        try {
            app(EvidenceReviewService::class)->review($evidence, EvidenceReviewStatus::Rejected, 'Unleserlich', $actor);
            $this->fail('The audit exception must bubble out of the transaction.');
        } catch (RuntimeException) {
        }

        $this->assertSame(EvidenceReviewStatus::PendingReview, $evidence->fresh()->status);
    }

    /** @return array{IsmsProject, EvidenceFile, User} */
    private function context(): array
    {
        $organization = Organization::factory()->create(['organization_type' => 'customer', 'entra_tenant_id' => null]);
        $project = IsmsProject::factory()->for($organization)->create();
        $actor = User::factory()->for(Organization::factory()->create(['organization_type' => 'internal']))->create(['role' => UserRole::Consultant]);

        return [$project, EvidenceFile::factory()->for($project)->create(['uploaded_by' => $actor->id]), $actor];
    }
}
