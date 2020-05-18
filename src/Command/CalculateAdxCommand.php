<?php

declare(strict_types = 1);

namespace Devbanana\OptionCalculator\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

class CalculateAdxCommand extends BaseCommand
{
    protected static $defaultName = 'calculate:adx';

    protected function configure()
    {
        $this
            ->setDescription('Calculates the average directional index')
            ->setHelp('Calculates the average directional index for the given time period. Also includes +DI and -DI.')
            ->addArgument('symbol', InputArgument::REQUIRED, 'Symbol to fetch data for')
            ->addOption('period', 'p', InputOption::VALUE_REQUIRED, 'Days in ADX calculation', 14)
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'How many days of RSI to fetch', 30)
            ->addOption('export', 'o', InputOption::VALUE_REQUIRED, 'Export as CSV')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $symbol = $input->getArgument('symbol');
        $days = $input->getOption('days');
        $period = $input->getOption('period');

        $start = new \DateTime('-1 year');
        $tradier = $this->createTradier();
        $history = $tradier->getHistoricalQuotes($symbol, 'daily', $start);

        $highs = array_column($history, 'high');
        $lows = array_column($history, 'low');
        $closes = array_column($history, 'close');

        $adx = trader_adx($highs, $lows, $closes, $period);
        $pdi = trader_plus_di($highs, $lows, $closes, $period);
        $mdi = trader_minus_di($highs, $lows, $closes, $period);

        $history = array_slice($history, -$days);
        $adx = array_slice($adx, -$days);
        $pdi = array_slice($pdi, -$days);
        $mdi = array_slice($mdi, -$days);

        foreach ($history as $i => $day) {
            $day->adx = $adx[$i];
            $day->plus_di = $pdi[$i];
            $day->minus_di = $mdi[$i];
        }

        $headers = ['Date', 'Close', 'ADX', '+DI', '-DI'];

        $rows = [];

        foreach ($history as $day) {
            $date = new \DateTime($day->date);
            $row = [];
            $row[] = $date->format('F j Y');
            $row[] = $this->formatCurrency($day->close);
            $row[] = $this->formatNumber($day->adx, 3);
            $row[] = $this->formatNumber($day->plus_di, 3);
            $row[] = $this->formatNumber($day->minus_di, 3);
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
