<?php

namespace App\Data\Bcm;

use App\Enums\DependencyNodeType;

final readonly class BcmDependencyPath
{
    /** @param list<string> $pathEdgeIds */
    public function __construct(
        public DependencyNodeType $targetType,
        public string $targetId,
        public bool $critical,
        public array $pathEdgeIds,
    ) {}
}
