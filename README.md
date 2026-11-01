# Project Context Connector

Exposes sanitized, read-only Drupal project context as JSON, for prompt building
and automation. It provides two HTTP endpoints, a Drush command and an optional
Tool API plugin. All of them use one snapshot service.

- API, schema semantics and limits: `docs/API.md`
- Upgrade from 1.x: `UPGRADE.md`
- Testing: `TESTING.md`
- Security model: `SECURITY.md`

## Requirements

PHP 8.3 or later. Drupal 12 requires PHP 8.5.

| Target | Base module | Tool adapter | Drush |
| --- | --- | --- | --- |
| Drupal 10.6.20 (temporary) | Tested | Tool 1.0.0-beta12 | 13 |
| Drupal 11.4.11 | Tested | Tool 1.0.0-beta12, MCP Server 2.0.0-beta7 | 13 |
| Drupal 11.5.0-beta1 | Tested | Tool 1.0.0-beta12, MCP Server 2.0.0-beta7 | 13 |
| Drupal 12.0.0-beta2 | Tested with a test-only core patch, see `TESTING.md` | Not available: Tool declares only `^10.5 \|\| ^11` | 14 dev |

## Install

```bash
composer require drupal/project_context_connector
drush en project_context_connector
```

Fresh installs have CORS off and expose no update status, database facts, paths
or custom extensions. Change this at
`/admin/config/development/project-context-connector`.

## Use the standard endpoint

Grant `access project context snapshot` to a dedicated role. Basic Auth needs the
core `basic_auth` module.

```bash
drush en basic_auth
curl -u "$PCC_USER" https://example.com/project-context-connector/snapshot
```

## Use the signed endpoint

Add a key to `settings.php`. Keep the secret out of Git and configuration exports.

```php
$settings['project_context_connector_api_keys'] = [
  'prompt-bot' => getenv('PCC_SHARED_SECRET'),
];
```

Then sign each request:

```bash
METHOD=GET
REQUEST_PATH=/project-context-connector/snapshot/signed
TS="$(date +%s)"
export PCC_SHARED_SECRET
SIG="$(printf '%s\n%s\n%s' "$METHOD" "$REQUEST_PATH" "$TS" \
  | php -r 'echo hash_hmac("sha256", stream_get_contents(STDIN), getenv("PCC_SHARED_SECRET"));')"
curl "https://example.com$REQUEST_PATH" \
  -H "X-PCC-Key: prompt-bot" -H "X-PCC-Timestamp: $TS" -H "X-PCC-Signature: $SIG"
```

PHP reads the secret from its environment. Do not pass the secret to a command as
an argument, for example `openssl dgst -hmac`, because other processes can read
command arguments.

`examples/` has complete clients. Requests stay valid for 300 seconds, and a
captured request can be replayed in that time, so use TLS.

## Use Drush

```bash
drush pcc:snapshot
drush pcc:snapshot --pretty
```

The command exits nonzero if the snapshot cannot be encoded as JSON. Exposure
settings apply to Drush too.

## Use Tool API and MCP

```bash
composer require drupal/tool
drush en project_context_connector_tool
```

The tool ID is `project_context_snapshot`. To reach MCP clients, install MCP
Server 2.x and its companion project `drupal/mcp_server_tool_bridge`. Those
modules own the transport and client authorization. This module adds none.
Tests cover bridge discovery, permission checks and tool calls in the bridge
plugin. They do not cover an MCP client session over a transport.

## Accessibility

The settings form uses standard Drupal form elements with labels and
descriptions. No formal accessibility audit has been done.

## Maintainers and support

Use the project issue queue. Report security problems as `SECURITY.md` describes.

## License

GPL-2.0-or-later.
