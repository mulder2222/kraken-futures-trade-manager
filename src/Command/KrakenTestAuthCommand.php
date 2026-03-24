<?php

declare(strict_types=1);

namespace App\Command;

use App\Config\TradingRuntime;
use App\Exception\TradeException;
use App\Kraken\KrakenFuturesClientInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'kraken:test-auth', description: 'Verify Kraken Futures private API authentication with a read-only request.')]
final class KrakenTestAuthCommand extends Command
{
    public function __construct(
        private readonly KrakenFuturesClientInterface $krakenClient,
        private readonly TradingRuntime $runtime,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('symbol', null, InputOption::VALUE_OPTIONAL, 'Optional symbol filter for the auth test.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $symbol = $input->getOption('symbol');
        $symbol = is_string($symbol) && $symbol !== '' ? $symbol : null;

        $io->section('Kraken Futures Auth Test');
        $io->definitionList(
            ['Mode' => $this->runtime->isSandbox() ? 'sandbox' : 'live'],
            ['Base URI' => $this->runtime->krakenBaseUri()],
        );

        try {
            $positions = $this->krakenClient->getOpenPositions($symbol);

            $io->success(sprintf(
                'Authentication succeeded. Private API call returned %d open position(s)%s.',
                count($positions),
                $symbol !== null ? sprintf(' for %s', $symbol) : ''
            ));

            return Command::SUCCESS;
        } catch (TradeException $exception) {
            $io->error($exception->getMessage());
            $io->writeln('Controleer of je demo/live mode klopt, je API key uit dezelfde omgeving komt, en de secret exact goed is geplakt.');

            return Command::FAILURE;
        }
    }
}
