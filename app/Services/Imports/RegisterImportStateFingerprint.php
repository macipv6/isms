<?php

namespace App\Services\Imports;

use JsonException;

class RegisterImportStateFingerprint
{
    /**
     * @param  array<string, string>  $categoriesByKey
     *
     * @throws JsonException
     */
    public function make(array $categoriesByKey): string
    {
        ksort($categoriesByKey);

        return hash_hmac(
            'sha256',
            json_encode($categoriesByKey, JSON_THROW_ON_ERROR),
            (string) config('app.key'),
        );
    }
}
