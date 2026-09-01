<?php

use App\Enums\ProjectStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('isms_projects', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('framework', 32)->default('BSI');
            $table->string('approach', 64)->default('basis_absicherung');
            $table->string('bcm_level', 64)->default('aufbau_bcms');
            $table->string('status', 32)->default(ProjectStatus::Draft->value);
            $table->text('scope_text')->nullable();
            $table->date('started_at')->nullable();
            $table->date('target_date')->nullable();
            $table->date('completed_at')->nullable();
            $table->foreignUuid('created_by')->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('isms_projects');
    }
};
