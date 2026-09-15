<?php

namespace App\Http\Requests\Assets;

use App\Models\Asset;
use App\Models\IsmsProject;
use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ChangeAssetStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('changeStatus', $this->asset());
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['active' => ['required', 'boolean']];
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
