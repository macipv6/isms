<?php

namespace App\Http\Controllers;

use App\Data\Dependencies\DependencyNode;
use App\Enums\DependencyNodeType;
use App\Http\Requests\Dependencies\ChangeDependencyStatusRequest;
use App\Http\Requests\Dependencies\ShowDependencyGraphRequest;
use App\Http\Requests\Dependencies\StoreDependencyRequest;
use App\Http\Requests\Dependencies\UpdateDependencyRequest;
use App\Models\Asset;
use App\Models\BusinessProcess;
use App\Models\DependencyEdge;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\User;
use App\Services\Dependencies\DependencyGraph;
use App\Services\Dependencies\DependencyService;
use App\Services\Registers\RegisterKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DependencyController extends Controller
{
    public function store(StoreDependencyRequest $request, Organization $organization, IsmsProject $project, DependencyService $service): RedirectResponse
    {
        $service->create($project, $request->validated(), $this->actor($request));

        return back()->with('success', 'Abhängigkeit angelegt.');
    }

    public function update(UpdateDependencyRequest $request, Organization $organization, IsmsProject $project, DependencyEdge $dependency, DependencyService $service): RedirectResponse
    {
        $service->update($dependency, $request->safe()->only(['importance', 'reason']), $this->actor($request), $request->validated('updated_at'));

        return back()->with('success', 'Abhängigkeit aktualisiert.');
    }

    public function status(ChangeDependencyStatusRequest $request, Organization $organization, IsmsProject $project, DependencyEdge $dependency, DependencyService $service): RedirectResponse
    {
        $service->changeStatus($dependency, $request->boolean('active'), $this->actor($request));

        return back()->with('success', 'Abhängigkeitsstatus aktualisiert.');
    }

    public function graph(ShowDependencyGraphRequest $request, Organization $organization, IsmsProject $project, string $type, string $key, DependencyGraph $graph): JsonResponse
    {
        $node = $this->node($project, DependencyNodeType::from($type), RegisterKey::normalize($key));
        $direction = $request->validated('direction', 'dependencies');
        $transitive = $request->validated('transitive', 'false') === 'true';
        $includeInactive = $request->validated('include_inactive', 'false') === 'true';
        $hits = match ($direction) {
            'dependents' => $graph->dependents($project, $node, $transitive, $includeInactive),
            'affected_processes' => $graph->affectedProcesses($project, $node, $transitive, $includeInactive),
            default => $graph->dependencies($project, $node, $transitive, $includeInactive),
        };

        return response()->json(['data' => array_map(fn ($hit): array => ['type' => $hit->node->type->value, 'key' => $hit->node->key, 'depth' => $hit->depth, 'importance' => $hit->importance->value], $hits)]);
    }

    private function node(IsmsProject $project, DependencyNodeType $type, string $key): DependencyNode
    {
        if ($type === DependencyNodeType::Process) {
            $process = BusinessProcess::query()->where('project_id', $project->id)->where('key', $key)->first();
            abort_unless($process instanceof BusinessProcess, 404);

            return DependencyNode::process($process);
        }
        $asset = Asset::query()->where('project_id', $project->id)->where('key', $key)->first();
        abort_unless($asset instanceof Asset, 404);

        return DependencyNode::asset($asset);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
