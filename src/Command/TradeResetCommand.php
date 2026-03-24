<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\TradeManagerService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'trade:reset', description: 'Reset local active trade state without sending exchange actions.')]
final class TradeResetCommand extends Command
{
    public function __construct(private readonly TradeManagerService $tradeManager)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $trade = $this->tradeManager->resetActiveTrade();

        if ($trade === null) {
            $io->writeln('No active trade found.');

            return Command::SUCCESS;
        }

        $io->success(sprintf('Trade %d reset locally.', $trade->getId() ?? 0));

        return Command::SUCCESS;
    }
}
