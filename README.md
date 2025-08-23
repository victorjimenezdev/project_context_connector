# Project Context Connector

Project Context Connector exposes a safe, read-only JSON snapshot of your Drupal site for prompts, scripts, automation, and CI. It reports core version, active modules with versions and composer package, PHP and database versions, default and admin theme details, and selected non‑PII configuration flags. Routes are permission‑gated, cached, and rate‑limited.

## Features

- Read‑only JSON endpoint with a curated project snapshot
- Drush command `drush pcc:snapshot` that emits the same JSON for local use and pipelines
- Security focused: permission‑gated route, no write endpoints, no remote code execution, no telemetry
- Optional per‑project status derived from Update Manager cached data only
- Performance: cacheable responses with cache contexts and tags, built‑in rate limiting via Flood, optional CORS allow‑list for browser clients
- Quality: PSR‑12 and Drupal coding standards, PHPDoc, translatable strings, accessible admin form that meets WCAG 2.2 AA
- Compatible with Drupal 10 and 11; includes metadata for Project Browser
- Optional HMAC signed endpoint for token based server to server access

## Requirements

- Drupal core 10 or 11
- Optional for authentication:
  - `basic_auth` (core) for Basic Auth
  - `simple_oauth` (contrib) if you prefer OAuth2 bearer tokens
- Optional: `update` module to populate per project status from cached data

## Installation

```bash
composer require drupal/project_context_connector
drush en -y project_context_connector
drush cr
````

## Configuration

Navigate to **Configuration → Development → Project Context Connector** and set:

* Allowed origins for browser clients
* Rate limit threshold and window
* Cache max age
* Expose update status metadata

## Endpoints

* `GET /project-context-connector/snapshot`
  Permission: `access project context snapshot`
* `GET /project-context-connector/snapshot/signed`
  HMAC signed access when enabled; no Drupal user required

Both endpoints return the same JSON structure. Responses include `Cache-Control`, cache contexts, and cache tags.

## Authentication

### Option A. Basic Auth

Enable Basic Auth and create a minimal service user.

```bash
drush en -y basic_auth
drush role:create pcc_consumer "Project Context Consumer"
drush role:perm:add pcc_consumer "access project context snapshot"
drush user:create pcc_bot --mail="pcc-bot@example.com" --password="STRONG-PASSWORD"
drush user:role:add pcc_consumer pcc_bot
```

Example request:

```
GET /project-context-connector/snapshot
Header: Authorization: Basic base64(pcc_bot:STRONG-PASSWORD)
Header: Accept: application/json
```

### Option B. HMAC signed requests

Add a shared secret in `settings.php`:

```php
$settings['project_context_connector_api_keys'] = [
  'prompt-bot' => 'paste-a-strong-random-secret',
];
```

Call the signed route:

```
GET /project-context-connector/snapshot/signed
Headers:
  X-PCC-Key: prompt-bot
  X-PCC-Timestamp: <unix seconds>
  X-PCC-Signature: hex(hmac_sha256("<METHOD>\n<PATH>\n<TIMESTAMP>", secret))
```

Preflight `OPTIONS` is allowed. Signed route is also throttled.

## Drush

```bash
drush pcc:snapshot --pretty
```

Use this in CI to archive a build artifact of environment facts.

## Example responses

See `examples/snapshot.example.json` in this repository.

## Caching and rate limiting

* Responses use `CacheableJsonResponse` with explicit `max-age`
* Cache contexts include `user.permissions` and `headers:Origin`
* Cache tags include `config:system.theme` and a module tag
* Throttle uses a shared bucket for both routes. On `429` a `Retry-After` header is sent

## Security and privacy

* Read only endpoints, no PII, no remote calls
* Input validation on settings form
* Optional per project status comes from cached Update Manager data
* Use HTTPS end to end
* For Basic Auth, create a dedicated service user with a single permission
* For HMAC, keep secrets only in `settings.php` and rotate as needed

## Internationalization and accessibility

* All UI strings use `t()` and are translatable
* Admin form meets WCAG 2.2 AA and is keyboard accessible

## Testing

* Kernel and unit tests can be run with `phpunit`
* Static checks with `phpcs` and `phpstan`
* See `.gitlab-ci.yml` for the pipeline template

## Client examples

* `examples/curl-basic.sh`
* `examples/curl-signed.sh`
* `examples/node-fetch-basic.mjs`
* `examples/node-fetch-signed.mjs`
* `examples/postman/Project_Context_Connector.postman_collection.json`

See the files for usage. Mark shell scripts as executable.

## Support and contributions

Open issues and merge requests in the project issue queue. See `CONTRIBUTING.md` for coding standards, tests, and release process.

## License

GPL-2.0-or-later. See `LICENSE.txt`.