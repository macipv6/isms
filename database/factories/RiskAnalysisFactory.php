<?php

namespace Database\Factories;

use App\Models\RiskAnalysis;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RiskAnalysis> */
class RiskAnalysisFactory extends Factory
{
    protected $model = RiskAnalysis::class;

    public function definition(): array
    {
        return ['scenario_identity' => mb_strtolower($this->faker->unique()->words(3, true))];
    }
}
