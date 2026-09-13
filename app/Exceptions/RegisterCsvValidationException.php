<?php

namespace App\Exceptions;

use App\Data\Imports\ParsedRegisterCsv;
use Illuminate\Validation\ValidationException;

final class RegisterCsvValidationException extends ValidationException
{
    public ParsedRegisterCsv $parsed;

    public int $invalidRowCount;

    /** @param array<string, list<string>> $errors */
    public static function withParsed(ParsedRegisterCsv $parsed, array $errors, int $invalidRowCount): self
    {
        $exception = self::withMessages($errors);
        $exception->parsed = $parsed;
        $exception->invalidRowCount = $invalidRowCount;

        return $exception;
    }
}
