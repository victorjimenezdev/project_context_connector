# Testing

## Build a site

`scripts/setup-test-env.sh` builds a disposable Drupal site that links this
module. Put the site outside the module directory. The script refuses a target
that exists and is not empty, and a target inside the module.

```bash
PHP_BIN=/path/to/php8.4 scripts/setup-test-env.sh /tmp/pcc-d114 '~11.4.0' \
  'drush/drush:^13' 'drupal/tool:1.0.0-beta12' \
  'drupal/mcp_server:2.0.0-beta7' 'drupal/mcp_server_tool_bridge:1.0.0-beta5'
```

## Drupal 12 test-only patch

Unmodified Drupal core 12.0.0-beta2 cannot run these kernel and functional tests.
`core/tests/Drupal/TestTools/ErrorHandler/DrupalDebugClassLoader.php:65` passes a
NULL declaring class from Symfony metadata to a string parameter, so tests stop
with a `TypeError`. A core kernel test reproduces this failure without this module.

`scripts/apply-test-patch.sh` changes only that test-harness file in a disposable
site. It applies only to 12.0.0-beta2 and only when the file has the expected
SHA-256. It leaves other versions unchanged and refuses changed bytes. Production
core is not changed. The Drupal 12 result therefore means "passes on beta2 with
this test-only patch". It does not mean that unmodified core passes the suite.
Real-site HTTP and Drush checks do not load this file.

## Run the tests

`scripts/run-tests.sh` starts the PHP web server and runs unit, kernel and
functional suites on SQLite. Functional tests send real HTTP requests through
routing, access checks, Flood and page caches. The script refuses a port that is
in use, stops only the server it started, and fails when PHPUnit runs zero tests.

```bash
PHP_BIN=/path/to/php8.4 scripts/run-tests.sh /tmp/pcc-d114
```

The script runs PHPUnit twice, because PHPUnit 9 ignores a second test path:

| Group | Test path | JUnit report |
| --- | --- | --- |
| Base | `tests` | `SITE_DIR/junit-base.xml` |
| Adapter | `modules/project_context_connector_tool/tests` | `SITE_DIR/junit-adapter.xml` |

It does not scan other directories, so a local `vendor` directory in the module
root is not tested. Arguments after `SITE_DIR` go to both PHPUnit runs. With
arguments, one group can be empty, but the total cannot be zero. Without
arguments, each group must run at least one test.

## Static checks

Run these from the site directory:

```bash
# Base module. Use this configuration when drupal/tool is absent.
vendor/bin/phpstan analyse -c web/modules/contrib/project_context_connector/phpstan.neon
# Base module and adapter. Use this configuration when drupal/tool is installed.
vendor/bin/phpstan analyse -c web/modules/contrib/project_context_connector/phpstan-adapter.neon
vendor/bin/phpcs --standard=Drupal,DrupalPractice --extensions=php,module,inc,install,yml \
  --ignore='*/.Records/*,*/vendor/*' web/modules/contrib/project_context_connector
composer audit --no-dev
```

The PHPStan configurations name the owned paths, and the PHPCS command ignores
`vendor`, so a local `vendor` directory in the module root is not analyzed.

## Coverage notes

Tests use PHPUnit attributes. PHPUnit 9 on Drupal 10.6 ignores attributes, so
tests avoid data providers and still run. Core 10.6 `run-tests.sh` discovery
requires `@group`, so the GitLab 10.6 job sets `_PHPUNIT_CONCURRENT: "0"` to run
PHPUnit directly, as `scripts/run-tests.sh` does. Adapter tests skip with a stated reason
when `drupal/tool` is absent. `McpBridgeTest` also skips without
`drupal/mcp_server_tool_bridge`.

## Verified versions

| Core | PHP | Drush | PHPUnit | Adapter |
| --- | --- | --- | --- | --- |
| 10.6.20 | 8.3 | 13.8.0 | 9.6 | Tool beta12 |
| 11.4.11 | 8.4 | 13.8.0 | 11.5 | Tool beta12, MCP Server 2.0.0-beta7, bridge 1.0.0-beta5 |
| 11.5.0-beta1 | 8.5 | 13.8.0 | 11.5 | Tool beta12, MCP Server 2.0.0-beta7, bridge 1.0.0-beta5 |
| 12.0.0-beta2, test patch | 8.5 | 14.x-dev | 12.5 | Absent: Tool declares only `^10.5 \|\| ^11` |

The one remaining deprecation report comes from Tool
`ValueViolationsTrait` on PHP 8.5, not from this module. PHPUnit 9 reports attribute
classes as missing to PHPStan, so 10.6 analysis covers source and leaves out tests.

CI has not run on the project's hosted runners yet. The GitLab next major job uses
the template's core development branch without the patch, so it does not block.
