<?php

namespace App\Enums;

enum DowngradeRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Superseded = 'superseded';
    case Invalidated = 'invalidated';
}
