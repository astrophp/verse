#!/usr/bin/env bash
# Waits until a workflow has a successful push run on the branch for the commit.
#   RELEASE_RUNS_DEADLINE         epoch seconds to stop waiting (shared by several calls)
#   RELEASE_RUNS_TIMEOUT_SECONDS  used when no deadline is set (default 1200; 0 checks once)
#   RELEASE_RUNS_GRACE_SECONDS    how long a run GitHub has not created yet is tolerated (default 180)
#   RELEASE_RUNS_SLEEP_SECONDS    pause between polls (default 30)
set -euo pipefail

workflow=${1:-}
sha=${2:-}
branch=${3:-main}
sleep_seconds=${RELEASE_RUNS_SLEEP_SECONDS:-30}
grace_seconds=${RELEASE_RUNS_GRACE_SECONDS:-180}
timeout_seconds=${RELEASE_RUNS_TIMEOUT_SECONDS:-1200}

for value in "$sleep_seconds" "$grace_seconds" "$timeout_seconds" "${RELEASE_RUNS_DEADLINE:-0}"; do
    case "$value" in ''|*[!0-9]*) printf 'Release wait settings must be non-negative integers.\n' >&2; exit 2;; esac
done

start=$(date +%s)
deadline=${RELEASE_RUNS_DEADLINE:-$((start + timeout_seconds))}
grace_end=$((start + grace_seconds))

fetch_runs() {
    if [ -n "${RELEASE_RUNS_FETCH_COMMAND:-}" ]; then
        "$RELEASE_RUNS_FETCH_COMMAND" "$workflow" "$sha" "$branch"
    else
        gh api "repos/${GH_REPO:?GH_REPO is required}/actions/workflows/$workflow/runs?head_sha=$sha&branch=$branch&event=push"
    fi
}

while :; do
    # stderr goes to the log; only stdout is the JSON.
    if ! runs=$(fetch_runs); then
        printf 'Could not query %s for %s.\n' "$workflow" "$sha" >&2
        exit 1
    fi
    status=0
    printf '%s' "$runs" | "$(dirname "$0")/runs-green.sh" "$workflow" "$sha" "$branch" || status=$?
    case "$status" in
        0) exit 0;;
        2) ;;
        3)
            if [ "$(date +%s)" -ge "$grace_end" ]; then
                printf 'GitHub never created a push run of %s for %s on %s within %s seconds. Was the commit merged to %s?\n' "$workflow" "$sha" "$branch" "$grace_seconds" "$branch" >&2
                exit 1
            fi
            ;;
        *) exit "$status";;
    esac
    now=$(date +%s)
    if [ "$now" -ge "$deadline" ]; then
        printf '%s did not finish successfully for %s before the wait deadline; run the release again once it is green.\n' "$workflow" "$sha" >&2
        exit 1
    fi
    pause=$((deadline - now))
    [ "$pause" -le "$sleep_seconds" ] || pause=$sleep_seconds
    sleep "$pause"
done
