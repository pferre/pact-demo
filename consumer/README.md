# OrderService — Consumer
## Symfony 7 · PHP 8.3 · pact-php v10

The **OrderService** is the consumer microservice in this PACT contract testing demo.
It creates orders by calling the **ProductService** over HTTP, and publishes
`order.created` events to **RabbitMQ** for downstream services to consume.

It owns **both sides of the contract** it participates in:
- It **generates** HTTP pacts against ProductService
- It **generates** message pacts describing the shape of events it publishes

---

## Prerequisites

This service is designed to run as part of the `pact-demo` monorepo stack.
When running in isolation (e.g. in CI), ensure the following are available:

| Dependency | Purpose |
|------------|---------|
| PHP 8.3 | Runtime |
| Composer | Dependency management |
| RabbitMQ | Message broker (runtime only, not needed for PACT tests) |
| PACT Broker | Stores and serves pact files |

---

## Local Development (within the monorepo)

Start the full stack from the repo root:

```bash
make up
make install
```

The consumer is available at **http://localhost:8001**.

---

## API Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/api/orders` | Create an order (calls ProductService internally) |
| `GET` | `/api/orders/health` | Health check |

### Example

```bash
curl -X POST http://localhost:8001/api/orders
# Returns: { "order_id": "ORD-...", "product_id": 1, "name": "Widget", "price": 9.99, "status": "created" }

curl http://localhost:8001/api/orders/health
# Returns: { "service": "consumer", "status": "ok" }
```

---

## Project Structure

```
consumer/
├── Dockerfile
├── composer.json
├── .env                            # Default env config
├── .gitlab-ci.yml                  # CI pipeline (GitLab.com runners)
├── .gitlab-ci.self-hosted.yml      # CI pipeline (self-hosted runners)
│
├── src/
│   ├── Controller/
│   │   └── OrderController.php         # POST /api/orders, GET /api/orders/health
│   ├── Service/
│   │   └── ProductServiceClient.php    # HTTP client → ProductService
│   ├── Message/
│   │   └── OrderCreatedMessage.php     # DTO for the order.created event
│   └── Publisher/
│       └── OrderEventPublisher.php     # Publishes order.created to RabbitMQ
│
├── tests/Contract/
│   ├── ProductServiceConsumerTest.php  # HTTP pact consumer test
│   └── OrderCreatedMessageTest.php     # Message pact consumer test
│
└── pacts/                              # Generated pact files (committed)
    ├── OrderService-ProductService.json
    └── OrderService-ProductService-Events.json
```

---

## PACT Contract Tests

This service participates in two PACT workflows.

### 1 — HTTP API Pact

Verifies the contract between this service and the **ProductService HTTP API**.

The test (`ProductServiceConsumerTest.php`):
1. Defines the HTTP interactions this service expects from ProductService
2. Spins up a PACT mock server
3. Runs the real `ProductServiceClient` against the mock
4. Writes the pact file to `pacts/OrderService-ProductService.json`

```bash
# From the monorepo root
make test-consumer

# Or directly inside the container
php vendor/bin/phpunit tests/Contract/ProductServiceConsumerTest.php --testdox
```

### 2 — Message Pact (RabbitMQ)

Verifies the shape of the `order.created` event this service publishes to RabbitMQ.

The test (`OrderCreatedMessageTest.php`):
1. Defines the expected message fields and their types using PACT matchers
2. Asserts that the real `OrderCreatedMessage` DTO satisfies that shape
3. Writes the pact file to `pacts/OrderService-ProductService-Events.json`

> **No RabbitMQ connection is needed** — PACT tests the message shape in isolation,
> not the transport.

```bash
# From the monorepo root
make test-message-consumer

# Or directly inside the container
php vendor/bin/phpunit tests/Contract/OrderCreatedMessageTest.php --testdox
```

#### The `order.created` event shape

| Field | Type | Example |
|-------|------|---------|
| `event` | string | `order.created` |
| `orderId` | string | `ORD-abc123` |
| `customerId` | string | `CUST-001` |
| `customerEmail` | string | `customer@example.com` |
| `totalAmount` | decimal | `49.99` |
| `currency` | string | `GBP` |
| `createdAt` | string (ISO 8601) | `2024-01-01T00:00:00+00:00` |

All fields use **type matchers** — the provider verifies the shape, not hardcoded values.

### Publishing pacts to the broker

After running the consumer tests, publish the generated pact files to the PACT Broker
so the provider can fetch and verify them:

```bash
# From the monorepo root
make pact-publish          # publishes HTTP API pacts
make pact-publish-message  # publishes message pacts
```

> **Important:** always run the consumer tests before publishing. Each publish must use
> a unique `--consumer-app-version`. In CI this is the git commit SHA (`$CI_COMMIT_SHORT_SHA`).
> Re-publishing the same version with a different pact content will be rejected by the broker.

---

## Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `PROVIDER_BASE_URL` | `http://provider:80` | URL of the ProductService (runtime) |
| `PACT_BROKER_BASE_URL` | `http://pact-broker:9292` | PACT Broker URL |
| `PACT_BROKER_USERNAME` | `pact` | Broker basic auth username |
| `PACT_BROKER_PASSWORD` | `pact` | Broker basic auth password |
| `RABBITMQ_HOST` | `rabbitmq` | RabbitMQ hostname |
| `RABBITMQ_PORT` | `5673` | RabbitMQ AMQP port |
| `RABBITMQ_USER` | `guest` | RabbitMQ username |
| `RABBITMQ_PASSWORD` | `guest` | RabbitMQ password |

---

## CI/CD Pipeline (GitLab)

The pipeline is defined in `.gitlab-ci.yml` (GitLab.com) or `.gitlab-ci.self-hosted.yml`
(self-hosted runners). Both follow the same stage flow:

```
composer-install → unit-tests → pact-consumer-tests → pact-publish → can-i-deploy → deploy
```

| Stage | What it does |
|-------|-------------|
| `build` | `composer install` |
| `test` | Unit tests (non-contract) |
| `pact-test` | Runs PACT consumer tests, writes pact files |
| `pact-publish` | Uploads pact files to the PACT Broker, tagged with commit SHA and branch |
| `can-i-deploy` | Asks the broker: *"Has ProductService verified this pact?"* — blocks if not |
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

### The `can-i-deploy` safety gate

Before this service deploys, the pipeline asks the broker:

> *"Has the currently deployed version of ProductService verified the pacts published
> by this version of OrderService?"*

If the provider hasn't verified yet → **pipeline blocks**.
The job retries every 10 seconds for up to 60 seconds, giving the provider pipeline
time to complete its verification if it's running in parallel.
