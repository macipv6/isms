<?php

namespace App\Enums;

enum FindingStatus: string
{
    case Proposed = 'proposed';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Closed = 'closed';
}
