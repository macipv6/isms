<?php

namespace App\Services\Risks;

use App\Models\RiskMethodologyVersion;
use App\Models\ThreatCatalogVersion;

class RiskVersionPresenter
{
    /** @return array<string, mixed> */
    public function methodology(RiskMethodologyVersion $version): array
    {
        $version->loadMissing(['methodology', 'frequencies', 'impacts', 'riskLevels', 'matrixCells.frequency', 'matrixCells.impact', 'matrixCells.riskLevel']);

        return [
            'id' => $version->id, 'methodologyId' => $version->methodology_id, 'name' => $version->methodology->name, 'version' => $version->version,
            'status' => $version->status->value, 'description' => $version->description,
            'sourceReference' => $version->source_reference, 'publishedAt' => $version->published_at?->toIso8601String(),
            'levels' => [
                'frequency' => $version->frequencies->map->only(['key', 'label', 'description', 'sort_order'])->all(),
                'impact' => $version->impacts->map->only(['key', 'label', 'description', 'sort_order'])->all(),
                'risk' => $version->riskLevels->map->only(['key', 'label', 'description', 'sort_order'])->all(),
            ],
            'cells' => $version->matrixCells->map(fn ($cell) => ['frequency_key' => $cell->frequency->key, 'impact_key' => $cell->impact->key, 'risk_key' => $cell->riskLevel->key])->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function catalog(ThreatCatalogVersion $version, bool $includeEntries = true, int $entryPage = 1): array
    {
        $version->loadMissing('catalog');
        $count = $version->entries()->count();

        return [
            'id' => $version->id, 'catalogId' => $version->catalog_id, 'name' => $version->catalog->name, 'version' => $version->version,
            'status' => $version->status->value, 'description' => $version->description,
            'sourceReference' => $version->source_reference, 'sourceEdition' => $version->source_edition,
            'publishedAt' => $version->published_at?->toIso8601String(), 'threatCount' => $count,
            'entryPages' => ['current' => $entryPage, 'last' => max(1, (int) ceil($count / 100))],
            'entries' => $includeEntries ? $version->entries()->offset(($entryPage - 1) * 100)->limit(100)->get()->map->only(['code', 'title', 'description', 'confidentiality', 'integrity', 'availability', 'sort_order', 'source_edition', 'source_reference'])->all() : [],
        ];
    }
}
