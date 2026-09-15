<?php

use App\Enums\RegisterImportStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('register_import_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained('isms_projects')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('kind', 20);
            $table->foreignUuid('created_by')->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->char('sha256', 64);
            $table->jsonb('payload');
            $table->jsonb('summary');
            $table->string('status', 20)->default(RegisterImportStatus::Pending->value);
            $table->timestampTz('expires_at');
            $table->timestampTz('applied_at')->nullable();
            $table->timestampsTz();

            $table->index(['project_id', 'kind', 'status']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('register_import_batches');
    }
};
