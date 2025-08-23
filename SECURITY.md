# Security Policy

## Supported versions
The module follows semantic versioning. Only the latest minor versions within each supported major will receive security fixes.

## Reporting a vulnerability
Please **do not** open public issues for security problems.

1. Email the maintainers via the security contact on the Drupal.org project page.
2. If the project has opted in to **Drupal Security Advisory coverage**, report via the private Security Team issue queue as per Drupal.org guidance.
3. Provide a proof of concept, affected versions, and impact assessment where possible.

We will acknowledge receipt within five business days and coordinate a fix and release.

## Security Advisory coverage (opt‑in)
- Maintainers should request **Security Advisory (SA) coverage** from the Drupal Security Team once a stable release exists. See **“Security Coverage Opt‑in steps”** in this repository.
- Once approved, future security fixes will be coordinated with the Security Team and communicated via SA‑CONTRIB advisories.

## Data handling
- Endpoints are **read‑only** and expose only non‑PII metadata.
- No secrets, tokens, or emails are exposed. No telemetry by default.
- CORS is opt‑in via allow‑list; rate limits are enforced by IP + route.
