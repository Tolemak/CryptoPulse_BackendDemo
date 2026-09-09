<?php

namespace App\Command;

use App\Service\Price\PriceRefreshService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:poll-prices',
    description: 'Fetches current prices from every configured exchange, caches the aggregate, and evaluates alerts. Meant to run on a schedule (cron).',
)]
final class PollPricesCommand extends Command
{
    public function __construct(
        private readonly PriceRefreshService $refresh,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        foreach ($this->refresh->refreshAll() as $price) {
            $io->writeln(sprintf(
                '%s: %.2f (from %d exchange(s))',
                $price->pair->value,
                $price->median,
                count($price->breakdown),
            ));
        }

        return Command::SUCCESS;
    }
}
