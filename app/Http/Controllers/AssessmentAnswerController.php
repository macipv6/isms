<?php

namespace App\Http\Controllers;

use App\Http\Requests\Assessments\UpdateAssessmentAnswerRequest;
use App\Models\AssessmentQuestion;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\User;
use App\Services\Assessment\AnswerValidator;
use App\Services\Assessment\AnswerWriter;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class AssessmentAnswerController extends Controller
{
    public function update(
        UpdateAssessmentAnswerRequest $request,
        Organization $organization,
        IsmsProject $project,
        AssessmentQuestion $question,
        AnswerValidator $validator,
        AnswerWriter $writer,
        AuditLogger $audit,
    ): RedirectResponse {
        $assessment = $project->assessment()->firstOrFail();
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        DB::transaction(function () use (
            $writer,
            $assessment,
            $question,
            $validator,
            $request,
            $actor,
            $audit,
            $project,
            $organization,
        ): void {
            $answer = $writer->save(
                $assessment,
                $question,
                $validator->validate($question, $request->validated()),
                $actor,
            );
            $changedFields = array_values(array_diff(
                array_keys($answer->getChanges()),
                ['id', 'created_at', 'updated_at', 'answered_at', 'answered_by'],
            ));

            $audit->record(
                'assessment.answer_saved',
                $actor,
                [
                    'project_id' => $project->id,
                    'question_key' => $question->question_key,
                    'changed_fields' => $changedFields,
                ],
                $organization->id,
            );
        });

        return redirect('/organizations/'.$organization->id.'/projects/'.$project->id.'/assessment')
            ->with('success', 'Antwort gespeichert.');
    }
}
