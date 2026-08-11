#!/usr/bin/env zsh
set -euo pipefail

repo_root="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$repo_root"

env_value() {
  local key="$1"
  sed -n -E "s/^${key}=['\"]?([^'\"#[:space:]]+).*$/\\1/p" .env 2>/dev/null | tail -n 1
}

app_env="${APP_ENV:-$(env_value APP_ENV)}"
app_url="${APP_URL:-$(env_value APP_URL)}"
db_name="${DB_DATABASE:-$(env_value DB_DATABASE)}"
local_url="${LOCAL_QA_BASE_URL:-${app_url:-http://127.0.0.1:8000}}"

if [[ "$app_env" == "production" || "$app_url" == *"school.mmbowen.com"* || "$app_url" == *"staging.school.mmbowen.com"* || "$db_name" == sql_43_160_241_126 || "$db_name" == eschool_saas_* ]]; then
  print -u2 "Refusing local QA: production or staging environment identity detected."
  exit 1
fi

case "$local_url" in
  http://127.0.0.1*|https://127.0.0.1*|http://localhost*|https://localhost*|http://[[]::1[]]*|https://[[]::1[]]*) ;;
  *) print -u2 "Refusing local QA: LOCAL_QA_BASE_URL must be local."; exit 1 ;;
esac

print "LOCAL QA GUARD PASS: env=${app_env:-unset} url=$local_url database=${db_name:-unset}"

if [[ "${1:-}" == "--check" ]]; then
  exit 0
fi

if [[ -x scripts/qa/seed-local.sh ]]; then
  print "Running the repository local synthetic seed hook."
  scripts/qa/seed-local.sh
else
  print "No repository local seed hook yet; using the existing local synthetic fixture set."
fi

php artisan test ${LOCAL_QA_PHPUNIT_TARGET:-tests/Unit}
LOCAL_QA_BASE_URL="$local_url" npx playwright test --config=playwright.local.config.cjs
