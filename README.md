# PACT Contract Testing Demo
## Symfony 7 · PHP 8.3 · Docker · pact-php v10

A minimal two-microservice demo that shows how **PACT consumer-driven contract testing**
fits into an automated delivery pipeline — covering both **HTTP API pacts** and
**async message pacts** (RabbitMQ).

```
┌──────────────────────────────────────────────────────────────┐
│                      Docker Network                          │
│                                                              │
│  ┌──────────────┐   HTTP    ┌──────────────────┐            │
│  │   Consumer   │ ────────► │    Provider      │            │
│  │ OrderService │           │  ProductService  │            │
│  │  :8001       │           │  :8002           │            │
│  └──────┬───────┘           └────────┬─────────┘            │
│         │  publish pacts             │ verify pacts          │
│         │                           │                       │
│         └──────────┐  ┌─────────────┘                       │
│                    ▼  ▼                                      │
│             ┌─────────────┐                                  │
│             │ PACT Broker │                                  │
│             │   :9292     │                                  │
│             └─────────────┘                                  │
│                                                              │
│  ┌────────────────────────────────────────────────────────┐  │
│  │  RabbitMQ  :5673 (AMQP) · :15673 (Management UI)      │  │
│  │  Consumer publishes order.created → Provider consumes  │  │
│  └────────────────────────────────────────────────────────┘  │
└──────────────────────────────────────────────────────────────┘
```

---

## Prerequisites

| Tool | Version |
|------|---------|
| Docker | ≥ 24 |
| Docker Compose | ≥ 2.20 |
| make | any |

---

## Quick Start

### 1. Clone / set up the repo

```bash
git clone <your-repo-url> pact-demo
cd pact-demo
```

> **First time only:** install Symfony dependencies inside the containers.
>
> ```bash
> make build
> make up
> make install
> ```

### 2. Start the stack

```bash
make up
```

| Service | URL |
|---------|-----|
| Consumer (Order Service) | http://localhost:8001 |
| Provider (Product Service) | http://localhost:8002 |
| PACT Broker UI | http://localhost:9292 |
| RabbitMQ Management UI | http://localhost:15673 |

Broker credentials: **pact / pact**
RabbitMQ credentials: **guest / guest**

### 3. Verify services are healthy

```bash
make health
```

---

## PACT Workflows

There are two independent PACT workflows in this demo — one for HTTP API contracts
and one for async message contracts over RabbitMQ.

---

### HTTP API Pact Workflow

Tests that the **OrderService consumer** and the **ProductService provider** agree
on HTTP request/response shapes.

#### Full cycle (one command)

```bash
make pact-full-cycle
```

#### Step by step

**Step 1 — Consumer generates the pact**

The consumer test spins up a PACT mock server, defines the interactions it expects,
runs the real `ProductServiceClient` against that mock, then writes a pact JSON file.

```bash
make test-consumer
# Pact file written to: consumer/pacts/OrderService-ProductService.json
```

**Step 2 — Publish pacts to the broker**

```bash
make pact-publish
# Pacts now visible at http://localhost:9292
```

**Step 3 — Provider verifies the pacts**

The provider test fetches published pacts from the broker and replays every
interaction against the real running provider.

```bash
make test-provider
```

---

### Message Pact Workflow (RabbitMQ)

Tests that the **shape of messages** published to RabbitMQ by the OrderService
matches what the ProductService expects to receive. Critically, **no RabbitMQ
connection is required during the PACT tests** — PACT tests the message contract
in isolation.

```
Consumer (OrderService)                Provider (ProductService)
───────────────────────────────        ───────────────────────────────────────
1. Define expected message shape       1. Fetch message pact from broker
   using MessageBuilder + Matchers
                                       2. Spin up a lightweight PHP built-in
2. Assert real OrderCreatedMessage        HTTP server as a message transport
   payload matches the shape              endpoint (port 7202)

3. Write message pact JSON to          3. Verifier calls the transport endpoint
   consumer/pacts/                        with each pact message description

4. Publish pact to broker              4. Handler returns the matching payload

                                       5. Verifier asserts payload matches
                                          the consumer contract

                                       6. Publish verification results to broker
```

