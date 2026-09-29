<?php

namespace App\Services\RiskMethodology;

use App\Enums\PublicationStatus;
use App\Enums\UserRole;
use App\Models\RiskMatrixCell;
use App\Models\RiskMethodologyVersion;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RiskMethodologyPublisher
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function publish(RiskMethodologyVersion $version, User $actor): RiskMethodologyVersion
    {
        $this->requireAdmin($actor);

        return DB::transaction(function () use ($version, $actor): RiskMethodologyVersion {
            $locked = RiskMethodologyVersion::query()->lockForUpdate()->findOrFail($version->id);
            $this->requireDraft($locked);
            $this->validateStoredMatrix($locked);
            $locked->update([
                'status' => PublicationStatus::Published,
                'published_at' => now(),
                'published_by' => $actor->id,
            ]);
            $this->audit->record('risk_methodology.published', $actor, [
                'source_id' => $locked->methodology_id,
                'target_id' => $locked->id,
            ]);

            return $locked->refresh();
        });
    }

    public function clonePublished(RiskMethodologyVersion $source, string $description, User $actor): RiskMethodologyVersion
    {
        $this->requireAdmin($actor);
        if (trim($description) === '') {
            throw ValidationException::withMessages(['description' => 'A description is required.']);
        }

        return DB::transaction(function () use ($source, $description, $actor): RiskMethodologyVersion {
            $locked = RiskMethodologyVersion::query()->lockForUpdate()->findOrFail($source->id);
            if ($locked->status !== PublicationStatus::Published) {
                throw ValidationException::withMessages(['version' => 'Only a published methodology can be cloned.']);
            }
            $locked->methodology()->lockForUpdate()->firstOrFail();
            $next = (int) RiskMethodologyVersion::query()->where('methodology_id', $locked->methodology_id)->max('version') + 1;
            $clone = RiskMethodologyVersion::query()->create([
                'methodology_id' => $locked->methodology_id,
                'version' => $next,
                'status' => PublicationStatus::Draft,
                'description' => $description,
                'source_reference' => $locked->source_reference,
            ]);

            $idMaps = [];
            foreach (['frequencies', 'impacts', 'riskLevels'] as $relation) {
                foreach ($locked->{$relation} as $level) {
                    $idMaps[$relation][$level->id] = $clone->{$relation}()->create(
                        $level->only(['key', 'label', 'description', 'sort_order']),
                    )->id;
                }
            }
            foreach ($locked->matrixCells as $cell) {
                $clone->matrixCells()->create([
                    'frequency_level_id' => $idMaps['frequencies'][$cell->frequency_level_id],
                    'impact_level_id' => $idMaps['impacts'][$cell->impact_level_id],
                    'risk_level_id' => $idMaps['riskLevels'][$cell->risk_level_id],
                ]);
            }
            $this->audit->record('risk_methodology.cloned', $actor, [
                'source_id' => $locked->id,
                'target_id' => $clone->id,
            ]);

            return $clone->refresh();
        });
    }

    /** @param array<string, list<array<string, mixed>>> $levels
     * @param  list<array<string, string>>  $cells
     */
    public function replaceDraftMatrix(RiskMethodologyVersion $draft, array $levels, array $cells, User $actor): RiskMethodologyVersion
    {
        $this->requireAdmin($actor);
        $this->validateInputMatrix($levels, $cells);

        return DB::transaction(function () use ($draft, $levels, $cells, $actor): RiskMethodologyVersion {
            $locked = RiskMethodologyVersion::query()->lockForUpdate()->findOrFail($draft->id);
            $this->requireDraft($locked);
            $changed = $this->changedFields($locked, $levels, $cells);

            $locked->matrixCells()->delete();
            $locked->frequencies()->delete();
            $locked->impacts()->delete();
            $locked->riskLevels()->delete();

            $ids = [];
            foreach (['frequency' => 'frequencies', 'impact' => 'impacts', 'risk' => 'riskLevels'] as $kind => $relation) {
                foreach ($levels[$kind] as $level) {
                    $ids[$kind][$level['key']] = $locked->{$relation}()->create(
                        array_intersect_key($level, array_flip(['key', 'label', 'description', 'sort_order'])),
                    )->id;
                }
            }
            foreach ($cells as $cell) {
                $locked->matrixCells()->create([
                    'frequency_level_id' => $ids['frequency'][$cell['frequency_key']],
                    'impact_level_id' => $ids['impact'][$cell['impact_key']],
                    'risk_level_id' => $ids['risk'][$cell['risk_key']],
                ]);
            }
            $this->audit->record('risk_methodology.draft_matrix_replaced', $actor, [
                'source_id' => $locked->methodology_id,
                'target_id' => $locked->id,
                'changed_fields' => $changed,
            ]);

            return $locked->refresh();
        });
    }

    private function requireAdmin(User $actor): void
    {
        $organization = $actor->organization;
        if (! $actor->is_active || $actor->role !== UserRole::Admin || ! $organization || ! $organization->is_active || $organization->organization_type !== 'internal') {
            throw ValidationException::withMessages(['actor' => 'An active internal administrator is required.']);
        }
    }

    private function requireDraft(RiskMethodologyVersion $version): void
    {
        if ($version->status !== PublicationStatus::Draft) {
            throw ValidationException::withMessages(['version' => 'Only a draft methodology can be changed.']);
        }
    }

    private function validateStoredMatrix(RiskMethodologyVersion $version): void
    {
        $frequency = $version->frequencies()->get();
        $impact = $version->impacts()->get();
        $risk = $version->riskLevels()->get();
        foreach ([$frequency, $impact, $risk] as $items) {
            if ($items->count() !== 4 || $items->pluck('key')->unique()->count() !== 4 || $items->pluck('sort_order')->unique()->count() !== 4) {
                throw ValidationException::withMessages(['levels' => 'Each methodology axis must have four unique ordered levels.']);
            }
        }
        $cells = $version->matrixCells()->get();
        if ($cells->count() !== 16 || $cells->map(fn ($cell) => $cell->frequency_level_id.':'.$cell->impact_level_id)->unique()->count() !== 16) {
            throw ValidationException::withMessages(['matrix' => 'The matrix must contain 16 distinct cells.']);
        }
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $levels
     * @param  list<array<string, string>>  $cells
     */
    private function validateInputMatrix(array $levels, array $cells): void
    {
        $keys = [];
        foreach (['frequency', 'impact', 'risk'] as $kind) {
            $items = $levels[$kind] ?? null;
            if (! is_array($items) || count($items) !== 4) {
                throw ValidationException::withMessages([$kind => 'Exactly four levels are required.']);
            }
            $orders = [];
            foreach ($items as $item) {
                if (! is_string($item['key'] ?? null) || trim($item['key']) === '' || ! is_string($item['label'] ?? null) || trim($item['label']) === '' || ! is_int($item['sort_order'] ?? null) || $item['sort_order'] < 1) {
                    throw ValidationException::withMessages([$kind => 'Each level needs a key, label and positive order.']);
                }
                $keys[$kind][] = $item['key'];
                $orders[] = $item['sort_order'];
            }
            if (count(array_unique($keys[$kind])) !== 4 || count(array_unique($orders)) !== 4) {
                throw ValidationException::withMessages([$kind => 'Level keys and orders must be unique.']);
            }
        }
        if (count($cells) !== 16) {
            throw ValidationException::withMessages(['matrix' => 'Exactly 16 cells are required.']);
        }
        $coordinates = [];
        foreach ($cells as $cell) {
            if (! in_array($cell['frequency_key'] ?? null, $keys['frequency'], true) || ! in_array($cell['impact_key'] ?? null, $keys['impact'], true) || ! in_array($cell['risk_key'] ?? null, $keys['risk'], true)) {
                throw ValidationException::withMessages(['matrix' => 'A cell references an unknown level.']);
            }
            $coordinates[] = $cell['frequency_key'].':'.$cell['impact_key'];
        }
        if (count(array_unique($coordinates)) !== 16) {
            throw ValidationException::withMessages(['matrix' => 'Matrix coordinates must be unique.']);
        }
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $levels
     * @param  list<array<string, string>>  $cells
     * @return list<string>
     */
    private function changedFields(RiskMethodologyVersion $version, array $levels, array $cells): array
    {
        $changed = [];
        foreach (['frequency' => 'frequencies', 'impact' => 'impacts', 'risk' => 'riskLevels'] as $kind => $relation) {
            $old = $version->{$relation}()->get()->map->only(['key', 'label', 'description', 'sort_order'])->all();
            $incoming = $levels[$kind];
            usort($old, fn (array $a, array $b): int => $a['sort_order'] <=> $b['sort_order']);
            usort($incoming, fn (array $a, array $b): int => $a['sort_order'] <=> $b['sort_order']);
            if ($old !== $incoming) {
                $changed[] = $kind.'_levels';
            }
        }
        $oldCells = $version->matrixCells()->with(['frequency', 'impact', 'riskLevel'])->get()->map(fn (RiskMatrixCell $cell): array => [
            'frequency_key' => $cell->frequency->key,
            'impact_key' => $cell->impact->key,
            'risk_key' => $cell->riskLevel->key,
        ])->all();
        $orderedCells = $cells;
        $byCoordinate = fn (array $a, array $b): int => strcmp($a['frequency_key'].':'.$a['impact_key'], $b['frequency_key'].':'.$b['impact_key']);
        usort($oldCells, $byCoordinate);
        usort($orderedCells, $byCoordinate);
        if ($oldCells !== $orderedCells) {
            $changed[] = 'matrix_cells';
        }

        return $changed;
    }
}
