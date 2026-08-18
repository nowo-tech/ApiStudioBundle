# Demo applications with FrankenPHP (development and production)

This document describes how the bundle's demo applications run under **FrankenPHP** in Docker, and how to reproduce **development** (no cache, changes visible on refresh) and **production** (worker mode, cache enabled) configurations.

## Table of contents

- [Contents](#contents)
- [Overview](#overview)
- [Timeouts (REQ-RUNTIME-001)](#timeouts-req-runtime-001)
- [What the demos include](#what-the-demos-include)
- [Development configuration](#development-configuration)
- [Production configuration](#production-configuration)
- [Switching between development and production](#switching-between-development-and-production)
- [Troubleshooting](#troubleshooting)

## Contents

- [Overview](#overview)
- [What the demos include](#what-the-demos-include)
- [Timeouts (REQ-RUNTIME-001)](#timeouts-req-runtime-001)
- [Development configuration](#development-configuration)
- [Production configuration](#production-configuration)
- [Switching between development and production](#switching-between-development-and-production)
- [Troubleshooting](#troubleshooting)

---

## Overview

**The `demo/` folder is not shipped when the bundle is installed** (e.g. via `composer require nowo-tech/api-studio-bundle`). It is excluded from the Composer package (via `archive.exclude` in the bundle's `composer.json`). The demo applications exist only in the bundle's source repository and are intended for development, testing, and documentation. To run or modify the demos, use a clone of the bundle repository.

The demos use:

- **FrankenPHP** (Caddy + PHP) in a single container.
- **Docker Compose** with the app and the parent bundle mounted as volumes (`../..` → `/var/api-studio-bundle`).
- **Two Caddyfiles**: `Caddyfile` (production, with worker) and `Caddyfile.dev` (development, no worker).
- An **entrypoint** script that selects the Caddyfile from **`FRANKENPHP_MODE`** (`classic` | `worker`), defined in the demo **`.env`** / `.env.example` and passed by Compose (not baked into the Dockerfile). **Default is `worker`.** Edit `.env` and run `docker compose up -d` (recreate) to switch modes without rebuilding. If unset, the entrypoint uses `worker`.
- **Symfony 8 demo on the latest PHP available** in official FrankenPHP images when constraints allow (currently **PHP 8.5** → `dunglas/frankenphp:1-php8.5-alpine`).

There is a demo for **Symfony 8** (`demo/symfony8`) with its own Dockerfile, docker-compose.yml and Makefile. From the bundle root you run e.g. `make -C demo up-symfony8` (see the demo's README for the URL and port).

**Ports:** The demo uses `PORT` from its `.env` (default **8023**; see `.env.example`).

---

## Timeouts (REQ-RUNTIME-001)

The request console executes outbound REST/GraphQL (Symfony HttpClient) and SOAP (`SoapClient`) calls. Timeouts are layered so the **innermost** deadline fires first and FrankenPHP workers are not left blocked indefinitely:

| Layer | Default | Role |
|-------|---------|------|
| `nowo_api_studio.ui.request_timeout_seconds` | **30s** (1–300) | HttpClient `timeout` and SOAP `connection_timeout` for console executions |
| PHP `max_execution_time` / `max_input_time` | Image / Caddy defaults | Must stay **greater** than the UI request timeout if you raise it |
| Caddy / FrankenPHP write / wait limits | Image defaults | Cap how long a request may occupy a worker thread |

When raising `ui.request_timeout_seconds` in application YAML, also raise PHP and Caddy write timeouts in the same change if demos or host FrankenPHP configs pin lower ceilings. See [CONFIGURATION.md](CONFIGURATION.md) and [SECURITY.md](SECURITY.md).

| Aspect | Development (`classic`) | Default / production (`worker`) |
|--------|-------------------------|----------------------------------|
| `FRANKENPHP_MODE` | **`classic`** (set explicitly) | **`worker`** (default) |
| FrankenPHP worker mode | **Off** (one PHP process per request) | **On** (workers keep app in memory) |
| Twig cache | **Off** (`config/packages/dev/twig.yaml`) when present | **On** (default) |
| OPcache revalidation | Every request (`docker/php-dev.ini`) when mounted | Default |
| HTTP cache headers | `no-store`, `no-cache` (in Caddyfile.dev) | Omitted or cache-friendly |
| `APP_ENV` / `APP_DEBUG` | `dev` / `1` | `prod` / `0` (or `dev` + worker for compatibility tests) |

---

## What the demos include

The demo applications are configured for **local development and debugging**:

- **Symfony Web Profiler** and **Debug bundle** — enabled in `dev` and `test` environments.
- **Nowo Twig Inspector** (`nowo-tech/twig-inspector-bundle`) and **Nowo Hot Reload** (`nowo-tech/hot-reload-bundle`) — required together on FrankenPHP demos (dev/test only; Caddyfile Mercure + `hot_reload`, plus `worker { watch }` in worker mode). Do not enable Hot Reload in production.
- **Api Studio Bundle** (`Nowo\ApiStudioBundle\ApiStudioBundle`) — the bundle under test; enabled in the demos.

In **production** (`APP_ENV=prod`), only bundles registered for `all` or `prod` are loaded.

---

## Development configuration

Goal: every change to PHP, Twig or config is visible on the next browser refresh without restarting the container. No long-lived PHP workers; cache disabled or revalidated on every request.

### 1. Caddyfile (development)

The development Caddyfile is **docker/frankenphp/Caddyfile.dev** in the demo. It uses plain `php_server` (no worker) and cache-busting headers. The entrypoint copies it over `/etc/frankenphp/Caddyfile` when **`FRANKENPHP_MODE=classic`**.

### 2. PHP configuration (development)

When present, **docker/php-dev.ini** with `opcache.revalidate_freq=0` is mounted for classic/dev workflows.

### 3. Twig configuration (development)

Use **config/packages/dev/twig.yaml** with `twig.cache: false` so template changes are visible on refresh.

### 4. Docker Compose (development)

The demo's **docker-compose.yml** passes `FRANKENPHP_MODE=${FRANKENPHP_MODE:-worker}` from the demo `.env` (template: `.env.example`, default **`worker`**), plus app env vars, and mounts the app, the bundle (`../..:/var/api-studio-bundle`), and the FrankenPHP Caddyfiles. The entrypoint applies classic/worker according to that variable.

### 5. Start the demo (development)

From the bundle root: `make -C demo up-symfony8`. Or from the demo directory: `make up`.

Default URL: `http://localhost:8023` (override with `PORT`). Dashboard: `/api-studio`.

---

## Production configuration

**`FRANKENPHP_MODE=worker`** is the default (worker Caddyfile with `php_server { worker /app/public/index.php }`). For a full production Symfony profile, also set `APP_ENV=prod` and `APP_DEBUG=0`, and do not mount `php-dev.ini`.

---

## Switching between development and production

- **Default / worker:** `FRANKENPHP_MODE=worker` (`.env.example` default). Entrypoint keeps the worker Caddyfile.
- **Classic (hot-reload friendly):** set `FRANKENPHP_MODE=classic` in `.env`. Entrypoint copies Caddyfile.dev (no worker, no-cache headers). Keep `APP_ENV=dev`.

After changing `.env`, run `docker compose up -d` (or `make up`) so the container is **recreated** with the new env — **no image rebuild**. A plain `restart` does not reload environment variables.

---

## Troubleshooting

- **Changes not visible:** Ensure `FRANKENPHP_MODE=classic` (Caddyfile.dev has no `worker`), add dev twig.yaml and php-dev.ini if needed, recreate the container, hard-refresh the browser.
- **Web Profiler not visible:** Check `APP_ENV=dev` and `APP_DEBUG=1`, and that WebProfilerBundle is enabled for `dev` in `bundles.php`.
- **Demo times out / port busy:** Confirm `PORT` (default 8023) is free, check `docker compose logs php`, and required env vars (e.g. `APP_SECRET`). Ensure PostgreSQL is healthy.
- **Outbound request hangs in the console:** Lower or raise `nowo_api_studio.ui.request_timeout_seconds` (default 30); ensure PHP/Caddy ceilings stay above that value in FrankenPHP setups.
- **Bundle code not updating:** Run `make update-bundle` from `demo/symfony8` so the path repo symlink and cache stay in sync.
