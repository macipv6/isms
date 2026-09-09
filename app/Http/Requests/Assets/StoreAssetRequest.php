<?php

namespace App\Http\Requests\Assets;

use App\Enums\AssetType;
use App\Models\Asset;
use App\Models\IsmsProject;
use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', [Asset::class, $this->project()]);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['key' => ['required', 'string'], 'name' => ['required', 'string', 'max:160'], 'type' => ['required', Rule::enum(AssetType::class)], 'description' => ['nullable', 'string', 'max:4000'], 'owner_name' => ['nullable', 'string', 'max:160'], 'owner_email' => ['nullable', 'email:rfc', 'max:254'], 'active' => ['sometimes', 'boolean']];
    }

    private function project(): IsmsProject
    {
        $organization = $this->route('organization');
        $project = $this->route('project');
        abort_unless($organization instanceof Organization && $project instanceof IsmsProject && $organization->organization_type === 'customer' && $project->organization_id === $organization->id, 404);

        return $project;
    }
}
