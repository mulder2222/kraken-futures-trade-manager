<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\KrakenFill;
use App\Dto\KrakenOpenOrder;
use App\Dto\KrakenOpenPosition;
use App\Dto\KrakenOrderActionResult;
use App\Entity\Trade;
use App\Enum\TradeSide;
use App\Enum\TradeStatus;
use App\Exception\ActiveTradeExistsException;
use App\Exception\InconsistentTradeStateException;
use App\Exception\InvalidTradeInputException;
use App\Kraken\KrakenFuturesClientInterface;
use App\Repository\TradeRepository;
use App\ValueObject\OpenTradeRequest;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;

final class TradeManagerService
{
    public function __construct(
        private readonly TradeRepository $tradeRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly KrakenFuturesClientInterface $krakenClient,
        private readonly LockFactory $lockFactory,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly float $tp1Ratio,
    ) {
    }

    public function openTrade(OpenTradeRequest $request, bool $execute = false): Trade
    {
        $lock = $this->lockFactory->createLock('trade-manager');
        $lock->acquire(true);

        try {
            $this->validateSingleActiveTrade();
            $this->validateRequest($request, $execute);

            $entryResult = $execute
                ? $this->krakenClient->sendOrder($this->buildEntryPayload($request))
                : $this->fakeOrderAction('entry');

            $entryPrice = $this->resolveEntryPrice($request);

            $trade = new Trade(
                $request->symbol,
                $request->side,
                $entryPrice,
                $request->size,
                $request->stopLossPrice,
                $request->tp1Price,
                $request->tp2Price,
                !$execute,
                $this->now(),
            );
            $trade->setStatus(TradeStatus::ACTIVE);
            $trade->setKrakenOrderId('entry', $entryResult->orderId);

            $this->entityManager->persist($trade);
            $this->placeInitialOrders($trade, $execute);
            $this->entityManager->flush();

            $this->logger->info('Trade opened', [
                'trade_id' => $trade->getId(),
                'symbol' => $trade->getSymbol(),
                'side' => $trade->getSide()->value,
                'execute' => $execute,
            ]);

            return $trade;
        } finally {
            $lock->release();
        }
    }

    public function placeInitialOrders(Trade $trade, bool $execute = false): void
    {
        if ($trade->getKrakenOrderId('stop_loss') !== null || $trade->getKrakenOrderId('tp1') !== null || $trade->getKrakenOrderId('tp2') !== null) {
            throw new InconsistentTradeStateException('Initial protection orders already exist for this trade.');
        }

        [$tp1Size, $tp2Size] = $this->splitTakeProfitSizes($trade->getSize());

        $stopLoss = $execute
            ? $this->krakenClient->sendOrder($this->buildStopLossPayload($trade, $trade->getSize(), $trade->getStopLossPrice()))
            : $this->fakeOrderAction('stop-loss');
        $tp1 = $execute
            ? $this->krakenClient->sendOrder($this->buildTakeProfitPayload($trade, $tp1Size, $trade->getTp1Price()))
            : $this->fakeOrderAction('tp1');
        $tp2 = $execute
            ? $this->krakenClient->sendOrder($this->buildTakeProfitPayload($trade, $tp2Size, $trade->getTp2Price()))
            : $this->fakeOrderAction('tp2');

        $trade->setKrakenOrderId('stop_loss', $stopLoss->orderId);
        $trade->setKrakenOrderId('tp1', $tp1->orderId);
        $trade->setKrakenOrderId('tp2', $tp2->orderId);

        $this->logger->info('Initial orders placed', [
            'trade_id' => $trade->getId(),
            'stop_loss_order_id' => $stopLoss->orderId,
            'tp1_order_id' => $tp1->orderId,
            'tp2_order_id' => $tp2->orderId,
            'execute' => $execute,
        ]);
    }

