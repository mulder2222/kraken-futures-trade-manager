<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class KrakenFill
{
    public function __construct(
        public string $orderId,
        public string $symbol,
        public string $side,
        public float $quantity,
        public float $price,
        public array $raw,
    ) {
    }
}
