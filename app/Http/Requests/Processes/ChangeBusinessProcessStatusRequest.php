<?php

namespace App\Http\Requests\Processes;

use App\Models\BusinessProcess;
use App\Models\IsmsProject;
use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ChangeBusinessProcessStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('changeStatus', $this->process());
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['active' => ['required', 'boolean']];
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
