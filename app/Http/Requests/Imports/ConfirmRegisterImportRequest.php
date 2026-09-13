<?php

namespace App\Http\Requests\Imports;

use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\RegisterImportBatch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ConfirmRegisterImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = $this->route('organization');
        $project = $this->route('project');
        $batch = $this->route('batch');
        abort_unless(
            $organization instanceof Organization
            && $project instanceof IsmsProject
            && $batch instanceof RegisterImportBatch
            && $organization->organization_type === 'customer'
            && $project->organization_id === $organization->id
            && $batch->project_id === $project->id,
            404,
        );

        return Gate::allows('confirm', $batch);
    }

    /** @return array<string, never> */
    public function rules(): array
    {
        return [];
    }
}
