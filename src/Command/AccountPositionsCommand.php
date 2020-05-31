<?php

namespace Devbanana\OptionCalculator\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class AccountPositionsCommand extends BaseCommand
{
    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'account:positions';

    protected function configure()
    {
        $this
            ->setDescription('Lists positions in your account')
            ->setHelp(
                <<<EOF
Lists all positions in your Tradier account.
EOF
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);

        $tradier = $this->createTradier();

        $positions = $tradier->getPositions();

        $symbols = [];

        foreach ($positions as $p) {
            $quote = $tradier->getQuote($p->symbol);
            $position = new \stdClass;
            $position->cost_basis = $p->cost_basis;
            $position->quantity = $p->quantity;
            if ($quote->type === 'option') {
                $position->type = 'option';
                $position->symbol = $quote->underlying;
                $position->value = ($quote->bid + $quote->ask) / 2 * 100 * $p->quantity;
            } else {
                $position->type = 'equity';
                $position->symbol = $quote->symbol;
                $position->value = $quote->last * $p->quantity;
            }
            $symbols[$position->symbol . '-' . $position->type][] = $position;
        }

        $headers = ['Symbol', 'Cost Basis', 'Quantity', 'Value', 'Gain/Loss'];
        $rows = [];
        foreach ($symbols as $position) {
            $row = [];
            $row[] = $position[0]->symbol;
            $cost = array_sum(array_column($position, 'cost_basis'));
            $quantity = min(array_column($position, 'quantity'));
            $value = array_sum(array_column($position, 'value'));

            $row[] = $this->formatCurrency($cost);
            $row[] = $this->formatNumber($quantity, 0);
            $row[] = $this->formatCurrency($value);

            if ($cost != 0) {
                $change = $value - $cost;
                $changePercent = $change / abs($cost) * 100;
                $row[] = $this->formatChange($change, $changePercent);
            } else {
                $row[] = '';
            }

            $rows[] = $row;
        }

        $balances = $tradier->getBalances();

        $io->title('Account Positions');
        $io->text('Account balance: ' . $this->formatCurrency($balances->total_equity));
        $io->table($headers, $rows);

        return 0;
    }
}