The key insight: **the consumer defines the message shape it will produce;
the provider proves it can handle that shape** — without either side needing
a live RabbitMQ broker during testing.

#### Full cycle (one command)

```bash
make pact-message-cycle
```

#### Step by step

**Step 1 — Consumer generates the message pact**

```bash
make test-message-consumer
# Pact file written to: consumer/pacts/OrderService-ProductService-Events.json
```

**Step 2 — Publish message pacts to the broker**

```bash
make pact-publish-message
# Message pacts now visible at http://localhost:9292
```

> **Note:** always run `test-message-consumer` before `pact-publish-message` to
> ensure the pact file is freshly generated. Publishing with the same version
> number but a changed pact will be rejected by the broker — use a unique version
> (e.g. the git SHA) to avoid conflicts.

**Step 3 — Provider verifies the message pacts**

```bash
make test-message-provider
```

#### The `order.created` event shape

The message pact covers the following fields published by the OrderService:

| Field | Type | Example |
|-------|------|---------|
| `event` | string | `order.created` |
| `orderId` | string | `ORD-abc123` |
| `customerId` | string | `CUST-001` |
| `customerEmail` | string | `customer@example.com` |
| `totalAmount` | decimal | `49.99` |
| `currency` | string | `GBP` |
| `createdAt` | string (ISO 8601) | `2024-01-01T00:00:00+00:00` |

All fields use **type matchers** — the provider is verified against the shape,
not hardcoded example values.

---

## Project Structure

```
pact-demo/
├── docker-compose.yml              # Full stack: consumer + provider + broker + RabbitMQ
├── Makefile                        # All workflow commands
│
├── consumer/                       # Order Service
│   ├── Dockerfile
│   ├── composer.json               # includes pact-foundation/pact-php, php-amqplib
│   ├── src/
│   │   ├── Controller/
│   │   │   └── OrderController.php          # POST /api/orders
│   │   ├── Service/
│   │   │   └── ProductServiceClient.php     # HTTP client → provider
│   │   ├── Message/
│   │   │   └── OrderCreatedMessage.php      # Message DTO
│   │   └── Publisher/
│   │       └── OrderEventPublisher.php      # Publishes to RabbitMQ
│   └── tests/Contract/
│       ├── ProductServiceConsumerTest.php   ← HTTP PACT consumer test
│       └── OrderCreatedMessageTest.php      ← Message PACT consumer test
│
├── provider/                       # Product Service
│   ├── Dockerfile
│   ├── composer.json               # includes pact-foundation/pact-php
│   ├── src/
│   │   ├── Controller/
│   │   │   └── ProductController.php        # GET /api/products/{id}
│   │   ├── Repository/
│   │   │   └── ProductRepository.php        # in-memory data store
│   │   ├── Message/
│   │   │   └── OrderCreatedMessage.php      # Message DTO (provider side)
│   │   └── EventHandler/
│   │       └── OrderCreatedHandler.php      # Handles order.created events
│   └── tests/Contract/
│       ├── ProductServiceProviderTest.php   ← HTTP PACT provider verification
│       └── OrderCreatedMessageProviderTest.php ← Message PACT provider verification
│
└── pact-broker/                    # broker data volume (auto-managed)
```

---

## API Endpoints

### Consumer (Order Service) — port 8001

| Method | Path | Description |
|--------|------|-------------|
| POST | `/api/orders` | Create an order (calls provider internally) |
| GET | `/api/orders/health` | Health check |

### Provider (Product Service) — port 8002

| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/products` | List all products |
| GET | `/api/products/{id}` | Get product by ID |
| GET | `/api/products/health` | Health check |

---

## How PACT Works in This Demo

### HTTP API pacts

```
Consumer Test                        Provider Test
──────────────────────────────       ──────────────────────────────────────
1. Define expected interaction        1. Fetch pact from broker
   (request + response shape)
                                      2. Replay each interaction against
