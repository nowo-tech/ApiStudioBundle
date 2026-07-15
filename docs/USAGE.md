# Usage

## Workspaces

Create a workspace per product, client, or team. Each workspace owns:

- **Services** — API providers (REST/SOAP/GraphQL)
- **Environments** — variable sets (`local`, `staging`, `prod`)
- **Endpoints** — individual operations

## Documenting endpoints

For each endpoint you can add:

- Translations per locale (`title`, `description`, `notes`)
- Request examples (headers, query, body)
- Response examples (status, headers, body)

## Demo seed

```bash
php bin/console nowo:api-studio:seed-demo
php bin/console nowo:api-studio:seed-demo --fresh
```

Includes reference catalogs for:

- **JSONPlaceholder** — live REST (no auth)
- **LinkedIn API v2** — profile, UGC posts, organization search
- **Google Cloud Translation** — translate, detect, list languages
- **Catastro (España)** — SOAP `Consulta_CPMRC` / `Consulta_DNPRC` and HTTP variants

Configure credentials in **Environments** before calling real APIs.

## Testing requests

Open an endpoint detail page, pick an environment, edit the body if needed, and click **Send request**. Results and timing are shown inline; executions are stored in history.

## Auth on services

Configure `auth_type` on the service:

- `none`, `basic`, `bearer`, `api_key`, `custom`
- Auth config values support `{{variables}}` from the selected environment
