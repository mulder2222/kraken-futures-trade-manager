<?php

declare(strict_types=1);

namespace App\Config;

final readonly class TradingRuntime
{
    public function __construct(
        private bool $dryRunByDefault,
        private string $defaultSymbol,
        private float $tp1Ratio,
        private bool $sandbox,
        private string $liveBaseUri,
        private string $sandboxBaseUri,
        private int $requestTimeout,
        private int $maxRetries,
    ) {
    }

    public function isDryRunByDefault(): bool
    {
        return $this->dryRunByDefault;
    }

    public function defaultSymbol(): string
    {
        return $this->defaultSymbol;
    }

    public function tp1Ratio(): float
    {
        return $this->tp1Ratio;
    }

    public function isSandbox(): bool
    {
        return $this->sandbox;
    }

    public function krakenBaseUri(): string
    {
        return $this->sandbox ? $this->sandboxBaseUri : $this->liveBaseUri;
    }

    public function requestTimeout(): int
    {
        return $this->requestTimeout;
    }

    public function maxRetries(): int
    {
        return $this->maxRetries;
    }
}
