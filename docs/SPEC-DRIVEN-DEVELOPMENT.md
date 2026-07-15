# Spec-driven development

In this repository, **spec-driven development** has three layers that stay in sync:

1. **GitHub Spec Kit baseline** — [`specs/001-baseline/`](../specs/001-baseline/) ([`spec.md`](../specs/001-baseline/spec.md), [`code-inventory.md`](../specs/001-baseline/code-inventory.md)), initialized with [GitHub Spec Kit](https://github.com/github/spec-kit) (`.specify/`, **Cursor Agent** skills in `.cursor/skills/speckit-*`). The inventory maps **100%** of production code in `src/`. **How to install, initialize, and use Spec Kit:** [`SPEC-KIT.md`](SPEC-KIT.md).
2. **Product behavior** — what **ApiStudioBundle** guarantees to applications that integrate it (see [`USAGE.md`](USAGE.md), [`CONFIGURATION.md`](CONFIGURATION.md), [`INSTALLATION.md`](INSTALLATION.md) when present). **PHPUnit** and **PHPStan** enforce contracts in CI where applicable.
3. **Traceability anchors** — stable **`REQ-*`** identifiers in Makefiles and demos (when present) so changes to scripts, ports, and demo workflows stay discoverable from issues and PRs.

There is no separate executable spec language (for example Gherkin); Spec Kit specs, tests, and static analysis are the mechanical proof alongside this document.

---

## User stories

| ID | Story |
| --- | --- |
| US-01 | **As an** API team member, **I want** to store and organize REST/SOAP/GraphQL requests in collections **so that** I can reuse them like Postman workspaces. |
| US-02 | **As a** developer, **I want** to execute requests against configured environments **so that** I can test APIs from inside Symfony. |
| US-03 | **As a** technical writer, **I want** generated API documentation **so that** consumers see up-to-date endpoint descriptions. |
| US-04 | **As an** integrator, **I want** Doctrine-backed persistence and Symfony Security integration **so that** studio data stays in my application database. |
| US-05 | **As a** maintainer, **I want** PHPUnit and PHPStan in CI **so that** request runners and parsers do not regress silently. |

---

## Bundle functional scope

**Goal:** Manage, document, and test REST, SOAP, and GraphQL APIs inside Symfony (Postman/Apidog-style studio).

**In scope**

- Documented integration (root `README.md` and `docs/`).
- Configuration and runtime behavior described in integrator docs when present.
- Consumer-facing change notes in [`CHANGELOG.md`](CHANGELOG.md) and [`UPGRADING.md`](UPGRADING.md) when applicable.

**Explicit non-goals**

- Behavior not documented here or in linked integrator docs.
- **`demo/`** trees: illustrative unless explicitly published as stable API.

---

## Validating the functional spec

- Run **`composer qa`** and/or **`make qa`** / **`make release-check`** as documented in [`CONTRIBUTING.md`](CONTRIBUTING.md) when present.
- Run **PHPUnit** and **PHPStan** in CI and locally for code changes.

---

## Requirement identifiers (`REQ-*`)

| ID | Where | What it marks |
| --- | --- | --- |
| *(none yet)* | `Makefile`, `demo/**/Makefile` | Add `REQ-*` comments next to targets when scripted behavior must stay traceable; document each ID here. |

---

## Suggested workflow for contributors

1. **Clarify behavior** in an issue or draft PR: acceptance criteria for the **product** and, if relevant, **Makefiles/demos** (`REQ-*`).
2. **Implement** with tests and static analysis.
3. **Anchor scripts and demos** when dev UX changes: add or adjust `REQ-*` comments and this table.
4. **Ship integrator docs** when behavior or configuration changes: [`USAGE.md`](USAGE.md), [`CONFIGURATION.md`](CONFIGURATION.md), [`CHANGELOG.md`](CHANGELOG.md), and [`UPGRADING.md`](UPGRADING.md) when consumers must change code or config.
5. **Keep Spec Kit artifacts in sync** when production code under `src/` changes:
   - Update [`specs/001-baseline/spec.md`](../specs/001-baseline/spec.md) and [`code-inventory.md`](../specs/001-baseline/code-inventory.md).
   - Follow the maintainer checklist in [`SPEC-KIT.md`](SPEC-KIT.md).
   - For **new features**, use Cursor Agent skills (`/speckit-specify`, `/speckit-plan`, `/speckit-tasks`) as documented in SPEC-KIT.

---

## Relationship to Engram / external checklists

[`ENGRAM.md`](ENGRAM.md) covers Nowo-wide documentation checklist items when present. This document ties together **what the package does**, **how we verify it**, and **local `REQ-*` habits**.

---

## GitHub Spec Kit (summary)

This repository uses [GitHub Spec Kit](https://github.com/github/spec-kit) with **Cursor Agent** (`cursor-agent` integration).

| Artifact | Path |
| --- | --- |
| **Operator manual** (install, init, usage) | [`SPEC-KIT.md`](SPEC-KIT.md) |
| Baseline spec | [`specs/001-baseline/spec.md`](../specs/001-baseline/spec.md) |
| Code inventory (100%) | [`specs/001-baseline/code-inventory.md`](../specs/001-baseline/code-inventory.md) |
| Constitution | [`.specify/memory/constitution.md`](../.specify/memory/constitution.md) |
| Cursor Agent skills | [`.cursor/skills/`](../.cursor/skills/) (`speckit-*`) |

**Quick start (maintainers):**

```bash
specify init --here --force --integration cursor-agent --script sh
specify integration list
```

In Cursor Agent, start a new feature with `/speckit-specify <description>`. Read **[`SPEC-KIT.md`](SPEC-KIT.md)** for full tooling details.

---

## See also

- [`SPEC-KIT.md`](SPEC-KIT.md) — GitHub Spec Kit manual (install, structure, usage)
- [`specs/001-baseline/spec.md`](../specs/001-baseline/spec.md)
- [`USAGE.md`](USAGE.md)
- [`CONFIGURATION.md`](CONFIGURATION.md)
- [`CONTRIBUTING.md`](CONTRIBUTING.md)
