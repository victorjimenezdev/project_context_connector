# Snapshot API, schema version 2.0

The schema is `schema/snapshot.v2.schema.json`. `examples/snapshot.example.json`
is a valid example. HTTP, Drush and the Tool adapter return the same structure
from one service.

## Endpoints

| Path | Method | Authorization | Cache-Control |
| --- | --- | --- | --- |
| `/project-context-connector/snapshot` | GET | `access project context snapshot`, by cookie or Basic Auth | `private, max-age=N`, or `private, no-store` when N is 0 |
| `/project-context-connector/snapshot/signed` | GET | HMAC headers | `private, no-store` |

Basic Auth needs the core `basic_auth` module. No other authentication provider
is enabled on the standard route.

## HMAC signature

- Canonical string: `METHOD` newline `PATH` newline `TIMESTAMP`. There is no
  query string and no trailing newline.
- `PATH` is the request path exactly as sent, after any site base path. It is not
  decoded or normalized. A request to `/project-context-connector/%73napshot/signed`
  or to `/es/project-context-connector/snapshot/signed` signs that text. A signature
  of another spelling of the path is denied.
- Signature: lowercase hex HMAC-SHA256 of the canonical string.
- Headers: `X-PCC-Key`, `X-PCC-Timestamp`, `X-PCC-Signature`.
- Window: the timestamp must be within 300 seconds of server time.
- Keys: `$settings['project_context_connector_api_keys'] = ['key-id' => 'secret'];`

There is no nonce. Anyone who captures a signed request can replay it to the
same method and path until its timestamp leaves the window. Use TLS.

## Status codes

| Code | Meaning |
| --- | --- |
| 200 | Snapshot returned. |
| 204 | Allowed CORS preflight. It has no body. |
| 401 | Basic Auth challenge, when `basic_auth` is installed. |
| 403 | Denied, or rejected preflight. It holds no snapshot data. |
| 429 | Quota or failed-authorization limit reached. See `Retry-After`. |

## CORS

CORS is off on a fresh install and the allow-list is empty. It applies to the
standard route only.

- A pattern is `scheme://host[:port]`. A leading `*.` on the host matches
  subdomains only, and it needs at least two labels after it. `*.example.com`
  without a scheme means HTTPS.
- Scheme, host and effective port must match. Userinfo, paths, queries,
  fragments and malformed hosts are rejected.
- A preflight must request GET and only `Accept`, `Authorization` or
  `Content-Type`. It needs no credentials and returns no data.
- `Access-Control-Allow-Credentials` is never sent, so cross-origin cookies do
  not work. A browser client must send an `Authorization` header.
- The signed route never sends CORS headers. A browser must not hold the HMAC
  secret. `OPTIONS` on that route returns only core's `Allow` response.
- Preflight on a language-prefixed path gets core's response, with no CORS
  headers.

## Quotas

- One Flood event covers both routes, with `rate_limit_threshold` for each pool.
- Each authorized GET or HEAD uses one unit of its IP address pool. It also uses
  one unit of its user ID pool or its HMAC key pool, stored as a SHA-256 hash.
- All clients behind one IP address share one total allowance, including
  independent keys and accounts. Changing the credential or the route does not
  add requests. Raise the threshold for clients behind a shared address.
- The IP address comes from `Request::getClientIp()`. Behind a reverse proxy,
  configure Drupal's reverse proxy settings, or every client shares the proxy's
  address.
- A credential cannot exceed the threshold from several addresses. A user and a
  key have separate pools, so they share an allowance only from the same address.
- A request that any pool rejects returns 429 and uses no unit from any pool.
  Page caches cannot serve these routes, so no request skips the count. A
  preflight uses no unit.
- Denied requests count per IP address plus claimed key, against
  `failed_auth_threshold`. The whole IP address has a limit of five times that
  number. A client behind a shared IP address can be blocked if others on that
  address reach the higher limit. These counters are separate from the quota.
- The failed-authorization block applies after Drupal resolves the route and
  before the access check, so it covers encoded paths, language prefixes and path
  aliases. A blocked request adds no counter.

## Caching

Page Cache and Dynamic Page Cache do not store these routes. A permission
change or a removed key applies to the next request. Clients can keep their own
private copy for `cache_max_age` seconds on the standard route, so they can hold
an old copy for that long. The module has no internal snapshot cache.

## Exposure settings

| Setting | Fresh install | When FALSE |
| --- | --- | --- |
| `expose_update_status` | FALSE | Update data is not collected. `update_status` is absent and each `security_status` is `unknown`. |
| `expose_database_version` | FALSE | `database.driver` and `database.version` are null. |
| `expose_paths` | FALSE | Each `path` is null. |
| `expose_custom_modules` | FALSE | Extensions outside core and contrib are left out. |

`_meta.redactions` lists each redacted source. Sites that upgrade from 1.x keep
1.x disclosure. See `UPGRADE.md`.

## Update data

The module reads cached Update Manager data only. It makes no HTTP request and
queues no fetch task.

`drupal.update_status.data_state` is one of:

- `unavailable`: the Update module is not installed.
- `missing`: no release data is stored.
- `stale`: the data is older than the configured check interval, or the last
  check time is unknown.
- `current`: the data is within the interval.

`age_seconds` and `stale_after_seconds` give the numbers. Per-module
`security_status` is one of `current`, `update_available`,
`security_update_available`, `not_supported`, `revoked`, `not_fetched` or
`unknown`.

## Changes from 1.x

1.x fields keep their names and types. These values changed as security
corrections:

- `path` can be null, and custom extensions can be absent.
- `_meta.cache.max_age` reports 0 when configured as 0. 1.x reported 300.
- `_meta.cache.max_age` is always 0 on the signed route.
- `composer` is now found by install path, so a submodule names its real package.

## Tool adapter

The optional `project_context_connector_tool` submodule provides the tool
`project_context_snapshot`. It requires the snapshot permission for the acting
account. The optional boolean input `include_security_updates` defaults to TRUE.
FALSE removes update data. TRUE cannot reveal data that site settings redact.
The output `snapshot` is the structure above.
