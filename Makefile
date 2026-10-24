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

.PHONY: help up down down-dev build shell install assets test test-coverage test-ts cs-check cs-fix qa clean release-check release-check-demos composer-sync rector rector-dry phpstan igor update validate validate-translations check-no-cursor-coauthor check-open-prs check-no-raw-html-form strip-cursor-coauthor-from-history setup-hooks demo-smoke check-twig-extra

help:
	@echo "API Studio Bundle - Development Commands"
	@echo ""
	@echo "Usage: make <target>"
	@echo ""
	@echo "Targets:"
	@echo "  up            Start Docker container"
	@echo "  down          Stop Docker container"
	@echo "  down-dev      Stop root container (non-destructive; --remove-orphans)"
	@echo "  build         Rebuild Docker image (no cache)"
	@echo "  shell         Open shell in container"
	@echo "  install       Install Composer dependencies"
	@echo "  assets        Build the TypeScript assets (pnpm)"
	@echo "  test          Run PHPUnit tests"
	@echo "  test-coverage Run PHPUnit with code coverage"
	@echo "  test-ts       TypeScript type check (tsc --noEmit; no Vitest suite)"
	@echo "  cs-check      Check code style"
	@echo "  cs-fix        Fix code style"
	@echo "  rector        Apply Rector refactoring"
	@echo "  rector-dry    Run Rector in dry-run mode"
	@echo "  phpstan       Run PHPStan static analysis"
	@echo "  igor          Run Igor worker-state audit (REQ-CS-008)"
	@echo "  qa            Run all QA checks (cs-check + test)"
	@echo "  release-check Pre-release pipeline (checks, CS, Rector, PHPStan, Igor, coverage, TS check, demos)"
	@echo "  composer-sync Validate composer.json and align composer.lock (no install)"
	@echo "  clean         Remove vendor and caches"
	@echo "  update        Update composer.lock (composer update)"
	@echo "  update-deps   Update bundle and demo dependencies (REQ-MAKE-008)"
	@echo "  validate      Run composer validate --strict"
	@echo ""
	@echo "Bundle-specific:"
	@echo "  validate-translations Check translation YAML key parity (REQ-MAKE-004)"
	@echo "  twig-lint     Lint Twig templates"
	@echo "  check-twig-extra  Twig Extra gate (REQ-TWIG-004)"
	@echo "  check-open-prs Fail if unresolved open GitHub PRs remain (REQ-REL-003)"
	@echo "  check-no-raw-html-form Fail on raw <form>/<input>/<textarea>/<select> in Twig (REQ-TWIG-005)"
	@echo "  setup-hooks   Install git hooks"
	@echo ""
	@echo "Demos: make demo-smoke | make -C demo up-symfony8"
	@echo ""

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
	@$(COMPOSE) exec -T $(SERVICE_PHP) php .scripts/validate-translations.php


check-twig-extra:
	@chmod +x .scripts/check-twig-extra.sh
	@./.scripts/check-twig-extra.sh


# Run Igor worker-state audit (REQ-CS-008)
igor: ensure-up
	$(COMPOSE) exec -T php composer igor

# Pre-release (REQ-MAKE-002)
# check-no-raw-html-form (REQ-TWIG-005) is wired once the remaining raw form markup is migrated
release-check: ensure-up check-no-cursor-coauthor check-open-prs check-twig-extra validate-translations composer-sync cs-fix cs-check rector-dry phpstan igor test-coverage release-check-demos test-ts

# REQ-TEST-011 — boot demo stack and assert one HTTP 200
demo-smoke:
	@$(MAKE) -C demo/symfony8 up
	@PORT=$$(grep "^PORT=" demo/symfony8/.env 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT" ] && PORT=$$(grep "^PORT=" demo/symfony8/.env.example 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT" ] && PORT=8023; \
	echo "Smoke GET http://localhost:$$PORT/"; \
	code=000; \
	for i in 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15; do \
		code=$$(curl -fsS -o /dev/null -w "%{http_code}" --max-time 10 "http://localhost:$$PORT/" 2>/dev/null || true); \
		if [ "$$code" = "200" ]; then echo "demo-smoke OK (HTTP 200)"; exit 0; fi; \
		sleep 2; \
	done; \
	echo "demo-smoke failed: HTTP $$code"; \
	$(MAKE) -C demo/symfony8 logs 2>/dev/null || true; \
	exit 1

release-check-demos:
	@$(MAKE) -C demo release-verify

composer-sync: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer validate --strict
	$(COMPOSE) exec -T $(SERVICE_PHP) composer install --no-interaction --prefer-dist

clean: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) sh -c "rm -rf vendor .phpunit.cache coverage coverage.xml coverage-php.txt .php-cs-fixer.cache"

assets: ensure-up
	@$(COMPOSE) exec -T -e CI=true $(SERVICE_PHP) pnpm install
	@$(COMPOSE) exec -T -e CI=true $(SERVICE_PHP) pnpm run build

test-ts: ensure-up
	@$(COMPOSE) exec -T -e CI=true $(SERVICE_PHP) pnpm install
	@$(COMPOSE) exec -T $(SERVICE_PHP) pnpm run typecheck
	@echo "TypeScript check OK (no Vitest suite in this bundle)."

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

# Fail on raw <form>/<input>/<textarea>/<select> in Twig (REQ-TWIG-005)
check-no-raw-html-form:
	@bash .scripts/check-no-raw-html-form.sh

check-open-prs:
	@chmod +x .scripts/check-open-prs.sh
	@bash .scripts/check-open-prs.sh

strip-cursor-coauthor-from-history:
	@chmod +x .scripts/strip-cursor-coauthor-from-history.sh
	@./.scripts/strip-cursor-coauthor-from-history.sh main

twig-lint: ensure-up
	@$(COMPOSE) exec -T $(SERVICE_PHP) composer twig:lint || $(COMPOSE) exec -T $(SERVICE_PHP) ./vendor/bin/twig-cs-fixer lint --config=.twig-cs-fixer.php
