#!/usr/bin/env bash
# Runs the module test suites against a site from setup-test-env.sh.
#
# Usage:
#   scripts/run-tests.sh SITE_DIR [PHPUNIT_ARGUMENT...]
#
# Set PHP_BIN to choose a PHP runtime and PCC_TEST_PORT to choose a port.
# The script refuses a busy port, stops only the server it started, and fails
# when PHPUnit runs zero tests.
#
# PHPUnit runs once for the base tests and once for the adapter tests, because
# PHPUnit 9 ignores a second test path. JUnit reports: SITE_DIR/junit-base.xml and
# SITE_DIR/junit-adapter.xml. With PHPUnit arguments, such as a filter, one group
# can be empty. Without arguments, both groups must run tests.
set -euo pipefail

if [ "$#" -lt 1 ]; then
  echo "Usage: $0 SITE_DIR [PHPUNIT_ARGUMENT...]" >&2
  exit 2
fi

site="$(cd "$1" && pwd)"
shift
php_bin="${PHP_BIN:-php}"
port="${PCC_TEST_PORT:-8891}"
web="$site/web"
junit_base="$site/junit-base.xml"
junit_adapter="$site/junit-adapter.xml"
mkdir -p "$web/sites/simpletest/browser_output" "$web/sites/default/files"
rm -f "$site/junit.xml" "$junit_base" "$junit_adapter"

export SIMPLETEST_BASE_URL="http://127.0.0.1:$port"
export SIMPLETEST_DB="sqlite://localhost/$site/test.sqlite"
export BROWSERTEST_OUTPUT_DIRECTORY="$web/sites/simpletest/browser_output"
export PHP_CLI_SERVER_WORKERS=4
# Drupal 10 uses the Symfony PHPUnit bridge. Drupal 11.5 deprecates this variable.
case "$(sed -n "s/.*const VERSION = '\([^']*\)'.*/\1/p" "$web/core/lib/Drupal.php")" in
  10.*) export SYMFONY_DEPRECATIONS_HELPER="${SYMFONY_DEPRECATIONS_HELPER:-disabled}" ;;
esac
"$(dirname "$0")/apply-test-patch.sh" "$site"

port_open() {
  (exec 3<>"/dev/tcp/127.0.0.1/$port") 2>/dev/null
}

if port_open; then
  echo "Port $port is already in use. A stale server would test old code." >&2
  exit 1
fi

(cd "$web" && exec "$php_bin" -S "127.0.0.1:$port" .ht.router.php) >"$site/server.log" 2>&1 &
server=$!

stop_server() {
  # Workers are children of this exact server process.
  pkill -TERM -P "$server" 2>/dev/null || true
  kill "$server" 2>/dev/null || true
  wait "$server" 2>/dev/null || true
  if port_open; then
    echo "Port $port is still in use after cleanup." >&2
  fi
}
trap stop_server EXIT

for _ in $(seq 1 50); do
  if port_open; then
    break
  fi
  kill -0 "$server" 2>/dev/null || { echo "The test server exited. See $site/server.log." >&2; exit 1; }
  sleep 0.2
done
port_open || { echo "The test server did not open port $port." >&2; exit 1; }

count_tests() {
  local tests=""
  if [ -f "$1" ]; then
    tests="$(sed -n 's/.*<testsuite [^>]*tests="\([0-9]*\)".*/\1/p' "$1" | head -n 1)"
  fi
  echo "${tests:-0}"
}

cd "$web"
module=modules/contrib/project_context_connector
argument_count=$#
# PHPUnit 12 fails an empty group by default. The counts below decide this case.
case "$("$php_bin" "$site/vendor/bin/phpunit" --help)" in
  *--do-not-fail-on-empty-test-suite*) set -- --do-not-fail-on-empty-test-suite "$@" ;;
esac
status=0
"$php_bin" "$site/vendor/bin/phpunit" -c core --log-junit "$junit_base" "$@" "$module/tests" || status=$?
adapter_status=0
"$php_bin" "$site/vendor/bin/phpunit" -c core --log-junit "$junit_adapter" "$@" "$module/modules/project_context_connector_tool/tests" || adapter_status=$?
if [ "$status" -ne 0 ] || [ "$adapter_status" -ne 0 ]; then
  echo "PHPUnit failed: base exit $status, adapter exit $adapter_status." >&2
  exit "$((status != 0 ? status : adapter_status))"
fi

base_tests="$(count_tests "$junit_base")"
adapter_tests="$(count_tests "$junit_adapter")"
echo "Tests run: base $base_tests, adapter $adapter_tests."
if [ "$((base_tests + adapter_tests))" -eq 0 ]; then
  echo "PHPUnit ran zero tests." >&2
  exit 1
fi
if [ "$argument_count" -eq 0 ] && { [ "$base_tests" -eq 0 ] || [ "$adapter_tests" -eq 0 ]; }; then
  echo "An unfiltered run must run base and adapter tests." >&2
  exit 1
fi
