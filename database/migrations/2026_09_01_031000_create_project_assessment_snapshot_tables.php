<?php

use App\Enums\AssessmentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_assessments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained('isms_projects')->cascadeOnUpdate()->restrictOnDelete()->unique();
            $table->foreignUuid('catalog_version_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('status', 24)->default(AssessmentStatus::InProgress->value);
            $table->foreignUuid('started_by')->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->timestampTz('started_at');
            $table->timestampsTz();
        });

        Schema::create('assessment_questions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_assessment_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignUuid('source_question_id')->nullable()->constrained('catalog_questions')->cascadeOnUpdate()->nullOnDelete();
            $table->string('question_key', 128);
            $table->string('category_key', 64);
            $table->string('category_name');
            $table->unsignedInteger('category_sort_order');
            $table->string('title');
            $table->text('question_text');
            $table->text('help_text')->nullable();
            $table->string('answer_type', 32);
            $table->string('severity', 20);
            $table->boolean('evidence_expected');
            $table->boolean('is_active');
            $table->unsignedInteger('question_sort_order');
            $table->json('options');
            $table->json('rules');
            $table->timestampsTz();

            $table->unique(['project_assessment_id', 'question_key'], 'assessment_questions_key_unique');
            $table->index(['project_assessment_id', 'category_sort_order', 'question_sort_order'], 'assessment_questions_order_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_questions');
        Schema::dropIfExists('project_assessments');
    }
};
