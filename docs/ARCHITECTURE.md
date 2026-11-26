# Architecture

## Table of contents

- [Layers](#layers)
- [Architecture checks](#architecture-checks)

## Layers

Api Studio is a standard Symfony bundle (REQ-ARCH-001): controllers, forms, services, repositories and entities, with
the extension points a host codes against kept apart from the implementation. It has no `Domain/` or `Application/`
split, so the layer check uses the two-layer ruleset of REQ-ARCH-002 rule 6.

## Architecture checks

`composer deptrac` (or `make deptrac`, also part of `make release-check` and of the CI "Static analysis" job) runs
[Deptrac](https://github.com/deptrac/deptrac) with `deptrac.yaml`: `deptrac analyse --fail-on-uncovered
--report-uncovered`, then `deptrac debug:unassigned`. It fails on a forbidden dependency, on a dependency outside every
layer and on a class under `src/` that belongs to no layer. `skip_violations` is empty.

| Deptrac layer | Namespaces (`Nowo\ApiStudioBundle\…`) | May depend on |
| --- | --- | --- |
| `PublicApi` | `Entity`, `Enum`, `Event`, `Model`, `Concurrency`, `Validator` (constraints), `Repository`, `Query` (port and value objects), `Scope` (ports and value objects), `Security` (ports, actions, subjects), `Secret` (ports and value objects), `Script\Runner` (port and value objects), `Tenancy` (tenant value objects and ports), `Search\SearchKind`, `Service\VariableSyntax`, `Privacy` (1.24 GDPR / retention ports, value objects and the purge message) | itself, PSR, `transaction-contract`, Symfony contracts, Symfony, Doctrine |
| `Internal` | everything else: controllers, forms, commands, Doctrine listeners and resolvers, the concrete services of the public namespaces (`Query\Doctrine*`, `Scope\ScopeListener`, `Scope\WorkspaceScopeMover`, `Security\ApiStudioAuthorization` and the other implementations, `Script\Runner\ProcessScriptRunner`, `Privacy\Doctrine*`, `Privacy\BatchWriter`, `Privacy\PurgeApiStudioHistoryHandler`), the UserKit bridges (`Integration\UserKit`), seeds, Twig, routing, the page factory and the composition root (`DependencyInjection`, the bundle class) | `PublicApi` and every vendor layer, including Twig, `transaction-doctrine`, UiKit, FormKit, SearchKit and UserKit |
| Vendor layers | `Psr`, `TransactionContract`, `SymfonyContracts`, `Symfony`, `Doctrine`, `Twig`, `TransactionDoctrine`, `NowoBundles` (UiKit, FormKit, SearchKit), `PhpNative` (`SortDirection`) | declared so that the ruleset states who may use them |

The rule that matters: `PublicApi` never depends on `Internal`, so a host that types against the public classes never
pulls in an implementation detail. The `@internal` classes sit in `Internal`.
