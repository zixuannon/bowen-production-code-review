#!/usr/bin/env bash
set -euo pipefail

# Runtime-side immutable release check. It intentionally performs no Git
# operation; deployment identity and ancestry are checked by the root runner.
usage() { echo "Usage: $0 RELEASE_DIR staged|active ACTIVE_LINK EXPECTED_SHA" >&2; exit 2; }
[[ $# -eq 4 ]] || usage
release_input=$1
mode=$2
active_link=$3
expected_sha=$4
[[ "$mode" == "staged" || "$mode" == "active" ]] || usage
php_bin=${PHP_BIN:-/usr/bin/php83}
release_root=${RELEASES_ROOT:-/www/wwwroot/releases}

fail() { echo "RUNTIME_RELEASE_FAIL: $1" >&2; exit 1; }
[[ -x "$php_bin" ]] || fail "PHP binary unavailable"
[[ -d "$release_input" && ! -L "$release_input" ]] || fail "release directory unavailable or indirect"
release=$("$php_bin" -r '$v=realpath($argv[1]); if ($v === false) exit(1); echo $v;' "$release_input") || fail "release path unavailable"
root=$("$php_bin" -r '$v=realpath($argv[1]); if ($v === false) exit(1); echo $v;' "$release_root") || fail "release root unavailable"
active=$("$php_bin" -r '$v=realpath($argv[1]); if ($v === false) exit(1); echo $v;' "$active_link") || fail "active symlink unavailable"
[[ "$release" == "$root"/* ]] || fail "release is outside the immutable release root"
if [[ "$mode" == "active" ]]; then
  [[ "$active" == "$release" ]] || fail "active symlink does not target the verified release"
else
  [[ "$active" != "$release" ]] || fail "candidate is already active; staged check expected"
fi

marker_file=$release/.release-commit
manifest_file=$release/.release-manifest.json
[[ -f "$marker_file" && ! -L "$marker_file" && -r "$marker_file" ]] || fail "release marker is missing or unreadable"
[[ -f "$manifest_file" && ! -L "$manifest_file" && -r "$manifest_file" ]] || fail "release manifest is missing or unreadable"
marker=$(tr -d '\r\n' < "$marker_file")
manifest_sha=$("$php_bin" -r '$d=json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR); echo $d["commit_sha"] ?? "";' "$manifest_file")
manifest_name=$("$php_bin" -r '$d=json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR); echo $d["release_name"] ?? "";' "$manifest_file")
[[ "$marker" == "$expected_sha" && "$manifest_sha" == "$expected_sha" ]] || fail "marker/manifest SHA does not match expected release"
[[ "$manifest_name" == "$(basename "$release")" ]] || fail "manifest release name mismatch"

for required in artisan bootstrap/app.php bootstrap/cache app config database routes resources vendor/autoload.php; do
  [[ -r "$release/$required" ]] || fail "required application path is unreadable: $required"
done
unreadable=$(find "$release/app" "$release/bootstrap" "$release/config" "$release/database" "$release/routes" "$release/resources" "$release/vendor" -type f ! -readable -print -quit 2>/dev/null || true)
[[ -z "$unreadable" ]] || fail "application file is unreadable: $unreadable"
unreadable_mode=$("$php_bin" -r '
  foreach (array_slice($argv, 1) as $root) {
      $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
      foreach ($items as $item) {
          if ($item->isFile() && ((fileperms($item->getPathname()) & 0444) === 0)) { echo $item->getPathname(); exit(0); }
      }
  }
' "$release/app" "$release/bootstrap" "$release/config" "$release/database" "$release/routes" "$release/resources" "$release/vendor")
[[ -z "$unreadable_mode" ]] || fail "application file has no read permission bits: $unreadable_mode"
[[ -w "$release/bootstrap/cache" ]] || fail "runtime user cannot write release bootstrap cache"

if [[ "${SKIP_ARTISAN_BOOT:-0}" != "1" ]]; then
  (cd "$release" && "$php_bin" artisan --version >/dev/null) || fail "Laravel/PHP bootstrap failed"
fi

echo "RUNTIME_RELEASE_PASS:$expected_sha:$mode"
