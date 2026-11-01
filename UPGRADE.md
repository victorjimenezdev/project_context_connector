# Upgrade from 1.x to 2.x

## Requirements

- PHP 8.3 or later. 1.x allowed PHP 8.1.
- Drupal 10.6, 11.4 or later. Drupal 12 status is in `README.md`.

## Steps

1. Update the code with Composer.
2. Run `drush updatedb`. Update 20001 adds the new settings.
3. Rebuild caches. The Drush command and routes have moved.
4. Read the checks below.

## What the update keeps

Update 20001 keeps the CORS setting, origins, rate limits, cache age and exposure
values. It sets `expose_paths` and `expose_custom_modules` to TRUE, because 1.x
always exposed them. It adds `failed_auth_threshold` (20) and
`failed_auth_window` (300).

## Checks for existing sites

- The 1.x install default was CORS on with `https://example.com`. Remove that
  origin if you did not choose it.
- Origins are now strict. A value with a path, userinfo or invalid host is
  ignored at runtime and fails configuration import. Correct such values.
- Set each exposure setting to FALSE unless a client needs that data.

## Behavior changes

- Routes accept GET and HEAD only. OPTIONS no longer returns a snapshot.
- Responses are never public and page caches do not store them.
- The quota now covers both routes and has a pool for each IP address, so a client
  that used both routes has a lower total. Independent keys and accounts behind one
  IP address also share one allowance. Raise `rate_limit_threshold` if needed.
- `status_counts` in `update_status` now always lists every status, with zero
  counts. A client must not treat a listed status as a status that some project has.
- `examples/curl-signed.sh` and the README example now need `php` to sign, so the
  secret stays out of process arguments.
- Repeated denied requests now return 429.
- The standard route accepts cookie and Basic Auth only. A site that used OAuth
  on this route must add that provider to the route with its own route
  subscriber and test it. The module makes no OAuth claim.

## Tool API users

Enable the `project_context_connector_tool` submodule. The tool ID is unchanged.
It now requires the snapshot permission. Its `snapshot` output is now the
structured data. The Markdown text and the `raw_data` output are removed.

## Code that uses module classes

- `SignatureSubscriber` is removed. `SignatureValidator::isValid()` now takes the
  request.
- `RateLimiter` methods changed.
- The Drush command moved to `src/Drush/Commands` and is now a Symfony Console
  command. `drush.services.yml` is removed. The `pcc:snapshot` name, `pcc` alias
  and `--pretty` option are unchanged.

## Branch policy

- 2.x is the development branch. It raises the PHP floor and changes defaults.
- 1.x receives security and maintenance fixes only.
- Drupal 10.6 support on 2.x is temporary and ends with Drupal 10 support.
