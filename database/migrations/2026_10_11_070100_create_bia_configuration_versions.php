<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bia_configuration_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained('isms_projects')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->unsignedInteger('lock_version')->default(1);
            $table->jsonb('minutes');
            $table->string('status')->default('approved');
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('approved_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('approved_at');
            $table->timestampsTz();
            $table->unique(['project_id', 'version']);
            $table->unique(['id', 'project_id']);
        });
        Schema::table('isms_projects', function (Blueprint $table) {
            $table->uuid('bia_configuration_version_id')->nullable();
            $table->unsignedInteger('bia_configuration_lock_version')->default(0);
            $table->foreign(['bia_configuration_version_id', 'id'], 'project_bia_configuration_fk')->references(['id', 'project_id'])->on('bia_configuration_versions')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE bia_configuration_versions ADD CONSTRAINT bia_configuration_metadata_check CHECK (status = 'approved' AND version >= 1 AND lock_version = 1)");
        DB::statement('ALTER TABLE isms_projects ADD CONSTRAINT project_bia_head_check CHECK ((bia_configuration_version_id IS NULL AND bia_configuration_lock_version = 0) OR (bia_configuration_version_id IS NOT NULL AND bia_configuration_lock_version >= 1))');
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION valid_bia_minutes(values_json jsonb) RETURNS boolean AS $$
DECLARE
    entry jsonb;
    minute numeric;
    previous numeric := 0;
BEGIN
    IF jsonb_typeof(values_json) <> 'array' THEN RETURN false; END IF;
    IF jsonb_array_length(values_json) < 2 OR jsonb_array_length(values_json) > 12 THEN RETURN false; END IF;
    FOR entry IN SELECT value FROM jsonb_array_elements(values_json) LOOP
        IF jsonb_typeof(entry) <> 'number' OR entry::text !~ '^[0-9]+$' THEN RETURN false; END IF;
        minute := entry::text::numeric;
        IF minute <= previous OR minute > 525600 THEN RETURN false; END IF;
        previous := minute;
    END LOOP;
    RETURN true;
END;
$$ LANGUAGE plpgsql IMMUTABLE;
ALTER TABLE bia_configuration_versions ADD CONSTRAINT bia_configuration_minutes_check CHECK (valid_bia_minutes(minutes));
CREATE OR REPLACE FUNCTION protect_bia_configuration_history() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'Activated BIA configuration versions are immutable';
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER bia_configuration_history_guard BEFORE UPDATE OR DELETE ON bia_configuration_versions
FOR EACH ROW EXECUTE FUNCTION protect_bia_configuration_history();
SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE isms_projects DROP CONSTRAINT project_bia_head_check');
        Schema::table('isms_projects', function (Blueprint $table) {
            $table->dropForeign('project_bia_configuration_fk');
            $table->dropColumn(['bia_configuration_version_id', 'bia_configuration_lock_version']);
        });
        Schema::dropIfExists('bia_configuration_versions');
        DB::statement('DROP FUNCTION IF EXISTS protect_bia_configuration_history()');
        DB::statement('DROP FUNCTION IF EXISTS valid_bia_minutes(jsonb)');
    }
};
