<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class FindingAssetLink extends Model
{
    use HasUuids;

    protected $fillable = ['project_id', 'finding_id', 'asset_id'];
}
