<?php

use App\Enums\FindingStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_assessments', function (Blueprint $table): void {
            $table->unique(['id', 'project_id'], 'project_assessments_id_project_id_unique');
        });
        Schema::table('assessment_questions', function (Blueprint $table): void {
            $table->unique(
                ['id', 'project_assessment_id'],
                'assessment_questions_id_project_assessment_id_unique',
            );
        });

        Schema::create('findings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->uuid('project_assessment_id');
            $table->uuid('assessment_question_id');
            $table->string('title');
            $table->text('description');
            $table->string('severity', 20);
            $table->string('status', 20)->default(FindingStatus::Proposed->value);
            $table->foreignUuid('proposed_by')->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->timestampTz('proposed_at');
            $table->foreignUuid('decided_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->foreignUuid('closed_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['id', 'project_id'], 'findings_id_project_id_unique');
            $table->unique(
                ['id', 'project_id', 'project_assessment_id'],
                'findings_id_project_assessment_unique',
            );
            $table->foreign(
                ['project_assessment_id', 'project_id'],
                'findings_assessment_project_foreign',
            )->references(['id', 'project_id'])->on('project_assessments')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign(
                ['assessment_question_id', 'project_assessment_id'],
                'findings_question_assessment_foreign',
            )->references(['id', 'project_assessment_id'])->on('assessment_questions')->cascadeOnUpdate()->restrictOnDelete();
            $table->index(['project_id', 'status']);
        });

        DB::statement("CREATE UNIQUE INDEX findings_one_active_per_question
            ON findings (assessment_question_id)
            WHERE status IN ('proposed', 'accepted')");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS findings_one_active_per_question');

        Schema::dropIfExists('findings');
        Schema::table('assessment_questions', function (Blueprint $table): void {
            $table->dropUnique('assessment_questions_id_project_assessment_id_unique');
        });
        Schema::table('project_assessments', function (Blueprint $table): void {
            $table->dropUnique('project_assessments_id_project_id_unique');
        });
    }
};
