# Security Policy

## Supported Versions

The module follows semantic versioning. Only the latest minor versions within each supported major will receive security fixes.

| Version | Supported          |
| ------- | ------------------ |
| 1.x     | :white_check_mark: |
| < 1.0   | :x:                |

## Reporting a Vulnerability

Please **do not** open public issues for security problems.

### Reporting Process

1. **Private disclosure**: Email the maintainers via the security contact on the [Drupal.org project page](https://www.drupal.org/project/project_context_connector)
2. **Security Team**: If the project has opted in to **Drupal Security Advisory coverage**, report via the private Security Team issue queue per Drupal.org guidance
3. **Required information**:
   - Vulnerability description
   - Proof of concept or reproduction steps
   - Affected versions
   - Impact assessment (severity, exploitability)
   - Suggested remediation (if known)

### Response Timeline

- **Acknowledgment**: Within 5 business days
- **Initial assessment**: Within 10 business days
- **Fix timeline**: Varies by severity (critical: days, high: weeks, medium/low: next release)
- **Disclosure**: Coordinated disclosure after fix is available

## Security Advisory Coverage

This project is working toward **Drupal Security Advisory (SA) coverage**:

- Maintainers will request SA coverage from the Drupal Security Team after 1.0 stable release
- Once approved, security fixes will be coordinated with the Security Team
- Vulnerabilities will be communicated via SA-CONTRIB advisories on Drupal.org
- See [Drupal.org security team documentation](https://www.drupal.org/drupal-security-team/faq) for details

## Data Handling and Privacy

### Information Exposed

The module exposes **only non-PII operational metadata**:

- Drupal core version
- PHP version
- Database driver and version (configurable, can be disabled)
- Active module names, versions, and composer packages
- Security update status per project (if enabled)
- Default and admin theme names and versions
- Configuration flags: maintenance mode, caching settings, error display level, cron last run
- Relative file paths for modules/themes

### Information NOT Exposed

The module is explicitly designed **never** to expose:

- User emails, passwords, or any personally identifiable information (PII)
- API keys, tokens, or credentials
- Content or entity data
- Configuration values (except explicitly allow-listed flags)
- Database credentials or connection strings
- Absolute file system paths
- Environment variables
- Private files or secrets
- No telemetry, tracking, or external requests

## Threat Model and Mitigations

### Threat: Unauthorized Information Disclosure

**Mitigation**:
- Permission-gated endpoints require explicit `access project context snapshot` permission
- HMAC signed endpoint requires shared secret stored in `settings.php`
- Rate limiting prevents brute force attempts
- No sensitive data in responses

### Threat: Denial of Service (DoS)

**Mitigation**:
- Flood-based rate limiting per IP/user
- Configurable request throttling (default: 60 requests/minute)
- Cached responses reduce backend load
- OPTIONS requests rate-limited to prevent CORS probing

### Threat: Cross-Site Request Forgery (CSRF)

**Mitigation**:
- Read-only endpoints only (no state changes)
- No CSRF protection needed for GET requests
- Signed endpoint uses HMAC with timestamp to prevent replay attacks

### Threat: Timing Attacks

**Mitigation**:
- `hash_equals()` used for signature comparison (constant-time)
- Multiple validation failures indistinguishable to attacker

### Threat: Replay Attacks (HMAC)

**Mitigation**:
- Timestamp included in signature with configurable skew window (default: 5 minutes)
- Expired requests rejected

## Secret Management Best Practices

### Generating Secrets

Always use cryptographically secure random generation:

```bash
# PHP (recommended, 32 bytes = 256 bits)
php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"

# OpenSSL alternative
openssl rand -hex 32

# Node.js
node -e "console.log(require('crypto').randomBytes(32).toString('hex'))"
```

**Minimum requirements**:
- Length: 32+ characters (256+ bits of entropy)
- Character set: Hexadecimal, base64, or alphanumeric with symbols
- Generation: Cryptographically secure random number generator (CSRNG)

### Storing Secrets

**Never** commit secrets to version control. Use environment variables:

```php
// settings.php
$settings['project_context_connector_api_keys'] = [
  'production-bot' => getenv('PCC_SECRET'),
  'ci-pipeline' => getenv('PCC_CI_SECRET'),
];
```

**Environment variable options**:
- `.env` files (with proper `.gitignore`)
- Server environment variables
- Secrets management services (Vault, AWS Secrets Manager, etc.)
- Pantheon/Acquia secrets management

**Development vs. Production**:
- Development: Can use simpler secrets, documented in team wiki
- Staging: Use production-grade secrets, separate from production
- Production: High-entropy secrets from secure source, regularly rotated

### Secret Rotation

Rotate secrets regularly (recommended: every 90 days):

1. **Generate new secret**:
   ```bash
   NEW_SECRET=$(php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;")
   ```

2. **Add new key** with different key ID:
   ```php
   $settings['project_context_connector_api_keys'] = [
     'bot-2025-02' => 'new-secret-here',  // New
     'bot-2024-11' => 'old-secret-here',  // Keep temporarily
   ];
   ```

3. **Update clients** to use new key ID and secret

4. **Verify** new key works in production

5. **Remove old key** after 24-48 hours (grace period for in-flight requests)

6. **Clear caches**: `drush cr`

### Compromised Secret Response

If a secret is compromised or suspected compromised:

1. **Immediate action**:
   ```php
   // Remove from settings.php
   $settings['project_context_connector_api_keys'] = [
     // 'compromised-key' => 'secret',  // REMOVED
   ];
   ```

2. **Clear all caches**: `drush cr`

3. **Review access logs**:
   ```bash
   # Look for unauthorized access
   grep "project-context-connector" /var/log/apache2/access.log
   grep "X-PCC-Key: compromised-key" /var/log/apache2/access.log
   ```

4. **Generate and deploy new secrets** to all legitimate clients

5. **Consider temporary disable** if breach is severe:
   ```php
   // Temporarily disable signed endpoint
   $settings['project_context_connector_api_keys'] = [];
   ```

6. **Document incident** for security audit trail

7. **Inform security team** if SA coverage is enabled

## CORS Security

CORS is **opt-in** and requires explicit origin allow-listing.

### Configuration Best Practices

**Exact matches** (recommended for production):
```yaml
allowed_origins:
  - https://dashboard.example.com
  - https://admin.example.com
```

**Wildcard subdomains** (use cautiously):
```yaml
allowed_origins:
  - https://*.example.com  # Matches subdomains ONLY
  - https://example.com     # Add base domain separately if needed
```

**Important**:
- Wildcard `*.example.com` matches `sub.example.com` but **not** `example.com`
- HTTP origins trigger security warnings (disable in production)
- Scheme enforcement: `https://*.example.com` won't match `http://sub.example.com`

### CORS Attack Prevention

- **Probing attacks**: OPTIONS requests are rate-limited
- **Credential theft**: No credentials in responses; CORS headers only for allow-listed origins
- **Information disclosure**: Cache contexts include `headers:Origin` to prevent cross-origin cache leakage

### Production Recommendation

**Disable CORS** unless browser access is required. Use HMAC signed endpoint for server-to-server access instead:

- HMAC requires no CORS (server-to-server)
- Basic Auth works without CORS (curl, scripts)
- CORS only needed for in-browser JavaScript

## Authentication Security

### Basic Auth

**Pros**:
- Simple setup
- Wide client support
- Standard HTTP authentication

**Cons**:
- Credentials in every request (base64-encoded, not encrypted)
- Visible in web server logs
- No built-in expiration

**Best practices**:
1. Use HTTPS always (credentials in clear over HTTP)
2. Create dedicated service user with single permission
3. Use strong, random passwords (32+ characters)
4. Rotate passwords every 90 days
5. Never reuse Drupal admin credentials

**Example secure user creation**:
```bash
PASSWORD=$(openssl rand -base64 32)
drush user:create pcc_bot --password="$PASSWORD"
drush role:create pcc_consumer "Project Context Consumer"
drush role:perm:add pcc_consumer "access project context snapshot"
drush user:role:add pcc_consumer pcc_bot

# Store password in secure location (password manager, secrets service)
echo "pcc_bot:$PASSWORD" >> /secure/location/credentials.txt
chmod 600 /secure/location/credentials.txt
```

### HMAC Signed Requests

**Pros**:
- No Drupal user required
- Credentials not in request (only signature)
- Timestamp prevents replay attacks
- Multiple keys with different IDs

**Cons**:
- Complex client implementation
- Clock synchronization required
- Not browser-friendly

**Best practices**:
1. Generate high-entropy secrets (256+ bits)
2. Store secrets in environment variables, not `settings.php` directly
3. Use key IDs for rotation (`bot-2025-02`, `bot-2025-05`)
4. Monitor for signature validation failures (potential attack)
5. Implement client-side retry with backoff for 429 responses

**Security parameters**:
- Timestamp skew: Default 300s (5 minutes), configurable
- Signature algorithm: HMAC-SHA256 (not SHA1 or MD5)
- Canonical format: `METHOD\nPATH\nTIMESTAMP` (exact format required)

## Rate Limiting

### Configuration

Default: 60 requests per 60-second window per IP/UID

**Tuning recommendations**:
- **Development**: 120+ (generous for testing)
- **Production**: 60 (reasonable for automation)
- **High-traffic**: 120-300 (with monitoring)
- **Public endpoint**: 30 (more restrictive)

### Monitoring

Watch for rate limit violations:

```bash
# Drupal logs
drush watchdog:show --type=project_context_connector

# Check flood table
drush sql:query "SELECT * FROM flood WHERE event LIKE 'pcc.%' ORDER BY timestamp DESC LIMIT 20;"
```

**Red flags**:
- Single IP hitting limit repeatedly (potential DDoS)
- Multiple IPs hitting limit (distributed attack)
- Sudden spike in 429 responses (investigate cause)

## Additional Security Recommendations

### HTTPS Configuration

Always use HTTPS in production:

```apache
# Apache: Enforce HTTPS
<Location /project-context-connector>
    Require expr %{HTTPS} == "on"
</Location>
```

```nginx
# Nginx: Redirect HTTP to HTTPS
if ($scheme = http) {
    return 301 https://$server_name$request_uri;
}
```

### Web Application Firewall (WAF)

Consider WAF rules for additional protection:

- Block requests with suspicious User-Agents
- Geo-blocking if only specific regions need access
- Additional rate limiting at WAF level

### Monitoring and Alerting

Set up alerts for:

- Repeated 403 errors (unauthorized access attempts)
- Repeated 429 errors (rate limit violations)
- Sudden traffic spikes
- Signature validation failures (HMAC)

### Security Headers

The module sets `X-Content-Type-Options: nosniff`. Consider additional headers at web server level:

```
X-Frame-Options: DENY
X-Content-Type-Options: nosniff
Referrer-Policy: no-referrer
Permissions-Policy: geolocation=(), microphone=(), camera=()
```

### Drupal Security

Keep Drupal core and contributed modules updated:

```bash
# Check for security updates
composer outdated "drupal/*"

# Apply security updates
composer update drupal/core --with-dependencies
drush updatedb
drush cr
```

## Compliance Considerations

### GDPR

The module does **not** expose personal data. However:

- Access logs may contain IP addresses (personal data under GDPR)
- Ensure access logs comply with retention policies
- Document this endpoint in privacy policy if exposed to EU users

### SOC 2 / ISO 27001

For compliance:

- Document authentication and authorization mechanisms
- Implement secret rotation procedures
- Maintain audit logs of access
- Regular security reviews and penetration testing

## Security Changelog

Document security-relevant changes in releases:

- **1.1.0**: Added custom access checker for signed route, improved CORS validation
- **1.0.0**: Initial release with permission-gated and HMAC endpoints

## Contact

For security concerns: See [Drupal.org security contact](https://www.drupal.org/project/project_context_connector)

For general support: See [project issue queue](https://www.drupal.org/project/issues/project_context_connector)
