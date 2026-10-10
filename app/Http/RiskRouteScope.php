<?php

namespace App\Http;

use App\Data\Dependencies\DependencyNode;
use App\Models\Asset;
use App\Models\BusinessProcess;
use App\Models\EvidenceFile;
use App\Models\Finding;
use App\Models\IsmsProject;
use App\Models\Measure;
use App\Models\Organization;
use App\Models\ProjectRiskContext;
use App\Models\ProjectThreat;
use App\Models\ProtectionNeed;
use App\Models\ProtectionNeedDowngradeRequest;
use App\Models\RiskAnalysis;
use App\Models\RiskAnalysisCycle;
use App\Models\RiskAnalysisTrigger;
use App\Models\RiskTargetThreatReview;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

final class RiskRouteScope
{
    /** @var array<string, class-string<Model>> */
    private const CHILDREN = [
        'need' => ProtectionNeed::class, 'downgrade' => ProtectionNeedDowngradeRequest::class,
        'context' => ProjectRiskContext::class, 'threat' => ProjectThreat::class,
        'trigger' => RiskAnalysisTrigger::class, 'review' => RiskTargetThreatReview::class,
        'analysis' => RiskAnalysis::class, 'cycle' => RiskAnalysisCycle::class,
    ];

    /** @return array{Organization, IsmsProject} */
    public static function project(Request $request): array
    {
        $organization = Organization::query()->whereKey(self::id($request->route('organization')))->where('organization_type', 'customer')->firstOrFail();
        $project = IsmsProject::query()->with('organization')->whereKey(self::id($request->route('project')))->where('organization_id', $organization->id)->firstOrFail();
        foreach (self::CHILDREN as $parameter => $class) {
            if ($request->route($parameter) !== null) {
                self::child($request, $project, $parameter, $class);
            }
        }
        if ($request->route('target') !== null) {
            self::target($request, $project);
        }
        if ($request->route('resource') !== null) {
            $class = match ($request->route('kind')) {
                'finding' => Finding::class,
                'measure' => Measure::class,
                'evidence' => EvidenceFile::class,
                default => abort(404),
            };
            self::child($request, $project, 'resource', $class);
        }

        return [$organization, $project];
    }

    /** @template T of Model
     * @param  class-string<T>  $class
     * @return T
     */
    public static function child(Request $request, IsmsProject $project, string $parameter, string $class): Model
    {
        /** @var T $child */
        $child = $class::query()->whereKey(self::id($request->route($parameter)))->where('project_id', $project->id)->firstOrFail();

        return $child;
    }

    public static function target(Request $request, IsmsProject $project): DependencyNode
    {
        $type = $request->route('targetType');
        $id = self::id($request->route('target'));
        if ($type === 'process') {
            return DependencyNode::process(BusinessProcess::query()->where('project_id', $project->id)->findOrFail($id));
        }
        if ($type === 'asset') {
            return DependencyNode::asset(Asset::query()->where('project_id', $project->id)->findOrFail($id));
        }
        abort(404);
    }

    /** @template T of Model
     * @param  class-string<T>  $class
     * @return T
     */
    public static function global(Request $request, string $parameter, string $class): Model
    {
        /** @var T $model */
        $model = $class::query()->findOrFail(self::id($request->route($parameter)));

        return $model;
    }

    private static function id(mixed $value): string
    {
        if ($value instanceof Model) {
            return (string) $value->getKey();
        }
        abort_unless(is_string($value), 404);

        return $value;
    }
}
