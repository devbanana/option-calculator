<?php

declare(strict_types = 1);

namespace Devbanana\OptionCalculator\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

class CalculateRsiCommand extends BaseCommand
{
    protected static $defaultName = 'calculate:rsi';

    protected function configure()
    {
        $this
            ->setDescription('Calculates the relative strength index')
            ->setHelp('Calculates the relative strength index for the given range')
            ->addArgument('symbol', InputArgument::REQUIRED, 'Symbol to fetch data for')
            ->addOption('period', 'p', InputOption::VALUE_REQUIRED, 'Days in RSI calculation', 14)
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'How many days of RSI to fetch', 30)
            ->addOption('export', 'o', InputOption::VALUE_REQUIRED, 'Export as CSV')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $symbol = $input->getArgument('symbol');
        $days = $input->getOption('days');
        $period = intval($input->getOption('period'));

        $start = new \DateTime('-1 year');
        $tradier = $this->createTradier();
        $history = $tradier->getHistoricalQuotes($symbol, 'daily', $start);
        $closes = array_column($history, 'close');
        $rsi = trader_rsi($closes, $period);

        $history = array_slice($history, -$days);
        $rsi = array_slice($rsi, -$days);
        foreach ($history as $i => $day) {
            $day->rsi = $rsi[$i];
        }

        $headers = ['Date', 'Close', 'RSI'];

        $rows = [];

        foreach ($history as $day) {
            $date = new \DateTime($day->date);
            $row = [];
            $row[] = $date->format('F j Y');
            $row[] = $this->formatCurrency($day->close);
            $row[] = $this->formatNumber($day->rsi, 3);
            $rows[] = $row;
        }

        $io = new SymfonyStyle($input, $output);
        $io->table($headers, $rows);

        if ($input->getOption('export')) {
            $this->export($input->getOption('export'), $headers, $rows);
        }

        return 0;
    }
}
