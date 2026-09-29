<?php

namespace Database\Factories;

use App\Enums\PublicationStatus;
use App\Models\RiskMethodology;
use App\Models\RiskMethodologyVersion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<RiskMethodologyVersion> */
class RiskMethodologyVersionFactory extends Factory
{
    protected $model = RiskMethodologyVersion::class;

    public function definition(): array
    {
        return [
            'methodology_id' => RiskMethodology::query()->create([
                'key' => 'test-'.Str::lower(Str::random(12)),
                'name' => 'Test methodology',
            ])->id,
            'version' => 1,
            'status' => PublicationStatus::Draft,
            'description' => 'Test methodology version',
        ];
    }

    public function draftWithCompleteMatrix(): static
    {
        return $this->afterCreating(function (RiskMethodologyVersion $version): void {
            $frequencyIds = [];
            foreach (['rare', 'medium', 'frequent', 'very_frequent'] as $index => $key) {
                $frequencyIds[$key] = $version->frequencies()->create([
                    'key' => $key, 'label' => ucfirst($key), 'description' => $key, 'sort_order' => $index + 1,
                ])->id;
            }
            $impactIds = [];
            foreach (['negligible', 'limited', 'considerable', 'existential'] as $index => $key) {
                $impactIds[$key] = $version->impacts()->create([
                    'key' => $key, 'label' => ucfirst($key), 'description' => $key, 'sort_order' => $index + 1,
                ])->id;
            }
            $riskIds = [];
            foreach (['low', 'medium', 'high', 'very_high'] as $index => $key) {
                $riskIds[$key] = $version->riskLevels()->create([
                    'key' => $key, 'label' => ucfirst($key), 'description' => $key, 'sort_order' => $index + 1,
                ])->id;
            }
            foreach ($impactIds as $impactId) {
                foreach ($frequencyIds as $frequencyId) {
                    $version->matrixCells()->create([
                        'frequency_level_id' => $frequencyId,
                        'impact_level_id' => $impactId,
                        'risk_level_id' => $riskIds['low'],
                    ]);
                }
            }
        });
    }
}
