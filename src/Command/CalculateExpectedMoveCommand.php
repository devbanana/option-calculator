<?php

declare(strict_types = 1);

namespace Devbanana\OptionCalculator\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Devbanana\OptionCalculator\Exception\TradierException;

class CalculateExpectedMoveCommand extends BaseCommand
{
    protected static $defaultName = 'calculate:expected-move';

    protected function configure()
    {
        $this
            ->setDescription('Calculates the expected move of a stock')
            ->setHelp(
                <<<EOF
Calculates the expected move of a stock.

If no expiration is provided, then the expected move is for one day. If an expiration is provided, then the expected move is by that expiration.

The expected move is 1 standard deviation of how much the stock should move within the given time period. That means it is 68% likely to remain in that range.
EOF
            )
            ->addArgument('symbol', InputArgument::REQUIRED, 'Symbol to fetch data for')
            ->addOption('expiration', null, InputOption::VALUE_REQUIRED, 'Option expiration')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);

        $symbol = $input->getArgument('symbol');
        $expiration = $input->getOption('expiration');

        if ($expiration) {
            try {
                $expiration = new \DateTime($expiration);
            } catch (\Exception $e) {
                $io->error("Invalid expiration provided for $symbol.");
                return 1;
            }
        }

        $tradier = $this->createTradier();

        try {
            $quote = $tradier->getQuote($symbol);
            $expectedMove = $tradier->getExpectedMove($symbol, $expiration);
            $expectedMoveAmount = $expectedMove * $quote->last;
        } catch (TradierException $e) {
            $io->error($e->getMessage());
            return 1;
        }

        $io->title("Expected Move for $quote->symbol");

        $dte = 1;
        if ($expiration) {
            $dte = $expiration->diff(new \DateTime('today'))->days;
        }

        $io->definitionList(
            "Expected Move in $dte Day" . ($dte !== 1 ? 's' : ''),
            ['Percent Move' => '±' . $this->formatPercent($expectedMove)],
            ['Dollar Move' => '±' . $this->formatCurrency($expectedMoveAmount)],
            ['Range Low' => $this->formatCurrency($quote->last - $expectedMoveAmount)],
            ['Range High' => $this->formatCurrency($quote->last + $expectedMoveAmount)],
        );

        return 0;
    }
}
