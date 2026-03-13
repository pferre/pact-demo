# PACT Contract Testing Demo
## Symfony 7 · PHP 8.3 · Docker · pact-php v10

A minimal two-microservice demo that shows how **PACT consumer-driven contract testing**
fits into an automated delivery pipeline.

```
┌─────────────────────────────────────────────────────┐
│                  Docker Network                      │
│                                                      │
│  ┌──────────────┐   HTTP    ┌──────────────────┐    │
│  │   Consumer   │ ────────► │    Provider      │    │
│  │ OrderService │           │  ProductService  │    │
│  │  :8001       │           │  :8002           │    │
│  └──────┬───────┘           └────────┬─────────┘    │
│         │  publish pacts             │ verify pacts  │
│         │                           │               │
│         └──────────┐  ┌─────────────┘               │
│                    ▼  ▼                              │
│             ┌─────────────┐                          │
│             │ PACT Broker │                          │
│             │   :9292     │                          │
│             └─────────────┘                          │
└─────────────────────────────────────────────────────┘
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

> **First time only:** pull the two Symfony skeletons into each service directory.
>
> ```bash
> # In consumer/ and provider/ — install Symfony via Composer inside the container
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

Broker credentials: **pact / pact**

### 3. Verify services are healthy

```bash
make health
```

---

## PACT Workflow

### Full cycle (one command)

```bash
make pact-full-cycle
```

This runs steps 1–3 below in sequence.

---

### Step 1 — Consumer generates the pact

The consumer test spins up a **PACT mock server**, defines the interactions
it expects from the provider, runs the real `ProductServiceClient` against
that mock, then writes a pact JSON file.

```bash
make test-consumer
# Pact file written to: consumer/pacts/OrderService-ProductService.json
```

---

### Step 2 — Publish pacts to the broker

```bash
make pact-publish
# Pacts now visible at http://localhost:9292
```

---

### Step 3 — Provider verifies the pacts

The provider test fetches published pacts from the broker and replays
every interaction against the **real running provider**, confirming it
honours the consumer contracts.

```bash
make test-provider
```

---

## Project Structure

```
pact-demo/
├── docker-compose.yml          # Full stack: consumer + provider + broker
├── Makefile                    # All workflow commands
│
├── consumer/                   # Order Service
│   ├── Dockerfile
│   ├── composer.json           # includes pact-foundation/pact-php
│   ├── src/
│   │   ├── Controller/
│   │   │   └── OrderController.php      # POST /api/orders
│   │   └── Service/
│   │       └── ProductServiceClient.php # HTTP client → provider
│   └── tests/Contract/
│       └── ProductServiceConsumerTest.php  ← PACT consumer test
│
├── provider/                   # Product Service
│   ├── Dockerfile
│   ├── composer.json           # includes pact-foundation/pact-php
│   ├── src/
│   │   ├── Controller/
│   │   │   └── ProductController.php    # GET /api/products/{id}
│   │   └── Repository/
│   │       └── ProductRepository.php   # in-memory data store
│   └── tests/Contract/
│       └── ProductServiceProviderTest.php  ← PACT provider verification
│
└── pact-broker/                # broker data volume (auto-managed)
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

```
Consumer Test                     Provider Test
─────────────────────────────     ─────────────────────────────────────
1. Define expected interaction     1. Fetch pact from broker
   (request + response shape)
                                   2. Replay each interaction against
2. PACT starts mock server            the REAL running provider

3. Run real HTTP client against    3. Assert response matches the
   mock server                        contract defined by consumer

4. Write pact JSON to /pacts/      4. Publish verification results
                                      back to broker
5. Publish pact to broker
```

The key insight: **the consumer defines what it needs; the provider proves
it delivers it** — without the two teams needing to coordinate test environments.

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

This is the key step. Before either service deploys, the pipeline asks the broker:

> *"Is this version compatible with everything currently running in production?"*

If the provider hasn't verified the consumer's pacts yet → **pipeline blocks**.
If the provider has a breaking change → **pipeline blocks**.

### Required GitLab CI/CD variables

Set these under **Settings → CI/CD → Variables** in each repo:

| Variable | Description |
|----------|-------------|
| `PACT_BROKER_BASE_URL` | Broker URL (e.g. `http://your-broker:9292`) |
| `PACT_BROKER_USERNAME` | Broker username |
| `PACT_BROKER_PASSWORD` | Broker password (**mask this**) |

See `docs/gitlab-variables.env.example` for the full reference.

### Broker webhook (auto-trigger provider on new pacts)

Configure the broker to fire a GitLab pipeline trigger whenever the consumer
publishes new pacts — so the provider verifies immediately without waiting for
Team A to manually kick off a pipeline. Full setup in `docs/ci-cd-flow.md`.

### Breaking change demo script

See `docs/ci-cd-flow.md` → *"The Breaking Change Scenario"* for a step-by-step
walkthrough of renaming an API field and watching the pipeline block it.

---

## Useful Commands

```bash
make help              # list all commands
make up                # start the stack
make down              # stop the stack
make logs              # tail all logs
make shell-consumer    # shell into consumer container
make shell-provider    # shell into provider container
make pact-full-cycle   # run the complete PACT workflow
make health            # check all service health endpoints
```
