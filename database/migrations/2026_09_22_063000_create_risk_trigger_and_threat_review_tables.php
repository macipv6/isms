<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finding_process_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->uuid('finding_id');
            $table->uuid('business_process_id');
            $table->timestampsTz();
            $table->foreign(['finding_id', 'project_id'])->references(['id', 'project_id'])->on('findings')->restrictOnDelete();
            $table->foreign(['business_process_id', 'project_id'])->references(['id', 'project_id'])->on('business_processes')->restrictOnDelete();
            $table->unique(['finding_id', 'business_process_id']);
        });
        Schema::create('finding_asset_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->uuid('finding_id');
            $table->uuid('asset_id');
            $table->timestampsTz();
            $table->foreign(['finding_id', 'project_id'])->references(['id', 'project_id'])->on('findings')->restrictOnDelete();
            $table->foreign(['asset_id', 'project_id'])->references(['id', 'project_id'])->on('assets')->restrictOnDelete();
            $table->unique(['finding_id', 'asset_id']);
        });
        DB::statement('ALTER TABLE project_threats ADD CONSTRAINT project_threats_id_project_unique UNIQUE (id, project_id)');
        DB::statement('ALTER TABLE project_threats DROP CONSTRAINT IF EXISTS project_threats_project_id_catalog_version_id_code_unique');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS project_threats_active_code_unique ON project_threats (project_id, catalog_version_id, code) WHERE is_active = true');
        Schema::create('risk_analysis_triggers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->uuid('business_process_id')->nullable();
            $table->uuid('asset_id')->nullable();
            $table->string('type', 48);
            $table->string('source_identity', 80);
            $table->uuid('source_finding_id')->nullable();
            $table->text('rationale')->nullable();
            $table->string('status', 16)->default('open');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->foreign('project_id')->references('id')->on('isms_projects')->restrictOnDelete();
            $table->foreign(['business_process_id', 'project_id'])->references(['id', 'project_id'])->on('business_processes')->restrictOnDelete();
            $table->foreign(['asset_id', 'project_id'])->references(['id', 'project_id'])->on('assets')->restrictOnDelete();
            $table->foreign(['source_finding_id', 'project_id'])->references(['id', 'project_id'])->on('findings')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE risk_analysis_triggers ADD CONSTRAINT risk_trigger_target_check CHECK ((business_process_id IS NOT NULL) <> (asset_id IS NOT NULL))');
        DB::statement("ALTER TABLE risk_analysis_triggers ADD CONSTRAINT risk_trigger_type_check CHECK (type IN ('elevated_protection_need','insufficient_baseline_coverage','nonstandard_scenario','manual'))");
        DB::statement("ALTER TABLE risk_analysis_triggers ADD CONSTRAINT risk_trigger_status_check CHECK (status IN ('open','covered','superseded'))");
        DB::statement("ALTER TABLE risk_analysis_triggers ADD CONSTRAINT risk_trigger_source_check CHECK ((type = 'insufficient_baseline_coverage') = (source_finding_id IS NOT NULL) AND (type NOT IN ('nonstandard_scenario','manual') OR (rationale IS NOT NULL AND length(trim(rationale)) > 0 AND created_by IS NOT NULL)))");
        DB::statement("CREATE UNIQUE INDEX risk_trigger_process_active_unique ON risk_analysis_triggers (project_id,business_process_id,type,source_identity) WHERE business_process_id IS NOT NULL AND status IN ('open','covered')");
        DB::statement("CREATE UNIQUE INDEX risk_trigger_asset_active_unique ON risk_analysis_triggers (project_id,asset_id,type,source_identity) WHERE asset_id IS NOT NULL AND status IN ('open','covered')");
        Schema::create('risk_target_threat_reviews', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->uuid('business_process_id')->nullable();
            $table->uuid('asset_id')->nullable();
            $table->uuid('project_threat_id');
            $table->string('status', 20)->default('unreviewed');
            $table->text('rationale')->nullable();
            $table->uuid('reviewed_by')->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampsTz();
            $table->foreign('project_id')->references('id')->on('isms_projects')->restrictOnDelete();
            $table->foreign(['business_process_id', 'project_id'])->references(['id', 'project_id'])->on('business_processes')->restrictOnDelete();
            $table->foreign(['asset_id', 'project_id'])->references(['id', 'project_id'])->on('assets')->restrictOnDelete();
            $table->foreign(['project_threat_id', 'project_id'])->references(['id', 'project_id'])->on('project_threats')->restrictOnDelete();
            $table->foreign('reviewed_by')->references('id')->on('users')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE risk_target_threat_reviews ADD CONSTRAINT risk_review_target_check CHECK ((business_process_id IS NOT NULL) <> (asset_id IS NOT NULL))');
        DB::statement("ALTER TABLE risk_target_threat_reviews ADD CONSTRAINT risk_review_status_check CHECK (status IN ('unreviewed','applicable','not_applicable'))");
        DB::statement("ALTER TABLE risk_target_threat_reviews ADD CONSTRAINT risk_review_rationale_check CHECK (status <> 'not_applicable' OR (rationale IS NOT NULL AND length(trim(rationale)) > 0))");
        DB::statement('CREATE UNIQUE INDEX risk_review_process_unique ON risk_target_threat_reviews (business_process_id,project_threat_id) WHERE business_process_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX risk_review_asset_unique ON risk_target_threat_reviews (asset_id,project_threat_id) WHERE asset_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_target_threat_reviews');
        Schema::dropIfExists('risk_analysis_triggers');
        // Retired replacements can share their stable code with the active row.
        // Keep the active-only index when restoring the old constraint would discard or reject history.
        $hasHistoricalDuplicates = DB::table('project_threats')
            ->selectRaw('1')
            ->groupBy('project_id', 'catalog_version_id', 'code')
            ->havingRaw('count(*) > 1')
            ->first() !== null;
        if (! $hasHistoricalDuplicates) {
            DB::statement('DROP INDEX IF EXISTS project_threats_active_code_unique');
            DB::statement('ALTER TABLE project_threats ADD CONSTRAINT project_threats_project_id_catalog_version_id_code_unique UNIQUE (project_id, catalog_version_id, code)');
        }
        DB::statement('ALTER TABLE project_threats DROP CONSTRAINT IF EXISTS project_threats_id_project_unique');
        Schema::dropIfExists('finding_asset_links');
        Schema::dropIfExists('finding_process_links');
    }
};
