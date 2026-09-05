<?php

use App\Enums\EvidenceReviewStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evidence_files', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained('isms_projects')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('storage_path');
            $table->string('original_name');
            $table->string('mime_type', 127);
            $table->string('file_kind', 32);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->string('status', 32)->default(EvidenceReviewStatus::PendingReview->value);
            $table->foreignUuid('uploaded_by')->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->timestampTz('uploaded_at');
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestampsTz();

            $table->unique(['project_id', 'sha256'], 'evidence_files_project_sha256_unique');
            $table->unique(['id', 'project_id'], 'evidence_files_id_project_id_unique');
            $table->index(['project_id', 'status']);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prevent_evidence_original_metadata_change()
            RETURNS TRIGGER AS $$
            BEGIN
                IF NEW.storage_path IS DISTINCT FROM OLD.storage_path
                    OR NEW.original_name IS DISTINCT FROM OLD.original_name
                    OR NEW.mime_type IS DISTINCT FROM OLD.mime_type
                    OR NEW.file_kind IS DISTINCT FROM OLD.file_kind
                    OR NEW.size_bytes IS DISTINCT FROM OLD.size_bytes
                    OR NEW.sha256 IS DISTINCT FROM OLD.sha256
                    OR NEW.uploaded_by IS DISTINCT FROM OLD.uploaded_by
                    OR NEW.uploaded_at IS DISTINCT FROM OLD.uploaded_at THEN
                    RAISE EXCEPTION 'evidence original metadata is immutable';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER evidence_files_original_metadata_immutable
            BEFORE UPDATE ON evidence_files
            FOR EACH ROW
            EXECUTE FUNCTION prevent_evidence_original_metadata_change();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS evidence_files_original_metadata_immutable ON evidence_files');
        DB::unprepared('DROP FUNCTION IF EXISTS prevent_evidence_original_metadata_change()');

        Schema::dropIfExists('evidence_files');
    }
};
