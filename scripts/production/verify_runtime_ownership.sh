#!/usr/bin/env bash
set -euo pipefail

# Fail before activation if a privileged Laravel bootstrap has left shared
# runtime artifacts that PHP-FPM cannot safely replace.  This is a guard, not
# a repair tool: it never changes ownership, modes, or cached files.
release_dir=${1:-$(pwd)}
baseline_file=${2:-$(cd "$(dirname "$0")/../.." && pwd)/config/production-baseline.json}
php_bin=${JSON_PHP_BIN:-${PHP_BIN:-/usr/bin/php83}}

fail() { echo "RUNTIME_OWNERSHIP_GUARD_FAIL: $1" >&2; exit 1; }
read_json() {
  "$php_bin" -r '$d=json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR); $v=$d[$argv[2]] ?? null; if (!is_string($v) || $v === "") exit(2); echo $v;' "$baseline_file" "$1" \
    || fail "missing baseline field $1"
}
resolved_path() { readlink -f "$1" 2>/dev/null || "$php_bin" -r '$v=realpath($argv[1]); if ($v === false) exit(1); echo $v;' "$1"; }
as_runtime_user() {
  if [[ "$(id -un)" == "$runtime_user" ]]; then test -w "$1"; return; fi
  command -v runuser >/dev/null 2>&1 || fail "cannot verify writable access as $runtime_user"
  runuser -u "$runtime_user" -- test -w "$1"
}

[[ -d "$release_dir" ]] || fail "release directory unavailable"
[[ -f "$baseline_file" ]] || fail "baseline contract missing"
[[ -x "$php_bin" ]] || fail "JSON PHP binary unavailable ($php_bin)"
runtime_user=$(read_json runtime_user)
id "$runtime_user" >/dev/null 2>&1 || fail "runtime user unavailable ($runtime_user)"

storage_link="$release_dir/storage"
[[ -L "$storage_link" ]] || fail "release storage must be a symlink"
storage_root=$(resolved_path "$storage_link") || fail "release storage target is broken"
expected_storage=$(resolved_path "$(read_json shared_storage_target)") || fail "expected shared storage target is broken"
[[ "$storage_root" == "$expected_storage" ]] || fail "release storage target mismatch"

for relative in framework/cache/data framework/views framework/sessions; do
  target="$storage_root/$relative"
  [[ -d "$target" && ! -L "$target" ]] || fail "$relative is missing or unsafe"
  as_runtime_user "$target" || fail "$relative is not writable by $runtime_user"
  offenders=$(find "$target" -xdev -user root -print 2>/dev/null || true)
  if [[ -n "$offenders" ]]; then
    printf '%s\n' "$offenders" >&2
    fail "$relative contains root-owned runtime entries"
  fi
done

echo "RUNTIME_OWNERSHIP_GUARD_PASS:$storage_root"
