<?php

namespace App\Services\Assessment;

use App\Enums\AssessmentStatus;
use App\Models\CatalogQuestion;
use App\Models\CatalogVersion;
use App\Models\IsmsProject;
use App\Models\ProjectAssessment;
use App\Models\QuestionOption;
use App\Models\QuestionRule;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AssessmentStarter
{
    public function start(IsmsProject $project, User $actor): ProjectAssessment
    {
        return DB::transaction(function () use ($project, $actor): ProjectAssessment {
            $lockedProject = IsmsProject::query()
                ->lockForUpdate()
                ->findOrFail($project->id);
            $existing = ProjectAssessment::query()
                ->where('project_id', $lockedProject->id)
                ->first();

            if ($existing instanceof ProjectAssessment) {
                return $existing;
            }

            $catalog = CatalogVersion::publishedForFramework($lockedProject->framework);
            $assessment = ProjectAssessment::query()->create([
                'project_id' => $lockedProject->id,
                'catalog_version_id' => $catalog->id,
                'status' => AssessmentStatus::InProgress,
                'started_by' => $actor->id,
                'started_at' => now(),
            ]);

            $catalog->questions()
                ->where('is_active', true)
                ->with(['category', 'options', 'incomingRules.triggerQuestion'])
                ->get()
                ->each(function (CatalogQuestion $question) use ($assessment): void {
                    $assessment->questions()->create([
                        'source_question_id' => $question->id,
                        'question_key' => $question->question_key,
                        'category_key' => $question->category->key,
                        'category_name' => $question->category->name,
                        'category_sort_order' => $question->category->sort_order,
                        'title' => $question->title,
                        'question_text' => $question->question_text,
                        'help_text' => $question->help_text,
                        'answer_type' => $question->answer_type,
                        'severity' => $question->severity,
                        'evidence_expected' => $question->evidence_expected,
                        'is_active' => $question->is_active,
                        'question_sort_order' => $question->sort_order,
                        'options' => $question->options
                            ->map(fn (QuestionOption $option): array => [
                                'value' => $option->value,
                                'label' => $option->label,
                                'score' => $option->score,
                                'sort_order' => $option->sort_order,
                            ])
                            ->values()
                            ->all(),
                        'rules' => $question->incomingRules
                            ->map(fn (QuestionRule $rule): array => [
                                'trigger_question_key' => $rule->triggerQuestion->question_key,
                                'operator' => $rule->operator->value,
                                'expected_value' => $rule->expected_value,
                                'action' => $rule->action->value,
                            ])
                            ->values()
                            ->all(),
                    ]);
                });

            return $assessment;
        });
    }
}
