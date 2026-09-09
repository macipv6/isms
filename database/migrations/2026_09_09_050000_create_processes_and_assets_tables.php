<?php

use App\Enums\AssetType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_processes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained('isms_projects')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('key', 64);
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->string('owner_name', 160)->nullable();
            $table->string('owner_email', 254)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignUuid('created_by')->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->timestampsTz();

            $table->unique(['id', 'project_id'], 'business_processes_id_project_id_unique');
            $table->unique(['project_id', 'key'], 'business_processes_project_key_unique');
        });

        Schema::create('assets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained('isms_projects')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('key', 64);
            $table->string('name', 160);
            $table->string('type', 20);
            $table->text('description')->nullable();
            $table->string('owner_name', 160)->nullable();
            $table->string('owner_email', 254)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignUuid('created_by')->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->timestampsTz();

            $table->unique(['id', 'project_id'], 'assets_id_project_id_unique');
            $table->unique(['project_id', 'key'], 'assets_project_key_unique');
        });

        DB::statement("ALTER TABLE business_processes ADD CONSTRAINT business_processes_key_format_check CHECK (key ~ '^[A-Z0-9][A-Z0-9._-]{1,63}$')");
        DB::statement('ALTER TABLE business_processes ADD CONSTRAINT business_processes_owner_email_length_check CHECK (owner_email IS NULL OR char_length(owner_email) <= 254)');
        DB::statement("ALTER TABLE assets ADD CONSTRAINT assets_key_format_check CHECK (key ~ '^[A-Z0-9][A-Z0-9._-]{1,63}$')");
        DB::statement('ALTER TABLE assets ADD CONSTRAINT assets_owner_email_length_check CHECK (owner_email IS NULL OR char_length(owner_email) <= 254)');
        DB::statement("ALTER TABLE assets ADD CONSTRAINT assets_type_check CHECK (type IN ('".AssetType::Information->value."', '".AssetType::Application->value."', '".AssetType::ItSystem->value."', '".AssetType::Service->value."', '".AssetType::Facility->value."'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE assets DROP CONSTRAINT IF EXISTS assets_type_check');
        DB::statement('ALTER TABLE assets DROP CONSTRAINT IF EXISTS assets_owner_email_length_check');
        DB::statement('ALTER TABLE assets DROP CONSTRAINT IF EXISTS assets_key_format_check');
        DB::statement('ALTER TABLE business_processes DROP CONSTRAINT IF EXISTS business_processes_owner_email_length_check');
        DB::statement('ALTER TABLE business_processes DROP CONSTRAINT IF EXISTS business_processes_key_format_check');

        Schema::dropIfExists('assets');
        Schema::dropIfExists('business_processes');
    }
};
