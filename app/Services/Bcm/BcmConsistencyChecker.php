<?php

namespace App\Services\Bcm;

use App\Data\Bcm\BcmWarning;
use App\Enums\DependencyNodeType;
use App\Models\BiaRevision;

class BcmConsistencyChecker
{
    public function __construct(private readonly BcmSourceSnapshot $sources, private readonly BcmFreshness $freshness) {}

    /** @return array<string, mixed> */
    public function sourceSnapshot(BiaRevision $revision): array
    {
        return $this->sources->bia($revision);
    }

    /** @return list<BcmWarning> */
    public function warnings(BiaRevision $revision): array
    {
        $sources = $this->sourceSnapshot($revision);
        foreach ($sources['dependencies'] as &$dependency) {
            $dependency['stale'] = false;
            if ($dependency['target_type'] === 'process' && $dependency['objective'] !== null) {
                $target = BiaRevision::query()->whereKey($dependency['objective']['id'])->where('project_id', $revision->project_id)->firstOrFail();
                $dependency['freshness'] = $this->freshness->bia($target);
                $dependency['stale'] = ! $dependency['freshness']['current'];
                $dependency['current_source_hash'] = hash('sha256', json_encode($this->sources->bia($target), JSON_THROW_ON_ERROR));
            }
        }
        unset($dependency);
        $root = $sources['root_targets'];
        $warnings = [];
        foreach ($sources['dependencies'] as $dependency) {
            $objective = $dependency['objective'];
            $codes = [];
            if ($objective === null) {
                $codes[] = 'dependency_target_unknown';
            } else {
                if ($dependency['stale']) {
                    $codes[] = 'dependency_target_stale';
                }
                if ($root['rto_minutes'] !== null && $objective['rto_minutes'] !== null && $objective['rto_minutes'] > $root['rto_minutes']) {
                    $codes[] = 'dependency_rto_exceeds_process';
                }
                if (! $root['rpo_not_applicable'] && ! $objective['rpo_not_applicable'] && $root['rpo_minutes'] !== null && $objective['rpo_minutes'] !== null && $objective['rpo_minutes'] > $root['rpo_minutes']) {
                    $codes[] = 'dependency_rpo_exceeds_process';
                }
            }
            foreach ($codes as $code) {
                $warnings[] = $this->warning($code, $dependency['target_type'], $dependency['target_id'], $objective['id'] ?? null,
                    $dependency['path_edge_ids'], $dependency['critical'], $sources, $dependency);
            }
        }
        $firstCritical = null;
        $previous = [];
        $descending = [];
        foreach ($sources['impacts'] as $impact) {
            if ($impact['level'] === 4) {
                $firstCritical = $firstCritical === null ? $impact['minutes'] : min($firstCritical, $impact['minutes']);
            }
            if ($impact['level'] !== null) {
                if (isset($previous[$impact['area']]) && $impact['level'] < $previous[$impact['area']]) {
                    $descending[] = $impact;
                }
                $previous[$impact['area']] = $impact['level'];
            }
        }
        if ($firstCritical !== null && $root['mtpd_minutes'] !== null && $root['mtpd_minutes'] > $firstCritical) {
            $warnings[] = $this->warning('mtpd_after_critical_impact', 'process', $sources['root_process_id'], $sources['root_revision_id'], [], true, $sources, ['first_critical_minutes' => $firstCritical]);
        }
        if ($descending !== []) {
            $warnings[] = $this->warning('descending_impact', 'process', $sources['root_process_id'], $sources['root_revision_id'], [], true, $sources, ['descending' => $descending]);
        }

        return $warnings;
    }

    /**
     * @param  list<string>  $path
     * @param  array<string, mixed>  $sources
     * @param  array<string, mixed>  $detail
     */
    private function warning(string $code, string $type, string $id, ?string $revision, array $path, bool $blocking, array $sources, array $detail): BcmWarning
    {
        $relevant = ['project_id' => $sources['project_id'], 'root_process_id' => $sources['root_process_id'],
            'root_revision_id' => $sources['root_revision_id'], 'root_version' => $sources['root_version'],
            'root_targets' => $sources['root_targets'], 'configuration' => $sources['configuration']];
        if ($path === []) {
            $relevant['impacts'] = $sources['impacts'];
        } else {
            $relevant['edges'] = array_values(array_filter($sources['edges'], fn ($edge) => in_array($edge['id'], $path, true)));
        }
        $fingerprint = hash('sha256', json_encode(['code' => $code, 'sources' => $relevant, 'detail' => $detail], JSON_THROW_ON_ERROR));

        return new BcmWarning($code, DependencyNodeType::from($type), $id, $revision, $path, $blocking, $fingerprint);
    }
}
