<?php

namespace Devbanana\OptionCalculator\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Style\SymfonyStyle;

class ListExpirationsCommand extends BaseCommand
{
    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'list:expirations';

    protected function configure()
    {
        $this
            ->setDescription('Lists options expirations')
            ->setHelp('This command lists all expirations for a given symbol.')
            ->addArgument('symbol', InputArgument::REQUIRED, 'Symbol')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);
        $symbol = strtoupper($input->getArgument('symbol'));

        $tradier = $this->createTradier();
        $expirations = $tradier->getOptionExpirations($symbol, true);

        $io->title("Expirations for $symbol");

        $now = new \DateTime('today');
        $expirationList = [];

        foreach ($expirations as $date) {
            $dte = ($date->diff($now))->days;
            $expirationList[] = $date->format('M j, Y') . " ($dte DTE)";
        }

        $io->listing($expirationList);

        return 0;
    }
}
