# Installation

```bash
composer require nowo-tech/api-studio-bundle
```

This also installs:

- **[UiKitBundle](https://github.com/nowo-tech/UiKitBundle)** (`nowo-tech/ui-kit-bundle` `^1.4`) — admin Twig macros and `nowo-ui.css` (REQ-UI-001-kit)
- **[FormKitBundle](https://github.com/nowo-tech/FormKitBundle)** (`nowo-tech/form-kit-bundle` `^2.0`) — dashboard/admin Symfony forms (`FormOptionsTrait`, profile `api_studio`)
- **Twig Extra** (`twig/extra-bundle` + `twig/string-extra` `^3.12`) — required (REQ-TWIG-004)

Register in `config/bundles.php` (Flex usually does this):

```php
Nowo\ApiStudioBundle\ApiStudioBundle::class => ['all' => true],
Nowo\UiKitBundle\NowoUiKitBundle::class => ['all' => true],
Nowo\FormKitBundle\NowoFormKitBundle::class => ['all' => true],
Twig\Extra\TwigExtraBundle\TwigExtraBundle::class => ['all' => true],
```

Optional host YAML for FormKit: `config/packages/nowo_form_kit.yaml`. If omitted, Api Studio prepends the `api_studio` profile (and aligns `css_framework` from `nowo_api_studio.ui.css_framework` when the host has not set FormKit keys).

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

## Twig Extra Bundle (REQ-TWIG-004)

This package ships Twig templates. Host applications **must** install and enable Twig Extra (pulled by Composer with this package):

```bash
composer require twig/extra-bundle twig/string-extra
```

Register `Twig\Extra\TwigExtraBundle\TwigExtraBundle` in `config/bundles.php` (Flex usually does this). Demos already include the same stack. The package `release-check` runs `make check-twig-extra` to guard this contract.
