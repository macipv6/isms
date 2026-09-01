<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use Database\Factories\IsmsProjectFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property ProjectStatus $status
 */
class IsmsProject extends Model
{
    /** @use HasFactory<IsmsProjectFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'organization_id',
        'name',
        'description',
        'framework',
        'approach',
        'bcm_level',
        'status',
        'scope_text',
        'started_at',
        'target_date',
        'completed_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'started_at' => 'immutable_date',
            'target_date' => 'immutable_date',
            'completed_at' => 'immutable_date',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
