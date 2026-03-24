<?php

declare(strict_types=1);

namespace App\Tests\Double;

use App\Dto\KrakenFill;
use App\Dto\KrakenOpenOrder;
use App\Dto\KrakenOpenPosition;
use App\Dto\KrakenOrderActionResult;
use App\Kraken\KrakenFuturesClientInterface;

final class FakeKrakenFuturesClient implements KrakenFuturesClientInterface
{
    /** @var list<array<string, mixed>> */
    public array $sentOrders = [];

    /** @var list<string> */
    public array $cancelledOrders = [];

    /** @var list<KrakenOpenOrder> */
    public array $openOrders = [];

    /** @var list<KrakenOpenPosition> */
    public array $openPositions = [];

    /** @var list<KrakenFill> */
    public array $fills = [];

    public function sendOrder(array $payload): KrakenOrderActionResult
    {
        $this->sentOrders[] = $payload;

        return new KrakenOrderActionResult('placed', 'order-'.count($this->sentOrders), null, ['payload' => $payload]);
    }

    public function editOrder(array $payload): KrakenOrderActionResult
    {
        return new KrakenOrderActionResult('edited', 'edit-1', null, ['payload' => $payload]);
    }

    public function cancelOrder(string $orderId): KrakenOrderActionResult
    {
        $this->cancelledOrders[] = $orderId;

        return new KrakenOrderActionResult('cancelled', $orderId, null, []);
    }

    public function getOpenOrders(?string $symbol = null): array
    {
        return $this->openOrders;
    }

    public function getOpenPositions(?string $symbol = null): array
    {
        return $this->openPositions;
    }

    public function getFills(?string $symbol = null): array
    {
        return $this->fills;
    }

    public function batchOrder(array $payload): array
    {
        return ['result' => 'success', 'payload' => $payload];
    }
}
