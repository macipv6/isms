<?php

namespace Tests\Feature\Findings;

use App\Enums\ComplianceStatus;
use App\Enums\UserRole;
use App\Models\AssessmentQuestion;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\ProjectAnswer;
use App\Models\ProjectAssessment;
use App\Models\User;
use App\Services\Assessment\AssessmentStarter;
use Database\Seeders\AssessmentCatalogSeeder;

trait InteractsWithFindingWorkflow
{
    /** @return array{Organization, IsmsProject, ProjectAssessment, AssessmentQuestion, User} */
    protected function findingContext(
        ComplianceStatus $compliance = ComplianceStatus::Partial,
        array $projectAttributes = [],
    ): array {
        $this->seed(AssessmentCatalogSeeder::class);
        $customer = Organization::factory()->create([
            'organization_type' => 'customer',
            'entra_tenant_id' => null,
        ]);
        $project = IsmsProject::factory()->for($customer)->create($projectAttributes);
        $actor = $this->internalUser(UserRole::Consultant);
        $assessment = app(AssessmentStarter::class)->start($project, $actor);
        $question = $assessment->questions()->where('question_key', 'governance.objectives')->sole();
        ProjectAnswer::query()->create([
            'project_assessment_id' => $assessment->id,
            'assessment_question_id' => $question->id,
            'answer_value' => 'Bewertungsergebnis nur für den Test',
            'answer_json' => null,
            'comment' => 'Vertraulicher Antwortkommentar',
            'compliance_status' => $compliance,
            'answered_by' => $actor->id,
            'answered_at' => now(),
        ]);

        return [$customer, $project, $assessment, $question, $actor];
    }

    protected function internalUser(UserRole $role = UserRole::Consultant): User
    {
        return User::factory()
            ->for(Organization::factory()->create(['organization_type' => 'internal']))
            ->create(['role' => $role]);
    }

    protected function findingPayload(array $overrides = []): array
    {
        return [
            'title' => 'Unvollständige Sicherheitsziele',
            'description' => 'Die dokumentierten Sicherheitsziele decken den aktuellen Geltungsbereich nicht vollständig ab.',
            'severity' => 'high',
            ...$overrides,
        ];
    }

    protected function findingStoreUrl(Organization $organization, IsmsProject $project, AssessmentQuestion $question): string
    {
        return '/organizations/'.$organization->id.'/projects/'.$project->id.'/assessment/questions/'.$question->id.'/findings';
    }

    protected function findingUrl(Organization $organization, IsmsProject $project, object $finding, string $suffix = ''): string
    {
        return '/organizations/'.$organization->id.'/projects/'.$project->id.'/findings/'.$finding->id.$suffix;
    }
}
