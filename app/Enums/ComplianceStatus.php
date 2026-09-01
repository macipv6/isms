<?php

namespace App\Enums;

enum ComplianceStatus: string
{
    case Fulfilled = 'fulfilled';
    case Partial = 'partial';
    case NotFulfilled = 'not_fulfilled';
    case NotApplicable = 'not_applicable';
}
