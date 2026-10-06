#!/usr/bin/env bash
set -euo pipefail

# Production entry point for QA Run schema inspection/migration. Confirmation
# happens before Git or database checks; Git integrity is then verified as the
# deployment actor, while Laravel runs only as the runtime account.
usage() { echo "Usage: $0 RELEASE_DIR --preflight|--execute" >&2; exit 2; }
[[ $# -eq 2 ]] || usage
release_input=$1
mode=$2
[[ "$mode" == "--preflight" || "$mode" == "--execute" ]] || usage

fail() { echo "QA_RUN_MIGRATION_WRAPPER_FAIL: $1" >&2; exit 1; }
[[ "$(id -u)" -eq 0 ]] || fail "trusted deployment identity is required"

if [[ "$mode" == "--execute" ]]; then
  printf 'Apply the exact Central QA Run migration to Production? Type YES to continue: '
  IFS= read -r answer || answer=''
  [[ "$answer" == "YES" ]] || { echo 'Command cancelled.' >&2; exit 1; }
fi

[[ -d "$release_input" && ! -L "$release_input" ]] || fail "unsafe release directory"
release=$(realpath "$release_input") || fail "release path unavailable"
release_root_real=$(realpath /www/wwwroot/releases) || fail "release root unavailable"
[[ "$release" == "$release_root_real"/* ]] || fail "release is outside the immutable release root"

script_root=$release/scripts/production
identity_verifier=$script_root/verify_qa_run_release_identity.sh
runtime_verifier=$script_root/verify_runtime_release.sh
runtime_artisan=$script_root/run_artisan_as_runtime_user.sh
runtime_links=$script_root/verify_runtime_links.sh
runtime_ownership=$script_root/verify_runtime_ownership.sh
for script in "$identity_verifier" "$runtime_verifier" "$runtime_artisan" "$runtime_links" "$runtime_ownership"; do
  [[ -f "$script" && ! -L "$script" && -x "$script" ]] || fail "required deployment script missing or not executable"
done

active_link=/www/wwwroot/43.160.241.126
expected_active=b87bac3a2bc6eaf32cada9cdbfa19c565d5e61b2
prior_qa=e9d0be2efe82bd0882377799d17ea9fb5d6efbfe
expected_baseline=7d6e73c12f6de23c24e5dd62312df53fcef8d497
expected_ref=codex/zixuan-qa-run-final-candidate

RELEASES_ROOT=/www/wwwroot/releases PHP_BIN=/usr/bin/php83 \
  "$identity_verifier" "$release" "$active_link" "$expected_active" "$prior_qa" "$expected_baseline" "$expected_ref"
candidate_sha=$(tr -d '\r\n' < "$release/.release-commit")
JSON_PHP_BIN=/usr/bin/php83 PHP_BIN=/usr/bin/php83 "$runtime_links" "$release"
JSON_PHP_BIN=/usr/bin/php83 PHP_BIN=/usr/bin/php83 "$runtime_ownership" "$release"

runtime_user=www
PHP_BIN=/usr/bin/php83 runuser -u "$runtime_user" -- "$runtime_verifier" "$release" staged "$active_link" "$candidate_sha"

export QA_RUN_VERIFIED_RELEASE_SHA=$candidate_sha
if [[ "$mode" == "--execute" ]]; then
  RUNTIME_USER=www PHP_BIN=/usr/bin/php83 "$runtime_artisan" "$release" finance:qa-runs-migrate --execute --deployment-verified
else
  RUNTIME_USER=www PHP_BIN=/usr/bin/php83 "$runtime_artisan" "$release" finance:qa-runs-migrate --deployment-verified
fi
