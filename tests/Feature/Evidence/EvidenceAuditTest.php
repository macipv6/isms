<?php

namespace Tests\Feature\Evidence;

use App\Models\AuditEvent;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EvidenceAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_evidence_audit_context_retains_only_allowed_identifiers_and_state_metadata(): void
    {
        $actor = User::factory()->create();
        $context = [
            'project_id' => 'project-id',
            'evidence_id' => 'evidence-id',
            'finding_id' => 'finding-id',
            'measure_id' => 'measure-id',
            'old_status' => 'pending_review',
            'new_status' => 'verified',
            'link_type' => 'finding',
            'failure_kind' => 'missing_object',
            'original_name' => 'secret-policy.txt',
            'sha256' => str_repeat('a', 64),
            'content' => 'approved policy content',
            'review_note' => 'private review note',
            'storage_path' => 'projects/private/evidence.txt',
            'description' => 'private finding description',
            'email' => 'private@example.test',
            'name' => 'Private Person',
        ];

        app(AuditLogger::class)->record('evidence.reviewed', $actor, $context);

        $event = AuditEvent::query()->sole();
        $encoded = json_encode($event->context, JSON_THROW_ON_ERROR);

        $this->assertSame([
            'project_id' => 'project-id',
            'evidence_id' => 'evidence-id',
            'finding_id' => 'finding-id',
            'measure_id' => 'measure-id',
            'old_status' => 'pending_review',
            'new_status' => 'verified',
            'link_type' => 'finding',
            'failure_kind' => 'missing_object',
        ], $event->context);
        foreach ([
            'secret-policy.txt',
            str_repeat('a', 64),
            'approved policy content',
            'private review note',
            'projects/private/evidence.txt',
            'private finding description',
            'private@example.test',
            'Private Person',
        ] as $sensitiveValue) {
            $this->assertStringNotContainsString($sensitiveValue, $encoded);
        }
    }
}
