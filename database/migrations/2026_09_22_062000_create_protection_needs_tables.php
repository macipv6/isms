<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('protection_needs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->uuid('business_process_id')->nullable();
            $table->uuid('asset_id')->nullable();
            $table->string('objective', 24);
            $table->string('direct_level', 16)->nullable();
            $table->text('direct_rationale')->nullable();
            $table->string('inherited_level', 16)->nullable();
            $table->string('effective_level', 16)->nullable();
            $table->string('status', 16)->default('draft');
            $table->boolean('needs_review')->default(false);
            $table->uuid('rated_by')->nullable();
            $table->timestampTz('rated_at')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestampsTz();
            $table->foreign('project_id')->references('id')->on('isms_projects')->restrictOnDelete();
            $table->foreign(['business_process_id', 'project_id'])->references(['id', 'project_id'])->on('business_processes')->restrictOnDelete();
            $table->foreign(['asset_id', 'project_id'])->references(['id', 'project_id'])->on('assets')->restrictOnDelete();
            $table->foreign('rated_by')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['id', 'project_id']);
        });
        DB::statement('ALTER TABLE protection_needs ADD CONSTRAINT protection_needs_shape_check CHECK ((business_process_id IS NOT NULL) <> (asset_id IS NOT NULL))');
        DB::statement("ALTER TABLE protection_needs ADD CONSTRAINT protection_needs_objective_check CHECK (objective IN ('confidentiality','integrity','availability'))");
        DB::statement("ALTER TABLE protection_needs ADD CONSTRAINT protection_needs_level_check CHECK ((direct_level IS NULL OR direct_level IN ('normal','high','very_high')) AND (inherited_level IS NULL OR inherited_level IN ('normal','high','very_high')) AND (effective_level IS NULL OR effective_level IN ('normal','high','very_high')))");
        DB::statement('ALTER TABLE protection_needs ADD CONSTRAINT protection_needs_rationale_check CHECK (direct_level IS NULL OR (direct_rationale IS NOT NULL AND length(trim(direct_rationale)) > 0))');
        DB::statement("ALTER TABLE protection_needs ADD CONSTRAINT protection_needs_status_check CHECK (status IN ('draft','assessed','approved'))");
        DB::statement('CREATE UNIQUE INDEX protection_needs_process_objective_unique ON protection_needs (business_process_id, objective) WHERE business_process_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX protection_needs_asset_objective_unique ON protection_needs (asset_id, objective) WHERE asset_id IS NOT NULL');

        DB::statement('ALTER TABLE dependency_edges ADD CONSTRAINT dependency_edges_id_project_unique UNIQUE (id, project_id)');
        Schema::create('protection_need_sources', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->uuid('protection_need_id');
            $table->uuid('origin_process_id')->nullable();
            $table->uuid('origin_asset_id')->nullable();
            $table->string('origin_level', 16);
            $table->uuid('first_edge_id');
            $table->timestampsTz();
            $table->foreign(['protection_need_id', 'project_id'])->references(['id', 'project_id'])->on('protection_needs')->cascadeOnDelete();
            $table->foreign(['origin_process_id', 'project_id'])->references(['id', 'project_id'])->on('business_processes')->restrictOnDelete();
            $table->foreign(['origin_asset_id', 'project_id'])->references(['id', 'project_id'])->on('assets')->restrictOnDelete();
            $table->foreign(['first_edge_id', 'project_id'])->references(['id', 'project_id'])->on('dependency_edges')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE protection_need_sources ADD CONSTRAINT protection_need_sources_shape_check CHECK ((origin_process_id IS NOT NULL) <> (origin_asset_id IS NOT NULL))');
        DB::statement("ALTER TABLE protection_need_sources ADD CONSTRAINT protection_need_sources_level_check CHECK (origin_level IN ('normal','high','very_high'))");
        DB::statement('CREATE UNIQUE INDEX protection_need_sources_process_unique ON protection_need_sources (protection_need_id, origin_process_id) WHERE origin_process_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX protection_need_sources_asset_unique ON protection_need_sources (protection_need_id, origin_asset_id) WHERE origin_asset_id IS NOT NULL');

        Schema::create('protection_need_downgrade_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->uuid('protection_need_id');
            $table->string('inherited_level', 16);
            $table->string('requested_level', 16);
            $table->text('rationale');
            $table->foreignUuid('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 16)->default('pending');
            $table->string('decision_channel', 16)->nullable();
            $table->uuid('decided_by')->nullable();
            $table->uuid('recorded_by')->nullable();
            $table->string('external_name', 160)->nullable();
            $table->string('external_function', 160)->nullable();
            $table->date('external_decision_date')->nullable();
            $table->text('decision_rationale')->nullable();
            $table->uuid('external_evidence_id')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->timestampsTz();
            $table->foreign(['protection_need_id', 'project_id'])->references(['id', 'project_id'])->on('protection_needs')->restrictOnDelete();
            $table->foreign('decided_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('recorded_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign(['external_evidence_id', 'project_id'])->references(['id', 'project_id'])->on('evidence_files')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE protection_need_downgrade_requests ADD CONSTRAINT protection_need_downgrade_requests_status_check CHECK (status IN ('pending','approved','rejected','superseded','invalidated'))");
        DB::statement('CREATE UNIQUE INDEX protection_need_downgrade_pending_unique ON protection_need_downgrade_requests (protection_need_id) WHERE status = \'pending\'');
    }

    public function down(): void
    {
        Schema::dropIfExists('protection_need_downgrade_requests');
        Schema::dropIfExists('protection_need_sources');
        Schema::dropIfExists('protection_needs');
        DB::statement('ALTER TABLE dependency_edges DROP CONSTRAINT IF EXISTS dependency_edges_id_project_unique');
    }
};
