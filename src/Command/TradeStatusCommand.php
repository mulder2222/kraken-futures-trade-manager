<?php

declare(strict_types=1);

namespace App\Command;

use App\Kraken\KrakenFuturesClientInterface;
use App\Repository\TradeRepository;
use JsonException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'trade:status', description: 'Show current trade, tracked orders and position snapshot.')]
final class TradeStatusCommand extends Command
{
    public function __construct(
        private readonly TradeRepository $tradeRepository,
        private readonly KrakenFuturesClientInterface $krakenClient,
    ) {
        parent::__construct();
    }

    /**
     * @throws JsonException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $trade = $this->tradeRepository->findActiveTrade();

        if ($trade === null) {
            $io->writeln('No active trade stored.');

            return Command::SUCCESS;
        }

        $position = $this->krakenClient->getOpenPositions($trade->getSymbol())[0] ?? null;
        $openOrders = $this->krakenClient->getOpenOrders($trade->getSymbol());

        $table = new Table($output);
        $table->setHeaders(['Field', 'Value']);
        $table->setRows([
            ['Trade ID', (string) ($trade->getId() ?? 0)],
            ['Symbol', $trade->getSymbol()],
            ['Side', $trade->getSide()->value],
            ['Status', $trade->getStatus()->value],
            ['Entry price', (string) $trade->getEntryPrice()],
            ['Size', (string) $trade->getSize()],
            ['Stop loss', (string) $trade->getStopLossPrice()],
            ['TP1', (string) $trade->getTp1Price()],
            ['TP2', (string) $trade->getTp2Price()],
            ['TP1 filled', $trade->isTp1Filled() ? 'yes' : 'no'],
            ['Break-even moved', $trade->isBreakEvenMoved() ? 'yes' : 'no'],
            ['PnL', $position !== null ? (string) $position->pnl : 'n/a'],
            ['Open position size', $position !== null ? (string) $position->size : '0'],
            ['Tracked Kraken order ids', json_encode($trade->getKrakenOrderIds(), JSON_THROW_ON_ERROR)],
            ['Open Kraken orders', (string) count($openOrders)],
        ]);
        $table->render();

        return Command::SUCCESS;
    }
}
