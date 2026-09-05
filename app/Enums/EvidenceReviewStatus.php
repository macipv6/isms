<?php

namespace App\Enums;

enum EvidenceReviewStatus: string
{
    case PendingReview = 'pending_review';
    case Verified = 'verified';
    case Rejected = 'rejected';
}
