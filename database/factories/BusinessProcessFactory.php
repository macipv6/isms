<?php

namespace Database\Factories;

use App\Models\BusinessProcess;
use App\Models\IsmsProject;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<BusinessProcess> */
class BusinessProcessFactory extends Factory
{
    protected $model = BusinessProcess::class;

    public function definition(): array
    {
        return [
            'project_id' => IsmsProject::factory(),
            'key' => 'PROCESS-'.Str::upper(Str::random(12)),
            'name' => fake()->sentence(3),
            'description' => fake()->optional()->paragraph(),
            'owner_name' => fake()->optional()->name(),
            'owner_email' => fake()->optional()->safeEmail(),
            'is_active' => true,
            'created_by' => User::factory(),
        ];
    }
}