    public function syncTradeState(bool $execute = false): ?Trade
    {
        $lock = $this->lockFactory->createLock('trade-manager');
        $lock->acquire(true);

        try {
            $trade = $this->tradeRepository->findActiveTrade();

            if ($trade === null) {
                return null;
            }

            if ($trade->isDryRun()) {
                $this->logger->info('Dry-run trade sync skipped external Kraken checks', [
                    'trade_id' => $trade->getId(),
                ]);

                return $trade;
            }

            $position = $this->findPositionForTrade($trade);
            if ($position === null) {
                $this->cleanupTradeOrders($trade, $this->krakenClient->getOpenOrders($trade->getSymbol()), $execute);
                $trade->setStatus(TradeStatus::CLOSED);
                $this->entityManager->flush();

                return $trade;
            }

            if (!$trade->isTp1Filled() && $this->wasOrderFilled($trade->getKrakenOrderId('tp1'), $this->krakenClient->getFills($trade->getSymbol()))) {
                $this->handleTp1Filled($trade, $position, $execute);
                $trade->setStatus(TradeStatus::TP1_FILLED);
            }

            $this->assertStopLossMatchesPosition($trade, $position, $this->findOrdersForTrade($trade));
            $this->entityManager->flush();

            return $trade;
        } finally {
            $lock->release();
        }
    }

    public function handleTp1Filled(Trade $trade, KrakenOpenPosition $position, bool $execute = false): void
    {
        if ($trade->isTp1Filled()) {
            return;
        }

        $existingStopLossId = $trade->getKrakenOrderId('stop_loss');

        if ($existingStopLossId === null) {
            throw new InconsistentTradeStateException('Cannot handle TP1 fill because the tracked stop loss is missing.');
        }

        if ($execute) {
            $this->krakenClient->cancelOrder($existingStopLossId);
        }

        $trade->unsetKrakenOrderId('stop_loss');
        $trade->markTp1Filled();

        $this->logger->info('TP1 filled', [
            'trade_id' => $trade->getId(),
            'cancelled_stop_loss_order_id' => $existingStopLossId,
            'execute' => $execute,
        ]);

        $this->moveStopToBreakEven($trade, $position, $execute);
    }

    public function moveStopToBreakEven(Trade $trade, KrakenOpenPosition $position, bool $execute = false): void
    {
        if ($trade->isBreakEvenMoved()) {
            return;
        }

        $remainingSize = round(abs($position->size), 8);

        if ($remainingSize <= 0.0 || $remainingSize > round($trade->getSize(), 8)) {
            throw new InconsistentTradeStateException('Remaining size is invalid for break-even protection.');
        }

        $newStopLoss = $execute
            ? $this->krakenClient->sendOrder($this->buildStopLossPayload($trade, $remainingSize, $trade->getEntryPrice()))
            : $this->fakeOrderAction('break-even');

        $trade->setKrakenOrderId('break_even_stop_loss', $newStopLoss->orderId);
        $trade->markBreakEvenMoved();

        $this->logger->info('Stop loss moved to break-even', [
            'trade_id' => $trade->getId(),
            'new_stop_loss_order_id' => $newStopLoss->orderId,
            'remaining_size' => $remainingSize,
            'execute' => $execute,
        ]);
    }

    public function closeTrade(Trade $trade, bool $execute = false): void
    {
        if ($trade->isDryRun()) {
            $trade->setStatus(TradeStatus::CLOSED);
            $this->entityManager->flush();

            return;
        }

        $this->cleanupTradeOrders($trade, $this->findOrdersForTrade($trade), $execute);
        $trade->setStatus(TradeStatus::CLOSED);
        $this->entityManager->flush();
    }

    public function closeActiveTrade(bool $execute = false): ?Trade
    {
        $lock = $this->lockFactory->createLock('trade-manager');
        $lock->acquire(true);

        try {
            $trade = $this->tradeRepository->findActiveTrade();

            if ($trade === null) {
                return null;
            }

            if ($trade->isDryRun()) {
                $trade->setStatus(TradeStatus::CLOSED);
                $this->entityManager->flush();

                return $trade;
            }

            if (!$execute) {
                throw new InvalidTradeInputException('Live trades can only be closed with --execute. Use trade:reset only for local state reset.');
            }

            $position = $this->findPositionForTrade($trade);

            if ($position !== null) {
                $this->krakenClient->sendOrder($this->buildClosePositionPayload($trade, abs($position->size)));
            }

            $this->cleanupTradeOrders($trade, $this->krakenClient->getOpenOrders($trade->getSymbol()), true);
            $trade->setStatus(TradeStatus::CLOSED);
            $this->entityManager->flush();

            $this->logger->info('Active trade closed', [
                'trade_id' => $trade->getId(),
                'symbol' => $trade->getSymbol(),
            ]);

            return $trade;
        } finally {
            $lock->release();
        }
    }

