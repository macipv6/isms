<?php

namespace Database\Factories;

use App\Enums\PublicationStatus;
use App\Models\ThreatCatalog;
use App\Models\ThreatCatalogVersion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ThreatCatalogVersion> */
class ThreatCatalogVersionFactory extends Factory
{
    protected $model = ThreatCatalogVersion::class;

    public function definition(): array
    {
        return [
            'catalog_id' => ThreatCatalog::query()->create(['key' => 'test-'.Str::lower(Str::random(12)), 'name' => 'Test catalog'])->id,
            'version' => 1,
            'status' => PublicationStatus::Draft,
            'description' => 'Test threat catalog',
            'source_edition' => '2023',
            'source_reference' => 'Test source',
        ];
    }
}
