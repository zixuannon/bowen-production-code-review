#!/usr/bin/env bash
set -euo pipefail

# Root may orchestrate immutable releases, but Laravel commands that boot the
# application must execute as the same identity that owns shared runtime
# storage.  That keeps file-cache, compiled Blade, and session artifacts out
# of root ownership.
usage() { echo "Usage: $0 RELEASE_DIR ARTISAN_ARGUMENT..." >&2; exit 2; }
[[ $# -ge 2 ]] || usage

release_dir=$1
shift
runtime_user=${RUNTIME_USER:-www}
php_bin=${PHP_BIN:-/usr/bin/php83}

[[ -d "$release_dir" && ! -L "$release_dir" ]] || { echo "RUNTIME_ARTISAN_FAIL: unsafe release directory" >&2; exit 1; }
[[ -f "$release_dir/artisan" && ! -L "$release_dir/artisan" ]] || { echo "RUNTIME_ARTISAN_FAIL: artisan unavailable" >&2; exit 1; }
[[ -x "$php_bin" ]] || { echo "RUNTIME_ARTISAN_FAIL: PHP binary unavailable ($php_bin)" >&2; exit 1; }
id "$runtime_user" >/dev/null 2>&1 || { echo "RUNTIME_ARTISAN_FAIL: runtime user unavailable ($runtime_user)" >&2; exit 1; }

if [[ "$(id -un)" == "$runtime_user" ]]; then
  cd "$release_dir"
  exec "$php_bin" artisan "$@"
fi

command -v runuser >/dev/null 2>&1 || { echo "RUNTIME_ARTISAN_FAIL: runuser is required to drop privileges" >&2; exit 1; }
exec /usr/sbin/runuser -u "$runtime_user" -- sh -c 'cd "$1" && shift && exec "$@"' sh "$release_dir" "$php_bin" artisan "$@"
