#!/usr/bin/env bash
set -euo pipefail

release_dir=${1:-$(pwd)}
source_repo=${SOURCE_REPO:-$release_dir}
php_bin=${PHP_BIN:-/usr/bin/php83}
json_php_bin=${JSON_PHP_BIN:-$php_bin}
baseline_file=${BASELINE_FILE:-$(cd "$(dirname "$0")/../.." && pwd)/config/production-baseline.json}
manifest="$release_dir/.release-manifest.json"

test -f "$baseline_file" || { echo "GUARD_FAIL: baseline contract missing" >&2; exit 1; }
test -f "$manifest" || { echo "GUARD_FAIL: release manifest missing" >&2; exit 1; }
test -x "$php_bin" || { echo "GUARD_FAIL: required PHP binary missing ($php_bin)" >&2; exit 1; }

read_json() {
  "$json_php_bin" -r '$d=json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR); $v=$d[$argv[2]] ?? null; if (!is_string($v)) { fwrite(STDERR, "GUARD_FAIL: missing ".$argv[2]."\n"); exit(1); } echo $v;' "$1" "$2"
}

expect() {
  [ "$1" = "$2" ] || { echo "GUARD_FAIL: $3 (expected $2, got $1)" >&2; exit 1; }
}

expect "$(read_json "$baseline_file" production_host)" "43.160.241.126" production_host
expect "$(read_json "$baseline_file" php_binary)" "/usr/bin/php83" php_binary
expect "$(read_json "$baseline_file" php_fpm_socket)" "/tmp/php-cgi-83.sock" php_fpm_socket
expect "$(read_json "$baseline_file" release_root)" "/www/wwwroot/releases" release_root

manifest_sha=$(read_json "$manifest" commit_sha)
manifest_name=$(read_json "$manifest" release_name)
manifest_php=$(read_json "$manifest" php_binary)
manifest_fpm=$(read_json "$manifest" php_fpm_socket)
manifest_root=$(read_json "$manifest" release_root)
baseline_sha=$(read_json "$manifest" baseline_sha)
github_ref=$(read_json "$manifest" github_ref)
homepage_view_sha=$(read_json "$manifest" homepage_view_sha256)
homepage_assets_sha=$(read_json "$manifest" homepage_assets_sha256)
actual_homepage_assets_sha=$(cd "$release_dir/public/assets/bowen-school" && find . -type f -print0 | sort -z | while IFS= read -r -d '' f; do printf '%s %s\n' "${f#./}" "$(sha256sum "$f" | awk '{print $1}')"; done | sha256sum | awk '{print $1}')
expect "$manifest_name" "$(basename "$release_dir")" release_name
expect "$manifest_php" "/usr/bin/php83" manifest_php_binary
expect "$manifest_fpm" "/tmp/php-cgi-83.sock" manifest_php_fpm_socket
expect "$manifest_root" "/www/wwwroot/releases" manifest_release_root
expect "$homepage_view_sha" "$(read_json "$baseline_file" approved_homepage_view_sha256)" homepage_view_sha256
expect "$homepage_assets_sha" "$(read_json "$baseline_file" approved_homepage_assets_sha256)" homepage_assets_sha256
expect "$homepage_assets_sha" "$actual_homepage_assets_sha" actual_homepage_assets_sha256
test -f "$release_dir/resources/views/bowen-school/home.blade.php" || { echo "GUARD_FAIL: homepage view missing" >&2; exit 1; }
test -d "$release_dir/public/assets/bowen-school" || { echo "GUARD_FAIL: homepage assets missing" >&2; exit 1; }

# Verify the rendered host-specific root branch, not just source hashes. This
# catches a stale route/config/vhost path that would otherwise serve the legacy
# SaaS landing page while all homepage files remain present.
render_probe=$(mktemp)
trap 'rm -f "$render_probe"' EXIT
curl --fail --silent --show-error --max-time 20 -H 'Host: school.mmbowen.com' \
  "${RELEASE_GUARD_RENDER_URL:-http://127.0.0.1/}" -o "$render_probe" \
  || { echo "GUARD_FAIL: homepage render probe failed" >&2; exit 1; }
grep -Fq '让每一种成长，都通向更广阔的世界' "$render_probe" \
  || { echo "GUARD_FAIL: rendered Bowen homepage fingerprint missing" >&2; exit 1; }
grep -Fq 'BOWEN INTERNATIONAL EDUCATION' "$render_probe" \
  || { echo "GUARD_FAIL: rendered Bowen homepage section missing" >&2; exit 1; }
if grep -Fq 'eSchool-Saas - Manage Your School' "$render_probe" || grep -Fq '/assets/home_page/' "$render_probe"; then
  echo "GUARD_FAIL: rendered legacy landing page detected" >&2
  exit 1
fi
actual_sha=$(git -C "$source_repo" rev-parse HEAD)
expect "$manifest_sha" "$actual_sha" manifest_commit_sha
git -C "$source_repo" merge-base --is-ancestor "$(read_json "$baseline_file" accepted_production_sha)" "$actual_sha" || { echo "GUARD_FAIL: candidate is not an accepted-baseline descendant" >&2; exit 1; }
git -C "$source_repo" show-ref --verify --quiet "refs/remotes/origin/$github_ref" || { echo "GUARD_FAIL: GitHub ref unavailable" >&2; exit 1; }
test -L "$release_dir/.env" || { echo "GUARD_FAIL: .env must be a symlink" >&2; exit 1; }
test -L "$release_dir/storage" || { echo "GUARD_FAIL: storage must be a symlink" >&2; exit 1; }
test -L "$release_dir/public/storage" || { echo "GUARD_FAIL: public/storage must be a symlink" >&2; exit 1; }
test -d "$release_dir/bootstrap/cache" && test -w "$release_dir/bootstrap/cache" || { echo "GUARD_FAIL: bootstrap/cache unavailable" >&2; exit 1; }
echo "RELEASE_GUARD_PASS:$actual_sha"
