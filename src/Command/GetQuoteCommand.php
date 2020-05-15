<?php

namespace Devbanana\OptionCalculator\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
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
            ->addOption('refresh', 'r', InputOption::VALUE_NONE, 'Refresh quote data')
            ->addOption('interval', 'i', InputOption::VALUE_REQUIRED, 'Number of seconds between refresh', 10)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $symbol = $input->getArgument('symbol');

        $tradier = $this->createTradier();

        if ($input->getOption('refresh') === true) {
            $section = $output->section();

            while (true) {
                $quote = $tradier->getQuote($symbol, true);
                $this->renderQuoteTable($quote, $section);
                sleep($input->getOption('interval'));
                $section->clear();
            }
        } else {
            $quote = $tradier->getQuote($symbol, true);
            $this->renderQuoteTable($quote, $output);
        }

        return 0;
    }

    protected function renderQuoteTable(\stdClass $quote, OutputInterface $output): void
    {
        $headers = [
            new TableCell($quote->description, ['colspan' => 2]),
        ];

        $rows = [];

        $fmt = new \NumberFormatter('en_US', \NumberFormatter::CURRENCY);
        $changeFmt = new \NumberFormatter('en_US', \NumberFormatter::CURRENCY);
        $changeFmt->setTextAttribute(\NumberFormatter::POSITIVE_PREFIX, '+$');
        $changePercentFmt = new \NumberFormatter('en_US', \NumberFormatter::PERCENT);
        $changePercentFmt->setTextAttribute(\NumberFormatter::POSITIVE_PREFIX, '+');
        $changePercentFmt->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, 2);
        $intFmt = new \NumberFormatter('en_US', \NumberFormatter::DECIMAL);
        $percentFmt = new \NumberFormatter('en_US', \NumberFormatter::PERCENT);
        $percentFmt->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, 2);

        $rows[] = [new TableCell($fmt->formatCurrency($quote->last, 'USD'), ['colspan' => 2])];

        $change = $changeFmt->formatCurrency($quote->change, 'USD') . ' (' .
            $changePercentFmt->format($quote->change_percentage/100) . ')';
        if ($quote->change < 0) {
            $change = "<error>$change</error>";
        } else {
            $change = "<info>$change</info>";
        }
        $rows[] = [new TableCell($change, ['colspan' => 2])];

        $rows[] = new TableSeparator();
        $rows[] = ['<options=bold>Bid</>', '<options=bold>Ask</>'];
        $rows[] = [
            $fmt->formatCurrency($quote->bid, 'USD'),
            $fmt->formatCurrency($quote->ask, 'USD'),
        ];
        $rows[] = new TableSeparator();

        $rows[] = ['Open', $fmt->formatCurrency($quote->open, 'USD')];
        $rows[] = ['High', $fmt->formatCurrency($quote->high, 'USD')];
        $rows[] = ['Low', $fmt->formatCurrency($quote->low, 'USD')];
        $rows[] = ['Close', $fmt->formatCurrency($quote->close, 'USD')];
        $rows[] = ['Previous close', $fmt->formatCurrency($quote->prevclose, 'USD')];
        if ($quote->type === 'option') {
            $rows[] = ['Volume', $intFmt->format($quote->volume)];
            $rows[] = ['Open Interest', $intFmt->format($quote->open_interest)];
            $rows[] = ['Implied Volatility', $percentFmt->format($quote->greeks->smv_vol)];
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
            $rows[] = ['Volume', $intFmt->format($quote->volume)];
            $rows[] = ['Average Volume', $intFmt->format($quote->average_volume)];
            $rows[] = ['52 Week High', $fmt->formatCurrency($quote->week_52_high, 'USD')];
            $rows[] = ['52 Week Low', $fmt->formatCurrency($quote->week_52_low, 'USD')];
        }

        $table = new Table($output);
        $table
            ->setHeaders($headers)
            ->setRows($rows)
            ->setStyle('borderless')
            ->render()
        ;
    }
}