    public function resetActiveTrade(): ?Trade
    {
        $lock = $this->lockFactory->createLock('trade-manager');
        $lock->acquire(true);

        try {
            $trade = $this->tradeRepository->findActiveTrade();

            if ($trade === null) {
                return null;
            }

            $trade->setStatus(TradeStatus::CLOSED);
            $this->entityManager->flush();

            $this->logger->warning('Active trade reset locally', [
                'trade_id' => $trade->getId(),
                'symbol' => $trade->getSymbol(),
                'dry_run' => $trade->isDryRun(),
            ]);

            return $trade;
        } finally {
            $lock->release();
        }
    }

    public function cleanupOrphanedOrders(string $symbol, bool $execute = false): int
    {
        if ($this->krakenClient->getOpenPositions($symbol) !== []) {
            throw new InconsistentTradeStateException(sprintf('Cleanup refused: open position still exists for %s.', $symbol));
        }

        $cancelled = 0;

        foreach ($this->krakenClient->getOpenOrders($symbol) as $order) {
            if ($execute) {
                $this->krakenClient->cancelOrder($order->orderId);
            }

            ++$cancelled;
            $this->logger->info('Orphaned order cancelled', [
                'symbol' => $symbol,
                'order_id' => $order->orderId,
                'execute' => $execute,
            ]);
        }

        return $cancelled;
    }

    public function validateSingleActiveTrade(): void
    {
        if ($this->tradeRepository->findActiveTrade() !== null) {
            throw new ActiveTradeExistsException('A trade is already active. V1 supports only one active trade.');
        }
    }

    private function validateRequest(OpenTradeRequest $request, bool $execute): void
    {
        if ($request->size <= 0.0) {
            throw new InvalidTradeInputException('Trade size must be greater than zero.');
        }

        if (!in_array($request->entryType, ['market', 'limit'], true)) {
            throw new InvalidTradeInputException('Entry type must be market or limit.');
        }

        if ($request->entryType === 'limit' && $request->entryPrice === null) {
            throw new InvalidTradeInputException('A limit order requires --entry-price.');
        }

        if ($request->entryPrice === null) {
            throw new InvalidTradeInputException('V1 requires --entry-price for both market and limit entries so protective orders can be validated safely.');
        }

        $reference = $request->entryPrice;

        if ($request->side === TradeSide::LONG) {
            if ($request->stopLossPrice >= $reference) {
                throw new InvalidTradeInputException('For long trades the stop loss must be below entry.');
            }

            if ($request->tp1Price <= $reference || $request->tp2Price <= $request->tp1Price) {
                throw new InvalidTradeInputException('For long trades TP1 and TP2 must be above entry, and TP2 must be above TP1.');
            }
        }

        if ($request->side === TradeSide::SHORT) {
            if ($request->stopLossPrice <= $reference) {
                throw new InvalidTradeInputException('For short trades the stop loss must be above entry.');
            }

            if ($request->tp1Price >= $reference || $request->tp2Price >= $request->tp1Price) {
                throw new InvalidTradeInputException('For short trades TP1 and TP2 must be below entry, and TP2 must be below TP1.');
            }
        }
    }

    private function resolveEntryPrice(OpenTradeRequest $request): float
    {
        if ($request->entryPrice === null) {
            throw new InvalidTradeInputException('Entry price is required in this MVP.');
        }

        return $request->entryPrice;
    }

    private function buildEntryPayload(OpenTradeRequest $request): array
    {
        $payload = [
            'symbol' => $request->symbol,
            'side' => $request->side->entryOrderSide(),
            'size' => $this->format($request->size),
            'orderType' => $request->entryType === 'limit' ? 'lmt' : 'market',
        ];

        if ($request->entryPrice !== null) {
            $payload['limitPrice'] = $this->format($request->entryPrice);
        }

        return $payload;
    }

