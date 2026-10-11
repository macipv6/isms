<?php

namespace App\Enums;

enum RiskTreatmentAction: string
{
    case Avoid = 'avoid';
    case Reduce = 'reduce';
    case Transfer = 'transfer';
    case Accept = 'accept';
}
