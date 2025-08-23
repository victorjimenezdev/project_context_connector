
### `CHANGELOG.md`
```md
# Changelog
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog] and this project adheres to [Semantic Versioning].

## [Unreleased]
### Added
- Initial Drupal 10/11 release with JSON endpoint, Drush command, CORS allow‑list, rate‑limits, caching, tests, and docs.

## [1.0.0] - 2025-08-23
### Added
- Read‑only `/project-context-connector/snapshot` endpoint (permission‑gated).
- Drush `pcc:snapshot` outputs same JSON.
- Rate limiting via Flood API and configurable cache max‑age.
- CORS allow‑list with exact and wildcard subdomain support.
- Kernel and Unit tests; GitLab CI for PHPStan, PHPUnit, and Coder.
- SECURITY.md with Security Advisory coverage opt‑in guidance.

[Keep a Changelog]: https://keepachangelog.com/en/1.1.0/
[Semantic Versioning]: https://semver.org/spec/v2.0.0.html
