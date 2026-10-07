#!/usr/bin/env bash
set -euo pipefail
# Future Production use ONLY after explicit exact-SHA migration/deployment approval.
# No automatic backup, release switch, service restart, role change or gate removal.
[[ $# -eq 4 ]] || { echo "Usage: $0 RELEASE_DIR FULL_SHA preflight|close|migrate|open RECOVERY_SET" >&2; exit 2; }
[[ $(id -u) -eq 0 ]] || { echo 'P0 gate requires the trusted root deployment actor.' >&2; exit 1; }
release=$(realpath "$1")
sha=$2
phase=$3
backup=$4
[[ "$sha" =~ ^[0-9a-f]{40}$ && "$phase" =~ ^(preflight|close|migrate|open)$ ]] || exit 2
[[ "$release" == /www/wwwroot/releases/* && ! -L "$1" ]] || exit 2
# Python holds both deployment and scheduler locks with O_NOFOLLOW through the
# Artisan operation. Do not use a shell redirection that could follow a symlink.
if [[ "$phase" != preflight ]]; then
    printf 'Approved exact candidate %s; perform P0 %s? Type YES: ' "$sha" "$phase"
    IFS= read -r confirmation
    [[ "$confirmation" == YES ]] || exit 1
fi
# The verifier performs independent COS HEAD and immutable/runtime checks, then
# atomically writes short-lived root-owned evidence. Any failure leaves DB gates.
/usr/bin/python3 "$release/scripts/production/p0_release_evidence.py" \
    "$release" "$sha" "$phase" "$backup" --run
