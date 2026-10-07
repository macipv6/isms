<?php

namespace App\Services\ProtectionNeeds;

use App\Data\Dependencies\DependencyNode;
use App\Enums\ProtectionObjective;
use App\Models\Asset;
use App\Models\BusinessProcess;
use App\Models\DependencyEdge;
use App\Models\IsmsProject;
use App\Models\ProtectionNeed;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProtectionNeedPropagator
{
    public function __construct(private readonly ProtectionNeedInitializer $initializer, private readonly ProtectionNeedInvalidator $invalidator, private readonly ProtectionNeedAccess $access, private readonly AuditLogger $audit) {}

    /** @param list<DependencyNode> $roots
     * @return Collection<int, ProtectionNeed>
     */
    public function recalculate(IsmsProject $project, array $roots = [], ?User $actor = null, bool $recordAudit = true): Collection
    {
        return DB::transaction(function () use ($project, $roots, $actor, $recordAudit): Collection {
            $locked = $this->access->project($project);
            $nodes = [];
            foreach (BusinessProcess::query()->where('project_id', $locked->id)->where('is_active', true)->get() as $process) {
                $node = DependencyNode::process($process);
                $nodes[$node->identity()] = $node;
            }
            foreach (Asset::query()->where('project_id', $locked->id)->where('is_active', true)->get() as $asset) {
                $node = DependencyNode::asset($asset);
                $nodes[$node->identity()] = $node;
            }
            $out = [];
            $indegree = array_fill_keys(array_keys($nodes), 0);
            foreach (DependencyEdge::query()->where('project_id', $locked->id)->where('is_active', true)->get() as $edge) {
                $source = ($edge->source_process_id ? 'process:'.$edge->source_process_id : 'asset:'.$edge->source_asset_id);
                $target = ($edge->target_process_id ? 'process:'.$edge->target_process_id : 'asset:'.$edge->target_asset_id);
                if (! isset($nodes[$source], $nodes[$target])) {
                    continue;
                }
                $out[$source][] = [$target, $edge->id];
                $indegree[$target]++;
            }
            foreach ($out as &$edges) {
                usort($edges, fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
            }
            unset($edges);
            $ready = array_keys(array_filter($indegree, fn (int $degree): bool => $degree === 0));
            sort($ready);
            $order = [];
            while ($ready !== []) {
                $id = array_shift($ready);
                $order[] = $id;
                foreach ($out[$id] ?? [] as [$target]) {
                    $indegree[$target]--;
                    if ($indegree[$target] === 0) {
                        $ready[] = $target;
                        sort($ready);
                    }
                }
            }
            if (count($order) !== count($nodes)) {
                throw ValidationException::withMessages(['dependencies' => 'Der aktive Abhängigkeitsgraph enthält einen Zyklus.']);
            }
            $affected = [];
            if ($roots === []) {
                $affected = array_fill_keys(array_keys($nodes), true);
            } else {
                $queue = [];
                foreach ($roots as $root) {
                    if ($root->projectId !== $locked->id) {
                        throw ValidationException::withMessages(['target' => 'Das Ziel gehört nicht zu diesem Projekt.']);
                    }
                    $queue[] = $root->identity();
                }
                while ($queue !== []) {
                    $id = array_shift($queue);
                    if (isset($affected[$id])) {
                        continue;
                    }
                    $affected[$id] = true;
                    foreach ($out[$id] ?? [] as [$target]) {
                        $queue[] = $target;
                    }
                }
            }
            $needs = [];
            foreach ($nodes as $id => $node) {
                $rows = $this->initializer->ensureTarget($node);
                foreach ($rows as $need) {
                    $needs[$id][$need->objective->value] = $need;
                }
            }
            $incoming = [];
            $changed = new Collection;
            foreach ($order as $id) {
                $node = $nodes[$id];
                foreach (ProtectionObjective::cases() as $objective) {
                    $key = $objective->value;
                    $need = $needs[$id][$key];
                    $candidates = $incoming[$id][$key] ?? [];
                    $max = null;
                    foreach ($candidates as $candidate) {
                        if ($max === null || $candidate['level']->rank() > $max->rank()) {
                            $max = $candidate['level'];
                        }
                    }
                    $sources = [];
                    foreach ($candidates as $candidate) {
                        if ($candidate['level'] === $max) {
                            if (! isset($sources[$candidate['origin']]) || $candidate['edge'] < $sources[$candidate['origin']]['edge']) {
                                $sources[$candidate['origin']] = $candidate;
                            }
                        }
                    }
                    ksort($sources);
                    $oldSources = $need->sources()->get()->map(fn ($s) => [
                        'origin' => $s->origin_process_id ? 'process:'.$s->origin_process_id : 'asset:'.$s->origin_asset_id,
                        'level' => $s->origin_level->value, 'edge' => $s->first_edge_id,
                    ])->sortBy('origin')->values()->all();
                    $newSources = array_values(array_map(fn (array $s): array => ['origin' => $s['origin'], 'level' => $s['level']->value, 'edge' => $s['edge']], $sources));
                    $sourceChanged = $oldSources !== $newSources;
                    $inheritedChanged = $need->inherited_level !== $max;
                    $oldEffective = $need->effective_level;
                    $invalidated = false;
                    if (isset($affected[$id]) && ($inheritedChanged || $sourceChanged)) {
                        $invalidated = $this->invalidator->invalidate($need, $actor, $recordAudit);
                        $need->refresh();
                        $need->sources()->delete();
                        foreach ($sources as $source) {
                            $need->sources()->create([
                                'project_id' => $locked->id,
                                'origin_process_id' => str_starts_with($source['origin'], 'process:') ? substr($source['origin'], 8) : null,
                                'origin_asset_id' => str_starts_with($source['origin'], 'asset:') ? substr($source['origin'], 6) : null,
                                'origin_level' => $source['level'],
                                'first_edge_id' => $source['edge'],
                            ]);
                        }
                    }
                    $approved = $need->downgradeRequests()->where('status', 'approved')->where('inherited_level', $max?->value)->where('requested_level', $need->direct_level?->value)->exists();
                    $effective = $need->direct_level;
                    if ($max && (! $effective || ($max->rank() > $effective->rank() && ! $approved))) {
                        $effective = $max;
                    }
                    $effectiveChanged = $oldEffective !== $effective;
                    if (isset($affected[$id]) && ($sourceChanged || $inheritedChanged || $effectiveChanged)) {
                        $need->inherited_level = $max;
                        $need->effective_level = $effective;
                        if (! $invalidated) {
                            $need->lock_version++;
                        }
                        $need->save();
                        if ($recordAudit) {
                            $this->audit->record('protection_need.recalculated', $actor, [
                                'project_id' => $locked->id,
                                'protection_need_id' => $need->id,
                                'objective' => $key,
                                'old_level' => $oldEffective?->value,
                                'new_level' => $effective?->value,
                                'changed_fields' => array_values(array_filter([
                                    $sourceChanged ? 'sources' : null,
                                    $inheritedChanged ? 'inherited_level' : null,
                                    $effectiveChanged ? 'effective_level' : null,
                                ])),
                            ], $locked->organization_id);
                        }
                        $changed->push($need);
                    }
                    if ($effective) {
                        $origins = [];
                        if ($need->direct_level === $effective) {
                            $origins[$id] = ['origin' => $id, 'level' => $effective, 'edge' => null];
                        }
                        if ($max === $effective) {
                            foreach ($sources as $source) {
                                $origins[$source['origin']] = ['origin' => $source['origin'], 'level' => $source['level'], 'edge' => $source['edge']];
                            }
                        }
                        foreach ($out[$id] ?? [] as [$target, $edgeId]) {
                            foreach ($origins as $origin) {
                                $incoming[$target][$key][] = [...$origin, 'edge' => $origin['edge'] ?? $edgeId];
                            }
                        }
                    }
                }
            }

            return $changed;
        });
    }
}
