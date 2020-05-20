<?php

declare(strict_types = 1);

namespace Devbanana\OptionCalculator;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
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

    public function requiresAccount(): void
    {
        if (!isset($this->accountId)) {
            throw new TradierException('This call requires a Tradier brokerage account.');
        }
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

    public function put(string $endpoint, array $query = [], array $fields = [])
    {
        return $this->call('PUT', $endpoint, $query, $fields);
    }

    public function getQuote(string $symbol, bool $greeks = false)
    {
        $response = $this->get('markets/quotes', [
            'symbols' => $symbol,
            'greeks' => $greeks === true ? 'true' : 'false',
        ]);

        return $response->quotes->quote;
    }

    public function getOptionExpirations(string $symbol, bool $includeAllRoutes = false, bool $strikes = false): array
    {
        $params = [];
        $params['symbol'] = $symbol;

        if ($includeAllRoutes) {
            $params['includeAllRoutes'] = 'true';
        }
        if ($strikes) {
            $params['strikes'] = 'true';
        }

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

        if ($response->options === null) {
            throw new TradierException('No option chains were found.');
        }

        $chains = [];

        foreach ($response->options->option as $option) {
            $chains[] = $option;
        }

        return $chains;
    }

    public function previewOrder(array $order)
    {
        $this->requiresAccount();
        $order['preview'] = 'true';
        return $this->createOrder($order);
    }

    public function createOrder(array $order)
    {
        $this->requiresAccount();

        $response = $this->post("accounts/{$this->accountId}/orders", [], $order);

        if (isset($response->errors)) {
            throw new TradierException($response->errors->error);
        }

        return $response->order;
    }

    public function modifyOrder(string $orderId, array $order)
    {
        $this->requiresAccount();

        $response = $this->put("accounts/{$this->accountId}/orders/$orderId", [], $order);

        if (isset($response->errors)) {
            throw new TradierException($response->errors->error);
        }

        return $response->order;
    }

    public function getBalances()
    {
        $this->requiresAccount();

        $response = $this->get("accounts/{$this->accountId}/balances");
        return $response->balances;
    }

    public function getHistory()
    {
        $this->requiresAccount();

        $response = $this->get("accounts/{$this->accountId}/history");
        if ($response->history === null) {
            throw new TradierException('No history found.');
        }

        $history = $response->history->event;
        if (!is_array($history)) {
            $history = [$history];
        }

        return $history;
    }

    public function getOrders()
    {
        $this->requiresAccount();

        $response = $this->get("accounts/{$this->accountId}/orders");
        if ($response->orders === null) {
            return [];
        } elseif (!is_array($response->orders->order)) {
            return [$response->orders->order];
        } else {
            return $response->orders->order;
        }
    }

    public function getOrder(int $order)
    {
        $this->requiresAccount();

        try {
            $response = $this->get("accounts/{$this->accountId}/orders/$order");
        } catch (ClientException $e) {
            throw new TradierException("Order $order does not exist.");
        }

        return $response->order;
    }

    public function lookup(string $q, ?string $exchanges = null, ?string $types = null)
    {
        $params = [];
        $params['q'] = $q;
        if ($exchanges) {
            $params['exchanges'] = $exchanges;
        }
        if ($types) {
            $params['types'] = $types;
        }

        $response = $this->get('markets/lookup', $params);

        if ($response->securities === null) {
            throw new TradierException('No matches found.');
        }
        $securities = $response->securities->security;
        if (!is_array($securities)) {
            $securities = [$securities];
        }

        return $securities;
    }

    public function getHistoricalQuotes(string $symbol, string $interval = 'daily', ?\DateTime $start, ?\DateTime $end = null)
    {
        $params = [];
        $params['symbol'] = $symbol;
        $params['interval'] = $interval;

        if (isset($start)) {
            $params['start'] = $start->format('Y-m-d');
        }
        if (isset($end)) {
            $params['end'] = $end->format('Y-m-d');
        }

        $response = $this->get('markets/history', $params);

        return $response->history->day;
    }

    public function getClock()
    {
        $response = $this->get('markets/clock');

        return $response->clock;
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
