# Changelog

## Unreleased (2.0.0)

### Security

- Fixed signed route access. The route named a class that Drupal could not build.
  It now uses a tagged access check service.
- OPTIONS no longer returns a snapshot on either route.
- Page Cache and Dynamic Page Cache no longer store snapshot routes, so cached
  responses cannot skip authorization, revocation or quota.
- Origin checks now compare scheme, host and effective port, and reject userinfo,
  paths, queries, fragments and malformed hosts.
- Added a failed-authorization limit. The Tool plugin now requires the snapshot
  permission and returns no exception detail.
- Disabled exposure settings now stop collection as well as output.
- Fresh installs have CORS off, no origins and a minimal exposure profile.

### Added

- Settings `expose_paths`, `expose_custom_modules`, `failed_auth_threshold` and
  `failed_auth_window`, with typed constraints that also apply to configuration
  import.
- JSON Schema, `docs/API.md`, `UPGRADE.md` and update status freshness.
- `include_security_updates` tool input, which was advertised but absent.
- Test site scripts and functional tests with real HTTP requests.

### Changed

- Requires PHP 8.3. Tested core versions are listed in `README.md`.
- One quota now covers both routes, with a shared pool for each IP address and
  another for each user or key. Clients behind one IP address share an allowance.
- The failed-authorization block runs on the resolved route, so encoded, prefixed
  and aliased paths cannot avoid it. Page cache policy decodes paths as core does.
- `status_counts` always lists every status, so it is an object in all outputs.
- Signing examples read the secret from the environment in PHP, not from arguments.
- `cache_max_age` of 0 now stays 0.
- Update data is read without `update_get_available()`, so no fetch task is queued.
- Composer attribution uses install paths, so submodules name the right package.
- The Tool plugin moved to the `project_context_connector_tool` submodule.
- The Drush command moved to `src/Drush/Commands` and is now a Symfony Console
  command, because Drush 14 deprecates `DrushCommands`.
- Tests use PHPUnit attributes. PHPStan runs at level 5 with no plugin exclusion.
  Base analysis leaves out the optional adapter. `phpstan-adapter.neon` analyzes
  it where Tool is installed.

### Fixed

- `examples/curl-signed.sh` and the README no longer overwrite the shell `PATH`.
- `.github/workflows/ci.yml` no longer names a missing PHPStan file or unsupported
  versions.

### Removed

- Unverified OAuth claims, `SignatureSubscriber`, `drush.services.yml`,
  `phpunit.xml.dist` and `CI_FIX_SUMMARY.md`.

Earlier 1.x changes are in the Git history of the 1.x branch.
