<?php

namespace Database\Factories;

use App\Enums\DependencyImportance;
use App\Models\Asset;
use App\Models\BusinessProcess;
use App\Models\DependencyEdge;
use App\Models\IsmsProject;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DependencyEdge> */
class DependencyEdgeFactory extends Factory
{
    protected $model = DependencyEdge::class;

    public function definition(): array
    {
        return [
            'project_id' => IsmsProject::factory(),
            'source_process_id' => fn (array $attributes): string => BusinessProcess::factory()
                ->for(IsmsProject::query()->findOrFail($attributes['project_id']))
                ->create()
                ->id,
            'source_asset_id' => null,
            'target_process_id' => null,
            'target_asset_id' => fn (array $attributes): string => Asset::factory()
                ->for(IsmsProject::query()->findOrFail($attributes['project_id']))
                ->create()
                ->id,
            'importance' => DependencyImportance::Supporting,
            'reason' => fake()->optional()->sentence(),
            'is_active' => true,
            'created_by' => User::factory(),
        ];
    }
}
