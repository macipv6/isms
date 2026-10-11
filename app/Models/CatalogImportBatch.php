<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @phpstan-import-type CatalogCsvRow from \App\Services\Assessment\CatalogCsvParser
 *
 * @property list<CatalogCsvRow> $payload
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $applied_at
 */
class CatalogImportBatch extends Model
{
    use HasUuids;

    // PostgreSQL timestamptz must receive the application timezone offset.
    protected $dateFormat = 'Y-m-d H:i:sP';

    protected $fillable = ['framework_id', 'created_by', 'version', 'payload', 'expires_at', 'applied_at'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'expires_at' => 'immutable_datetime', 'applied_at' => 'immutable_datetime'];
    }
}
