<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('measures', fn (Blueprint $table) => $table->unique(['id', 'project_id']));

        Schema::create('risk_treatments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->uuid('risk_analysis_cycle_id');
            $table->string('action', 16);
            $table->text('rationale');
            $table->string('counterparty_name')->nullable();
            $table->string('counterparty_email')->nullable();
            $table->text('cessation_statement')->nullable();
            $table->foreignUuid('selected_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->foreign(['risk_analysis_cycle_id', 'project_id'])->references(['id', 'project_id'])->on('risk_analysis_cycles')->restrictOnDelete();
            $table->unique('risk_analysis_cycle_id');
            $table->unique(['id', 'project_id']);
        });
        DB::statement("ALTER TABLE risk_treatments ADD CONSTRAINT risk_treatment_action_check CHECK (action IN ('avoid','reduce','transfer','accept'))");
        DB::statement("ALTER TABLE risk_treatments ADD CONSTRAINT risk_treatment_details_check CHECK (length(trim(rationale)) > 0 AND length(rationale) <= 10000 AND (action <> 'transfer' OR coalesce(length(trim(counterparty_name)) > 0, false)) AND (action <> 'avoid' OR coalesce(length(trim(cessation_statement)) > 0, false)))");
        DB::statement('CREATE TRIGGER risk_treatment_completed_immutable BEFORE INSERT OR UPDATE OR DELETE ON risk_treatments FOR EACH ROW EXECUTE FUNCTION protect_completed_risk_child()');

        foreach (['finding' => 'findings', 'measure' => 'measures', 'evidence' => 'evidence_files'] as $kind => $source) {
            Schema::create('risk_analysis_'.$kind.'_links', function (Blueprint $table) use ($kind, $source): void {
                $table->uuid('id')->primary();
                $table->uuid('project_id');
                $table->uuid('risk_analysis_cycle_id');
                $table->uuid($kind.'_id');
                $table->timestampsTz();
                $table->foreign(['risk_analysis_cycle_id', 'project_id'])->references(['id', 'project_id'])->on('risk_analysis_cycles')->restrictOnDelete();
                $table->foreign([$kind.'_id', 'project_id'])->references(['id', 'project_id'])->on($source)->restrictOnDelete();
                $table->unique(['risk_analysis_cycle_id', $kind.'_id']);
            });
            DB::statement('CREATE TRIGGER risk_'.$kind.'_link_completed_immutable BEFORE INSERT OR UPDATE OR DELETE ON risk_analysis_'.$kind.'_links FOR EACH ROW EXECUTE FUNCTION protect_completed_risk_child()');
        }

        Schema::create('risk_decisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->uuid('risk_analysis_cycle_id');
            $table->string('status', 16);
            $table->string('channel', 16);
            $table->text('rationale');
            $table->date('decision_date');
            $table->foreignUuid('recorded_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('internal_decision_maker_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('external_decision_maker_name')->nullable();
            $table->string('external_decision_maker_function')->nullable();
            $table->timestampTz('invalidated_at')->nullable();
            $table->foreignUuid('invalidated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('invalidation_reason', 32)->nullable();
            $table->timestampsTz();
            $table->foreign(['risk_analysis_cycle_id', 'project_id'])->references(['id', 'project_id'])->on('risk_analysis_cycles')->restrictOnDelete();
            $table->unique(['id', 'project_id', 'risk_analysis_cycle_id'], 'risk_decision_id_project_cycle_unique');
        });
        DB::statement("ALTER TABLE risk_decisions ADD CONSTRAINT risk_decision_values_check CHECK (status IN ('accepted','rejected') AND channel IN ('internal','external') AND length(trim(rationale)) > 0 AND length(rationale) <= 10000)");
        DB::statement("ALTER TABLE risk_decisions ADD CONSTRAINT risk_decision_actor_check CHECK ((channel = 'internal' AND internal_decision_maker_id IS NOT NULL AND external_decision_maker_name IS NULL AND external_decision_maker_function IS NULL) OR (channel = 'external' AND internal_decision_maker_id IS NULL AND coalesce(length(trim(external_decision_maker_name)) > 0, false) AND coalesce(length(trim(external_decision_maker_function)) > 0, false)))");
        DB::statement('CREATE UNIQUE INDEX risk_decision_one_current ON risk_decisions (risk_analysis_cycle_id) WHERE invalidated_at IS NULL');
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION protect_completed_risk_decision() RETURNS trigger AS $$
DECLARE cycle_status text;
BEGIN
    SELECT status INTO cycle_status FROM risk_analysis_cycles WHERE id = COALESCE(NEW.risk_analysis_cycle_id, OLD.risk_analysis_cycle_id);
    IF cycle_status IN ('completed', 'superseded') THEN
        IF TG_OP = 'DELETE' OR TG_OP = 'INSERT' THEN
            RAISE EXCEPTION 'Historical risk decisions are immutable';
        END IF;
        IF OLD.invalidated_at IS NOT NULL
            OR NEW.invalidated_at IS NULL
            OR (to_jsonb(NEW) - 'invalidated_at' - 'invalidated_by' - 'invalidation_reason' - 'updated_at')
                IS DISTINCT FROM (to_jsonb(OLD) - 'invalidated_at' - 'invalidated_by' - 'invalidation_reason' - 'updated_at') THEN
            RAISE EXCEPTION 'Historical risk decisions are immutable';
        END IF;
    END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);
        DB::statement('CREATE TRIGGER risk_decision_completed_immutable BEFORE INSERT OR UPDATE OR DELETE ON risk_decisions FOR EACH ROW EXECUTE FUNCTION protect_completed_risk_decision()');

        Schema::create('risk_decision_evidence_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->uuid('risk_analysis_cycle_id');
            $table->uuid('risk_decision_id');
            $table->uuid('evidence_id');
            $table->timestampsTz();
            $table->foreign(['risk_analysis_cycle_id', 'project_id'])->references(['id', 'project_id'])->on('risk_analysis_cycles')->restrictOnDelete();
            $table->foreign(['risk_decision_id', 'project_id', 'risk_analysis_cycle_id'], 'risk_decision_evidence_decision_fk')->references(['id', 'project_id', 'risk_analysis_cycle_id'])->on('risk_decisions')->restrictOnDelete();
            $table->foreign(['evidence_id', 'project_id'])->references(['id', 'project_id'])->on('evidence_files')->restrictOnDelete();
            $table->unique(['risk_decision_id', 'evidence_id']);
        });
        DB::statement('CREATE TRIGGER risk_decision_evidence_completed_immutable BEFORE INSERT OR UPDATE OR DELETE ON risk_decision_evidence_links FOR EACH ROW EXECUTE FUNCTION protect_completed_risk_child()');
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_decision_evidence_links');
        Schema::dropIfExists('risk_decisions');
        DB::statement('DROP FUNCTION IF EXISTS protect_completed_risk_decision()');
        foreach (['finding', 'measure', 'evidence'] as $kind) {
            Schema::dropIfExists('risk_analysis_'.$kind.'_links');
        }
        Schema::dropIfExists('risk_treatments');
        Schema::table('measures', fn (Blueprint $table) => $table->dropUnique(['id', 'project_id']));
    }
};
