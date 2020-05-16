<?php

namespace Devbanana\OptionCalculator\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableCell;
use Devbanana\OptionCalculator\Tradier;
use Devbanana\OptionCalculator\Exception\TradierException;

class TradeCreateCommand extends BaseCommand
{
    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'trade:create';

    protected Tradier $tradier;
    protected string $symbol;
    protected string $class;
    protected string $type;
    protected string $duration;
    protected ?float $price;
    protected ?float $stop;
    protected array $sides = [];
    protected array $quantities = [];
    protected ?array $optionSymbols;
    protected ?array $chains;

    protected function configure()
    {
        $this
            ->setDescription('Opens a new trade')
            ->setHelp(
                <<<EOF
Opens a new trade on Tradier.

To use this command, you must have the TRADIER_ACCOUNT_ID environment variable set.
EOF
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->tradier = $this->createTradier();

        $output->writeln([
            '<options=bold>New Trade</>',
            '=========',
            '',
        ]);

        $helper = $this->getHelper('question');
        $this->symbol = $helper->ask($input, $output, new Question('Symbol: '));

        $quote = $this->getQuote();

        $output->writeln([
            $quote->description,
            $this->formatCurrency($quote->last),
            $this->formatChange($quote->change, $quote->change_percentage),
            '',
        ]);

        $anotherLeg = new ConfirmationQuestion('Add another leg? ', false);
        do {
            $this->addLeg($input, $output);
        } while ($helper->ask($input, $output, $anotherLeg));

        $limitQuestion = new Question('Limit (enter "r" to refresh): ');
        $limitQuestion->setValidator(function ($limit) {
            if (strtolower($limit) === 'r') {
                return strtolower($limit);
            } elseif (!is_numeric($limit)) {
                throw new \RuntimeException('Please enter a numeric limit.');
            } elseif ($limit == 0) {
                throw new \RuntimeException('Limit price must not be 0.');
            } elseif ($limit < 0) {
                throw new \RuntimeException('Limit price must be a positive number.');
            }
            return floatval($limit);
        });

        if ($this->class === 'equity' || $this->class === 'option') {
            $orderTypeQuestion = new ChoiceQuestion('Order type:', [
                1 => 'market',
                'limit',
                'stop limit',
                'stop',
            ]);
            $this->type = str_replace(' ', '_', $helper->ask($input, $output, $orderTypeQuestion));

            if ($this->type === 'limit' || $this->type === 'stop_limit') {
                while (true) {
                    if (isset($this->chains)) {
                        $quote = $this->tradier->getQuote($this->chains[0]->symbol);
                    } else {
                        $quote = $this->getQuote();
                    }

                    $bid = $quote->bid;
                    $ask = $quote->ask;

                    $output->writeln($this->showBidAsk($bid, $ask, $this->class === 'option'));

                    $limit = $helper->ask($input, $output, $limitQuestion);
                    if ($limit !== 'r') {
                        break;
                    }
                }
                $this->price = $limit;
            }

            if ($this->type === 'stop' || $this->type === 'stop_limit') {
                $stopQuestion = new Question('Stop price: ');
                $stopQuestion->setValidator(function ($stop) {
                    if (!is_numeric($stop)) {
                        throw new \RuntimeException('Please enter a valid stop price.');
                    } elseif ($stop == 0) {
                        throw new \RuntimeException('Stop price must not be 0.');
                    } elseif ($stop < 0) {
                        throw new \RuntimeException('Stop price must be a positive number.');
                    }
                    return floatval($stop);
                });
                $this->stop = $helper->ask($input, $output, $stopQuestion);
            }
        } else {
            $orderTypeQuestion = new ChoiceQuestion('Order type:', [
                1 => 'market',
                'debit',
                'credit',
                'even',
            ]);
            $this->type = $helper->ask($input, $output, $orderTypeQuestion);

            if ($this->type === 'debit' || $this->type === 'credit') {
                while (true) {
                    $bid = 0;
                    $mid = 0;
                    $ask = 0;

                    foreach ($this->chains as $i => $chain) {
                        $quote = $this->tradier->getQuote($chain->symbol);
                        if (strpos($this->sides[$i], 'buy') !== false) {
                            $bid += $quote->bid;
                            $ask += $quote->ask;
                        } else {
                            $bid -= $quote->ask;
                            $ask -= $quote->bid;
                        }
                    }

                    if ($bid < 0) {
                        [$bid, $ask] = [$ask, $bid];
                    }

                    $output->writeln($this->showBidAsk($bid, $ask, true, true));

                    $limit = $helper->ask($input, $output, $limitQuestion);
                    if ($limit !== 'r') {
                        break;
                    }
                }
                $this->price = $limit;
            }
        }

        $durationQuestion = new ChoiceQuestion('Duration:', [
            1 => 'day',
            'GTC',
            'pre-market',
            'post-market',
        ], 1);
        $duration = $helper->ask($input, $output, $durationQuestion);

        // Change pre-market to pre, and post-market to post
        $this->duration = str_replace('-market', '', $duration);

        $params = [];
        $params['symbol'] = $this->symbol;
        $params['class'] = $this->class;
        $params['type'] = $this->type;
        $params['duration'] = $this->duration;
        if (isset($this->price)) {
            $params['price'] = $this->price;
        }
        if (isset($this->stop)) {
            $params['stop'] = $this->stop;
        }
        $params['side'] = $this->arrayOrString($this->sides);
        $params['quantity'] = $this->arrayOrString($this->quantities);
        if (isset($this->optionSymbols)) {
            $params['option_symbol'] = $this->arrayOrString($this->optionSymbols);
        }

        try {
            $order = $this->tradier->previewOrder($params);
        } catch (TradierException $e) {
            $error = $e->getMessage();
            $output->writeln("<error>$error</error>");
            return 1;
        }

        $table = new Table($output);
        $table->addRow([
            'Commission',
            $this->formatCurrency($order->commission),
        ]);

        if ($order->order_cost < 0) {
            $table->addRow([
                'Order Proceeds',
                $this->formatCurrency(abs($order->order_cost)),
            ]);
        } else {
            $table->addRow([
                'Order Cost',
                $this->formatCurrency($order->order_cost),
            ]);
        }

        $table->addRow([
            'Est. Total Cost',
            $this->formatCurrency($order->cost),
        ]);
        $table->setStyle('compact');
        $table->render();

        $confirm = $helper->ask($input, $output, new ConfirmationQuestion('Send this order? ', false));
        if ($confirm === true) {
            $order = $this->tradier->createOrder($params);
            $output->writeln("Order created: order ID #" . $order->id);
        } else {
            $output->writeln('Order was not submitted.');
        }

        return 0;
    }

    protected function addLeg($input, $output)
    {
        $class = $this->getClass($input, $output);
        $side = $this->getSide($input, $output, $class);
        $quantity = $this->getQuantity($input, $output, $class);

        if ($class === 'option') {
            $expiration = $this->getExpiration($input, $output);
            $optionType = $this->getOptionType($input, $output);
            $chain = $this->getChain($input, $output, $expiration, $optionType);
            $optionSymbol = $chain->symbol;
        }

        // Get index.
        $index = count($this->sides);
        $this->sides[$index] = $side;
        $this->quantities[$index] = $quantity;
        if ($class === 'option') {
            $this->optionSymbols[$index] = $optionSymbol;
            $this->chains[$index] = $chain;
        }
    }

    protected function showBidAsk(float $bid, float $ask, bool $includeMid = false, bool $includeDebitCredit = false): array
    {
        if ($includeMid === true) {
            $mid = $this->getMid($bid, $ask);
        }

        $messages = [];
        $bidMsg = '';
        $midMsg = '';
        $askMsg = '';
        if ($includeDebitCredit === true) {
            $bidMsg = $bid < 0 ? 'Credit ' : 'Debit ';
            if (isset($mid)) {
                $midMsg = $mid < 0 ? 'Credit ' : 'Debit ';
            }
            $askMsg = $ask < 0 ? 'Credit ' : 'Debit ';
        }

        $bidMsg .= 'Bid: ' . $this->formatCurrency(abs($bid));
        if (isset($mid)) {
            $midMsg .= 'Mid: ' . $this->formatCurrency(abs($mid));
        }
        $askMsg .= 'Ask: ' . $this->formatCurrency(abs($ask));

        $messages[] = $bidMsg;
        if (isset($mid)) {
            $messages[] = $midMsg;
        }
        $messages[] = $askMsg;
        $messages[] = '';

        return $messages;
    }

    protected function getQuote(): \stdClass
    {
        return $this->tradier->getQuote($this->symbol);
    }

    protected function getMid(float $bid, float $ask): float
    {
        return round(($bid + $ask) / 2, 2);
    }

    protected function arrayOrString(array $value)
    {
        return count($value) > 1 ? $value : $value[0];
    }

    protected function getAllowedSides(string $class): array
    {
        if ($class === 'equity') {
            return [
                1 => 'buy',
                'buy to cover',
                'sell',
                'sell short',
            ];
        } else {
            return [
                1 => 'buy to open',
                'buy to close',
                'sell to open',
                'sell to close',
            ];
        }
    }

    protected function getClass(InputInterface $input, OutputInterface $output): string
    {
        $classQuestion = new ChoiceQuestion(
            'Security type:',
            [
                1 => 'equity',
                'option',
            ]
        );
        $class = $this->getHelper('question')->ask($input, $output, $classQuestion);

        if (!isset($this->class) || $this->class !== 'combo') {
            if (!isset($this->class)) {
                $this->class = $class;
            } elseif ($this->class === 'equity') {
                $this->class = 'combo';
            } elseif ($this->class === 'option' && $class === 'equity') {
                $this->class = 'combo';
            } elseif ($this->class === 'option' && $class === 'option') {
                $this->class = 'multileg';
            } elseif ($this->class === 'multileg' && $class === 'equity') {
                $this->class = 'combo';
            }
        }

        return $class;
    }

    protected function getSide(InputInterface $input, OutputInterface $output, string $class): string
    {
        $sideQuestion = new ChoiceQuestion('Side:', $this->getAllowedSides($class));
        return str_replace(' ', '_', $this->getHelper('question')->ask($input, $output, $sideQuestion));
    }

    protected function getQuantity(InputInterface $input, OutputInterface $output, string $class): int
    {
        $quantityQuestion = new Question($class === 'option' ? 'Contracts: ' : 'Shares: ');
        $quantityQuestion->setValidator(function ($quantity) {
            if (!is_numeric($quantity) || intval($quantity) <= 0) {
                throw new \RuntimeException('Please enter a valid numeric quantity.');
            }

            return intval($quantity);
        });
        return $this->getHelper('question')->ask($input, $output, $quantityQuestion);
    }

    protected function getExpiration(InputInterface $input, OutputInterface $output): \DateTime
    {
        $helper = $this->getHelper('question');
        $expirations = $this->tradier->getOptionExpirations($this->symbol, ['includeAllRoots' => true]);

        $expirationQuestion = new Question('Expiration (enter "list" to list all expirations): ');
        $expirationQuestion->setValidator(function ($expiration) use ($expirations) {
            if ($expiration === 'list') {
                return $expiration;
            }

            try {
                $expiration = new \DateTime($expiration);
            } catch (\Exception $e) {
                throw new \RuntimeException('Please enter a valid date.');
            }

            $found = false;
            foreach ($expirations as $exp) {
                if ($expiration == $exp) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                throw new \RuntimeException('That expiration date does not exist.');
            }

            return $expiration;
        });
        $expiration = $helper->ask($input, $output, $expirationQuestion);

        if ($expiration === 'list') {
            $expirationChoices = [];
            foreach ($expirations as $i => $exp) {
                $expirationChoices[$i+1] = $exp->format('M j, Y');
            }

            $expirationQuestion = new ChoiceQuestion('Expiration:', $expirationChoices);
            $expiration = new \DateTime($helper->ask($input, $output, $expirationQuestion));
        }

        return $expiration;
    }

    protected function getOptionType(InputInterface $input, OutputInterface $output): string
    {
        $optionTypeQuestion = new ChoiceQuestion('Option type:', [1 => 'call', 'put']);
        return $this->getHelper('question')->ask($input, $output, $optionTypeQuestion);
    }

    protected function getChain(InputInterface $input, OutputInterface $output, \DateTime $expiration, string $optionType): \stdClass
    {
        $helper = $this->getHelper('question');
        $strikes = $this->tradier->getOptionStrikes($this->symbol, $expiration);
        $chains = $this->tradier->getOptionChains($this->symbol, $expiration, true);

        $methodQuestion = new ChoiceQuestion('How would you like to enter the strike?', [
            1 => 'manually',
            'select from list',
            'by delta',
        ]);

        // Repeat until a strike is chosen.
        while (true) {
            $method = $helper->ask($input, $output, $methodQuestion);

            if ($method === 'manually') {
                $manual = new Question('Strike (enter "<" to choose another method): ');
                $manual->setValidator(function ($strike) use ($strikes) {
                    if ($strike === '<') {
                        return $strike;
                    } elseif (!is_numeric($strike)) {
                        throw new \RuntimeException('Please enter a valid numeric strike.');
                    } elseif (!in_array((float)$strike, $strikes)) {
                        throw new \RuntimeException('That is not a valid strike.');
                    }
                    return floatval($strike);
                });

                $strike = $helper->ask($input, $output, $manual);
                if ($manual === '<') {
                    continue;
                }
            } elseif ($method === 'select from list') {
                $numStrikesQuestion = new ChoiceQuestion('Number of strikes to view:', [
                    1 => 6,
                    8,
                    10,
                    12,
                    14,
                    16,
                    18,
                    20,
                    'all',
                ]);
                $numStrikes = $helper->ask($input, $output, $numStrikesQuestion);

                if ($numStrikes === 'all') {
                    $strikeSelection = $strikes;
                } else {
                    // Divide in half.
                    $numStrikes /= 2;
                    $quote = $this->getQuote();

                    $lower = array_slice(array_filter($strikes, fn ($strike) => $strike < $quote->last), -$numStrikes);
                    $higher = array_slice(array_filter($strikes, fn ($strike) => $strike >= $quote->last), 0, $numStrikes);

                    $strikeSelection = [...$lower, ...$higher];
                }

                $strikeSelection += ['go back'];
                $strikeSelection = array_combine(range(1, count($strikeSelection)), array_values($strikeSelection));
                $strikeQuestion = new ChoiceQuestion('Strike:', $strikeSelection);
                $strike = $helper->ask($input, $output, $strikeQuestion);
                if ($strike === 'go back') {
                    continue;
                }
                $strike = floatval($strike);
            } elseif ($method === 'by delta') {
                $deltaQuestion = new Question('Delta (enter "<" to go back)');
                $deltaQuestion->setValidator(function ($delta) use ($optionType) {
                    if ($delta === '<') {
                        return $delta;
                    } elseif (!is_numeric($delta)) {
                        throw new \RuntimeException('Please enter a valid delta.');
                    }
                    $delta = floatval($delta);
                    if ($optionType === 'put' && $delta > 0) {
                        $delta *= -1;
                    }
                    return $delta;
                });
                $delta = $helper->ask($input, $output, $deltaQuestion);
                if ($delta === '<') {
                    continue;
                }

                $possibleChains = [];
                foreach ($chains as $chain) {
                    if ($chain->option_type !== $optionType) {
                        continue;
                    }
                    // Add 20% margin to not be too restrictive.
                    $marginDelta = $delta * 1.2;
                    if ($optionType === 'call' && $chain->greeks->delta <= $marginDelta) {
                        $possibleChains[] = $chain;
                    } elseif ($optionType === 'put' && $chain->greeks->delta >= $marginDelta) {
                        $possibleChains[] = $chain;
                    }
                }

                $selectedChain = null;
                $diff = null;
                foreach ($possibleChains as $possibleChain) {
                    $chainDiff = abs($possibleChain->greeks->delta - $delta);
                    if ($diff === null) {
                        $diff = $chainDiff;
                        $selectedChain = $possibleChain;
                    } elseif ($chainDiff < $diff) {
                        $diff = $chainDiff;
                        $selectedChain = $possibleChain;
                    }
                }

                $strike = $selectedChain->strike;
            }

            // Validate strike selection.
            if (!isset($selectedChain)) {
                foreach ($chains as $chain) {
                    if ($optionType === $chain->option_type && $strike === $chain->strike) {
                        $selectedChain = $chain;
                        break;
                    }
                }
            }

            $mid = $this->getMid($selectedChain->bid, $selectedChain->ask);

            $table = new Table($output);
            $table->setHeaders([new TableCell($selectedChain->description, ['colspan' => 3])]);
            $table->setStyle('borderless');
            $table->addRow(['Bid', 'Mid', 'Ask']);
            $table->addRow([
                $this->formatCurrency($selectedChain->bid),
                $this->formatCurrency($mid),
                $this->formatCurrency($selectedChain->ask),
            ]);
            $table->addRow([
                'Volume',
                new TableCell($this->formatNumber($selectedChain->volume, 0), ['colspan' => 2]),
            ]);
            $table->addRow([
                'Open Interest',
                new TableCell($this->formatNumber($selectedChain->open_interest, 0), ['colspan' => 2]),
            ]);
            $table->addRow([
                'Delta',
                new TableCell($selectedChain->greeks->delta, ['colspan' => 2]),
            ]);
            $table->render();

            $output->writeln('');

            $confirm = $helper->ask($input, $output, new ConfirmationQuestion('Is this OK? ', false));
            if ($confirm === true) {
                break;
            }
        }

        return $selectedChain;
    }
}
