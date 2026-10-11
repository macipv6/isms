<?php

namespace App\Http\Requests\Risks;

final class RiskValidationMessages
{
    /** @return array<string, string> */
    public static function all(): array
    {
        return [
            'required' => 'Dieses Feld ist erforderlich.',
            'string' => 'Dieses Feld muss Text enthalten.',
            'max' => 'Dieses Feld ist zu lang.',
            'uuid' => 'Die Referenz ist ungültig.',
            'email' => 'Die E-Mail-Adresse ist ungültig.',
            'date_format' => 'Ein gültiges Datum ist erforderlich.',
            'in' => 'Der gewählte Wert ist ungültig.',
            'enum' => 'Der gewählte Wert ist ungültig.',
            'Illuminate\\Validation\\Rules\\Enum' => 'Der gewählte Wert ist ungültig.',
            'boolean' => 'Dieses Feld muss wahr oder falsch sein.',
            'integer' => 'Dieses Feld muss eine ganze Zahl sein.',
            'array' => 'Dieses Feld muss eine Liste enthalten.',
            'min' => 'Dieses Feld unterschreitet den zulässigen Mindestwert.',
            'required_if' => 'Dieses Feld ist erforderlich.',
            'required_unless' => 'Dieses Feld ist erforderlich.',
            'required_with' => 'Dieses Feld ist erforderlich.',
            'exists' => 'Die gewählte Referenz ist ungültig.',
            'regex' => 'Dieses Feld hat ein ungültiges Format.',
            'distinct' => 'Dieser Wert darf nicht mehrfach vorkommen.',
        ];
    }
}
