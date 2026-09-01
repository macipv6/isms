<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->string('address')->nullable()->after('employee_count');
            $table->string('contact_name')->nullable()->after('address');
            $table->string('contact_email')->nullable()->after('contact_name');
            $table->string('contact_phone')->nullable()->after('contact_email');
            $table->text('notes')->nullable()->after('contact_phone');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn([
                'address',
                'contact_name',
                'contact_email',
                'contact_phone',
                'notes',
            ]);
        });
    }
};
