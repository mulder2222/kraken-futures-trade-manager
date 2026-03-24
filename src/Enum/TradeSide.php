<?php

declare(strict_types=1);

namespace App\Enum;

enum TradeSide: string
{
    case LONG = 'long';
    case SHORT = 'short';

    public function entryOrderSide(): string
    {
        return $this === self::LONG ? 'buy' : 'sell';
    }

    public function exitOrderSide(): string
    {
        return $this === self::LONG ? 'sell' : 'buy';
    }
}
