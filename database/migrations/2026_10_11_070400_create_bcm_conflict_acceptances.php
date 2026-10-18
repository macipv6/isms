<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bcm_conflict_acceptances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained('isms_projects')->restrictOnDelete();
            $table->uuid('bia_revision_id');
            $table->foreign(['bia_revision_id', 'project_id'])->references(['id', 'project_id'])->on('bia_revisions')->restrictOnDelete();
            $table->string('fingerprint', 64);
            $table->string('warning_code', 80);
            $table->jsonb('warning_snapshot');
            $table->text('reason');
            $table->foreignUuid('accepted_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('accepted_at');
            $table->unique(['bia_revision_id', 'fingerprint']);
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE bcm_conflict_acceptances ADD CONSTRAINT bcm_acceptance_values_check CHECK (fingerprint ~ '^[a-f0-9]{64}$' AND btrim(reason) <> '' AND length(reason) <= 10000)");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION protect_bcm_acceptance_history() RETURNS trigger AS $$
DECLARE
    parent_status text;
BEGIN
    IF TG_OP <> 'INSERT' THEN RAISE EXCEPTION 'BCM conflict decisions are immutable'; END IF;
    SELECT status INTO parent_status FROM bia_revisions WHERE id = NEW.bia_revision_id FOR UPDATE;
    IF parent_status <> 'draft' THEN RAISE EXCEPTION 'Approved BCM conflict decisions are immutable'; END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER bcm_acceptance_history_guard BEFORE INSERT OR UPDATE OR DELETE ON bcm_conflict_acceptances
FOR EACH ROW EXECUTE FUNCTION protect_bcm_acceptance_history();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('bcm_conflict_acceptances');
        DB::statement('DROP FUNCTION IF EXISTS protect_bcm_acceptance_history()');
    }
};
