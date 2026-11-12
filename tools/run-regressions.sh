#!/usr/bin/env bash
# Single source of truth for SchoolFlow CRM release and public-form CI.
# Keep the existing workflow job names so branch-protection checks remain stable.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

for command in php node; do
  if ! command -v "$command" >/dev/null 2>&1; then
    echo "Missing required runtime: $command" >&2
    exit 1
  fi
done

echo "::group::PHP syntax"
while IFS= read -r -d '' file; do
  php -l "$file" >/dev/null
done < <(find app_private bin public_html tests tools wordpress -type f -name '*.php' -print0 | sort -z)
echo "::endgroup::"

echo "::group::PHP integration regressions"
# HTTP artifact checks require the generated release, so run them after packaging.
for test in tests/test_*.php; do
  case "${test##*/}" in
    test_http_json.php|test_http_xlsx.php|test_layout.php|test_csp.php) continue ;;
  esac
  echo "Running $test"
  php "$test"
done
echo "::endgroup::"

echo "::group::JavaScript syntax and UI regressions"
for source in public_html/static/*.js tests/check_*.js; do
  node --check "$source"
done
for check in tests/check_*.js; do
  echo "Running $check"
  node "$check"
done
echo "::endgroup::"

echo "::group::Public-only DigiOps artifact"
php tools/build-digiops.php
php -l dist/digiops-release/index.php
for asset in .htaccess static/app.js static/management.css static/index.html \
  static/lexicon-form.js static/lexicon-form.css \
  static/schoolflow-short-form.js static/schoolflow-short-form.css \
  static/admissions-chat.js static/admissions-chat.css; do
  test -f "dist/digiops-release/$asset" || { echo "Missing packaged asset: $asset" >&2; exit 1; }
done
test ! -e dist/digiops-release/bin/reset-staging.php
test ! -e dist/digiops-release/bin/verify-store.php
if grep -q "require_once __DIR__ . '/provider_webhooks.php'" dist/digiops-release/index.php; then
  echo "Bundled gateway still requires private provider source" >&2
  exit 1
fi
if grep -Eq "require_once.*(app_private|messaging.php)" dist/digiops-release/index.php; then
  echo "Bundled gateway references non-deployed PHP files" >&2
  exit 1
fi
if grep -q '/static/app.js' dist/digiops-release/static/index.html && \
   ! grep -q '/scrm/static/app.js' dist/digiops-release/static/index.html; then
  echo "Bundled UI refers to an invalid script path" >&2
  exit 1
fi
if find dist/digiops-release -type f \
   \( -name '*.json' -o -name '*.key' -o -name '*password*' -o -name '*.env' \) | grep -q .; then
  echo "Public release contains forbidden private files" >&2
  exit 1
fi
echo "::endgroup::"

echo "::group::Post-build HTTP and security checks"
for test in tests/test_http_json.php tests/test_http_xlsx.php tests/test_layout.php tests/test_csp.php; do
  echo "Running $test"
  php "$test"
done
echo "::endgroup::"
echo "PASS: unified PHP, JavaScript, popup, access-control and release regression suite"
