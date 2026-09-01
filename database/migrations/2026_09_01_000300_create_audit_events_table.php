<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('event_type', 128)->index();
            $table->uuid('actor_user_id')->nullable()->index();
            $table->uuid('organization_id')->nullable()->index();
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->jsonb('context')->default('{}');
            $table->timestampTz('occurred_at')->useCurrent()->index();
        });

        DB::unprepared(<<<'SQL'
CREATE FUNCTION reject_audit_event_mutation() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'audit_events are append-only';
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER audit_events_no_update
BEFORE UPDATE ON audit_events
FOR EACH ROW EXECUTE FUNCTION reject_audit_event_mutation();

CREATE TRIGGER audit_events_no_delete
BEFORE DELETE ON audit_events
FOR EACH ROW EXECUTE FUNCTION reject_audit_event_mutation();
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS audit_events_no_update ON audit_events;');
        DB::unprepared('DROP TRIGGER IF EXISTS audit_events_no_delete ON audit_events;');
        DB::unprepared('DROP FUNCTION IF EXISTS reject_audit_event_mutation();');
        Schema::dropIfExists('audit_events');
    }
};
