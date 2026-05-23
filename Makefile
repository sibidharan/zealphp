# ZealPHP — developer task shortcuts.
#
# Thin wrappers over composer / vendor/bin / the `php app.php` CLI / scripts/*.sh.
# Kept in lockstep with the "Commands" section of .claude/CLAUDE.md.
# Run `make` (or `make help`) to list every target. Override knobs inline, e.g.
#   make restart PORT=9501
#   make test PHPUNIT="php -d memory_limit=512M ./vendor/bin/phpunit"

PHP       ?= php
PHPUNIT   ?= ./vendor/bin/phpunit
PHPSTAN   ?= ./vendor/bin/phpstan
INFECTION ?= ./vendor/bin/infection
PORT      ?= 8080

.DEFAULT_GOAL := help

.PHONY: help install serve start restart stop status logs \
        test unit integration stan check coverage coverage-full infection \
        docs docs-rebuild bench perf-smoke valkey-up valkey-down

help: ## List available targets
	@awk 'BEGIN {FS = ":.*## "} /^[a-zA-Z0-9_-]+:.*## / {printf "  \033[36m%-14s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)

# ---- Setup & server ----
install: ## Install PHP dependencies (incl. PHPUnit + dev tools)
	composer install

serve: ## Start the dev server in the foreground (:8080)
	$(PHP) app.php

start: ## Start the server daemonized on :8080
	$(PHP) app.php start -p $(PORT) -d

restart: ## Restart the server on :8080
	$(PHP) app.php restart -p $(PORT)

stop: ## Stop the server on :8080
	$(PHP) app.php stop -p $(PORT)

status: ## Show server status on :8080
	$(PHP) app.php status -p $(PORT)

logs: ## Tail all server logs (Ctrl+C to stop)
	$(PHP) app.php logs

# ---- Tests & quality gates ----
test: ## Run the full PHPUnit suite (server must be up for integration)
	$(PHPUNIT) --testdox

unit: ## Run unit tests only (no server needed)
	$(PHPUNIT) tests/Unit/ --testdox

integration: ## Run integration tests (requires the server on :8080)
	$(PHPUNIT) tests/Integration/ --testdox

stan: ## PHPStan static analysis (level 10, zero errors)
	$(PHPSTAN) analyse --no-progress

check: unit stan ## Pre-commit gate: unit tests + PHPStan

coverage: ## Patch coverage for the unit suite (pcov)
	bash scripts/coverage.sh

coverage-full: ## Full coverage: unit + integration across lifecycle modes
	bash scripts/coverage_full.sh

infection: ## Mutation testing (MSI) over the Unit suite
	XDEBUG_MODE=coverage $(INFECTION) --threads=4 --test-framework-options="--testsuite=Unit"

# ---- API docs ----
docs: ## Build the API reference if it's missing (public/docs/api/)
	bash scripts/build-api-docs.sh

docs-rebuild: ## Force-regenerate the API reference from scratch
	rm -rf public/docs/api && bash scripts/build-api-docs.sh

# ---- Benchmarks ----
bench: ## Local performance sweep (16 workers, concurrency up to 1000)
	bash scripts/bench.sh

perf-smoke: ## Perf-regression smoke test (requires the server on :8080)
	bash scripts/perf_smoke.sh

# ---- Redis/Valkey for Store backend tests ----
valkey-up: ## Start an isolated valkey-server for Store/Counter tests (port 16379)
	bash scripts/test-valkey-start.sh

valkey-down: ## Stop the test-only valkey-server
	bash scripts/test-valkey-stop.sh
