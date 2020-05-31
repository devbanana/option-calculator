<?php

namespace Devbanana\OptionCalculator\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Input\InputArgument;

class TradeStatusCommand extends BaseCommand
{
    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'trade:status';

    protected function configure()
    {
        $this
            ->setDescription('Gets the status on an order')
            ->setHelp(
                <<<EOF
Gets the status of the provided order.
EOF
            )
            ->addArgument('order_id', InputArgument::REQUIRED, 'Order ID')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);

        $orderId = $input->getArgument('order_id');

        $tradier = $this->createTradier();
        $order = $tradier->getOrder($orderId);
        var_dump($order);

        if ($order->class === 'multileg' || $order->class === 'combo') {
            if ($order->type === 'debit' || $order->type === 'credit') {
                $title = sprintf('%s %s %s', ucfirst($order->type), $order->symbol, ucfirst($order->strategy));
            } else {
                $title = sprintf('%s %s', $order->symbol, ucfirst($order->strategy));
            }
        } elseif ($order->class === 'option') {
        }

        $io->title($title);

        return 0;
    }
}
