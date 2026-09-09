<?php

use App\Enums\DependencyImportance;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dependency_edges', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->uuid('source_process_id')->nullable();
            $table->uuid('source_asset_id')->nullable();
            $table->uuid('target_process_id')->nullable();
            $table->uuid('target_asset_id')->nullable();
            $table->string('importance', 20)->default(DependencyImportance::Supporting->value);
            $table->string('reason', 1000)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignUuid('created_by')->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->timestampsTz();

            $table->foreign('project_id')->references('id')->on('isms_projects')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign(['source_process_id', 'project_id'], 'dependency_edges_source_process_project_foreign')
                ->references(['id', 'project_id'])->on('business_processes')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign(['source_asset_id', 'project_id'], 'dependency_edges_source_asset_project_foreign')
                ->references(['id', 'project_id'])->on('assets')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign(['target_process_id', 'project_id'], 'dependency_edges_target_process_project_foreign')
                ->references(['id', 'project_id'])->on('business_processes')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign(['target_asset_id', 'project_id'], 'dependency_edges_target_asset_project_foreign')
                ->references(['id', 'project_id'])->on('assets')->cascadeOnUpdate()->restrictOnDelete();
        });

        DB::statement("ALTER TABLE dependency_edges ADD CONSTRAINT dependency_edges_importance_check CHECK (importance IN ('".DependencyImportance::Critical->value."', '".DependencyImportance::Supporting->value."'))");
        DB::statement(<<<'SQL'
            ALTER TABLE dependency_edges ADD CONSTRAINT dependency_edges_shape_check CHECK (
                (
                    source_process_id IS NOT NULL AND source_asset_id IS NULL
                    AND (
                        (target_process_id IS NOT NULL AND target_asset_id IS NULL)
                        OR (target_process_id IS NULL AND target_asset_id IS NOT NULL)
                    )
                )
                OR (
                    source_process_id IS NULL AND source_asset_id IS NOT NULL
                    AND target_process_id IS NULL AND target_asset_id IS NOT NULL
                )
            )
            SQL);
        DB::statement('CREATE UNIQUE INDEX dependency_edges_active_process_process_unique ON dependency_edges (project_id, source_process_id, target_process_id) WHERE is_active = true');
        DB::statement('CREATE UNIQUE INDEX dependency_edges_active_process_asset_unique ON dependency_edges (project_id, source_process_id, target_asset_id) WHERE is_active = true');
        DB::statement('CREATE UNIQUE INDEX dependency_edges_active_asset_asset_unique ON dependency_edges (project_id, source_asset_id, target_asset_id) WHERE is_active = true');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS dependency_edges_active_asset_asset_unique');
        DB::statement('DROP INDEX IF EXISTS dependency_edges_active_process_asset_unique');
        DB::statement('DROP INDEX IF EXISTS dependency_edges_active_process_process_unique');
        DB::statement('ALTER TABLE dependency_edges DROP CONSTRAINT IF EXISTS dependency_edges_shape_check');
        DB::statement('ALTER TABLE dependency_edges DROP CONSTRAINT IF EXISTS dependency_edges_importance_check');

        Schema::dropIfExists('dependency_edges');
    }
};