    private function buildStopLossPayload(Trade $trade, float $size, float $stopPrice): array
    {
        return [
            'symbol' => $trade->getSymbol(),
            'side' => $trade->getSide()->exitOrderSide(),
            'size' => $this->format($size),
            'orderType' => 'stp',
            'stopPrice' => $this->format($stopPrice),
            'reduceOnly' => 'true',
            'triggerSignal' => 'mark',
        ];
    }

    private function buildTakeProfitPayload(Trade $trade, float $size, float $targetPrice): array
    {
        return [
            'symbol' => $trade->getSymbol(),
            'side' => $trade->getSide()->exitOrderSide(),
            'size' => $this->format($size),
            'orderType' => 'take_profit',
            'stopPrice' => $this->format($targetPrice),
            'reduceOnly' => 'true',
            'triggerSignal' => 'mark',
        ];
    }

    private function buildClosePositionPayload(Trade $trade, float $size): array
    {
        return [
            'symbol' => $trade->getSymbol(),
            'side' => $trade->getSide()->exitOrderSide(),
            'size' => $this->format($size),
            'orderType' => 'market',
            'reduceOnly' => 'true',
        ];
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function splitTakeProfitSizes(float $size): array
    {
        $tp1Size = round($size * $this->tp1Ratio, 8);
        $tp2Size = round($size - $tp1Size, 8);

        if ($tp1Size <= 0.0 || $tp2Size <= 0.0) {
            throw new InvalidTradeInputException('Configured TP split produces an invalid size.');
        }

        return [$tp1Size, $tp2Size];
    }

    private function findPositionForTrade(Trade $trade): ?KrakenOpenPosition
    {
        foreach ($this->krakenClient->getOpenPositions($trade->getSymbol()) as $position) {
            if ($position->symbol === $trade->getSymbol()) {
                return $position;
            }
        }

        return null;
    }

    /**
     * @return list<KrakenOpenOrder>
     */
    private function findOrdersForTrade(Trade $trade): array
    {
        $trackedIds = array_filter($trade->getKrakenOrderIds());

        return array_values(array_filter(
            $this->krakenClient->getOpenOrders($trade->getSymbol()),
            static fn (KrakenOpenOrder $order): bool => in_array($order->orderId, $trackedIds, true)
        ));
    }

    /**
     * @param list<KrakenFill> $fills
     */
    private function wasOrderFilled(?string $orderId, array $fills): bool
    {
        if ($orderId === null) {
            return false;
        }

        foreach ($fills as $fill) {
            if ($fill->orderId === $orderId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<KrakenOpenOrder> $openOrders
     */
    private function cleanupTradeOrders(Trade $trade, array $openOrders, bool $execute): void
    {
        foreach ($openOrders as $order) {
            if ($execute) {
                $this->krakenClient->cancelOrder($order->orderId);
            }

            $this->logger->info('Order cancelled during cleanup', [
                'trade_id' => $trade->getId(),
                'order_id' => $order->orderId,
                'execute' => $execute,
            ]);
        }
    }

    /**
     * @param list<KrakenOpenOrder> $openOrders
     */
    private function assertStopLossMatchesPosition(Trade $trade, KrakenOpenPosition $position, array $openOrders): void
    {
        $expectedId = $trade->isBreakEvenMoved() ? $trade->getKrakenOrderId('break_even_stop_loss') : $trade->getKrakenOrderId('stop_loss');

        if ($expectedId === null) {
            throw new InconsistentTradeStateException('No tracked stop loss order is present for an active trade.');
        }

        foreach ($openOrders as $order) {
            if ($order->orderId !== $expectedId) {
                continue;
            }

            if (round($order->quantity, 8) !== round(abs($position->size), 8)) {
                throw new InconsistentTradeStateException('Tracked stop loss size does not match the remaining position size.');
            }

            return;
        }

        throw new InconsistentTradeStateException('Tracked stop loss order is missing from open orders.');
    }

    private function fakeOrderAction(string $prefix): KrakenOrderActionResult
    {
        return new KrakenOrderActionResult('dry-run', sprintf('%s-%s', $prefix, bin2hex(random_bytes(4))), null, []);
    }

    private function format(float $value): string
    {
        return number_format($value, 8, '.', '');
    }

    private function now(): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
