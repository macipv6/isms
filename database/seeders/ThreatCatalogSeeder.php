<?php

namespace Database\Seeders;

use App\Enums\PublicationStatus;
use App\Models\ThreatCatalog;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ThreatCatalogSeeder extends Seeder
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function run(): void
    {
        DB::transaction(function (): void {
            $catalog = ThreatCatalog::query()->firstOrCreate(
                ['key' => 'bsi-elemental-threats'],
                ['name' => 'BSI Elementare Gefährdungen', 'description' => 'IT-Grundschutz-Kompendium, Edition 2023.'],
            );
            if ($catalog->versions()->where('version', 1)->exists()) {
                return;
            }
            $version = $catalog->versions()->create([
                'version' => 1,
                'status' => PublicationStatus::Draft,
                'description' => '47 elementare Gefährdungen der BSI Edition 2023',
                'source_edition' => '2023',
                'source_reference' => 'BSI IT-Grundschutz-Kompendium, Edition 2023 (Stand Februar 2023), Elementare Gefährdungen',
            ]);
            foreach (require __DIR__.'/data/bsi-elemental-threats-2023.php' as $data) {
                $version->entries()->create($data);
            }
            $version->update(['status' => PublicationStatus::Published, 'published_at' => CarbonImmutable::parse('2023-02-01T00:00:00+00:00')]);
            $this->audit->record('threat_catalog.published', null, ['source_id' => $catalog->id, 'target_id' => $version->id]);
        });
    }
}
