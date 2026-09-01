<?php

namespace App\Enums;

enum CatalogStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Retired = 'retired';
}
