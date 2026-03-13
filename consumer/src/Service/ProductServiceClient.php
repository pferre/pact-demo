<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * HTTP client for communicating with the Provider (Product) service.
 *
 * The $baseUrl is injected via services.yaml so it can be overridden
 * in tests to point at the PACT mock server instead.
 */
class ProductServiceClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $baseUrl,
    ) {}

    /**
     * Fetch a single product by ID.
     *
     * @return array<string, mixed>|null
     */
    public function getProduct(int $id): ?array
    {
        try {
            $response = $this->httpClient->request(
                'GET',
                "{$this->baseUrl}/api/products/{$id}",
                ['headers' => ['Accept' => 'application/json']]
            );

            if ($response->getStatusCode() === 404) {
                return null;
            }

            return $response->toArray();
        } catch (TransportExceptionInterface) {
            return null;
        }
    }

    /**
     * Fetch all products.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getProducts(): array
    {
        try {
            $response = $this->httpClient->request(
                'GET',
                "{$this->baseUrl}/api/products",
                ['headers' => ['Accept' => 'application/json']]
            );

            return $response->toArray();
        } catch (TransportExceptionInterface) {
            return [];
        }
    }
}
