<?php

namespace App\Http\Requests\Processes;

use App\Models\BusinessProcess;
use App\Models\IsmsProject;
use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateBusinessProcessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('update', $this->process());
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:160'], 'description' => ['nullable', 'string', 'max:4000'], 'owner_name' => ['nullable', 'string', 'max:160'], 'owner_email' => ['nullable', 'email:rfc', 'max:254'], 'updated_at' => ['required', 'date']];
    }

    private function process(): BusinessProcess
    {
        $organization = $this->route('organization');
        $project = $this->route('project');
        $process = $this->route('process');
        abort_unless($organization instanceof Organization && $project instanceof IsmsProject && $process instanceof BusinessProcess && $organization->organization_type === 'customer' && $project->organization_id === $organization->id && $process->project_id === $project->id, 404);

        return $process;
    }
}
