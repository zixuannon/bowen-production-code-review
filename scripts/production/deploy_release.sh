#!/usr/bin/env bash
set -euo pipefail

# Immutable, commit-addressed release builder/switcher.  The default mode is a
# dry run; --switch is an explicit deployment gate for an already verified
# build.  No file is copied into an active release tree.
usage() { echo "Usage: $0 <commit-sha> [--switch]" >&2; exit 2; }
[[ $# -ge 1 && $# -le 2 ]] || usage
commit=$1
switch=${2:-}
[[ "$commit" =~ ^[0-9a-f]{40}$ ]] || { echo "DEPLOY_FAIL: full commit SHA required" >&2; exit 1; }
[[ -z "$switch" || "$switch" == "--switch" ]] || usage

repo=${REPO_ROOT:-/www/wwwroot/eschool-github/bowen-production-code-review}
release_root=${RELEASE_ROOT:-/www/wwwroot/releases}
active_link=${ACTIVE_LINK:-/www/wwwroot/43.160.241.126}
php_bin=${PHP_BIN:-/usr/bin/php83}
release_name=${RELEASE_NAME:-eschool-rc-${commit:0:12}-consolidated}
release_dir="$release_root/$release_name"

[[ -d "$repo/.git" ]] || { echo "DEPLOY_FAIL: repository unavailable" >&2; exit 1; }
[[ -x "$php_bin" ]] || { echo "DEPLOY_FAIL: PHP 8.3 binary unavailable" >&2; exit 1; }
git -C "$repo" cat-file -e "$commit^{commit}" || { echo "DEPLOY_FAIL: candidate commit unavailable" >&2; exit 1; }
base=$("$php_bin" -r 'echo json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR)["accepted_production_sha"];' "$repo/config/production-baseline.json")
git -C "$repo" merge-base --is-ancestor "$base" "$commit" || { echo "DEPLOY_FAIL: candidate is not baseline descendant" >&2; exit 1; }
[[ -L "$active_link/.env" && -L "$active_link/storage" ]] || { echo "DEPLOY_FAIL: shared runtime links missing" >&2; exit 1; }

[[ ! -e "$release_dir" ]] || { echo "DEPLOY_FAIL: release directory already exists" >&2; exit 1; }
git -C "$repo" worktree add --detach "$release_dir" "$commit" >/dev/null
cleanup() { if [[ "$switch" != "--switch" ]]; then git -C "$repo" worktree remove --force "$release_dir" >/dev/null 2>&1 || true; fi; }
trap cleanup EXIT
ln -s "$(readlink "$active_link/.env")" "$release_dir/.env"
ln -s "$(readlink "$active_link/storage")" "$release_dir/storage"
if [[ -L "$release_dir/public/storage" || -e "$release_dir/public/storage" ]]; then
  rm -f "$release_dir/public/storage"
fi
ln -s "$(readlink "$active_link/public/storage")" "$release_dir/public/storage"
mkdir -p "$release_dir/bootstrap/cache"
baseline=$($php_bin -r 'echo json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR)["accepted_production_sha"];' "$repo/config/production-baseline.json")
SOURCE_REPO="$release_dir" "$release_dir/scripts/production/write_release_manifest.sh" "$release_dir" "$baseline" "${GITHUB_REF:-main}"
"$repo/scripts/production/verify_release_guard.sh" "$release_dir"
echo "DRY_RUN_PASS:$commit:$release_dir"

if [[ "$switch" == "--switch" ]]; then
  [[ -f "$release_dir/.release-manifest.json" ]] || { echo "DEPLOY_FAIL: manifest missing" >&2; exit 1; }
  ln -sfn "$release_dir" "$active_link"
  echo "SWITCH_PASS:$commit:$release_dir"
fi
