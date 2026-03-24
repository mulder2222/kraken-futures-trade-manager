<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\KrakenFill;
use App\Dto\KrakenOpenOrder;
use App\Dto\KrakenOpenPosition;
use App\Entity\Trade;
use App\Enum\TradeSide;
use App\Enum\TradeStatus;
use App\Exception\ActiveTradeExistsException;
use App\Exception\InvalidTradeInputException;
use App\Repository\TradeRepository;
use App\Service\TradeManagerService;
use App\Tests\Double\FakeKrakenFuturesClient;
use App\ValueObject\OpenTradeRequest;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

final class TradeManagerServiceTest extends TestCase
{
    public function testTp1FilledCancelsOldStopAndPlacesBreakEvenStop(): void
    {
        $client = new FakeKrakenFuturesClient();
        $trade = new Trade('PF_XBTUSD', TradeSide::LONG, 60000.0, 2.0, 59000.0, 61000.0, 62000.0, false, new DateTimeImmutable());
        $trade->setStatus(TradeStatus::ACTIVE);
        $trade->setKrakenOrderId('stop_loss', 'sl-1');
        $trade->setKrakenOrderId('tp1', 'tp1-1');
        $trade->setKrakenOrderId('tp2', 'tp2-1');

        $position = new KrakenOpenPosition('PF_XBTUSD', 1.0, 60000.0, 61100.0, 1100.0, []);
        $service = $this->createService($client);

        $service->handleTp1Filled($trade, $position, true);

        self::assertSame(['sl-1'], $client->cancelledOrders);
        self::assertTrue($trade->isTp1Filled());
        self::assertTrue($trade->isBreakEvenMoved());
        self::assertNotNull($trade->getKrakenOrderId('break_even_stop_loss'));
        self::assertSame('60000.00000000', $client->sentOrders[0]['stopPrice']);
        self::assertSame('1.00000000', $client->sentOrders[0]['size']);
    }

    public function testCleanupCancelsOpenOrdersWhenNoPositionExists(): void
    {
        $client = new FakeKrakenFuturesClient();
        $client->openOrders = [
            new KrakenOpenOrder('ord-1', 'PF_XBTUSD', 'sell', 'stop', 1.0, 0.0, null, 59000.0, true, []),
            new KrakenOpenOrder('ord-2', 'PF_XBTUSD', 'sell', 'take_profit', 1.0, 0.0, null, 62000.0, true, []),
        ];

        $service = $this->createService($client);
        $cancelled = $service->cleanupOrphanedOrders('PF_XBTUSD', true);

        self::assertSame(2, $cancelled);
        self::assertSame(['ord-1', 'ord-2'], $client->cancelledOrders);
    }

    public function testOpenTradeRejectsInvalidInput(): void
    {
        $service = $this->createService(new FakeKrakenFuturesClient());

        $this->expectException(InvalidTradeInputException::class);

        $service->openTrade(new OpenTradeRequest(
            'PF_XBTUSD',
            TradeSide::LONG,
            1.0,
            'limit',
            60000.0,
            61000.0,
            62000.0,
            63000.0,
        ));
    }

    public function testNoSecondTradeAllowedWhileFirstIsActive(): void
    {
        $existingTrade = new Trade('PF_XBTUSD', TradeSide::LONG, 60000.0, 1.0, 59000.0, 61000.0, 62000.0, false, new DateTimeImmutable());
        $existingTrade->setStatus(TradeStatus::ACTIVE);
        $service = $this->createService(new FakeKrakenFuturesClient(), $existingTrade);

        $this->expectException(ActiveTradeExistsException::class);

        $service->openTrade(new OpenTradeRequest(
            'PF_XBTUSD',
            TradeSide::SHORT,
            1.0,
            'limit',
            60000.0,
            61000.0,
            59000.0,
            58000.0,
        ));
    }

    public function testDryRunTradeMonitorSkipsKrakenApiCalls(): void
    {
        $client = new FakeKrakenFuturesClient();
        $trade = new Trade('PF_XBTUSD', TradeSide::LONG, 60000.0, 1.0, 59000.0, 61000.0, 62000.0, true, new DateTimeImmutable());
        $trade->setStatus(TradeStatus::ACTIVE);

        $service = $this->createService($client, $trade);
        $syncedTrade = $service->syncTradeState();

        self::assertSame($trade, $syncedTrade);
        self::assertSame([], $client->sentOrders);
        self::assertSame([], $client->cancelledOrders);
    }

    private function createService(FakeKrakenFuturesClient $client, ?Trade $activeTrade = null): TradeManagerService
    {
        $repository = $this->createMock(TradeRepository::class);
        $repository->method('findActiveTrade')->willReturn($activeTrade);

        $entityManager = $this->createMock(EntityManagerInterface::class);

        $clock = new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-03-24T12:00:00+00:00');
            }
        };

        return new TradeManagerService(
            $repository,
            $entityManager,
            $client,
            new LockFactory(new FlockStore(sys_get_temp_dir())),
            $clock,
            new NullLogger(),
            0.5,
        );
    }
}
