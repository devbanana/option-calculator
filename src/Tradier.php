<?php

declare(strict_types = 1);

namespace Devbanana\OptionCalculator;

use GuzzleHttp\Client;

class Tradier
{

    protected Client $client;
    protected string $token;

    public function __construct(string $token, bool $sandbox = false)
    {
        $this->token = $token;
        $uri = $sandbox === true
            ? 'https://sandbox.tradier.com/v1/'
            : 'https://api.tradier.com/v1/'
        ;

            $this->client = new Client([
                'base_uri' => $uri,
            ]);
    }

    public function call(string $method, string $endpoint, array $query = [])
    {
        $headers = [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $this->getToken(),
        ];

        $response = $this->getClient()->request($method, $endpoint, [
            'query' => $query,
            'headers' => $headers,
        ]);

        return json_decode((string)$response->getBody());
    }

    public function get(string $endpoint, array $query = [])
    {
        return $this->call('GET', $endpoint, $query);
    }

    public function getQuote(string $symbol, bool $greeks = false)
    {
        $response = $this->get('markets/quotes', [
            'symbols' => $symbol,
            'greeks' => $greeks === true ? 'true' : 'false',
        ]);

        return $response->quotes->quote;
    }

    public function getOptionExpirations(string $symbol, ?array $additionalParams = []): array
    {
        $params = [
            'symbol' => $symbol,
        ] + $additionalParams;

        $response = $this->get('markets/options/expirations', $params);

        if ($response->expirations === null) {
            throw new \TradierException("No expirations found for $symbol");
        }

        $expirations = [];

        foreach ($response->expirations->date as $date) {
            $date = new \DateTime($date);
            $expirations[] = $date;
        }

        return $expirations;
    }

    public function getOptionChains(string $symbol, \DateTime $expiration, ?bool $greeks = false)
    {
        $response = $this->get('markets/options/chains', [
            'symbol' => $symbol,
            'expiration' => $expiration->format('Y-m-d'),
            'greeks' => $greeks === true ? 'true' : 'false',
        ]);

        $chains = [];

        foreach ($response->options->option as $option) {
            $chains[] = $option;
        }

        return $chains;
    }

    protected function getClient(): Client
    {
        return $this->client;
    }

    protected function getToken(): string
    {
        return $this->token;
    }

}
