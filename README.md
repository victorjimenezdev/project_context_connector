# Project Context Connector

Project Context Connector exposes a safe, read-only JSON snapshot of your Drupal site for AI agents, automation scripts, and CI/CD pipelines. It reports core version, active modules with versions and composer packages, PHP and database versions, default and admin theme details, and selected non-PII configuration flags. Routes are permission-gated, cached, and rate-limited.

## Why Use Project Context Connector?

Traditional Drupal sites are opaque to AI agents and automation tools. When building Slack bots, CI pipelines, or AI-powered assistants, there's no standard way to programmatically ask "What Drupal version is this site running?" or "Which modules are installed and are any outdated?"

This module solves that by exposing a read-only JSON snapshot that AI agents and scripts can consume to understand your Drupal site's current state without requiring direct database access or file system inspection.

### Use Cases

- **AI-Powered Support**: ChatGPT, Claude, or other AI agents with real-time context about your Drupal site
- **DevOps Automation**: CI/CD scripts that adapt based on current site configuration and module versions
- **Slack/Teams Bots**: Team bots that answer "What version of PHP are we running in production?"
- **Multi-Site Management**: Centralized dashboards that aggregate metadata from multiple Drupal instances
- **Security Auditing**: Automated checks for outdated modules or security vulnerabilities
- **Documentation Generation**: Auto-generate technical documentation that stays current with your site
- **Incident Response**: Quick environment snapshots for troubleshooting and support tickets

## Features

- **Read-only JSON endpoint** with a curated project snapshot
- **Drush command** `drush pcc:snapshot` that emits the same JSON for local use and pipelines
- **Security focused**: Permission-gated route, no write endpoints, no remote code execution, no telemetry
- **Optional per-project status** derived from Update Manager cached data only (no outbound requests)
- **Performance**: Cacheable responses with cache contexts and tags, built-in rate limiting via Flood, optional CORS allow-list for browser clients
- **Quality**: PSR-12 and Drupal coding standards, PHPDoc, translatable strings, accessible admin form that meets WCAG 2.2 AA
- **Compatible** with Drupal 10 and 11; includes metadata for Project Browser
- **Optional HMAC signed endpoint** for token-based server-to-server access without Drupal user accounts

## Requirements

- Drupal core 10 or 11
- PHP 8.1, 8.2, or 8.3
- Optional for authentication:
  - `basic_auth` (core) for Basic Auth
  - `simple_oauth` (contrib) if you prefer OAuth2 bearer tokens
- Optional: `update` module to populate per-project status from cached data

## Installation

```bash
composer require drupal/project_context_connector
drush en -y project_context_connector
drush cr
```

## Quick Start (5 Minutes)

### 1. Install the Module

```bash
composer require drupal/project_context_connector
drush en -y project_context_connector basic_auth
drush cr
```

### 2. Create a Service User

```bash
# Generate a strong password
PASSWORD=$(openssl rand -base64 32)

# Create dedicated service user
drush user:create pcc_bot --password="$PASSWORD"
drush role:create pcc_consumer "Project Context Consumer"
drush role:perm:add pcc_consumer "access project context snapshot"
drush user:role:add pcc_consumer pcc_bot

# Display credentials (save these securely)
echo "Username: pcc_bot"
echo "Password: $PASSWORD"
```

### 3. Test the Endpoint

```bash
curl -u pcc_bot:YOUR_PASSWORD \
  -H "Accept: application/json" \
  https://your-site.com/project-context-connector/snapshot | jq .
```

### 4. Integrate with Your AI Agent

Add the endpoint to your AI agent's tools, MCP server configuration, or automation scripts. See the [AI Agent Integration](#ai-agent-integration) section for examples.

## Configuration

Navigate to **Configuration → Development → Project Context Connector** (`/admin/config/development/project-context-connector`) to configure:

- **Allowed origins**: CORS allow-list for browser clients (one origin per line)
- **Enable CORS headers**: Toggle CORS support on/off
- **Rate limit threshold**: Maximum requests per window (default: 60)
- **Rate limit window**: Time window in seconds (default: 60)
- **Cache max age**: HTTP cache duration in seconds (default: 300)
- **Expose update status metadata**: Include security update status per project (requires Update module)
- **Expose database version**: Include database driver and version (disable for minimal information disclosure)

## Endpoints

### Standard Endpoint (Permission-Gated)

```
GET /project-context-connector/snapshot
```

