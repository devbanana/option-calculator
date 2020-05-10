<?php

declare(strict_types = 1);

namespace Devbanana\OptionCalculator;

use GuzzleHttp\Client;

class Tradier
{

    protected $client;
    protected $token;

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

    public function call(string $method, string $endpoint, ?array $query = [])
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

    public function get(string $endpoint, ?array $query = [])
    {
        return $this->call('GET', $endpoint, $query);
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

    protected function getClient(): Client
    {
        return $this->client;
    }

    protected function getToken(): string
    {
        return $this->token;
    }

}
