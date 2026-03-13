<?php

namespace App\Tests\Contract;

use App\Service\ProductServiceClient;
use PhpPact\Consumer\InteractionBuilder;
use PhpPact\Consumer\Matcher\Matcher;
use PhpPact\Consumer\Model\ConsumerRequest;
use PhpPact\Consumer\Model\ProviderResponse;
use PhpPact\Standalone\MockService\MockServerConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Consumer-side PACT contract test.
 *
 * This test:
 *   1. Defines the interactions (contracts) the Consumer expects from the Provider.
 *   2. Spins up a PACT mock server that acts as the Provider.
 *   3. Runs the real ProductServiceClient against the mock server.
 *   4. Writes the pact file to /app/pacts/ for publishing to the broker.
 */
class ProductServiceConsumerTest extends TestCase
{
    private InteractionBuilder $builder;
    private MockServerConfig $config;
    private Matcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new Matcher();

        $this->config = new MockServerConfig();
        $this->config
            ->setConsumer('OrderService')
            ->setProvider('ProductService')
            ->setPactDir('/app/pacts')
            ->setHost('localhost')
            ->setPort(7200);

        $this->builder = new InteractionBuilder($this->config);
    }

    public function testGetProductById(): void
    {
        // ── 1. Define the expected interaction ──────────────────────────────
        $request = new ConsumerRequest();
        $request
            ->setMethod('GET')
            ->setPath('/api/products/1')
            ->addHeader('Accept', 'application/json');

        $response = new ProviderResponse();
        $response
            ->setStatus(200)
            ->addHeader('Content-Type', 'application/json')
            ->setBody([
                'id'    => $this->matcher->integer(1),
                'name'  => $this->matcher->like('Widget A'),
                'price' => $this->matcher->decimal(9.99),
            ]);

        $this->builder
            ->uponReceiving('a request for product with id 1')
            ->with($request)
            ->willRespondWith($response);

        // ── 2. Run the real client against the mock server ──────────────────
        $mockUrl = "http://localhost:{$this->config->getPort()}";

        $client = new ProductServiceClient(
            HttpClient::create(),
            $mockUrl
        );

        $product = $client->getProduct(1);

        // ── 3. Assert the client parses the response correctly ──────────────
        self::assertIsArray($product);
        self::assertArrayHasKey('id', $product);
        self::assertArrayHasKey('name', $product);
        self::assertArrayHasKey('price', $product);

        // ── 4. Verify & write the pact file ─────────────────────────────────
        $this->builder->verify();
    }

    public function testGetProductByIdNotFound(): void
    {
        $request = new ConsumerRequest();
        $request
            ->setMethod('GET')
            ->setPath('/api/products/999')
            ->addHeader('Accept', 'application/json');

        $response = new ProviderResponse();
        $response->setStatus(404);

        $this->builder
            ->uponReceiving('a request for a product that does not exist')
            ->with($request)
            ->willRespondWith($response);

        $mockUrl = "http://localhost:{$this->config->getPort()}";

        $client = new ProductServiceClient(
            HttpClient::create(),
            $mockUrl
        );

        $product = $client->getProduct(999);

        self::assertNull($product);

        $this->builder->verify();
    }
}
