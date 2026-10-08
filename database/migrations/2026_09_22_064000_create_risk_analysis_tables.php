<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_analyses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->uuid('business_process_id')->nullable();
            $table->uuid('asset_id')->nullable();
            $table->uuid('project_threat_id');
            $table->string('scenario_identity', 255);
            $table->timestampsTz();
            $table->foreign('project_id')->references('id')->on('isms_projects')->restrictOnDelete();
            $table->foreign(['business_process_id', 'project_id'])->references(['id', 'project_id'])->on('business_processes')->restrictOnDelete();
            $table->foreign(['asset_id', 'project_id'])->references(['id', 'project_id'])->on('assets')->restrictOnDelete();
            $table->foreign(['project_threat_id', 'project_id'])->references(['id', 'project_id'])->on('project_threats')->restrictOnDelete();
            $table->unique(['id', 'project_id']);
        });
        DB::statement('ALTER TABLE risk_analyses ADD CONSTRAINT risk_analyses_target_check CHECK ((business_process_id IS NOT NULL) <> (asset_id IS NOT NULL))');
        DB::statement('ALTER TABLE risk_analyses ADD CONSTRAINT risk_analyses_identity_check CHECK (scenario_identity = lower(trim(scenario_identity)) AND length(scenario_identity) > 0)');
        DB::statement('CREATE UNIQUE INDEX risk_analyses_process_identity ON risk_analyses (project_id,business_process_id,project_threat_id,scenario_identity) WHERE business_process_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX risk_analyses_asset_identity ON risk_analyses (project_id,asset_id,project_threat_id,scenario_identity) WHERE asset_id IS NOT NULL');

        Schema::create('risk_analysis_cycles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->uuid('risk_analysis_id');
            $table->unsignedInteger('cycle_number');
            $table->string('title', 255);
            $table->text('cause');
            $table->text('event');
            $table->text('impact');
            $table->boolean('confidentiality');
            $table->boolean('integrity');
            $table->boolean('availability');
            $table->string('status', 32)->default('draft');
            $table->boolean('is_current')->default(true);
            $table->boolean('needs_review')->default(false);
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('updated_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('completed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->foreign(['risk_analysis_id', 'project_id'])->references(['id', 'project_id'])->on('risk_analyses')->restrictOnDelete();
            $table->unique(['id', 'project_id']);
            $table->unique(['risk_analysis_id', 'cycle_number']);
        });
        DB::statement("ALTER TABLE risk_analysis_cycles ADD CONSTRAINT risk_cycle_status_check CHECK (status IN ('draft','inherent_assessed','treatment_in_progress','residual_assessed','awaiting_decision','completed','superseded'))");
        DB::statement("ALTER TABLE risk_analysis_cycles ADD CONSTRAINT risk_cycle_superseded_check CHECK (status <> 'superseded' OR (needs_review AND NOT is_current))");
        DB::statement('ALTER TABLE risk_analysis_cycles ADD CONSTRAINT risk_cycle_positive_number CHECK (cycle_number > 0)');
        DB::statement('ALTER TABLE risk_analysis_cycles ADD CONSTRAINT risk_cycle_objectives_check CHECK (confidentiality OR integrity OR availability)');
        DB::statement('CREATE UNIQUE INDEX risk_cycle_one_open_current ON risk_analysis_cycles (risk_analysis_id) WHERE is_current = true AND status <> \'completed\'');
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION enforce_risk_cycle_number() RETURNS trigger AS $$
DECLARE previous_number integer;
DECLARE expected_identity text;
BEGIN
    SELECT scenario_identity INTO expected_identity FROM risk_analyses WHERE id = NEW.risk_analysis_id;
    IF expected_identity IS NOT NULL AND lower(trim(NEW.title)) <> expected_identity THEN
        RAISE EXCEPTION 'Risk cycle title does not match analysis identity';
    END IF;
    IF TG_OP = 'UPDATE' THEN
        IF NEW.cycle_number <> OLD.cycle_number OR NEW.risk_analysis_id <> OLD.risk_analysis_id THEN
            RAISE EXCEPTION 'Risk cycle identity is immutable';
        END IF;
        RETURN NEW;
    END IF;
    SELECT COALESCE(MAX(cycle_number), 0) INTO previous_number FROM risk_analysis_cycles WHERE risk_analysis_id = NEW.risk_analysis_id;
    IF NEW.cycle_number <> previous_number + 1 THEN
        RAISE EXCEPTION 'Risk cycle numbers must be consecutive';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);
        DB::statement('CREATE TRIGGER risk_cycle_number_guard BEFORE INSERT OR UPDATE ON risk_analysis_cycles FOR EACH ROW EXECUTE FUNCTION enforce_risk_cycle_number()');
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION protect_completed_risk_cycle() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' AND OLD.status IN ('completed', 'superseded') THEN
        RAISE EXCEPTION 'Completed risk cycle is immutable';
    END IF;
    IF TG_OP = 'UPDATE' AND OLD.status IN ('completed', 'superseded') THEN
        IF NEW.id <> OLD.id OR NEW.project_id <> OLD.project_id OR NEW.risk_analysis_id <> OLD.risk_analysis_id OR NEW.cycle_number <> OLD.cycle_number
           OR NEW.title <> OLD.title OR NEW.cause <> OLD.cause OR NEW.event <> OLD.event OR NEW.impact <> OLD.impact
           OR NEW.confidentiality <> OLD.confidentiality OR NEW.integrity <> OLD.integrity OR NEW.availability <> OLD.availability
           OR NEW.status <> OLD.status OR NEW.lock_version <> OLD.lock_version OR NEW.created_by <> OLD.created_by OR NEW.updated_by <> OLD.updated_by
           OR (OLD.needs_review AND NOT NEW.needs_review) OR (NOT OLD.is_current AND NEW.is_current)
           OR NEW.completed_by IS DISTINCT FROM OLD.completed_by OR NEW.completed_at IS DISTINCT FROM OLD.completed_at OR NEW.created_at <> OLD.created_at THEN
            RAISE EXCEPTION 'Completed risk cycle is immutable';
        END IF;
    END IF;
    IF TG_OP = 'INSERT' OR TG_OP = 'UPDATE' THEN RETURN NEW; END IF;
    RETURN OLD;
