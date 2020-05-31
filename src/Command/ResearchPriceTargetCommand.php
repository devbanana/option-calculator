<?php

declare(strict_types = 1);

namespace Devbanana\OptionCalculator\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Input\InputArgument;
use Devbanana\OptionCalculator\Exception\IexPaymentRequiredException;

class ResearchPriceTargetCommand extends BaseCommand
{
    protected static $defaultName = 'research:price-target';

    protected function configure()
    {
        $this
            ->setDescription('Gets analysts price targets for the given symbol')
            ->setHelp(
                <<<EOF
This command fetches analyst price targets for a given stock.
EOF
            )
            ->addArgument('symbol', InputArgument::REQUIRED, 'Symbol to fetch data for')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);

        $symbol = $input->getArgument('symbol');

        $iex = $this->createIex();

        try {
            $target = $iex->send("stock/$symbol/price-target");
        } catch (IexPaymentRequiredException $e) {
            $io->error('This is a premium feature of IEX.');
            return 1;
        }

        $io->title("Price Targets for $symbol");
        $io->definitionList(
            ['Target Average' => $this->formatCurrency($target->priceTargetAverage)],
            ['Target Low' => $this->formatCurrency($target->priceTargetLow)],
            ['Target High' => $this->formatCurrency($target->priceTargetHigh)],
            ['Number of Analysts' => $this->formatNumber($target->numberOfAnalysts, 0)],
            ['Last Updated' => (new \DateTime($target->updatedDate))->format('F j, Y')]
        );

        return 0;
    }
}
