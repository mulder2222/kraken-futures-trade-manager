<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class KrakenOpenOrder
{
    public function __construct(
        public string $orderId,
        public string $symbol,
        public string $side,
        public string $type,
        public float $quantity,
        public float $filledQuantity,
        public ?float $limitPrice,
        public ?float $stopPrice,
        public bool $reduceOnly,
        public array $raw,
    ) {
    }
}
