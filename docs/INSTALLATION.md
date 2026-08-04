# Installation

```bash
composer require nowo-tech/api-studio-bundle
```

This also installs **[UiKitBundle](https://github.com/nowo-tech/UiKitBundle)** (`nowo-tech/ui-kit-bundle` `^1.4`).

Register in `config/bundles.php` (Flex usually does this):

```php
Nowo\ApiStudioBundle\ApiStudioBundle::class => ['all' => true],
Nowo\UiKitBundle\NowoUiKitBundle::class => ['all' => true],
```

Import routes from `@NowoApiStudioBundle/Resources/config/routes.yaml` (or use the Flex recipe).

Run schema sync:

```bash
php bin/console nowo:api-studio:sync-schema
php bin/console assets:install
```

Configure access control:

```yaml
# config/packages/nowo_api_studio.yaml
nowo_api_studio:
    security:
        access_roles: [ROLE_ADMIN]
```

Optional demo seed:

```bash
php bin/console nowo:api-studio:seed-demo
```