END;
$$ LANGUAGE plpgsql;
SQL);
        DB::statement('CREATE TRIGGER risk_cycle_completed_immutable BEFORE UPDATE OR DELETE ON risk_analysis_cycles FOR EACH ROW EXECUTE FUNCTION protect_completed_risk_cycle()');

        Schema::create('risk_assessments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->uuid('risk_analysis_cycle_id');
            $table->string('phase', 16);
            $table->unsignedInteger('revision')->default(1);
            $table->uuid('methodology_version_id');
            $table->uuid('frequency_level_id');
            $table->uuid('impact_level_id');
            $table->uuid('risk_level_id');
            $table->text('rationale');
            $table->foreignUuid('assessed_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('assessed_at');
            $table->timestampsTz();
            $table->foreign(['risk_analysis_cycle_id', 'project_id'])->references(['id', 'project_id'])->on('risk_analysis_cycles')->restrictOnDelete();
            $table->foreign(['methodology_version_id', 'frequency_level_id'])->references(['version_id', 'id'])->on('risk_frequency_levels')->restrictOnDelete();
            $table->foreign(['methodology_version_id', 'impact_level_id'])->references(['version_id', 'id'])->on('risk_impact_levels')->restrictOnDelete();
            $table->foreign(['methodology_version_id', 'risk_level_id'])->references(['version_id', 'id'])->on('risk_levels')->restrictOnDelete();
            $table->unique(['risk_analysis_cycle_id', 'phase', 'revision']);
            $table->unique(['id', 'project_id', 'risk_analysis_cycle_id', 'phase'], 'risk_assessment_decision_reference_unique');
        });
        DB::statement("ALTER TABLE risk_assessments ADD CONSTRAINT risk_assessment_phase_check CHECK (phase IN ('inherent','residual'))");
        DB::statement('ALTER TABLE risk_assessments ADD CONSTRAINT risk_assessment_revision_check CHECK (revision > 0)');
        DB::statement("CREATE UNIQUE INDEX risk_assessment_one_inherent ON risk_assessments (risk_analysis_cycle_id) WHERE phase = 'inherent'");
        DB::statement('ALTER TABLE risk_assessments ADD CONSTRAINT risk_assessment_rationale_check CHECK (length(trim(rationale)) > 0 AND length(rationale) <= 10000)');
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION protect_completed_risk_child() RETURNS trigger AS $$
DECLARE cycle_status text;
BEGIN
    IF TG_OP IN ('UPDATE', 'DELETE') THEN
        SELECT status INTO cycle_status FROM risk_analysis_cycles WHERE id = OLD.risk_analysis_cycle_id;
        IF cycle_status IN ('completed', 'superseded') THEN RAISE EXCEPTION 'Historical risk cycle children are immutable'; END IF;
    END IF;
    IF TG_OP IN ('INSERT', 'UPDATE') THEN
        SELECT status INTO cycle_status FROM risk_analysis_cycles WHERE id = NEW.risk_analysis_cycle_id;
        IF cycle_status IN ('completed', 'superseded') THEN RAISE EXCEPTION 'Historical risk cycle children are immutable'; END IF;
    END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION protect_risk_assessment_history() RETURNS trigger AS $$
