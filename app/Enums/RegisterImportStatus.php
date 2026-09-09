<?php

namespace App\Enums;

enum RegisterImportStatus: string
{
    case Pending = 'pending';
    case Applied = 'applied';
    case Rejected = 'rejected';
    case Expired = 'expired';
}
