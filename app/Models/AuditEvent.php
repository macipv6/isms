<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AuditEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'event_type',
        'actor_user_id',
        'organization_id',
        'ip_address',
        'user_agent',
        'context',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
