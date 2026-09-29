<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('threat_catalogs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('key', 64)->unique();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->timestampsTz();
        });
        Schema::create('threat_catalog_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('catalog_id')->constrained('threat_catalogs')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 20)->default('draft');
            $table->text('description');
            $table->string('source_edition', 32);
            $table->string('source_reference', 512);
            $table->timestampTz('published_at')->nullable();
            $table->foreignUuid('published_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['catalog_id', 'version']);
        });
        Schema::create('threat_catalog_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('version_id')->constrained('threat_catalog_versions')->restrictOnDelete();
            $table->string('code', 32);
            $table->string('title', 255);
            $table->text('description');
            $table->boolean('confidentiality')->default(false);
            $table->boolean('integrity')->default(false);
            $table->boolean('availability')->default(false);
            $table->unsignedInteger('sort_order');
            $table->string('source_edition', 32);
            $table->string('source_reference', 512);
            $table->timestampsTz();
            $table->unique(['version_id', 'id']);
            $table->unique(['version_id', 'code']);
            $table->unique(['version_id', 'sort_order']);
        });
        DB::statement("ALTER TABLE threat_catalog_versions ADD CONSTRAINT threat_catalog_version_status CHECK (status IN ('draft', 'published'))");
        DB::statement('ALTER TABLE threat_catalog_versions ADD CONSTRAINT threat_catalog_version_positive CHECK (version > 0)');
        DB::statement('ALTER TABLE threat_catalog_entries ADD CONSTRAINT threat_catalog_entry_order_positive CHECK (sort_order > 0)');
        DB::statement('ALTER TABLE threat_catalog_entries ADD CONSTRAINT threat_catalog_entry_cia CHECK (confidentiality OR integrity OR availability)');

        Schema::create('project_risk_contexts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->unique()->constrained('isms_projects')->restrictOnDelete();
            $table->foreignUuid('methodology_version_id')->constrained('risk_methodology_versions')->restrictOnDelete();
            $table->foreignUuid('catalog_version_id')->constrained('threat_catalog_versions')->restrictOnDelete();
            $table->foreignUuid('initialized_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('initialized_at');
            $table->timestampsTz();
            $table->unique(['id', 'project_id']);
        });
        Schema::create('project_risk_context_changes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_risk_context_id');
            $table->uuid('project_id');
            $table->string('action', 20);
            $table->foreignUuid('old_methodology_version_id')->nullable()->constrained('risk_methodology_versions')->restrictOnDelete();
            $table->foreignUuid('new_methodology_version_id')->constrained('risk_methodology_versions')->restrictOnDelete();
            $table->foreignUuid('old_catalog_version_id')->nullable()->constrained('threat_catalog_versions')->restrictOnDelete();
            $table->foreignUuid('new_catalog_version_id')->constrained('threat_catalog_versions')->restrictOnDelete();
            $table->foreignUuid('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('changed_at');
            $table->timestampsTz();
            $table->foreign(['project_risk_context_id', 'project_id'], 'risk_context_changes_context_project_fk')->references(['id', 'project_id'])->on('project_risk_contexts')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE project_risk_context_changes ADD CONSTRAINT risk_context_change_action CHECK (action IN ('initialized', 'migrated'))");

        Schema::create('project_threats', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->uuid('project_risk_context_id');
            $table->foreignUuid('catalog_version_id')->constrained('threat_catalog_versions')->restrictOnDelete();
            $table->uuid('threat_catalog_entry_id')->nullable();
            $table->string('code', 32);
            $table->string('title', 255);
            $table->text('description');
            $table->boolean('confidentiality')->default(false);
            $table->boolean('integrity')->default(false);
            $table->boolean('availability')->default(false);
            $table->unsignedInteger('sort_order');
            $table->string('source_edition', 32)->nullable();
            $table->string('source_reference', 512)->nullable();
            $table->text('addition_rationale')->nullable();
            $table->string('responsible_name', 160)->nullable();
            $table->string('responsible_email', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('retired_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->foreign(['project_risk_context_id', 'project_id'], 'project_threats_context_project_fk')->references(['id', 'project_id'])->on('project_risk_contexts')->restrictOnDelete();
            $table->foreign(['catalog_version_id', 'threat_catalog_entry_id'], 'project_threats_entry_version_fk')->references(['version_id', 'id'])->on('threat_catalog_entries')->restrictOnDelete();
            $table->unique(['project_id', 'catalog_version_id', 'code']);
        });
        DB::statement('ALTER TABLE project_threats ADD CONSTRAINT project_threat_cia CHECK (confidentiality OR integrity OR availability)');
        DB::statement('ALTER TABLE project_threats ADD CONSTRAINT project_threat_custom_details CHECK (threat_catalog_entry_id IS NOT NULL OR (addition_rationale IS NOT NULL AND responsible_name IS NOT NULL AND created_by IS NOT NULL))');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION reject_published_threat_catalog_mutation() RETURNS trigger AS $$
DECLARE parent_status text;
BEGIN
    IF TG_TABLE_NAME = 'threat_catalog_versions' THEN
        IF OLD.status = 'published' THEN
            RAISE EXCEPTION 'Published threat catalog versions are immutable';
        END IF;
    ELSE
        IF TG_OP <> 'INSERT' THEN
            SELECT status INTO parent_status FROM threat_catalog_versions WHERE id = OLD.version_id FOR SHARE;
            IF parent_status = 'published' THEN
                RAISE EXCEPTION 'Published threat catalog entries are immutable';
            END IF;
        END IF;
        IF TG_OP <> 'DELETE' THEN
            SELECT status INTO parent_status FROM threat_catalog_versions WHERE id = NEW.version_id FOR SHARE;
            IF parent_status = 'published' THEN
                RAISE EXCEPTION 'Published threat catalog entries are immutable';
            END IF;
        END IF;
    END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);
        DB::statement('CREATE TRIGGER threat_catalog_versions_immutable BEFORE UPDATE OR DELETE ON threat_catalog_versions FOR EACH ROW EXECUTE FUNCTION reject_published_threat_catalog_mutation()');
        DB::statement('CREATE TRIGGER threat_catalog_entries_immutable BEFORE INSERT OR UPDATE OR DELETE ON threat_catalog_entries FOR EACH ROW EXECUTE FUNCTION reject_published_threat_catalog_mutation()');
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION reject_baseline_project_threat_mutation() RETURNS trigger AS $$
BEGIN
    IF OLD.threat_catalog_entry_id IS NOT NULL THEN
        RAISE EXCEPTION 'Baseline project threats are immutable';
    END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);
        DB::statement('CREATE TRIGGER project_baseline_threats_immutable BEFORE UPDATE OR DELETE ON project_threats FOR EACH ROW EXECUTE FUNCTION reject_baseline_project_threat_mutation()');
    }

    public function down(): void
    {
        Schema::dropIfExists('project_threats');
        Schema::dropIfExists('project_risk_context_changes');
        Schema::dropIfExists('project_risk_contexts');
        Schema::dropIfExists('threat_catalog_entries');
        Schema::dropIfExists('threat_catalog_versions');
        Schema::dropIfExists('threat_catalogs');
        DB::statement('DROP FUNCTION IF EXISTS reject_baseline_project_threat_mutation()');
        DB::statement('DROP FUNCTION IF EXISTS reject_published_threat_catalog_mutation()');
    }
};
