<?php

namespace App\Http\Requests\Risks;

use App\Enums\ProtectionLevel;
use App\Http\RiskRouteScope;
use App\Models\ProtectionNeed;
use App\Models\ProtectionNeedDowngradeRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class DecideDowngradeRequest extends RiskFormRequest
{
    public function authorize(): bool
    {
        if (! parent::authorize()) {
            return false;
        }
        if ($this->route('need') !== null) {
            $need = RiskRouteScope::child($this, $this->project(), 'need', ProtectionNeed::class);

            return Gate::forUser($this->user())->allows('requestDowngrade', $need);
        }
        $downgrade = RiskRouteScope::child($this, $this->project(), 'downgrade', ProtectionNeedDowngradeRequest::class);

        return Gate::forUser($this->user())->allows($this->input('action') === 'external' ? 'recordExternalDowngrade' : 'decideDowngrade', [ProtectionNeed::class, $downgrade]);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'requested_level' => ['required_if:action,request', Rule::enum(ProtectionLevel::class)],
            'action' => ['required', Rule::in($this->route('need') !== null ? ['request'] : ['internal', 'external'])],
            'approved' => ['required_unless:action,request', 'boolean'],
            'rationale' => ['required', 'string', 'max:10000'],
            'name' => ['required_if:action,external', 'nullable', 'string', 'max:160'],
            'function' => ['required_if:action,external', 'nullable', 'string', 'max:160'],
            'decision_date' => ['required_if:action,external', 'nullable', 'date_format:Y-m-d'],
            'evidence_id' => ['nullable', 'uuid', Rule::exists('evidence_files', 'id')->where('project_id', $this->project()->id)],
        ];
    }
}
