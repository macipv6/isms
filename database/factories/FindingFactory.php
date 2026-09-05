<?php

namespace Database\Factories;

use App\Enums\AnswerType;
use App\Enums\AssessmentStatus;
use App\Enums\CatalogStatus;
use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Models\AssessmentQuestion;
use App\Models\CatalogVersion;
use App\Models\Finding;
use App\Models\Framework;
use App\Models\IsmsProject;
use App\Models\ProjectAssessment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Finding> */
class FindingFactory extends Factory
{
    protected $model = Finding::class;

    public function definition(): array
    {
        return [
            'project_id' => IsmsProject::factory(),
            'project_assessment_id' => fn (array $attributes): string => $this->assessmentFor($attributes['project_id'])->id,
            'assessment_question_id' => fn (array $attributes): string => $this->questionFor($attributes['project_assessment_id'])->id,
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'severity' => fake()->randomElement(FindingSeverity::cases()),
            'status' => FindingStatus::Proposed,
            'proposed_by' => User::factory(),
            'proposed_at' => now(),
            'decided_by' => null,
            'decided_at' => null,
            'decision_note' => null,
            'closed_by' => null,
            'closed_at' => null,
        ];
    }

    private function assessmentFor(string $projectId): ProjectAssessment
    {
        $existing = ProjectAssessment::query()->where('project_id', $projectId)->first();

        if ($existing instanceof ProjectAssessment) {
            return $existing;
        }

        $catalog = CatalogVersion::query()->first();

        if (! $catalog instanceof CatalogVersion) {
            $framework = Framework::query()->firstOrCreate(
                ['key' => 'factory'],
                ['name' => 'Factory framework', 'is_active' => true],
            );
            $catalog = CatalogVersion::query()->firstOrCreate(
                ['framework_id' => $framework->id, 'version' => 'factory'],
                ['status' => CatalogStatus::Draft],
            );
        }

        return ProjectAssessment::query()->create([
            'project_id' => $projectId,
            'catalog_version_id' => $catalog->id,
            'framework_key' => 'factory',
            'catalog_version' => $catalog->version,
            'status' => AssessmentStatus::InProgress,
            'started_by' => User::factory()->create()->id,
            'started_at' => now(),
        ]);
    }

    private function questionFor(string $assessmentId): AssessmentQuestion
    {
        $existing = AssessmentQuestion::query()
            ->where('project_assessment_id', $assessmentId)
            ->first();

        if ($existing instanceof AssessmentQuestion) {
            return $existing;
        }

        return AssessmentQuestion::query()->create([
            'project_assessment_id' => $assessmentId,
            'question_key' => 'factory.'.Str::lower(Str::random(12)),
            'category_key' => 'factory',
            'category_name' => 'Factory',
            'category_sort_order' => 0,
            'title' => 'Factory question',
            'question_text' => 'Factory question text',
            'answer_type' => AnswerType::Text,
            'severity' => 'medium',
            'evidence_expected' => false,
            'is_active' => true,
            'question_sort_order' => 0,
            'options' => [],
            'rules' => [],
        ]);
    }
}
