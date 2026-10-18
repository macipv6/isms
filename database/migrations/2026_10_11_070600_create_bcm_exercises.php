<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bcm_exercises', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->uuid('continuity_plan_revision_id');
            $table->foreign(['continuity_plan_revision_id', 'project_id'], 'exercise_plan_project_fk')->references(['id', 'project_id'])->on('continuity_plan_revisions')->restrictOnDelete();
            $table->string('status')->default('draft');
            $table->unsignedInteger('lock_version')->default(1);
            $table->text('scenario')->nullable();
            $table->string('exercise_type')->nullable();
            $table->timestampTz('planned_at')->nullable();
            $table->string('leader_name', 160)->nullable();
            $table->text('participants')->nullable();
            $table->timestampTz('actual_at')->nullable();
            $table->jsonb('source_snapshot')->default('{}');
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            foreach (['completed', 'cancelled', 'source_change_acknowledged'] as $action) {
                $table->foreignUuid($action.'_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestampTz($action.'_at')->nullable();
            }
            $table->text('cancelled_reason')->nullable();
            $table->text('source_change_reason')->nullable();
            $table->unique(['id', 'project_id']);
            $table->index(['project_id', 'status']);
            $table->timestampsTz();
        });
        Schema::create('bcm_exercise_criteria', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->uuid('bcm_exercise_id');
            $table->foreign(['bcm_exercise_id', 'project_id'], 'exercise_criterion_project_fk')->references(['id', 'project_id'])->on('bcm_exercises')->restrictOnDelete();
            $table->unsignedInteger('position');
            $table->text('expected_result')->nullable();
            $table->bigInteger('expected_minutes')->nullable();
            $table->unique(['bcm_exercise_id', 'position']);
            $table->unique(['id', 'bcm_exercise_id', 'project_id'], 'exercise_criterion_scope_unique');
            $table->timestampsTz();
        });
        Schema::create('bcm_exercise_results', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->uuid('bcm_exercise_id');
            $table->uuid('bcm_exercise_criterion_id')->unique();
            $table->foreign(['bcm_exercise_criterion_id', 'bcm_exercise_id', 'project_id'], 'exercise_result_criterion_scope_fk')->references(['id', 'bcm_exercise_id', 'project_id'])->on('bcm_exercise_criteria')->restrictOnDelete();
            $table->string('outcome')->nullable();
            $table->text('observations')->nullable();
            $table->text('reason')->nullable();
            $table->bigInteger('actual_minutes')->nullable();
            $table->text('finding_waiver_reason')->nullable();
            $table->foreignUuid('finding_waived_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('finding_waived_at')->nullable();
            $table->unique(['id', 'project_id']);
            $table->timestampsTz();
        });
        Schema::create('bcm_exercise_evidence_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->uuid('bcm_exercise_id');
            $table->uuid('evidence_id');
            $table->foreign(['bcm_exercise_id', 'project_id'], 'exercise_evidence_parent_fk')->references(['id', 'project_id'])->on('bcm_exercises')->restrictOnDelete();
            $table->foreign(['evidence_id', 'project_id'], 'exercise_evidence_target_fk')->references(['id', 'project_id'])->on('evidence_files')->restrictOnDelete();
            $table->unique(['bcm_exercise_id', 'evidence_id']);
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE bcm_exercises ADD CONSTRAINT exercise_values_check CHECK (status IN ('draft','planned','conducted','completed','cancelled') AND lock_version >= 1 AND (exercise_type IS NULL OR exercise_type IN ('tabletop','recovery_test')))");
        DB::statement('ALTER TABLE bcm_exercise_criteria ADD CONSTRAINT exercise_criterion_values_check CHECK (position >= 1 AND (expected_minutes IS NULL OR expected_minutes >= 0))');
        DB::statement("ALTER TABLE bcm_exercise_results ADD CONSTRAINT exercise_result_values_check CHECK ((outcome IS NULL OR outcome IN ('passed','partial','failed','not_tested')) AND (actual_minutes IS NULL OR actual_minutes >= 0))");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION protect_bcm_exercise() RETURNS trigger AS $$
BEGIN
    IF OLD.status IN ('completed', 'cancelled') THEN RAISE EXCEPTION 'Final exercise history is immutable'; END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    IF NEW.project_id <> OLD.project_id OR NEW.continuity_plan_revision_id <> OLD.continuity_plan_revision_id THEN RAISE EXCEPTION 'Exercise provenance is immutable'; END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER exercise_history_guard BEFORE UPDATE OR DELETE ON bcm_exercises FOR EACH ROW EXECUTE FUNCTION protect_bcm_exercise();
CREATE OR REPLACE FUNCTION protect_bcm_exercise_child() RETURNS trigger AS $$
DECLARE parent_status text;
BEGIN
    IF TG_OP <> 'INSERT' THEN
        SELECT status INTO parent_status FROM bcm_exercises WHERE id = OLD.bcm_exercise_id FOR UPDATE;
        IF parent_status IN ('completed', 'cancelled') THEN RAISE EXCEPTION 'Final exercise children are immutable'; END IF;
    END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    SELECT status INTO parent_status FROM bcm_exercises WHERE id = NEW.bcm_exercise_id FOR UPDATE;
    IF parent_status IN ('completed', 'cancelled') THEN RAISE EXCEPTION 'Final exercise children are immutable'; END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);
        foreach (['criteria', 'results', 'evidence_links'] as $kind) {
            DB::statement('CREATE TRIGGER exercise_'.$kind.'_history_guard BEFORE INSERT OR UPDATE OR DELETE ON bcm_exercise_'.$kind.' FOR EACH ROW EXECUTE FUNCTION protect_bcm_exercise_child()');
        }
    }

    public function down(): void
    {
        foreach (['evidence_links', 'results', 'criteria'] as $kind) {
            Schema::dropIfExists('bcm_exercise_'.$kind);
        }
        Schema::dropIfExists('bcm_exercises');
        DB::statement('DROP FUNCTION IF EXISTS protect_bcm_exercise_child()');
        DB::statement('DROP FUNCTION IF EXISTS protect_bcm_exercise()');
    }
};
