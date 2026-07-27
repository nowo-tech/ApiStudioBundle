## AI contribution guidelines (Nowo Symfony bundle)

Use this when suggesting code, tests, documentation, or CI changes for **Api Studio Bundle**.

### Scope

- Symfony bundle published as `nowo-tech/api-studio-bundle` on Packagist.
- Respect **PHP >=8.2 <8.6** and **Symfony ^7.0 \|\| ^8.0** ranges in `composer.json`.
- Use **PHP 8 attributes** only. Do not introduce `doctrine/annotations`.

### Code

- Follow **PSR-12** and `.php-cs-fixer.dist.php`.
- Keep changes minimal; match patterns in `src/` and `tests/`.
- Controllers used as services must remain **public** in `services.yaml`.
- PHPDoc in **English** for non-trivial APIs.

### Tests and coverage

- Maintain **100% line coverage** on `src/` (`make test-coverage` / `composer test-coverage`).
- Run `make cs-check`, `make phpstan`, and `make test` before proposing merges.

### Documentation

- User-facing docs are **English** under `docs/`; only `README.md` at repository root.
- Document Twig overrides under `templates/bundles/NowoApiStudioBundle/` and translation domain `NowoApiStudioBundle`.

### Demos

- Demos use **FrankenPHP** (`demo/symfony8/`). See `docs/DEMO-FRANKENPHP.md`.

### Assets

- Frontend TypeScript lives in `src/Resources/assets/src/`; build with `pnpm` (`make assets`).
