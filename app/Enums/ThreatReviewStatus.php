<?php

namespace App\Enums;

enum ThreatReviewStatus: string
{
    case Unreviewed = 'unreviewed';
    case Applicable = 'applicable';
    case NotApplicable = 'not_applicable';
}
