#!/bin/sh

# Read-only release preflight for static assets intentionally kept outside Git.
set -eu

script_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
root_dir=$(CDPATH= cd -- "$script_dir/../.." && pwd)
manifest="$root_dir/release/required-assets.tsv"

while [ "$#" -gt 0 ]; do
    case "$1" in
        --root)
            root_dir=$2
            shift 2
            ;;
        --manifest)
            manifest=$2
            shift 2
            ;;
        *)
            echo "Usage: $0 [--root DIRECTORY] [--manifest FILE]" >&2
            exit 2
            ;;
    esac
done

if [ ! -f "$manifest" ]; then
    echo "Asset manifest not found: $manifest" >&2
    exit 2
fi

failures=0
checked=0
tab=$(printf '\t')

while IFS="$tab" read -r relative_path expected_checksum severity purpose; do
    case "$relative_path" in
        ''|'#'*) continue ;;
        /*|*'..'*)
            echo "INVALID manifest path: $relative_path" >&2
            failures=$((failures + 1))
            continue
            ;;
    esac

    if [ -z "$expected_checksum" ] || [ -z "$severity" ] || [ -z "$purpose" ]; then
        echo "INVALID manifest entry: $relative_path" >&2
        failures=$((failures + 1))
        continue
    fi

    checked=$((checked + 1))
    asset_path="$root_dir/$relative_path"
    if [ ! -f "$asset_path" ]; then
        echo "MISSING [$severity] $relative_path — $purpose" >&2
        failures=$((failures + 1))
        continue
    fi

    actual_checksum=$(shasum -a 256 "$asset_path" | awk '{print $1}')
    if [ "$actual_checksum" != "$expected_checksum" ]; then
        echo "MISMATCH [$severity] $relative_path" >&2
        failures=$((failures + 1))
        continue
    fi

    echo "OK [$severity] $relative_path"
done < "$manifest"

if [ "$checked" -eq 0 ]; then
    echo "Asset manifest has no entries: $manifest" >&2
    exit 2
fi

if [ "$failures" -ne 0 ]; then
    echo "Required asset verification failed: $failures issue(s)." >&2
    exit 1
fi

echo "Required asset verification passed: $checked asset(s)."