2. PACT starts mock server               the REAL running provider

3. Run real HTTP client against       3. Assert response matches the
   mock server                           contract defined by consumer

4. Write pact JSON to /pacts/         4. Publish verification results
                                         back to broker
5. Publish pact to broker
```

### Message pacts (RabbitMQ)

```
Consumer Test                        Provider Test
──────────────────────────────       ──────────────────────────────────────
1. Define expected message shape      1. Fetch message pact from broker
   (fields + type matchers)
                                      2. Start PHP built-in HTTP server
2. Assert real message DTO               as message transport (port 7202)
   satisfies the shape
                                      3. Verifier sends each message
3. Write message pact JSON               description to the transport
   to /pacts/
                                      4. Transport returns matching payload
4. Publish pact to broker
                                      5. Verifier checks payload against
                                         the consumer contract

                                      6. Publish results to broker
```

The key principle in both cases: **the consumer defines what it needs;
the provider proves it delivers it** — without the two teams needing to
coordinate live test environments.

---

## CI/CD Integration (GitLab CI)

Each service has its own `.gitlab-ci.yml` designed for **separate repos**.

### Consumer pipeline stages
```
composer-install → unit-tests → pact-consumer-tests → pact-publish → can-i-deploy → deploy
```

### Provider pipeline stages
```
composer-install → unit-tests → pact-verify → can-i-deploy → deploy
```

### The safety gate — `can-i-deploy`

Before either service deploys, the pipeline asks the broker:

> *"Is this version compatible with everything currently running in production?"*

If the provider hasn't verified the consumer's pacts yet → **pipeline blocks**.
If the provider has a breaking change → **pipeline blocks**.

### Required GitLab CI/CD variables

Set these under **Settings → CI/CD → Variables** in each repo:

| Variable | Description |
|----------|-------------|
| `PACT_BROKER_BASE_URL` | Broker URL — use your ngrok URL for local dev (e.g. `https://abc123.ngrok-free.app`) |
| `PACT_BROKER_USERNAME` | `pact` |
| `PACT_BROKER_PASSWORD` | `pact` (mark as **Masked**) |

> **Important:** do not re-declare these variables in the `variables:` block of
> your `.gitlab-ci.yml` using `"${PACT_BROKER_BASE_URL}"` syntax — GitLab will
> pass the literal string instead of the value, causing a URI parse error in the
> pact-cli. Variables set in **Settings → CI/CD → Variables** are automatically
> available in every job.

See `docs/gitlab-variables.env.example` for the full reference.

### Broker webhook (auto-trigger provider on new pacts)

Configure the broker to fire a GitLab pipeline trigger whenever the consumer
publishes new pacts — so the provider verifies immediately. Full setup in
`docs/ci-cd-flow.md`.

### Breaking change demo script

See `docs/ci-cd-flow.md` → *"The Breaking Change Scenario"* for a step-by-step
walkthrough of renaming an API field and watching the pipeline block it.

---

## Useful Commands

```bash
make help                  # list all commands
make up                    # start the stack
make down                  # stop the stack
make logs                  # tail all logs
make shell-consumer        # shell into consumer container
make shell-provider        # shell into provider container

# HTTP API pact workflow
make pact-full-cycle       # run the complete HTTP pact workflow

# Message pact workflow (RabbitMQ)
make pact-message-cycle    # run the complete message pact workflow
make test-message-consumer # generate message pact from consumer
make pact-publish-message  # publish message pacts to broker
make test-message-provider # verify message pacts on provider side

# Utilities
make health                # check all service health endpoints
make rabbitmq-ui           # open RabbitMQ management UI
make ngrok-start           # expose broker via ngrok (for GitLab CI)
make ngrok-url             # print current ngrok public URL
```
