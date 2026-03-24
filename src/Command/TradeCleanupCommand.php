<?php

declare(strict_types=1);

namespace App\Command;

use App\Config\TradingRuntime;
use App\Service\TradeManagerService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'trade:cleanup', description: 'Cancel orphaned orders when no open position exists.')]
final class TradeCleanupCommand extends Command
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
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Actually cancel the orphaned orders.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $count = $this->tradeManager->cleanupOrphanedOrders(
            (string) $input->getOption('symbol'),
            (bool) $input->getOption('execute'),
        );

        $io->success(sprintf('%d orphaned orders processed.', $count));

        return Command::SUCCESS;
    }
}
