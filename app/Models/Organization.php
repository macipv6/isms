<?php

namespace App\Models;

use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    use HasUuids, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'organization_type',
        'industry',
        'employee_count',
        'address',
        'contact_name',
        'contact_email',
        'contact_phone',
        'notes',
        'entra_tenant_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'employee_count' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
