# Release process

## Before tagging

1. Update [CHANGELOG.md](CHANGELOG.md).
2. Run `make release-check` (style, PHPStan, tests, coverage).
3. Complete the [Security release checklist (12.4.1)](SECURITY.md#release-security-checklist-1241) in `docs/SECURITY.md`.

## Tag and publish

```bash
git tag -a vX.Y.Z -m "Release vX.Y.Z"
git push origin vX.Y.Z
```

GitHub Actions (`release.yml`, `sync-releases.yml`) creates or updates the GitHub Release.

## After releasing

- Verify Packagist picks up the new tag.
- Update demo apps if needed.

After creating the release commit and tag, run `make check-no-cursor-coauthor` again **before** `git push` (REQ-GIT-001). The release commit itself is not covered by an earlier `release-check` run.
