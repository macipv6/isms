<?php

namespace App\Data\Imports;

use App\Enums\RegisterImportStatus;

final readonly class RegisterImportPreview
{
    /**
     * @param  list<array<string, string|bool|null>>  $payload
     * @param  array{counts: array{new: int, changed: int, unchanged: int, invalid: int}, rows: list<array<string, mixed>>, state_fingerprint?: string}  $summary
     */
    public function __construct(
        public array $payload,
        public array $summary,
        public RegisterImportStatus $status,
    ) {}
}
