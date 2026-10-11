<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('findings', function (Blueprint $table): void {
            $table->string('origin_type')->default('assessment_question');
            $table->uuid('bcm_exercise_result_id')->nullable();
            $table->uuid('project_assessment_id')->nullable()->change();
            $table->uuid('assessment_question_id')->nullable()->change();
            $table->foreign(['bcm_exercise_result_id', 'project_id'], 'finding_exercise_result_project_fk')->references(['id', 'project_id'])->on('bcm_exercise_results')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE findings ADD CONSTRAINT finding_origin_check CHECK (
            (origin_type = 'assessment_question' AND project_assessment_id IS NOT NULL AND assessment_question_id IS NOT NULL AND bcm_exercise_result_id IS NULL)
            OR (origin_type = 'bcm_exercise_result' AND project_assessment_id IS NULL AND assessment_question_id IS NULL AND bcm_exercise_result_id IS NOT NULL))");
        DB::statement("CREATE UNIQUE INDEX findings_one_active_per_result ON findings (bcm_exercise_result_id) WHERE status IN ('proposed','accepted')");
        Schema::table('evidence_finding_links', function (Blueprint $table): void {
            $table->uuid('project_assessment_id')->nullable()->change();
            $table->foreign(['finding_id', 'project_id'], 'evidence_finding_links_finding_project_fk')->references(['id', 'project_id'])->on('findings')->cascadeOnUpdate()->restrictOnDelete();
        });
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION enforce_evidence_finding_origin() RETURNS trigger AS $$
DECLARE assessment_id uuid;
BEGIN
    SELECT project_assessment_id INTO assessment_id FROM findings WHERE id = NEW.finding_id AND project_id = NEW.project_id FOR KEY SHARE;
    IF FOUND AND NEW.project_assessment_id IS DISTINCT FROM assessment_id THEN RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'Evidence Finding assessment provenance must match'; END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER evidence_finding_origin_guard BEFORE INSERT OR UPDATE ON evidence_finding_links FOR EACH ROW EXECUTE FUNCTION enforce_evidence_finding_origin();
CREATE OR REPLACE FUNCTION protect_finding_origin() RETURNS trigger AS $$
BEGIN
    IF NEW.project_id IS DISTINCT FROM OLD.project_id OR NEW.origin_type IS DISTINCT FROM OLD.origin_type OR NEW.project_assessment_id IS DISTINCT FROM OLD.project_assessment_id OR NEW.assessment_question_id IS DISTINCT FROM OLD.assessment_question_id OR NEW.bcm_exercise_result_id IS DISTINCT FROM OLD.bcm_exercise_result_id THEN RAISE EXCEPTION USING ERRCODE = '23514', MESSAGE = 'Finding provenance is immutable'; END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER finding_origin_guard BEFORE UPDATE ON findings FOR EACH ROW EXECUTE FUNCTION protect_finding_origin();
SQL);
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            DB::statement('LOCK TABLE findings IN ACCESS EXCLUSIVE MODE');
            if (DB::table('findings')->where('origin_type', 'bcm_exercise_result')->exists()) {
                throw new RuntimeException('Rollback refused: BCM Findings retain exercise provenance.');
            }
            DB::statement('DROP TRIGGER evidence_finding_origin_guard ON evidence_finding_links');
            DB::statement('DROP FUNCTION enforce_evidence_finding_origin()');
            DB::statement('DROP TRIGGER finding_origin_guard ON findings');
            DB::statement('DROP FUNCTION protect_finding_origin()');
            Schema::table('evidence_finding_links', function (Blueprint $table): void {
                $table->dropForeign('evidence_finding_links_finding_project_fk');
                $table->uuid('project_assessment_id')->nullable(false)->change();
            });
            DB::statement('DROP INDEX findings_one_active_per_result');
            DB::statement('ALTER TABLE findings DROP CONSTRAINT finding_origin_check');
            Schema::table('findings', function (Blueprint $table): void {
                $table->dropForeign('finding_exercise_result_project_fk');
                $table->dropColumn(['origin_type', 'bcm_exercise_result_id']);
                $table->uuid('project_assessment_id')->nullable(false)->change();
                $table->uuid('assessment_question_id')->nullable(false)->change();
            });
        });
    }
};
