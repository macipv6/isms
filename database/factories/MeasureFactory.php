<?php

namespace Database\Factories;

use App\Enums\FindingStatus;
use App\Enums\MeasurePriority;
use App\Enums\MeasureStatus;
use App\Models\Finding;
use App\Models\IsmsProject;
use App\Models\Measure;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Measure> */
class MeasureFactory extends Factory
{
    protected $model = Measure::class;

    public function definition(): array
    {
        return [
            'project_id' => IsmsProject::factory(),
            'finding_id' => fn (array $attributes): string => Finding::factory()
                ->for(IsmsProject::query()->findOrFail($attributes['project_id']))
                ->create(['status' => FindingStatus::Accepted])
                ->id,
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'priority' => fake()->randomElement(MeasurePriority::cases()),
            'responsible_name' => fake()->name(),
            'responsible_email' => fake()->optional()->safeEmail(),
            'due_date' => fake()->dateTimeBetween('+1 day', '+90 days'),
            'status' => MeasureStatus::Planned,
            'created_by' => User::factory(),
            'completed_by' => null,
            'completed_at' => null,
            'cancelled_reason' => null,
        ];
    }
}
