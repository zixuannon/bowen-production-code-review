#!/usr/bin/env bash
set -euo pipefail

release_dir=${1:-$(pwd)}
baseline_file=${2:-$(cd "$(dirname "$0")/../.." && pwd)/config/production-baseline.json}
php_bin=${JSON_PHP_BIN:-${PHP_BIN:-/usr/bin/php83}}

fail() { echo "RUNTIME_GUARD_FAIL: $1" >&2; exit 1; }
read_json() {
  "$php_bin" -r '$d=json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR); $v=$d[$argv[2]] ?? null; if (!is_string($v) || $v === "") exit(2); echo $v;' "$baseline_file" "$1" \
    || fail "missing baseline field $1"
}
resolved_link() {
  readlink -f "$1" 2>/dev/null || "$php_bin" -r '$v=realpath($argv[1]); if ($v === false) exit(1); echo $v;' "$1"
}
owner_group() {
  stat -c '%U:%G' "$1" 2>/dev/null || stat -f '%Su:%Sg' "$1" 2>/dev/null
}
as_runtime_user() {
  mode=$1 path=$2
  if [ "$(id -un)" = "$runtime_user" ]; then test "$mode" "$path"; return; fi
  if command -v runuser >/dev/null 2>&1; then runuser -u "$runtime_user" -- test "$mode" "$path"; return; fi
  fail "cannot verify $mode access as runtime user $runtime_user"
}
verify_link() {
  relative=$1 expected=$2 mode=$3
  link="$release_dir/$relative"
  test -L "$link" || fail "$relative must be a symlink"
  actual=$(resolved_link "$link") || fail "$relative is broken"
  test "$actual" = "$expected" || fail "$relative target mismatch (expected $expected, got $actual)"
  test "$(owner_group "$actual")" = "$runtime_user:$runtime_group" \
    || fail "$relative target owner must be $runtime_user:$runtime_group"
  as_runtime_user "$mode" "$actual" || fail "$relative target is not $mode by $runtime_user"
}

test -f "$baseline_file" || fail "baseline contract missing"
test -x "$php_bin" || fail "JSON PHP binary unavailable ($php_bin)"
runtime_user=$(read_json runtime_user)
runtime_group=$(read_json runtime_group)
id "$runtime_user" >/dev/null 2>&1 || fail "runtime user does not exist: $runtime_user"

verify_link .env "$(read_json shared_env_target)" -r
verify_link storage "$(read_json shared_storage_target)" -w
verify_link public/storage "$(read_json shared_public_storage_target)" -w

test -d "$release_dir/bootstrap/cache" || fail "bootstrap/cache missing"
as_runtime_user -w "$release_dir/bootstrap/cache" || fail "bootstrap/cache is not writable by $runtime_user"

if [ "${RUNTIME_GUARD_SKIP_ASSETS:-0}" != 1 ]; then
  "$(cd "$(dirname "$0")/.." && pwd)/release/verify_required_assets.sh" --root "$release_dir"
fi

echo "RUNTIME_LINK_GUARD_PASS:$release_dir"