**Authentication**: Requires `access project context snapshot` permission via:
- Basic Auth with dedicated service user
- OAuth2 bearer token (if `simple_oauth` is installed)
- Drupal session cookie (for admin use only)

**Response**: JSON snapshot with `Cache-Control`, cache contexts, and cache tags

### Signed Endpoint (HMAC Authentication)

```
GET /project-context-connector/snapshot/signed
```

**Authentication**: HMAC signature with shared secret (no Drupal user required)

**Response**: Same JSON structure as standard endpoint

Both endpoints return identical JSON structures and apply the same rate limiting and caching policies.

## Authentication Methods

### Option A: Basic Auth (Easiest for Development)

Enable Basic Auth module and create a minimal service user:

```bash
drush en -y basic_auth
drush role:create pcc_consumer "Project Context Consumer"
drush role:perm:add pcc_consumer "access project context snapshot"
drush user:create pcc_bot --mail="pcc-bot@example.com" --password="STRONG-PASSWORD"
drush user:role:add pcc_consumer pcc_bot
```

Example request:

```bash
curl -u pcc_bot:STRONG-PASSWORD \
  -H "Accept: application/json" \
  https://your-site.com/project-context-connector/snapshot
```

### Option B: HMAC Signed Requests (Best for Production)

Add a shared secret in `settings.php`:

```php
$settings['project_context_connector_api_keys'] = [
  'production-bot' => 'paste-a-strong-random-secret-here',
];
```

**Generate a secure secret**:

```bash
php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"
```

Call the signed route with HMAC headers:

```
GET /project-context-connector/snapshot/signed
Headers:
  X-PCC-Key: production-bot
  X-PCC-Timestamp: <unix seconds>
  X-PCC-Signature: hex(hmac_sha256("<METHOD>\n<PATH>\n<TIMESTAMP>", secret))
```

**Signature Construction**:

```bash
# Example: Generate signature for GET request
METHOD="GET"
PATH="/project-context-connector/snapshot/signed"
TIMESTAMP=$(date +%s)
SECRET="your-secret-key"

# Canonical string: METHOD + newline + PATH + newline + TIMESTAMP
CANONICAL="${METHOD}\n${PATH}\n${TIMESTAMP}"

# Generate HMAC-SHA256 signature
SIGNATURE=$(echo -n -e "$CANONICAL" | openssl dgst -sha256 -hmac "$SECRET" | cut -d' ' -f2)

# Make request
curl -H "X-PCC-Key: production-bot" \
     -H "X-PCC-Timestamp: $TIMESTAMP" \
     -H "X-PCC-Signature: $SIGNATURE" \
     https://your-site.com/project-context-connector/snapshot/signed
```

Preflight `OPTIONS` requests are allowed without signatures. The signed route applies the same rate limiting as the standard route.

### Option C: OAuth2 Bearer Tokens

Install and configure `simple_oauth` module, then use bearer tokens:

```bash
curl -H "Authorization: Bearer YOUR_OAUTH_TOKEN" \
     -H "Accept: application/json" \
     https://your-site.com/project-context-connector/snapshot
```

## Authentication Methods Compared

| Feature | Basic Auth | HMAC Signed | OAuth2 | Drupal Session |
|---------|------------|-------------|---------|----------------|
| Setup complexity | Easy | Medium | Hard | Easy |
| Credentials in logs | Yes (base64) | No | No | Cookie only |
| Requires Drupal user | Yes | No | Yes | Yes |
| Suitable for CI/CD | Yes | Yes (best) | Yes | No |
| Browser-friendly | Yes | No | Yes | Yes |
| Rotating credentials | Change password | Update settings.php | Refresh token | N/A |
| Best for | Development, testing | Production automation | API integration | Admin use only |

**Recommendation**: Use HMAC for production automation, Basic Auth for development and testing, OAuth2 for third-party integrations.

## Drush Command

```bash
# Output JSON to stdout
drush pcc:snapshot

# Pretty-printed JSON
drush pcc:snapshot --pretty

# Save to file (useful in CI/CD)
drush pcc:snapshot --pretty > snapshot.json
```

Use this in CI pipelines to archive build artifacts with environment facts, or for local debugging without HTTP requests.

## Example Response

See [`examples/snapshot.example.json`](examples/snapshot.example.json) for a complete example.

