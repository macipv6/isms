<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_impact_analyses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained('isms_projects')->restrictOnDelete();
            $table->uuid('business_process_id')->unique();
            $table->foreign(['business_process_id', 'project_id'])->references(['id', 'project_id'])->on('business_processes')->restrictOnDelete();
            $table->unique(['id', 'project_id']);
            $table->timestampsTz();
        });
        Schema::create('bia_revisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained('isms_projects')->restrictOnDelete();
            $table->uuid('business_impact_analysis_id');
            $table->uuid('bia_configuration_version_id');
            $table->foreign(['business_impact_analysis_id', 'project_id'], 'bia_revision_identity_fk')->references(['id', 'project_id'])->on('business_impact_analyses')->restrictOnDelete();
            $table->foreign(['bia_configuration_version_id', 'project_id'], 'bia_revision_configuration_fk')->references(['id', 'project_id'])->on('bia_configuration_versions')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->unsignedInteger('lock_version')->default(1);
            $table->string('status')->default('draft');
            $table->string('owner_name', 160)->nullable();
            $table->bigInteger('mtpd_minutes')->nullable();
            $table->bigInteger('rto_minutes')->nullable();
            $table->bigInteger('rpo_minutes')->nullable();
            $table->boolean('rpo_not_applicable')->default(false);
            $table->text('rpo_reason')->nullable();
            $table->text('target_reason')->nullable();
            $table->jsonb('source_snapshot')->default('{}');
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->unique(['business_impact_analysis_id', 'version']);
            $table->unique(['id', 'project_id']);
            $table->timestampsTz();
        });
        Schema::create('bia_impacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->uuid('bia_revision_id');
            $table->foreign(['bia_revision_id', 'project_id'])->references(['id', 'project_id'])->on('bia_revisions')->restrictOnDelete();
            $table->string('area', 20);
            $table->integer('minutes');
            $table->integer('level')->nullable();
            $table->text('reason')->nullable();
            $table->unique(['bia_revision_id', 'area', 'minutes']);
            $table->timestampsTz();
        });
        DB::statement("CREATE UNIQUE INDEX bia_one_draft ON bia_revisions (business_impact_analysis_id) WHERE status = 'draft'");
        DB::statement("ALTER TABLE bia_revisions ADD CONSTRAINT bia_revision_values_check CHECK (status IN ('draft','approved') AND version >= 1 AND lock_version >= 1 AND (mtpd_minutes IS NULL OR mtpd_minutes > 0) AND (rto_minutes IS NULL OR rto_minutes > 0) AND (rpo_minutes IS NULL OR rpo_minutes >= 0) AND (NOT rpo_not_applicable OR rpo_minutes IS NULL))");
        DB::statement("ALTER TABLE bia_revisions ADD CONSTRAINT bia_revision_approval_check CHECK ((status = 'draft' AND approved_by IS NULL AND approved_at IS NULL) OR (status = 'approved' AND approved_by IS NOT NULL AND approved_at IS NOT NULL AND owner_name IS NOT NULL AND btrim(owner_name) <> '' AND mtpd_minutes IS NOT NULL AND rto_minutes IS NOT NULL AND rto_minutes < mtpd_minutes AND target_reason IS NOT NULL AND btrim(target_reason) <> '' AND ((NOT rpo_not_applicable AND rpo_minutes IS NOT NULL) OR (rpo_not_applicable AND rpo_reason IS NOT NULL AND btrim(rpo_reason) <> ''))))");
        DB::statement("ALTER TABLE bia_impacts ADD CONSTRAINT bia_impact_values_check CHECK (area IN ('financial','operational','legal','reputation') AND minutes > 0 AND minutes <= 525600 AND (level IS NULL OR level BETWEEN 1 AND 4))");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION protect_bcm_revision_history() RETURNS trigger AS $$
BEGIN
    IF OLD.status = 'approved' THEN RAISE EXCEPTION 'Approved BCM revisions are immutable'; END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE OR REPLACE FUNCTION protect_bia_identity() RETURNS trigger AS $$
BEGIN
    IF NEW.business_process_id <> OLD.business_process_id OR NEW.project_id <> OLD.project_id THEN
        RAISE EXCEPTION 'BIA identities cannot be reassigned';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER bia_identity_guard BEFORE UPDATE ON business_impact_analyses
FOR EACH ROW EXECUTE FUNCTION protect_bia_identity();
CREATE TRIGGER bia_revision_history_guard BEFORE UPDATE OR DELETE ON bia_revisions
FOR EACH ROW EXECUTE FUNCTION protect_bcm_revision_history();
CREATE OR REPLACE FUNCTION protect_bia_impact_history() RETURNS trigger AS $$
DECLARE
    parent bia_revisions%ROWTYPE;
BEGIN
    IF TG_OP <> 'INSERT' THEN
        SELECT * INTO parent FROM bia_revisions WHERE id = OLD.bia_revision_id FOR UPDATE;
        IF parent.status = 'approved' THEN RAISE EXCEPTION 'Approved BIA impacts are immutable'; END IF;
    END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    SELECT * INTO parent FROM bia_revisions WHERE id = NEW.bia_revision_id FOR UPDATE;
    IF parent.status = 'approved' THEN RAISE EXCEPTION 'Approved BIA impacts are immutable'; END IF;
    IF NOT EXISTS (SELECT 1 FROM bia_configuration_versions c WHERE c.id = parent.bia_configuration_version_id AND c.minutes @> to_jsonb(NEW.minutes)) THEN
        RAISE EXCEPTION 'Impact time is not in the revision configuration';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER bia_impact_history_guard BEFORE INSERT OR UPDATE OR DELETE ON bia_impacts
FOR EACH ROW EXECUTE FUNCTION protect_bia_impact_history();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('bia_impacts');
        Schema::dropIfExists('bia_revisions');
        Schema::dropIfExists('business_impact_analyses');
        DB::statement('DROP FUNCTION IF EXISTS protect_bia_impact_history()');
        DB::statement('DROP FUNCTION IF EXISTS protect_bcm_revision_history()');
        DB::statement('DROP FUNCTION IF EXISTS protect_bia_identity()');
    }
};
