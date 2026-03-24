<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class KrakenOrderActionResult
{
    public function __construct(
        public string $status,
        public string $orderId,
        public ?string $receivedTime,
        public array $raw,
    ) {
    }
}
