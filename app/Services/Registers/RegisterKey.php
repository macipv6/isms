<?php

namespace App\Services\Registers;

use Illuminate\Support\Facades\Validator;

class RegisterKey
{
    public static function normalize(string $key): string
    {
        $normalized = strtoupper(trim($key));
        Validator::make(['key' => $normalized], ['key' => ['required', 'string', 'regex:/^[A-Z0-9][A-Z0-9._-]{1,63}$/']])->validate();

        return $normalized;
    }
}
