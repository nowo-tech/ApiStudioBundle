# Demo

```bash
make up-symfony8
```

Open http://localhost:8023 (set `PORT` to override).

Then:

```bash
docker compose exec php php bin/console nowo:api-studio:sync-schema
docker compose exec php php bin/console nowo:api-studio:seed-demo
docker compose exec php php bin/console assets:install
```

Dashboard: http://localhost:8023/api-studio
