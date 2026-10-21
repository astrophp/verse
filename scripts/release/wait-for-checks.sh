#!/usr/bin/env bash
# Requires a successful push run of both the tests and install workflows for a commit, sharing one
# deadline across the two waits (RELEASE_RUNS_TIMEOUT_SECONDS, default 1200; 0 checks once).
set -euo pipefail

sha=${1:-}
branch=${2:-main}
dir=$(dirname "$0")

case "${RELEASE_RUNS_TIMEOUT_SECONDS:-1200}" in ''|*[!0-9]*) printf 'RELEASE_RUNS_TIMEOUT_SECONDS must be a non-negative integer.\n' >&2; exit 2;; esac
RELEASE_RUNS_DEADLINE=${RELEASE_RUNS_DEADLINE:-$(($(date +%s) + ${RELEASE_RUNS_TIMEOUT_SECONDS:-1200}))}
export RELEASE_RUNS_DEADLINE

for workflow in tests.yml install.yml; do
    "$dir/wait-for-runs.sh" "$workflow" "$sha" "$branch"
done
