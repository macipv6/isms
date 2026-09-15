<?php

namespace App\Http\Requests\Imports;

use App\Enums\RegisterImportKind;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\RegisterImportBatch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class PreviewRegisterImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $this->kind();

        return Gate::allows('create', [RegisterImportBatch::class, $this->project()]);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['file' => ['required', 'file', 'max:5120', 'extensions:csv']];
    }

    public function kind(): RegisterImportKind
    {
        $kind = $this->route('kind');

        return is_string($kind) ? RegisterImportKind::tryFrom($kind) ?? abort(404) : abort(404);
    }

    private function project(): IsmsProject
    {
        $organization = $this->route('organization');
        $project = $this->route('project');
        abort_unless($organization instanceof Organization && $project instanceof IsmsProject && $organization->organization_type === 'customer' && $project->organization_id === $organization->id, 404);

        return $project;
    }
}
