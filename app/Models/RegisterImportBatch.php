<?php

namespace App\Models;

use App\Enums\RegisterImportKind;
use App\Enums\RegisterImportStatus;
use Carbon\CarbonImmutable;
use Database\Factories\RegisterImportBatchFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property RegisterImportKind $kind
 * @property RegisterImportStatus $status
 * @property array<mixed> $payload
 * @property array<mixed> $summary
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $applied_at
 */
class RegisterImportBatch extends Model
{
    /** @use HasFactory<RegisterImportBatchFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'project_id', 'kind', 'created_by', 'sha256', 'payload', 'summary', 'status', 'expires_at', 'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'kind' => RegisterImportKind::class,
            'payload' => 'array',
            'summary' => 'array',
            'status' => RegisterImportStatus::class,
            'expires_at' => 'immutable_datetime',
            'applied_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<IsmsProject, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(IsmsProject::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
