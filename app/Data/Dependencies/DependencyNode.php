<?php

namespace App\Data\Dependencies;

use App\Enums\DependencyNodeType;
use App\Models\Asset;
use App\Models\BusinessProcess;

readonly class DependencyNode
{
    public function __construct(
        public DependencyNodeType $type,
        public string $id,
        public string $projectId,
        public string $key,
    ) {}

    public static function process(BusinessProcess $process): self
    {
        return new self(DependencyNodeType::Process, $process->id, $process->project_id, $process->key);
    }

    public static function asset(Asset $asset): self
    {
        return new self(DependencyNodeType::Asset, $asset->id, $asset->project_id, $asset->key);
    }

    public function identity(): string
    {
        return $this->type->value.':'.$this->id;
    }
}
