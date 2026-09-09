<?php
namespace App\Http\Requests\Assets;
use App\Models\Asset; use App\Models\IsmsProject; use App\Models\Organization; use Illuminate\Foundation\Http\FormRequest; use Illuminate\Support\Facades\Gate;
class ChangeAssetStatusRequest extends FormRequest { public function authorize(): bool { return Gate::allows('changeStatus',$this->asset()); } public function rules(): array { return ['active'=>['required','boolean']]; } private function asset(): Asset { $o=$this->route('organization');$p=$this->route('project');$r=$this->route('asset');abort_unless($o instanceof Organization&&$p instanceof IsmsProject&&$r instanceof Asset&&$o->organization_type==='customer'&&$p->organization_id===$o->id&&$r->project_id===$p->id,404);return $r; } }
