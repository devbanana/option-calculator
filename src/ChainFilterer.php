<?php

declare(strict_types = 1);

namespace Devbanana\OptionCalculator;

class ChainFilterer
{
    protected array $chains;
    protected float $price;
    protected int $strikes;
    protected bool $includeCalls;
    protected bool $includePuts;

    public function __construct(array $chains)
    {
        $this->chains = $chains;
        $this->strikes = 5;
        $this->includeCalls = true;
        $this->includePuts = true;
    }

    public function setPrice(float $price): self
    {
        $this->price = $price;
        return $this;
    }

    public function setStrikes(int $strikes): self
    {
        $this->strikes = $strikes;
        return $this;
    }

    public function includeCalls(): self
    {
        $this->includeCalls = true;
        return $this;
    }

    public function excludeCalls(): self
    {
        $this->includeCalls = false;
        return $this;
    }

    public function includePuts(): self
    {
        $this->includePuts = true;
        return $this;
    }

    public function excludePuts(): self
    {
        $this->includePuts = false;
        return $this;
    }

    public function getChains(): array
    {
        // Perform some validation.
        if (!$this->includeCalls && !$this->includePuts) {
            throw new \InvalidArgumentException("Both calls and puts have been excluded.");
        }

        $lower = [];
        $higher = [];

        foreach ($this->chains as $chain) {
            if ($this->includeCalls && $chain->option_type === 'call') {
                if ($chain->strike < $this->price) {
                    $lower[(string)$chain->strike]['call'] = $chain;
                } else {
                    $higher[(string)$chain->strike]['call'] = $chain;
                }
            } elseif ($this->includePuts && $chain->option_type === 'put') {
                if ($chain->strike < $this->price) {
                    $lower[(string)$chain->strike]['put'] = $chain;
                } else {
                    $higher[(string)$chain->strike]['put'] = $chain;
                }
            }
        }

        $lowerChains = array_slice($lower, -$this->strikes);
        $higherChains = array_slice($higher, 0, $this->strikes);

        return array_merge($lowerChains, $higherChains);
    }
}
