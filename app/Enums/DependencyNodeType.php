<?php

namespace App\Enums;

enum DependencyNodeType: string
{
    case Process = 'process';
    case Asset = 'asset';
}
