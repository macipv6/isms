<?php

namespace App\Data\Imports;

use App\Enums\RegisterImportKind;

final readonly class ParsedRegisterCsv
{
    /** @param list<string> $headers @param list<RegisterCsvRow> $rows */
    public function __construct(public RegisterImportKind $kind, public string $sha256, public array $headers, public array $rows) {}
}
