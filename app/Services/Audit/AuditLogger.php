<?php

namespace App\Services\Audit;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Http\Request;

class AuditLogger
{
    private const ALLOWED_CONTEXT_KEYS = [
        'reason',
        'entra_tenant_id',
        'changed_fields',
        'project_id',
        'catalog_version',
        'question_key',
        'evidence_id',
        'finding_id',
        'measure_id',
        'old_status',
        'new_status',
        'link_type',
        'failure_kind',
    ];

    /**
     * @param  array<string, string|list<string>|null>  $context
     */
    public function record(
        string $eventType,
        ?User $actor,
        array $context = [],
        ?string $organizationId = null,
    ): AuditEvent {
        $request = app()->bound('request') ? app(Request::class) : null;
        $safeContext = array_intersect_key($context, array_flip(self::ALLOWED_CONTEXT_KEYS));

        return AuditEvent::query()->create([
            'event_type' => $eventType,
            'actor_user_id' => $actor?->id,
            'organization_id' => $organizationId ?? $actor?->organization_id,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'context' => $safeContext,
            'occurred_at' => now(),
        ]);
    }
}
