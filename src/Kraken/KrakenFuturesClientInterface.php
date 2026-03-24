<?php

declare(strict_types=1);

namespace App\Kraken;

use App\Dto\KrakenFill;
use App\Dto\KrakenOpenOrder;
use App\Dto\KrakenOpenPosition;
use App\Dto\KrakenOrderActionResult;

interface KrakenFuturesClientInterface
{
    public function sendOrder(array $payload): KrakenOrderActionResult;

    public function editOrder(array $payload): KrakenOrderActionResult;

    public function cancelOrder(string $orderId): KrakenOrderActionResult;

    /**
     * @return list<KrakenOpenOrder>
     */
    public function getOpenOrders(?string $symbol = null): array;

    /**
     * @return list<KrakenOpenPosition>
     */
    public function getOpenPositions(?string $symbol = null): array;

    /**
     * @return list<KrakenFill>
     */
    public function getFills(?string $symbol = null): array;

    public function batchOrder(array $payload): array;
}
