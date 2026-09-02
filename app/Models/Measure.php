<?php

namespace App\Models;

use App\Enums\MeasurePriority;
use App\Enums\MeasureStatus;
use Database\Factories\MeasureFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Measure extends Model
{
    /** @use HasFactory<MeasureFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'project_id',
        'finding_id',
        'title',
        'description',
        'priority',
        'responsible_name',
        'responsible_email',
        'due_date',
        'status',
        'created_by',
        'completed_by',
        'completed_at',
        'cancelled_reason',
    ];

    protected function casts(): array
    {
        return [
            'priority' => MeasurePriority::class,
            'due_date' => 'immutable_date',
            'status' => MeasureStatus::class,
            'completed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<IsmsProject, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(IsmsProject::class);
    }

    /**
     * @return BelongsTo<IsmsProject, $this>
     */
    public function ismsProject(): BelongsTo
    {
        return $this->project();
    }

    /**
     * @return BelongsTo<Finding, $this>
     */
    public function finding(): BelongsTo
    {
        return $this->belongsTo(Finding::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}
