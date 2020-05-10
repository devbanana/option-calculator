<?php

declare(strict_types = 1);

namespace Devbanana\OptionCalculator\Command;

use Symfony\Component\Console\Command\Command;
use Devbanana\OptionCalculator\Tradier;

abstract class BaseCommand extends Command
{
    protected function createTradier(): Tradier
    {
        return new Tradier($_ENV['TRADIER_TOKEN'], $_ENV['TRADIER_SANDBOX'] === 'true');
    }
}
