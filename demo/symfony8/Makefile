.PHONY: help up down build install shell logs test test-coverage cache-clear reset-demo link-bundle update-bundle ensure-up restart release-check

help:
	@echo "Symfony 8 Demo - API Studio Bundle"

up:
	@if [ ! -f .env ]; then cp .env.example .env 2>/dev/null || true; fi
	docker-compose build
	docker-compose up -d
	@echo "Waiting for database..."
	@sleep 8
	@echo "Installing dependencies..."
	@docker-compose exec -T -e COMPOSER_MEMORY_LIMIT=-1 php sh -c '\
		if [ ! -f vendor/autoload.php ] || [ ! -d vendor/phpunit/phpunit/src ]; then \
			rm -rf vendor; \
		fi; \
		if [ -f composer.lock ]; then \
			composer install --no-interaction --no-scripts; \
		else \
			composer update --no-interaction --no-scripts; \
		fi'
	@$(MAKE) link-bundle
	@docker-compose exec -T php composer run-script post-install-cmd --no-interaction
	@docker-compose exec -T php php bin/console nowo:api-studio:sync-schema
	@docker-compose exec -T php php bin/console assets:install --no-interaction
	@$(MAKE) reset-demo
	@PORT=$$(grep "^PORT=" .env 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT" ] && PORT=$$(grep "^PORT=" .env.example 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	echo "Demo started at: http://localhost:$$PORT"

down:
	docker-compose down

restart: ensure-up
	docker-compose restart

build:
	docker-compose build --no-cache

ensure-up:
	@if ! docker-compose exec -T php true 2>/dev/null; then docker-compose up -d; sleep 3; fi

install: ensure-up
	@docker-compose exec -T -e COMPOSER_MEMORY_LIMIT=-1 php sh -c '\
		if [ ! -f vendor/autoload.php ] || [ ! -d vendor/phpunit/phpunit/src ]; then rm -rf vendor; fi; \
		composer install --no-interaction 2>/dev/null || composer update --no-interaction'

shell: ensure-up
	docker-compose exec php sh

logs:
	docker-compose logs -f

test: ensure-up
	docker-compose exec -e APP_ENV=test php composer test

test-coverage: ensure-up
	docker-compose exec php composer test-coverage

cache-clear: ensure-up
	docker-compose exec php php bin/console cache:clear

reset-demo: ensure-up
	docker-compose exec -T php php bin/console nowo:api-studio:seed-demo --fresh --no-interaction

link-bundle: ensure-up
	docker-compose exec php sh -c "mkdir -p vendor/nowo-tech && rm -rf vendor/nowo-tech/api-studio-bundle && ln -s /var/api-studio-bundle vendor/nowo-tech/api-studio-bundle && php bin/console cache:clear"

update-bundle: ensure-up
	@docker-compose exec -T -e COMPOSER_MEMORY_LIMIT=-1 php sh -c '\
		composer update nowo-tech/api-studio-bundle --no-interaction --no-scripts || true'
	@$(MAKE) link-bundle
	@docker-compose exec -T php composer dump-autoload --no-interaction
	@docker-compose exec -T php php bin/console cache:clear --env=dev --no-interaction
	@docker-compose exec -T php php bin/console assets:install --no-interaction || true

release-check: ensure-up update-bundle test test-coverage

# REQ-MAKE-008: update-deps (REQ-MAKE-008)
SERVICE_PHP := php
include $(abspath $(dir $(lastword $(MAKEFILE_LIST)))/../../../.scripts/Makefile.demo-update-deps.mk)
