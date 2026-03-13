# CI/CD Flow — PACT Contract Testing in a Microservice Pipeline

This document explains how the two separate pipelines interact through
the PACT Broker to form a safety net for independent deployments.

---

## The Core Problem This Solves

In a microservice architecture with separate repos and separate pipelines,
**Team A can unknowingly break Team B's application** by changing an API
response shape, removing a field, or altering a status code.

Without PACT, you discover this in staging (lucky) or production (bad).
With PACT, the pipeline blocks the deployment before it ever ships.

---

## Pipeline Overview

```
CONSUMER REPO (OrderService)          PROVIDER REPO (ProductService)
─────────────────────────────────     ──────────────────────────────────────

 push to main                          push to main
   │                                     │
   ▼                                     ▼
 composer-install                      composer-install
   │                                     │
   ▼                                     ▼
 unit-tests                            unit-tests
   │                                     │
   ▼                                     ▼
 pact-consumer-tests                   pact-verify  ◄─── fetches pacts
   │  (generates pact JSON)              │               from broker
   │                                     │           (published by consumer)
   ▼                                     ▼
 pact-publish                          can-i-deploy?
   │  (sends pact to broker)             │  "Is this provider version
   │                                     │   compatible with all consumers
   ▼                                     │   in production?"
 can-i-deploy?                           │
   │  "Is this consumer version          ▼
   │   compatible with the provider    deploy-production
   │   in production?"                   │
   │                                     ▼
   ▼                                   record-deployment
 deploy-production                       (broker updated)
   │
   ▼
 record-deployment
   (broker updated)
```

---

## Stage-by-Stage Breakdown

### Consumer pipeline

| Stage | What happens | Fails if... |
|-------|-------------|-------------|
| `build` | `composer install` | Dependencies can't resolve |
| `test` | Unit tests (non-contract) | Business logic is broken |
| `pact-test` | Boots PACT mock server, runs `ProductServiceClient` against it, writes `pacts/*.json` | Client doesn't match the defined interaction |
| `pact-publish` | Uploads pact file to broker, tagged with commit SHA + branch | Broker is unreachable |
| `can-i-deploy` | Asks broker: *"Has the provider verified this pact?"* | Provider hasn't verified yet, or verification failed |
| `deploy` | Ships the consumer | `can-i-deploy` returned non-zero |

---

### Provider pipeline

| Stage | What happens | Fails if... |
|-------|-------------|-------------|
| `build` | `composer install` | Dependencies can't resolve |
| `test` | Unit tests (non-contract) | Business logic is broken |
| `pact-verify` | Fetches ALL consumer pacts from broker, replays each request against the **real** running provider, publishes results | Provider response doesn't match any consumer's contract |
| `can-i-deploy` | Asks broker: *"Is this provider version safe for all consumers in production?"* | Any consumer's contract is not satisfied |
| `deploy` | Ships the provider | `can-i-deploy` returned non-zero |

---

## The Breaking Change Scenario (Demo Script)

This is the scenario to walk through in a live demo:

### Setup
Both services are deployed to production. Contracts are verified. ✅

### Team A makes a breaking change

In `ProductController.php`, Team A renames the `name` field to `product_name`:

```php
// Before (what the consumer expects)
return $this->json([
    'id'    => $product['id'],
    'name'  => $product['name'],   // ← consumer depends on this
    'price' => $product['price'],
]);

// After (Team A's change)
return $this->json([
    'id'           => $product['id'],
    'product_name' => $product['name'],  // ← renamed — BREAKING
    'price'        => $product['price'],
]);
```

### What the pipeline does

```
Provider pipeline runs:

  ✅ unit-tests          (passes — provider logic works fine)
  ❌ pact-verify         FAILS

       Verifying a pact between OrderService and ProductService
         Given a request for product with id 1
           returns a response which
             has a body
               $.name    <- Expected 'name' but was missing   ← CAUGHT HERE

  ⛔ can-i-deploy        (never reached)
  ⛔ deploy-production   (never reached)
```

**Team A's breaking change is blocked before it reaches production.**
The consumer (Team B) continues running without interruption.

---

## Broker Webhook — Triggering the Provider on New Consumer Pacts

When Team B (consumer) publishes a new pact, the provider pipeline should
re-run automatically to verify it. Configure this in the broker UI:

**Broker UI → Webhooks → New Webhook**

```
Event:    Contract published with changed content / tags
Consumer: OrderService
Provider: ProductService

URL:      https://gitlab.example.com/api/v4/projects/<PROVIDER_PROJECT_ID>/trigger/pipeline
Method:   POST
Headers:  Content-Type: application/json
Body:
  {
    "token": "<GITLAB_TRIGGER_TOKEN>",
    "ref": "main",
    "variables": {
      "PACT_CONSUMER_VERSION": "${pactbroker.consumerVersionNumber}",
      "PACT_CONSUMER_BRANCH": "${pactbroker.consumerVersionBranch}"
    }
  }
```

This creates the full **bidirectional feedback loop**:

```
Consumer pushes pact
  → Broker webhook fires
    → Provider pipeline triggered
      → pact-verify runs
        → Results published to broker
          → Consumer's can-i-deploy now has an answer
```

---

## Environment Tracking in the Broker

The `record-deployment` call after each successful deploy tells the broker
which version is live in each environment. This is what makes `can-i-deploy`
meaningful — it compares against the **actual deployed version**, not just
the latest verified version.

```
Broker state after both services deploy:

  OrderService  v abc1234  → production ✅
  ProductService v def5678  → production ✅

  Verified matrix:
    OrderService@abc1234 × ProductService@def5678 = ✅ compatible
```

If a new consumer version hasn't been verified against the production
provider yet, `can-i-deploy` returns a "still pending" status and retries
(controlled by `--retry-while-unknown` and `--retry-interval`).

---

## Local vs Hosted Broker

| Option | Best for | Notes |
|--------|----------|-------|
| Self-hosted (this repo) | Demo, internal teams | Run `make up`, broker at `localhost:9292` |
| [PactFlow](https://pactflow.io) | Production use | Managed, includes network diagram, webhooks UI, analytics. Free tier available. |

To switch to PactFlow, replace `--broker-username/password` with
`--broker-token` in both `.gitlab-ci.yml` files and update
`PACT_BROKER_BASE_URL` to your PactFlow org URL.
