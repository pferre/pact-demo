<?php

namespace App\Tests\Contract;

use PhpPact\Standalone\ProviderVerifier\Model\VerifierConfig;
use PhpPact\Standalone\ProviderVerifier\Verifier;
use PHPUnit\Framework\TestCase;

/**
 * Provider-side PACT verification test.
 *
 * Fetches ALL published pacts for ProductService from the broker and
 * replays each interaction against the real running provider, confirming
 * this version honours every consumer contract.
 *
 * Environment variables (set automatically by GitLab CI or .env locally):
 *   PROVIDER_BASE_URL       URL of the running provider (default: http://localhost:8002)
 *   PACT_BROKER_URL         PACT broker base URL
 *   PACT_BROKER_USERNAME    Broker basic auth username
 *   PACT_BROKER_PASSWORD    Broker basic auth password
 *   APP_VERSION             Provider version to tag results with (CI commit SHA)
 *   CI_COMMIT_REF_NAME      Branch name — used to scope verification
 *
 * Run locally:
 *   docker compose exec provider php vendor/bin/phpunit tests/Contract --testdox
 *
 * Run in CI:
 *   Triggered automatically by the GitLab pipeline or by broker webhook.
 */
class ProductServiceProviderTest extends TestCase
{
    public function testProviderHonoursConsumerContracts(): void
    {
        $brokerUrl      = getenv('PACT_BROKER_URL')         ?: 'http://pact-broker:9292';
        $brokerUser     = getenv('PACT_BROKER_USERNAME')    ?: 'pact';
        $brokerPass     = getenv('PACT_BROKER_PASSWORD')    ?: 'pact';
        $providerUrl    = getenv('PROVIDER_BASE_URL')       ?: 'http://provider:80';
        $providerBranch = getenv('CI_COMMIT_REF_NAME')      ?: 'main';

        // Use the git commit SHA in CI, fall back to a local dev label
        $providerVersion = getenv('APP_VERSION') ?: ('local-' . date('YmdHis'));

        $config = new VerifierConfig();
        $config
            ->setProviderName('ProductService')
            ->setProviderVersion($providerVersion)
            ->setProviderBranch($providerBranch)
            ->setProviderBaseUrl($providerUrl)

            // Pull pacts from the broker
            ->setBrokerUrl($brokerUrl)
            ->setBrokerUsername($brokerUser)
            ->setBrokerPassword($brokerPass)

            // Verify pacts from all consumers that are deployed to production
            // AND any pacts from consumer feature branches (pending pacts)
            ->setConsumerVersionSelectors([
                ['deployedOrReleased' => true],   // what's in production right now
                ['mainBranch'         => true],   // consumer's main branch pacts
                ['matchingBranch'     => true],   // pacts from a matching branch name
            ])

            // Publish results back to broker so can-i-deploy has an answer
            ->setPublishResults(true)

            // Pending pacts: new consumer contracts won't fail THIS provider
            // pipeline until both teams have agreed to support them.
            ->setEnablePending(true)

            // WIP pacts: include consumer feature branch pacts for early warning
            ->setIncludeWipPactsSince(new \DateTime('2024-01-01'));

        $verifier = new Verifier($config);
        $verifier->verifyAll();

        $this->assertTrue(
            true,
            "ProductService@{$providerVersion} verified all consumer pacts successfully."
        );
    }
}
