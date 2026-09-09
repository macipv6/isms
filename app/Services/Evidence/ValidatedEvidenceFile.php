<?php

namespace App\Services\Evidence;

final readonly class ValidatedEvidenceFile
{
    public function __construct(
        public string $originalName,
        public string $mimeType,
        public string $kind,
        public int $sizeBytes,
        public string $temporaryPath,
    ) {}
}
