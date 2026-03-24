<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\TradeManagerService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'trade:monitor', description: 'Sync fills, open orders and open position for the current trade.')]
final class TradeMonitorCommand extends Command
{
    public function __construct(private readonly TradeManagerService $tradeManager)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('execute', null, InputOption::VALUE_NONE, 'Apply state-changing order management on Kraken.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $trade = $this->tradeManager->syncTradeState((bool) $input->getOption('execute'));

        if ($trade === null) {
            $io->writeln('No active trade found.');

            return Command::SUCCESS;
        }

        $io->success(sprintf('Trade %d synced. Status: %s', $trade->getId() ?? 0, $trade->getStatus()->value));

        return Command::SUCCESS;
    }
}
