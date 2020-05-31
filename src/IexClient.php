<?php

declare(strict_types = 1);

namespace Devbanana\OptionCalculator;

use GuzzleHttp\Client;
use Devbanana\OptionCalculator\Exception\IexPaymentRequiredException;
use GuzzleHttp\Exception\ClientException;

class IexClient
{
    private $client;
    private $token;

    public function __construct(string $token)
    {
        $this->token = $token;
        $baseUrl = 'https://cloud.iexapis.com/stable/';
        $this->client = new Client([
            'base_uri' => $baseUrl,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    public function send(string $endpoint, array $params = [], $method = 'GET')
    {
        $params['token'] = $this->token;

        try {
            $response = $this->client->request($method, $endpoint, [
                'query' => $params,
            ]);
        } catch (ClientException $e) {
            $response = $e->getResponse();
            if ($response->getStatusCode() === 402 && $response->getReasonPhrase() === 'Payment Required') {
                throw new IexPaymentRequiredException('This is a premium endpoint.');
            } else {
                throw $e;
            }
        }

        return json_decode((string)$response->getBody());
    }
}
