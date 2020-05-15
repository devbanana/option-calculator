<?php

declare(strict_types = 1);

namespace Devbanana\OptionCalculator\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableCell;
use Devbanana\OptionCalculator\ChainFilterer;

class ChainCommand extends BaseCommand
{
    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'list:chains';

    protected function configure()
    {
        $this
            ->setDescription('Lists options chains')
            ->setHelp('This command lists the option chain for a given symbol and expiration.')
            ->addArgument('symbol', InputArgument::REQUIRED, 'Underlying symbol')
            ->addArgument('expiration', InputArgument::REQUIRED, 'Option expiration in YYYY-mm-dd format. If not provided you will be given a list of expirations to choose from.')
            ->addOption('strikes', null, InputOption::VALUE_REQUIRED, 'Number of strikes above and below current price', 5)
        ;
    }

    protected function interact(InputInterface $input, OutputInterface $output)
    {
            $helper = $this->getHelper('question');

        if (!$input->getArgument('symbol')) {
            $question = new Question('Symbol: ');
            $symbol = $helper->ask($input, $output, $question);
            $input->setArgument('symbol', $symbol);
        }
        if (!$input->getArgument('expiration')) {
            $tradier = $this->createTradier();
            $expirations = $tradier->getOptionExpirations($input->getArgument('symbol'));
            $choices = [];
            foreach ($expirations as $date) {
                $choices[] = $date->format('M j, Y');
            }

            $choice = new ChoiceQuestion(
                'Please select an expiration:',
                $choices,
                0
            );
            $expiration = $helper->ask($input, $output, $choice);
            $input->setArgument('expiration', $expiration);
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $symbol = $input->getArgument('symbol');
        $expiration = new \DateTime($input->getArgument('expiration'));
        $strikes = intval($input->getOption('strikes'));

        $tradier = $this->createTradier();

        $quote = $tradier->getQuote($symbol);
        $chains = $tradier->getOptionchains($symbol, $expiration, true);

        $filterer = new ChainFilterer($chains);
        $chains = $filterer
            ->setStrikes($strikes)
            ->setPrice($quote->last)
            ->getChains()
        ;

        $fmt = new \NumberFormatter('en_US', \NumberFormatter::CURRENCY);
        $nmt = new \NumberFormatter('en_US', \NumberFormatter::DECIMAL);
        $nmt->setAttribute(\NumberFormatter::FRACTION_DIGITS, 1);
        $numFmt = new \NumberFormatter('en_US', \NumberFormatter::DECIMAL);

        $rows = [];
        foreach ($chains as $chain) {
            $call = [
                $fmt->formatCurrency($chain['call']->bid, 'USD'),
                $fmt->formatCurrency($chain['call']->ask, 'USD'),
                $numFmt->format($chain['call']->volume),
                $numFmt->format($chain['call']->open_interest),
            ];
            $put = [
                $fmt->formatCurrency($chain['put']->bid, 'USD'),
                $fmt->formatCurrency($chain['put']->ask, 'USD'),
                $numFmt->format($chain['put']->volume),
                $numFmt->format($chain['put']->open_interest),
            ];
            if ($chain['call']->strike <= $quote->last) {
                $call = array_map(fn ($value) => "<comment>$value</comment>", $call);
            }
            if ($chain['put']->strike >= $quote->last) {
                $put = array_map(fn ($value) => "<comment>$value</comment>", $put);
            }
            $rows[] = [...$call,                 $nmt->format($chain['call']->strike), ...$put];
        }

        $table = new Table($output);
        $table
            ->setHeaders([
                [
                    new TableCell('Call', ['colspan' => 4]),
                    '',
                    new TableCell('Put', ['colspan' => 4]),
                ],
                ['Bid', 'Ask', 'Vol', 'Op Int', 'Strike', 'Bid', 'Ask', 'Vol', 'Op Int'],
            ])
            ->setRows($rows)
            ->render();

        return 0;
    }
}
