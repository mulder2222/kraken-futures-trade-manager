<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class KrakenOpenPosition
{
    public function __construct(
        public string $symbol,
        public float $size,
        public float $entryPrice,
        public float $markPrice,
        public float $pnl,
        public array $raw,
    ) {
    }
}
