<?php

namespace App\Data\Imports;

final readonly class RegisterCsvRow
{
    /** @param array<string, string|bool|null> $values */
    public function __construct(public int $line, public array $values) {}
}
