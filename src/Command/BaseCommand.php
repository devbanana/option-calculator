<?php

declare(strict_types = 1);

namespace Devbanana\OptionCalculator\Command;

use Symfony\Component\Console\Command\Command;
use Devbanana\OptionCalculator\Tradier;

abstract class BaseCommand extends Command
{
    protected \NumberFormatter $moneyFormatter;
    protected \NumberFormatter $percentFormatter;
    protected \NumberFormatter $numberFormatter;
    protected \NumberFormatter $changeFormatter;
    protected \NumberFormatter $changePercentFormatter;

    public function __construct()
    {
        $this->moneyFormatter = new \NumberFormatter('en_US', \NumberFormatter::CURRENCY);
        $this->percentFormatter = new \NumberFormatter('en_US', \NumberFormatter::PERCENT);
        $this->numberFormatter = new \NumberFormatter('en_US', \NumberFormatter::DECIMAL);
        $this->changeFormatter = new \NumberFormatter('en_US', \NumberFormatter::CURRENCY);
        $this->changeFormatter->setTextAttribute(\NumberFormatter::POSITIVE_PREFIX, '+$');
        $this->changePercentFormatter = new \NumberFormatter('en_US', \NumberFormatter::PERCENT);
        $this->changePercentFormatter->setTextAttribute(\NumberFormatter::POSITIVE_PREFIX, '+');
        $this->changePercentFormatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, 2);
        parent::__construct();
    }

    protected function createTradier(): Tradier
    {
        $tradier = new Tradier($_ENV['TRADIER_TOKEN'], $_ENV['TRADIER_SANDBOX'] === 'true');
        if (isset($_ENV['TRADIER_ACCOUNT_ID'])) {
            $tradier->setAccountId($_ENV['TRADIER_ACCOUNT_ID']);
        }

        return $tradier;
    }

    protected function formatCurrency($number, string $currency = 'USD'): string
    {
        return $this->moneyFormatter->formatCurrency($number, 'USD');
    }

    protected function formatPercent($number, int $decimals = 2): string
    {
        if ($decimals !== null) {
            $this->percentFormatter->setAttribute(\NumberFormatter::FRACTION_DIGITS, $decimals);
        }
        return $this->percentFormatter->format($number);
    }

    protected function formatNumber($number, int $decimals = 1): string
    {
        if ($decimals !== null) {
            $this->numberFormatter->setAttribute(\NumberFormatter::FRACTION_DIGITS, $decimals);
        }
        return $this->numberFormatter->format($number);
    }

    protected function formatChange(float $change, float $changePercent): string
    {
        $output = $this->changeFormatter->formatCurrency($change, 'USD') . ' (' .
            $this->changePercentFormatter->format($changePercent/100) . ')';
        if ($change > 0) {
            $output = "<info>$output</info>";
        } elseif ($change < 0) {
            $output = "<error>$output</error>";
        }
        return $output;
    }

    protected function export(string $file, array $headers, array $rows)
    {
        $handle = fopen($file, 'w');
        fputcsv($handle, $headers);
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);
    }
}
