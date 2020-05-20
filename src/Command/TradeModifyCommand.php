<?php

namespace Devbanana\OptionCalculator\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

class TradeModifyCommand extends BaseCommand
{
    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'trade:modify';

    protected function configure()
    {
        $this
            ->setDescription('Modifies an existing order')
            ->setHelp(
                <<<EOF
Allows you to modify an existing order. Just pass the order ID and what you want to be modified.
EOF
            )
            ->addArgument('order_id', InputArgument::REQUIRED, 'Order ID')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Change the order type (limit, stop, stop_limit, debit, credit)')
            ->addOption('duration', null, InputOption::VALUE_REQUIRED, 'Time order will remain active (day, gtc, pre, post)')
            ->addOption('price', null, InputOption::VALUE_REQUIRED, 'Limit price of the order')
            ->addOption('stop', null, InputOption::VALUE_REQUIRED, 'Stop price of the order')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $orderId = $input->getArgument('order_id');
        $params = [];

        $io = new SymfonyStyle($input, $output);

        $validTypes = ['limit', 'stop', 'stop_limit', 'debit', 'credit'];
        if ($type = $input->getOption('type')) {
            if (!in_array($type, $validTypes)) {
                $io->error("Invalid type specified: $type is not a valid type.");
                return 1;
            }
            $params['type'] = $type;
        }

        $validDurations = ['day', 'gtc', 'pre', 'post'];
        if ($duration = $input->getOption('duration')) {
            if (!in_array($duration, $validDurations)) {
                $io->error("Invalid duration specified: $duration is not a valid duration.");
                return 1;
            }
            $params['duration'] = $duration;
        }

        if ($price = $input->getOption('price')) {
            if (!is_numeric($price)) {
                $io->error('Limit price must be a valid number.');
                return 1;
            } elseif ($price <= 0) {
                $io->error('Limit price must be a positive number.');
                return 1;
            }
            $params['price'] = floatval($price);
        }

        if ($stop = $input->getOption('stop')) {
            if (!is_numeric($stop)) {
                $io->error('Stop price must be a valid number.');
                return 1;
            } elseif ($stop <= 0) {
                $io->error('Stop price must be a positive number.');
                return 1;
            }
            $params['stop'] = floatval($stop);
        }

        if (empty($params)) {
            $io->error('You must specify at least one option to modify the order.');
            return 1;
        }

        $tradier = $this->createTradier();

        $tradier->modifyOrder($orderId, $params);
        $io->success('Order modified.');

        return 0;
    }
}
