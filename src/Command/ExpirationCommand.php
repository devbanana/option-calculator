<?php

namespace Devbanana\OptionCalculator\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;

class ExpirationCommand extends BaseCommand
{
    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'list:expirations';

    protected function configure()
    {
        $this
            ->setDescription('Lists options expirations')
            ->setHelp('This commands lists all expirations for a given symbol.')
            ->addArgument('symbol', InputArgument::REQUIRED, 'Symbol')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $symbol = $input->getArgument('symbol');

        $tradier = $this->createTradier();
        $expirations = $tradier->get('markets/options/expirations', [
            'symbol' => $symbol,
            'includeAllRoots' => 'true',
        ]);

        $output->writeln([
            "Expirations for $symbol",
            str_repeat('=', strlen($symbol) + 16),
            '',
        ]);

        $now = new \DateTime('today');

        foreach ($expirations->expirations->date as $date) {
            $date = new \DateTime($date);
            $dte = ($date->diff($now))->days;
            $output->writeln($date->format('M j, Y') . " ($dte DTE)");
        }

        return 0;
    }
}
