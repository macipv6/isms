<?php

namespace App\Http;

use App\Models\Asset;
use App\Models\AssetRecoveryObjectiveRevision;
use App\Models\BcmExercise;
use App\Models\BcmExerciseResult;
use App\Models\BiaRevision;
use App\Models\ContinuityPlanRevision;
use App\Models\IsmsProject;
use App\Models\User;
use App\Policies\ProjectBcmPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class BcmRouteScope
{
    public static function project(Request $request, bool $write = true): IsmsProject
    {
        $project = IsmsProject::query()->with('organization')->findOrFail(self::routeId($request, 'project'));
        if ($request->route('asset') !== null) {
            self::child($request, $project, 'asset', Asset::class);
        }
        if ($request->route('revision') !== null) {
            $class = match (true) {
                $request->route('asset') !== null => AssetRecoveryObjectiveRevision::class,
                str_contains($request->path(), '/bia/') => BiaRevision::class,
                default => ContinuityPlanRevision::class,
            };
            $revision = self::child($request, $project, 'revision', $class);
            if ($revision instanceof AssetRecoveryObjectiveRevision) {
                abort_unless($revision->asset_id === $request->route('asset'), 404);
            }
        }
        if ($request->route('exercise') !== null) {
            self::child($request, $project, 'exercise', BcmExercise::class);
        }
        if ($request->route('result') !== null) {
            $result = self::child($request, $project, 'result', BcmExerciseResult::class);
            abort_unless($result->bcm_exercise_id === $request->route('exercise'), 404);
        }
        $policy = app(ProjectBcmPolicy::class);
        abort_unless($write ? $policy->write(self::actor($request), $project) : $policy->view(self::actor($request), $project), 403);

        return $project;
    }

    public static function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    /** @template T of Model
     * @param  class-string<T>  $class
     * @return T
     */
    public static function child(Request $request, IsmsProject $project, string $parameter, string $class): Model
    {
        return $class::query()->where('project_id', $project->id)->findOrFail(self::routeId($request, $parameter));
    }

    /** @template T of Model
     * @param  class-string<T>  $class
     * @return T
     */
    public static function inputChild(Request $request, IsmsProject $project, string $field, string $class): Model
    {
        $data = $request->validate([$field => ['required', 'uuid']]);

        return $class::query()->where('project_id', $project->id)->findOrFail($data[$field]);
    }

    public static function version(Request $request): int
    {
        $data = $request->validate(['lock_version' => ['required', 'integer']]);

        return (int) $data['lock_version'];
    }

    /** Browser controls send strings; normalize only documented number/boolean fields.
     * @return array<string, mixed>
     */
    public static function payload(Request $request): array
    {
        $data = $request->all();
        foreach (['rto_minutes', 'rpo_minutes', 'mtpd_minutes'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = self::integer($data[$field]);
            }
        }
        if (isset($data['rpo_not_applicable'])) {
            $data['rpo_not_applicable'] = match ($data['rpo_not_applicable']) {
                0, '0', 'false' => false,
                1, '1', 'true' => true,
                default => $data['rpo_not_applicable'],
            };
        }
        foreach (['impacts' => ['minutes', 'level'], 'steps' => ['expected_minutes'], 'criteria' => ['expected_minutes'], 'results' => ['actual_minutes']] as $list => $fields) {
            if (! isset($data[$list]) || ! is_array($data[$list])) {
                continue;
            }
            foreach ($data[$list] as &$row) {
                if (is_array($row)) {
                    foreach ($fields as $field) {
                        if (array_key_exists($field, $row)) {
                            $row[$field] = self::integer($row[$field]);
                        }
                    }
                }
            }
            unset($row);
        }
        if (isset($data['minutes']) && is_array($data['minutes'])) {
            $data['minutes'] = array_map(self::integer(...), $data['minutes']);
        }
        // Editable full replacement lists have a finite contract. Results/history paginate.
        foreach (['steps', 'roles', 'criteria', 'process_ids', 'evidence_ids', 'risk_analysis_ids'] as $field) {
            if (isset($data[$field]) && is_array($data[$field]) && count($data[$field]) > 200) {
                throw ValidationException::withMessages([$field => 'Höchstens 200 Einträge sind erlaubt.']);
            }
        }

        return $data;
    }

    private static function integer(mixed $value): mixed
    {
        if (is_string($value) && preg_match('/^-?(0|[1-9][0-9]*)$/D', $value)) {
            $integer = filter_var($value, FILTER_VALIDATE_INT);
            if ($integer !== false) {
                return $integer;
            }
        }

        return $value;
    }

    private static function routeId(Request $request, string $parameter): string
    {
        $id = $request->route($parameter);
        abort_unless(is_string($id) && Str::isUuid($id), 404);

        return $id;
    }
}
