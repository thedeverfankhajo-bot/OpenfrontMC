# Testing

## Automated local checks

Run these commands in a development environment:

- composer validate --no-check-publish
- composer install --no-interaction --prefer-dist
- composer test
- composer analyse
- PHP lint for all PHP files in src and tests

The test runner exercises input validation, rate limiting, deterministic map generation, economy rules, technology-tree structure, match state transitions, and SQLite-backed country, territory, economy transaction, building, research, diplomacy, and match workflows.

## PocketMine-MP integration coverage

The unit/service suite does not boot PocketMine-MP. A real API 5 test server is still required to verify plugin discovery, manifest parsing, world loading/generation, scheduler behavior, event registration, Bedrock command flow, chunk persistence, safe restart, and protection behavior in a live world. These tests are not reported as passed by the repository unit suite.

## CI

GitHub Actions validates the manifest API target, required files, PHP syntax, Composer metadata, static analysis, and the unit/service suite on pushes to main. The workflow does not require repository secrets.
