<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CatalogImportBatch extends Model
{
    use HasUuids;

    protected $fillable = ['framework_id', 'created_by', 'version', 'payload', 'expires_at', 'applied_at'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'expires_at' => 'immutable_datetime', 'applied_at' => 'immutable_datetime'];
    }
}