DECLARE cycle_status text;
BEGIN
    IF TG_OP IN ('UPDATE', 'DELETE') THEN
        RAISE EXCEPTION 'Risk assessment history is immutable';
    END IF;
    SELECT status INTO cycle_status FROM risk_analysis_cycles WHERE id = NEW.risk_analysis_cycle_id;
    IF cycle_status IN ('completed', 'superseded') THEN
        RAISE EXCEPTION 'Historical risk cycle children are immutable';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);
        DB::statement('CREATE TRIGGER risk_assessment_history_immutable BEFORE INSERT OR UPDATE OR DELETE ON risk_assessments FOR EACH ROW EXECUTE FUNCTION protect_risk_assessment_history()');

        DB::statement('ALTER TABLE risk_analysis_triggers ADD CONSTRAINT risk_analysis_triggers_id_project_unique UNIQUE (id, project_id)');
        Schema::create('risk_analysis_trigger_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->uuid('risk_analysis_cycle_id');
            $table->uuid('risk_analysis_trigger_id');
            $table->timestampsTz();
            $table->foreign(['risk_analysis_cycle_id', 'project_id'])->references(['id', 'project_id'])->on('risk_analysis_cycles')->restrictOnDelete();
            $table->foreign(['risk_analysis_trigger_id', 'project_id'])->references(['id', 'project_id'])->on('risk_analysis_triggers')->restrictOnDelete();
            $table->unique(['risk_analysis_cycle_id', 'risk_analysis_trigger_id']);
        });
        DB::statement('CREATE TRIGGER risk_trigger_link_completed_immutable BEFORE INSERT OR UPDATE OR DELETE ON risk_analysis_trigger_links FOR EACH ROW EXECUTE FUNCTION protect_completed_risk_child()');
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_analysis_trigger_links');
        DB::statement('ALTER TABLE risk_analysis_triggers DROP CONSTRAINT IF EXISTS risk_analysis_triggers_id_project_unique');
        Schema::dropIfExists('risk_assessments');
        Schema::dropIfExists('risk_analysis_cycles');
        Schema::dropIfExists('risk_analyses');
        DB::statement('DROP FUNCTION IF EXISTS protect_completed_risk_child()');
        DB::statement('DROP FUNCTION IF EXISTS protect_risk_assessment_history()');
        DB::statement('DROP FUNCTION IF EXISTS protect_completed_risk_cycle()');
        DB::statement('DROP FUNCTION IF EXISTS enforce_risk_cycle_number()');
    }
};
