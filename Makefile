.PHONY: help up down build restart logs shell-consumer shell-provider \
        install-consumer install-provider \
        test-consumer test-provider \
        pact-publish pact-verify pact-full-cycle

# ──────────────────────────────────────────────
# Colours
# ──────────────────────────────────────────────
CYAN  := \033[0;36m
RESET := \033[0m
NETWORK := pact-demo_pact_network


help: ## Show this help
	@echo ""
	@echo "  $(CYAN)PACT Demo — available commands$(RESET)"
	@echo ""
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  $(CYAN)%-25s$(RESET) %s\n", $$1, $$2}'
	@echo ""

# ──────────────────────────────────────────────
# Docker
# ──────────────────────────────────────────────
up: ## Start all services
	docker compose up -d

down: ## Stop all services
	docker compose down

build: ## Rebuild all images
	docker compose build --no-cache

restart: down up ## Restart all services

logs: ## Tail logs for all services
	docker compose logs -f

logs-consumer: ## Tail consumer logs
	docker compose logs -f consumer

logs-provider: ## Tail provider logs
	docker compose logs -f provider

logs-broker: ## Tail broker logs
	docker compose logs -f pact-broker

# ──────────────────────────────────────────────
# Shells
# ──────────────────────────────────────────────
shell-consumer: ## Open a shell in the consumer container
	docker compose exec consumer sh

shell-provider: ## Open a shell in the provider container
	docker compose exec provider sh

# ──────────────────────────────────────────────
# Composer
# ──────────────────────────────────────────────
install-consumer: ## Run composer install in consumer
	docker compose exec consumer composer install

install-provider: ## Run composer install in provider
	docker compose exec provider composer install

install: install-consumer install-provider ## Install deps in both services

# ──────────────────────────────────────────────
# PACT workflow
# ──────────────────────────────────────────────
test-consumer: ## Run consumer contract tests (generates pact file)
	docker compose exec consumer \
		php vendor/bin/phpunit tests/Contract --testdox

pact-publish: ## Publish consumer pacts to the broker via pact-cli container
	docker run --rm \
		--network $(NETWORK) \
		-v $(PWD)/consumer/pacts:/pacts \
		pactfoundation/pact-cli:latest \
		pact-broker publish /pacts \
			--consumer-app-version=1.0.0 \
			--broker-base-url=http://pact-broker:9292 \
			--broker-username=pact \
			--broker-password=pact

test-provider: ## Run provider verification against broker pacts
	docker compose exec provider \
		php vendor/bin/phpunit tests/Contract --testdox

pact-full-cycle: ## Run the full consumer → publish → verify cycle
	@echo "$(CYAN)Step 1: Running consumer contract tests...$(RESET)"
	@$(MAKE) test-consumer
	@echo ""
	@echo "$(CYAN)Step 2: Publishing pacts to broker...$(RESET)"
	@$(MAKE) pact-publish
	@echo ""
	@echo "$(CYAN)Step 3: Provider verifying pacts from broker...$(RESET)"
	@$(MAKE) test-provider
	@echo ""
	@echo "$(CYAN)✓ Full PACT cycle complete!$(RESET)"

# ──────────────────────────────────────────────
# Health checks
# ──────────────────────────────────────────────
health: ## Check health endpoints on both services
	@echo "Consumer:"; curl -s http://localhost:8001/api/orders/health | python3 -m json.tool
	@echo "Provider:"; curl -s http://localhost:8002/api/products/health | python3 -m json.tool
	@echo "Broker:  "; curl -s http://localhost:9292/diagnostic/status/heartbeat | python3 -m json.tool

ngrok-start: ## Expose the PACT broker publicly via ngrok (for GitLab CI webhooks)
	@echo "$(CYAN)Starting ngrok tunnel to broker on port 9292...$(RESET)"
	@echo "$(CYAN)Update PACT_BROKER_BASE_URL in GitLab CI/CD variables with the URL below$(RESET)"
	ngrok http 9292

ngrok-url: ## Print the current ngrok public URL for the broker
	@curl -s http://localhost:4040/api/tunnels \
		| python3 -c "import sys,json; tunnels=json.load(sys.stdin)['tunnels']; \
		  print(next(t['public_url'] for t in tunnels if 'https' in t['public_url']))" \
		2>/dev/null || echo "ngrok not running — run 'make ngrok-start' first"

# ──────────────────────────────────────────────
# Message PACT workflow (RabbitMQ)
# ──────────────────────────────────────────────
test-message-consumer: ## Run order.created message pact consumer test
	docker compose exec consumer \
		php vendor/bin/phpunit tests/Contract/OrderCreatedMessageTest.php --testdox

pact-publish-message: ## Publish message pacts to broker
	docker run --rm \
		--network $(NETWORK) \
		-v $(PWD)/consumer/pacts:/pacts \
		pactfoundation/pact-cli:latest \
		pact-broker publish /pacts \
			--consumer-app-version=$(shell git rev-parse --short HEAD) \
			--broker-base-url=http://pact-broker:9292 \
			--broker-username=pact \
			--broker-password=pact

test-message-provider: ## Run order.created message pact provider verification
	docker compose exec provider \
		php vendor/bin/phpunit tests/Contract/OrderCreatedMessageProviderTest.php --testdox

pact-message-cycle: ## Run full message pact cycle
	@echo "$(CYAN)Step 1: Consumer message pact test...$(RESET)"
	@$(MAKE) test-message-consumer
	@echo ""
	@echo "$(CYAN)Step 2: Publishing message pacts...$(RESET)"
	@$(MAKE) pact-publish-message
	@echo ""
	@echo "$(CYAN)Step 3: Provider verifying message pacts...$(RESET)"
	@$(MAKE) test-message-provider
	@echo ""
	@echo "$(GREEN)✓ Message PACT cycle complete!$(RESET)"

rabbitmq-ui: ## Open RabbitMQ management UI
	open http://localhost:15673
