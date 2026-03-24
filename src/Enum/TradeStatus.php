<?php

declare(strict_types=1);

namespace App\Enum;

enum TradeStatus: string
{
    case PENDING = 'pending';
    case ACTIVE = 'active';
    case TP1_FILLED = 'tp1_filled';
    case CLOSED = 'closed';
    case ERROR = 'error';
}
