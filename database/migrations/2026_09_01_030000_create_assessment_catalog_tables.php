<?php

use App\Enums\CatalogStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('frameworks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('key', 32)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('catalog_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('framework_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('version', 32);
            $table->string('status', 20)->default(CatalogStatus::Draft->value);
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();

            $table->unique(['framework_id', 'version']);
            $table->index(['framework_id', 'status', 'published_at']);
        });

        Schema::create('question_categories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('catalog_version_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('key', 64);
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampsTz();

            $table->unique(['catalog_version_id', 'key']);
        });

        Schema::create('catalog_questions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('catalog_version_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignUuid('question_category_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('question_key', 128);
            $table->string('title');
            $table->text('question_text');
            $table->text('help_text')->nullable();
            $table->string('answer_type', 32);
            $table->string('severity', 20)->default('medium');
            $table->boolean('evidence_expected')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampsTz();

            $table->unique(['catalog_version_id', 'question_key']);
            $table->index(['question_category_id', 'sort_order']);
        });

        Schema::create('question_options', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('catalog_question_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('value', 64);
            $table->string('label');
            $table->integer('score')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampsTz();

            $table->unique(['catalog_question_id', 'value']);
        });

        Schema::create('question_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('catalog_version_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignUuid('trigger_question_id')->constrained('catalog_questions')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignUuid('target_question_id')->constrained('catalog_questions')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('operator', 32);
            $table->json('expected_value');
            $table->string('action', 20);
            $table->timestampsTz();

            $table->unique([
                'trigger_question_id',
                'target_question_id',
                'operator',
                'action',
            ], 'question_rules_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_rules');
        Schema::dropIfExists('question_options');
        Schema::dropIfExists('catalog_questions');
        Schema::dropIfExists('question_categories');
        Schema::dropIfExists('catalog_versions');
        Schema::dropIfExists('frameworks');
    }
};
