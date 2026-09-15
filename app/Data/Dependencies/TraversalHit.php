<?php

namespace App\Data\Dependencies;

use App\Enums\DependencyImportance;

readonly class TraversalHit
{
    public function __construct(
        public DependencyNode $node,
        public int $depth,
        public DependencyImportance $importance,
    ) {}
}