```json
{
  "generated_at": "2025-08-23T12:00:00Z",
  "drupal": {
    "core_version": "10.3.x",
    "php": {"version": "8.3.0"},
    "database": {"driver": "mysql", "version": "8.0.36"},
    "themes": {
      "default": "olivero",
      "admin": "claro"
    },
    "config_flags": {
      "maintenance_mode": false,
      "error_level": "hide",
      "css_preprocess": true,
      "js_preprocess": true,
      "page_cache_max_age": 300,
      "cron_last": 1724400000
    },
    "active_modules": [
      {
        "name": "node",
        "label": "Node",
        "version": "10.3.0",
        "composer": "drupal/core",
        "project": "drupal",
        "origin": "core",
        "path": "core/modules/node",
        "security_status": "current"
      }
    ]
  },
  "rate_limit": {
    "threshold": 60,
    "window_seconds": 60
  },
  "_meta": {
    "cache": {"max_age": 300}
  }
}
```

## Caching and Rate Limiting

### Caching

- Responses use `CacheableJsonResponse` with explicit `max-age` header (configurable, default 300s)
- Cache contexts include `user.permissions` and `headers:Origin` to prevent cross-user/origin leakage
- Cache tags include `config:system.theme` and `project_context_connector:snapshot` for targeted invalidation
- Use `drush cr` or flush caches after configuration changes

### Rate Limiting

- Throttling uses Drupal's Flood API with a shared bucket for both endpoints
- Authenticated users are tracked by UID, anonymous by IP address
- On `429 Too Many Requests`, a `Retry-After` header indicates wait time
- OPTIONS preflight requests are rate-limited to prevent CORS probing attacks
- Configure threshold and window in admin settings

## Security Best Practices

### For Production Environments

1. **Always use HTTPS** - Never expose this endpoint over unencrypted HTTP
2. **Prefer HMAC over Basic Auth** - Keeps credentials out of web server access logs
3. **Restrict CORS origins** - Use exact domain matches; avoid wildcards in production
4. **Enable rate limiting** - Default (60 req/min) is reasonable; adjust based on usage patterns
5. **Rotate secrets regularly** - Change HMAC secrets every 90 days minimum
6. **Monitor access logs** - Watch for unusual access patterns or brute force attempts
7. **Keep Drupal updated** - This module relies on core security; stay current with security releases
8. **Minimize information disclosure** - Disable database version exposure if not needed

### Secret Management

**Generate strong secrets** (32+ characters, high entropy):

```bash
# Linux/macOS
php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"

# Alternative with OpenSSL
openssl rand -hex 32
```

**Store secrets securely**:

```php
// settings.php - Never commit secrets to version control
$settings['project_context_connector_api_keys'] = [
  'production-bot' => getenv('PCC_SECRET'), // From environment variable
  'ci-pipeline' => getenv('PCC_CI_SECRET'),
];
```

**Secret rotation procedure**:

1. Generate new secret
2. Add new key with different key ID to `settings.php`
3. Update client to use new key ID and secret
4. Verify new key works
5. Remove old key from `settings.php` after 24-48 hours

**What to do if a secret is compromised**:

1. Immediately remove the compromised key from `settings.php`
2. Clear all caches: `drush cr`
3. Review access logs for unauthorized access
4. Generate and deploy new secrets to all legitimate clients
5. Consider temporarily disabling the signed endpoint if breach is severe

### What's Exposed vs. Not Exposed

**Information Exposed**:
- Drupal core version
- PHP version
- Database driver and version (if enabled)
- Active module names, versions, and composer packages
- Security update status per project (if enabled)
- Default and admin theme names and versions
- Configuration flags: maintenance mode, caching settings, error display level, cron last run
- Relative file paths for modules/themes (e.g., `modules/contrib/views`)

**Information NOT Exposed**:
- User emails, passwords, or any PII
- API keys, tokens, or credentials
- Content or entity data
- Configuration values (except allow-listed flags)
- Database credentials or connection strings
- Absolute file system paths
- Environment variables
- Private files or secrets
- No telemetry or tracking

This module is designed to expose only operational metadata useful for automation and support, never sensitive data.

### CORS Security

CORS is opt-in and requires explicit origin allow-listing. Configure carefully:

- **Exact matches**: `https://example.com` matches only that exact origin
- **Wildcard subdomains**: `https://*.example.com` matches subdomains ONLY, not `example.com` itself
  - To match both, add both: `https://example.com` and `https://*.example.com`
- **HTTP warnings**: The admin form warns when HTTP origins are configured (insecure for production)
- **Scheme enforcement**: Wildcard patterns respect schemes (http vs https)

