<?php

declare(strict_types = 1);

namespace Devbanana\OptionCalculator;

use GuzzleHttp\Client;
use Devbanana\OptionCalculator\Exception\TradierException;

class Tradier
{
    protected Client $client;
    protected string $token;
    protected string $accountId;

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

    public function setAccountId(string $accountId)
    {
        $this->accountId = $accountId;
    }

    public function call(string $method, string $endpoint, array $query = [], ?array $fields = null)
    {
        $headers = [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $this->getToken(),
        ];

        $options = [];
        $options['query'] = $query;
        $options['headers'] = $headers;
        if (isset($fields)) {
            $options['form_params'] = $fields;
        }

        $response = $this->getClient()->request($method, $endpoint, $options);

        return json_decode((string)$response->getBody());
    }

    public function get(string $endpoint, array $query = [])
    {
        return $this->call('GET', $endpoint, $query);
    }

    public function post(string $endpoint, array $query = [], array $fields = [])
    {
        return $this->call('POST', $endpoint, $query, $fields);
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
            throw new TradierException("No expirations found for $symbol");
        }

        $expirations = [];

        foreach ($response->expirations->date as $date) {
            $date = new \DateTime($date);
            $expirations[] = $date;
        }

        return $expirations;
    }

    public function getOptionStrikes(string $symbol, \DateTime $expiration): array
    {
        $response = $this->get(
            'markets/options/strikes',
            [
                'symbol' => $symbol,
                'expiration' => $expiration->format('Y-m-d'),
            ]
        );

        if ($response->strikes === null) {
            throw new TradierException('No strikes were found for that symbol and expiration.');
        }

        return $response->strikes->strike;
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

    public function previewOrder(array $order)
    {
        $order['preview'] = 'true';
        return $this->createOrder($order);
    }

    public function createOrder(array $order)
    {
        $response = $this->post("accounts/{$this->accountId}/orders", [], $order);

        if (isset($response->errors)) {
            throw new TradierException($response->errors->error);
        }

        return $response->order;
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
