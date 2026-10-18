<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_recovery_objective_revisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained('isms_projects')->restrictOnDelete();
            $table->uuid('asset_id');
            $table->foreign(['asset_id', 'project_id'])->references(['id', 'project_id'])->on('assets')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->unsignedInteger('lock_version')->default(1);
            $table->string('status')->default('draft');
            $table->bigInteger('rto_minutes')->nullable();
            $table->bigInteger('rpo_minutes')->nullable();
            $table->boolean('rpo_not_applicable')->default(false);
            $table->text('rpo_reason')->nullable();
            $table->text('target_reason')->nullable();
            $table->jsonb('source_snapshot')->default('{}');
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->unique(['asset_id', 'version']);
            $table->unique(['id', 'project_id']);
            $table->timestampsTz();
        });
        DB::statement("CREATE UNIQUE INDEX asset_recovery_one_draft ON asset_recovery_objective_revisions (asset_id) WHERE status = 'draft'");
        DB::statement("ALTER TABLE asset_recovery_objective_revisions ADD CONSTRAINT asset_recovery_values_check CHECK (status IN ('draft','approved') AND version >= 1 AND lock_version >= 1 AND (rto_minutes IS NULL OR rto_minutes > 0) AND (rpo_minutes IS NULL OR rpo_minutes >= 0) AND (NOT rpo_not_applicable OR rpo_minutes IS NULL))");
        DB::statement("ALTER TABLE asset_recovery_objective_revisions ADD CONSTRAINT asset_recovery_approval_check CHECK ((status = 'draft' AND approved_by IS NULL AND approved_at IS NULL) OR (status = 'approved' AND approved_by IS NOT NULL AND approved_at IS NOT NULL AND rto_minutes IS NOT NULL AND target_reason IS NOT NULL AND btrim(target_reason) <> '' AND ((NOT rpo_not_applicable AND rpo_minutes IS NOT NULL) OR (rpo_not_applicable AND rpo_reason IS NOT NULL AND btrim(rpo_reason) <> ''))))");
        DB::statement('CREATE TRIGGER asset_recovery_history_guard BEFORE UPDATE OR DELETE ON asset_recovery_objective_revisions FOR EACH ROW EXECUTE FUNCTION protect_bcm_revision_history()');
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_recovery_objective_revisions');
    }
};
