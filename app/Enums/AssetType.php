<?php

namespace App\Enums;

enum AssetType: string
{
    case Information = 'information';
    case Application = 'application';
    case ItSystem = 'it_system';
    case Service = 'service';
    case Facility = 'facility';
}
