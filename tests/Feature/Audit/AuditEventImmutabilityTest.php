<?php

namespace Tests\Feature\Audit;

use App\Models\AuditEvent;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditEventImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_events_cannot_be_updated(): void
    {
        $event = AuditEvent::query()->create(['event_type' => 'test.created', 'context' => []]);

        $this->expectException(QueryException::class);
        DB::table('audit_events')->where('id', $event->id)->update(['event_type' => 'test.changed']);
    }

    public function test_audit_events_cannot_be_deleted(): void
    {
        $event = AuditEvent::query()->create(['event_type' => 'test.created', 'context' => []]);

        $this->expectException(QueryException::class);
        DB::table('audit_events')->where('id', $event->id)->delete();
    }
}
