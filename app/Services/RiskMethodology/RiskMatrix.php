<?php

namespace App\Services\RiskMethodology;

use App\Models\RiskLevel;
use App\Models\RiskMethodologyVersion;
use Illuminate\Validation\ValidationException;

class RiskMatrix
{
    public function category(RiskMethodologyVersion $version, string $frequencyKey, string $impactKey): RiskLevel
    {
        $cell = $version->matrixCells()
            ->whereHas('frequency', fn ($query) => $query->where('key', $frequencyKey))
            ->whereHas('impact', fn ($query) => $query->where('key', $impactKey))
            ->with('riskLevel')
            ->first();

        if (! $cell) {
            throw ValidationException::withMessages(['matrix' => 'Für diese Methodikstufen ist keine Risikokategorie hinterlegt.']);
        }

        return $cell->riskLevel;
    }
}
