<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_answers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_assessment_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignUuid('assessment_question_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->text('answer_value')->nullable();
            $table->json('answer_json')->nullable();
            $table->text('comment')->nullable();
            $table->string('compliance_status', 32);
            $table->foreignUuid('answered_by')->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->timestampTz('answered_at');
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['project_assessment_id', 'assessment_question_id'], 'project_answers_question_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_answers');
    }
};
