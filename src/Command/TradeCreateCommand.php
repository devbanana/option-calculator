<?php

namespace Devbanana\OptionCalculator\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Style\SymfonyStyle;
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
        $io = new SymfonyStyle($input, $output);

        $io->title('New Trade');

        $balances = $this->tradier->getBalances();

        $io->definitionList(
            ['Stock Buying Power' => $this->formatCurrency($balances->margin->stock_buying_power)],
            ['Option Buying Power' => $this->formatCurrency($balances->margin->option_buying_power)]
        );

        $symbolQuestion = new Question('Symbol');
        $symbolQuestion->setAutocompleterCallback([$this, 'lookup']);

        $this->symbol = $io->askQuestion($symbolQuestion);

        $quote = $this->getQuote();

        $io->text([
            $quote->description,
            $this->formatCurrency($quote->last),
            $this->formatChange($quote->change, $quote->change_percentage),
        ]);

        do {
            $this->addLeg($io);
        } while ($io->confirm('Add another leg?', false));

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
            $orderTypeChoices = [
                1 => 'market',
                'limit',
                'stop limit',
                'stop',
            ];
            $this->type = str_replace(' ', '_', $io->choice('Order type', $orderTypeChoices));

            if ($this->type === 'limit' || $this->type === 'stop_limit') {
                while (true) {
                    if (isset($this->chains)) {
                        $quote = $this->tradier->getQuote($this->chains[0]->symbol);
                    } else {
                        $quote = $this->getQuote();
                    }

                    $bid = $quote->bid;
                    $ask = $quote->ask;

                    $this->showBidAsk($io, $bid, $ask, $this->class === 'option');

                    $limit = $io->askQuestion($limitQuestion);
                    if ($limit !== 'r') {
                        break;
                    }
                }
                $this->price = $limit;
            }

            if ($this->type === 'stop' || $this->type === 'stop_limit') {
                $this->stop = $io->ask('Stop price', null, function ($stop) {
                    if (!is_numeric($stop)) {
                        throw new \RuntimeException('Please enter a valid stop price.');
                    } elseif ($stop == 0) {
                        throw new \RuntimeException('Stop price must not be 0.');
                    } elseif ($stop < 0) {
                        throw new \RuntimeException('Stop price must be a positive number.');
                    }
                    return floatval($stop);
                });
            }
        } else {
            $orderTypeChoices = [
                1 => 'market',
                'debit',
                'credit',
                'even',
            ];

            $this->type = $io->choice('Order type', $orderTypeChoices);

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

                    $this->showBidAsk($io, $bid, $ask, true, true);

                    $limit = $io->askQuestion($limitQuestion);
                    if ($limit !== 'r') {
                        break;
                    }
                }
                $this->price = $limit;
            }
        }

        $duration = $io->choice('Duration', [
            1 => 'day',
            'GTC',
            'pre-market',
            'post-market',
        ], 'day');

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
            $io->error($e->getMessage());
            return 1;
        }

        $list = [];
        $list[] = 'Order Details';
        $list[] = ['Commission' => $this->formatCurrency($order->commission)];

        if ($order->order_cost < 0) {
            $list[] = ['Order Proceeds' => $this->formatCurrency(abs($order->order_cost))];
        } else {
            $list[] = ['Order Cost' => $this->formatCurrency($order->order_cost)];
        }

        if (isset($order->margin_change)) {
            $list[] = ['Margin Requirement' => $this->formatCurrency($order->margin_change)];
        }

        if ($order->cost < 0) {
            $list[] = ['Total Order Proceeds' => $this->formatCurrency(abs($order->cost))];
        } else {
            $list[] = ['Est. Total Cost' => $this->formatCurrency($order->cost)];
        }

        if (isset($this->chains)) {
            $list[] = new TableSeparator();
            $greeks = [
                'delta' => 0,
                'gamma' => 0,
                'theta' => 0,
                'vega' => 0,
            ];
            foreach ($this->chains as $i => $chain) {
                if (strpos($this->sides[$i], 'buy') !== false) {
                    $greeks['delta'] += $chain->greeks->delta;
                    $greeks['gamma'] += $chain->greeks->gamma;
                    $greeks['theta'] += $chain->greeks->theta;
                    $greeks['vega'] += $chain->greeks->vega;
                } else {
                    $greeks['delta'] -= $chain->greeks->delta;
                    $greeks['gamma'] -= $chain->greeks->gamma;
                    $greeks['theta'] -= $chain->greeks->theta;
                    $greeks['vega'] -= $chain->greeks->vega;
                }
            }

            foreach ($greeks as $greek => $value) {
                $list[] = ["Net " . ucfirst($greek) => round($value, 6)];
            }
        }

        $io->definitionList(...$list);

        $confirm = $io->confirm('Send this order?', false);
        if ($confirm === true) {
            $order = $this->tradier->createOrder($params);
            $io->success(['Order created', 'Order ID: ' . $order->id]);
        } else {
            $io->note('Order was not submitted.');
        }

        return 0;
    }

    protected function addLeg(SymfonyStyle $io)
    {
        $class = $this->getClass($io);
        $side = $this->getSide($io, $class);
        $quantity = $this->getQuantity($io, $class);

        if ($class === 'option') {
            $expiration = $this->getExpiration($io);
            $optionType = $this->getOptionType($io);
            $chain = $this->getChain($io, $expiration, $optionType);
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

    protected function showBidAsk(SymfonyStyle $io, float $bid, float $ask, bool $includeMid = false, bool $includeDebitCredit = false): void
    {
        if ($includeMid === true) {
            $mid = $this->getMid($bid, $ask);
        }

        $list = [];
        $bidLabel = '';
        $midLabel = '';
        $askLabel = '';
        if ($includeDebitCredit === true) {
            $bidLabel = $bid < 0 ? 'Credit ' : 'Debit ';
            if (isset($mid)) {
                $midLabel = $mid < 0 ? 'Credit ' : 'Debit ';
            }
            $askLabel = $ask < 0 ? 'Credit ' : 'Debit ';
        }

        $bidLabel .= 'Bid';
        $list[] = [$bidLabel => $this->formatCurrency(abs($bid))];
        if (isset($mid)) {
            $midLabel .= 'Mid';
            $list[] = [$midLabel => $this->formatCurrency(abs($mid))];
        }
        $askLabel .= 'Ask';
        $list[] = [$askLabel => $this->formatCurrency(abs($ask))];

        $io->definitionList(...$list);
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

    protected function getClass(SymfonyStyle $io): string
    {
        $choices = [
            1 => 'equity',
            'option',
        ];
        $class = $io->choice('Security type', $choices);

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

    protected function getSide(SymfonyStyle $io, string $class): string
    {
        return str_replace(' ', '_', $io->choice('Side', $this->getAllowedSides($class)));
    }

    protected function getQuantity(SymfonyStyle $io, string $class): int
    {
        $validator = function ($quantity) {
            if (!is_numeric($quantity) || intval($quantity) <= 0) {
                throw new \RuntimeException('Please enter a valid numeric quantity.');
            }

            return intval($quantity);
        };

        return $io->ask($class === 'option' ? 'Contracts: ' : 'Shares: ', null, $validator);
    }

    protected function getExpiration(SymfonyStyle $io): \DateTime
    {
        $expirations = $this->tradier->getOptionExpirations($this->symbol, true);

        $validator = function ($expiration) use ($expirations) {
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
        };

        $expiration = $io->ask('Expiration (enter "list" to list all expirations)', null, $validator);

        if ($expiration === 'list') {
            $expirationChoices = [];
            foreach ($expirations as $i => $exp) {
                $expirationChoices[$i+1] = $exp->format('M j, Y');
            }

            $expiration = new \DateTime($io->choice('Expiration', $expirationChoices));
        }

        return $expiration;
    }

    protected function getOptionType(SymfonyStyle $io): string
    {
        return $io->choice('Option type', [1 => 'call', 'put']);
    }

    protected function getChain(SymfonyStyle $io, \DateTime $expiration, string $optionType): \stdClass
    {
        $strikes = $this->tradier->getOptionStrikes($this->symbol, $expiration);

        $methodChoices = [
            1 => 'manually',
            'select from list',
            'by delta',
        ];

        // Repeat until a strike is chosen.
        while (true) {
            $method = $io->choice('How would you like to enter the strike?', $methodChoices);
            $chains = $this->tradier->getOptionChains($this->symbol, $expiration, true);
            $selectedChain = null;

            if ($method === 'manually') {
                $validator = function ($strike) use ($strikes) {
                    if ($strike === '<') {
                        return $strike;
                    } elseif (!is_numeric($strike)) {
                        throw new \RuntimeException('Please enter a valid numeric strike.');
                    } elseif (!in_array((float)$strike, $strikes)) {
                        throw new \RuntimeException('That is not a valid strike.');
                    }
                    return floatval($strike);
                };

                $strike = $io->ask('Strike (enter "<" to choose another method)', null, $validator);
                if ($strike === '<') {
                    continue;
                }
            } elseif ($method === 'select from list') {
                $numStrikesChoices = [
                    1 => 6,
                    8,
                    10,
                    12,
                    14,
                    16,
                    18,
                    20,
                    'all',
                ];
                $numStrikes = $io->choice('How many strikes would you like to view?', $numStrikesChoices);

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

                $strikeSelection = array_combine(range(1, count($strikeSelection)), array_values($strikeSelection));
                $strikeSelection[] = 'go back';
                $strike = $io->choice('Strike', $strikeSelection);
                if ($strike === 'go back') {
                    continue;
                }
                $strike = floatval($strike);
            } elseif ($method === 'by delta') {
                $validator = function ($delta) use ($optionType) {
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
                };

                $delta = $io->ask('Delta (enter "<" to go back)', null, $validator);
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

            $io->definitionList(
                $selectedChain->description,
                new TableSeparator(),
                ['Bid' => $this->formatCurrency($selectedChain->bid)],
                ['Mid' => $this->formatCurrency($mid)],
                ['Ask' => $this->formatCurrency($selectedChain->ask)],
                ['Volume' => $this->formatNumber($selectedChain->volume, 0)],
                ['Open Interest' => $this->formatNumber($selectedChain->open_interest, 0)],
                ['IV' => $this->formatPercent($selectedChain->greeks->smv_vol)],
                new TableSeparator(),
                'Greeks',
                new TableSeparator(),
                ['Delta' => $selectedChain->greeks->delta],
                ['Gamma' => $selectedChain->greeks->gamma],
                ['Theta' => $selectedChain->greeks->theta],
                ['Vega' => $selectedChain->greeks->vega]
            );

            $confirm = $io->confirm('Is this OK?', false);
            if ($confirm === true) {
                break;
            }
        }

        return $selectedChain;
    }

    public function lookup($value)
    {
        if (empty($value)) {
            return [];
        }

        try {
            $results = $this->tradier->lookup($value);
        } catch (TradierException $e) {
            return [];
        }

        $matches = [];
        foreach ($results as $result) {
            $matches[] = $result->symbol;
        }

        return $matches;
    }
}
