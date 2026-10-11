<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_import_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('framework_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->string('version', 32);
            $table->longText('payload');
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('applied_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_import_batches');
    }
};
