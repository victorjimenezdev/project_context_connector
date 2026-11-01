# Security

Report vulnerabilities through the Drupal security team process, not a public
issue.

## Model

The snapshot holds site metadata, not user data. It can still help an attacker,
for example through versions and update status. Grant access narrowly.

- The standard route requires a restricted permission, by cookie or Basic Auth.
- The signed route requires an HMAC signature. Secrets live in `settings.php`.
- Page caches do not store either route.
- Flood limits apply a shared quota and a failed-authorization limit. All clients
  behind one IP address share one quota allowance.
- CORS is off by default. It never grants credentials, and it never applies to
  the signed route.

## Known limits

- HMAC has no nonce. A captured request can be replayed for up to 300 seconds in
  either direction of its timestamp. The signature does not cover the query
  string, headers or host. Use TLS.
- Clients behind one IP address share the higher failed-authorization limit. A
  hostile neighbor can block them for the window. Client IP accuracy depends on
  the site's reverse proxy settings.
- A client can keep a private copy for `cache_max_age` seconds.
- A key removed from `settings.php` stops working on the next request. There is
  no other key revocation or rotation feature. Add the new key, move clients, and
  then remove the old key.
- Sites upgraded from 1.x keep 1.x exposure until an administrator changes it.

## Operations

- Use HTTPS, a dedicated account or key per client, and random secrets of at
  least 32 bytes, such as `openssl rand -hex 32`.
- Never put a secret in a browser, in Git or in a configuration export.
- Basic Auth sends a password on each request. Prefer HMAC for automation.

This module makes no compliance claim.
