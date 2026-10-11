<?php

namespace App\Enums;

enum ProtectionNeedStatus: string
{
    case Draft = 'draft';
    case Assessed = 'assessed';
    case Approved = 'approved';
}
