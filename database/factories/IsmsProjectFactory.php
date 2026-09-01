<?php

namespace Database\Factories;

use App\Enums\ProjectStatus;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<IsmsProject> */
class IsmsProjectFactory extends Factory
{
    protected $model = IsmsProject::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory()->state([
                'organization_type' => 'customer',
                'entra_tenant_id' => null,
            ]),
            'name' => 'ISMS '.fake()->year(),
            'description' => fake()->optional()->sentence(),
            'framework' => 'BSI',
            'approach' => 'basis_absicherung',
            'bcm_level' => 'aufbau_bcms',
            'status' => ProjectStatus::Draft,
            'scope_text' => fake()->optional()->paragraph(),
            'started_at' => null,
            'target_date' => null,
            'completed_at' => null,
            'created_by' => User::factory(),
        ];
    }
}
