<?php

namespace App\Http\Requests\Assets;

use App\Enums\AssetType;
use App\Models\Asset;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Rules\Iso8601ConcurrencyToken;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('update', $this->asset());
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:160'], 'type' => ['required', Rule::enum(AssetType::class)], 'description' => ['nullable', 'string', 'max:4000'], 'owner_name' => ['nullable', 'string', 'max:160'], 'owner_email' => ['nullable', 'email:rfc', 'max:254'], 'updated_at' => ['required', new Iso8601ConcurrencyToken]];
    }

    private function asset(): Asset
    {
        $organization = $this->route('organization');
        $project = $this->route('project');
        $asset = $this->route('asset');
        abort_unless($organization instanceof Organization && $project instanceof IsmsProject && $asset instanceof Asset && $organization->organization_type === 'customer' && $project->organization_id === $organization->id && $asset->project_id === $project->id, 404);

        return $asset;
    }
}
