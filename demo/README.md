# Demo

```bash
make up-symfony8
```

Open http://localhost:8023 (set `PORT` to override).

## FrankenPHP mode

The Symfony 8 demo runs on FrankenPHP. Choose the runtime mode with `FRANKENPHP_MODE` in `demo/symfony8/.env` (see `.env.example`):

| Value | Behavior |
|-------|----------|
| `worker` (default) | Long-lived PHP workers (`php_server { worker ... }`) |
| `classic` | One PHP process per request — easier hot-reload while developing |

After changing the mode, recreate the container:

```bash
cd demo/symfony8 && docker compose up -d
```

Then:

```bash
docker compose exec php php bin/console nowo:api-studio:sync-schema
docker compose exec php php bin/console nowo:api-studio:seed-demo
docker compose exec php php bin/console assets:install
```

Dashboard: http://localhost:8023/api-studio
