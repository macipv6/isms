<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('continuity_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained('isms_projects')->restrictOnDelete();
            $table->unique(['id', 'project_id']);
            $table->timestampsTz();
        });
        Schema::create('continuity_plan_revisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->uuid('continuity_plan_id');
            $table->foreign(['continuity_plan_id', 'project_id'], 'plan_revision_identity_fk')->references(['id', 'project_id'])->on('continuity_plans')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->unsignedInteger('lock_version')->default(1);
            $table->string('status')->default('draft');
            $table->string('title')->nullable();
            foreach (['scenario', 'activation_conditions', 'strategy', 'minimum_operation', 'normal_operation_return'] as $field) {
                $table->text($field)->nullable();
            }
            $table->jsonb('source_snapshot')->default('{}');
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->unique(['continuity_plan_id', 'version']);
            $table->unique(['id', 'project_id']);
            $table->timestampsTz();
        });
        foreach (['processes', 'steps', 'roles', 'evidence_links', 'risk_links'] as $kind) {
            Schema::create('continuity_plan_'.$kind, function (Blueprint $table) use ($kind) {
                $table->uuid('id')->primary();
                $table->uuid('project_id');
                $table->uuid('continuity_plan_revision_id');
                $table->foreign(['continuity_plan_revision_id', 'project_id'], 'plan_'.$kind.'_revision_fk')->references(['id', 'project_id'])->on('continuity_plan_revisions')->restrictOnDelete();
                if ($kind === 'processes') {
                    $table->uuid('business_process_id');
                    $table->uuid('bia_revision_id')->nullable();
                    $table->foreign(['business_process_id', 'project_id'], 'plan_process_target_fk')->references(['id', 'project_id'])->on('business_processes')->restrictOnDelete();
                    $table->foreign(['bia_revision_id', 'project_id'], 'plan_process_bia_fk')->references(['id', 'project_id'])->on('bia_revisions')->restrictOnDelete();
                    $table->unique(['continuity_plan_revision_id', 'business_process_id'], 'plan_process_unique');
                } elseif ($kind === 'steps' || $kind === 'roles') {
                    $table->unsignedInteger('position');
                    if ($kind === 'steps') {
                        $table->text('instruction')->nullable();
                        $table->string('owner_name', 160)->nullable();
                        $table->bigInteger('expected_minutes')->nullable();
                    } else {
                        foreach (['role', 'name', 'deputy_name', 'phone'] as $field) {
                            $table->string($field, 160)->nullable();
                        }
                        $table->string('email')->nullable();
                    }
                    $table->unique(['continuity_plan_revision_id', 'position'], 'plan_'.$kind.'_position_unique');
                } else {
                    $column = $kind === 'evidence_links' ? 'evidence_id' : 'risk_analysis_id';
                    $table->uuid($column);
                    $table->foreign([$column, 'project_id'], 'plan_'.$kind.'_target_fk')->references(['id', 'project_id'])->on($kind === 'evidence_links' ? 'evidence_files' : 'risk_analyses')->restrictOnDelete();
                    $table->unique(['continuity_plan_revision_id', $column], 'plan_'.$kind.'_unique');
                }
                $table->timestampsTz();
            });
        }
        DB::statement("CREATE UNIQUE INDEX continuity_plan_one_draft ON continuity_plan_revisions (continuity_plan_id) WHERE status = 'draft'");
        DB::statement("ALTER TABLE continuity_plan_revisions ADD CONSTRAINT plan_revision_values_check CHECK (status IN ('draft','approved') AND version >= 1 AND lock_version >= 1)");
        DB::statement("ALTER TABLE continuity_plan_revisions ADD CONSTRAINT plan_revision_approval_check CHECK ((status = 'draft' AND approved_by IS NULL AND approved_at IS NULL) OR (status = 'approved' AND approved_by IS NOT NULL AND approved_at IS NOT NULL AND title IS NOT NULL AND btrim(title) <> '' AND scenario IS NOT NULL AND btrim(scenario) <> '' AND activation_conditions IS NOT NULL AND btrim(activation_conditions) <> '' AND strategy IS NOT NULL AND btrim(strategy) <> '' AND minimum_operation IS NOT NULL AND btrim(minimum_operation) <> '' AND normal_operation_return IS NOT NULL AND btrim(normal_operation_return) <> ''))");
        DB::statement('ALTER TABLE continuity_plan_steps ADD CONSTRAINT plan_step_minutes_check CHECK (position >= 1 AND (expected_minutes IS NULL OR expected_minutes >= 0))');
        DB::statement('ALTER TABLE continuity_plan_roles ADD CONSTRAINT plan_role_position_check CHECK (position >= 1)');
        DB::statement('CREATE TRIGGER plan_revision_history_guard BEFORE UPDATE OR DELETE ON continuity_plan_revisions FOR EACH ROW EXECUTE FUNCTION protect_bcm_revision_history()');
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION protect_continuity_plan_identity() RETURNS trigger AS $$
BEGIN
    IF NEW.project_id <> OLD.project_id THEN RAISE EXCEPTION 'Continuity plans cannot be reassigned'; END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER plan_identity_guard BEFORE UPDATE ON continuity_plans FOR EACH ROW EXECUTE FUNCTION protect_continuity_plan_identity();
CREATE OR REPLACE FUNCTION protect_continuity_plan_child() RETURNS trigger AS $$
DECLARE parent_status text;
BEGIN
    IF TG_OP <> 'INSERT' THEN
        SELECT status INTO parent_status FROM continuity_plan_revisions WHERE id = OLD.continuity_plan_revision_id FOR UPDATE;
        IF parent_status = 'approved' THEN RAISE EXCEPTION 'Approved plan children are immutable'; END IF;
    END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    SELECT status INTO parent_status FROM continuity_plan_revisions WHERE id = NEW.continuity_plan_revision_id FOR UPDATE;
    IF parent_status = 'approved' THEN RAISE EXCEPTION 'Approved plan children are immutable'; END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);
        foreach (['processes', 'steps', 'roles', 'evidence_links', 'risk_links'] as $kind) {
            DB::statement('CREATE TRIGGER plan_'.$kind.'_history_guard BEFORE INSERT OR UPDATE OR DELETE ON continuity_plan_'.$kind.' FOR EACH ROW EXECUTE FUNCTION protect_continuity_plan_child()');
        }
    }

    public function down(): void
    {
        foreach (['risk_links', 'evidence_links', 'roles', 'steps', 'processes', 'revisions'] as $kind) {
            Schema::dropIfExists('continuity_plan_'.$kind);
        }
        Schema::dropIfExists('continuity_plans');
        DB::statement('DROP FUNCTION IF EXISTS protect_continuity_plan_child()');
        DB::statement('DROP FUNCTION IF EXISTS protect_continuity_plan_identity()');
    }
};