**Production recommendation**: Disable CORS unless absolutely required. Use HMAC signed endpoint for server-to-server access instead.

## AI Agent Integration

### Claude/ChatGPT Integration

Add this to your AI agent's system prompt or tool configuration:

```
You have access to Drupal site metadata at https://example.com/project-context-connector/snapshot

Authentication: Basic Auth
  Username: pcc_bot
  Password: [from secure storage]

Fetch this endpoint to understand:
- Drupal core version and PHP version
- Active modules with versions and security update status
- Current theme and site configuration
- Database driver and version

Use this context when answering questions about the Drupal site or suggesting module
installations. Always check security_status field to warn about outdated modules.
```

### Model Context Protocol (MCP) Server

Build an MCP server wrapper for use with Claude Desktop or other MCP clients:

```javascript
// Example MCP server tool definition
{
  "name": "get_drupal_context",
  "description": "Fetch current Drupal site context including modules, versions, and configuration",
  "inputSchema": {
    "type": "object",
    "properties": {
      "site_url": {
        "type": "string",
        "description": "Base URL of the Drupal site"
      }
    },
    "required": ["site_url"]
  }
}
```

See [Model Context Protocol documentation](https://modelcontextprotocol.io/) for implementation details.

### Slack Bot Example

```javascript
// Slack slash command: /site-info
const { WebClient } = require('@slack/web-api');
const fetch = require('node-fetch');

async function handleSiteInfo() {
  const snapshot = await fetch('https://example.com/project-context-connector/snapshot', {
    headers: {
      'Authorization': `Basic ${Buffer.from('pcc_bot:password').toString('base64')}`,
      'Accept': 'application/json'
    }
  }).then(r => r.json());

  const outdated = snapshot.drupal.active_modules.filter(
    m => m.security_status === 'security_update_available'
  );

  return {
    response_type: 'in_channel',
    text: `Running Drupal ${snapshot.drupal.core_version} with PHP ${snapshot.drupal.php.version}`,
    blocks: [
      {
        type: 'section',
        text: {
          type: 'mrkdwn',
          text: `*Environment Info*\n• Drupal: ${snapshot.drupal.core_version}\n• PHP: ${snapshot.drupal.php.version}\n• Database: ${snapshot.drupal.database.driver} ${snapshot.drupal.database.version}`
        }
      },
      {
        type: 'section',
        text: {
          type: 'mrkdwn',
          text: `*Modules*\n${snapshot.drupal.active_modules.length} active modules${outdated.length > 0 ? `\n:warning: ${outdated.length} modules need security updates!` : ''}`
        }
      }
    ]
  };
}
```

### Python Automation Example

```python
import requests
import json

class DrupalContextClient:
    def __init__(self, base_url, username, password):
        self.base_url = base_url
        self.auth = (username, password)

    def get_snapshot(self):
        response = requests.get(
            f"{self.base_url}/project-context-connector/snapshot",
            auth=self.auth,
            headers={"Accept": "application/json"}
        )
        response.raise_for_status()
        return response.json()

    def check_security_updates(self):
        snapshot = self.get_snapshot()
        vulnerable = [
            m for m in snapshot['drupal']['active_modules']
            if m['security_status'] == 'security_update_available'
        ]
        return vulnerable

# Usage in CI/CD pipeline
client = DrupalContextClient('https://example.com', 'pcc_bot', 'password')
vulnerable_modules = client.check_security_updates()

if vulnerable_modules:
    print(f"ERROR: {len(vulnerable_modules)} modules need security updates!")
    for module in vulnerable_modules:
        print(f"  - {module['name']} ({module['version']})")
    exit(1)
```

## Client Examples

Pre-built client examples in the `examples/` directory:

- **Shell scripts**:
  - `curl-basic.sh` - Basic Auth example
  - `curl-signed.sh` - HMAC signed request example
- **Node.js**:
  - `node-fetch-basic.mjs` - ES module with Basic Auth
  - `node-fetch-signed.mjs` - ES module with HMAC signing
- **Postman**: `postman/Project_Context_Connector.postman_collection.json`

Mark shell scripts as executable before running:

```bash
chmod +x examples/*.sh
./examples/curl-basic.sh
```

## Troubleshooting

### 403 Forbidden

**Causes**:
- User lacks `access project context snapshot` permission
- Basic Auth module not enabled
- CORS origin not in allow-list (for browser requests)
- HMAC signature validation failed (signed endpoint)

**Solutions**:
```bash
# Check permissions
drush role:perm:list pcc_consumer

# Enable Basic Auth if missing
drush en -y basic_auth
drush cr

# Verify CORS configuration
drush cget project_context_connector.settings allowed_origins
```

### 429 Too Many Requests

**Cause**: Rate limit exceeded

**Solution**: Wait for the duration specified in `Retry-After` header, then retry. Or adjust rate limits in admin settings.

```bash
# Check current rate limit settings
drush cget project_context_connector.settings rate_limit_threshold
drush cget project_context_connector.settings rate_limit_window

# Increase if needed (use with caution)
drush cset project_context_connector.settings rate_limit_threshold 120
```

### Empty or Missing Module Versions

**Causes**:
- Module not installed via Composer
- `version` key missing from `.info.yml` (common for dev versions)
- Update module not enabled

**Solutions**:
```bash
# Enable Update module to populate version data
drush en -y update

# Manually fetch update data
drush updatedb
drush cr
```

### Signature Validation Failing (HMAC)

**Common issues**:
1. **Clock skew**: Server and client clocks differ by >5 minutes
2. **Wrong secret**: Secret in `settings.php` doesn't match client
3. **Incorrect signature construction**: Newlines, encoding, or hash algorithm mismatch

**Debug checklist**:
```bash
# Verify server time
date +%s

# Check settings.php has correct key
drush php-eval "print_r(\Drupal\Core\Site\Settings::get('project_context_connector_api_keys'));"

# Test with verbose curl
curl -v -H "X-PCC-Key: your-key" \
     -H "X-PCC-Timestamp: $(date +%s)" \
     -H "X-PCC-Signature: your-signature" \
     https://your-site.com/project-context-connector/snapshot/signed
```

**Signature construction must use**:
- Method: Uppercase (e.g., `GET`)
- Path: Without query string (e.g., `/project-context-connector/snapshot/signed`)
- Timestamp: Unix seconds as string
- Canonical format: `<METHOD>\n<PATH>\n<TIMESTAMP>` (literal newlines)
- Algorithm: HMAC-SHA256
- Output: Lowercase hexadecimal

### Database Version Shows NULL

**Cause**: `expose_database_version` setting is disabled

**Solution**:
```bash
# Enable database version exposure
drush cset project_context_connector.settings expose_database_version 1
drush cr
```

### Stale Data in Response

**Cause**: Response is cached

**Solution**:
```bash
# Clear all caches
drush cr

# Or clear specific cache tags
drush eval "\Drupal::service('cache_tags.invalidator')->invalidateTags(['project_context_connector:snapshot']);"
```

## Testing

The module includes comprehensive test coverage:

```bash
# Unit tests (fast, no database)
phpunit --testsuite Unit

# Kernel tests (Drupal bootstrap, no full site)
phpunit --testsuite Kernel

# Functional tests (full site, browser-like)
phpunit --testsuite Functional

# Static analysis
phpstan analyse -c phpstan.neon.dist
phpcs --standard=Drupal,DrupalPractice src/ tests/
```

See [`.gitlab-ci.yml`](.gitlab-ci.yml) and [`.github/workflows/ci.yml`](.github/workflows/ci.yml) for CI pipeline examples.

## Internationalization and Accessibility

- All UI strings use `t()` and are translatable via Drupal's translation system
- Admin form meets WCAG 2.2 AA standards with proper labels, descriptions, and keyboard navigation
- Form validation provides clear, translatable error messages
- Translation template included in `translations/`

## Support and Contributions

- **Issue queue**: [Drupal.org project issues](https://www.drupal.org/project/issues/project_context_connector)
- **Contributing**: See [`CONTRIBUTING.md`](CONTRIBUTING.md) for coding standards, tests, and release process
- **Code of conduct**: See [`CODE_OF_CONDUCT.md`](CODE_OF_CONDUCT.md)
- **Security issues**: See [`SECURITY.md`](SECURITY.md) for responsible disclosure

## Roadmap

Potential future enhancements:

- Webhook notifications when security updates become available
- Historical snapshot storage for trend analysis
- Module compatibility checking API
- GraphQL endpoint alternative
- Performance metrics (page load times, cache hit rates)

Suggest features in the issue queue!

## License

GPL-2.0-or-later. See [`LICENSE.txt`](LICENSE.txt).

## Credits

Developed and maintained by the Drupal community. See commit history for contributors.
