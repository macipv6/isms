<?php

namespace App\Services\Threats;

use App\Enums\PublicationStatus;
use App\Enums\UserRole;
use App\Models\ThreatCatalogEntry;
use App\Models\ThreatCatalogVersion;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ThreatCatalogPublisher
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function clonePublished(ThreatCatalogVersion $source, User $actor): ThreatCatalogVersion
    {
        return DB::transaction(function () use ($source, $actor): ThreatCatalogVersion {
            $actor = $this->requireAdmin($actor);
            $source = ThreatCatalogVersion::query()->lockForUpdate()->findOrFail($source->id);
            if ($source->status !== PublicationStatus::Published) {
                throw ValidationException::withMessages(['version' => 'Only a published catalog can be cloned.']);
            }
            $source->catalog()->lockForUpdate()->firstOrFail();
            $next = (int) ThreatCatalogVersion::query()->where('catalog_id', $source->catalog_id)->max('version') + 1;
            $clone = ThreatCatalogVersion::query()->create([
                'catalog_id' => $source->catalog_id,
                'version' => $next,
                'status' => PublicationStatus::Draft,
                'description' => $source->description,
                'source_edition' => $source->source_edition,
                'source_reference' => $source->source_reference,
            ]);
            foreach ($source->entries as $entry) {
                $clone->entries()->create($entry->only(['code', 'title', 'description', 'confidentiality', 'integrity', 'availability', 'sort_order', 'source_edition', 'source_reference']));
            }
            $this->audit->record('threat_catalog.cloned', $actor, ['source_id' => $source->id, 'target_id' => $clone->id]);

            return $clone->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function upsertDraftEntry(ThreatCatalogVersion $draft, array $data, User $actor): ThreatCatalogEntry
    {
        $this->validateEntry($data);

        return DB::transaction(function () use ($draft, $data, $actor): ThreatCatalogEntry {
            $actor = $this->requireAdmin($actor);
            $draft = ThreatCatalogVersion::query()->lockForUpdate()->findOrFail($draft->id);
            $this->requireDraft($draft);
            $payload = array_intersect_key($data, array_flip(['title', 'description', 'confidentiality', 'integrity', 'availability', 'sort_order', 'source_edition', 'source_reference']));
            $payload['confidentiality'] = (bool) ($data['confidentiality'] ?? false);
            $payload['integrity'] = (bool) ($data['integrity'] ?? false);
            $payload['availability'] = (bool) ($data['availability'] ?? false);
            $payload['source_edition'] ??= $draft->source_edition;
            $payload['source_reference'] ??= $draft->source_reference;
            $entry = $draft->entries()->updateOrCreate(['code' => $data['code']], $payload);
            $this->audit->record('threat_catalog.draft_entry_upserted', $actor, [
                'source_id' => $draft->id,
                'target_id' => $entry->id,
                'changed_fields' => array_keys($payload),
            ]);

            return $entry;
        });
    }

    public function publish(ThreatCatalogVersion $version, User $actor): ThreatCatalogVersion
    {
        return DB::transaction(function () use ($version, $actor): ThreatCatalogVersion {
            $actor = $this->requireAdmin($actor);
            $version = ThreatCatalogVersion::query()->lockForUpdate()->findOrFail($version->id);
            $this->requireDraft($version);
            $entries = $version->entries()->get();
            if ($entries->isEmpty() || $entries->pluck('code')->unique()->count() !== $entries->count() || $entries->pluck('sort_order')->unique()->count() !== $entries->count()) {
                throw ValidationException::withMessages(['entries' => 'A non-empty catalog with unique codes and order is required.']);
            }
            foreach ($entries as $entry) {
                $this->validateEntry($entry->toArray());
            }
            if ($version->catalog->key === 'bsi-elemental-threats') {
                $codes = $entries->pluck('code')->all();
                foreach (range(1, 47) as $number) {
                    if (! in_array('G 0.'.$number, $codes, true)) {
                        throw ValidationException::withMessages(['entries' => 'The BSI baseline requires all 47 elemental threat codes.']);
                    }
                }
            }
            $version->update(['status' => PublicationStatus::Published, 'published_at' => now(), 'published_by' => $actor->id]);
            $this->audit->record('threat_catalog.published', $actor, ['source_id' => $version->catalog_id, 'target_id' => $version->id]);

            return $version->refresh();
        });
    }

    private function requireAdmin(User $actor): User
    {
        $current = User::query()->lockForUpdate()->find($actor->id);
        $organization = $current?->organization()->lockForUpdate()->first();
        if (! $current || ! $current->is_active || $current->role !== UserRole::Admin || ! $organization || ! $organization->is_active || $organization->organization_type !== 'internal') {
            throw ValidationException::withMessages(['actor' => 'An active internal administrator is required.']);
        }

        return $current;
    }

    private function requireDraft(ThreatCatalogVersion $version): void
    {
        if ($version->status !== PublicationStatus::Draft) {
            throw ValidationException::withMessages(['version' => 'Only a draft catalog can be changed.']);
        }
    }

    /** @param array<string, mixed> $data */
    private function validateEntry(array $data): void
    {
        if (! is_string($data['code'] ?? null) || ! preg_match('/^[A-Z0-9][A-Z0-9._ -]{0,31}$/', $data['code']) || ! is_string($data['title'] ?? null) || trim($data['title']) === '' || mb_strlen($data['title']) > 255 || ! is_string($data['description'] ?? null) || trim($data['description']) === '' || mb_strlen($data['description']) > 4000 || ! is_numeric($data['sort_order'] ?? null) || (int) $data['sort_order'] < 1 || ! ($data['confidentiality'] ?? false) && ! ($data['integrity'] ?? false) && ! ($data['availability'] ?? false)) {
            throw ValidationException::withMessages(['entry' => 'Code, title, bounded description, positive order and at least one CIA objective are required.']);
        }
    }
}
