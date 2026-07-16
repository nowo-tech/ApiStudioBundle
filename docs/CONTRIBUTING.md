# Contributing

Thank you for considering contributing to Api Studio Bundle.


## Code of Conduct

This project follows the [Contributor Covenant Code of Conduct](../CODE_OF_CONDUCT.md). By participating, you are expected to uphold it. Please report unacceptable behavior to **hectorfranco@nowo.tech**.

## Development setup

1. Clone the repository and start Docker:

   ```bash
   make up
   make install
   ```

2. Run tests:

   ```bash
   make test
   make test-coverage
   ```

3. Code style and static analysis:

   ```bash
   make cs-check
   make cs-fix
   make phpstan
   make qa
   ```

## Pull requests

- Target the `main` branch.
- Run `make release-check` before opening a PR.
- Update `docs/CHANGELOG.md` for user-visible changes.
- Complete the PR template checklist.

## Reporting issues

Use GitHub issue templates. Do **not** report security vulnerabilities in public issues — see [Security](SECURITY.md).
## Git hooks (REQ-GIT-001)

Do **not** add `Co-authored-by: Cursor` or `cursoragent@cursor.com` trailers to commit messages.

```bash
make setup-hooks
make check-no-cursor-coauthor
```

`make setup-hooks` installs `.githooks/commit-msg` (or sets `core.hooksPath` to `.githooks`). Run it once per clone before your first commit.
If CI fails because trailers are already on the remote, see [GITHUB_CI.md](GITHUB_CI.md) (REQ-GIT-001) and run `make strip-cursor-coauthor-from-history` before `git push --force-with-lease`.
