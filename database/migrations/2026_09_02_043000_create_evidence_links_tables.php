<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evidence_question_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->uuid('project_assessment_id');
            $table->uuid('assessment_question_id');
            $table->uuid('evidence_file_id');
            $table->timestampsTz();

            $table->foreign(
                ['project_assessment_id', 'project_id'],
                'evidence_question_links_assessment_project_foreign',
            )->references(['id', 'project_id'])->on('project_assessments')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign(
                ['assessment_question_id', 'project_assessment_id'],
                'evidence_question_links_question_assessment_foreign',
            )->references(['id', 'project_assessment_id'])->on('assessment_questions')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign(
                ['evidence_file_id', 'project_id'],
                'evidence_question_links_evidence_project_foreign',
            )->references(['id', 'project_id'])->on('evidence_files')->cascadeOnUpdate()->restrictOnDelete();
            $table->unique(
                ['evidence_file_id', 'assessment_question_id'],
                'evidence_question_links_evidence_question_unique',
            );
        });

        Schema::create('evidence_finding_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->uuid('project_assessment_id');
            $table->uuid('finding_id');
            $table->uuid('evidence_file_id');
            $table->timestampsTz();

            $table->foreign(
                ['project_assessment_id', 'project_id'],
                'evidence_finding_links_assessment_project_foreign',
            )->references(['id', 'project_id'])->on('project_assessments')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign(
                ['finding_id', 'project_id', 'project_assessment_id'],
                'evidence_finding_links_finding_project_assessment_foreign',
            )->references(['id', 'project_id', 'project_assessment_id'])->on('findings')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign(
                ['evidence_file_id', 'project_id'],
                'evidence_finding_links_evidence_project_foreign',
            )->references(['id', 'project_id'])->on('evidence_files')->cascadeOnUpdate()->restrictOnDelete();
            $table->unique(
                ['evidence_file_id', 'finding_id'],
                'evidence_finding_links_evidence_finding_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evidence_finding_links');
        Schema::dropIfExists('evidence_question_links');
    }
};
