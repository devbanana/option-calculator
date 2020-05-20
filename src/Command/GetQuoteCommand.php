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
use Devbanana\OptionCalculator\Exception\TradierException;

class GetQuoteCommand extends BaseCommand
{
    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'get:quote';

    protected function configure()
    {
        $dateExample = new \DateTime('third Friday of next month');
        $dateString = $dateExample->format('Y-m-d');
        $dateReadable = $dateExample->format('m/d/y');
        $dateOption = $dateExample->format('ymd');

        $this
            ->setDescription('Gets quotes for stocks or options')
            ->setHelp(
                <<<EOF
This command allows you to fetch quotes for stocks or options.

For a stock quote, simply pass the stock symbol.

For options quotes, pass the option symbol, including expiration and strike price.

For example, the SPY $dateReadable 300.0 call would be formatted like this:

SPY{$dateOption}C00300000

If you don't want to try to figure out the option symbol, you can also specify the underlying, followed by specifying the expiration, option type and strike as follows:

get:quote SPY --expiration=$dateString --call --strike=300

You can also specify a put as follows:

get:quote SPY --expiration=$dateString --put --strike=300
EOF
            )
            ->addArgument('symbol', InputArgument::REQUIRED, 'Stock or option symbol')
            ->addOption('expiration', null, InputOption::VALUE_REQUIRED, 'Option expiration')
            ->addOption('call', null, InputOption::VALUE_NONE, 'Limit to call options')
            ->addOption('put', null, InputOption::VALUE_NONE, 'Limit to put options')
            ->addOption('strike', null, InputOption::VALUE_REQUIRED, 'Option strike')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);

        $symbol = $input->getArgument('symbol');
        $expiration = $input->getOption('expiration');
        $call = $input->getOption('call');
        $put = $input->getOption('put');
        $strike = $input->getOption('strike');

        $tradier = $this->createTradier();

        $quote = $tradier->getQuote($symbol, true);

        // Should we look for an option?
        if (
            $quote->type !== 'option'
            && ($expiration || $call || $put || $strike)
        ) {
            if (!$expiration) {
                $io->error('Expiration is required in order to find an option.');
                return 1;
            }

            try {
                $expiration = new \DateTime($expiration);
                $chains = $tradier->getOptionChains($symbol, $expiration);
            } catch (TradierException $e) {
                $io->error("That is not a valid expiration for $symbol.");
                return 1;
            } catch (\Exception $e) {
                $io->error('Expiration must be a valid date.');
                return 1;
            }

            if (!$call && !$put) {
                $io->error('Please specify one of either --call or --put.');
                return 1;
            }

            if (!$strike) {
                $io->error('Strike is required in order to find an option.');
                return 1;
            } elseif (!is_numeric($strike)) {
                $io->error('Strike must be a number.');
                return 1;
            }

            $strike = floatval($strike);
            $strikes = array_column($chains, 'strike');
            if (!in_array($strike, $strikes)) {
                $io->error("That is not a valid strike for $symbol on " . $expiration->format('M j'));
                return 1;
            }

            // Find the chain
            foreach ($chains as $chain) {
                if ($chain->expiration_date !== $expiration->format('Y-m-d')) {
                    continue;
                }
                if ($call && $chain->option_type !== 'call') {
                    continue;
                }
                if ($put && $chain->option_type === 'put') {
                    continue;
                }
                if ($strike !== $chain->strike) {
                    continue;
                }
                $quote = $tradier->getQuote($chain->symbol, true);
            }
        }

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

        if ($quote->open) {
            $rows[] = ['Open', $this->formatCurrency($quote->open)];
        }
        if ($quote->high) {
            $rows[] = ['High', $this->formatCurrency($quote->high)];
        }
        if ($quote->low) {
            $rows[] = ['Low', $this->formatCurrency($quote->low)];
        }
        if ($quote->close) {
            $rows[] = ['Close', $this->formatCurrency($quote->close)];
        }
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
