<?php
namespace App\Http\Requests\Processes;
use App\Models\BusinessProcess; use App\Models\IsmsProject; use App\Models\Organization; use Illuminate\Foundation\Http\FormRequest; use Illuminate\Support\Facades\Gate;
class ChangeBusinessProcessStatusRequest extends FormRequest { public function authorize(): bool { return Gate::allows('changeStatus',$this->process()); } public function rules(): array { return ['active'=>['required','boolean']]; } private function process(): BusinessProcess { $o=$this->route('organization');$p=$this->route('project');$r=$this->route('process');abort_unless($o instanceof Organization&&$p instanceof IsmsProject&&$r instanceof BusinessProcess&&$o->organization_type==='customer'&&$p->organization_id===$o->id&&$r->project_id===$p->id,404);return $r; } }
