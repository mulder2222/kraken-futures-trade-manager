<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\TradeSide;
use App\Enum\TradeStatus;
use App\Repository\TradeRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TradeRepository::class)]
#[ORM\Table(name: 'trades')]
class Trade
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32)]
    private string $symbol;

    #[ORM\Column(enumType: TradeSide::class)]
    private TradeSide $side;

    #[ORM\Column(type: 'decimal', precision: 18, scale: 8)]
    private string $entryPrice;

    #[ORM\Column(type: 'decimal', precision: 18, scale: 8)]
    private string $size;

    #[ORM\Column(type: 'decimal', precision: 18, scale: 8)]
    private string $stopLossPrice;

    #[ORM\Column(type: 'decimal', precision: 18, scale: 8)]
    private string $tp1Price;

    #[ORM\Column(type: 'decimal', precision: 18, scale: 8)]
    private string $tp2Price;

    #[ORM\Column(enumType: TradeStatus::class)]
    private TradeStatus $status = TradeStatus::PENDING;

    #[ORM\Column]
    private bool $tp1Filled = false;

    #[ORM\Column]
    private bool $breakEvenMoved = false;

    #[ORM\Column(type: 'json')]
    private array $krakenOrderIds = [];

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    public function __construct(
        string $symbol,
        TradeSide $side,
        float $entryPrice,
        float $size,
        float $stopLossPrice,
        float $tp1Price,
        float $tp2Price,
        DateTimeImmutable $createdAt,
    ) {
        $this->symbol = $symbol;
        $this->side = $side;
        $this->entryPrice = $this->formatDecimal($entryPrice);
        $this->size = $this->formatDecimal($size);
        $this->stopLossPrice = $this->formatDecimal($stopLossPrice);
        $this->tp1Price = $this->formatDecimal($tp1Price);
        $this->tp2Price = $this->formatDecimal($tp2Price);
        $this->createdAt = $createdAt;
        $this->updatedAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSymbol(): string
    {
        return $this->symbol;
    }

    public function getSide(): TradeSide
    {
        return $this->side;
    }

    public function getEntryPrice(): float
    {
        return (float) $this->entryPrice;
    }

    public function getSize(): float
    {
        return (float) $this->size;
    }

    public function getStopLossPrice(): float
    {
        return (float) $this->stopLossPrice;
    }

    public function getTp1Price(): float
    {
        return (float) $this->tp1Price;
    }

    public function getTp2Price(): float
    {
        return (float) $this->tp2Price;
    }

    public function getStatus(): TradeStatus
    {
        return $this->status;
    }

    public function setStatus(TradeStatus $status): void
    {
        $this->status = $status;
        $this->touch();
    }

    public function isTp1Filled(): bool
    {
        return $this->tp1Filled;
    }

    public function markTp1Filled(): void
    {
        $this->tp1Filled = true;
        $this->touch();
    }

    public function isBreakEvenMoved(): bool
    {
        return $this->breakEvenMoved;
    }

    public function markBreakEvenMoved(): void
    {
        $this->breakEvenMoved = true;
        $this->touch();
    }

    public function getKrakenOrderIds(): array
    {
        return $this->krakenOrderIds;
    }

    public function setKrakenOrderId(string $key, string $orderId): void
    {
        $this->krakenOrderIds[$key] = $orderId;
        $this->touch();
    }

    public function unsetKrakenOrderId(string $key): void
    {
        unset($this->krakenOrderIds[$key]);
        $this->touch();
    }

    public function getKrakenOrderId(string $key): ?string
    {
        return $this->krakenOrderIds[$key] ?? null;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }

    private function formatDecimal(float $value): string
    {
        return number_format($value, 8, '.', '');
    }
}
