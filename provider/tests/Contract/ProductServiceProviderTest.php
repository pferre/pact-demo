<?php

namespace App\Tests\Contract;

use PhpPact\Standalone\ProviderVerifier\Model\Config\ProviderInfo;
use PhpPact\Standalone\ProviderVerifier\Model\Config\ProviderTransport;
use PhpPact\Standalone\ProviderVerifier\Model\Config\PublishOptions;
use PhpPact\Standalone\ProviderVerifier\Model\Source\Broker;
use PhpPact\Standalone\ProviderVerifier\Model\VerifierConfig;
use PhpPact\Standalone\ProviderVerifier\Verifier;
use PHPUnit\Framework\TestCase;

class ProductServiceProviderTest extends TestCase
{
    public function testProviderHonoursConsumerContracts(): void
    {
        $brokerUrl      = getenv('PACT_BROKER_URL')      ?: 'http://pact-broker:9292';
        $brokerUser     = getenv('PACT_BROKER_USERNAME') ?: 'pact';
        $brokerPass     = getenv('PACT_BROKER_PASSWORD') ?: 'pact';
        $providerBranch = getenv('CI_COMMIT_REF_NAME')   ?: 'main';
        $providerVersion = getenv('APP_VERSION')         ?: ('local-' . date('YmdHis'));

        // Parse the provider URL into host/port/scheme
        $providerUrl = getenv('PROVIDER_BASE_URL') ?: 'http://provider:80';
        $parsed      = parse_url($providerUrl);

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
            ->setUrl(new \GuzzleHttp\Psr7\Uri($brokerUrl))
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
