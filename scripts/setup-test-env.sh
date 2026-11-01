#!/usr/bin/env bash
# Builds a disposable Drupal site for module tests.
#
# Usage:
#   scripts/setup-test-env.sh TARGET_DIR CORE_CONSTRAINT [EXTRA_PACKAGE...]
#
# Example:
#   scripts/setup-test-env.sh /tmp/pcc-d114 '~11.4.0' 'drush/drush:^13' \
#     'drupal/tool:*@beta'
#
# Set PHP_BIN to choose a PHP runtime. Composer runs under that runtime.
set -euo pipefail

if [ "$#" -lt 2 ]; then
  echo "Usage: $0 TARGET_DIR CORE_CONSTRAINT [EXTRA_PACKAGE...]" >&2
  exit 2
fi

module_dir="$(cd "$(dirname "$0")/.." && pwd)"
target="$1"
core="$2"
shift 2
php_bin="${PHP_BIN:-php}"
composer_bin="$(command -v composer)"
composer() { "$php_bin" "$composer_bin" --no-interaction --working-dir="$target" "$@"; }

case "$target" in
  /*) ;;
  *) target="$PWD/$target" ;;
esac
case "$target/" in
  "$module_dir"/*)
    echo "Refusing $target: test discovery walks the module tree." >&2
    exit 1
    ;;
esac
if [ -e "$target" ] && { [ ! -d "$target" ] || [ -n "$(ls -A "$target")" ]; }; then
  echo "Refusing $target: it exists and is not an empty directory." >&2
  exit 1
fi

mkdir -p "$target"
cat > "$target/composer.json" <<JSON
{
  "name": "pcc/test-site",
  "type": "project",
  "license": "GPL-2.0-or-later",
  "minimum-stability": "dev",
  "prefer-stable": true,
  "repositories": [
    {"type": "path", "url": "$module_dir", "options": {"symlink": true, "versions": {"drupal/project_context_connector": "2.0.x-dev"}}},
    {"type": "composer", "url": "https://packages.drupal.org/8"}
  ],
  "config": {
    "sort-packages": true,
    "allow-plugins": {
      "composer/installers": true,
      "dealerdirect/phpcodesniffer-composer-installer": true,
      "drupal/core-composer-scaffold": true,
      "php-http/discovery": true,
      "phpstan/extension-installer": true,
      "symfony/runtime": true,
      "tbachert/spi": true
    }
  },
  "extra": {
    "drupal-scaffold": {"locations": {"web-root": "web/"}},
    "installer-paths": {
      "web/core": ["type:drupal-core"],
      "web/modules/contrib/{\$name}": ["type:drupal-module"],
      "web/themes/contrib/{\$name}": ["type:drupal-theme"],
      "web/profiles/contrib/{\$name}": ["type:drupal-profile"]
    }
  }
}
JSON

composer require --no-update \
  "composer/installers:^2" \
  "drupal/core-composer-scaffold:$core" \
  "drupal/core-recommended:$core" \
  "drupal/project_context_connector:2.0.x-dev" \
  "$@"
composer require --dev --no-update "drupal/core-dev:$core"
composer update --prefer-dist --no-progress
# Some core archives leave out core/tests. A source install includes them.
if [ ! -f "$target/web/core/tests/bootstrap.php" ]; then
  composer reinstall drupal/core --prefer-install=source
fi
mkdir -p "$target/web/sites/simpletest/browser_output"
"$module_dir/scripts/apply-test-patch.sh" "$target"
