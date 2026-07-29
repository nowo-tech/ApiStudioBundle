# Prefer Compose V2; absolute docker path avoids shadowing by demo/symfony8/docker/ when PATH has "." (REQ-MAKE-010).
DOCKER_BIN := $(shell command -v docker 2>/dev/null)
ifeq ($(DOCKER_BIN),)
COMPOSE ?= docker-compose
else
COMPOSE ?= $(DOCKER_BIN) compose
endif

.PHONY: help up down build install shell logs test test-coverage cache-clear reset-demo link-bundle update-bundle ensure-up restart release-check setup verify

help:
	@echo "Symfony 8 Demo - API Studio Bundle"

up:
	@if [ ! -f .env ]; then cp .env.example .env 2>/dev/null || true; fi
	$(COMPOSE) up -d
	@echo "Waiting for database..."
	@sleep 5
	@echo "Installing dependencies..."
	@$(COMPOSE) exec -T -e COMPOSER_MEMORY_LIMIT=-1 php sh -c '\
		if [ ! -f vendor/autoload.php ] || [ ! -d vendor/phpunit/phpunit/src ]; then \
			rm -rf vendor; \
		fi; \
		if [ -f composer.lock ]; then \
			composer install --no-interaction --no-scripts; \
		else \
			composer update --no-interaction --no-scripts; \
		fi'
	@$(MAKE) link-bundle
	@$(COMPOSE) exec -T php composer run-script post-install-cmd --no-interaction
	@$(COMPOSE) exec -T php php bin/console nowo:api-studio:sync-schema
	@$(COMPOSE) exec -T php php bin/console assets:install --no-interaction
	@$(MAKE) reset-demo
	@PORT=$$(grep "^PORT=" .env 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT" ] && PORT=$$(grep "^PORT=" .env.example 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	echo "Demo started at: http://localhost:$$PORT"

down:
	$(COMPOSE) down

restart: ensure-up
	$(COMPOSE) restart

build:
	$(COMPOSE) build --no-cache

ensure-up:
	@if ! $(COMPOSE) exec -T php true 2>/dev/null; then $(COMPOSE) up -d; sleep 3; fi

install: ensure-up
	@$(COMPOSE) exec -T -e COMPOSER_MEMORY_LIMIT=-1 php sh -c '\
		if [ ! -f vendor/autoload.php ] || [ ! -d vendor/phpunit/phpunit/src ]; then rm -rf vendor; fi; \
		composer install --no-interaction 2>/dev/null || composer update --no-interaction'

shell: ensure-up
	$(COMPOSE) exec php sh

logs:
	$(COMPOSE) logs -f

test: ensure-up
	$(COMPOSE) exec -e APP_ENV=test php composer test

test-coverage: ensure-up
	$(COMPOSE) exec php composer test-coverage

cache-clear: ensure-up
	$(COMPOSE) exec php php bin/console cache:clear

reset-demo: ensure-up
	$(COMPOSE) exec -T php php bin/console nowo:api-studio:seed-demo --fresh --no-interaction

link-bundle: ensure-up
	$(COMPOSE) exec php sh -c "mkdir -p vendor/nowo-tech && rm -rf vendor/nowo-tech/api-studio-bundle && ln -s /var/api-studio-bundle vendor/nowo-tech/api-studio-bundle && php bin/console cache:clear"

update-bundle: ensure-up
	@$(COMPOSE) exec -T -e COMPOSER_MEMORY_LIMIT=-1 php sh -c '\
		composer update nowo-tech/api-studio-bundle --no-interaction --no-scripts || true'
	@$(MAKE) link-bundle
	@$(COMPOSE) exec -T php composer dump-autoload --no-interaction
	@$(COMPOSE) exec -T php php bin/console cache:clear --env=dev --no-interaction
	@$(COMPOSE) exec -T php php bin/console assets:install --no-interaction || true

setup: ensure-up
	@$(COMPOSE) exec -T php php bin/console nowo:api-studio:sync-schema
	@$(COMPOSE) exec -T php php bin/console assets:install --no-interaction
	@$(MAKE) reset-demo

verify: release-check

release-check: ensure-up update-bundle test test-coverage

# REQ-MAKE-008: update-deps (optional outside monorepo)
SERVICE_PHP := php
DEMO_UPDATE_DEPS_MK := $(abspath $(dir $(lastword $(MAKEFILE_LIST)))/../../../.scripts/Makefile.demo-update-deps.mk)
ifneq (,$(wildcard $(DEMO_UPDATE_DEPS_MK)))
include $(DEMO_UPDATE_DEPS_MK)
endif
