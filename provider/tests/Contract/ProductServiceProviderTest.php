<?php

namespace App\Tests\Contract;

use GuzzleHttp\Psr7\Uri;
use PhpPact\Standalone\ProviderVerifier\Model\Config\ProviderInfo;
use PhpPact\Standalone\ProviderVerifier\Model\Config\ProviderTransport;
use PhpPact\Standalone\ProviderVerifier\Model\Config\PublishOptions;
use PhpPact\Standalone\ProviderVerifier\Model\Source\Broker;
use PhpPact\Standalone\ProviderVerifier\Model\VerifierConfig;
use PhpPact\Standalone\ProviderVerifier\Verifier;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Dotenv\Dotenv;

/**
 * Provider-side PACT verification test.
 *
 * Uses Symfony's Dotenv component to load .env values without needing
 * to boot the full kernel — keeps the test lightweight while still
 * reading config consistently from the same .env files as the app.
 *
 * Env vars (define in provider/.env or provider/.env.local):
 *   PACT_BROKER_URL          Broker base URL
 *   PACT_BROKER_USERNAME     Broker basic auth username
 *   PACT_BROKER_PASSWORD     Broker basic auth password
 *   PROVIDER_BASE_URL        URL of the running provider
 *   APP_VERSION              Version string (CI commit SHA or local fallback)
 *   CI_COMMIT_REF_NAME       Branch name for scoping verification
 */
class ProductServiceProviderTest extends TestCase
{
    protected function setUp(): void
    {
        // Load .env into $_ENV / $_SERVER without overriding vars
        // already set by the OS (e.g. CI-injected values take precedence)
        $dotenv = new Dotenv();
        $dotenv->loadEnv(dirname(__DIR__, 2) . '/.env');
    }

    public function testProviderHonoursConsumerContracts(): void
    {
        // ── Resolve env vars ──────────────────────────────────────────────
        $brokerUrl       = $_ENV['PACT_BROKER_URL']      ?? 'http://pact-broker:9292';
        $brokerUser      = $_ENV['PACT_BROKER_USERNAME'] ?? 'pact';
        $brokerPass      = $_ENV['PACT_BROKER_PASSWORD'] ?? 'pact';
        $providerUrl     = $_ENV['PROVIDER_BASE_URL']    ?? 'http://provider:80';
        $providerBranch  = $_ENV['CI_COMMIT_REF_NAME']   ?? 'main';
        $providerVersion = $_ENV['APP_VERSION']          ?? ('local-' . date('YmdHis'));

        // ── Parse provider URL into host/port/scheme ──────────────────────
        $parsed = parse_url($providerUrl);

        // ── Provider info (who we are) ────────────────────────────────────
        $providerInfo = new ProviderInfo();
        $providerInfo
            ->setName('ProductService')
            ->setHost($parsed['host'])
            ->setScheme($parsed['scheme'] ?? 'http')
            ->setPort($parsed['port'] ?? 80);

        // ── Transport (how to reach us) ───────────────────────────────────
        $transport = new ProviderTransport();
        $transport
            ->setProtocol('http')
            ->setScheme($parsed['scheme'] ?? 'http')
            ->setPort($parsed['port'] ?? 80)
            ->setPath('/');

        // ── Broker source (where to fetch pacts from) ─────────────────────
        $broker = new Broker();
        $broker
            ->setUrl(new Uri($brokerUrl))
            ->setUsername($brokerUser)
            ->setPassword($brokerPass)
            ->setEnablePending(true)
            ->setIncludeWipPactSince('2024-01-01')
            ->setProviderBranch($providerBranch);

        // ── Publish options (tag results back to broker) ──────────────────
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
        $verifier->verify();

        $this->assertTrue(
            true,
            "ProductService@{$providerVersion} verified all consumer pacts successfully."
        );
    }
}
