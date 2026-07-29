# Makefile for API Studio Bundle

COMPOSE_FILE := docker-compose.yml
# Prefer Compose V2 with absolute docker path (avoid ./docker/ shadowing when PATH has ".") (REQ-MAKE-010).
DOCKER_BIN := $(shell command -v docker 2>/dev/null)
ifeq ($(DOCKER_BIN),)
COMPOSE_BIN := docker-compose
else
COMPOSE_BIN := $(shell $(DOCKER_BIN) compose version >/dev/null 2>&1 && echo "$(DOCKER_BIN) compose" || echo "docker-compose")
endif
COMPOSE     := $(COMPOSE_BIN) -f $(COMPOSE_FILE)
SERVICE_PHP := php

.PHONY: help up down down-dev build shell install assets test test-coverage cs-check cs-fix qa clean release-check release-check-demos composer-sync rector rector-dry phpstan update validate validate-translations check-no-cursor-coauthor check-open-prs strip-cursor-coauthor-from-history setup-hooks demo-smoke

help:
	@echo "API Studio Bundle - Development Commands"
	@echo ""
	@echo "  up down down-dev build shell install assets"
	@echo "  test test-coverage demo-smoke cs-check cs-fix rector rector-dry phpstan qa"
	@echo "  validate-translations release-check release-check-demos composer-sync"
	@echo "  check-open-prs Fail if unresolved open GitHub PRs remain (REQ-REL-003)"
	@echo "  down-dev      Stop root container (non-destructive; --remove-orphans)"
	@echo ""
	@echo "Demos: make demo-smoke | make -C demo up-symfony8"

build:
	$(COMPOSE) build --no-cache

up:
	$(COMPOSE) build
	$(COMPOSE) up -d
	@echo "Installing dependencies..."
	$(COMPOSE) exec -T $(SERVICE_PHP) composer install --no-interaction
	@echo "Container ready."

down:
	$(COMPOSE) down

down-dev:
	@$(COMPOSE) down --remove-orphans

shell:
	$(COMPOSE) exec $(SERVICE_PHP) sh

install: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer install

ensure-up:
	@if ! $(COMPOSE) exec -T $(SERVICE_PHP) true 2>/dev/null; then \
		echo "Starting container..."; \
		$(COMPOSE) up -d; \
		sleep 3; \
		$(COMPOSE) exec -T $(SERVICE_PHP) composer install --no-interaction; \
	fi

test: ensure-up
	$(COMPOSE) exec $(SERVICE_PHP) composer test

test-coverage: ensure-up
	$(COMPOSE) exec $(SERVICE_PHP) composer test-coverage | tee coverage-php.txt
	./.scripts/php-coverage-percent.sh coverage-php.txt

cs-check: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer cs-check

cs-fix: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer cs-fix

rector: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer rector

rector-dry: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer rector-dry

phpstan: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer phpstan

qa: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer qa

update: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer update --no-interaction

validate: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer validate --strict

validate-translations: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) php vendor/bin/yaml-lint src/Resources/translations

release-check: ensure-up check-no-cursor-coauthor check-open-prs composer-sync cs-fix cs-check rector-dry phpstan test-coverage validate-translations release-check-demos

# REQ-TEST-011 — boot demo stack and assert one HTTP 200
demo-smoke:
	@$(MAKE) -C demo/symfony8 up
	@PORT=$$(grep "^PORT=" demo/symfony8/.env 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT" ] && PORT=$$(grep "^PORT=" demo/symfony8/.env.example 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT" ] && PORT=8023; \
	echo "Smoke GET http://localhost:$$PORT/"; \
	code=$$(curl -fsS -o /dev/null -w "%{http_code}" "http://localhost:$$PORT/" || true); \
	if [ "$$code" != "200" ]; then echo "demo-smoke failed: HTTP $$code"; exit 1; fi; \
	echo "demo-smoke OK (HTTP 200)"

release-check-demos:
	@$(MAKE) -C demo release-verify

composer-sync: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer validate --strict
	$(COMPOSE) exec -T $(SERVICE_PHP) composer update --no-install

clean: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) sh -c "rm -rf vendor .phpunit.cache coverage coverage.xml coverage-php.txt .php-cs-fixer.cache"

assets: ensure-up
	@$(COMPOSE) exec -T -e CI=true $(SERVICE_PHP) pnpm install
	@$(COMPOSE) exec -T -e CI=true $(SERVICE_PHP) pnpm run build

setup-hooks:
	@chmod +x .githooks/pre-commit 2>/dev/null || true
	@chmod +x .githooks/commit-msg 2>/dev/null || true
	@git config core.hooksPath .githooks
	@echo "✅ Git hooks installed (.githooks — includes commit-msg for REQ-GIT-001)."

# REQ-MAKE-008: update-deps (optional when cloned outside the bundles monorepo)
BUNDLE_ROOT := $(abspath $(dir $(lastword $(MAKEFILE_LIST))))
UPDATE_DEPS_MK := $(BUNDLE_ROOT)/../.scripts/Makefile.update-deps.mk
ifneq (,$(wildcard $(UPDATE_DEPS_MK)))
include $(UPDATE_DEPS_MK)
endif
check-no-cursor-coauthor:
	@chmod +x .scripts/check-no-cursor-coauthor.sh
	@./.scripts/check-no-cursor-coauthor.sh HEAD

check-open-prs:
	@chmod +x .scripts/check-open-prs.sh
	@bash .scripts/check-open-prs.sh

strip-cursor-coauthor-from-history:
	@chmod +x .scripts/strip-cursor-coauthor-from-history.sh
	@./.scripts/strip-cursor-coauthor-from-history.sh main
