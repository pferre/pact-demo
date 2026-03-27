# ProductService — Provider
## Symfony 7 · PHP 8.3 · pact-php v10

The **ProductService** is the provider microservice in this PACT contract testing demo.
It exposes a product catalogue over HTTP and consumes `order.created` events
from **RabbitMQ** published by the **OrderService**.

As the provider, it **never generates pacts** — instead it fetches pacts published
by its consumers from the PACT Broker and verifies that its real implementation
satisfies every contract.

---

## Prerequisites

This service is designed to run as part of the `pact-demo` monorepo stack.
When running in isolation (e.g. in CI), ensure the following are available:

| Dependency | Purpose |
|------------|---------|
| PHP 8.3 | Runtime |
| Composer | Dependency management |
| PACT Broker | Stores and serves pact files for verification |

---

## Local Development (within the monorepo)

Start the full stack from the repo root:

```bash
make up
make install
```

The provider is available at **http://localhost:8002**.

---

## API Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/api/products` | List all products |
| `GET` | `/api/products/{id}` | Get a product by ID |
| `GET` | `/api/products/health` | Health check |

### Example

```bash
curl http://localhost:8002/api/products
# Returns: [{ "id": 1, "name": "Widget", "price": 9.99 }, ...]

curl http://localhost:8002/api/products/1
# Returns: { "id": 1, "name": "Widget", "price": 9.99 }

curl http://localhost:8002/api/products/health
# Returns: { "service": "provider", "status": "ok" }
```

---

## Project Structure

```
provider/
├── Dockerfile
├── composer.json
├── .env                                    # Default env config
├── .gitlab-ci.yml                          # CI pipeline (GitLab.com runners)
├── .gitlab-ci.self-hosted.yml              # CI pipeline (self-hosted runners)
│
├── src/
│   ├── Controller/
│   │   └── ProductController.php           # GET /api/products, GET /api/products/{id}
│   ├── Repository/
│   │   └── ProductRepository.php           # In-memory product data store
│   ├── Message/
│   │   └── OrderCreatedMessage.php         # DTO for the order.created event (consumer side)
│   └── EventHandler/
│       └── OrderCreatedHandler.php         # Handles order.created events from RabbitMQ
│
└── tests/Contract/
    ├── ProductServiceProviderTest.php       # HTTP pact provider verification
    └── OrderCreatedMessageProviderTest.php  # Message pact provider verification
```

---

## PACT Contract Verification

This service runs **two verification tests** — one for HTTP API pacts and one
for message pacts. Both fetch their contracts from the PACT Broker.

### 1 — HTTP API Pact Verification

Verifies that the ProductService HTTP API honours the contracts published by
all of its consumers (currently OrderService).

The test (`ProductServiceProviderTest.php`):
1. Fetches all pacts for `ProductService` from the PACT Broker
2. Replays each HTTP interaction against the **real running provider**
3. Asserts each response matches the consumer's expected shape
4. Publishes verification results back to the broker

```bash
# From the monorepo root
make test-provider

# Or directly inside the container
php vendor/bin/phpunit tests/Contract/ProductServiceProviderTest.php --testdox
```

### 2 — Message Pact Verification (RabbitMQ)

Verifies that this service can correctly handle the `order.created` messages
described in the message pact published by OrderService.

The test (`OrderCreatedMessageProviderTest.php`):
1. Fetches message pacts for `ProductService-Events` from the PACT Broker
2. Spins up a lightweight PHP built-in HTTP server on port **7202** as a
   message transport endpoint
3. The PACT verifier sends each message description to the transport
4. The transport responds with the matching payload
5. The verifier confirms the payload satisfies the consumer's contract
6. Publishes verification results back to the broker

> **No RabbitMQ connection is needed** — message verification is done entirely
> via the HTTP transport endpoint, not via the actual message broker.

```bash
# From the monorepo root
make test-message-provider

# Or directly inside the container
php vendor/bin/phpunit tests/Contract/OrderCreatedMessageProviderTest.php --testdox
```

#### How message verification works

```
PACT Verifier
     │
     │  POST / {"description": "an order.created event"}
     ▼
PHP built-in server (port 7202)
     │
     │  Returns matching OrderCreatedMessage payload as JSON
     ▼
PACT Verifier
     │
     │  Asserts payload matches consumer's type matchers
     ▼
Publishes result to PACT Broker
```

The `OrderCreatedHandler` in `src/EventHandler/` contains the real business
logic that would run when consuming from RabbitMQ in production. The verification
test calls it directly with the pact payload, confirming the handler can process
the expected message shape.

---

## Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `PROVIDER_BASE_URL` | `http://provider:80` | URL of this service (used by verifier) |
| `PACT_BROKER_BASE_URL` | `http://pact-broker:9292` | PACT Broker URL |
| `PACT_BROKER_USERNAME` | `pact` | Broker basic auth username |
| `PACT_BROKER_PASSWORD` | `pact` | Broker basic auth password |
| `APP_VERSION` | `local` | Version string published to broker (set to `$CI_COMMIT_SHORT_SHA` in CI) |
| `CI_COMMIT_REF_NAME` | `main` | Branch name used for broker scoping |
| `RABBITMQ_HOST` | `rabbitmq` | RabbitMQ hostname (runtime only) |
| `RABBITMQ_PORT` | `5673` | RabbitMQ AMQP port (runtime only) |

---

## CI/CD Pipeline (GitLab)

The pipeline is defined in `.gitlab-ci.yml` (GitLab.com) or `.gitlab-ci.self-hosted.yml`
(self-hosted runners). Both follow the same stage flow:

```
composer-install → unit-tests → pact-verify → can-i-deploy → deploy
```

| Stage | What it does |
|-------|-------------|
| `build` | `composer install` |
| `test` | Unit tests (non-contract) |
| `pact-verify` | Fetches pacts from broker, starts PHP built-in server, runs both verification tests |
| `can-i-deploy` | Asks the broker: *"Is this version of ProductService compatible with all consumers in production?"* |
| `deploy` | Deploys to production; records deployment in broker |

### Required GitLab CI/CD Variables

Set these under **Settings → CI/CD → Variables**:

| Variable | Description |
|----------|-------------|
| `PACT_BROKER_BASE_URL` | Broker URL — your ngrok URL for local dev (e.g. `https://abc123.ngrok-free.app`) |
| `PACT_BROKER_USERNAME` | `pact` |
| `PACT_BROKER_PASSWORD` | `pact` — mark as **Masked** |

> Do **not** re-declare these in the `variables:` block of `.gitlab-ci.yml` using
> `"${PACT_BROKER_BASE_URL}"` syntax — GitLab will pass the literal string and
> the pact-cli will fail with a URI parse error. Variables from Settings are
> automatically injected into every job.

### Webhook trigger (auto-verify on new consumer pacts)

This pipeline can be triggered automatically by the PACT Broker whenever
OrderService publishes new or changed pacts. Configure a webhook in the
Broker UI pointing at this repo's pipeline trigger URL so verification runs
immediately without waiting for a manual push.

```
OrderService publishes new pact
  → PACT Broker fires webhook
    → This pipeline triggered
      → pact-verify runs
        → Results published to broker
          → OrderService's can-i-deploy gets its answer
```

See `docs/ci-cd-flow.md` in the monorepo for full webhook setup instructions.

### The `can-i-deploy` safety gate

Before this service deploys, the pipeline asks the broker:

> *"Have all consumers that depend on ProductService had their pacts verified
> against this version?"*

If any consumer pact is unverified → **pipeline blocks**.
This prevents a provider change that would silently break a consumer from
ever reaching production.
