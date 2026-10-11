<?php

namespace Database\Factories;

use App\Models\BusinessProcess;
use App\Models\ProtectionNeed;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProtectionNeed> */
class ProtectionNeedFactory extends Factory
{
    protected $model = ProtectionNeed::class;

    public function definition(): array
    {
        return [
            'business_process_id' => BusinessProcess::factory(),
            'project_id' => fn (array $attributes): string => BusinessProcess::query()->findOrFail($attributes['business_process_id'])->project_id,
            'objective' => 'confidentiality',
            'status' => 'draft',
        ];
    }
}
