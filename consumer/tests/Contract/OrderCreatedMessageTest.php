<?php

namespace App\Tests\Contract;

use PhpPact\Config\Exception\InvalidWriteModeException;
use PhpPact\Standalone\PactMessage\PactMessageConfig;
use App\Message\OrderCreatedMessage;
use PhpPact\Consumer\MessageBuilder;
use PhpPact\Consumer\Matcher\Matcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Dotenv\Dotenv;

/**
 * Message PACT consumer test — defines the order.created event shape.
 * No RabbitMQ connection needed — PACT tests the message shape only.
 *
 * @group contract
 */
class OrderCreatedMessageTest extends TestCase
{
    private PactMessageConfig $config;
    private Matcher $matcher;

    /**
     * @throws InvalidWriteModeException
     */
    protected function setUp(): void
    {
        (new Dotenv())->loadEnv(dirname(__DIR__, 2) . '/.env');

        $this->matcher = new Matcher();

        $this->config = new PactMessageConfig();
        $this->config
            ->setConsumer('OrderService')
            ->setProvider('ProductService-Events')
            ->setPactDir('/app/pacts')
            ->setPactFileWriteMode('merge');  // ← add this
    }

    public function testOrderCreatedMessageShape(): void
    {
        $builder = new MessageBuilder($this->config);

        $builder
            ->given('an order has been placed')
            ->expectsToReceive('an order.created event')
            ->withContent([
                'event' => $this->matcher->like('order.created'),
                'orderId' => $this->matcher->like('ORD-abc123'),
                'customerId' => $this->matcher->like('CUST-001'),
                'customerEmail' => $this->matcher->like('customer@example.com'),
                'totalAmount' => $this->matcher->decimal(49.99),
                'currency' => $this->matcher->like('GBP'),
                'createdAt' => $this->matcher->like('2024-01-01T00:00:00+00:00'),
            ])
            ->withMetadata(['contentType' => 'application/json']);

        $message = new OrderCreatedMessage(
            orderId: 'ORD-test123',
            customerId: 'CUST-001',
            customerEmail: 'customer@example.com',
            totalAmount: 49.99,
            currency: 'GBP',
            createdAt: '2024-01-01T00:00:00+00:00',
        );

        $payload = $message->toArray();

        self::assertArrayHasKey('event', $payload);
        self::assertArrayHasKey('orderId', $payload);
        self::assertArrayHasKey('customerId', $payload);
        self::assertArrayHasKey('customerEmail', $payload);
        self::assertArrayHasKey('totalAmount', $payload);
        self::assertArrayHasKey('currency', $payload);
        self::assertArrayHasKey('createdAt', $payload);
        self::assertEquals('order.created', $payload['event']);

        $actual = static function (array $message) use ($payload): array {
            if (isset($message['orderId'], $message['customerEmail']) && $message['event'] === $payload['event']) {
                return $message;
            }
            return [];
        };

        $builder->verifyMessage(static function ($message) use ($payload): array {
            if (isset($message['orderId'], $message['customerEmail']) && $message['event'] === $payload['event']) {
                return $message($payload);
            }

            return [];
        });
    }
}
