# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.1.5] - 2026-02-16

### Fixed
- Tests: Removed 'final' keyword from SignatureValidator class to allow PHPUnit mocking in unit tests
- Tests: Fixed BrowserTestBase service override - replaced containerBuild() with setUp() and $this->container->set()
- Tests: Fixed 3 PHPUnit errors where SignatureValidator could not be mocked due to final keyword
- Tests: Fixed functional test 404 error by using correct Drupal 11 service override pattern
- CI/CD: Fixed PHPStan error about undefined static method containerBuild() (method only exists in KernelTestBase, not BrowserTestBase)

### Note
All changes in this release are test fixes only. No functional changes from 1.1.1.

## [1.1.4] - 2026-02-16

### Fixed
- CI/CD pipeline: Removed 'final' keyword from RateLimiter class to allow test doubles to extend it
- CI/CD pipeline: Changed containerBuild() from protected to public static for Drupal 11 compatibility
- Tests: Fixed PHPStan error "Call to undefined static method containerBuild()"
- Tests: Fixed PHPUnit/PHPStan error "Cannot extend final class RateLimiter"

### Note
All changes in this release are test configuration only. No functional changes from 1.1.1.

## [1.1.3] - 2026-02-16

### Fixed
- CI/CD pipeline: Removed custom phpstan.neon to use Drupal GitLab CI default configuration
- CI/CD pipeline: Allows CI templates to handle PHPStan setup automatically

### Note
All changes in this release are CI/build configuration only. No functional changes from 1.1.1.

## [1.1.2] - 2026-02-16

### Fixed
- CI/CD pipeline: Simplified phpstan.neon configuration to work correctly with Drupal GitLab CI
- CI/CD pipeline: Renamed phpstan.neon.dist to phpstan.neon following contrib module best practices
- CI/CD pipeline: Removed paths parameter from phpstan.neon that was causing GitLab CI failures
- CI/CD pipeline: Updated .gitignore to allow committing phpstan.neon

### Note
All changes in this release are CI/build configuration only. No functional changes from 1.1.1.

## [1.1.1] - 2026-02-16

### Fixed
- CI/CD pipeline issues: Added missing words to cspell dictionary (WCAG, phpcs, phpstan, etc.)
- CI/CD pipeline issues: Fixed phpcs line length violation in SignatureValidator
- CI/CD pipeline issues: Added phpstan.neon.dist configuration with Drupal support for proper static analysis
- Documentation: Replaced "whitelisted" with "allow-listed" for inclusive language
- Repository: Added .gitignore to exclude vendor/ and build artifacts

### Note
All changes in this release are CI/build configuration only. No functional changes from 1.1.0.

## [1.1.0] - 2026-02-16

### Added
- Custom access checker (`SignatureAccessChecker`) for signed route provides proper Drupal access control integration with audit trail
- Configuration option to expose/hide database version (`expose_database_version`) for minimal information disclosure
- Comprehensive security documentation in README.md and SECURITY.md with secret management best practices
- AI agent integration examples (Claude/ChatGPT, Slack bots, Python automation, MCP servers)
- Detailed troubleshooting section in README with common issues and solutions
- Authentication methods comparison table in README
- Quick start guide (5 minutes to production)
- CORS security warnings in admin form for HTTP origins

### Changed
- **BREAKING**: Wildcard CORS patterns (`*.example.com`) now match ONLY subdomains, not the base domain itself. To match both, add both patterns explicitly.
- Improved CORS origin validation with scheme enforcement (http vs https)
- Enhanced timestamp validation in HMAC signature validator with sanity checks (2000-2100 range, no leading zeros)
- Rate limiting now applies to OPTIONS requests to prevent CORS probing attacks
- README significantly expanded with security best practices, use cases, and integration guides
- SECURITY.md enhanced with threat model, secret rotation procedures, and compliance considerations
- Settings form provides descriptive help text for all configuration options

### Fixed
- Signed route now uses proper Drupal access checking instead of `_access: "TRUE"`, improving security and auditability
- CORS wildcard patterns now correctly enforce scheme matching (security fix)
- Timestamp validation rejects malformed inputs that could bypass validation
- Database version exposure is now opt-in via configuration (was always exposed before)

### Security
- Improved access control for signed endpoint with custom access checker
- Enhanced CORS validation prevents unintended origin matches
- Stricter timestamp validation prevents edge case bypasses
- OPTIONS request rate limiting prevents reconnaissance attacks
- Comprehensive secret management documentation reduces misconfiguration risks

## [1.0.0] - 2025-08-23

### Added
- Read-only `/project-context-connector/snapshot` endpoint (permission-gated)
- Signed endpoint `/project-context-connector/snapshot/signed` with HMAC authentication
- Drush command `pcc:snapshot` outputs same JSON for local use and CI/CD
- Rate limiting via Flood API with configurable threshold and window
- HTTP caching with proper cache contexts (`user.permissions`, `headers:Origin`) and cache tags
- CORS allow-list with exact and wildcard subdomain support
- Optional per-project security update status from Update Manager (cached, no outbound requests)
- Admin configuration form at `/admin/config/development/project-context-connector`
- Kernel, Unit, and Functional tests
- CI/CD pipelines for GitLab and GitHub Actions
- PHPStan level 5 static analysis
- PSR-12 and Drupal coding standards compliance
- WCAG 2.2 AA accessible admin form
- Internationalization support with translatable strings
- SECURITY.md with vulnerability reporting process
- CONTRIBUTING.md with development guidelines
- Example client code (curl, Node.js, Postman)
- Comprehensive documentation

### Security
- Permission-gated endpoints with dedicated Drupal permission
- HMAC-SHA256 signed requests with timestamp-based replay protection
- Rate limiting prevents brute force and DoS attacks
- No PII exposure, read-only operations
- Input validation on all admin form fields
- Timing-safe signature comparison with `hash_equals()`
- Security-focused design with no remote code execution, no write endpoints, no telemetry

[Unreleased]: https://www.drupal.org/project/project_context_connector
[1.1.0]: https://www.drupal.org/project/project_context_connector/releases/1.1.0
[1.0.0]: https://www.drupal.org/project/project_context_connector/releases/1.0.0
