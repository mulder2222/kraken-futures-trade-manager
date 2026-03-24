<?php

declare(strict_types=1);

namespace App\ValueObject;

use App\Enum\TradeSide;

final readonly class OpenTradeRequest
{
    public function __construct(
        public string $symbol,
        public TradeSide $side,
        public float $size,
        public string $entryType,
        public ?float $entryPrice,
        public float $stopLossPrice,
        public float $tp1Price,
        public float $tp2Price,
    ) {
    }
}
