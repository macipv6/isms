<?php

use App\Enums\PublicationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_methodologies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('key', 64)->unique();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->timestampsTz();
        });

        Schema::create('risk_methodology_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('methodology_id')->constrained('risk_methodologies')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 20)->default(PublicationStatus::Draft->value);
            $table->text('description');
            $table->string('source_reference', 255)->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->foreignUuid('published_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['methodology_id', 'version']);
        });

        foreach (['risk_frequency_levels', 'risk_impact_levels', 'risk_levels'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('version_id')->constrained('risk_methodology_versions')->restrictOnDelete();
                $table->string('key', 64);
                $table->string('label', 160);
                $table->text('description')->nullable();
                $table->unsignedInteger('sort_order');
                $table->timestampsTz();
                $table->unique(['version_id', 'id'], $name.'_version_id_id_unique');
                $table->unique(['version_id', 'key'], $name.'_version_key_unique');
                $table->unique(['version_id', 'sort_order'], $name.'_version_order_unique');
            });
            DB::statement("ALTER TABLE {$name} ADD CONSTRAINT {$name}_positive_order CHECK (sort_order > 0)");
        }

        Schema::create('risk_matrix_cells', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('version_id')->constrained('risk_methodology_versions')->restrictOnDelete();
            $table->uuid('frequency_level_id');
            $table->uuid('impact_level_id');
            $table->uuid('risk_level_id');
            $table->timestampsTz();
            $table->unique(['version_id', 'frequency_level_id', 'impact_level_id'], 'risk_matrix_cells_coordinate_unique');
            $table->foreign(['version_id', 'frequency_level_id'], 'risk_matrix_frequency_version_fk')
                ->references(['version_id', 'id'])->on('risk_frequency_levels')->restrictOnDelete();
            $table->foreign(['version_id', 'impact_level_id'], 'risk_matrix_impact_version_fk')
                ->references(['version_id', 'id'])->on('risk_impact_levels')->restrictOnDelete();
            $table->foreign(['version_id', 'risk_level_id'], 'risk_matrix_risk_version_fk')
                ->references(['version_id', 'id'])->on('risk_levels')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE risk_methodology_versions ADD CONSTRAINT risk_methodology_version_positive CHECK (version > 0)');
        DB::statement("ALTER TABLE risk_methodology_versions ADD CONSTRAINT risk_methodology_status_check CHECK (status IN ('draft', 'published'))");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION reject_published_risk_methodology_mutation() RETURNS trigger AS $$
DECLARE old_status text;
DECLARE new_status text;
BEGIN
    IF TG_TABLE_NAME = 'risk_methodology_versions' THEN
        IF OLD.status = 'published' THEN
            RAISE EXCEPTION 'Published risk methodology versions are immutable';
        END IF;
    ELSE
        IF TG_OP <> 'INSERT' THEN
            SELECT status INTO old_status FROM risk_methodology_versions WHERE id = OLD.version_id FOR SHARE;
            IF old_status = 'published' THEN
                RAISE EXCEPTION 'Published risk methodology children are immutable';
            END IF;
        END IF;
        IF TG_OP <> 'DELETE' THEN
            SELECT status INTO new_status FROM risk_methodology_versions WHERE id = NEW.version_id FOR SHARE;
            IF new_status = 'published' THEN
                RAISE EXCEPTION 'Published risk methodology children are immutable';
            END IF;
        END IF;
    END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);
        DB::statement('CREATE TRIGGER risk_methodology_versions_immutable BEFORE UPDATE OR DELETE ON risk_methodology_versions FOR EACH ROW EXECUTE FUNCTION reject_published_risk_methodology_mutation()');
        foreach (['risk_frequency_levels', 'risk_impact_levels', 'risk_levels', 'risk_matrix_cells'] as $name) {
            DB::statement("CREATE TRIGGER {$name}_immutable BEFORE INSERT OR UPDATE OR DELETE ON {$name} FOR EACH ROW EXECUTE FUNCTION reject_published_risk_methodology_mutation()");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_matrix_cells');
        Schema::dropIfExists('risk_levels');
        Schema::dropIfExists('risk_impact_levels');
        Schema::dropIfExists('risk_frequency_levels');
        Schema::dropIfExists('risk_methodology_versions');
        Schema::dropIfExists('risk_methodologies');
        DB::statement('DROP FUNCTION IF EXISTS reject_published_risk_methodology_mutation()');
    }
};
