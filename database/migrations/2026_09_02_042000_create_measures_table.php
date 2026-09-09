<?php

use App\Enums\MeasureStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('measures', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->uuid('finding_id');
            $table->string('title');
            $table->text('description');
            $table->string('priority', 20);
            $table->string('responsible_name');
            $table->string('responsible_email')->nullable();
            $table->date('due_date');
            $table->string('status', 20)->default(MeasureStatus::Planned->value);
            $table->foreignUuid('created_by')->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignUuid('completed_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestampTz('completed_at')->nullable();
            $table->text('cancelled_reason')->nullable();
            $table->timestampsTz();

            $table->foreign(['finding_id', 'project_id'], 'measures_finding_project_foreign')
                ->references(['id', 'project_id'])
                ->on('findings')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->index(['project_id', 'status']);
            $table->index(['finding_id', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('measures');
    }
};
