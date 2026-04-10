<?php

namespace App\Tests\Contract;

use GuzzleHttp\Psr7\Uri;
use PhpPact\Standalone\ProviderVerifier\Exception\InvalidVerifierHandleException;
use PhpPact\Standalone\ProviderVerifier\Model\Config\ProviderInfo;
use PhpPact\Standalone\ProviderVerifier\Model\Config\ProviderTransport;
use PhpPact\Standalone\ProviderVerifier\Model\Config\PublishOptions;
use PhpPact\Standalone\ProviderVerifier\Model\Source\Broker;
use PhpPact\Standalone\ProviderVerifier\Model\VerifierConfig;
use PhpPact\Standalone\ProviderVerifier\Verifier;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Dotenv\Dotenv;

/**
 * Message PACT provider verification test.
 *
 * In pact-php v10, message verification works via a provider transport —
 * the verifier calls an HTTP endpoint we expose which returns the message
 * payload. We spin up PHP's built-in server for this purpose.
 *
 * @group contract
 */
class OrderCreatedMessageProviderTest extends TestCase
{
    private const MESSAGE_PORT = 7202;
    private mixed $serverProcess = null;

    protected function setUp(): void
    {
        if (!getenv('CI')) {
            $dotenv = new Dotenv();
            $dotenv->loadEnv(dirname(__DIR__, 2) . '/.env');
        }
    }

    protected function tearDown(): void
    {
        $this->stopMessageServer();
    }

    private function startMessageServer(): void
    {
        // Write a simple message handler script
        $script = <<<'PHP'
            <?php
            header('Content-Type: application/json');
            $body = json_decode(file_get_contents('php://input'), true);
            $description = $body['description'] ?? '';

            if ($description === 'an order.created event') {
                echo json_encode([
                    'event'         => 'order.created',
                    'orderId'       => 'ORD-test123',
                    'customerId'    => 'CUST-001',
                    'customerEmail' => 'customer@example.com',
                    'totalAmount'   => 49.99,
                    'currency'      => 'GBP',
                    'createdAt'     => '2024-01-01T00:00:00+00:00',
                ]);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Unknown message description']);
            }
            PHP;
        $scriptPath = sys_get_temp_dir() . '/pact_message_handler.php';
        file_put_contents($scriptPath, $script);

        $this->serverProcess = proc_open(
            sprintf('php -S 0.0.0.0:%d %s', self::MESSAGE_PORT, $scriptPath),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        sleep(2); // Allow server to start
    }

    private function stopMessageServer(): void
    {
        if ($this->serverProcess) {
            proc_terminate($this->serverProcess);
            proc_close($this->serverProcess);
        }
    }

    /**
     * @throws InvalidVerifierHandleException
     */
    public function testProductServiceHandlesOrderCreatedMessage(): void
    {
        $brokerUrl = getenv('PACT_BROKER_BASE_URL') ?: getenv('PACT_BROKER_URL') ?: 'http://pact-broker:9292';
        $brokerUser = getenv('PACT_BROKER_USERNAME') ?: 'pact';
        $brokerPass = getenv('PACT_BROKER_PASSWORD') ?: 'pact';
        $providerBranch = getenv('CI_COMMIT_REF_NAME') ?: 'main';
        $providerVersion = getenv('APP_VERSION') ?: ('local-' . date('YmdHis'));

        // ── Provider info ─────────────────────────────────────────────────
        $providerInfo = new ProviderInfo();
        $providerInfo
            ->setName('ProductService-Events')
            ->setHost('localhost')
            ->setScheme('http')
            ->setPort(self::MESSAGE_PORT);

        // ── Message transport ─────────────────────────────────────────────
        // This tells the verifier to use our message handler endpoint
        // for message pact verification instead of an HTTP API
        $transport = new ProviderTransport();
        $transport
            ->setProtocol('message')
            ->setScheme('http')
            ->setPort(self::MESSAGE_PORT)
            ->setPath('/');

        // ── Broker source ─────────────────────────────────────────────────
        $broker = new Broker();
        $broker
            ->setUrl(new Uri($brokerUrl))
            ->setUsername($brokerUser)
            ->setPassword($brokerPass)
            ->setEnablePending(true)
            ->setProviderBranch($providerBranch);

        // ── Publish options ───────────────────────────────────────────────
        $publishOptions = new PublishOptions();
        $publishOptions
            ->setProviderVersion($providerVersion)
            ->setProviderBranch($providerBranch);

        // ── Assemble config ───────────────────────────────────────────────
        $config = new VerifierConfig();
        $config
            ->setProviderInfo($providerInfo)
            ->addProviderTransport($transport)
            ->setPublishOptions($publishOptions);

        // ── Run verification ──────────────────────────────────────────────
        $verifier = new Verifier($config);
        $verifier->addBroker($broker);

        $result = $verifier->verify();

        $this->assertTrue(
            $result,
            "ProductService failed to verify order.created message contract.",
        );
    }
}
