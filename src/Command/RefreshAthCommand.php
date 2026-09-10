<?php

namespace App\Command;

use App\Service\Price\AthRefreshService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:refresh-ath',
    description: 'Fetches each pair\'s all-time-high price from CoinGecko and caches it. Meant to run daily (cron) - an ATH does not move often enough to poll hourly.',
)]
final class RefreshAthCommand extends Command
{
    public function __construct(
        private readonly AthRefreshService $refresh,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        foreach ($this->refresh->refreshAll() as $ath) {
            $io->writeln(sprintf(
                '%s: ATH %.2f (%s)',
                $ath->pair->value,
                $ath->athPrice,
                $ath->athDate->format('Y-m-d'),
            ));
        }

        return Command::SUCCESS;
    }
}
