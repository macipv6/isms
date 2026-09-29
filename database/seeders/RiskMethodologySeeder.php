<?php

namespace Database\Seeders;

use App\Enums\PublicationStatus;
use App\Models\RiskMethodology;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RiskMethodologySeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $methodology = RiskMethodology::query()->firstOrCreate(
                ['key' => 'bsi-200-3'],
                ['name' => 'BSI Standard 200-3', 'description' => 'Risikomanagement nach BSI Standard 200-3.'],
            );
            if ($methodology->versions()->where('version', 1)->exists()) {
                return;
            }

            $version = $methodology->versions()->create([
                'version' => 1,
                'status' => PublicationStatus::Draft,
                'description' => 'BSI 200-3 Standard-Risikomatrix',
                'source_reference' => 'BSI Standard 200-3',
            ]);

            $frequencies = [
                ['rare', 'Selten', 'Ereignis könnte nach heutigem Kenntnisstand höchstens alle fünf Jahre eintreten.'],
                ['medium', 'Mittel', 'Ereignis tritt einmal alle fünf Jahre bis einmal im Jahr ein.'],
                ['frequent', 'Häufig', 'Ereignis tritt einmal im Jahr bis einmal pro Monat ein.'],
                ['very_frequent', 'Sehr häufig', 'Ereignis tritt mehrmals im Monat ein.'],
            ];
            $impacts = [
                ['negligible', 'Vernachlässigbar', 'Die Schadensauswirkungen sind gering und können vernachlässigt werden.'],
                ['limited', 'Begrenzt', 'Die Schadensauswirkungen sind begrenzt und überschaubar.'],
                ['considerable', 'Beträchtlich', 'Die Schadensauswirkungen können beträchtlich sein.'],
                ['existential', 'Existenzbedrohend', 'Die Schadensauswirkungen können ein existenziell bedrohliches, katastrophales Ausmaß erreichen.'],
            ];
            $levels = [
                ['low', 'Gering', 'Die bereits umgesetzten oder zumindest im Sicherheitskonzept vorgesehenen Sicherheitsmaßnahmen bieten einen ausreichenden Schutz. In der Praxis ist es üblich, geringe Risiken zu akzeptieren und die Gefährdung dennoch zu beobachten.'],
                ['medium', 'Mittel', 'Die bereits umgesetzten oder zumindest im Sicherheitskonzept vorgesehenen Sicherheitsmaßnahmen reichen möglicherweise nicht aus.'],
                ['high', 'Hoch', 'Die bereits umgesetzten oder zumindest im Sicherheitskonzept vorgesehenen Sicherheitsmaßnahmen bieten keinen ausreichenden Schutz vor der jeweiligen Gefährdung.'],
                ['very_high', 'Sehr hoch', 'Die bereits umgesetzten oder zumindest im Sicherheitskonzept vorgesehenen Sicherheitsmaßnahmen bieten keinen ausreichenden Schutz vor der jeweiligen Gefährdung. In der Praxis werden sehr hohe Risiken selten akzeptiert.'],
            ];
            $frequencyIds = $this->insertLevels($version, 'frequencies', $frequencies);
            $impactIds = $this->insertLevels($version, 'impacts', $impacts);
            $riskIds = $this->insertLevels($version, 'riskLevels', $levels);

            $matrix = [
                'negligible' => ['rare' => 'low', 'medium' => 'low', 'frequent' => 'low', 'very_frequent' => 'medium'],
                'limited' => ['rare' => 'low', 'medium' => 'low', 'frequent' => 'high', 'very_frequent' => 'high'],
                'considerable' => ['rare' => 'medium', 'medium' => 'high', 'frequent' => 'very_high', 'very_frequent' => 'very_high'],
                'existential' => ['rare' => 'high', 'medium' => 'very_high', 'frequent' => 'very_high', 'very_frequent' => 'very_high'],
            ];
            foreach ($matrix as $impact => $row) {
                foreach ($row as $frequency => $risk) {
                    $version->matrixCells()->create([
                        'frequency_level_id' => $frequencyIds[$frequency],
                        'impact_level_id' => $impactIds[$impact],
                        'risk_level_id' => $riskIds[$risk],
                    ]);
                }
            }

            $version->update([
                'status' => PublicationStatus::Published,
                'published_at' => CarbonImmutable::parse('2026-09-22T00:00:00+00:00'),
            ]);
        });
    }

    /** @param list<array{string, string, string}> $items
     * @return array<string, string>
     */
    private function insertLevels($version, string $relation, array $items): array
    {
        $ids = [];
        foreach ($items as $index => [$key, $label, $description]) {
            $ids[$key] = $version->{$relation}()->create([
                'key' => $key,
                'label' => $label,
                'description' => $description,
                'sort_order' => $index + 1,
            ])->id;
        }

        return $ids;
    }
}
