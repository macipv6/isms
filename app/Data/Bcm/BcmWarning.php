<?php

namespace App\Data\Bcm;

use App\Enums\DependencyNodeType;

final readonly class BcmWarning
{
    /** @param list<string> $pathEdgeIds */
    public function __construct(
        public string $code,
        public DependencyNodeType $targetType,
        public string $targetId,
        public ?string $revisionId,
        public array $pathEdgeIds,
        public bool $blocking,
        public string $fingerprint,
    ) {}
}
