<?php

namespace Database\Factories;

use App\Enums\AssetType;
use App\Models\Asset;
use App\Models\IsmsProject;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Asset> */
class AssetFactory extends Factory
{
    protected $model = Asset::class;

    public function definition(): array
    {
        return [
            'project_id' => IsmsProject::factory(),
            'key' => 'ASSET-'.Str::upper(Str::random(12)),
            'name' => fake()->sentence(3),
            'type' => fake()->randomElement(AssetType::cases()),
            'description' => fake()->optional()->paragraph(),
            'owner_name' => fake()->optional()->name(),
            'owner_email' => fake()->optional()->safeEmail(),
            'is_active' => true,
            'created_by' => User::factory(),
        ];
    }
}
