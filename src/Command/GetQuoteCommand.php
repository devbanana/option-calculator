<?php

namespace Devbanana\OptionCalculator\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Helper\TableSeparator;

class GetQuoteCommand extends BaseCommand
{
    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'get:quote';

    protected function configure()
    {
        $this
            ->setDescription('Gets quotes for stocks or options')
            ->setHelp(
                <<<EOF
This command allows you to fetch quotes for stocks or options.

For a stock quote, simply pass the stock symbol.

For options quotes, pass the option symbol, including expiration and strike price.

For example, the SPY 06/19/20 300.0 call would be formatted like this:

SPY200619C00300000

If you want to refresh the quotes every few seconds, add the --refresh argument. To specify how many seconds between refresh (it defaults to 10), specify --interval=<seconds>.
EOF
            )
            ->addArgument('symbol', InputArgument::REQUIRED, 'Stock or option symbol')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $symbol = $input->getArgument('symbol');

        $tradier = $this->createTradier();
        $io = new SymfonyStyle($input, $output);

        $quote = $tradier->getQuote($symbol, true);
        $this->renderQuoteTable($quote, $io);

        return 0;
    }

    protected function renderQuoteTable(\stdClass $quote, SymfonyStyle $io): void
    {
        $headers = [
            new TableCell($quote->description, ['colspan' => 2]),
        ];

        $rows = [];

        $rows[] = [new TableCell($this->formatCurrency($quote->last), ['colspan' => 2])];

        $change = $this->formatChange($quote->change, $quote->change_percentage);
        $rows[] = [new TableCell($change, ['colspan' => 2])];

        $rows[] = new TableSeparator();
        $rows[] = ['<info>Bid</info>', '<info>Ask</info>'];
        $rows[] = [
            $this->formatCurrency($quote->bid),
            $this->formatCurrency($quote->ask),
        ];
        $rows[] = new TableSeparator();

        $rows[] = ['Open', $this->formatCurrency($quote->open)];
        $rows[] = ['High', $this->formatCurrency($quote->high)];
        $rows[] = ['Low', $this->formatCurrency($quote->low)];
        $rows[] = ['Close', $this->formatCurrency($quote->close)];
        $rows[] = ['Previous close', $this->formatCurrency($quote->prevclose)];
        if ($quote->type === 'option') {
            $rows[] = ['Volume', $this->formatNumber($quote->volume, 0)];
            $rows[] = ['Open Interest', $this->formatNumber($quote->open_interest, 0)];
            $rows[] = ['Implied Volatility', $this->formatPercent($quote->greeks->smv_vol)];
            $rows[] = new TableSeparator();
            $rows[] = [new TableCell('<info>Option Greeks</info>', ['colspan' => 2])];
            $rows[] = new TableSeparator();
            $rows[] = ['Delta', $quote->greeks->delta];
            $rows[] = ['Gamma', $quote->greeks->gamma];
            $rows[] = ['Theta', $quote->greeks->theta];
            $rows[] = ['Vega', $quote->greeks->vega];
            $rows[] = ['Rho', $quote->greeks->rho];
            $rows[] = ['Phi', $quote->greeks->phi];
        } else {
            $rows[] = ['Volume', $this->formatNumber($quote->volume, 0)];
            $rows[] = ['Average Volume', $this->formatNumber($quote->average_volume, 0)];
            $rows[] = ['52 Week High', $this->formatCurrency($quote->week_52_high)];
            $rows[] = ['52 Week Low', $this->formatCurrency($quote->week_52_low)];
        }

        $io->table($headers, $rows);
    }
}
