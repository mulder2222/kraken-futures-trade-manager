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

#[AsCommand(name: 'trade:close', description: 'Close the active trade. Live trades require --execute.')]
final class TradeCloseCommand extends Command
{
    public function __construct(private readonly TradeManagerService $tradeManager)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('execute', null, InputOption::VALUE_NONE, 'Actually close the active live trade on Kraken.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $trade = $this->tradeManager->closeActiveTrade((bool) $input->getOption('execute'));

        if ($trade === null) {
            $io->writeln('No active trade found.');

            return Command::SUCCESS;
        }

        $io->success(sprintf('Trade %d closed. Mode: %s', $trade->getId() ?? 0, $trade->isDryRun() ? 'dry-run' : 'live'));

        return Command::SUCCESS;
    }
}
