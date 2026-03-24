<?php

declare(strict_types=1);

namespace App\Command;

use App\Config\TradingRuntime;
use App\Enum\TradeSide;
use App\Service\TradeManagerService;
use App\ValueObject\OpenTradeRequest;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'trade:open', description: 'Open a new managed Kraken Futures trade.')]
final class TradeOpenCommand extends Command
{
    public function __construct(
        private readonly TradeManagerService $tradeManager,
        private readonly TradingRuntime $runtime,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('symbol', null, InputOption::VALUE_REQUIRED, 'Kraken Futures symbol', $this->runtime->defaultSymbol())
            ->addOption('side', null, InputOption::VALUE_REQUIRED, 'Trade side: long or short')
            ->addOption('size', null, InputOption::VALUE_REQUIRED, 'Position size')
            ->addOption('entry-type', null, InputOption::VALUE_REQUIRED, 'Entry type: market|limit', 'market')
            ->addOption('entry-price', null, InputOption::VALUE_OPTIONAL, 'Entry price for limit orders or dry-run market orders')
            ->addOption('sl', null, InputOption::VALUE_REQUIRED, 'Stop loss price')
            ->addOption('tp1', null, InputOption::VALUE_REQUIRED, 'TP1 price')
            ->addOption('tp2', null, InputOption::VALUE_REQUIRED, 'TP2 price')
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Actually place the orders on Kraken.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $trade = $this->tradeManager->openTrade(
            new OpenTradeRequest(
                (string) $input->getOption('symbol'),
                TradeSide::from((string) $input->getOption('side')),
                (float) $input->getOption('size'),
                (string) $input->getOption('entry-type'),
                $input->getOption('entry-price') !== null ? (float) $input->getOption('entry-price') : null,
                (float) $input->getOption('sl'),
                (float) $input->getOption('tp1'),
                (float) $input->getOption('tp2'),
            ),
            (bool) $input->getOption('execute'),
        );

        $io->success(sprintf('Trade %d opened for %s in %s mode.', $trade->getId() ?? 0, $trade->getSymbol(), $input->getOption('execute') ? 'live' : 'dry-run'));

        return Command::SUCCESS;
    }
}
