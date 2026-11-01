#!/usr/bin/env bash
# Applies the test-only core patch to a disposable Drupal 12.0.0-beta2 site.
# Other core versions are left unchanged. Changed loader bytes are refused.
#
# Usage:
#   scripts/apply-test-patch.sh SITE_DIR
set -euo pipefail

if [ "$#" -ne 1 ]; then
  echo "Usage: $0 SITE_DIR" >&2
  exit 2
fi

module_dir="$(cd "$(dirname "$0")/.." && pwd)"
site="$(cd "$1" && pwd)"
patch_file="$module_dir/scripts/patches/drupal-12.0.0-beta2-debug-class-loader-null-declaring-class.patch"
loader="$site/web/core/tests/Drupal/TestTools/ErrorHandler/DrupalDebugClassLoader.php"
target_version='12.0.0-beta2'
before='0275a5e617bd8ec71b9fd116407dd532367b0103889d1d06d99de981919c39dd'
after='9d8e1614582d34ebdb921324fa7cc884721fb564230ef225490346f7234b495c'

version="$(sed -n "s/.*const VERSION = '\([^']*\)'.*/\1/p" "$site/web/core/lib/Drupal.php")"
if [ "$version" != "$target_version" ]; then
  echo "core $version: no test patch applies"
  exit 0
fi

current="$(shasum -a 256 "$loader" | cut -d' ' -f1)"
if [ "$current" = "$after" ]; then
  echo "core $version: test patch already applied"
  exit 0
fi
if [ "$current" != "$before" ]; then
  echo "core $version: refusing to patch unexpected loader $current" >&2
  exit 1
fi

patch --forward --batch -p1 -d "$site/web" -i "$patch_file" >/dev/null
current="$(shasum -a 256 "$loader" | cut -d' ' -f1)"
if [ "$current" != "$after" ]; then
  echo "core $version: patched loader has unexpected hash $current" >&2
  exit 1
fi
echo "core $version: test patch applied"
