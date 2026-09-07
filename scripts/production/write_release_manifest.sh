#!/usr/bin/env bash
set -euo pipefail

release_dir=${1:?usage: write_release_manifest.sh RELEASE_DIR BASELINE_SHA GITHUB_REF}
baseline_sha=${2:?usage: write_release_manifest.sh RELEASE_DIR BASELINE_SHA GITHUB_REF}
github_ref=${3:?usage: write_release_manifest.sh RELEASE_DIR BASELINE_SHA GITHUB_REF}

test -d "$release_dir"
php_bin=${PHP_BIN:-/usr/bin/php83}
json_php_bin=${JSON_PHP_BIN:-$php_bin}
test -x "$php_bin" || { echo "GUARD_FAIL: missing PHP runtime $php_bin" >&2; exit 1; }
source_repo=${SOURCE_REPO:-$release_dir}
commit_sha=$(git -C "$source_repo" rev-parse HEAD)
release_name=$(basename "$release_dir")
php_version=$($php_bin -r 'echo PHP_VERSION;')
built_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)
homepage_view_sha256=$(sha256sum "$release_dir/resources/views/bowen-school/home.blade.php" | awk '{print $1}')
homepage_assets_sha256=$(cd "$release_dir/public/assets/bowen-school" && find . -type f -print0 | sort -z | while IFS= read -r -d '' f; do printf '%s %s\n' "${f#./}" "$(sha256sum "$f" | awk '{print $1}')"; done | sha256sum | awk '{print $1}')

cat > "$release_dir/.release-manifest.json" <<EOF
{
  "commit_sha": "$commit_sha",
  "release_name": "$release_name",
  "built_at": "$built_at",
  "php_version": "$php_version",
  "baseline_sha": "$baseline_sha",
  "github_ref": "$github_ref",
  "homepage_view_sha256": "$homepage_view_sha256",
  "homepage_assets_sha256": "$homepage_assets_sha256",
  "production_host": "43.160.241.126",
  "php_binary": "/usr/bin/php83",
  "php_fpm_socket": "/tmp/php-cgi-83.sock",
  "release_root": "/www/wwwroot/releases",
  "shared_env_target": "/www/wwwroot/shared/eschool/.env",
  "shared_storage_target": "/www/wwwroot/shared/eschool/storage"
}
EOF

$json_php_bin -r 'json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);' "$release_dir/.release-manifest.json"
echo "MANIFEST_WRITTEN:$release_dir/.release-manifest.json"
